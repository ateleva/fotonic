<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Vault layer for free-text fields (notes, service titles, ...): authenticated, prefixed.
 *
 * Format: 'f1:' . base64( iv[12] | tag[16] | ciphertext ), AES-256-GCM, key =
 * HKDF-SHA256( Master Key, "ftnc-field-v1" ), the AAD is the string "ftnc-field-v1".
 *
 * Unlike the older v1 fields (AES-CBC, no prefix, no MAC) a value never needs guessing: a stored
 * value with the prefix is ciphertext, anything else is plain text. The one ambiguity, a plain
 * text that itself starts with "f1:" or "f0:", is removed by escaping it ("f0:" + text) when it is
 * stored without a key; read() strips the escape.
 *
 * Reading never invents data: no key, a wrong key or a damaged value gives null, never ''.
 */
class Fotonic_Field_Crypt {
	const PREFIX = 'f1:';
	const ESCAPE = 'f0:';
	const INFO   = 'ftnc-field-v1';
	const CIPHER = 'aes-256-gcm';

	/** Sub-key for fields, so the Master Key itself is never used as a field key. */
	public static function field_key( string $master_key ): string {
		return hash_hkdf( 'sha256', $master_key, 32, self::INFO );
	}

	/** True when the stored value is ciphertext of this layer. */
	public static function is_encrypted( string $value ): bool {
		return 0 === strncmp( $value, self::PREFIX, 3 );
	}

	/**
	 * @param string $plain      Plain text (may be empty).
	 * @param string $master_key 32-byte Master Key.
	 * @return string|false 'f1:...' (an empty text stays empty), or false on failure.
	 */
	public static function encrypt( string $plain, string $master_key ) {
		if ( 32 !== strlen( $master_key ) ) {
			return false;
		}
		if ( '' === $plain ) {
			return '';
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, self::CIPHER, self::field_key( $master_key ), OPENSSL_RAW_DATA, $iv, $tag, self::INFO, 16 );
		if ( false === $ct || 16 !== strlen( $tag ) ) {
			return false;
		}
		return self::PREFIX . base64_encode( $iv . $tag . $ct );
	}

	/**
	 * @param string $value      Stored value with the prefix.
	 * @param string $master_key 32-byte Master Key.
	 * @return string|false The plain text, or false when the value is not ours, damaged, or the key is wrong.
	 */
	public static function decrypt( string $value, string $master_key ) {
		if ( 32 !== strlen( $master_key ) || ! self::is_encrypted( $value ) ) {
			return false;
		}
		$raw = base64_decode( substr( $value, 3 ), true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return false;
		}
		return openssl_decrypt( substr( $raw, 28 ), self::CIPHER, self::field_key( $master_key ), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), self::INFO );
	}

	/**
	 * The value to store for a plain text: encrypted when there is a key, otherwise the plain text
	 * (escaped when it could be mistaken for ciphertext).
	 *
	 * @param string      $plain Plain text.
	 * @param string|null $key   Master Key or null.
	 * @return string|false The stored form, or false when encryption failed (never store plain then).
	 */
	public static function store( string $plain, ?string $key ) {
		if ( null !== $key ) {
			return self::encrypt( $plain, $key );
		}
		if ( 0 === strncmp( $plain, self::PREFIX, 3 ) || 0 === strncmp( $plain, self::ESCAPE, 3 ) ) {
			return self::ESCAPE . $plain;
		}
		return $plain;
	}

	/**
	 * The plain text of a stored value.
	 *
	 * @param string      $stored Stored value.
	 * @param string|null $key    Master Key or null.
	 * @return string|null Plain text, or null when it cannot be read (no key, wrong key, damaged).
	 */
	public static function read( string $stored, ?string $key ): ?string {
		if ( 0 === strncmp( $stored, self::ESCAPE, 3 ) ) {
			return substr( $stored, 3 );
		}
		if ( ! self::is_encrypted( $stored ) ) {
			return $stored;
		}
		if ( null === $key ) {
			return null;
		}
		$plain = self::decrypt( $stored, $key );
		return false === $plain ? null : $plain;
	}
}
