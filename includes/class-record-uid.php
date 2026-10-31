<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Stable identity of a record across sites: a random UUID v4 in the postmeta
 * `_ftnc_uid`.
 *
 * Post IDs differ between sites, so a backup restored into another site cannot
 * tell "the same customer" from "a different customer" by ID. Every record of a
 * tracked post type carries a uid from its creation; records that predate this
 * class get one lazily (admin backfill) and always right before a backup is built,
 * so every archive carries the uid of every record.
 *
 * The list of tracked post types is filterable (`ftnc_uid_post_types`): Pro adds its
 * own types, and must also declare `_ftnc_uid` in its backup dataset `meta_keys`,
 * otherwise Fotonic_Backup_Registry::audit() reports drift.
 *
 * A COPY of a record (Pro quote duplicate, restore "import as copy") must not keep
 * the uid of its source: delete it from the copy and call ensure().
 */
class Fotonic_Record_Uid {

	const META_KEY      = '_ftnc_uid';
	const OPTION_DONE   = 'fotonic_uid_backfill_done'; // bool: the admin backfill found nothing left to do.
	const BATCH         = 200;
	const STATUSES      = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );

	/**
	 * Post types that carry a uid.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = (array) apply_filters( 'ftnc_uid_post_types', array( 'ftnc_customer', 'ftnc_service', 'ftnc_work', 'ftnc_memory_card' ) );
		return array_values( array_unique( array_filter( $types, 'is_string' ) ) );
	}

	/**
	 * Random UUID v4, lowercase.
	 *
	 * @return string
	 */
	public static function generate(): string {
		$b    = random_bytes( 16 );
		$b[6] = chr( ( ord( $b[6] ) & 0x0f ) | 0x40 );
		$b[8] = chr( ( ord( $b[8] ) & 0x3f ) | 0x80 );
		$hex  = bin2hex( $b );
		return substr( $hex, 0, 8 ) . '-' . substr( $hex, 8, 4 ) . '-' . substr( $hex, 12, 4 ) . '-' . substr( $hex, 16, 4 ) . '-' . substr( $hex, 20, 12 );
	}

	/**
	 * Whether a value is a lowercase UUID v4.
	 *
	 * @param mixed $value Candidate.
	 * @return bool
	 */
	public static function is_valid( $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $value );
	}

	/**
	 * Make sure a record of a tracked type has a uid. Idempotent.
	 *
	 * @param int $post_id Post ID.
	 * @return string The uid, or '' when the post is not a tracked record.
	 */
	public static function ensure( int $post_id ): string {
		if ( $post_id <= 0 || ! in_array( get_post_type( $post_id ), self::post_types(), true ) ) {
			return '';
		}
		$current = get_post_meta( $post_id, self::META_KEY, true );
		if ( self::is_valid( $current ) ) {
			return $current;
		}
		$uid = self::generate();
		if ( false === update_post_meta( $post_id, self::META_KEY, $uid ) ) {
			return ''; // The write failed: say so, so callers (ensure_all) do not count it.
		}
		return $uid;
	}

	/**
	 * Action `wp_after_insert_post`: every record created or saved gets its uid.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function on_insert( $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( in_array( $post->post_type, self::post_types(), true ) ) {
			self::ensure( (int) $post_id );
		}
	}

	/**
	 * IDs of tracked records that have no valid uid yet.
	 *
	 * @param int $limit Maximum number of IDs.
	 * @return int[]
	 */
	public static function missing_ids( int $limit ): array {
		$ids = get_posts( array(
			'post_type'        => self::post_types(),
			'post_status'      => self::STATUSES,
			'posts_per_page'   => max( 1, $limit ),
			'fields'           => 'ids',
			'no_found_rows'    => true,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off backfill, bounded by $limit.
				array( 'key' => self::META_KEY, 'compare' => 'NOT EXISTS' ),
			),
		) );
		return array_map( 'intval', $ids );
	}

	/**
	 * Give a uid to tracked records that lack one. Cron-safe: no capability or Vault needed.
	 *
	 * @param int $max Stop after this many records; 0 = all of them (in batches).
	 * @return int Number of records that received a uid.
	 */
	public static function ensure_all( int $max = 0 ): int {
		$done = 0;
		while ( true ) {
			$room = 0 === $max ? self::BATCH : min( self::BATCH, $max - $done );
			if ( $room <= 0 ) {
				break;
			}
			$ids = self::missing_ids( $room );
			if ( empty( $ids ) ) {
				break;
			}
			$wrote = 0;
			foreach ( $ids as $id ) {
				if ( '' !== self::ensure( $id ) ) {
					$wrote++;
				}
			}
			if ( 0 === $wrote ) {
				break; // Nothing could be written: stop instead of asking for the same IDs again.
			}
			$done += $wrote;
		}
		return $done;
	}

	/**
	 * Action `admin_init`: convenience backfill for records created before this existed.
	 * Only for an administrator on a normal admin page load (not ajax, not cron), one
	 * batch per request, and the "done" flag is set only when nothing is left.
	 * Backups call ensure_all() themselves, so this is never what archives rely on.
	 */
	public static function maybe_backfill(): void {
		if ( get_option( self::OPTION_DONE ) || wp_doing_ajax() || wp_doing_cron() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		self::ensure_all( self::BATCH );
		if ( empty( self::missing_ids( 1 ) ) ) {
			update_option( self::OPTION_DONE, true, false );
		}
	}
}
