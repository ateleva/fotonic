<?php
/**
 * REST API for ftnc_memory_card CPT.
 * Namespace: fotonic/v1/memory-cards
 *
 * @package Fotonic
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_Memory_Card_REST_API {

	const NAMESPACE = 'fotonic/v1';

	public static function register_routes(): void {

		register_rest_route( self::NAMESPACE, '/memory-cards', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_cards' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
				'args'                => [
					'status' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_card' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/memory-cards/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_card' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'update_card' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'delete_card' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
		] );
	}

	public static function admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// ---------------------------------------------------------------------------
	// Handlers
	// ---------------------------------------------------------------------------

	public static function get_cards( \WP_REST_Request $request ): \WP_REST_Response {
		$status = $request->get_param( 'status' );

		$args = [
			'post_type'      => 'ftnc_memory_card',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		if ( $status ) {
			$valid = [ 'free', 'in_use', 'backed_up', 'damaged' ];
			if ( in_array( $status, $valid, true ) ) {
				$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					[
						'taxonomy' => 'ftnc_card_status',
						'field'    => 'slug',
						'terms'    => $status,
					],
				];
			}
		}

		$query        = new \WP_Query( $args );
		$card_to_work = self::build_card_to_work_map();
		$data         = [];
		foreach ( $query->posts as $post ) {
			$data[] = self::format_card( $post, $card_to_work );
		}

		return new \WP_REST_Response( [ 'data' => $data, 'total' => count( $data ) ], 200 );
	}

	public static function get_card( \WP_REST_Request $request ): \WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== 'ftnc_memory_card' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response( [ 'message' => __( 'Card not found.', 'eleva-crm-for-photographers' ) ], 404 );
		}
		return new \WP_REST_Response( self::format_card( $post ), 200 );
	}

	public static function create_card( \WP_REST_Request $request ): \WP_REST_Response {
		$body  = $request->get_json_params();
		$title = sanitize_text_field( $body['title'] ?? '' );
		if ( empty( $title ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Card title is required.', 'eleva-crm-for-photographers' ) ], 400 );
		}

		$post_id = wp_insert_post( [
			'post_type'   => 'ftnc_memory_card',
			'post_title'  => $title,
			'post_status' => 'publish',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return new \WP_REST_Response( [ 'message' => $post_id->get_error_message() ], 500 );
		}

		Fotonic_Memory_Card_CPT::set_card_status( $post_id, 'free' );

		return new \WP_REST_Response( self::format_card( get_post( $post_id ) ), 201 );
	}

	public static function update_card( \WP_REST_Request $request ): \WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$post = get_post( $id );
		if ( ! $post || $post->post_type !== 'ftnc_memory_card' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response( [ 'message' => __( 'Card not found.', 'eleva-crm-for-photographers' ) ], 404 );
		}

		$body = $request->get_json_params();

		if ( isset( $body['title'] ) ) {
			$title = sanitize_text_field( $body['title'] );
			if ( empty( $title ) ) {
				return new \WP_REST_Response( [ 'message' => __( 'Card title is required.', 'eleva-crm-for-photographers' ) ], 400 );
			}
			wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
		}

		if ( isset( $body['status'] ) ) {
			Fotonic_Memory_Card_CPT::set_card_status( $id, sanitize_text_field( $body['status'] ) );
		}

		return new \WP_REST_Response( self::format_card( get_post( $id ) ), 200 );
	}

	public static function delete_card( \WP_REST_Request $request ): \WP_REST_Response {
		$id     = (int) $request->get_param( 'id' );
		$post   = get_post( $id );
		if ( ! $post || $post->post_type !== 'ftnc_memory_card' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response( [ 'message' => __( 'Card not found.', 'eleva-crm-for-photographers' ) ], 404 );
		}

		$status = Fotonic_Memory_Card_CPT::get_card_status( $id );
		if ( 'in_use' === $status ) {
			return new \WP_REST_Response(
				[ 'message' => __( 'Card is currently in use and cannot be deleted.', 'eleva-crm-for-photographers' ) ],
				409
			);
		}

		wp_trash_post( $id );

		return new \WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	// ---------------------------------------------------------------------------
	// Formatter
	// ---------------------------------------------------------------------------

	/**
	 * Build a map of card_id → {id, title} from all works that have not been marked ready_for_next.
	 * Single WP_Query; meta cache pre-warmed by default (update_post_meta_cache = true).
	 *
	 * @return array<int, array{id: int, title: string}>
	 */
	private static function build_card_to_work_map(): array {
		$works = get_posts( [
			'post_type'              => 'ftnc_work',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		] );

		$map = [];
		foreach ( $works as $work ) {
			$raw = get_post_meta( $work->ID, '_ftnc_memory_cards', true );
			if ( empty( $raw ) ) {
				continue;
			}
			$cards = json_decode( $raw, true );
			if ( ! is_array( $cards ) ) {
				continue;
			}
			foreach ( $cards as $mc ) {
				$card_id = (int) ( $mc['card_id'] ?? 0 );
				if ( $card_id > 0 && ! isset( $map[ $card_id ] ) ) {
					$map[ $card_id ] = [ 'id' => $work->ID, 'title' => $work->post_title ];
				}
			}
		}
		return $map;
	}

	/**
	 * @param array<int, array{id: int, title: string}> $card_to_work Map built by build_card_to_work_map().
	 */
	public static function format_card( \WP_Post $post, array $card_to_work = [] ): array {
		$status = Fotonic_Memory_Card_CPT::get_card_status( $post->ID );

		$labels = [
			'free'      => __( 'Ready', 'eleva-crm-for-photographers' ),
			'in_use'    => __( 'In Use', 'eleva-crm-for-photographers' ),
			'backed_up' => __( 'Backed Up', 'eleva-crm-for-photographers' ),
			'damaged'   => __( 'Damaged', 'eleva-crm-for-photographers' ),
		];

		$in_use_work = null;
		if ( in_array( $status, [ 'in_use', 'backed_up' ], true ) ) {
			$in_use_work = $card_to_work[ $post->ID ] ?? null;
		}

		return [
			'id'           => $post->ID,
			'title'        => $post->post_title,
			'status'       => $status,
			'status_label' => $labels[ $status ] ?? $status,
			'in_use_work'  => $in_use_work,
		];
	}
}
