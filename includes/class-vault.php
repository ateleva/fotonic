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
	 * First-time vault setup using envelope encryption.
	 *
	 * Generates a random Master Key, wraps it under the password KEK, a
	 * fresh recovery code KEK, and a recovery phrase KEK.  Wraps the TOTP
	 * secret under MK.  Stores everything and issues the session cookie.
	 * Returns the recovery code and recovery phrase ONCE — neither is stored
	 * in plaintext.
	 *
	 * @param string $password    Plain-text vault password.
	 * @param string $totp_secret Base32 TOTP secret.
	 * @return array { ok: bool, recovery_code: string, recovery_phrase: string }
	 */
	public static function setup( string $password, string $totp_secret ): array {
		$fail = array( 'ok' => false, 'recovery_code' => '', 'recovery_phrase' => '' );

		if ( empty( $password ) || empty( $totp_secret ) ) {
			return $fail;
		}

		// Master Key — the stable key that encrypts all PII.
		$mk = random_bytes( 32 );

		// Password KEK.
		$salt_pw  = base64_encode( random_bytes( 32 ) );
		$kek_pw   = Fotonic_Crypto::derive_key( $password, $salt_pw );
		$wrap_pw  = Fotonic_Crypto::wrap( $mk, $kek_pw );
		if ( empty( $wrap_pw ) ) {
			return $fail;
		}

		// Recovery code KEK.
		$recovery_code = Fotonic_Crypto::generate_recovery_code();
		$salt_rec      = base64_encode( random_bytes( 32 ) );
		$kek_rec       = Fotonic_Crypto::derive_key( Fotonic_Crypto::normalize_recovery_code( $recovery_code ), $salt_rec );
		$wrap_rec      = Fotonic_Crypto::wrap( $mk, $kek_rec );
		if ( empty( $wrap_rec ) ) {
			return $fail;
		}

		// Recovery phrase KEK. Normalize (strip dashes/spaces, uppercase) so the
		// reset path matches regardless of how the user types the phrase back.
		$phrase      = Fotonic_TOTP::generate_recovery_phrase();
		$salt_phrase = base64_encode( random_bytes( 32 ) );
		$kek_phrase  = Fotonic_Crypto::derive_key( Fotonic_Crypto::normalize_recovery_code( $phrase ), $salt_phrase );
		$wrap_phrase = Fotonic_Crypto::wrap( $mk, $kek_phrase );
		if ( empty( $wrap_phrase ) ) {
			return $fail;
		}

		// Wrap TOTP secret under MK.
		$wrap_totp = Fotonic_Crypto::wrap( strtoupper( $totp_secret ), $mk );
		if ( empty( $wrap_totp ) ) {
			return $fail;
		}

		// Persist all envelope options.
		update_option( self::OPTION_SALT_PW,     $salt_pw,     false );
		update_option( self::OPTION_WRAP_PW,     $wrap_pw,     false );
		update_option( self::OPTION_SALT_REC,    $salt_rec,    false );
		update_option( self::OPTION_WRAP_REC,    $wrap_rec,    false );
		update_option( self::OPTION_WRAP_TOTP,   $wrap_totp,   false );
		update_option( self::OPTION_SALT_PHRASE, $salt_phrase, false );
		update_option( self::OPTION_WRAP_PHRASE, $wrap_phrase, false );
		update_option( self::OPTION_SCHEME,      2,            false );
		update_option( self::OPTION_SETUP,       true,         false );

		// Issue session cookie with MK.
		self::set_session_cookie( $mk );

		return array( 'ok' => true, 'recovery_code' => $recovery_code, 'recovery_phrase' => $phrase );
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
	// Recovery: reset password via recovery code
	// ---------------------------------------------------------------------------

	/**
	 * Reset the vault password using a recovery code (lost TOTP path).
	 *
	 * Verifies the recovery code unwraps wrap_rec, then re-wraps MK under a
	 * new password KEK.  The session cookie is set so the vault is immediately
	 * unlocked after the reset.
	 *
	 * @param string $recovery_code Raw recovery code (with or without dashes).
	 * @param string $new_pw        New password.
	 * @return bool True on success.
	 */
	public static function recover_reset_password( string $recovery_code, string $new_pw ): bool {
		if ( empty( $recovery_code ) || empty( $new_pw ) ) {
			return false;
		}

		$salt_rec = (string) get_option( self::OPTION_SALT_REC, '' );
		$wrap_rec = (string) get_option( self::OPTION_WRAP_REC, '' );
		if ( empty( $salt_rec ) || empty( $wrap_rec ) ) {
			return false;
		}

		$normalized = Fotonic_Crypto::normalize_recovery_code( $recovery_code );
		$kek_rec    = Fotonic_Crypto::derive_key( $normalized, $salt_rec );
		$mk         = Fotonic_Crypto::unwrap( $wrap_rec, $kek_rec );
		if ( false === $mk ) {
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

		self::set_session_cookie( $mk );
		return true;
	}

	// ---------------------------------------------------------------------------
	// Recovery: reset TOTP via password + recovery code (both required)
	// ---------------------------------------------------------------------------

	/**
	 * Reset the TOTP secret when the user still knows the password but has
	 * lost their authenticator device.  Requires BOTH password AND recovery
	 * code to prevent one-factor downgrade.
	 *
	 * @param string $password      Current vault password.
	 * @param string $recovery_code Raw recovery code.
	 * @return array|false Array with 'totp_secret' and 'qr_uri' on success, false on failure.
	 */
	public static function recover_reset_totp( string $password, string $recovery_code ) {
		if ( empty( $password ) || empty( $recovery_code ) ) {
			return false;
		}

		// Verify password path — get MK.
		$mk = self::get_mk_via_password( $password );
		if ( false === $mk ) {
			return false;
		}

		// Also verify recovery code independently (dual-factor requirement).
		$salt_rec = (string) get_option( self::OPTION_SALT_REC, '' );
		$wrap_rec = (string) get_option( self::OPTION_WRAP_REC, '' );
		if ( empty( $salt_rec ) || empty( $wrap_rec ) ) {
			return false;
		}
		$normalized = Fotonic_Crypto::normalize_recovery_code( $recovery_code );
		$kek_rec    = Fotonic_Crypto::derive_key( $normalized, $salt_rec );
		$mk_via_rec = Fotonic_Crypto::unwrap( $wrap_rec, $kek_rec );
		if ( false === $mk_via_rec ) {
			return false;
		}

		// Generate a new TOTP secret and wrap it under MK.
		$new_totp_secret = Fotonic_TOTP::generate_secret();
		$new_wrap_totp   = Fotonic_Crypto::wrap( strtoupper( $new_totp_secret ), $mk );
		if ( empty( $new_wrap_totp ) ) {
			return false;
		}

		update_option( self::OPTION_WRAP_TOTP, $new_wrap_totp, false );

		$site_name = get_bloginfo( 'name' ) ?: 'Eleva CRM';
		$label     = $site_name . ':' . wp_get_current_user()->user_email;
		$qr_uri    = Fotonic_TOTP::get_uri( $new_totp_secret, $label, $site_name );

		return array(
			'totp_secret' => $new_totp_secret,
			'qr_uri'      => $qr_uri,
		);
	}

	// ---------------------------------------------------------------------------
	// Recovery: regenerate recovery code (requires active session)
	// ---------------------------------------------------------------------------

	/**
	 * Generate a new recovery code, re-wrapping MK under the new KEK.
	 * Requires the vault to be unlocked (active session).
	 *
	 * @return array|false Array with 'recovery_code' on success, false otherwise.
	 */
	public static function regenerate_recovery_code() {
		$mk = self::get_session_key();
		if ( null === $mk ) {
			return false;
		}

		$new_code     = Fotonic_Crypto::generate_recovery_code();
		$new_salt_rec = base64_encode( random_bytes( 32 ) );
		$normalized   = Fotonic_Crypto::normalize_recovery_code( $new_code );
		$new_kek_rec  = Fotonic_Crypto::derive_key( $normalized, $new_salt_rec );
		$new_wrap_rec = Fotonic_Crypto::wrap( $mk, $new_kek_rec );
		if ( empty( $new_wrap_rec ) ) {
			return false;
		}

		update_option( self::OPTION_SALT_REC, $new_salt_rec, false );
		update_option( self::OPTION_WRAP_REC, $new_wrap_rec, false );

		return array( 'recovery_code' => $new_code );
	}

	// ---------------------------------------------------------------------------
	// Recovery: enroll recovery phrase (requires active session)
	// ---------------------------------------------------------------------------

	/**
	 * Generate a new recovery phrase, wrapping MK under the phrase KEK.
	 * Requires the vault to be unlocked (active session cookie).
	 *
	 * @return array|false Array with 'recovery_phrase' on success, false otherwise.
	 */
	public static function enroll_recovery_phrase() {
		$mk = self::get_session_key();
		if ( null === $mk ) {
			return false;
		}

		$phrase      = Fotonic_TOTP::generate_recovery_phrase();
		$salt_phrase = base64_encode( random_bytes( 32 ) );
		$kek_phrase  = Fotonic_Crypto::derive_key( Fotonic_Crypto::normalize_recovery_code( $phrase ), $salt_phrase );
		$wrap_phrase = Fotonic_Crypto::wrap( $mk, $kek_phrase );
		if ( empty( $wrap_phrase ) ) {
			return false;
		}

		update_option( self::OPTION_SALT_PHRASE, $salt_phrase, false );
		update_option( self::OPTION_WRAP_PHRASE, $wrap_phrase, false );

		return array( 'recovery_phrase' => $phrase );
	}

	// ---------------------------------------------------------------------------
	// Recovery: reset password via recovery phrase
	// ---------------------------------------------------------------------------

	/**
	 * Reset the vault password using a recovery phrase (lost-password path).
	 *
	 * Verifies the phrase unwraps wrap_phrase, then re-wraps MK under a new
	 * password KEK.  The session cookie is set so the vault is immediately
	 * unlocked after the reset.
	 *
	 * @param string $recovery_phrase Raw recovery phrase (with or without dashes).
	 * @param string $new_pw          New password.
	 * @return bool True on success.
	 */
	public static function recover_reset_password_via_phrase( string $recovery_phrase, string $new_pw ): bool {
		if ( empty( $recovery_phrase ) || empty( $new_pw ) ) {
			return false;
		}

		$salt_phrase = (string) get_option( self::OPTION_SALT_PHRASE, '' );
		$wrap_phrase = (string) get_option( self::OPTION_WRAP_PHRASE, '' );
		if ( empty( $salt_phrase ) || empty( $wrap_phrase ) ) {
			return false;
		}

		// Normalise (strip dashes/spaces, uppercase) — must match the enrol path.
		$kek_phrase = Fotonic_Crypto::derive_key( Fotonic_Crypto::normalize_recovery_code( $recovery_phrase ), $salt_phrase );
		$mk         = Fotonic_Crypto::unwrap( $wrap_phrase, $kek_phrase );
		if ( false === $mk ) {
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

		self::set_session_cookie( $mk );
		return true;
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
		// Stale v3 option names — clean up any DB rows left from abandoned v3 path.
		delete_option( 'fotonic_vault_salt_dek_pw' );
		delete_option( 'fotonic_vault_wrap_dek_pw' );
		delete_option( 'fotonic_vault_salt_dek_phrase' );
		delete_option( 'fotonic_vault_wrap_dek_phrase' );
		delete_option( 'fotonic_vault_totp_enc' );
		delete_option( 'fotonic_vault_recovery_code_hash' );
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
