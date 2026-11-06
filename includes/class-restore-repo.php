<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WordPress database access of the restore writer (Fotonic_Restore_Writer) and job.
 *
 * Records are created with $wpdb->insert() and changed with $wpdb->update(), never wp_insert_post() or
 * wp_update_post(): a restore must not fire the save hooks (Google Calendar sync and reminders of Pro,
 * VAT snapshots, payment status by form) nor bump modification dates the matcher reads. The one thing the
 * hooks did that a restore needs, the payment status of a work, is done by finish(). Meta goes through
 * update_post_meta( wp_slash ), so `è` and backslashes in JSON survive.
 *
 * Tests replace this class with an in-memory one (Fotonic_Restore_Writer::use_repo()).
 */
class Fotonic_Restore_Repo {

	/** @return int Id of the new record, 0 on failure. */
	public function insert_post( array $fields ): int {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$gmt = isset( $fields['post_date_gmt'] ) && '' !== $fields['post_date_gmt'] && '0000-00-00 00:00:00' !== $fields['post_date_gmt'] ? (string) $fields['post_date_gmt'] : $now;
		$row = array(
			'post_author'       => get_current_user_id(),
			'post_date'         => get_date_from_gmt( $gmt ),
			'post_date_gmt'     => $gmt,
			'post_content'      => (string) ( $fields['post_content'] ?? '' ),
			'post_title'        => (string) ( $fields['post_title'] ?? '' ),
			'post_excerpt'      => '',
			'post_status'       => (string) $fields['post_status'],
			'comment_status'    => 'closed',
			'ping_status'       => 'closed',
			'post_name'         => '',
			'to_ping'           => '',
			'pinged'            => '',
			'post_content_filtered' => '',
			'post_password'     => '',
			'post_parent'       => 0,
			'menu_order'        => 0,
			'post_mime_type'    => '',
			'post_modified'     => current_time( 'mysql' ),
			'post_modified_gmt' => $now,
			'post_type'         => (string) $fields['post_type'],
			'guid'              => '',
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberately not wp_insert_post(): no save hooks.
		if ( false === $wpdb->insert( $wpdb->posts, $row ) ) {
			return 0;
		}
		$id = (int) $wpdb->insert_id;
		clean_post_cache( $id );
		return $id;
	}

	/** @return array|null post_type, post_status, post_title, post_content, post_name; null when the record is gone. */
	public function get_post_fields( int $id ): ?array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- read straight from the table so no filter touches the value.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT post_type, post_status, post_title, post_content, post_name FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	}

	public function update_post_fields( int $id, array $fields ): bool {
		global $wpdb;
		$allowed = array_intersect_key( $fields, array_flip( array( 'post_title', 'post_content', 'post_name', 'post_status' ) ) );
		if ( empty( $allowed ) ) {
			return true;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberately not wp_update_post(): no save hooks.
		$ok = $wpdb->update( $wpdb->posts, $allowed, array( 'ID' => $id ) );
		clean_post_cache( $id );
		return false !== $ok;
	}

	/** The stored text of a meta, or null when the record has no such meta at all. */
	public function get_meta( int $id, string $key ): ?string {
		if ( ! metadata_exists( 'post', $id, $key ) ) {
			return null;
		}
		$value = get_post_meta( $id, $key, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	public function set_meta( int $id, string $key, string $value ): void {
		update_post_meta( $id, $key, wp_slash( $value ) );
	}

	public function delete_meta( int $id, string $key ): void {
		delete_post_meta( $id, $key );
	}

	/** @return string[] Slugs of the terms assigned in one taxonomy. */
	public function get_terms( int $id, string $taxonomy ): array {
		$out      = array();
		$assigned = get_the_terms( $id, $taxonomy );
		foreach ( is_array( $assigned ) ? $assigned : array() as $term ) {
			$out[] = $term->slug;
		}
		return $out;
	}

	/** Assign terms by slug; a slug that does not exist is skipped (a restore never invents a term). */
	public function set_terms( int $id, string $taxonomy, array $slugs ): void {
		$known = array();
		foreach ( $slugs as $slug ) {
			if ( term_exists( $slug, $taxonomy ) ) {
				$known[] = $slug;
			}
		}
		wp_set_object_terms( $id, $known, $taxonomy );
	}

	public function delete_post( int $id ): bool {
		return (bool) wp_delete_post( $id, true );
	}

	/**
	 * A file of the media library, created by the restore. Core's wp_insert_attachment() is used (an attachment has no
	 * plugin hooks to avoid); the labels go in right after the insert so a stop leaves something undo can find.
	 *
	 * @param array  $fields post_title, post_mime_type, post_status.
	 * @param string $file   Absolute path of the file, already inside the uploads folder.
	 * @param array  $meta   Meta to set at once (the restore labels).
	 * @return int Id of the attachment, 0 on failure.
	 */
	public function insert_attachment( array $fields, string $file, array $meta ): int {
		$id = wp_insert_attachment(
			array(
				'post_title'     => wp_slash( (string) ( $fields['post_title'] ?? '' ) ),
				'post_name'      => (string) ( $fields['post_name'] ?? '' ), // Empty = let WordPress derive it; a protected title gets a neutral one.
				'post_mime_type' => (string) ( $fields['post_mime_type'] ?? '' ),
				'post_status'    => (string) ( $fields['post_status'] ?? 'inherit' ),
				'post_content'   => '',
			),
			$file,
			0,
			true
		);
		if ( is_wp_error( $id ) || (int) $id <= 0 ) {
			return 0;
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( (int) $id, (string) $key, wp_slash( (string) $value ) );
		}
		return (int) $id;
	}

	/** Thumbnails and metadata of an image attachment (not of other files: their `sizes` would point to nothing). */
	public function generate_attachment_metadata( int $id, string $file ): void {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$data = wp_generate_attachment_metadata( $id, $file );
		if ( is_array( $data ) && ! empty( $data ) ) {
			wp_update_attachment_metadata( $id, $data );
		}
	}

	/** Absolute path of the file of an attachment, '' when it has none. */
	public function attachment_path( int $id ): string {
		$path = get_attached_file( $id, true );
		return is_string( $path ) ? $path : '';
	}

	/** Delete an attachment and its files. */
	public function delete_attachment( int $id ): bool {
		return false !== wp_delete_attachment( $id, true );
	}

	/**
	 * Delete up to $limit records of these post types for good (every status, the bin included), except those that
	 * carry the restore label $keep_batch. No delete hook fires: Pro's Google Calendar sync would remove the events
	 * of the person's calendar and the restore does not bring them back. Terms and attachments are left alone.
	 *
	 * @return array{deleted:int,remaining:int,error:bool}
	 */
	public function wipe_batch( array $post_types, string $keep_batch, int $limit ): array {
		global $wpdb;
		if ( empty( $post_types ) ) {
			return array( 'deleted' => 0, 'remaining' => 0, 'error' => false );
		}
		$types = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$where = "post_type IN ( $types ) AND NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = {$wpdb->posts}.ID AND pm.meta_key = %s AND pm.meta_value = %s )";
		$args  = array_merge( array_values( $post_types ), array( Fotonic_Restore_Writer::BATCH_META, $keep_batch ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built from the list of post types; deliberately no delete hooks.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE $where ORDER BY ID ASC LIMIT " . max( 1, (int) $limit ), $args ) ) );
		$failed = false;
		if ( ! empty( $ids ) ) {
			$in    = implode( ',', $ids );
			$terms = (array) $wpdb->get_results( "SELECT DISTINCT tt.term_taxonomy_id, tt.taxonomy FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id IN ( $in )", ARRAY_A );
			$failed = false === $wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ( $in )" )
				|| false === $wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ( $in )" )
				|| false === $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ( $in )" );
			foreach ( $ids as $id ) {
				clean_post_cache( $id );
			}
			$by_taxonomy = array();
			foreach ( $terms as $t ) {
				$by_taxonomy[ $t['taxonomy'] ][] = (int) $t['term_taxonomy_id'];
			}
			foreach ( $by_taxonomy as $taxonomy => $tt_ids ) {
				wp_update_term_count_now( $tt_ids, $taxonomy ); // The counts of the terms the records were in.
			}
		}
		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE $where", $args ) );
		// phpcs:enable
		return array( 'deleted' => $failed ? 0 : count( $ids ), 'remaining' => $remaining, 'error' => $failed );
	}

	/**
	 * Records of a type that looks like the plugin's (`ftnc_*`) but is not in $known_types: Pro's data even when the Pro
	 * plugin is switched off (its types are then not registered, so no dataset lists them).
	 */
	public function count_unknown_records( array $known_types ): int {
		global $wpdb;
		$statuses = Fotonic_Backup_Archive::POST_STATUSES;
		$stat     = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args     = array( $wpdb->esc_like( 'ftnc_' ) . '%' );
		$not      = '';
		if ( ! empty( $known_types ) ) {
			$not  = ' AND post_type NOT IN ( ' . implode( ',', array_fill( 0, count( $known_types ), '%s' ) ) . ' )';
			$args = array_merge( $args, array_values( $known_types ) );
		}
		$args = array_merge( $args, $statuses );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built from constant lists.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type LIKE %s$not AND post_status IN ( $stat )", $args ) );
	}

	/** @return int[] Ids of the records that carry a meta with exactly this value. */
	public function ids_with_meta( string $key, string $value, int $limit = 0 ): array {
		global $wpdb;
		$sql = "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC";
		if ( $limit > 0 ) {
			$sql .= ' LIMIT ' . (int) $limit;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- the limit is an intval; the key and value are placeholders.
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, $key, $value ) ) );
	}

	/** How many records of these post types the site has (trash included, as the archive collects). */
	public function count_records( array $post_types ): int {
		global $wpdb;
		if ( empty( $post_types ) ) {
			return 0;
		}
		$statuses = Fotonic_Backup_Archive::POST_STATUSES;
		$types    = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$stat     = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- placeholders are built from constant lists.
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( $types ) AND post_status IN ( $stat )", array_merge( array_values( $post_types ), $statuses ) ) );
	}

	/**
	 * A short fingerprint of the records of these post types and of their plugin meta: it changes when a
	 * record is added, removed or edited. The preview stores it, the start of the restore compares it.
	 */
	public function fingerprint( array $post_types ): string {
		global $wpdb;
		if ( empty( $post_types ) ) {
			return '';
		}
		$statuses = Fotonic_Backup_Archive::POST_STATUSES;
		$types    = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$stat     = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args     = array_merge( array_values( $post_types ), $statuses );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- aggregate read; placeholders are built from constant lists.
		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT post_type, COUNT(*) AS n, SUM(CRC32(CONCAT(ID, '|', post_status, '|', post_modified_gmt, '|', post_title))) AS h FROM {$wpdb->posts} WHERE post_type IN ( $types ) AND post_status IN ( $stat ) GROUP BY post_type ORDER BY post_type", $args ), ARRAY_A );
		$meta  = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, SUM(CRC32(CONCAT(pm.post_id, '|', pm.meta_key, '|', pm.meta_value))) AS h FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type IN ( $types ) AND p.post_status IN ( $stat ) AND pm.meta_key LIKE %s", array_merge( $args, array( $wpdb->esc_like( '_ftnc_' ) . '%' ) ) ), ARRAY_A );
		// phpcs:enable
		return md5( wp_json_encode( array( $posts, $meta ) ) );
	}

	/** The one thing the save hooks did that a restore needs: the payment status of a work. */
	public function finish( string $post_type, int $id ): void {
		if ( 'ftnc_work' === $post_type && class_exists( 'Fotonic_Meta_Boxes' ) ) {
			Fotonic_Meta_Boxes::auto_assign_payment_status( $id );
		}
		clean_post_cache( $id );
	}
}
