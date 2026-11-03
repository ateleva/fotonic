<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Vault unlock with the device fingerprint (WebAuthn PRF).
 *
 * The browser asks the device for a secret that only the enrolled credential can produce (the
 * WebAuthn PRF extension) and turns it into a 32-byte key (KEK) with HKDF. This class keeps, for
 * each enrolled device, the Master Key wrapped under that KEK. Nothing here checks a WebAuthn
 * signature: the server never releases the Master Key because an assertion looks valid. The only
 * proof is that AES-GCM opens the wrap with the KEK the browser sends, so a request that lacks the
 * device secret gets nothing.
 *
 * Per device record (option OPTION, autoload off):
 *   cred_id    base64url credential id
 *   label      name shown to the person
 *   host       the host name the passkey is bound to (location.hostname at enrollment)
 *   salt_cur   base64url, the PRF salt the current wrap is bound to
 *   salt_next  base64url, the salt the next wrap will be bound to
 *   gen        rotation counter, +1 on every unlock
 *   wrap       Fotonic_Crypto::wrap( MK, KEK( salt_cur ) )
 *   created, last_used  unix time
 *
 * Replay protection: every unlock opens the wrap with KEK( salt_cur ) and writes it again under
 * KEK( salt_next ), then salt_cur takes the old salt_next and a fresh salt_next is drawn. A KEK
 * captured earlier is useless once the next unlock has happened.
 *
 * Callers must hold Fotonic_Vault_Attempts::acquire( 'fingerprint' ) around every method that
 * writes (enroll, remove, unlock): the option is one array, so a read-modify-write needs the lock.
 *
 * The option is never part of a backup (Fotonic_Backup_Registry::denied_options()) nor of
 * key.json: it is bound to this site's passkeys and to this Master Key.
 */
final class Fotonic_Vault_Fingerprint {

	const OPTION        = 'fotonic_vault_prf_devices';
	const TRANSIENT     = 'fotonic_vault_prf_enroll_'; // + user id.
	const ENROLL_TTL    = 600;                          // Seconds between "begin" and "finish".
	const MAX_DEVICES   = 10;
	const MAX_LABEL     = 60;

	/** Fresh 32-byte value as base64url. */
	private static function new_salt(): string {
		return self::b64u_encode( random_bytes( 32 ) );
	}

	public static function b64u_encode( string $raw ): string {
		return rtrim( strtr( base64_encode( $raw ), '+/', '-_' ), '=' );
	}

	/** Strict base64url decode; false for anything that is not base64url. */
	public static function b64u_decode( string $value ) {
		if ( '' === $value || ! preg_match( '/^[A-Za-z0-9_-]+$/', $value ) ) {
			return false;
		}
		$pad = ( 4 - strlen( $value ) % 4 ) % 4;
		return base64_decode( strtr( $value, '-_', '+/' ) . str_repeat( '=', $pad ), true );
	}

	/** A KEK as the browser sends it: base64url of exactly 32 bytes. Returns the raw bytes or ''. */
	public static function parse_kek( $value ): string {
		$raw = is_string( $value ) ? self::b64u_decode( $value ) : false;
		return ( false !== $raw && 32 === strlen( $raw ) ) ? $raw : '';
	}

	/** A credential id as sent by the browser. */
	public static function valid_cred_id( $value ): bool {
		return is_string( $value ) && (bool) preg_match( '/^[A-Za-z0-9_-]{16,1400}$/', $value );
	}

	private static function clean_label( string $label ): string {
		$label = trim( sanitize_text_field( $label ) );
		if ( function_exists( 'mb_substr' ) ) {
			$label = mb_substr( $label, 0, self::MAX_LABEL );
		} else {
			$label = substr( $label, 0, self::MAX_LABEL );
		}
		return $label;
	}

	/** @return array[] Every stored record. */
	private static function all(): array {
		$devices = get_option( self::OPTION, array() );
		return is_array( $devices ) ? array_values( array_filter( $devices, 'is_array' ) ) : array();
	}

	private static function save( array $devices ): void {
		if ( empty( $devices ) ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, array_values( $devices ), false );
	}

	// ---------------------------------------------------------------------------
	// Listing
	// ---------------------------------------------------------------------------

	/** Number of enrolled devices. */
	public static function count(): int {
		return count( self::all() );
	}

	/**
	 * What the screen may show about each device. No wrap, no salts.
	 *
	 * @return array[]
	 */
	public static function devices(): array {
		$out = array();
		foreach ( self::all() as $d ) {
			$out[] = array(
				'cred_id'   => (string) ( $d['cred_id'] ?? '' ),
				'label'     => (string) ( $d['label'] ?? '' ),
				'host'      => (string) ( $d['host'] ?? '' ),
				'created'   => (int) ( $d['created'] ?? 0 ),
				'last_used' => (int) ( $d['last_used'] ?? 0 ),
			);
		}
		return $out;
	}

	// ---------------------------------------------------------------------------
	// Enrollment: begin (after password + code were checked) and finish
	// ---------------------------------------------------------------------------

	/**
	 * First enrollment step. Called once password and authenticator code were verified: draws the
	 * PRF salt the new wrap will be bound to and a single-use token that proves the check happened.
	 *
	 * @param int $user_id WordPress user that is enrolling.
	 * @return array{token:string,salt:string}
	 */
	public static function begin_enroll( int $user_id ): array {
		$token = bin2hex( random_bytes( 16 ) );
		$salt  = self::new_salt();
		set_transient(
			self::TRANSIENT . $user_id,
			array( 'hash' => hash( 'sha256', $token ), 'salt' => $salt ),
			self::ENROLL_TTL
		);
		return array( 'token' => $token, 'salt' => $salt );
	}

	/**
	 * Second enrollment step: wrap the Master Key under the KEK the browser derived.
	 *
	 * @param int    $user_id WordPress user.
	 * @param string $mk      Raw Master Key (from the unlocked session).
	 * @param string $token   Token from begin_enroll().
	 * @param string $cred_id base64url credential id.
	 * @param string $label   Name shown to the person.
	 * @param string $host    Host name the passkey is bound to.
	 * @param string $kek_b64 base64url, 32 bytes: KEK( salt from begin ).
	 * @return array{ok:bool,code?:string,device?:array}
	 */
	public static function finish_enroll( int $user_id, string $mk, string $token, string $cred_id, string $label, string $host, string $kek_b64 ): array {
		$pending = get_transient( self::TRANSIENT . $user_id );
		delete_transient( self::TRANSIENT . $user_id ); // Single use, pass or fail.
		if ( ! is_array( $pending ) || empty( $pending['hash'] ) || ! hash_equals( (string) $pending['hash'], hash( 'sha256', $token ) ) ) {
			return array( 'ok' => false, 'code' => 'enroll_expired' );
		}

		$kek = self::parse_kek( $kek_b64 );
		if ( '' === $kek || 32 !== strlen( $mk ) || ! self::valid_cred_id( $cred_id ) ) {
			return array( 'ok' => false, 'code' => 'bad_request' );
		}
		$host = strtolower( trim( $host ) );
		if ( '' === $host || strlen( $host ) > 253 ) {
			return array( 'ok' => false, 'code' => 'bad_request' );
		}

		$wrap = Fotonic_Crypto::wrap( $mk, $kek );
		if ( '' === $wrap ) {
			return array( 'ok' => false, 'code' => 'wrap_failed' );
		}

		// The same credential enrolled again replaces its record; otherwise there is a limit.
		$devices = array_values( array_filter( self::all(), function ( $d ) use ( $cred_id ) {
			return ( $d['cred_id'] ?? '' ) !== $cred_id;
		} ) );
		if ( count( $devices ) >= self::MAX_DEVICES ) {
			return array( 'ok' => false, 'code' => 'too_many_devices' );
		}

		$label = self::clean_label( $label );
		$now   = time();
		$device = array(
			'cred_id'   => $cred_id,
			'label'     => '' !== $label ? $label : 'Device',
			'host'      => $host,
			'salt_cur'  => (string) $pending['salt'],
			'salt_next' => self::new_salt(),
			'gen'       => 1,
			'wrap'      => $wrap,
			'created'   => $now,
			'last_used' => 0,
		);
		$devices[] = $device;
		self::save( $devices );

		return array( 'ok' => true, 'device' => self::public_view( $device ) );
	}

	private static function public_view( array $d ): array {
		return array(
			'cred_id'   => $d['cred_id'],
			'label'     => $d['label'],
			'host'      => $d['host'],
			'created'   => (int) $d['created'],
			'last_used' => (int) $d['last_used'],
		);
	}

	// ---------------------------------------------------------------------------
	// Unlock
	// ---------------------------------------------------------------------------

	/**
	 * What the browser needs to ask for the secret: every device enrolled for this host name.
	 *
	 * @param string $host location.hostname of the page.
	 * @return array[] Each { cred_id, salt_cur, salt_next, gen }.
	 */
	public static function challenge( string $host ): array {
		$host = strtolower( trim( $host ) );
		$out  = array();
		foreach ( self::all() as $d ) {
			if ( ( $d['host'] ?? '' ) !== $host ) {
				continue;
			}
			$out[] = array(
				'cred_id'   => (string) $d['cred_id'],
				'salt_cur'  => (string) $d['salt_cur'],
				'salt_next' => (string) $d['salt_next'],
				'gen'       => (int) $d['gen'],
			);
		}
		return $out;
	}

	/**
	 * Open the wrap with KEK( salt_cur ), write it again under KEK( salt_next ), rotate the salts.
	 * Returns the Master Key; the caller issues the session cookie.
	 *
	 * Codes: 'bad_request' (malformed, nothing changes, not a guess), 'unknown' (no such device),
	 * 'stale' (another request rotated first: refetch, not a failed guess), 'invalid' (the KEK did
	 * not open the wrap: a failed guess).
	 *
	 * @param string $cred_id  Device.
	 * @param int    $gen      Rotation counter the browser got in challenge().
	 * @param string $kek_cur  base64url, 32 bytes.
	 * @param string $kek_next base64url, 32 bytes.
	 * @return array{ok:bool,code?:string,mk?:string,label?:string}
	 */
	public static function unlock( string $cred_id, int $gen, string $kek_cur, string $kek_next ): array {
		$cur  = self::parse_kek( $kek_cur );
		$next = self::parse_kek( $kek_next );
		// A missing or odd "next" secret never falls through to an unlock without rotation.
		if ( '' === $cur || '' === $next || hash_equals( $cur, $next ) || ! self::valid_cred_id( $cred_id ) ) {
			return array( 'ok' => false, 'code' => 'bad_request' );
		}

		$devices = self::all();
		$index   = null;
		foreach ( $devices as $i => $d ) {
			if ( ( $d['cred_id'] ?? '' ) === $cred_id ) {
				$index = $i;
				break;
			}
		}
		if ( null === $index ) {
			return array( 'ok' => false, 'code' => 'unknown' );
		}
		$d = $devices[ $index ];

		if ( (int) $d['gen'] !== $gen ) {
			return array( 'ok' => false, 'code' => 'stale' );
		}

		$mk = Fotonic_Crypto::unwrap( (string) $d['wrap'], $cur );
		if ( false === $mk || 32 !== strlen( $mk ) ) {
			return array( 'ok' => false, 'code' => 'invalid' );
		}

		$new_wrap = Fotonic_Crypto::wrap( $mk, $next );
		if ( '' === $new_wrap ) {
			return array( 'ok' => false, 'code' => 'bad_request' );
		}

		$devices[ $index ]['wrap']      = $new_wrap;
		$devices[ $index ]['salt_cur']  = $d['salt_next'];
		$devices[ $index ]['salt_next'] = self::new_salt();
		$devices[ $index ]['gen']       = (int) $d['gen'] + 1;
		$devices[ $index ]['last_used'] = time();
		self::save( $devices );

		return array( 'ok' => true, 'mk' => $mk, 'label' => (string) $d['label'] );
	}

	// ---------------------------------------------------------------------------
	// Removal
	// ---------------------------------------------------------------------------

	/**
	 * Forget one device. The passkey itself stays in the person's keychain; without its wrap it
	 * opens nothing.
	 *
	 * @return bool True when a device was removed.
	 */
	public static function remove( string $cred_id ): bool {
		$devices = self::all();
		$kept    = array_values( array_filter( $devices, function ( $d ) use ( $cred_id ) {
			return ( $d['cred_id'] ?? '' ) !== $cred_id;
		} ) );
		if ( count( $kept ) === count( $devices ) ) {
			return false;
		}
		self::save( $kept );
		return true;
	}

	/** Forget every device (Vault reset, recovery). */
	public static function revoke_all(): void {
		delete_option( self::OPTION );
	}
}
