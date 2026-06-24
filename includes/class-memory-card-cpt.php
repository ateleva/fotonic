<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_Memory_Card_CPT {

	public static function register(): void {
		self::register_memory_card_cpt();
		self::register_card_status_taxonomy();
	}

	private static function register_memory_card_cpt(): void {
		$labels = [
			'name'               => __( 'Memory Cards', 'eleva-crm-for-photographers' ),
			'singular_name'      => __( 'Memory Card', 'eleva-crm-for-photographers' ),
			'add_new'            => __( 'Add New', 'eleva-crm-for-photographers' ),
			'add_new_item'       => __( 'Add New Memory Card', 'eleva-crm-for-photographers' ),
			'edit_item'          => __( 'Edit Memory Card', 'eleva-crm-for-photographers' ),
			'new_item'           => __( 'New Memory Card', 'eleva-crm-for-photographers' ),
			'view_item'          => __( 'View Memory Card', 'eleva-crm-for-photographers' ),
			'search_items'       => __( 'Search Memory Cards', 'eleva-crm-for-photographers' ),
			'not_found'          => __( 'No memory cards found.', 'eleva-crm-for-photographers' ),
			'not_found_in_trash' => __( 'No memory cards found in trash.', 'eleva-crm-for-photographers' ),
			'all_items'          => __( 'All Memory Cards', 'eleva-crm-for-photographers' ),
			'menu_name'          => __( 'Memory Cards', 'eleva-crm-for-photographers' ),
		];
		register_post_type( 'ftnc_memory_card', [
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => false,
			'show_in_menu' => false,
			'supports'     => [ 'title' ],
			'show_in_rest' => false,
			'rewrite'      => false,
		] );
	}

	private static function register_card_status_taxonomy(): void {
		$labels = [
			'name'          => __( 'Card Status', 'eleva-crm-for-photographers' ),
			'singular_name' => __( 'Card Status', 'eleva-crm-for-photographers' ),
			'all_items'     => __( 'All Card Statuses', 'eleva-crm-for-photographers' ),
		];
		register_taxonomy( 'ftnc_card_status', 'ftnc_memory_card', [
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => false,
			'show_in_rest' => false,
			'rewrite'      => false,
			'hierarchical' => false,
		] );
	}

	/**
	 * Seed the four canonical card-status terms. Safe to call on every init.
	 * Also migrates legacy terms: needs_backup → in_use, needs_formatting → backed_up.
	 */
	public static function ensure_card_status_terms(): void {
		$terms = [
			'free'      => __( 'Ready', 'eleva-crm-for-photographers' ),
			'in_use'    => __( 'In Use', 'eleva-crm-for-photographers' ),
			'backed_up' => __( 'Backed Up', 'eleva-crm-for-photographers' ),
			'damaged'   => __( 'Damaged', 'eleva-crm-for-photographers' ),
		];
		foreach ( $terms as $slug => $name ) {
			$existing = term_exists( $slug, 'ftnc_card_status' );
			if ( ! $existing ) {
				wp_insert_term( $name, 'ftnc_card_status', [ 'slug' => $slug ] );
			} else {
				$term_id = is_array( $existing ) ? $existing['term_id'] : $existing;
				$term    = get_term_by( 'id', $term_id, 'ftnc_card_status' );
				if ( $term && $term->name !== $name ) {
					wp_update_term( $term_id, 'ftnc_card_status', [ 'name' => $name ] );
				}
			}
		}

		// Migrate legacy statuses from previous taxonomy shape.
		self::migrate_legacy_status( 'needs_backup',     'in_use' );
		self::migrate_legacy_status( 'needs_formatting', 'backed_up' );
	}

	/**
	 * Reassign all cards with $old_status to $new_status, then delete the old term.
	 */
	private static function migrate_legacy_status( string $old_status, string $new_status ): void {
		$legacy_term = get_term_by( 'slug', $old_status, 'ftnc_card_status' );
		if ( ! $legacy_term ) {
			return;
		}
		$cards = get_posts( [
			'post_type'      => 'ftnc_memory_card',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'no_found_rows'  => true,
			'tax_query'      => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => 'ftnc_card_status',
					'field'    => 'slug',
					'terms'    => $old_status,
				],
			],
		] );
		foreach ( $cards as $card ) {
			wp_set_object_terms( $card->ID, $new_status, 'ftnc_card_status' );
		}
		wp_delete_term( $legacy_term->term_id, 'ftnc_card_status' );
	}

	/**
	 * Get the current status slug of a memory card.
	 *
	 * @param int $card_id Post ID of ftnc_memory_card.
	 * @return string Status slug, or 'free' as fallback.
	 */
	public static function get_card_status( int $card_id ): string {
		$terms = wp_get_object_terms( $card_id, 'ftnc_card_status', [ 'fields' => 'slugs' ] );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return 'free';
		}
		return $terms[0];
	}

	/**
	 * Set the status of a memory card.
	 *
	 * @param int    $card_id Post ID of ftnc_memory_card.
	 * @param string $status  Status slug.
	 */
	public static function set_card_status( int $card_id, string $status ): void {
		$valid = [ 'free', 'in_use', 'backed_up', 'damaged' ];
		if ( ! in_array( $status, $valid, true ) ) {
			return;
		}
		wp_set_object_terms( $card_id, $status, 'ftnc_card_status' );
	}
}
