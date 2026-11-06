<?php
/**
 * The Bin of the plugin (phase 16, 1.7.0): what the app "deleted" (WordPress trash) and has not removed yet.
 *
 * One place that lists the plugin's records in the Bin, brings them back and deletes them for good.
 * WordPress itself empties the Bin after EMPTY_TRASH_DAYS days (30 by default, set by the host); this class makes
 * that visible and lets the admin act earlier.
 *
 * Rules:
 * - Only the plugin's own record types (Fotonic_Restore_Writer::post_types(), Pro's included while Pro is active);
 *   a type never comes from the client.
 * - Deleting for good = wp_delete_post( $id, true ): the deletion hooks of free and Pro do the rest (documents of a
 *   work, quote PDFs). Google Calendar events already left Google when the work went into the Bin.
 * - A record is never deleted while something outside the Bin still points at it (blocker()).
 * - Nothing here needs the Vault: a title that cannot be read is null ("protected item").
 *
 * @package Fotonic
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_Trash {

	/** The word the person types to delete several records at once. Owned by the server, like RESET and REPLACE. */
	const WORD = 'DELETE';

	/** Records deleted per call (each deletion can remove files). */
	const BATCH = 20;

	/**
	 * Deletion order: records that point at others first, so a customer or a service is not held back by works that
	 * are in the Bin too.
	 */
	const ORDER = array(
		'ftnc_task', 'ftnc_work', 'ftnc_quote', 'ftnc_expense', 'ftnc_product', 'ftnc_supplier',
		'ftnc_collaborator', 'ftnc_memory_card', 'ftnc_backup_loc', 'ftnc_service', 'ftnc_customer',
	);

	// ---------------------------------------------------------------------------
	// Pure helpers (unit tested without WordPress)
	// ---------------------------------------------------------------------------

	/** Unix time the Bin removes a record by itself, or null when the host does not empty the Bin by itself. */
	public static function expires_at( int $trashed_at, $days ): ?int {
		$days = is_numeric( $days ) ? (int) $days : 0;
		if ( $trashed_at <= 0 || $days <= 0 ) {
			return null;
		}
		return $trashed_at + $days * 86400;
	}

	/** Position of a post type in the deletion order (unknown types go last). */
	public static function rank( string $type ): int {
		$i = array_search( $type, self::ORDER, true );
		return false === $i ? count( self::ORDER ) : (int) $i;
	}

	/**
	 * Sort [ id => type ] by the deletion order, then by id.
	 *
	 * @param array $map id => post type.
	 * @return int[] Ids.
	 */
	public static function sort_for_delete( array $map ): array {
		$ids = array_keys( $map );
		usort( $ids, function ( $a, $b ) use ( $map ) {
			$r = self::rank( (string) $map[ $a ] ) <=> self::rank( (string) $map[ $b ] );
			return 0 !== $r ? $r : ( (int) $a <=> (int) $b );
		} );
		return array_map( 'intval', $ids );
	}

	/** The status a record gets back from the Bin: the one it had, never "trash" or an auto-draft. */
	public static function restore_status( $previous ): string {
		$previous = is_string( $previous ) ? $previous : '';
		return in_array( $previous, array( 'publish', 'draft', 'pending', 'private', 'future' ), true ) ? $previous : 'publish';
	}

	/** Is this the word that unlocks a delete of several records? */
	public static function confirm_ok( $given ): bool {
		return is_string( $given ) && hash_equals( self::WORD, trim( $given ) );
	}

	/** Does this call need the word? Several ids or "all": yes. One id: no. */
	public static function needs_word( bool $all, int $count ): bool {
		return $all || $count > 1;
	}

	// ---------------------------------------------------------------------------
	// Which records
	// ---------------------------------------------------------------------------

	/** The record types of the Bin (registered post types only). */
	public static function types(): array {
		$types = array();
		foreach ( Fotonic_Restore_Writer::post_types() as $t ) {
			if ( post_type_exists( $t ) ) {
				$types[] = $t;
			}
		}
		return array_values( array_unique( $types ) );
	}

	/**
	 * Test seam: a list of ids that limits every read and write of this class to those records (the real Bin of the
	 * site may hold the admin's own records). Null = no limit. Mirrors ftnc_vault_encrypt_scope.
	 */
	private static function scope(): ?array {
		$scope = apply_filters( 'ftnc_trash_scope', null );
		return is_array( $scope ) ? array_map( 'intval', $scope ) : null;
	}

	/** Is $type one of the record types of the Bin? (A type from the client is checked here, never used as it comes.) */
	public static function valid_type( $type ): bool {
		return is_string( $type ) && in_array( $type, self::types(), true );
	}

	/**
	 * SQL fragment (after WHERE) that selects the plugin's records in the Bin.
	 *
	 * @param string|null $type Only this record type (must be valid: the caller checks it), null = every type.
	 */
	private static function where( ?string $type = null ): string {
		global $wpdb;
		$types = null !== $type ? ( self::valid_type( $type ) ? array( $type ) : array() ) : self::types();
		if ( ! $types ) {
			return '1=0';
		}
		$in  = implode( ',', array_map( function ( $t ) use ( $wpdb ) { return $wpdb->prepare( '%s', $t ); }, $types ) );
		$sql = "p.post_status = 'trash' AND p.post_type IN ($in)";
		$scope = self::scope();
		if ( null !== $scope ) {
			$sql .= $scope ? ' AND p.ID IN (' . implode( ',', $scope ) . ')' : ' AND 1=0';
		}
		return $sql;
	}

	public static function count( ?string $type = null ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->posts . ' p WHERE ' . self::where( $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- where() prepares its values.
	}

	/** Records in the Bin per record type, in ONE query: [ post type => count ] (types with nothing are left out). */
	public static function by_type(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT p.post_type, COUNT(*) AS n FROM ' . $wpdb->posts . ' p WHERE ' . self::where() . ' GROUP BY p.post_type' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r->post_type ] = (int) $r->n;
		}
		return $out;
	}

	/** Days after which WordPress empties the Bin by itself (0 = never, or the Bin is off). */
	public static function auto_days(): int {
		return defined( 'EMPTY_TRASH_DAYS' ) && is_numeric( EMPTY_TRASH_DAYS ) ? (int) EMPTY_TRASH_DAYS : 0;
	}

	/** @return array<int,string> id => post type of everything in the Bin (the plugin's types). */
	private static function all_map( ?string $type = null ): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT p.ID, p.post_type FROM ' . $wpdb->posts . ' p WHERE ' . self::where( $type ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$map  = array();
		foreach ( (array) $rows as $r ) {
			$map[ (int) $r->ID ] = (string) $r->post_type;
		}
		return $map;
	}

	/**
	 * One page of the Bin, the most recently deleted first.
	 *
	 * @return array{data: array, total: int, pages: int}
	 */
	public static function items( int $page, int $per_page, ?string $type = null ): array {
		global $wpdb;
		$page     = max( 1, $page );
		$per_page = min( 100, max( 1, $per_page ) );
		$total    = self::count( $type );
		$ids      = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			'SELECT p.ID FROM ' . $wpdb->posts . ' p LEFT JOIN ' . $wpdb->postmeta . " m ON m.post_id = p.ID AND m.meta_key = '_wp_trash_meta_time' WHERE " . self::where( $type )
			. ' ORDER BY CAST( m.meta_value AS UNSIGNED ) DESC, p.ID DESC LIMIT %d OFFSET %d',
			$per_page,
			( $page - 1 ) * $per_page
		) );
		$data = array();
		$key  = Fotonic_Vault::get_session_key();
		foreach ( (array) $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post ) {
				$data[] = self::row( $post, $key );
			}
		}
		return array(
			'data'  => $data,
			'total' => $total,
			'pages' => (int) max( 1, ceil( $total / $per_page ) ),
		);
	}

	private static function trashed_at( \WP_Post $post ): int {
		$t = (int) get_post_meta( $post->ID, '_wp_trash_meta_time', true );
		return $t > 0 ? $t : (int) strtotime( $post->post_modified_gmt . ' UTC' );
	}

	/** The singular name of a record type, already translated by whoever registered it (free or Pro). */
	public static function type_label( string $type ): string {
		$obj = get_post_type_object( $type );
		return $obj && isset( $obj->labels->singular_name ) ? (string) $obj->labels->singular_name : $type;
	}

	/** [ post type => plural name ] of the types that have something in the Bin (for the filter of the Bin page). */
	public static function type_names(): array {
		$out = array();
		foreach ( array_keys( self::by_type() ) as $type ) {
			$obj          = get_post_type_object( $type );
			$out[ $type ] = $obj && isset( $obj->labels->name ) ? (string) $obj->labels->name : $type;
		}
		return $out;
	}

	private static function row( \WP_Post $post, ?string $key ): array {
		$label   = self::type_label( $post->post_type );
		$trashed = self::trashed_at( $post );
		$title   = Fotonic_Titles::read( $post, $key ); // null = protected and the Vault is closed.
		$expires = self::expires_at( $trashed, self::auto_days() );
		return array(
			'id'         => $post->ID,
			'type'       => $post->post_type,
			'type_label' => $label,
			'title'      => $title,
			'trashed_at' => $trashed,
			'expires_at' => $expires,
			'blocked'    => self::blocker( $post ),
		);
	}

	// ---------------------------------------------------------------------------
	// Who still points at a record
	// ---------------------------------------------------------------------------

	/** Works (any status but Bin and auto-draft) of a customer. @return int[] */
	public static function live_work_ids_for_customer( int $customer_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ftnc_customer_id' AND m.meta_value = %s
			 WHERE p.post_type = 'ftnc_work' AND p.post_status NOT IN ( 'trash', 'auto-draft' ) ORDER BY p.ID",
			(string) $customer_id
		) );
		return array_map( 'intval', (array) $ids );
	}

	/** Works (any status but Bin and auto-draft) that list a service. @return int[] */
	public static function live_work_ids_for_service( int $service_id ): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT p.ID, m.meta_value FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_ftnc_work_services'
			 WHERE p.post_type = 'ftnc_work' AND p.post_status NOT IN ( 'trash', 'auto-draft' ) AND m.meta_value LIKE '%service_id%' ORDER BY p.ID"
		);
		$out = array();
		foreach ( (array) $rows as $r ) {
			$list = json_decode( (string) $r->meta_value, true );
			if ( ! is_array( $list ) ) {
				continue;
			}
			foreach ( $list as $svc ) {
				if ( isset( $svc['service_id'] ) && (int) $svc['service_id'] === $service_id ) {
					$out[] = (int) $r->ID;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Why a record in the Bin cannot be deleted for good yet, or null. A sentence for the person, never a code.
	 * Pro adds its own through the filter ftnc_trash_blocker( null, WP_Post ).
	 */
	public static function blocker( \WP_Post $post ): ?string {
		$reason = null;
		if ( 'ftnc_customer' === $post->post_type ) {
			$n = count( self::live_work_ids_for_customer( $post->ID ) );
			if ( $n > 0 ) {
				/* translators: %d: number of works */
				$reason = sprintf( __( 'This customer is still used by works that are not in the Bin (%d). Change or delete them first.', 'eleva-crm-for-photographers' ), $n );
			}
		} elseif ( 'ftnc_service' === $post->post_type ) {
			$n = count( self::live_work_ids_for_service( $post->ID ) );
			if ( $n > 0 ) {
				/* translators: %d: number of works */
				$reason = sprintf( __( 'This service is still used by works that are not in the Bin (%d). Change or delete them first.', 'eleva-crm-for-photographers' ), $n );
			}
		} elseif ( 'ftnc_memory_card' === $post->post_type && 'in_use' === Fotonic_Memory_Card_CPT::get_card_status( $post->ID ) ) {
			$reason = __( 'This memory card is marked as in use. Mark it as free first.', 'eleva-crm-for-photographers' );
		}
		$filtered = apply_filters( 'ftnc_trash_blocker', $reason, $post );
		return is_string( $filtered ) && '' !== $filtered ? $filtered : null;
	}

	// ---------------------------------------------------------------------------
	// Restore
	// ---------------------------------------------------------------------------

	/**
	 * wp_untrash_post_status: WordPress puts every restored record back as a draft. A record of the plugin goes back
	 * to the status it had, so it is visible again. Global on purpose: it holds for every way a record leaves the Bin.
	 */
	public static function untrash_status( $new_status, $post_id, $previous_status ) {
		$type = get_post_type( (int) $post_id );
		if ( $type && in_array( $type, self::types(), true ) ) {
			return self::restore_status( $previous_status );
		}
		return $new_status;
	}

	/** Is this id a record of the plugin that is in the Bin (and inside the test scope)? */
	private static function in_bin( int $id ): ?\WP_Post {
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post || 'trash' !== $post->post_status || ! in_array( $post->post_type, self::types(), true ) ) {
			return null;
		}
		$scope = self::scope();
		if ( null !== $scope && ! in_array( $id, $scope, true ) ) {
			return null;
		}
		return $post;
	}

	/**
	 * Bring one record back.
	 *
	 * @return array{id:int, ok:bool, warnings:array, error?:string}
	 */
	public static function restore( int $id ): array {
		$post = self::in_bin( $id );
		if ( ! $post ) {
			return array( 'id' => $id, 'ok' => false, 'warnings' => array(), 'error' => 'not_found' );
		}
		$type = $post->post_type;
		wp_untrash_post( $id );
		$after = get_post( $id );
		if ( ! $after || 'trash' === $after->post_status ) {
			return array( 'id' => $id, 'ok' => false, 'warnings' => array(), 'error' => 'failed' );
		}
		self::log( 'restored', array( $type ), 1 );
		return array( 'id' => $id, 'ok' => true, 'warnings' => self::warnings( $after ) );
	}

	/**
	 * What the person has to look at after a restore: a work or a quote whose customer is still in the Bin, or gone.
	 * Nothing is restored along with it (the person chose this record only).
	 */
	public static function warnings( \WP_Post $post ): array {
		$out = array();
		if ( in_array( $post->post_type, array( 'ftnc_work', 'ftnc_quote' ), true ) ) {
			$cid = (int) get_post_meta( $post->ID, '_ftnc_customer_id', true );
			if ( $cid > 0 ) {
				$cust = get_post( $cid );
				if ( ! $cust || 'ftnc_customer' !== $cust->post_type ) {
					$out[] = array( 'code' => 'parent_missing', 'type' => 'ftnc_customer' );
				} elseif ( 'trash' === $cust->post_status ) {
					$out[] = array( 'code' => 'parent_in_bin', 'type' => 'ftnc_customer' );
				}
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------------------
	// Delete for good
	// ---------------------------------------------------------------------------

	/**
	 * Is it safe to delete now? A WP_Error says why not (423 while a restore or the encryption runs).
	 *
	 * @return true|\WP_Error
	 */
	public static function guard() {
		if ( Fotonic_Vault_Encrypt_Existing::is_blocking() ) {
			return new \WP_Error( 'encrypt_in_progress', __( 'A restore or the protection of your data is running. Try again when it has finished.', 'eleva-crm-for-photographers' ), array( 'status' => 423 ) );
		}
		return true;
	}

	/**
	 * Delete up to BATCH records for good.
	 *
	 * @param int[]|null $ids  The records to delete, or null with $all = true.
	 * @param bool       $all  Everything that is in the Bin (the server picks the ids).
	 * @param int[]      $skip Ids already refused in an earlier call of the same run (all mode).
	 * @param string|null $type In all mode: only this record type (the caller has checked it is valid), null = every type.
	 * @return array{deleted:int, blocked:array, processed:int[], remaining:int, missing:int}
	 */
	public static function delete_batch( ?array $ids, bool $all, array $skip = array(), ?string $type = null ): array {
		$skip = array_map( 'intval', $skip );
		if ( $all ) {
			$map = self::all_map( $type );
			foreach ( $skip as $s ) {
				unset( $map[ $s ] );
			}
			$todo = self::sort_for_delete( $map );
		} else {
			$todo = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
		}
		$batch   = array_slice( $todo, 0, self::BATCH );
		$deleted = 0;
		$missing = 0;
		$blocked = array();
		$types   = array();
		foreach ( $batch as $id ) {
			$post = self::in_bin( $id );
			if ( ! $post ) {
				$missing++;
				continue;
			}
			$reason = self::blocker( $post );
			if ( null !== $reason ) {
				$blocked[] = array( 'id' => $id, 'type' => $post->post_type, 'type_label' => self::type_label( $post->post_type ), 'reason' => $reason );
				continue;
			}
			$type = $post->post_type;
			wp_delete_post( $id, true );
			if ( null === get_post( $id ) || false === get_post( $id ) ) {
				$deleted++;
				$types[] = $type;
			} else {
				$blocked[] = array( 'id' => $id, 'type' => $type, 'type_label' => self::type_label( $type ), 'reason' => __( 'It could not be deleted.', 'eleva-crm-for-photographers' ) );
			}
		}
		if ( $deleted ) {
			self::log( 'deleted', $types, $deleted );
		}
		return array(
			'deleted'   => $deleted,
			'blocked'   => $blocked,
			'processed' => $batch,
			'missing'   => $missing,
			'remaining' => max( 0, count( $todo ) - count( $batch ) ),
		);
	}

	/** One line in the error log per action: what and how many, never a title. */
	private static function log( string $what, array $types, int $n ): void {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Audit trail, same as the Vault and the backups.
		error_log( sprintf( '[eleva-crm] bin %s %d record(s): %s', $what, $n, implode( ',', array_count_values( $types ) ? array_keys( array_count_values( $types ) ) : array() ) ) );
	}
}
