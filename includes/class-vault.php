<?php
/**
 * Fotonic Vault
 *
 * Manages TOTP-gated AES-256 encryption of PII stored in postmeta.
 *
 * Scheme v2 (envelope encryption)
 * --------------------------------
 * A random 32-byte Master Key (MK) encrypts all PII.  MK is never stored in
 * plaintext; it is "wrapped" (AES-256-GCM) under three separate Key Encryption
 * Keys (KEKs):
 *
 *   wrap_pw   = Crypto::wrap( MK,           KEK_pw  )   KEK_pw  = derive(password, salt_pw)
 *   wrap_rec  = Crypto::wrap( MK,           KEK_rec )   KEK_rec = derive(normalize(code), salt_rec)
 *   wrap_totp = Crypto::wrap( totp_secret,  MK      )
 *
 * Changing the password therefore only re-wraps MK — NO PII re-encryption needed.
 * The session cookie still carries MK (get_session_key() returns MK).
 *
 * Legacy scheme v1 (pre-envelope)
 * ---------------------------------
 * OPTION_TOTP holds the TOTP secret encrypted with the derived key K directly.
 * On first unlock of a legacy vault, migrate_legacy() is called automatically:
 * it generates a fresh MK, re-encrypts all PII (old K → MK), and upgrades the
 * stored options to scheme v2.  The user must then set up a recovery code via
 * the UI (has_recovery() will return false after migration).
 *
 * @package Fotonic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fotonic_Vault {

	const COOKIE_NAME  = 'fotonic_vault_session';
	const COOKIE_EXPIRY = 86400; // 24 hours in seconds.
	const CIPHER        = 'aes-256-gcm';

	// ---------------------------------------------------------------------------
	// Option keys
	// ---------------------------------------------------------------------------

	// Kept from v1 — used as OPTION_SALT_PW and for legacy migration detection.
	const OPTION_SALT      = 'fotonic_vault_salt';          // PBKDF2 salt for password KEK (base64).
	const OPTION_TOTP      = 'fotonic_vault_totp_secret';   // Legacy v1: AES-CBC( totp, K ). NOT used in v2.
	const OPTION_SETUP     = 'fotonic_vault_setup';          // bool — vault has been initialised.

	// Envelope v2 options.
	const OPTION_SALT_PW     = 'fotonic_vault_salt';          // Alias: same key as OPTION_SALT.
	const OPTION_WRAP_PW     = 'fotonic_vault_wrap_pw';        // AES-GCM( MK, KEK_pw )  'w1:…'
	const OPTION_SALT_REC    = 'fotonic_vault_salt_rec';       // PBKDF2 salt for recovery KEK (base64).
	const OPTION_WRAP_REC    = 'fotonic_vault_wrap_rec';       // AES-GCM( MK, KEK_rec )  'w1:…'
	const OPTION_WRAP_TOTP   = 'fotonic_vault_wrap_totp';      // AES-GCM( totp_secret, MK )  'w1:…'
	const OPTION_SCHEME      = 'fotonic_vault_scheme';         // int: 1 = legacy, 2 = envelope.

	// Recovery phrase options (v2 extension).
	const OPTION_SALT_PHRASE = 'fotonic_vault_salt_phrase';    // PBKDF2 salt for phrase KEK (base64).
	const OPTION_WRAP_PHRASE = 'fotonic_vault_wrap_phrase';    // AES-GCM( MK, KEK_phrase )  'w1:…'

	// Two-phase setup, recovery kit acknowledgment and recovery TOTP staging.
	const OPTION_PENDING       = 'fotonic_vault_pending';        // array { salt_pw, wrap_pw, wrap_totp } until the OTP is confirmed.
	const OPTION_RECOVERY_ACK  = 'fotonic_vault_recovery_ack';   // bool: the user has seen and verified the current recovery kit.
	const OPTION_PENDING_TOTP  = 'fotonic_vault_pending_totp';   // AES-GCM( new totp_secret, MK )  'w1:…', staged by recovery.

	// Email-gated destructive reset (transient names).
	const TRANSIENT_RESET_CODE     = 'fotonic_vault_reset_code';      // array { hash, attempts, expires }, 15 minutes.
	const TRANSIENT_RESET_REQUESTS = 'fotonic_vault_reset_requests';  // int, requests made this hour.

	// Single source for the vault password minimum (client mirrors it in utils/recoveryKit.js).
	const MIN_PASSWORD_LENGTH = 8;

	// ---------------------------------------------------------------------------
	// Status helpers
	// ---------------------------------------------------------------------------

	/**
	 * Whether the vault has been initialised (password + TOTP set).
	 *
	 * @return bool
	 */
	public static function is_setup(): bool {
		return (bool) get_option( self::OPTION_SETUP, false );
	}

	/**
	 * Whether the vault is currently unlocked.
	 * Relies solely on the HTTP-Only session cookie (scheme v1/v2).
	 *
	 * @return bool
	 */
	public static function is_unlocked(): bool {
		return self::get_session_key() !== null;
	}

	/**
	 * Whether a recovery code credential is stored (OPTION_WRAP_REC).
	 *
	 * @return bool
	 */
	public static function has_recovery(): bool {
		$wrap = get_option( self::OPTION_WRAP_REC, '' );
		return ! empty( $wrap );
	}

	/**
	 * Whether a recovery phrase credential is stored (OPTION_WRAP_PHRASE).
	 *
	 * @return bool
	 */
	public static function has_recovery_phrase(): bool {
		return ! empty( get_option( self::OPTION_WRAP_PHRASE, '' ) );
	}

	/**
	 * Return the current encryption scheme version.
	 *
	 * @return int 1 = legacy CBC-direct, 2 = envelope GCM.
	 */
	public static function get_scheme(): int {
		return (int) get_option( self::OPTION_SCHEME, 1 );
	}

	// ---------------------------------------------------------------------------
	// Setup (scheme v2)
	// ---------------------------------------------------------------------------

	/**
	 * Stage a first-time vault setup. Nothing becomes live until confirm_setup()
	 * proves the user can produce a valid OTP from the authenticator.
	 *
	 * Generates a random Master Key, wraps it under the password KEK and wraps
	 * the TOTP secret under MK, then stores ONLY the pending option. No cookie,
	 * no OPTION_SETUP, no recovery wraps. Overwrites any previous pending setup.
	 *
	 * @param string $password    Plain-text vault password.
	 * @param string $totp_secret Base32 TOTP secret.
	 * @return bool True when the pending setup was stored.
	 */
	public static function stage_setup( string $password, string $totp_secret ): bool {
		if ( '' === $password || '' === $totp_secret || self::is_setup() ) {
			return false;
		}

		$mk       = random_bytes( 32 );
		$salt_pw  = base64_encode( random_bytes( 32 ) );
		$kek_pw   = Fotonic_Crypto::derive_key( $password, $salt_pw );
		$wrap_pw  = Fotonic_Crypto::wrap( $mk, $kek_pw );
		$wrap_totp = Fotonic_Crypto::wrap( strtoupper( $totp_secret ), $mk );
		if ( empty( $wrap_pw ) || empty( $wrap_totp ) ) {
			return false;
		}

		update_option(
			self::OPTION_PENDING,
			array(
				'salt_pw'   => $salt_pw,
				'wrap_pw'   => $wrap_pw,
				'wrap_totp' => $wrap_totp,
			),
			false
		);
		return true;
	}

	/**
	 * Whether a staged (not yet confirmed) setup exists.
	 *
	 * @return bool
	 */
	public static function has_pending_setup(): bool {
		$pending = get_option( self::OPTION_PENDING, array() );
		return is_array( $pending ) && ! empty( $pending['salt_pw'] ) && ! empty( $pending['wrap_pw'] ) && ! empty( $pending['wrap_totp'] );
	}

	/**
	 * Promote the staged setup to a live vault once the OTP is proven.
	 *
	 * On failure nothing changes. On success the live options are written, the
	 * recovery kit is flagged as not yet acknowledged, the backup keypair is
	 * provisioned and the session cookie is issued.
	 *
	 * @param string $password Plain-text vault password.
	 * @param string $otp      6-digit TOTP code.
	 * @return bool
	 */
	public static function confirm_setup( string $password, string $otp ): bool {
		if ( self::is_setup() || ! self::has_pending_setup() ) {
			return false;
		}

		$pending = get_option( self::OPTION_PENDING, array() );

		$kek_pw = Fotonic_Crypto::derive_key( $password, (string) $pending['salt_pw'] );
		$mk     = Fotonic_Crypto::unwrap( (string) $pending['wrap_pw'], $kek_pw );
		if ( false === $mk ) {
			return false;
		}

		$totp_secret = Fotonic_Crypto::unwrap( (string) $pending['wrap_totp'], $mk );
		if ( false === $totp_secret || ! Fotonic_TOTP::verify( $otp, $totp_secret, 2 ) ) {
			return false;
		}

		update_option( self::OPTION_SALT_PW,      $pending['salt_pw'],   false );
		update_option( self::OPTION_WRAP_PW,      $pending['wrap_pw'],   false );
		update_option( self::OPTION_WRAP_TOTP,    $pending['wrap_totp'], false );
		update_option( self::OPTION_SCHEME,       2,                     false );
		update_option( self::OPTION_SETUP,        true,                  false );
		update_option( self::OPTION_RECOVERY_ACK, false,                 false );
		delete_option( self::OPTION_PENDING );

		// Provision the backup keypair while MK is available (idempotent).
		Fotonic_Backup_Keys::setup( $mk );

		self::set_session_cookie( $mk );
		return true;
	}

	// ---------------------------------------------------------------------------
	// Recovery kit (phrase + code)
	// ---------------------------------------------------------------------------

	/**
	 * Generate a fresh recovery phrase and recovery code, replacing both wraps.
	 * Requires the vault to be unlocked. Drops the acknowledgment flag, so a tab
	 * closed before the user confirmed the kit still blocks at the next unlock.
	 * Does NOT send mail (the REST layer does).
	 *
	 * @return array|false { recovery_code, recovery_phrase } or false when locked / on failure.
	 */
	public static function issue_recovery_kit() {
		$mk = self::get_session_key();
		if ( null === $mk ) {
			return false;
		}

		$code        = Fotonic_Crypto::generate_recovery_code();
		$salt_rec    = base64_encode( random_bytes( 32 ) );
		$kek_rec     = Fotonic_Crypto::derive_key( Fotonic_Crypto::normalize_recovery_code( $code ), $salt_rec );
		$wrap_rec    = Fotonic_Crypto::wrap( $mk, $kek_rec );

		$phrase      = Fotonic_TOTP::generate_recovery_phrase();
		$salt_phrase = base64_encode( random_bytes( 32 ) );
		$kek_phrase  = Fotonic_Crypto::derive_key( Fotonic_Crypto::normalize_recovery_code( $phrase ), $salt_phrase );
		$wrap_phrase = Fotonic_Crypto::wrap( $mk, $kek_phrase );

		if ( empty( $wrap_rec ) || empty( $wrap_phrase ) ) {
			return false;
		}

		update_option( self::OPTION_SALT_REC,    $salt_rec,    false );
		update_option( self::OPTION_WRAP_REC,    $wrap_rec,    false );
		update_option( self::OPTION_SALT_PHRASE, $salt_phrase, false );
		update_option( self::OPTION_WRAP_PHRASE, $wrap_phrase, false );
		update_option( self::OPTION_RECOVERY_ACK, false,       false );

		return array(
			'recovery_code'   => $code,
			'recovery_phrase' => $phrase,
		);
	}

	/**
	 * Record whether the user has seen and verified the current kit.
	 *
	 * @param bool $ack New flag value.
	 * @return bool False when locked, or when acknowledging while either wrap is missing.
	 */
	public static function set_recovery_ack( bool $ack ): bool {
		if ( null === self::get_session_key() ) {
			return false;
		}
		if ( $ack && ( ! self::has_recovery() || ! self::has_recovery_phrase() ) ) {
			return false;
		}
		update_option( self::OPTION_RECOVERY_ACK, $ack, false );
		return true;
	}

	/**
	 * Whether the current kit is issued AND acknowledged. A missing ack option
	 * (any install that predates the kit wizard) counts as not acknowledged.
	 *
	 * @return bool
	 */
	public static function is_recovery_kit_ok(): bool {
		return (bool) get_option( self::OPTION_RECOVERY_ACK, false )
			&& self::has_recovery()
			&& self::has_recovery_phrase();
	}

	// ---------------------------------------------------------------------------
	// Unlock / Lock
	// ---------------------------------------------------------------------------

	/**
	 * Verify password + TOTP OTP, then issue a session cookie containing MK.
	 *
	 * If the vault is still scheme v1 (legacy), migrate_legacy() is called first;
	 * on success the vault is silently upgraded to scheme v2 and the session
	 * cookie is issued.
	 *
	 * @param string $password Plain-text vault password.
	 * @param string $otp      6-digit TOTP code.
	 * @return bool True on success; false if credentials are wrong.
	 */
	public static function unlock( string $password, string $otp ): bool {
		if ( ! self::is_setup() ) {
			return false;
		}

		// Auto-migrate legacy v1 vaults on first unlock.
		if ( self::get_scheme() < 2 && ! empty( get_option( self::OPTION_TOTP, '' ) ) ) {
			return self::migrate_legacy( $password, $otp );
		}

		// Scheme v2 envelope path.
		$salt_pw  = (string) get_option( self::OPTION_SALT_PW, '' );
		$wrap_pw  = (string) get_option( self::OPTION_WRAP_PW, '' );
		$wrap_totp = (string) get_option( self::OPTION_WRAP_TOTP, '' );

		if ( empty( $salt_pw ) || empty( $wrap_pw ) || empty( $wrap_totp ) ) {
			return false;
		}

		$kek_pw = Fotonic_Crypto::derive_key( $password, $salt_pw );
		$mk     = Fotonic_Crypto::unwrap( $wrap_pw, $kek_pw );
		if ( false === $mk ) {
			return false;
		}

		$totp_secret = Fotonic_Crypto::unwrap( $wrap_totp, $mk );
		if ( false === $totp_secret ) {
			return false;
		}

		// Window=2 gives ±60 s tolerance — covers Docker/VM clock drift after restart.
		if ( ! Fotonic_TOTP::verify( $otp, $totp_secret, 2 ) ) {
			return false;
		}

		self::set_session_cookie( $mk );

		// Backfill the backup keypair for vaults that predate the backup feature.
		// Idempotent, so this is a no-op on every unlock after the first.
		Fotonic_Backup_Keys::setup( $mk );

		return true;
	}

	/**
	 * Destroy the vault session cookie.
	 *
	 * @return void
	 */
	public static function lock(): void {
		self::send_cookie( '', time() - 3600 );
		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}

	// ---------------------------------------------------------------------------
	// Password management
	// ---------------------------------------------------------------------------

	/**
	 * Change the vault password.
	 *
	 * Verifies current credentials, then re-wraps MK under a new KEK derived
	 * from the new password.  No PII re-encryption is needed because MK does
	 * not change.
	 *
	 * @param string $current_pw Current password.
	 * @param string $otp        Current TOTP code.
	 * @param string $new_pw     New password.
	 * @return bool True on success.
	 */
	public static function change_password( string $current_pw, string $otp, string $new_pw ): bool {
		if ( empty( $current_pw ) || empty( $otp ) || empty( $new_pw ) ) {
			return false;
		}

		// Obtain MK by verifying existing credentials.
		$mk = self::get_mk_via_password( $current_pw );
		if ( false === $mk ) {
			return false;
		}

		// Verify OTP.
		$wrap_totp = (string) get_option( self::OPTION_WRAP_TOTP, '' );
		if ( empty( $wrap_totp ) ) {
			return false;
		}
		$totp_secret = Fotonic_Crypto::unwrap( $wrap_totp, $mk );
		if ( false === $totp_secret || ! Fotonic_TOTP::verify( $otp, $totp_secret, 2 ) ) {
			return false;
		}

		// Re-wrap MK under new password KEK.
		$new_salt_pw = base64_encode( random_bytes( 32 ) );
		$new_kek_pw  = Fotonic_Crypto::derive_key( $new_pw, $new_salt_pw );
		$new_wrap_pw = Fotonic_Crypto::wrap( $mk, $new_kek_pw );
		if ( empty( $new_wrap_pw ) ) {
			return false;
		}

		update_option( self::OPTION_SALT_PW, $new_salt_pw, false );
		update_option( self::OPTION_WRAP_PW, $new_wrap_pw, false );

		// Refresh session cookie with same MK.
		self::update_session_key( $mk );
		return true;
	}

	// ---------------------------------------------------------------------------
	// Recovery: any recovery secret, then new password, then new authenticator
	// ---------------------------------------------------------------------------

	/**
	 * Begin a recovery from a recovery phrase or a recovery code.
	 *
	 * Tries the phrase wrap, then the code wrap. When one yields MK, MK is
	 * re-wrapped under the new password, the session cookie is set and a new
	 * TOTP secret is staged (pending). The live wrap_totp, wrap_rec and
	 * wrap_phrase are never touched here, so an interrupted recovery can simply
	 * be repeated with the same secret.
	 *
	 * @param string $secret Recovery phrase or code, any case, dashes/spaces ignored.
	 * @param string $new_pw New vault password.
	 * @return bool True on success.
	 */
	public static function recover_with_secret( string $secret, string $new_pw ): bool {
		$normalized = Fotonic_Crypto::normalize_recovery_code( $secret );
		if ( '' === $normalized || '' === $new_pw ) {
			return false;
		}

		$mk      = false;
		$sources = array(
			array( self::OPTION_SALT_PHRASE, self::OPTION_WRAP_PHRASE ),
			array( self::OPTION_SALT_REC, self::OPTION_WRAP_REC ),
		);
		foreach ( $sources as $source ) {
			$salt = (string) get_option( $source[0], '' );
			$wrap = (string) get_option( $source[1], '' );
			if ( '' === $salt || '' === $wrap ) {
				continue;
			}
			$mk = Fotonic_Crypto::unwrap( $wrap, Fotonic_Crypto::derive_key( $normalized, $salt ) );
			if ( false !== $mk ) {
				break;
			}
		}
		if ( false === $mk ) {
			return false;
		}

		$new_salt_pw = base64_encode( random_bytes( 32 ) );
		$new_wrap_pw = Fotonic_Crypto::wrap( $mk, Fotonic_Crypto::derive_key( $new_pw, $new_salt_pw ) );
		if ( empty( $new_wrap_pw ) ) {
			return false;
		}

		update_option( self::OPTION_SALT_PW, $new_salt_pw, false );
		update_option( self::OPTION_WRAP_PW, $new_wrap_pw, false );

		self::set_session_cookie( $mk );

		return false !== self::stage_pending_totp();
	}

	/**
	 * Stage a fresh TOTP secret, wrapped under MK, replacing any earlier pending
	 * one. The live wrap_totp stays untouched until confirm_pending_totp().
	 *
	 * @return string|false New base32 secret, or false when the vault is locked.
	 */
	public static function stage_pending_totp() {
		$mk = self::get_session_key();
		if ( null === $mk ) {
			return false;
		}

		$secret = Fotonic_TOTP::generate_secret();
		$wrap   = Fotonic_Crypto::wrap( strtoupper( $secret ), $mk );
		if ( empty( $wrap ) ) {
			return false;
		}

		update_option( self::OPTION_PENDING_TOTP, $wrap, false );
		return $secret;
	}

	/**
	 * The staged TOTP secret in clear (base32), so a repeated request shows the
	 * same QR the user may already have scanned.
	 *
	 * @return string|false False when locked or nothing is staged.
	 */
	public static function get_pending_totp_secret() {
		$mk = self::get_session_key();
		if ( null === $mk ) {
			return false;
		}
		$wrap = (string) get_option( self::OPTION_PENDING_TOTP, '' );
		if ( '' === $wrap ) {
			return false;
		}
		return Fotonic_Crypto::unwrap( $wrap, $mk );
	}

	/**
	 * Whether a staged TOTP secret is waiting for its first OTP.
	 *
	 * @return bool
	 */
	public static function has_pending_totp(): bool {
		return ! empty( get_option( self::OPTION_PENDING_TOTP, '' ) );
	}

	/**
	 * Promote the staged TOTP secret once an OTP generated from it is proven.
	 * Drops the kit acknowledgment: recovery always ends with a new kit.
	 *
	 * @param string $otp 6-digit code from the new authenticator entry.
	 * @return bool
	 */
	public static function confirm_pending_totp( string $otp ): bool {
		$mk = self::get_session_key();
		if ( null === $mk ) {
			return false;
		}

		$wrap = (string) get_option( self::OPTION_PENDING_TOTP, '' );
		if ( '' === $wrap ) {
			return false;
		}

		$secret = Fotonic_Crypto::unwrap( $wrap, $mk );
		if ( false === $secret || ! Fotonic_TOTP::verify( $otp, $secret, 2 ) ) {
			return false;
		}

		update_option( self::OPTION_WRAP_TOTP, $wrap, false );
		delete_option( self::OPTION_PENDING_TOTP );
		update_option( self::OPTION_RECOVERY_ACK, false, false );
		return true;
	}

	// ---------------------------------------------------------------------------
	// Destructive reset gates
	// ---------------------------------------------------------------------------

	/**
	 * Create a one-time reset code. Only a keyed hash is stored; the plaintext
	 * is returned once so the mailer can send it.
	 *
	 * @return string|false 8-digit code, or false when 3 codes were already requested this hour.
	 */
	public static function request_reset_code() {
		$requests = (int) get_transient( self::TRANSIENT_RESET_REQUESTS );
		if ( $requests >= 3 ) {
			return false;
		}
		set_transient( self::TRANSIENT_RESET_REQUESTS, $requests + 1, HOUR_IN_SECONDS );

		$code = str_pad( (string) random_int( 0, 99999999 ), 8, '0', STR_PAD_LEFT );
		set_transient(
			self::TRANSIENT_RESET_CODE,
			array(
				'hash'     => self::hash_reset_code( $code ),
				'attempts' => 0,
				'expires'  => time() + 15 * MINUTE_IN_SECONDS,
			),
			15 * MINUTE_IN_SECONDS
		);
		return $code;
	}

	/**
	 * Check a reset code. Single use; five wrong tries burn the code.
	 *
	 * @param string $code Code typed by the user.
	 * @return bool
	 */
	public static function verify_reset_code( string $code ): bool {
		$state = get_transient( self::TRANSIENT_RESET_CODE );
		if ( ! is_array( $state ) || empty( $state['hash'] ) ) {
			return false;
		}

		$attempts = isset( $state['attempts'] ) ? (int) $state['attempts'] : 0;
		if ( $attempts >= 5 ) {
			delete_transient( self::TRANSIENT_RESET_CODE );
			return false;
		}

		if ( hash_equals( (string) $state['hash'], self::hash_reset_code( trim( $code ) ) ) ) {
			delete_transient( self::TRANSIENT_RESET_CODE );
			return true;
		}

		$attempts++;
		$ttl = isset( $state['expires'] ) ? (int) $state['expires'] - time() : 15 * MINUTE_IN_SECONDS;
		if ( $attempts >= 5 || $ttl <= 0 ) {
			delete_transient( self::TRANSIENT_RESET_CODE );
		} else {
			$state['attempts'] = $attempts;
			set_transient( self::TRANSIENT_RESET_CODE, $state, $ttl );
		}
		return false;
	}

	/**
	 * Verify password + OTP against the live vault without side effects (no cookie).
	 *
	 * @param string $password Vault password.
	 * @param string $otp      6-digit TOTP code.
	 * @return bool
	 */
	public static function verify_credentials( string $password, string $otp ): bool {
		if ( ! self::is_setup() || '' === $password || '' === $otp ) {
			return false;
		}

		$mk = self::get_mk_via_password( $password );
		if ( false === $mk ) {
			return false;
		}

		$wrap_totp = (string) get_option( self::OPTION_WRAP_TOTP, '' );
		if ( '' === $wrap_totp ) {
			return false;
		}
		$totp_secret = Fotonic_Crypto::unwrap( $wrap_totp, $mk );
		return false !== $totp_secret && Fotonic_TOTP::verify( $otp, $totp_secret, 2 );
	}

	/**
	 * Keyed hash of a reset code.
	 *
	 * @param string $code Plain code.
	 * @return string
	 */
	private static function hash_reset_code( string $code ): string {
		return hash_hmac( 'sha256', $code, wp_salt( 'auth' ) );
	}

	// ---------------------------------------------------------------------------
	// Full vault reset
	// ---------------------------------------------------------------------------

	/**
	 * Completely delete all vault options.
	 *
	 * WARNING: existing PII ciphertext in postmeta will be unrecoverable once
	 * the Master Key is gone.  This is a destructive, last-resort operation.
	 *
	 * @return bool True when options deleted successfully.
	 */
	public static function reset_vault(): bool {
		// v1/v2 options.
		delete_option( self::OPTION_SETUP );
		delete_option( self::OPTION_SALT );      // also OPTION_SALT_PW.
		delete_option( self::OPTION_TOTP );
		delete_option( self::OPTION_WRAP_PW );
		delete_option( self::OPTION_SALT_REC );
		delete_option( self::OPTION_WRAP_REC );
		delete_option( self::OPTION_WRAP_TOTP );
		delete_option( self::OPTION_SCHEME );
		// Recovery phrase options (v2 extension).
		delete_option( self::OPTION_SALT_PHRASE );
		delete_option( self::OPTION_WRAP_PHRASE );
		// Two-phase setup, kit acknowledgment and recovery staging.
		delete_option( self::OPTION_PENDING );
		delete_option( self::OPTION_RECOVERY_ACK );
		delete_option( self::OPTION_PENDING_TOTP );
		// Stale v3 option names — clean up any DB rows left from abandoned v3 path.
		delete_option( 'fotonic_vault_salt_dek_pw' );
		delete_option( 'fotonic_vault_wrap_dek_pw' );
		delete_option( 'fotonic_vault_salt_dek_phrase' );
		delete_option( 'fotonic_vault_wrap_dek_phrase' );
		delete_option( 'fotonic_vault_totp_enc' );
		delete_option( 'fotonic_vault_recovery_code_hash' );
		// Reset-code state dies with the vault it was meant to reset.
		delete_transient( self::TRANSIENT_RESET_CODE );
		delete_transient( self::TRANSIENT_RESET_REQUESTS );
		delete_transient( 'fotonic_vault_recovery_fails_' . get_current_user_id() );
		// A fresh vault starts with a clean unlock/confirm counter, otherwise a
		// user who failed unlocks before resetting is refused at setup confirm.
		delete_transient( 'fotonic_vault_fails_' . get_current_user_id() );
		// Destroying MK strands the wrapped backup private key — it could never be
		// unwrapped again. Drop both halves so the next setup() mints a fresh pair.
		Fotonic_Backup_Keys::delete_all();
		self::lock();
		return true;
	}

	// ---------------------------------------------------------------------------
	// Session key
	// ---------------------------------------------------------------------------

	/**
	 * Return the raw 32-byte Master Key (MK) from the session cookie, or null if
	 * the vault is locked or the cookie is invalid / tampered.
	 *
	 * Cookie format: base64( nonce[12] || GCM-tag[16] || AES-256-GCM-ciphertext[32] )
	 *
	 * @return string|null Raw binary MK, or null.
	 */
	public static function get_session_key(): ?string {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		$blob = base64_decode( sanitize_text_field( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) ), true );

		// 12-byte nonce + 16-byte GCM tag + 64-byte plaintext (MK[32] || binding[32]).
		if ( false === $blob || strlen( $blob ) < 92 ) {
			return null;
		}

		$iv         = substr( $blob, 0, 12 );
		$tag        = substr( $blob, 12, 16 );
		$ciphertext = substr( $blob, 28 );

		$server_key = hash( 'sha256', self::server_secret(), true );

		$plain = openssl_decrypt( $ciphertext, self::CIPHER, $server_key, OPENSSL_RAW_DATA, $iv, $tag );

		// Plaintext must be exactly MK[32] || login-session binding[32].
		if ( false === $plain || strlen( $plain ) !== 64 ) {
			return null;
		}

		$key  = substr( $plain, 0, 32 );
		$bind = substr( $plain, 32, 32 );

		// Reject cookies minted under a different WP login session / user.
		if ( ! hash_equals( self::session_binding(), $bind ) ) {
			return null;
		}

		return $key;
	}

	// ---------------------------------------------------------------------------
	// Session management
	// ---------------------------------------------------------------------------

	/**
	 * Replace the current session cookie with a (potentially new) MK.
	 * Called after a password change or migration to keep the session alive.
	 *
	 * @param string $key 32-byte raw MK.
	 */
	public static function update_session_key( string $key ): void {
		self::set_session_cookie( $key );
	}

	// ---------------------------------------------------------------------------
	// Legacy migration (v1 → v2)
	// ---------------------------------------------------------------------------

	/**
	 * Migrate a legacy scheme-v1 vault to envelope scheme-v2.
	 *
	 * Steps:
	 *  1. Verify credentials against the legacy v1 storage.
	 *  2. Generate a fresh Master Key (MK).
	 *  3. Re-encrypt all PII postmeta: old K → MK (via REST-API helpers).
	 *  4. Wrap: wrap_pw = wrap(MK, KEK_pw), wrap_totp = wrap(totp, MK).
	 *  5. Store new options, set OPTION_SCHEME=2.
	 *  6. Issue session cookie with MK.
	 *
	 * Recovery code is NOT created here — the frontend detects
	 * has_recovery()==false after migration and prompts setup.
	 *
	 * @param string $password Plain-text vault password.
	 * @param string $otp      6-digit TOTP code.
	 * @return bool True on success.
	 */
	public static function migrate_legacy( string $password, string $otp ): bool {
		$old_salt       = (string) get_option( self::OPTION_SALT, '' );
		$encrypted_totp = (string) get_option( self::OPTION_TOTP, '' );

		if ( empty( $old_salt ) || empty( $encrypted_totp ) ) {
			return false;
		}

		// Derive legacy K and decrypt TOTP.
		$old_key     = Fotonic_Crypto::derive_key( $password, $old_salt );
		$totp_secret = Fotonic_Crypto::decrypt( $encrypted_totp, $old_key );

		if ( empty( $totp_secret ) ) {
			return false;
		}

		if ( ! Fotonic_TOTP::verify( $otp, $totp_secret, 2 ) ) {
			return false;
		}

		// Generate a fresh Master Key.
		$mk = random_bytes( 32 );

		// Re-encrypt all PII from old K → MK using the REST-API helpers.
		Fotonic_REST_API::reencrypt_customers( $old_key, $mk );
		Fotonic_REST_API::reencrypt_works( $old_key, $mk );

		/**
		 * Lets Pro re-encrypt its own Vault-protected data (quote people and
		 * addresses) with the new Master Key.
		 *
		 * @param string $old_key Legacy 32-byte key.
		 * @param string $new_key New 32-byte Master Key.
		 */
		do_action( 'ftnc_vault_reencrypt', $old_key, $mk );

		// Wrap password KEK (reuse existing salt for backwards compat).
		$kek_pw  = Fotonic_Crypto::derive_key( $password, $old_salt );
		$wrap_pw = Fotonic_Crypto::wrap( $mk, $kek_pw );
		if ( empty( $wrap_pw ) ) {
			return false;
		}

		// Wrap TOTP under MK.
		$wrap_totp = Fotonic_Crypto::wrap( strtoupper( $totp_secret ), $mk );
		if ( empty( $wrap_totp ) ) {
			return false;
		}

		// Persist.  OPTION_SALT_PW intentionally left as $old_salt (shared key).
		update_option( self::OPTION_WRAP_PW,   $wrap_pw,   false );
		update_option( self::OPTION_WRAP_TOTP, $wrap_totp, false );
		update_option( self::OPTION_SCHEME,    2,          false );
		// Remove the legacy plaintext-under-K TOTP option.
		delete_option( self::OPTION_TOTP );

		self::set_session_cookie( $mk );

		// This is the ONE path that replaces MK rather than re-wrapping it, so any
		// existing backup private key must move to the new MK or it is stranded.
		// In practice a legacy v1 vault has no keypair yet (provisioning only
		// happens on the v2 unlock path, which a v1 vault never reaches), so this
		// is normally a no-op — but it must not silently depend on that.
		Fotonic_Backup_Keys::rewrap( $old_key, $mk );
		Fotonic_Backup_Keys::setup( $mk );

		return true;
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Derive MK from the stored password wrap (scheme v2).
	 * Does NOT verify OTP — use for operations that need MK but verify OTP separately.
	 *
	 * @param string $password Plain-text vault password.
	 * @return string|false 32-byte MK, or false on failure.
	 */
	private static function get_mk_via_password( string $password ) {
		$salt_pw = (string) get_option( self::OPTION_SALT_PW, '' );
		$wrap_pw = (string) get_option( self::OPTION_WRAP_PW, '' );
		if ( empty( $salt_pw ) || empty( $wrap_pw ) ) {
			return false;
		}
		$kek_pw = Fotonic_Crypto::derive_key( $password, $salt_pw );
		$mk     = Fotonic_Crypto::unwrap( $wrap_pw, $kek_pw );
		return ( false === $mk ) ? false : $mk;
	}

	/**
	 * Encrypt MK with the server secret and write the cookie.
	 *
	 * Cookie value: base64( nonce[12] || GCM-tag[16] || AES-256-GCM( MK, hash(AUTH_KEY) ) )
	 *
	 * @param string $key 32-byte MK.
	 * @return void
	 */
	private static function set_session_cookie( string $key ): void {
		$server_key = hash( 'sha256', self::server_secret(), true );
		$iv         = random_bytes( 12 );
		$tag        = '';
		// Bind the session to the current WP login: MK[32] || binding[32].
		$plain      = $key . self::session_binding();
		$ciphertext = openssl_encrypt( $plain, self::CIPHER, $server_key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );

		if ( false === $ciphertext ) {
			return;
		}

		$value = base64_encode( $iv . $tag . $ciphertext );
		self::send_cookie( $value, time() + self::COOKIE_EXPIRY );
	}

	/**
	 * Binding hash that ties a vault session to the current WP login session.
	 *
	 * A fresh login mints a new session token, so a vault cookie issued under a
	 * previous login (or a different user) no longer matches and is rejected by
	 * get_session_key() — forcing a re-unlock per WP login session.
	 *
	 * @return string 32 raw bytes.
	 */
	private static function session_binding(): string {
		$uid   = get_current_user_id();
		$token = function_exists( 'wp_get_session_token' ) ? wp_get_session_token() : '';
		return hash( 'sha256', $uid . '|' . $token, true );
	}

	/**
	 * Emit the Set-Cookie header.
	 *
	 * @param string $value  Cookie value (empty string to clear).
	 * @param int    $expiry Unix timestamp for expiry.
	 * @return void
	 */
	private static function send_cookie( string $value, int $expiry ): void {
		setcookie(
			self::COOKIE_NAME,
			$value,
			array(
				'expires'  => $expiry,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Strict',
			)
		);
		if ( '' === $value ) {
			unset( $_COOKIE[ self::COOKIE_NAME ] );
		} else {
			$_COOKIE[ self::COOKIE_NAME ] = $value;
		}
	}

	/**
	 * Server-side secret used to wrap MK in the cookie.
	 *
	 * @return string
	 */
	private static function server_secret(): string {
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			return AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			return SECURE_AUTH_KEY;
		}
		$fallback = get_option( 'fotonic_server_secret_fallback', '' );
		if ( empty( $fallback ) ) {
			$fallback = wp_generate_password( 64, true, true );
			update_option( 'fotonic_server_secret_fallback', $fallback, false );
		}
		return $fallback;
	}
}
