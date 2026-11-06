<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Keeps the documents of the works (`uploads/fotonic/work-files/`, see Fotonic_Work_Files) out of the WordPress media
 * library and out of its other doors. The same six guards Eleva CRM Pro puts on its quote PDFs, for this folder only:
 *   - the media modal, the Media list screen and /wp/v2/media do not list them;
 *   - core's single-attachment paths (read, edit, delete a post, the media modal's get-attachment) are closed to anyone
 *     but an administrator with the Vault unlocked (or a site with no Vault), because a private attachment is otherwise
 *     checked by role, not by the Vault;
 *   - the core media REST answer never shows the file address.
 * The plugin's own code does not go through these checks (its routes use manage_options and it deletes with
 * wp_delete_attachment()).
 */
class Fotonic_Private_Media {

	public static function init(): void {
		add_filter( 'ajax_query_attachments_args', array( __CLASS__, 'exclude_from_media_ajax' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_from_media_list' ) );
		add_filter( 'rest_attachment_query', array( __CLASS__, 'exclude_from_media_rest' ) );
		add_filter( 'map_meta_cap', array( __CLASS__, 'guard_media_caps' ), 10, 4 );
		add_filter( 'rest_prepare_attachment', array( __CLASS__, 'hide_media_url' ), 10, 2 );
		add_filter( 'wp_prepare_attachment_for_js', array( __CLASS__, 'guard_media_js' ), 10, 2 );
		add_action( 'before_delete_post', array( 'Fotonic_Work_Files', 'on_delete_post' ) );
		add_action( Fotonic_Work_Files::CRON_HOOK, array( 'Fotonic_Work_Files', 'purge_pending' ) );
	}

	/** Attachments outside our folder (and any without a file path). */
	private static function outside_clause(): array {
		return array(
			'relation' => 'OR',
			array( 'key' => '_wp_attached_file', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_wp_attached_file', 'value' => '^' . Fotonic_Work_Files::DIR . '/', 'compare' => 'NOT REGEXP' ),
		);
	}

	/** ANDs our clause with whatever meta query is already there, whatever its relation. */
	private static function with_clause( $meta_query ): array {
		$meta_query = is_array( $meta_query ) ? $meta_query : array();
		return $meta_query ? array( 'relation' => 'AND', $meta_query, self::outside_clause() ) : array( self::outside_clause() );
	}

	public static function exclude_from_media_ajax( $query ) {
		$query = is_array( $query ) ? $query : array();
		$query['meta_query'] = self::with_clause( $query['meta_query'] ?? array() ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $query;
	}

	public static function exclude_from_media_list( $q ): void {
		if ( ! is_admin() || ! $q instanceof \WP_Query || ! $q->is_main_query() || 'attachment' !== $q->get( 'post_type' ) ) {
			return;
		}
		$q->set( 'meta_query', self::with_clause( $q->get( 'meta_query' ) ) );
	}

	public static function exclude_from_media_rest( $args ) {
		return self::exclude_from_media_ajax( $args );
	}

	/** Who may read, edit or delete one of our files through core: an administrator with the Vault open (or no Vault). */
	public static function guard_media_caps( $caps, $cap, $user_id, $args ) {
		if ( ! in_array( $cap, array( 'read_post', 'edit_post', 'delete_post' ), true ) || empty( $args[0] ) || ! is_numeric( $args[0] ) ) {
			return $caps;
		}
		if ( ! Fotonic_Work_Files::is_work_file( (int) $args[0] ) ) {
			return $caps;
		}
		if ( (int) $user_id === get_current_user_id()
			&& user_can( $user_id, 'manage_options' )
			&& ( ! Fotonic_Vault::is_setup() || Fotonic_Vault::is_unlocked() )
		) {
			return $caps;
		}
		return array( 'do_not_allow' );
	}

	/** The core media REST answer never carries the address of one of our files. */
	public static function hide_media_url( $response, $post ) {
		if ( $post instanceof \WP_Post && $response instanceof \WP_REST_Response && Fotonic_Work_Files::is_work_file( (int) $post->ID ) ) {
			$data = $response->get_data();
			if ( is_array( $data ) ) {
				unset( $data['source_url'], $data['guid'] );
				$response->set_data( $data );
			}
		}
		return $response;
	}

	/** admin-ajax get-attachment only checks upload_files: for one of our files the Vault check applies too. */
	public static function guard_media_js( $response, $attachment ) {
		$id = $attachment instanceof \WP_Post ? (int) $attachment->ID : (int) $attachment;
		if ( $response && Fotonic_Work_Files::is_work_file( $id ) && ! current_user_can( 'read_post', $id ) ) {
			return false;
		}
		return $response;
	}
}
