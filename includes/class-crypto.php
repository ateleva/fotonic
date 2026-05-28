<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_Crypto {
    const CIPHER = 'aes-256-cbc';

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
}
