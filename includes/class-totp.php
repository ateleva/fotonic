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

	public static function generate_secret(): string {
		$alphabet = self::BASE32_CHARS;
		$secret   = '';
		$bytes    = random_bytes( 10 );

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
		if ( $bits > 0 ) {
			$secret .= $alphabet[ ( $value << ( 5 - $bits ) ) & 0x1F ];
		}

		return strtoupper( substr( $secret, 0, 16 ) );
	}

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

	public static function verify( string $code, string $secret, int $window = 1 ): bool {
		$code = trim( $code );

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

	private static function hotp( string $secret, int $counter ): string {
		$key = self::base32_decode( $secret );

		$counter_bytes = pack( 'N*', 0 ) . pack( 'N*', $counter );

		$hash = hash_hmac( 'sha1', $counter_bytes, $key, true );

		$offset = ord( $hash[19] ) & 0x0F;
		$code   =
			( ( ord( $hash[ $offset ] )     & 0x7F ) << 24 ) |
			( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 ) |
			( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) <<  8 ) |
			  ( ord( $hash[ $offset + 3 ] ) & 0xFF );

		$otp = $code % ( 10 ** self::DIGITS );

		return str_pad( (string) $otp, self::DIGITS, '0', STR_PAD_LEFT );
	}

	private static function base32_decode( string $secret ): string {
		$secret = strtoupper( trim( $secret ) );
		$secret = rtrim( $secret, '=' );

		$alphabet = self::BASE32_CHARS;
		$map      = array_flip( str_split( $alphabet ) );

		$buffer = 0;
		$bits   = 0;
		$output = '';

		for ( $i = 0; $i < strlen( $secret ); $i++ ) {
			$char = $secret[ $i ];
			if ( ! isset( $map[ $char ] ) ) {
				continue;
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