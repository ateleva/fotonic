<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_Crypto {
    const CIPHER     = 'aes-256-cbc';
    const GCM_CIPHER = 'aes-256-gcm';

    /**
     * Encrypt plaintext with random IV. Returns base64(iv + ciphertext).
     */
    public static function encrypt( string $plaintext, string $key ): string {
        $iv         = random_bytes( 16 );
        $ciphertext = openssl_encrypt( $plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
        if ( false === $ciphertext ) {
            return '';
        }
        return base64_encode( $iv . $ciphertext );
    }

    /**
     * Decrypt base64(iv + ciphertext) back to plaintext.
     */
    public static function decrypt( string $encoded, string $key ): string {
        $raw        = base64_decode( $encoded );
        $iv         = substr( $raw, 0, 16 );
        $ciphertext = substr( $raw, 16 );
        $result     = openssl_decrypt( $ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
        return ( false === $result ) ? '' : $result;
    }

    /**
     * Deterministic encryption: same input always → same output (searchable).
     * Uses HMAC-SHA256(key, value) as IV so different values produce different IVs,
     * eliminating ECB-mode prefix leakage from a fixed IV.
     * Output format: 'v1d:' . base64( HMAC_IV[16] || AES-256-CBC_ciphertext )
     * The IV is included in the output so decryption does not require the plaintext.
     */
    public static function deterministic_encrypt( string $value, string $key ): string {
        // Per-value IV: HMAC-SHA256(key, value) — deterministic per (key, value) pair.
        $iv = substr( hash_hmac( 'sha256', $value, $key, true ), 0, 16 );
        $ct = openssl_encrypt( $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
        return ( false === $ct ) ? '' : 'v1d:' . base64_encode( $iv . $ct );
    }

    /**
     * Decrypt a value produced by deterministic_encrypt().
     * Handles the 'v1d:' prefix and passes the rest to the standard decrypt().
     */
    public static function deterministic_decrypt( string $encoded, string $key ): string {
        if ( strncmp( $encoded, 'v1d:', 4 ) === 0 ) {
            $encoded = substr( $encoded, 4 );
        }
        return self::decrypt( $encoded, $key );
    }

    /**
     * Derive AES-256 key from vault password using PBKDF2-SHA256.
     * 600k iterations matches OWASP 2023 guidance for PBKDF2-HMAC-SHA256.
     */
    const PBKDF2_ITERATIONS = 600000;

    public static function derive_key( string $vault_password, string $salt ): string {
        return hash_pbkdf2( 'sha256', $vault_password, $salt, self::PBKDF2_ITERATIONS, 32, true );
    }

    // ---------------------------------------------------------------------------
    // GCM key-wrap helpers (envelope encryption, scheme v2)
    // ---------------------------------------------------------------------------

    /**
     * Wrap (encrypt) a plaintext key material with a key-encryption key (KEK)
     * using AES-256-GCM.
     *
     * Output format: 'w1:' . base64( iv[12] || tag[16] || ciphertext )
     *
     * @param string $plaintext Raw bytes to protect (e.g. Master Key or TOTP secret).
     * @param string $kek       32-byte key-encryption key.
     * @return string Wrapped blob, or '' on failure.
     */
    public static function wrap( string $plaintext, string $kek ): string {
        $iv  = random_bytes( 12 );
        $tag = '';
        $ct  = openssl_encrypt( $plaintext, self::GCM_CIPHER, $kek, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
        if ( false === $ct ) {
            return '';
        }
        return 'w1:' . base64_encode( $iv . $tag . $ct );
    }

    /**
     * Unwrap (decrypt + authenticate) a blob produced by wrap().
     * Returns the original plaintext, or false if authentication fails or
     * the blob is malformed.
     *
     * @param string $wrapped Blob with 'w1:' prefix.
     * @param string $kek     32-byte key-encryption key.
     * @return string|false Plaintext, or false on any error.
     */
    public static function unwrap( string $wrapped, string $kek ) {
        if ( strncmp( $wrapped, 'w1:', 3 ) !== 0 ) {
            return false;
        }
        $raw = base64_decode( substr( $wrapped, 3 ), true );
        // iv[12] + tag[16] + at least 1 byte of ciphertext.
        if ( false === $raw || strlen( $raw ) < 29 ) {
            return false;
        }
        $iv  = substr( $raw, 0, 12 );
        $tag = substr( $raw, 12, 16 );
        $ct  = substr( $raw, 28 );
        $pt  = openssl_decrypt( $ct, self::GCM_CIPHER, $kek, OPENSSL_RAW_DATA, $iv, $tag );
        return ( false === $pt ) ? false : $pt;
    }

    // ---------------------------------------------------------------------------
    // Recovery-code helpers
    // ---------------------------------------------------------------------------

    /**
     * Generate a one-time recovery code as 5 groups of 4 characters from the
     * Base32 alphabet (A-Z, 2-7), e.g. "ABCD-2345-WXYZ-FG7H-MNPQ".
     * Uses random_bytes() for cryptographically-secure entropy.
     *
     * @return string 24-character formatted recovery code (20 chars + 4 dashes).
     */
    public static function generate_recovery_code(): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $raw      = random_bytes( 20 );
        $chars    = '';
        for ( $i = 0; $i < 20; $i++ ) {
            $chars .= $alphabet[ ord( $raw[ $i ] ) % 32 ];
        }
        return implode( '-', str_split( $chars, 4 ) );
    }

    /**
     * Normalize a user-supplied recovery code: strip non-alphanumeric characters
     * and convert to uppercase, so both "ABCD-2345-WXYZ-FG7H-MNPQ" and
     * "abcd23456wxyzfg7hmnpq" hash to the same key-derivation input.
     *
     * @param string $code Raw code from user.
     * @return string Normalized code.
     */
    public static function normalize_recovery_code( string $code ): string {
        return strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '', $code ) );
    }

}
