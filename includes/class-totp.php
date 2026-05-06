<?php
/**
 * Fotonic TOTP — RFC 6238 Time-based One-Time Password
 *
 * Manual implementation — no Composer, no external libraries.
 * Algorithm: HMAC-SHA1, 30-second time step, 6 digits, ±1 window tolerance.
 *
 * @package Fotonic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fotonic_TOTP {

	const TIME_STEP = 30;
	const DIGITS    = 6;

	// Base32 alphabet (RFC 4648).
	const BASE32_CHARS = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	// ---------------------------------------------------------------------------
	// Public API
	// ---------------------------------------------------------------------------

	/**
	 * Generate a random 16-character base32 secret suitable for TOTP setup.
	 *
	 * @return string 16-char uppercase base32 string.
	 */
	public static function generate_secret(): string {
		$alphabet = self::BASE32_CHARS;
		$secret   = '';
		$bytes    = random_bytes( 10 ); // 10 bytes → 80 bits → 16 base32 chars.

		// Encode 10 bytes → 16 base32 characters.
		$n = 0;
		$bits = 0;
		$value = 0;
		for ( $i = 0; $i < strlen( $bytes ); $i++ ) {
			$value = ( $value << 8 ) | ord( $bytes[ $i ] );
			$bits  += 8;
			while ( $bits >= 5 ) {
				$bits    -= 5;
				$secret  .= $alphabet[ ( $value >> $bits ) & 0x1F ];
				$n++;
			}
		}
		// Pad remaining bits if needed.
		if ( $bits > 0 ) {
			$secret .= $alphabet[ ( $value << ( 5 - $bits ) ) & 0x1F ];
		}

		return strtoupper( substr( $secret, 0, 16 ) );
	}

	/**
	 * Build an otpauth:// URI for use in QR codes.
	 *
	 * @param string $secret  Base32-encoded TOTP secret.
	 * @param string $label   Account label (e.g. user email or site name).
	 * @param string $issuer  Issuer name shown in authenticator apps.
	 * @return string otpauth://totp/... URI.
	 */
	public static function get_uri( string $secret, string $label, string $issuer = 'Fotonic' ): string {
		$params = http_build_query( [
			'secret' => strtoupper( $secret ),
			'issuer' => $issuer,
			'algorithm' => 'SHA1',
			'digits'    => self::DIGITS,
			'period'    => self::TIME_STEP,
		] );

		return 'otpauth://totp/' . rawurlencode( $label ) . '?' . $params;
	}

	/**
	 * Verify a 6-digit TOTP code with ±window tolerance.
	 *
	 * @param string $code   6-digit code to verify.
	 * @param string $secret Base32-encoded TOTP secret.
	 * @param int    $window Number of steps to check on each side (default 1 → prev/current/next).
	 * @return bool True if the code is valid within the tolerance window.
	 */
	public static function verify( string $code, string $secret, int $window = 1 ): bool {
		$code = trim( $code );

		// Must be exactly 6 digits.
		if ( ! preg_match( '/^\d{6}$/', $code ) ) {
			return false;
		}

		$timestamp = (int) floor( time() / self::TIME_STEP );

		for ( $i = -$window; $i <= $window; $i++ ) {
			$counter   = $timestamp + $i;
			$expected  = self::hotp( $secret, $counter );
			if ( hash_equals( $expected, $code ) ) {
				return true;
			}
		}

		return false;
	}

	// ---------------------------------------------------------------------------
	// Private helpers
	// ---------------------------------------------------------------------------

	/**
	 * Compute an HMAC-based OTP (HOTP) for a given secret and counter.
	 * RFC 4226 — HMAC-SHA1 truncation to 6 digits.
	 *
	 * @param string $secret  Base32-encoded secret.
	 * @param int    $counter Counter value (time step).
	 * @return string 6-digit zero-padded OTP string.
	 */
	private static function hotp( string $secret, int $counter ): string {
		$key = self::base32_decode( $secret );

		// Pack counter as 64-bit big-endian (8 bytes).
		$counter_bytes = pack( 'N*', 0 ) . pack( 'N*', $counter );

		// HMAC-SHA1.
		$hash = hash_hmac( 'sha1', $counter_bytes, $key, true );

		// Dynamic truncation.
		$offset = ord( $hash[19] ) & 0x0F;
		$code   =
			( ( ord( $hash[ $offset ] )     & 0x7F ) << 24 ) |
			( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 ) |
			( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) <<  8 ) |
			  ( ord( $hash[ $offset + 3 ] ) & 0xFF );

		$otp = $code % ( 10 ** self::DIGITS );

		return str_pad( (string) $otp, self::DIGITS, '0', STR_PAD_LEFT );
	}

	/**
	 * Decode a base32-encoded string into raw binary.
	 *
	 * Handles uppercase, strips padding ('='), ignores invalid characters.
	 *
	 * @param string $secret Base32-encoded input.
	 * @return string Raw binary string.
	 */
	private static function base32_decode( string $secret ): string {
		$secret = strtoupper( trim( $secret ) );
		$secret = rtrim( $secret, '=' ); // Strip padding.

		$alphabet = self::BASE32_CHARS;
		$map      = array_flip( str_split( $alphabet ) );

		$buffer = 0;
		$bits   = 0;
		$output = '';

		for ( $i = 0; $i < strlen( $secret ); $i++ ) {
			$char = $secret[ $i ];
			if ( ! isset( $map[ $char ] ) ) {
				continue; // Skip invalid characters.
			}
			$buffer = ( $buffer << 5 ) | $map[ $char ];
			$bits  += 5;
			if ( $bits >= 8 ) {
				$bits   -= 8;
				$output .= chr( ( $buffer >> $bits ) & 0xFF );
			}
		}

		return $output;
	}
}
