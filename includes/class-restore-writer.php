<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Restore, step 4: writes ONE record of the backup into this site.
 *
 * Three actions (the job decides which, from the preview plan and the choices of the person):
 *   - import    : a new record, with the uid it had in the backup;
 *   - copy      : a new record with a NEW uid ("import as copy" of something that already exists here);
 *   - overwrite : the backup values replace those of a site record (`use_backup`). The values the record had
 *                 are first saved, as stored, in its meta `_ftnc_restore_prev`, so undo gives them back.
 * Every record written carries `_ftnc_import_batch` (the job id) and, when created, `_ftnc_import_src`
 * (`dataset:backup id`): undo finds them by the batch, and a job that stopped halfway can tell a record it
 * already created from one it has not.
 *
 * How values are written (the matcher reads them back the same way, so a preview run after a restore says
 * "identical" for everything, Review Focus 5):
 *   - personal fields (the Vault registry, Fotonic_Vault_Fields::groups()) are opened with the OLD Master Key
 *     and stored again with this site's key (scenario B), or as plain text on a site with no Vault (C). A value
 *     that cannot be opened is not written (the field stays empty) and is counted as `unreadable`;
 *   - references go through the id map only. A reference to a record the map does not know becomes "no
 *     reference" (0 in a row, no meta for a scalar), never the raw backup id;
 *   - an empty value is not written at all (a missing meta reads as '', which the matcher treats as empty);
 *   - rows of attachments get the new id of their file (Fotonic_Restore_Files imports the files before the record is written); a row whose file did not come back is left out and counted;
 *   - nothing here fires save hooks (see Fotonic_Restore_Repo); the payment status of a work is set by finish().
 * Created records get a neutral slug (`customer-123`), never one derived from a title that may be ciphertext.
 */
final class Fotonic_Restore_Writer {

	const BATCH_META = '_ftnc_import_batch';
	const SRC_META   = '_ftnc_import_src';
	const PREV_META  = '_ftnc_restore_prev';

	/** @var object|null Repository override (tests). */
	private static $repo = null;

	public static function use_repo( $repo ): void {
		self::$repo = $repo;
	}

	public static function repo() {
		if ( null === self::$repo ) {
			self::$repo = new Fotonic_Restore_Repo();
		}
		return self::$repo;
	}

	/** Meta keys the restore adds to a record (never part of a dataset, never backed up). */
	public static function own_meta_keys(): array {
		return array( self::BATCH_META, self::SRC_META, self::PREV_META );
	}

	/**
	 * Datasets this version can write, in no particular order: the free ones. Pro adds its own (phase 12)
	 * through the filter, once its identity rules and references are described.
	 *
	 * @return string[]
	 */
	public static function writable_datasets(): array {
		$free = array( 'ftnc_customer', 'ftnc_service', 'ftnc_work', 'ftnc_memory_card', 'ftnc_backup_loc' );
		$all  = (array) apply_filters( 'ftnc_restore_writable_datasets', $free );
		return array_values( array_filter( $all, 'is_string' ) );
	}

	// ---------------------------------------------------------------------------
	// One record
	// ---------------------------------------------------------------------------

	/**
	 * @param string $dataset_key Dataset (post type) key of the registry.
	 * @param array  $record      The record as the archive holds it (ID, post_status, post_title, meta, terms...).
	 * @param array  $opts        action ('import'|'copy'|'overwrite'), site_id (overwrite), scenario ('B'|'C'),
	 *                            old_mk, new_mk (B only), batch (job id), idmap (Fotonic_Restore_Idmap), user_id,
	 *                            existing_id (a record this job created before it stopped: written again in place).
	 * @return array|\WP_Error array( id, unreadable, unmapped, attachments, skipped ) or the error that stopped this record.
	 */
	public static function write_record( string $dataset_key, array $record, array $opts ) {
		$registry = Fotonic_Backup_Registry::datasets();
		/**
		 * About to write one record (before anything is). Pro uses it to bring back the terms the record points to.
		 *
		 * @param string $dataset_key Dataset key.
		 * @param array  $opts        The options of write_record() (sid, idmap, batch, user_id...).
		 */
		do_action( 'ftnc_restore_before_record', $dataset_key, $opts );
		if ( ! isset( $registry[ $dataset_key ] ) ) {
			return new \WP_Error( 'ftnc_restore_unknown_dataset', __( 'This backup has data this site cannot restore.', 'eleva-crm-for-photographers' ), array( 'status' => 422 ) );
		}
		$dataset = $registry[ $dataset_key ];
		$action  = (string) ( $opts['action'] ?? 'import' );
		$repo    = self::repo();
		$id      = 0;
		if ( 'overwrite' === $action ) {
			$id   = (int) ( $opts['site_id'] ?? 0 );
			$have = $repo->get_post_fields( $id );
			if ( null === $have || $have['post_type'] !== $dataset['post_type'] ) {
				return new \WP_Error( 'ftnc_restore_site_record_gone', __( 'A record of this site changed or was removed since the preview. Run the preview again.', 'eleva-crm-for-photographers' ), array( 'status' => 409 ) );
			}
		}

		$built = self::build( $dataset_key, $dataset, $record, $opts );
		if ( is_wp_error( $built ) ) {
			return $built;
		}

		$batch = (string) $opts['batch'];
		if ( 'overwrite' === $action ) {
			// The journal first (it names the job, so a resume never journals twice), then the label, then the values:
			// a stop between two of these steps leaves the record as it was, or tagged and journaled, never half changed
			// without a way back.
			if ( ! self::journal_is_ours( $id, $batch ) ) {
				self::save_previous( $id, $built, $batch );
			}
			$repo->set_meta( $id, self::BATCH_META, $batch );
			if ( ! $repo->update_post_fields( $id, $built['post'] ) ) {
				return new \WP_Error( 'ftnc_restore_write_failed', __( 'A record could not be written.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
			}
		} else {
			$resume = (int) ( $opts['existing_id'] ?? 0 );
			if ( $resume > 0 ) {
				// A record this job created before it stopped: write it again in place.
				$have = $repo->get_post_fields( $resume );
				if ( null === $have || $have['post_type'] !== $dataset['post_type'] ) {
					return new \WP_Error( 'ftnc_restore_site_record_gone', __( 'A record of this site changed or was removed since the preview. Run the preview again.', 'eleva-crm-for-photographers' ), array( 'status' => 409 ) );
				}
				$id = $resume;
				$repo->update_post_fields( $id, $built['post'] );
			} else {
				$fields = array_merge( $built['post'], array( 'post_type' => $dataset['post_type'], 'post_date_gmt' => (string) ( $record['post_date_gmt'] ?? '' ) ) );
				$id     = $repo->insert_post( $fields );
				if ( $id <= 0 ) {
					return new \WP_Error( 'ftnc_restore_write_failed', __( 'A record could not be written.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
				}
				// The labels right after the insert: a stop later still leaves a record undo and resume can find.
				$repo->set_meta( $id, self::BATCH_META, $batch );
				$repo->set_meta( $id, self::SRC_META, $dataset_key . ':' . (int) ( $record['ID'] ?? 0 ) );
				$repo->update_post_fields( $id, array( 'post_name' => Fotonic_Vault_Fields::neutral_slug( $dataset['post_type'], $id ) ) );
			}
		}

		foreach ( $built['meta'] as $key => $value ) {
			if ( null === $value ) {
				if ( null !== $repo->get_meta( $id, $key ) ) {
					$repo->delete_meta( $id, $key );
				}
			} else {
				$repo->set_meta( $id, $key, $value );
			}
		}
		foreach ( $built['terms'] as $taxonomy => $slugs ) {
			$repo->set_terms( $id, $taxonomy, $slugs );
		}

		// The uid: a copy is a different record (new uid); otherwise the backup's own uid goes with the record.
		$backup_uid = isset( $record['meta']['_ftnc_uid'] ) && is_string( $record['meta']['_ftnc_uid'] ) ? strtolower( trim( $record['meta']['_ftnc_uid'] ) ) : '';
		$current    = (string) $repo->get_meta( $id, '_ftnc_uid' );
		if ( 'copy' === $action ) {
			if ( ! ( (int) ( $opts['existing_id'] ?? 0 ) > 0 && Fotonic_Record_Uid::is_valid( $current ) && $current !== $backup_uid ) ) {
				$repo->set_meta( $id, '_ftnc_uid', Fotonic_Record_Uid::generate() );
			}
		} elseif ( Fotonic_Record_Uid::is_valid( $backup_uid ) ) {
			$repo->set_meta( $id, '_ftnc_uid', $backup_uid );
		} elseif ( 'import' === $action && ! Fotonic_Record_Uid::is_valid( $current ) ) {
			$repo->set_meta( $id, '_ftnc_uid', Fotonic_Record_Uid::generate() );
		}
		$repo->finish( $dataset['post_type'], $id );

		return array(
			'id'          => $id,
			'unreadable'  => $built['unreadable'],
			'unmapped'    => $built['unmapped'],
			'attachments' => $built['attachments'],
			'skipped'     => $built['skipped'],
		);
	}

	// ---------------------------------------------------------------------------
	// What to write
	// ---------------------------------------------------------------------------

	/**
	 * The values of one record as they will be stored on this site.
	 *
	 * @return array|\WP_Error array( post, meta (key => stored text or null = no value), terms, unreadable, unmapped, attachments )
	 */
	private static function build( string $dataset_key, array $dataset, array $record, array $opts ) {
		$rules    = Fotonic_Restore_Matcher::rules( $dataset_key, $dataset );
		$specs    = Fotonic_Restore_Matcher::field_specs( (string) $dataset['post_type'] );
		$old_mk   = isset( $opts['old_mk'] ) ? (string) $opts['old_mk'] : null;
		$scenario = (string) ( $opts['scenario'] ?? 'B' );
		$new_mk   = 'B' === $scenario && isset( $opts['new_mk'] ) ? (string) $opts['new_mk'] : null;
		$idmap    = $opts['idmap'];
		$ctx      = array( 'old' => $old_mk, 'new' => $new_mk, 'scenario' => $scenario, 'unreadable' => 0, 'unmapped' => 0, 'attachments' => 0, 'error' => null );
		$skipped  = array();

		$status = isset( $record['post_status'] ) ? (string) $record['post_status'] : 'publish';
		if ( ! in_array( $status, Fotonic_Backup_Archive::POST_STATUSES, true ) ) {
			$status = 'publish';
		}
		$post = array( 'post_status' => $status );

		foreach ( array( 'title' => 'post_title', 'content' => 'post_content' ) as $support => $column ) {
			if ( ! in_array( $support, (array) $dataset['supports'], true ) ) {
				continue;
			}
			$stored = isset( $record[ $column ] ) ? (string) $record[ $column ] : '';
			if ( isset( $specs['post'][ $column ] ) ) {
				$stored = self::convert( $stored, $specs['post'][ $column ], $ctx );
			}
			$post[ $column ] = $stored;
		}

		$meta   = array();
		$ignore = array_merge( array( '_ftnc_uid', '_ftnc_gcal_event_id' ), self::own_meta_keys() );
		// Fields an add-on does not want written right now (Pro without a valid license leaves its own fields of a work out).
		$ignore = array_merge( $ignore, array_map( 'strval', (array) apply_filters( 'ftnc_restore_ignored_meta', array(), $dataset_key ) ) );
		$source = isset( $record['meta'] ) && is_array( $record['meta'] ) ? $record['meta'] : array();
		foreach ( (array) $dataset['meta_keys'] as $key ) {
			if ( in_array( $key, $ignore, true ) ) {
				continue;
			}
			$raw = $source[ $key ] ?? '';
			$raw = is_scalar( $raw ) ? (string) $raw : ( is_array( $raw ) ? wp_json_encode( $raw ) : '' );

			if ( '_ftnc_owner_id' === $key ) {
				$meta[ $key ] = self::owner_id( $source, $idmap, $opts, $ctx );
				continue;
			}
			$handler = isset( $rules['handlers'][ $key ]['write'] ) ? $rules['handlers'][ $key ]['write'] : null;
			if ( is_callable( $handler ) ) {
				// array( value (stored text or null = no value), unmapped, attachments ): a field the generic engine cannot write.
				$done = (array) call_user_func( $handler, $raw, array( 'idmap' => $idmap, 'user_id' => (int) ( $opts['user_id'] ?? 0 ), 'sid' => (string) ( $opts['sid'] ?? '' ), 'scenario' => $scenario, 'meta' => $source ) );
				$meta[ $key ]       = isset( $done['value'] ) && is_string( $done['value'] ) && '' !== $done['value'] ? $done['value'] : null;
				$ctx['unmapped']    += (int) ( $done['unmapped'] ?? 0 );
				$ctx['attachments'] += (int) ( $done['attachments'] ?? 0 );
				continue;
			}
			$ref = isset( $rules['refs'][ $key ] ) ? $rules['refs'][ $key ] : null;
			// A field that holds ids of other records (or of another system) and that no identity rule translates is NOT
			// written: the raw backup id could point to an unrelated record here. It is reported by name (Pro, phase 12).
			if ( null === $ref && ! empty( $dataset['ref_fields'][ $key ] ) && ! self::is_attachment_rows( $dataset, $key ) ) {
				$skipped[] = $key;
				continue;
			}
			if ( '' === $raw ) {
				$meta[ $key ] = null;
				continue;
			}
			$json = '[' === $raw[0] || '{' === $raw[0];
			if ( isset( $specs['rows'][ $key ] ) || $json ) {
				$meta[ $key ] = self::rows( $key, $raw, $dataset, $specs['rows'][ $key ] ?? array(), $ref, $idmap, $ctx );
				continue;
			}
			if ( isset( $specs['meta'][ $key ] ) ) {
				$value = self::convert( $raw, $specs['meta'][ $key ], $ctx );
				$meta[ $key ] = '' === $value ? null : $value;
				continue;
			}
			if ( null !== $ref && '' === $ref[1] ) {
				$new = $idmap->get( (string) $ref[0], (int) $raw );
				if ( null === $new && (int) $raw > 0 ) {
					$ctx['unmapped']++;
				}
				$meta[ $key ] = null === $new ? null : (string) $new;
				continue;
			}
			$meta[ $key ] = $raw;
		}
		if ( null !== $ctx['error'] ) {
			return $ctx['error'];
		}

		$terms = array();
		foreach ( (array) $dataset['taxonomies'] as $taxonomy => $tax_opts ) {
			if ( ! empty( $tax_opts['derived'] ) ) {
				continue; // Recomputed by the plugin (finish()).
			}
			$slugs = array();
			foreach ( (array) ( $record['terms'][ $taxonomy ] ?? array() ) as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'] ) ) {
					$slugs[] = (string) $entry['slug'];
				}
			}
			$terms[ $taxonomy ] = $slugs;
		}

		return array(
			'post'        => $post,
			'meta'        => $meta,
			'terms'       => $terms,
			'unreadable'  => $ctx['unreadable'],
			'unmapped'    => $ctx['unmapped'],
			'attachments' => $ctx['attachments'],
			'skipped'     => $skipped,
		);
	}

	/** Whether a meta is a list of rows of attachments (files), which phase 10 handles. */
	private static function is_attachment_rows( array $dataset, string $key ): bool {
		foreach ( (array) ( $dataset['ref_fields'][ $key ] ?? array() ) as $kind ) {
			if ( is_string( $kind ) && 0 === strpos( $kind, 'json:[]' ) && '|attachment' === substr( $kind, -11 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * One protected value: opened with the old Master Key, stored for this site (encrypted with its key, or as it is
	 * on a site with no Vault). A value that cannot be opened stays out ('' and counted).
	 */
	public static function convert( string $stored, string $format, array &$ctx ): string {
		if ( '' === $stored ) {
			return '';
		}
		$plain = Fotonic_Restore_Matcher::read_value( $stored, $format, $ctx['old'] );
		if ( null === $plain ) {
			$ctx['unreadable']++;
			return '';
		}
		if ( '' === $plain ) {
			return '';
		}
		if ( null !== $ctx['new'] ) {
			$out = Fotonic_Vault_Fields::encrypt_value( $plain, $format, $ctx['new'] );
		} elseif ( 'f1' === $format ) {
			$out = Fotonic_Field_Crypt::store( $plain, null );
		} else {
			$out = $plain; // v1 and v1d on a site with no Vault are plain text.
		}
		if ( false === $out ) {
			$ctx['error'] = new \WP_Error( 'ftnc_restore_encrypt_failed', __( 'A value could not be encrypted. Nothing else was written.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
			return '';
		}
		return (string) $out;
	}

	/** The owner of a work: the person doing the restore (admin works), or the mapped collaborator (Pro). */
	private static function owner_id( array $source, Fotonic_Restore_Idmap $idmap, array $opts, array &$ctx ): ?string {
		$type = isset( $source['_ftnc_owner_type'] ) ? (string) $source['_ftnc_owner_type'] : 'admin';
		if ( 'collaborator' === $type ) {
			$new = $idmap->get( 'ftnc_collaborator', (int) ( $source['_ftnc_owner_id'] ?? 0 ) );
			if ( null === $new ) {
				$ctx['unmapped']++;
				return null;
			}
			return (string) $new;
		}
		$user = (int) ( $opts['user_id'] ?? 0 );
		return $user > 0 ? (string) $user : null;
	}

	/** A JSON list of rows (people, addresses, services of a work, installments, files...). */
	private static function rows( string $key, string $raw, array $dataset, array $cols, $ref, Fotonic_Restore_Idmap $idmap, array &$ctx ): ?string {
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return $raw;
		}
		$attachment_rows = self::is_attachment_rows( $dataset, $key );
		$out = array();
		foreach ( $decoded as $row ) {
			if ( $attachment_rows ) {
				// A bare number is the old list of attachment ids; a row without a type is an attachment too.
				$is_link = is_array( $row ) && 'link' === ( $row['type'] ?? 'attachment' );
				if ( ! $is_link ) {
					$old_id = is_array( $row ) ? (int) ( $row['id'] ?? 0 ) : (int) $row;
					$new_id = $idmap->get( 'attachment', $old_id );
					if ( null === $new_id ) {
						$ctx['attachments']++; // The file did not come back: the row is left out and counted.
						continue;
					}
					if ( ! is_array( $row ) ) {
						$out[] = $new_id;
						continue;
					}
					// The row now points at the new file; its protected columns (the label) go through the
					// conversion below like any other row's.
					$row = array_merge( $row, array( 'id' => $new_id ) );
				}
			}
			if ( ! is_array( $row ) ) {
				$out[] = $row;
				continue;
			}
			foreach ( $cols as $column => $format ) {
				if ( ! isset( $row[ $column ] ) || ! is_string( $row[ $column ] ) || '' === $row[ $column ] ) {
					continue;
				}
				$value = self::convert( $row[ $column ], (string) $format, $ctx );
				if ( '' === $value ) {
					unset( $row[ $column ] );
				} else {
					$row[ $column ] = $value;
				}
			}
			if ( is_array( $ref ) && '' !== $ref[1] && isset( $row[ $ref[1] ] ) ) {
				$new = $idmap->get( (string) $ref[0], (int) $row[ $ref[1] ] );
				if ( null === $new && (int) $row[ $ref[1] ] > 0 ) {
					$ctx['unmapped']++;
				}
				$row[ $ref[1] ] = null === $new ? 0 : $new;
			}
			$out[] = $row;
		}
		return empty( $out ) ? null : (string) wp_json_encode( $out );
	}

	// ---------------------------------------------------------------------------
	// Undo journal
	// ---------------------------------------------------------------------------

	/** Save what an overwritten record holds now, as stored, so undo can give it back. */
	private static function save_previous( int $id, array $built, string $batch ): void {
		$repo = self::repo();
		$have = $repo->get_post_fields( $id );
		$keys = array_unique( array_merge( array_keys( $built['meta'] ), array( '_ftnc_uid', self::BATCH_META ) ) );
		$meta = array();
		foreach ( $keys as $key ) {
			$meta[ $key ] = $repo->get_meta( $id, $key );
		}
		$terms = array();
		foreach ( array_keys( $built['terms'] ) as $taxonomy ) {
			$terms[ $taxonomy ] = $repo->get_terms( $id, $taxonomy );
		}
		$post = array();
		foreach ( array( 'post_title', 'post_content', 'post_status' ) as $column ) {
			if ( isset( $have[ $column ] ) ) {
				$post[ $column ] = $have[ $column ];
			}
		}
		$journal = array( 'b' => $batch, 'p' => $post, 'm' => $meta, 't' => $terms );
		$older   = $repo->get_meta( $id, self::PREV_META );
		if ( null !== $older ) {
			$journal['o'] = $older; // The record was already overwritten by an earlier restore: keep that journal too.
		}
		$repo->set_meta( $id, self::PREV_META, (string) wp_json_encode( $journal ) );
	}

	/** Whether the journal of this record was written by this job (a resume must not journal a second time). */
	private static function journal_is_ours( int $id, string $batch ): bool {
		$raw  = self::repo()->get_meta( $id, self::PREV_META );
		$data = null === $raw ? null : json_decode( $raw, true );
		return is_array( $data ) && isset( $data['b'] ) && $data['b'] === $batch;
	}

	/**
	 * Undo what the restore did to one record: a created record is deleted, an overwritten one gets its old
	 * values back. Needs no key, no Vault and no restore session (the values are kept as they were stored).
	 *
	 * @return string 'deleted', 'restored' or 'skipped' (not a plugin record).
	 */
	public static function undo_record( int $id ): string {
		$repo = self::repo();
		$have = $repo->get_post_fields( $id );
		if ( null !== $have && 'attachment' === $have['post_type'] ) {
			// A file this restore made (its labels say so): deleted with its file. Never any other attachment.
			$src = (string) $repo->get_meta( $id, self::SRC_META );
			if ( '' === (string) $repo->get_meta( $id, self::BATCH_META ) || 0 !== strpos( $src, Fotonic_Restore_Files::SRC_PREFIX ) ) {
				return 'skipped';
			}
			$repo->delete_attachment( $id );
			return 'deleted';
		}
		if ( null === $have || ! in_array( $have['post_type'], self::post_types(), true ) ) {
			return 'skipped';
		}
		$journal = $repo->get_meta( $id, self::PREV_META );
		if ( null === $journal ) {
			$repo->delete_post( $id );
			return 'deleted';
		}
		$data = json_decode( $journal, true );
		if ( ! is_array( $data ) ) {
			return 'skipped';
		}
		$repo->update_post_fields( $id, (array) ( $data['p'] ?? array() ) );
		foreach ( (array) ( $data['m'] ?? array() ) as $key => $value ) {
			if ( null === $value ) {
				if ( null !== $repo->get_meta( $id, (string) $key ) ) {
					$repo->delete_meta( $id, (string) $key );
				}
			} else {
				$repo->set_meta( $id, (string) $key, (string) $value );
			}
		}
		foreach ( (array) ( $data['t'] ?? array() ) as $taxonomy => $slugs ) {
			$repo->set_terms( $id, (string) $taxonomy, (array) $slugs );
		}
		if ( isset( $data['o'] ) && is_string( $data['o'] ) ) {
			$repo->set_meta( $id, self::PREV_META, $data['o'] );
		} else {
			$repo->delete_meta( $id, self::PREV_META );
		}
		$repo->finish( $have['post_type'], $id );
		return 'restored';
	}

	/** Post types of the datasets this version writes. @return string[] */
	public static function post_types(): array {
		$registry = Fotonic_Backup_Registry::datasets();
		$types    = array();
		foreach ( self::writable_datasets() as $key ) {
			if ( isset( $registry[ $key ]['post_type'] ) ) {
				$types[] = (string) $registry[ $key ]['post_type'];
			}
		}
		return $types;
	}

	// ---------------------------------------------------------------------------
	// Options
	// ---------------------------------------------------------------------------

	/**
	 * The payment types of the backup added to those of this site, by slug. A slug the site already has keeps the
	 * site's own entry; a new one gets the next free id. Returns the JSON to store, or null when nothing is added.
	 */
	public static function merge_payment_types( $site_raw, $backup_raw ): ?string {
		$site   = is_string( $site_raw ) ? json_decode( $site_raw, true ) : null;
		$backup = is_string( $backup_raw ) ? json_decode( $backup_raw, true ) : null;
		if ( ! is_array( $backup ) ) {
			return null;
		}
		$site   = is_array( $site ) ? array_values( $site ) : array();
		$slugs  = array();
		$max_id = 0;
		foreach ( $site as $entry ) {
			if ( is_array( $entry ) ) {
				$slugs[ (string) ( $entry['slug'] ?? '' ) ] = true;
				$max_id = max( $max_id, (int) ( $entry['id'] ?? 0 ) );
			}
		}
		$added = 0;
		foreach ( $backup as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['slug'] ) || ! is_string( $entry['slug'] ) || '' === $entry['slug'] || isset( $slugs[ $entry['slug'] ] ) ) {
				continue;
			}
			$entry['id']              = ++$max_id;
			$site[]                   = $entry;
			$slugs[ $entry['slug'] ] = true;
			$added++;
		}
		return 0 === $added ? null : (string) wp_json_encode( $site );
	}
}
