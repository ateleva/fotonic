<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_Vault {
    const COOKIE_NAME    = 'fotonic_vault_session';
    const SESSION_EXPIRY = 28800; // 8 hours

    public static function is_unlocked(): bool {
        return ! empty( $_COOKIE[ self::COOKIE_NAME ] );
    }

    /**
     * Validate vault password + TOTP OTP, then set HTTP-Only session cookie.
     * Returns true on success. Key never stored in DB.
     */
    public static function unlock( string $password, string $otp ): bool {
        // TODO: implement TOTP verification via vendor TOTP library (Phase: feature dev)
        // TODO: derive key from password + stored salt
        // TODO: set HTTP-Only, Secure, SameSite=Strict cookie with encrypted session key
        return false;
    }

    public static function lock(): void {
        setcookie(
            self::COOKIE_NAME,
            '',
            time() - 3600,
            COOKIEPATH,
            COOKIE_DOMAIN,
            is_ssl(),
            true // httponly
        );
    }

    /**
     * Returns AES-256 key from session cookie, or null if vault locked.
     * Key is decrypted from cookie using WP auth salt as secondary key.
     */
    public static function get_session_key(): ?string {
        if ( ! self::is_unlocked() ) {
            return null;
        }
        // TODO: decrypt key from session cookie value
        return null;
    }
}
