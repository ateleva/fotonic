<?php
/**
 * Where a lead (a customer) came from: the one list the customer form, the customer list and,
 * in Eleva CRM Pro, the quote form, the quote list, the statistics and the work all read, so a
 * label never exists twice. Pure logic: no database, no request.
 *
 * @package Fotonic
 */

defined( 'ABSPATH' ) || exit;

final class Fotonic_Lead_Sources {

	/** Stored slugs, in display order ("other" last). */
	const SOURCES = array( 'instagram', 'facebook', 'website', 'word_of_mouth', 'whatsapp', 'phone', 'google', 'tiktok', 'supplier_referral', 'returning_client', 'matrimonio_com', 'other' );

	/** Sources that take a short free text (who recommended, which supplier, what else). */
	const DETAIL_SOURCES = array( 'word_of_mouth', 'supplier_referral', 'other' );

	const DETAIL_MAX = 120;

	/**
	 * Slug => translated label, in display order.
	 *
	 * @return array<string,string>
	 */
	public static function labels(): array {
		$labels = array(
			'instagram'         => __( 'Instagram', 'eleva-crm-for-photographers' ),
			'facebook'          => __( 'Facebook', 'eleva-crm-for-photographers' ),
			'website'           => __( 'Website', 'eleva-crm-for-photographers' ),
			'word_of_mouth'     => __( 'Word of mouth', 'eleva-crm-for-photographers' ),
			'whatsapp'          => __( 'WhatsApp', 'eleva-crm-for-photographers' ),
			'phone'             => __( 'Phone', 'eleva-crm-for-photographers' ),
			'google'            => __( 'Google', 'eleva-crm-for-photographers' ),
			'tiktok'            => __( 'TikTok', 'eleva-crm-for-photographers' ),
			'supplier_referral' => __( 'Supplier', 'eleva-crm-for-photographers' ),
			'returning_client'  => __( 'Returning client', 'eleva-crm-for-photographers' ),
			// A brand name: the same in every language, so it is not passed through __().
			'matrimonio_com'    => 'Matrimonio.com',
			'other'             => __( 'Other', 'eleva-crm-for-photographers' ),
		);
		$out    = array();
		foreach ( self::SOURCES as $slug ) {
			$out[ $slug ] = $labels[ $slug ];
		}
		return $out;
	}

	/** Label of a stored slug; '' for an empty or unknown one. */
	public static function label( string $slug ): string {
		$all = self::labels();
		return isset( $all[ $slug ] ) ? $all[ $slug ] : '';
	}

	/** The slug when it is in the list, otherwise ''. */
	public static function slug( $value ): string {
		$slug = is_string( $value ) ? $value : '';
		return in_array( $slug, self::SOURCES, true ) ? $slug : '';
	}

	/** Whether this source takes the detail text. */
	public static function takes_detail( string $slug ): bool {
		return in_array( $slug, self::DETAIL_SOURCES, true );
	}

	/** The detail text, cleaned and cut to DETAIL_MAX characters; '' for anything that is not a string. */
	public static function detail( $value ): string {
		if ( ! is_string( $value ) ) {
			return '';
		}
		return mb_substr( sanitize_text_field( $value ), 0, self::DETAIL_MAX );
	}
}
