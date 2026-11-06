<?php
/**
 * REST API for ftnc_backup_loc (backup locations) and the shots history of the Storage page.
 * Namespace: fotonic/v1/backup-locations, fotonic/v1/storage/shots
 *
 * @package Fotonic
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_Backup_Loc_REST_API {

	const NAMESPACE = 'fotonic/v1';

	public static function register_routes(): void {
		register_rest_route( self::NAMESPACE, '/backup-locations', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_locations' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_location' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/backup-locations/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_location' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ __CLASS__, 'update_location' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'delete_location' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/storage/shots', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_shots' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
				'args'                => [
					'year' => [ 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
				],
			],
		] );
	}

	public static function admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// ---------------------------------------------------------------------------
	// Locations
	// ---------------------------------------------------------------------------

	public static function get_locations(): \WP_REST_Response {
		$posts = get_posts( [
			'post_type'      => Fotonic_Backup_Loc_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		$map  = self::build_location_to_works_map();
		$data = [];
		foreach ( $posts as $post ) {
			$data[] = self::format_location( $post, $map );
		}

		return new \WP_REST_Response( [ 'data' => $data, 'total' => count( $data ) ], 200 );
	}

	public static function get_location( \WP_REST_Request $request ): \WP_REST_Response {
		$post = self::find( (int) $request->get_param( 'id' ) );
		if ( ! $post ) {
			return self::not_found();
		}
		return new \WP_REST_Response( self::format_location( $post, self::build_location_to_works_map(), true ), 200 );
	}

	public static function create_location( \WP_REST_Request $request ): \WP_REST_Response {
		$body  = (array) $request->get_json_params();
		$title = sanitize_text_field( $body['title'] ?? '' );
		if ( '' === $title ) {
			return new \WP_REST_Response( [ 'message' => __( 'Backup location name is required.', 'eleva-crm-for-photographers' ) ], 400 );
		}

		$blocked = self::blocked_response( $body );
		if ( $blocked ) {
			return $blocked;
		}

		// wp_insert_post() unslashes its input: wp_slash() keeps backslashes in the title.
		$post_id = wp_insert_post( wp_slash( [
			'post_type'   => Fotonic_Backup_Loc_CPT::POST_TYPE,
			'post_title'  => $title,
			'post_status' => 'publish',
		] ), true );

		if ( is_wp_error( $post_id ) ) {
			return new \WP_REST_Response( [ 'message' => $post_id->get_error_message() ], 500 );
		}

		Fotonic_Backup_Loc_CPT::flush_states();
		try {
			self::save_meta( (int) $post_id, $body );
		} catch ( \RuntimeException $e ) {
			wp_delete_post( (int) $post_id, true );
			return new \WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
		}

		return new \WP_REST_Response( self::format_location( get_post( $post_id ), [] ), 201 );
	}

	public static function update_location( \WP_REST_Request $request ): \WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$post = self::find( $id );
		if ( ! $post ) {
			return self::not_found();
		}

		$body    = (array) $request->get_json_params();
		$blocked = self::blocked_response( $body );
		if ( $blocked ) {
			return $blocked;
		}

		if ( isset( $body['title'] ) ) {
			$title = sanitize_text_field( $body['title'] );
			if ( '' === $title ) {
				return new \WP_REST_Response( [ 'message' => __( 'Backup location name is required.', 'eleva-crm-for-photographers' ) ], 400 );
			}
			wp_update_post( wp_slash( [ 'ID' => $id, 'post_title' => $title ] ) );
		}

		try {
			self::save_meta( $id, $body );
		} catch ( \RuntimeException $e ) {
			return new \WP_REST_Response( [ 'message' => $e->getMessage() ], 500 );
		}
		Fotonic_Backup_Loc_CPT::flush_states();

		return new \WP_REST_Response( self::format_location( get_post( $id ), self::build_location_to_works_map(), true ), 200 );
	}

	/**
	 * Trashes the location. Never blocked: a drive breaks or is retired. The works that point at it keep the
	 * row, marked as deleted, so the admin sees what to fix (see Fotonic_Backup_Loc_CPT::describe_row()).
	 */
	public static function delete_location( \WP_REST_Request $request ): \WP_REST_Response {
		$id   = (int) $request->get_param( 'id' );
		$post = self::find( $id );
		if ( ! $post ) {
			return self::not_found();
		}

		wp_trash_post( $id );
		Fotonic_Backup_Loc_CPT::flush_states();

		return new \WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	// ---------------------------------------------------------------------------
	// Shots history
	// ---------------------------------------------------------------------------

	/**
	 * One row per work that has at least one of the two numbers. `year` filters on the event date.
	 * `years` always lists every year that has a row, so the filter can offer them.
	 */
	public static function get_shots( \WP_REST_Request $request ): \WP_REST_Response {
		$year  = (string) $request->get_param( 'year' );
		$works = get_posts( [
			'post_type'              => 'ftnc_work',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		] );

		$rows  = [];
		$years = [];
		foreach ( $works as $work ) {
			$taken     = self::shots_value( get_post_meta( $work->ID, '_ftnc_shots_taken', true ) );
			$delivered = self::shots_value( get_post_meta( $work->ID, '_ftnc_shots_delivered', true ) );
			if ( null === $taken && null === $delivered ) {
				continue;
			}
			$date = (string) get_post_meta( $work->ID, '_ftnc_event_date', true );
			$y    = preg_match( '/^(\d{4})-/', $date, $m ) ? $m[1] : '';
			if ( '' !== $y ) {
				$years[ $y ] = true;
			}
			if ( '' !== $year && $y !== $year ) {
				continue;
			}
			$rows[] = [
				'id'               => $work->ID,
				'title'            => Fotonic_Titles::read( $work, Fotonic_Vault::get_session_key() ),
				'event_date'       => $date,
				'shots_taken'      => $taken,
				'shots_delivered'  => $delivered,
			];
		}

		usort( $rows, static function ( $a, $b ) {
			return strcmp( (string) $b['event_date'], (string) $a['event_date'] );
		} );

		krsort( $years );

		return new \WP_REST_Response( [
			'data'   => $rows,
			'years'  => array_map( 'strval', array_keys( $years ) ),
			'totals' => [
				'works'     => count( $rows ),
				'taken'     => array_sum( array_map( static function ( $r ) { return (int) $r['shots_taken']; }, $rows ) ),
				'delivered' => array_sum( array_map( static function ( $r ) { return (int) $r['shots_delivered']; }, $rows ) ),
			],
		], 200 );
	}

	/** @param mixed $raw Stored meta. @return int|null null = never filled in. */
	public static function shots_value( $raw ): ?int {
		return ( '' === $raw || null === $raw || false === $raw ) ? null : max( 0, (int) $raw );
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	private static function find( int $id ): ?\WP_Post {
		$post = get_post( $id );
		if ( ! $post || Fotonic_Backup_Loc_CPT::POST_TYPE !== $post->post_type || 'trash' === $post->post_status ) {
			return null;
		}
		return $post;
	}

	private static function not_found(): \WP_REST_Response {
		return new \WP_REST_Response( [ 'message' => __( 'Backup location not found.', 'eleva-crm-for-photographers' ) ], 404 );
	}

	/** 423 while the encrypt job runs, or when the Vault is locked and the body carries note text. */
	private static function blocked_response( array $body ): ?\WP_REST_Response {
		$job = Fotonic_REST_API::encrypt_job_response();
		if ( $job ) {
			return $job;
		}
		$has_text = isset( $body['notes'] ) && is_string( $body['notes'] ) && '' !== $body['notes'];
		return Fotonic_REST_API::locked_response_if( $has_text );
	}

	/** @throws \RuntimeException When a protected field could not be encrypted. */
	private static function save_meta( int $post_id, array $body ): void {
		if ( array_key_exists( 'type', $body ) ) {
			update_post_meta( $post_id, '_ftnc_loc_type', wp_slash( mb_substr( sanitize_text_field( (string) $body['type'] ), 0, 120 ) ) );
		}
		if ( array_key_exists( 'capacity', $body ) ) {
			update_post_meta( $post_id, '_ftnc_loc_capacity', wp_slash( mb_substr( sanitize_text_field( (string) $body['capacity'] ), 0, 60 ) ) );
		}
		if ( array_key_exists( 'status', $body ) && in_array( $body['status'], Fotonic_Backup_Loc_CPT::statuses(), true ) ) {
			update_post_meta( $post_id, '_ftnc_loc_status', $body['status'] );
		}
		if ( array_key_exists( 'notes', $body ) ) {
			// null = the screen could not read the stored note: it stays as it is.
			$new    = null === $body['notes'] ? null : sanitize_textarea_field( (string) $body['notes'] );
			$stored = Fotonic_REST_API::field_to_store( $new, (string) get_post_meta( $post_id, '_ftnc_loc_notes', true ), Fotonic_Vault::get_session_key() );
			update_post_meta( $post_id, '_ftnc_loc_notes', wp_slash( $stored ) );
		}
	}

	/**
	 * One pass over the works: location id => list of works that use it. No query per location.
	 *
	 * @return array<int, array<int, array{id: int, title: string, event_date: string, customer_id: int}>>
	 */
	private static function build_location_to_works_map(): array {
		$works = get_posts( [
			'post_type'              => 'ftnc_work',
			'post_status'            => 'publish',
			'posts_per_page'         => -1,
			'no_found_rows'          => true,
			'update_post_term_cache' => false,
		] );

		$map = [];
		foreach ( $works as $work ) {
			$raw = get_post_meta( $work->ID, '_ftnc_storage_locations', true );
			if ( empty( $raw ) ) {
				continue;
			}
			$rows = json_decode( $raw, true );
			if ( ! is_array( $rows ) ) {
				continue;
			}
			$seen = [];
			foreach ( $rows as $row ) {
				$loc_id = is_array( $row ) ? (int) ( $row['location_id'] ?? 0 ) : 0;
				if ( $loc_id <= 0 || isset( $seen[ $loc_id ] ) ) {
					continue;
				}
				$seen[ $loc_id ]  = true;
				$map[ $loc_id ][] = [
					'id'          => $work->ID,
					'title'       => Fotonic_Titles::read( $work, Fotonic_Vault::get_session_key() ),
					'event_date'  => (string) get_post_meta( $work->ID, '_ftnc_event_date', true ),
					'customer_id' => (int) get_post_meta( $work->ID, '_ftnc_customer_id', true ),
				];
			}
		}
		return $map;
	}

	/**
	 * @param array<int, array> $map    From build_location_to_works_map().
	 * @param bool              $detail True on the single-location response: adds the works table.
	 */
	public static function format_location( \WP_Post $post, array $map, bool $detail = false ): array {
		$status = Fotonic_Backup_Loc_CPT::get_status( $post->ID );
		$labels = Fotonic_Backup_Loc_CPT::status_labels();
		$works  = $map[ $post->ID ] ?? [];

		$out = [
			'id'           => $post->ID,
			'title'        => $post->post_title,
			'type'         => (string) get_post_meta( $post->ID, '_ftnc_loc_type', true ),
			'capacity'     => (string) get_post_meta( $post->ID, '_ftnc_loc_capacity', true ),
			'status'       => $status,
			'status_label' => $labels[ $status ] ?? $status,
			'notes'        => Fotonic_REST_API::field_for_screen( (string) get_post_meta( $post->ID, '_ftnc_loc_notes', true ), Fotonic_Vault::get_session_key() ),
			'work_count'   => count( $works ),
		];

		if ( $detail ) {
			usort( $works, static function ( $a, $b ) {
				return strcmp( (string) $b['event_date'], (string) $a['event_date'] );
			} );
			$out['works'] = array_map( static function ( $w ) {
				$customer = $w['customer_id'] ? get_post( $w['customer_id'] ) : null;
				return [
					'id'             => $w['id'],
					'title'          => $w['title'],
					'event_date'     => $w['event_date'],
					'customer_id'    => $w['customer_id'],
					'customer_title' => $customer ? Fotonic_Titles::read( $customer, Fotonic_Vault::get_session_key() ) : '',
				];
			}, $works );
		}

		return $out;
	}
}
