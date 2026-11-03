<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The payments of a work: the installment rows decide the total.
 *
 * The admin types, on each row, only its `base`: the share of the services price that row covers. Everything
 * else is worked out here, row by row, and the shares must add up to what the work asks for (services plus
 * the supplier fees marked "added"). A row carries:
 *   base      the typed share
 *   surcharge a percentage taken from the payment type of the row (Pro's Rivalsa INPS 4%), rounded to cents
 *   taxable   true when the row counts for VAT; always true when it has a surcharge
 *   vat       the VAT of the row (0 when it is not taxable or the VAT is off)
 *   amount    the cash the client pays for the row (keeps the meaning it always had: every report reads it)
 * `src/src/utils/installments.js` mirrors compute() operation by operation; both are checked against the same
 * case table in the development checks, so PHP and JS can never drift.
 */
class Fotonic_Payments {

	/** Work meta written on every save under this model; a work without it is "legacy" (saved before). */
	const MODEL_META = '_ftnc_pay_model';
	const MODEL      = 2;

	/**
	 * Round to cents, identically on every PHP version (see Fotonic_Vat::round2 in Pro and round2 in the JS
	 * mirror: absolute value, x100, pre-round to 15 significant digits, halves up, sign restored, never -0).
	 */
	public static function round2( float $n ): float {
		$x = abs( $n ) * 100;
		if ( ! is_finite( $x ) || 0.0 === $x ) {
			return 0.0;
		}
		$cents = floor( (float) sprintf( '%.14E', $x ) + 0.5 );
		if ( 0.0 === $cents ) {
			return 0.0;
		}
		return ( $n < 0 ? -$cents : $cents ) / 100;
	}

	/** Sum of the price column of the work's service rows. */
	public static function services_subtotal( array $rows ): float {
		$sum = 0.0;
		foreach ( $rows as $row ) {
			if ( is_array( $row ) && isset( $row['price_override'] ) && is_numeric( $row['price_override'] ) ) {
				$sum += (float) $row['price_override'];
			}
		}
		return self::round2( $sum );
	}

	/** Surcharge percentage of a payment type slug (0 when the type has none or is unknown). */
	public static function pct_for_type( string $slug, array $types ): float {
		foreach ( $types as $t ) {
			if ( is_array( $t ) && ( $t['slug'] ?? '' ) === $slug ) {
				return max( 0.0, min( 100.0, (float) ( $t['surcharge_pct'] ?? 0 ) ) );
			}
		}
		return 0.0;
	}

	/**
	 * What the work asks for versus what the rows add up to.
	 *
	 * @param array $services   Service rows (price_override).
	 * @param array $installments Raw installment rows (base, or amount for a row saved before this model).
	 * @param float $adjustment Supplier fees marked "added" (from the filter ftnc_work_required_adjustment).
	 * @return array|null Null when everything is fine, else [code, required, planned] for the error answer.
	 */
	public static function check( array $services, array $installments, float $adjustment ): ?array {
		$required = self::round2( self::services_subtotal( $services ) + $adjustment );
		$has_svc  = false;
		foreach ( $services as $row ) {
			if ( is_array( $row ) ) {
				$has_svc = true;
				break;
			}
		}
		if ( ! $has_svc ) {
			return [ 'code' => 'no_services', 'required' => $required, 'planned' => 0.0 ];
		}
		$rows = array_values( array_filter( $installments, 'is_array' ) );
		if ( ! $rows ) {
			return [ 'code' => 'no_installments', 'required' => $required, 'planned' => 0.0 ];
		}
		$planned = 0.0;
		foreach ( $rows as $row ) {
			$raw = $row['base'] ?? ( $row['amount'] ?? '' );
			if ( ! is_numeric( $raw ) || (float) $raw < 0 || ( 0.0 === (float) $raw && $required > 0 ) ) {
				return [ 'code' => 'invalid_installment', 'required' => $required, 'planned' => 0.0 ];
			}
			$planned += (float) $raw;
		}
		$planned = self::round2( $planned );
		if ( (int) round( $planned * 100 ) !== (int) round( $required * 100 ) ) {
			return [ 'code' => 'installments_mismatch', 'required' => $required, 'planned' => $planned ];
		}
		return null;
	}

	/**
	 * Row math. $vat is [mode => none|excluded|included, rate => percent].
	 *
	 * @return array{rows: array, total: float, taxable: float, vat: float, to_collect: float, planned: float}
	 */
	public static function compute( array $rows, array $types, array $vat ): array {
		$mode = in_array( $vat['mode'] ?? '', [ 'excluded', 'included' ], true ) ? $vat['mode'] : 'none';
		$rate = max( 0.0, min( 100.0, (float) ( $vat['rate'] ?? 0 ) ) );
		if ( $rate <= 0 ) {
			$mode = 'none';
		}
		$r        = $rate / 100;
		$out      = [];
		$planned  = 0.0;
		$total    = 0.0;
		$taxable  = 0.0;
		$vat_sum  = 0.0;
		$collect  = 0.0;
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$base      = max( 0.0, self::round2( (float) ( $row['base'] ?? ( $row['amount'] ?? 0 ) ) ) );
			$pct       = self::pct_for_type( (string) ( $row['type'] ?? '' ), $types );
			$surcharge = self::round2( $base * $pct / 100 );
			$net       = self::round2( $base + $surcharge );
			$is_tax    = $surcharge > 0 || ! empty( $row['taxable'] );
			$vat_row   = 0.0;
			$amount    = $net;
			if ( $is_tax && 'excluded' === $mode ) {
				$vat_row = self::round2( $net * $r );
				$amount  = self::round2( $net + $vat_row );
			} elseif ( $is_tax && 'included' === $mode ) {
				$vat_row = self::round2( $net - self::round2( $net / ( 1 + $r ) ) );
			}
			$out[]    = array_merge( $row, [
				'base'      => $base,
				'surcharge' => $surcharge,
				'taxable'   => $is_tax,
				'vat'       => $vat_row,
				'amount'    => $amount,
			] );
			$planned += $base;
			$total   += $net;
			$vat_sum += $vat_row;
			$collect += $amount;
			if ( $is_tax ) {
				$taxable += $net;
			}
		}
		// Taxable price is net of VAT: in "included" mode the taxable rows carry their VAT inside.
		$taxable_net = 'included' === $mode ? $taxable - $vat_sum : $taxable;
		return [
			'rows'       => $out,
			'total'      => self::round2( $total ),
			'taxable'    => self::round2( $taxable_net ),
			'vat'        => self::round2( $vat_sum ),
			'to_collect' => self::round2( $collect ),
			'planned'    => self::round2( $planned ),
		];
	}
}
