<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Restore, step 3 (WordPress side): the preview of a restore. Writes NOTHING to the site; the only
 * files it creates are plan files inside the private folder of the restore session.
 *
 * One call to step() reads a batch of backup records of one dataset, matches each against the records of
 * this site (Fotonic_Restore_Matcher) and appends the verdicts to `plan-<dataset>.jsonl` in the session
 * folder: one line per backup record, `{"b":backup id,"s":status,"i":site id|null,"c":[candidates],"f":[field names]}`.
 * Ids, statuses and field names only: no decrypted value is ever stored. The plan is what phase 09
 * (the writer) will read, and what a later call needs (ids already taken, ids of the records the next
 * dataset points to). The client calls step() until `done` is true.
 *
 * Datasets are walked in dependency order (customers, services, ..., works), so a work is compared with
 * the customer it points to translated to this site. Collections that are not records (`terms:*`,
 * `attachments`) are not counted: attachments come with their works (phase 10). Datasets of the backup
 * that this site does not know (Pro switched off) are unimportable, listed by name with their counts.
 *
 * Keys: the old Master Key (from the restore session) opens the backup side; this site's own key opens the
 * site side. A site whose Vault is set up but locked cannot be compared: 423 `vault_locked`.
 */
class Fotonic_Restore_Preview {

	/** Backup records read per call. */
	const BATCH = 250;

	/** @var int Records per call right now (tests make it small). */
	private static $batch = self::BATCH;

	/** Records per call; tests only. */
	public static function set_batch_size( int $size ): void {
		self::$batch = max( 1, $size );
	}

	/** Stop reading more records in one call after this many seconds. */
	const TIME_BUDGET = 10;

	/** @var array<string,array> Request cache of the site index per dataset. */
	private static $site_cache = array();

	// ---------------------------------------------------------------------------
	// Step
	// ---------------------------------------------------------------------------

	/**
	 * Process the next batch of the preview.
	 *
	 * @param string $session_id Restore session (needs its cookie, like every read of the archive).
	 * @param bool   $restart    Forget the plan and start again.
	 * @return array|\WP_Error   { done, phase, counts, unimportable, attachments, items, processed }
	 */
	public static function step( string $session_id, bool $restart = false ) {
		// The plan belongs to the restore that is using it: a preview now would delete it under the writer.
		if ( class_exists( 'Fotonic_Restore_Job' ) && Fotonic_Restore_Job::uses_session( $session_id ) ) {
			return new \WP_Error( 'ftnc_restore_in_use', __( 'A restore is still going on. Finish it or undo it before you check another backup.', 'eleva-crm-for-photographers' ), array( 'status' => 409 ) );
		}
		$collections = Fotonic_Restore_Reader::collections( $session_id );
		if ( is_wp_error( $collections ) ) {
			return $collections;
		}
		$dir = Fotonic_Restore_Reader::work_dir( $session_id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$site_key = Fotonic_Vault::get_session_key();
		if ( null === $site_key && Fotonic_Vault::is_setup() ) {
			return new \WP_Error( 'vault_locked', __( 'Unlock the Vault of this site first: the backup is compared with the records you have now.', 'eleva-crm-for-photographers' ), array( 'status' => 423 ) );
		}
		$old_key = Fotonic_Restore_Reader::old_master_key( $session_id );
		if ( is_wp_error( $old_key ) ) {
			return $old_key;
		}

		$lock = fopen( $dir . '/plan.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			return new \WP_Error( 'ftnc_restore_busy', __( 'The preview is already running. Wait a moment.', 'eleva-crm-for-photographers' ), array( 'status' => 409 ) );
		}
		try {
			if ( $restart ) {
				foreach ( (array) glob( $dir . '/plan-*.jsonl' ) as $file ) {
					wp_delete_file( $file );
				}
			}
			return self::run_step( $session_id, $dir, $collections, $site_key, $old_key );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	private static function run_step( string $session_id, string $dir, array $collections, ?string $site_key, string $old_key ) {
		$registry = Fotonic_Backup_Registry::datasets();
		// A dataset the registry knows but this site may not write (Pro installed without a valid license) is left out like one it does not know.
		$writable = class_exists( 'Fotonic_Restore_Writer' ) ? Fotonic_Restore_Writer::writable_datasets() : null; // null: the writer is not loaded (the preview unit test), no filtering.
		$present  = array();
		$attach   = 0;
		$unknown  = array();
		foreach ( $collections as $c ) {
			if ( 'attachments' === $c['kind'] ) {
				$attach = (int) $c['count'];
			} elseif ( 'datasets' === $c['kind'] ) {
				if ( isset( $registry[ $c['key'] ] ) && 1 === preg_match( '/^[a-z0-9_]{1,60}$/', (string) $c['key'] ) && ( null === $writable || in_array( $c['key'], $writable, true ) ) ) {
					$present[ $c['key'] ] = $c;
				} elseif ( (int) $c['count'] > 0 ) { // A kind with no records has nothing to leave out.
					$unknown[] = array( 'dataset' => (string) $c['key'], 'count' => (int) $c['count'] );
				}
			}
		}

		$order   = self::order( array_keys( $present ), $registry );
		$plans   = array();
		$refmap  = array();
		$counts  = array( 'new' => 0, 'identical' => 0, 'different' => 0, 'possible' => 0, 'unimportable' => 0 );
		foreach ( $unknown as $u ) {
			$counts['unimportable'] += $u['count'];
		}
		foreach ( $order as $key ) {
			$plans[ $key ] = self::read_plan( $dir, $key );
			foreach ( $plans[ $key ] as $line ) {
				$counts[ $line['s'] ] = ( $counts[ $line['s'] ] ?? 0 ) + 1;
				if ( $line['b'] > 0 ) {
					$refmap[ $key ][ $line['b'] ] = self::ref_text( $line );
				}
			}
		}

		// The first dataset not finished.
		$current = null;
		foreach ( $order as $key ) {
			if ( count( $plans[ $key ] ) < (int) $present[ $key ]['count'] ) {
				$current = $key;
				break;
			}
		}

		$items     = array();
		$processed = 0;
		if ( null !== $current ) {
			$dataset = $registry[ $current ];
			$rules   = Fotonic_Restore_Matcher::rules( $current, $dataset );
			$specs   = Fotonic_Restore_Matcher::field_specs( (string) $dataset['post_type'] );
			$offset  = count( $plans[ $current ] );
			$records = Fotonic_Restore_Reader::records( $session_id, $present[ $current ]['id'], $offset, self::$batch );
			if ( is_wp_error( $records ) ) {
				return $records;
			}
			$site    = self::site_context( $current, $dataset, $rules, $specs, $site_key );
			$claimed = array();
			foreach ( $plans[ $current ] as $line ) {
				if ( in_array( $line['s'], array( 'identical', 'different' ), true ) && $line['i'] ) {
					$claimed[] = (int) $line['i'];
				}
			}
			$started = microtime( true );
			$lines   = '';
			foreach ( $records as $record ) {
				if ( microtime( true ) - $started > self::TIME_BUDGET ) {
					break;
				}
				$processed++;
				$bid = is_array( $record ) && isset( $record['ID'] ) ? (int) $record['ID'] : 0;
				if ( $bid <= 0 ) {
					$counts['unimportable']++;
					$lines .= wp_json_encode( array( 'b' => 0, 's' => 'unimportable', 'i' => null, 'c' => array(), 'f' => array() ) ) . "\n";
					continue;
				}
				$bview  = Fotonic_Restore_Matcher::view( $record, $dataset, $rules, $specs, $old_key, $refmap );
				$result = Fotonic_Restore_Matcher::classify( $rules, $bview, $site['index'], $site['load'], $claimed );
				$line   = array(
					'b' => $bid,
					's' => $result['status'],
					'i' => $result['site_id'],
					'c' => $result['candidates'],
					'f' => array_map( function ( $d ) { return $d['field']; }, $result['diff'] ),
				);
				$lines .= wp_json_encode( $line ) . "\n";
				$counts[ $result['status'] ]++;
				$refmap[ $current ][ $bid ] = self::ref_text( $line );
				if ( null !== $result['site_id'] && in_array( $result['status'], array( 'identical', 'different' ), true ) ) {
					$claimed[] = (int) $result['site_id'];
				}
				if ( Fotonic_Restore_Matcher::IDENTICAL !== $result['status'] ) {
					$items[] = self::item( $current, $bid, $bview, $result, $site );
				}
			}
			if ( '' !== $lines ) {
				file_put_contents( $dir . '/plan-' . $current . '.jsonl', $lines, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}
			$done_here = $offset + $processed;
		}

		$finished = true;
		foreach ( $order as $key ) {
			$have = $key === $current ? ( $offset + $processed ) : count( $plans[ $key ] );
			if ( $have < (int) $present[ $key ]['count'] ) {
				$finished = false;
				break;
			}
		}

		if ( $finished && class_exists( 'Fotonic_Restore_Job' ) ) {
			// A photograph of this site for the writer: it refuses to start when the site changed after this moment.
			file_put_contents( $dir . '/plan.fp', Fotonic_Restore_Job::fingerprint() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}

		return array(
			'done'         => $finished,
			'phase'        => null === $current ? null : array( 'dataset' => $current, 'done' => $done_here, 'total' => (int) $present[ $current ]['count'] ),
			'counts'       => $counts,
			'unimportable' => $unknown,
			'attachments'  => $attach,
			'items'        => $items,
			'processed'    => $processed,
		);
	}

	// ---------------------------------------------------------------------------
	// The plan
	// ---------------------------------------------------------------------------

	/**
	 * The plan written so far, for the writer (phase 09) and the screen: dataset key => list of
	 * { b: backup id, s: status, i: site id|null, c: candidate ids, f: field names that differ }.
	 *
	 * @return array|\WP_Error
	 */
	public static function plan( string $session_id ) {
		$dir = Fotonic_Restore_Reader::work_dir( $session_id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$out = array();
		foreach ( (array) glob( $dir . '/plan-*.jsonl' ) as $file ) {
			$key         = substr( basename( $file ), 5, -6 );
			$out[ $key ] = self::read_plan( $dir, $key );
		}
		return $out;
	}

	private static function read_plan( string $dir, string $key ): array {
		$file = $dir . '/plan-' . $key . '.jsonl';
		if ( ! is_file( $file ) ) {
			return array();
		}
		$out = array();
		$fh  = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return array();
		}
		while ( false !== ( $line = fgets( $fh ) ) ) {
			$row = json_decode( trim( $line ), true );
			if ( is_array( $row ) && isset( $row['b'], $row['s'] ) ) {
				$out[] = array(
					'b' => (int) $row['b'],
					's' => (string) $row['s'],
					'i' => isset( $row['i'] ) ? (int) $row['i'] : null,
					'c' => isset( $row['c'] ) && is_array( $row['c'] ) ? array_map( 'intval', $row['c'] ) : array(),
					'f' => isset( $row['f'] ) && is_array( $row['f'] ) ? $row['f'] : array(),
				);
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $out;
	}

	/** What a record of the backup stands for on this site, for the records that point to it. */
	private static function ref_text( array $line ): string {
		if ( in_array( $line['s'], array( 'identical', 'different' ), true ) && $line['i'] ) {
			return (string) (int) $line['i'];
		}
		return 'possible' === $line['s'] ? 'possible:' . $line['b'] : 'new:' . $line['b'];
	}

	/** Datasets in the order they can be compared: the ones a dataset points to come first. */
	public static function order( array $keys, array $registry ): array {
		$out     = array();
		$visited = array();
		$visit   = function ( $key ) use ( &$visit, &$out, &$visited, $keys, $registry ) {
			if ( isset( $visited[ $key ] ) ) {
				return;
			}
			$visited[ $key ] = true;
			$rules = Fotonic_Restore_Matcher::rules( $key, $registry[ $key ] );
			foreach ( (array) $rules['refs'] as $ref ) {
				if ( is_array( $ref ) && in_array( $ref[0], $keys, true ) ) {
					$visit( $ref[0] );
				}
			}
			foreach ( (array) $rules['after'] as $before ) {
				if ( is_string( $before ) && in_array( $before, $keys, true ) && isset( $registry[ $before ] ) ) {
					$visit( $before );
				}
			}
			$out[] = $key;
		};
		foreach ( $keys as $key ) {
			$visit( $key );
		}
		return $out;
	}

	// ---------------------------------------------------------------------------
	// This site
	// ---------------------------------------------------------------------------

	/** One record of this site in the shape of a backup record. */
	private static function site_record( int $id, array $dataset ): array {
		$post   = get_post( $id );
		$record = array( 'ID' => $id, 'post_status' => $post ? $post->post_status : '' );
		if ( $post && in_array( 'title', (array) $dataset['supports'], true ) ) {
			$record['post_title'] = $post->post_title;
		}
		if ( $post && in_array( 'content', (array) $dataset['supports'], true ) ) {
			$record['post_content'] = $post->post_content;
		}
		$meta = array();
		foreach ( (array) $dataset['meta_keys'] as $key ) {
			$meta[ $key ] = get_post_meta( $id, $key, true );
		}
		$record['meta'] = $meta;
		$terms          = array();
		foreach ( (array) $dataset['taxonomies'] as $taxonomy => $opts ) {
			if ( ! empty( $opts['derived'] ) ) {
				continue;
			}
			$entries = array();
			$assigned = get_the_terms( $id, $taxonomy );
			foreach ( is_array( $assigned ) ? $assigned : array() as $term ) {
				$entries[] = array( 'slug' => $term->slug );
			}
			$terms[ $taxonomy ] = $entries;
		}
		$record['terms'] = $terms;
		return $record;
	}

	/**
	 * The keys of every record of this site for one dataset (one query for the ids, the meta and term
	 * caches primed in chunks), and a loader that rebuilds the view of one record.
	 *
	 * @return array{index:array,load:callable}
	 */
	private static function site_context( string $key, array $dataset, array $rules, array $specs, ?string $site_key ): array {
		if ( isset( self::$site_cache[ $key ] ) ) {
			return self::$site_cache[ $key ];
		}
		$ids   = self::site_ids( (string) $dataset['post_type'] );
		$exist = array();
		foreach ( (array) $rules['refs'] as $ref ) {
			if ( is_array( $ref ) && ! isset( $exist[ $ref[0] ] ) ) {
				$exist[ $ref[0] ] = array_flip( self::site_ids( (string) $ref[0] ) );
			}
		}
		// Datasets a handler points to (Pro: the collaborators of a work) are listed in `after`: their ids must be known too.
		$registry = Fotonic_Backup_Registry::datasets();
		foreach ( (array) $rules['after'] as $other ) {
			if ( is_string( $other ) && ! isset( $exist[ $other ] ) && isset( $registry[ $other ]['post_type'] ) ) {
				$exist[ $other ] = array_flip( self::site_ids( (string) $registry[ $other ]['post_type'] ) );
			}
		}
		$index = Fotonic_Restore_Matcher::new_index();
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			_prime_post_caches( $chunk, true, true );
			update_meta_cache( 'post', $chunk );
			if ( ! empty( $dataset['taxonomies'] ) ) {
				update_object_term_cache( $chunk, $dataset['post_type'] );
			}
			foreach ( $chunk as $id ) {
				$view = Fotonic_Restore_Matcher::view( self::site_record( $id, $dataset ), $dataset, $rules, $specs, $site_key, null, $exist );
				Fotonic_Restore_Matcher::index_add( $index, $id, $view, $rules );
			}
		}
		$load = function ( $id ) use ( $dataset, $rules, $specs, $site_key, $exist ) {
			return Fotonic_Restore_Matcher::view( self::site_record( (int) $id, $dataset ), $dataset, $rules, $specs, $site_key, null, $exist );
		};
		self::$site_cache[ $key ] = array( 'index' => $index, 'load' => $load );
		return self::$site_cache[ $key ];
	}

	/** @var array<string,int[]> Request cache of the ids of each post type. */
	private static $id_cache = array();

	/**
	 * Ids of every record of one post type on this site, trash included (the statuses the archive collects). One query.
	 *
	 * @return int[]
	 */
	private static function site_ids( string $post_type ): array {
		if ( isset( self::$id_cache[ $post_type ] ) ) {
			return self::$id_cache[ $post_type ];
		}
		global $wpdb;
		$statuses = Fotonic_Backup_Archive::POST_STATUSES;
		$marks    = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared -- one read of the ids of our own post type; the placeholders are built from a constant list.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( $marks ) ORDER BY ID ASC", array_merge( array( $post_type ), $statuses ) ) ) );
		self::$id_cache[ $post_type ] = $ids;
		return $ids;
	}

	/** Forget the request caches (tests). */
	public static function flush_cache(): void {
		self::$site_cache = array();
		self::$id_cache   = array();
	}

	// ---------------------------------------------------------------------------
	// What the screen shows
	// ---------------------------------------------------------------------------

	private static function item( string $dataset, int $bid, array $bview, array $result, array $site ): array {
		$item = array(
			'dataset'    => $dataset,
			'backup_id'  => $bid,
			'status'     => $result['status'],
			'title'      => $bview['title'],
			'site_id'    => $result['site_id'],
			'site_title' => null,
			'candidates' => array(),
			'diff'       => $result['diff'],
		);
		if ( null !== $result['site_id'] ) {
			$sv                 = call_user_func( $site['load'], $result['site_id'] );
			$item['site_title'] = $sv['title'];
		}
		foreach ( array_slice( $result['candidates'], 0, 5 ) as $cid ) {
			$cv                   = call_user_func( $site['load'], $cid );
			$item['candidates'][] = array( 'id' => (int) $cid, 'title' => $cv['title'] );
		}
		return $item;
	}
}
