<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Vault layer for stored files (quote PDFs, from Pro): authenticated encryption of a whole file
 * with a key derived from the Vault Master Key.
 *
 * File format (CONTRACT: tools/eleva-backup-decrypt.php reads it too):
 *   "FTNCFIL1" (8 bytes) | iv (12) | GCM tag (16) | ciphertext
 *   key = HKDF-SHA256(MK, info "ftnc-file-v1", 32 bytes); AAD = the 8-byte magic.
 *
 * The file is kept in memory while it is processed: it is meant for documents of a few hundred
 * kilobytes, not for galleries. Files keep a ".enc" suffix after their own extension (name.pdf.enc),
 * so no web server ever serves one as a PDF.
 */
class Fotonic_File_Crypt {
    const MAGIC  = 'FTNCFIL1';
    const SUFFIX = '.enc';
    const INFO   = 'ftnc-file-v1';
    const CIPHER = 'aes-256-gcm';

    /** Sub-key for files, so the Master Key itself is never used as a file key. */
    public static function file_key( string $master_key ): string {
        return hash_hkdf( 'sha256', $master_key, 32, self::INFO );
    }

    /** @return string|false MAGIC | iv | tag | ciphertext, or false on failure. */
    public static function encrypt( string $plain, string $master_key ) {
        if ( 32 !== strlen( $master_key ) ) {
            return false;
        }
        $iv  = random_bytes( 12 );
        $tag = '';
        $ct  = openssl_encrypt( $plain, self::CIPHER, self::file_key( $master_key ), OPENSSL_RAW_DATA, $iv, $tag, self::MAGIC, 16 );
        if ( false === $ct || 16 !== strlen( $tag ) ) {
            return false;
        }
        return self::MAGIC . $iv . $tag . $ct;
    }

    /** @return string|false The plaintext, or false when the blob is not ours, was altered, or the key is wrong. */
    public static function decrypt( string $blob, string $master_key ) {
        if ( 32 !== strlen( $master_key ) || strlen( $blob ) < 36 || 0 !== strncmp( $blob, self::MAGIC, 8 ) ) {
            return false;
        }
        return openssl_decrypt( substr( $blob, 36 ), self::CIPHER, self::file_key( $master_key ), OPENSSL_RAW_DATA, substr( $blob, 8, 12 ), substr( $blob, 20, 16 ), self::MAGIC );
    }

    /** True when the file at $path starts with the magic. */
    public static function is_encrypted( string $path ): bool {
        if ( ! is_file( $path ) || ! is_readable( $path ) ) {
            return false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reads 8 bytes of a local file.
        $h = fopen( $path, 'rb' );
        if ( false === $h ) {
            return false;
        }
        $head = fread( $h, 8 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
        fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        return self::MAGIC === $head;
    }

    /** @return string|false */
    public static function decrypt_file( string $path, string $master_key ) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file inside uploads, path built by the caller.
        $blob = is_file( $path ) ? file_get_contents( $path ) : false;
        return false === $blob ? false : self::decrypt( $blob, $master_key );
    }

    /**
     * Encrypt $source into $target and check that $target decrypts back to the same bytes before
     * it replaces anything: a half-written or wrong file never survives.
     */
    public static function encrypt_file( string $source, string $target, string $master_key ): bool {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- see decrypt_file().
        $plain = is_file( $source ) ? file_get_contents( $source ) : false;
        if ( false === $plain ) {
            return false;
        }
        $blob = self::encrypt( $plain, $master_key );
        if ( false === $blob ) {
            return false;
        }
        $tmp = $target . '.part';
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- own folder, temp name next to the target.
        if ( strlen( $blob ) !== file_put_contents( $tmp, $blob ) ) {
            wp_delete_file( $tmp );
            return false;
        }
        $check = self::decrypt_file( $tmp, $master_key );
        if ( ! is_string( $check ) || ! hash_equals( hash( 'sha256', $plain ), hash( 'sha256', $check ) ) || ! rename( $tmp, $target ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- same folder.
            wp_delete_file( $tmp );
            return false;
        }
        return true;
    }

    /** "name.pdf.enc" -> "name.pdf": the name a person should see and download. */
    public static function plain_name( string $name ): string {
        $len = strlen( self::SUFFIX );
        return strlen( $name ) > $len && self::SUFFIX === substr( $name, -$len ) ? substr( $name, 0, -$len ) : $name;
    }
}
