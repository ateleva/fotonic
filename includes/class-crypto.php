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
     * Deterministic encryption: same input always produces same output.
     * Use ONLY for searchable fields (email, phone). Not for sensitive free-text.
     */
    public static function deterministic_encrypt( string $value, string $key ): string {
        $iv = substr( hash( 'sha256', $key . 'fotonic_det_iv_v1', true ), 0, 16 );
        $ct = openssl_encrypt( $value, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv );
        return ( false === $ct ) ? '' : base64_encode( $ct );
    }

    /**
     * Derive AES-256 key from vault password using PBKDF2-SHA256, 100k iterations.
     */
    public static function derive_key( string $vault_password, string $salt ): string {
        return hash_pbkdf2( 'sha256', $vault_password, $salt, 100000, 32, true );
    }
}
