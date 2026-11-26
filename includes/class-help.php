<?php
/**
 * The in-app Help (Guida): cards bundled as JSON per language, merged with the cards other plugins add through
 * filters, and served to the app. No runtime call to an external service.
 *
 * Files: languages/help-it_IT.json and languages/help-en_US.json (generated, never edited by hand). Only Italian
 * and English exist: every other locale gets English.
 *
 * @package Fotonic
 */

defined( 'ABSPATH' ) || exit;

final class Fotonic_Help {

	/** Defaults of the `ftnc_app_features` filter (kept in step with Fotonic_Admin_Page). */
	const BASE_FEATURES = array(
		'calendar' => true,
		'restore'  => true,
	);

	/** The language file name part for a WordPress locale: it_* reads Italian, everything else English. */
	public static function locale_key( string $locale ): string {
		return 0 === strpos( strtolower( $locale ), 'it' ) ? 'it_IT' : 'en_US';
	}

	/**
	 * Read one bundled file. A missing or broken file gives an empty set, never an error.
	 *
	 * @return array{sections:array<string,string>,cards:array<int,array<string,mixed>>}
	 */
	public static function load_file( string $dir, string $key ): array {
		$empty = array(
			'sections' => array(),
			'cards'    => array(),
		);
		$file  = rtrim( $dir, '/' ) . '/help-' . $key . '.json';
		if ( ! is_readable( $file ) ) {
			return $empty;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file shipped inside the plugin.
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) || ! isset( $data['cards'] ) || ! is_array( $data['cards'] ) ) {
			return $empty;
		}
		return array(
			'sections' => isset( $data['sections'] ) && is_array( $data['sections'] ) ? $data['sections'] : array(),
			'cards'    => $data['cards'],
		);
	}

	/**
	 * Keep the well-formed cards, drop a repeated id (the first one wins, so a free card beats a later one),
	 * and mark each card available or not from the feature flags.
	 *
	 * @param array<int,mixed>     $cards    Cards in order.
	 * @param array<string,mixed>  $features The `ftnc_app_features` flags.
	 * @return array<int,array<string,mixed>>
	 */
	public static function normalize( array $cards, array $features ): array {
		$seen = array();
		$out  = array();
		foreach ( $cards as $card ) {
			if ( ! is_array( $card ) ) {
				continue;
			}
			$id = isset( $card['id'] ) && is_string( $card['id'] ) ? $card['id'] : '';
			if ( '' === $id || isset( $seen[ $id ] ) ) {
				continue;
			}
			if ( empty( $card['question'] ) || ! is_string( $card['question'] ) || empty( $card['answer'] ) || ! is_string( $card['answer'] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			$requires    = isset( $card['requires'] ) && is_string( $card['requires'] ) ? $card['requires'] : '';
			$keywords    = array();
			if ( isset( $card['keywords'] ) && is_array( $card['keywords'] ) ) {
				foreach ( $card['keywords'] as $k ) {
					if ( is_string( $k ) && '' !== trim( $k ) ) {
						$keywords[] = $k;
					}
				}
			}
			$out[] = array(
				'id'          => $id,
				'section'     => isset( $card['section'] ) && is_string( $card['section'] ) ? $card['section'] : '',
				'order'       => isset( $card['order'] ) ? (int) $card['order'] : 0,
				'first_steps' => ! empty( $card['first_steps'] ),
				'requires'    => $requires,
				'available'   => '' === $requires || ! empty( $features[ $requires ] ),
				// What to show instead of the answer when the feature is off. A plugin that adds cards may fill it.
				'unavailable' => isset( $card['unavailable'] ) && is_string( $card['unavailable'] ) ? $card['unavailable'] : '',
				'question'    => $card['question'],
				'answer'      => $card['answer'],
				'keywords'    => $keywords,
			);
		}
		return $out;
	}

	/**
	 * The Help for the current admin: bundled cards, then what the filters add.
	 *
	 * @return array{sections:array<string,string>,cards:array<int,array<string,mixed>>}
	 */
	public static function get( string $locale ): array {
		$key  = self::locale_key( $locale );
		$data = self::load_file( FOTONIC_DIR . 'languages', $key );
		if ( ! $data['cards'] && 'en_US' !== $key ) {
			$data = self::load_file( FOTONIC_DIR . 'languages', 'en_US' );
		}
		$free_ids = array();
		foreach ( $data['cards'] as $c ) {
			if ( is_array( $c ) && isset( $c['id'] ) && is_string( $c['id'] ) ) {
				$free_ids[ $c['id'] ] = true;
			}
		}

		$cards    = apply_filters( 'ftnc_help_cards', $data['cards'], $key );
		$sections = apply_filters( 'ftnc_help_sections', $data['sections'], $key );
		$cards    = is_array( $cards ) ? $cards : $data['cards'];
		$sections = is_array( $sections ) ? $sections : $data['sections'];

		// Free cards always come first and win a repeated id, whatever the filters did with the order.
		$mine   = array();
		$others = array();
		foreach ( $cards as $c ) {
			if ( is_array( $c ) && isset( $c['id'] ) && isset( $free_ids[ $c['id'] ] ) ) {
				$mine[] = $c;
			} else {
				$others[] = $c;
			}
		}
		$features = apply_filters( 'ftnc_app_features', self::BASE_FEATURES );
		return array(
			'sections' => $sections,
			'cards'    => self::normalize( array_merge( $mine, $others ), is_array( $features ) ? $features : self::BASE_FEATURES ),
		);
	}

	public static function register_routes(): void {
		register_rest_route(
			Fotonic_REST_API::REST_NAMESPACE,
			'/help',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'rest_get' ),
				'permission_callback' => array( 'Fotonic_REST_API', 'admin_permission' ),
			)
		);
	}

	public static function rest_get(): \WP_REST_Response {
		return new \WP_REST_Response( self::get( determine_locale() ), 200 );
	}
}
