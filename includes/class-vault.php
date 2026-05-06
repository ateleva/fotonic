<?php
/**
 * Fotonic Vault
 *
 * Manages TOTP-gated AES-256 encryption of PII stored in postmeta.
 * The derived key is never stored in the DB — it lives only in an
 * HTTP-Only, SameSite=Strict session cookie encrypted with WP AUTH_KEY.
 *
 * @package Fotonic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fotonic_Vault {

	const COOKIE_NAME  = 'fotonic_vault_session';
	const OPTION_TOTP  = 'fotonic_vault_totp_secret'; // Stores AES-encrypted TOTP secret.
	const OPTION_SALT  = 'fotonic_vault_salt';          // PBKDF2 salt (random bytes, base64).
	const OPTION_SETUP = 'fotonic_vault_setup';          // bool — whether vault has been set up.

	const COOKIE_EXPIRY = 86400; // 24 hours in seconds.
	const CIPHER        = 'aes-256-cbc';

	// ---------------------------------------------------------------------------
	// Status
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
	 * Whether the vault is currently unlocked (valid session cookie present).
	 *
	 * @return bool
	 */
	public static function is_unlocked(): bool {
		return self::get_session_key() !== null;
	}

	// ---------------------------------------------------------------------------
	// Setup
	// ---------------------------------------------------------------------------

	/**
	 * First-time vault setup: derive key from password, encrypt + store the
	 * TOTP secret, write the PBKDF2 salt, and mark vault as set up.
	 *
	 * @param string $password    Plain-text vault password chosen by the user.
	 * @param string $totp_secret Base32 TOTP secret (from Fotonic_TOTP::generate_secret()).
	 * @return bool True on success.
	 */
	public static function setup( string $password, string $totp_secret ): bool {
		if ( empty( $password ) || empty( $totp_secret ) ) {
			return false;
		}

		// Generate a fresh PBKDF2 salt.
		$salt = base64_encode( random_bytes( 32 ) );

		// Derive the vault key from the password.
		$key = Fotonic_Crypto::derive_key( $password, $salt );

		// Encrypt the TOTP secret with the derived key so we can verify OTPs on unlock.
		$encrypted_totp = Fotonic_Crypto::encrypt( strtoupper( $totp_secret ), $key );
		if ( empty( $encrypted_totp ) ) {
			return false;
		}

		// Persist.
		update_option( self::OPTION_SALT,  $salt,           false );
		update_option( self::OPTION_TOTP,  $encrypted_totp, false );
		update_option( self::OPTION_SETUP, true,            false );

		return true;
	}

	// ---------------------------------------------------------------------------
	// Unlock / Lock
	// ---------------------------------------------------------------------------

	/**
	 * Verify password + TOTP OTP, then issue a session cookie containing the
	 * derived key encrypted with WP's AUTH_KEY.
	 *
	 * @param string $password Plain-text vault password.
	 * @param string $otp      6-digit TOTP code.
	 * @return bool True on success; false if password or OTP is wrong.
	 */
	public static function unlock( string $password, string $otp ): bool {
		if ( ! self::is_setup() ) {
			return false;
		}

		$salt           = (string) get_option( self::OPTION_SALT, '' );
		$encrypted_totp = (string) get_option( self::OPTION_TOTP, '' );

		if ( empty( $salt ) || empty( $encrypted_totp ) ) {
			return false;
		}

		// Derive key candidate from supplied password.
		$key = Fotonic_Crypto::derive_key( $password, $salt );

		// Attempt to decrypt the stored TOTP secret — if the password is wrong
		// the decryption will yield garbage, and the TOTP verify below will fail.
		$totp_secret = Fotonic_Crypto::decrypt( $encrypted_totp, $key );
		if ( empty( $totp_secret ) ) {
			return false;
		}

		// Verify the OTP code against the (now decrypted) secret.
		if ( ! Fotonic_TOTP::verify( $otp, $totp_secret ) ) {
			return false;
		}

		// All checks passed — issue the session cookie.
		self::set_session_cookie( $key );

		return true;
	}

	/**
	 * Destroy the vault session cookie.
	 *
	 * @return void
	 */
	public static function lock(): void {
		// Expire cookie in the past.
		self::send_cookie( '', time() - 3600 );
		// Also clear from the current request superglobal.
		unset( $_COOKIE[ self::COOKIE_NAME ] );
	}

	// ---------------------------------------------------------------------------
	// Session key
	// ---------------------------------------------------------------------------

	/**
	 * Return the raw 32-byte AES-256 key from the session cookie, or null if
	 * the vault is locked or the cookie is invalid / tampered.
	 *
	 * @return string|null Raw binary key, or null.
	 */
	public static function get_session_key(): ?string {
		if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
			return null;
		}

		$server_secret = self::server_secret();
		$blob          = base64_decode( $_COOKIE[ self::COOKIE_NAME ], true );

		if ( false === $blob || strlen( $blob ) < 16 ) {
			return null;
		}

		$iv         = substr( $blob, 0, 16 );
		$ciphertext = substr( $blob, 16 );

		// The server_secret is a string, not necessarily 32 bytes — hash it to get a proper key.
		$server_key = hash( 'sha256', $server_secret, true );

		$key = openssl_decrypt( $ciphertext, self::CIPHER, $server_key, OPENSSL_RAW_DATA, $iv );

		if ( false === $key || strlen( $key ) !== 32 ) {
			return null;
		}

		return $key;
	}

	// ---------------------------------------------------------------------------
	// Session management
	// ---------------------------------------------------------------------------

	/**
	 * Replace the current session cookie with a new derived key.
	 * Called after a vault password change to keep the session alive.
	 *
	 * @param string $key 32-byte raw derived key.
	 */
	public static function update_session_key( string $key ): void {
		self::set_session_cookie( $key );
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Encrypt the derived key with the server secret and write the cookie.
	 *
	 * Cookie value: base64( IV || AES-256-CBC( derived_key, hash(AUTH_KEY) ) )
	 *
	 * @param string $key 32-byte derived key.
	 * @return void
	 */
	private static function set_session_cookie( string $key ): void {
		$server_key = hash( 'sha256', self::server_secret(), true );
		$iv         = random_bytes( 16 );
		$ciphertext = openssl_encrypt( $key, self::CIPHER, $server_key, OPENSSL_RAW_DATA, $iv );

		if ( false === $ciphertext ) {
			return;
		}

		$value = base64_encode( $iv . $ciphertext );
		self::send_cookie( $value, time() + self::COOKIE_EXPIRY );
	}

	/**
	 * Emit the Set-Cookie header.
	 *
	 * Uses header() directly so we can append SameSite=Strict, which
	 * setcookie() only supports in PHP 7.3+ via the $options array form.
	 *
	 * @param string $value  Cookie value (empty string to clear).
	 * @param int    $expiry Unix timestamp for expiry.
	 * @return void
	 */
	private static function send_cookie( string $value, int $expiry ): void {
		$secure   = is_ssl() ? '; Secure' : '';
		$path     = COOKIEPATH ?: '/';
		$domain   = COOKIE_DOMAIN ?: '';
		$domain_s = $domain ? '; Domain=' . $domain : '';

		$cookie_line = sprintf(
			'%s=%s; Expires=%s; Max-Age=%d; Path=%s%s; HttpOnly%s; SameSite=Strict',
			self::COOKIE_NAME,
			rawurlencode( $value ),
			gmdate( 'D, d M Y H:i:s T', $expiry ),
			max( 0, $expiry - time() ),
			$path,
			$domain_s,
			$secure
		);

		header( 'Set-Cookie: ' . $cookie_line, false );

		// Also update the in-process superglobal so same-request reads work.
		if ( '' === $value ) {
			unset( $_COOKIE[ self::COOKIE_NAME ] );
		} else {
			$_COOKIE[ self::COOKIE_NAME ] = $value;
		}
	}

	/**
	 * Server-side secret used to wrap the derived key in the cookie.
	 * Falls back to SECURE_AUTH_KEY then a site-specific string if AUTH_KEY
	 * is not defined (should always be defined in wp-config.php).
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
		// Last-resort: site URL + DB prefix (not ideal but avoids a hard failure).
		return get_site_url() . ( isset( $GLOBALS['wpdb'] ) ? $GLOBALS['wpdb']->prefix : '' );
	}
}
