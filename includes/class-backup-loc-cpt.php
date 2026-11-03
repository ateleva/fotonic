<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ftnc_backup_loc: the places where the admin keeps the backups of a work (external drive, NAS, cloud...).
 *
 * Meta: _ftnc_loc_type (free text), _ftnc_loc_capacity (free text), _ftnc_loc_status (active|full|retired),
 * _ftnc_loc_notes (protected text, f1), _ftnc_uid. The status is only a label: it blocks nothing.
 */
class Fotonic_Backup_Loc_CPT {

	const POST_TYPE = 'ftnc_backup_loc';

	/** @var array<int, array{title: string, trashed: bool}>|null Every location of the request, trashed ones included. */
	private static $states = null;

	public static function register(): void {
		$labels = [
			'name'               => __( 'Backup Locations', 'eleva-crm-for-photographers' ),
			'singular_name'      => __( 'Backup Location', 'eleva-crm-for-photographers' ),
			'add_new'            => __( 'Add New', 'eleva-crm-for-photographers' ),
			'add_new_item'       => __( 'Add New Backup Location', 'eleva-crm-for-photographers' ),
			'edit_item'          => __( 'Edit Backup Location', 'eleva-crm-for-photographers' ),
			'new_item'           => __( 'New Backup Location', 'eleva-crm-for-photographers' ),
			'view_item'          => __( 'View Backup Location', 'eleva-crm-for-photographers' ),
			'search_items'       => __( 'Search Backup Locations', 'eleva-crm-for-photographers' ),
			'not_found'          => __( 'No backup locations found.', 'eleva-crm-for-photographers' ),
			'not_found_in_trash' => __( 'No backup locations found in trash.', 'eleva-crm-for-photographers' ),
			'all_items'          => __( 'All Backup Locations', 'eleva-crm-for-photographers' ),
			'menu_name'          => __( 'Backup Locations', 'eleva-crm-for-photographers' ),
		];
		register_post_type( self::POST_TYPE, [
			'labels'       => $labels,
			'public'       => false,
			'show_ui'      => false,
			'show_in_menu' => false,
			'supports'     => [ 'title' ],
			'show_in_rest' => false,
			'rewrite'      => false,
			'capabilities' => Fotonic_CPT_Registry::admin_only_caps(),
			'map_meta_cap' => true,
		] );
	}

	/** @return string[] */
	public static function statuses(): array {
		return [ 'active', 'full', 'retired' ];
	}

	/** @return array<string, string> status => label */
	public static function status_labels(): array {
		return [
			'active'  => __( 'Active', 'eleva-crm-for-photographers' ),
			'full'    => __( 'Full', 'eleva-crm-for-photographers' ),
			'retired' => __( 'Retired', 'eleva-crm-for-photographers' ),
		];
	}

	public static function get_status( int $id ): string {
		$status = (string) get_post_meta( $id, '_ftnc_loc_status', true );
		return in_array( $status, self::statuses(), true ) ? $status : 'active';
	}

	/**
	 * Title and trash flag of every location, in ONE query for the whole request, so a list of works can tell
	 * which of its archives were deleted without a query per row. A location that is not here at all was
	 * deleted for good (the trash is emptied after 30 days).
	 *
	 * @return array<int, array{title: string, trashed: bool}>
	 */
	public static function states(): array {
		if ( null === self::$states ) {
			self::$states = [];
			$posts        = get_posts( [
				'post_type'              => self::POST_TYPE,
				// Not only 'publish': a location restored from the trash comes back as a draft.
				'post_status'            => [ 'publish', 'draft', 'pending', 'private', 'future', 'trash' ],
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			] );
			foreach ( $posts as $p ) {
				self::$states[ (int) $p->ID ] = [
					'title'   => (string) $p->post_title,
					'trashed' => 'trash' === $p->post_status,
				];
			}
		}
		return self::$states;
	}

	/** Forget the per-request maps after a write. */
	public static function flush_states(): void {
		self::$states = null;
		self::$alert_ids = null;
	}

	/** @var int[]|null Works that point at a deleted location, for this request. */
	private static $alert_ids = null;

	/**
	 * IDs of the published works with at least one row on a deleted location (the list filter and its counter).
	 * Only works that have the storage meta at all are read, and their meta is loaded in one go.
	 *
	 * @return int[]
	 */
	public static function alert_work_ids(): array {
		if ( null === self::$alert_ids ) {
			self::$alert_ids = [];
			$works           = get_posts( [
				'post_type'              => 'ftnc_work',
				'post_status'            => 'publish',
				'posts_per_page'         => -1,
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_key'               => '_ftnc_storage_locations', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Only works that use the feature are read.
				'meta_value'             => '',
				'meta_compare'           => '!=',
			] );
			foreach ( $works as $work ) {
				$rows = json_decode( (string) get_post_meta( $work->ID, '_ftnc_storage_locations', true ), true );
				if ( ! is_array( $rows ) ) {
					continue;
				}
				foreach ( $rows as $row ) {
					if ( is_array( $row ) && (int) ( $row['location_id'] ?? 0 ) > 0 && self::describe_row( $row )['deleted'] ) {
						self::$alert_ids[] = (int) $work->ID;
						break;
					}
				}
			}
		}
		return self::$alert_ids;
	}

	/**
	 * @param array $row One row of _ftnc_storage_locations.
	 * @return array{location_id: int, location_title: string, deleted: bool}
	 */
	public static function describe_row( array $row ): array {
		$id     = (int) ( $row['location_id'] ?? 0 );
		$states = self::states();
		$state  = $states[ $id ] ?? null;
		return [
			'location_id'    => $id,
			'location_title' => $state ? $state['title'] : '',
			'deleted'        => ! $state || $state['trashed'],
		];
	}
}
