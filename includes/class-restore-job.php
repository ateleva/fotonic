<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Restore, step 4: the job that writes the backup into this site, batch by batch, and undoes it.
 *
 * Flow: the preview (Fotonic_Restore_Preview) leaves a plan, `create()` turns the plan plus the person's choices
 * into a job, `run()` is called until the status is `done`. The scenario is chosen HERE, never by the client:
 *   B = this site has a Vault: it must be unlocked, personal fields are encrypted again with its key;
 *   C = this site has no Vault: personal fields are stored as plain text (a site with data and no Vault is refused:
 *       turn the Vault on first, so there is a safety backup and nothing sensitive lands in the clear).
 * Scenario A (adopt the Vault of the backup) is phase 10.
 *
 * Decisions (`$decisions[dataset key][backup id] = { action, site_id? }`), checked by create():
 *   new        -> import (default), copy, skip
 *   identical  -> keep_site (default), copy, skip
 *   different  -> keep_site (default: the site keeps what it has), use_backup (site_id = the matched record), copy, skip
 *   possible   -> NO default: keep_site / use_backup (site_id must be one of the candidates), copy, skip, and import when
 *                 it has no candidate at all (it is only possible because the record it points to is)
 *   unimportable -> skipped always.
 * Several backup records may be kept as the same site record (a duplicate inside the backup), but a site record that is
 * overwritten (`use_backup`) can be the target of one choice only. `copy` writes a new record with a new uid.
 *
 * Guarantees:
 *   - the preview is a photograph: create() refuses (409) when the site changed since (fingerprint), and every
 *     overwrite checks that its target still exists;
 *   - from create() until the end (or undo) the site refuses writes of protected data (423 `encrypt_in_progress`,
 *     see Fotonic_Vault_Encrypt_Existing::is_blocking()), and no restore starts while the encrypt-existing job runs;
 *   - scenario B with data on the site: a safety backup (Fotonic_Backup_Archive::create()) is made first; if it fails
 *     the job stops with zero writes;
 *   - undo needs no key and no session: created records carry `_ftnc_import_batch`, overwritten ones a journal of
 *     their old values (Fotonic_Restore_Writer);
 *   - a job that stopped (tab closed, timeout) resumes from its cursor; a record created just before the stop is
 *     found again by its `_ftnc_import_src` and not created twice;
 *   - the old Master Key is never stored here: every call asks the restore session (its cookie) for it. Without the
 *     cookie or after 2 hours the job is `expired` (undo still works).
 * Options: only `fotonic_payment_types`, merged by slug (the site wins); never the Vault options, never what
 * `Fotonic_Backup_Registry::denied_options()` lists; the others are reported for phase 12.
 */
final class Fotonic_Restore_Job {

	const OPTION      = 'fotonic_restore_job';
	const BATCH       = 25;
	const TIME_BUDGET = 10;

	/** @var callable|null Replaces the safety backup (tests). */
	private static $safety = null;

	public static function use_safety_backup( ?callable $callback ): void {
		self::$safety = $callback;
	}

	// ---------------------------------------------------------------------------
	// State
	// ---------------------------------------------------------------------------

	public static function job(): ?array {
		$job = get_option( self::OPTION, null );
		return is_array( $job ) && isset( $job['id'], $job['status'] ) ? $job : null;
	}

	/**
	 * Writes of protected data are refused while a restore runs, is paused or is being undone. A job nobody has touched
	 * for the length of a restore session (a closed tab, a forgotten pause) or whose session is gone does not block
	 * for ever: it becomes `expired` (undo still works).
	 */
	public static function is_blocking(): bool {
		$job = self::job();
		if ( null === $job || ! in_array( $job['status'], array( 'running', 'paused', 'undoing' ), true ) ) {
			return false;
		}
		$stale = ( time() - (int) $job['updated'] ) > Fotonic_Restore_Reader::TTL;
		if ( ! $stale && 'undoing' !== $job['status'] ) {
			// The session of ANY person: the check runs on every protected save, and a save by another admin must not end a restore.
			$stale = ! Fotonic_Restore_Reader::session_alive( (string) $job['sid'] );
		}
		if ( $stale ) {
			$job['status'] = 'expired';
			$job['error']  = __( 'The restore session ended before the restore was finished. Undo it, or open the backup again.', 'eleva-crm-for-photographers' );
			self::save( $job );
			return false;
		}
		return true;
	}

	/**
	 * True when a restore that is not finished still needs this session (running, paused, being undone, or stopped by an
	 * error and waiting to be resumed): nothing else may close it or rewrite its plan.
	 */
	public static function uses_session( string $session_id ): bool {
		$job = self::job();
		if ( null === $job || (string) ( $job['sid'] ?? '' ) !== $session_id ) {
			return false;
		}
		if ( 'error' === $job['status'] ) {
			return true;
		}
		return in_array( $job['status'], array( 'running', 'paused', 'undoing' ), true ) && self::is_blocking();
	}

	/**
	 * The session cannot be reached by this request. If it is still alive, it belongs to another person or browser: say so
	 * and leave the job alone. Only a session that is really gone ends the job.
	 */
	private static function lost( array $job ) {
		if ( Fotonic_Restore_Reader::session_alive( (string) $job['sid'] ) ) {
			return self::error( 'ftnc_restore_other_browser', __( 'This restore is running in another browser or for another person. It goes on there.', 'eleva-crm-for-photographers' ), 409 );
		}
		return self::expire( $job );
	}

	private static function save( array $job ): void {
		$job['updated'] = time();
		update_option( self::OPTION, $job, false );
	}

	/** The job as the screen needs it (no decisions, no keys). */
	public static function status( ?string $job_id = null ): ?array {
		$job = self::job();
		if ( null === $job || ( null !== $job_id && $job['id'] !== $job_id ) ) {
			return null;
		}
		$total   = max( 1, (int) $job['total'] );
		$percent = in_array( $job['status'], array( 'done', 'undone' ), true ) ? 100 : (int) min( 99, floor( 100 * (int) $job['done'] / $total ) );
		$elapsed = max( 1, time() - (int) $job['created'] );
		$eta     = ( 'running' === $job['status'] && (int) $job['done'] > 0 ) ? (int) ceil( ( $total - (int) $job['done'] ) * $elapsed / (int) $job['done'] ) : null;
		return array(
			'job_id'      => $job['id'],
			'status'      => $job['status'],
			'scenario'    => $job['scenario'],
			'mode'        => $job['mode'] ?? 'merge',
			'undoable'    => 'total' !== ( $job['mode'] ?? 'merge' ) && in_array( $job['status'], array( 'running', 'paused', 'done', 'error', 'expired', 'undoing' ), true ),
			'step'        => $job['step'],
			'done'        => (int) $job['done'],
			'total'       => (int) $job['total'],
			'percent'     => $percent,
			'eta_seconds' => $eta,
			'counts'      => $job['counts'],
			'report'      => $job['report'],
			'safety'      => '' !== $job['safety'],
			'safety_file' => (string) $job['safety'],
			'deleted'     => (int) ( $job['wipe']['deleted'] ?? 0 ),
			'error'       => $job['error'],
		);
	}

	/** Fingerprint of the records the restore reads and writes. */
	public static function fingerprint(): string {
		return Fotonic_Restore_Writer::repo()->fingerprint( Fotonic_Restore_Writer::post_types() );
	}

	/** Save the progress of a batch without undoing a pause that came in while it ran. */
	private static function save_progress( array $job ): void {
		$stored = self::job();
		if ( null !== $stored && 'running' !== $stored['status'] && $stored['id'] === $job['id'] ) {
			$job['status'] = $stored['status'];
		}
		self::save( $job );
	}

	private static function key_check( string $mk ): string {
		return substr( hash_hmac( 'sha256', 'ftnc-restore-check', $mk ), 0, 32 );
	}

	private static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	// ---------------------------------------------------------------------------
	// Create
	// ---------------------------------------------------------------------------

	/**
	 * Check the plan and the choices and start the job (the first run() call does the writing).
	 *
	 * @param string $session_id Restore session whose preview is complete.
	 * @param array  $decisions  dataset key => ( backup id => { action, site_id? } ).
	 * @param array  $options    plain (bool: restore into a site with no Vault, data stays plain text),
	 *                           total (bool) + confirm (string): replace everything, see Fotonic_Restore_Wipe.
	 * @return array|\WP_Error   status()
	 */
	public static function create( string $session_id, array $decisions, array $options = array() ) {
		$collections = Fotonic_Restore_Reader::collections( $session_id );
		if ( is_wp_error( $collections ) ) {
			return $collections;
		}
		$old_mk = Fotonic_Restore_Reader::old_master_key( $session_id );
		if ( is_wp_error( $old_mk ) ) {
			return $old_mk;
		}
		$dir = Fotonic_Restore_Reader::work_dir( $session_id );
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$enc = Fotonic_Vault_Encrypt_Existing::job();
		if ( null !== $enc && in_array( $enc['status'], array( 'running', 'paused' ), true ) ) {
			return self::error( 'encrypt_in_progress', __( 'Your data is being encrypted. Wait until it has finished, then restore.', 'eleva-crm-for-photographers' ), 423 );
		}
		$existing = self::job();
		if ( null !== $existing && ! in_array( $existing['status'], array( 'done', 'undone', 'dismissed' ), true ) ) {
			self::is_blocking(); // A stale job becomes `expired` here; either way it must be finished or dismissed first.
			return self::error( 'ftnc_restore_unfinished', __( 'Another restore is not finished. Finish it, undo it or dismiss it first.', 'eleva-crm-for-photographers' ), 409 );
		}

		$repo     = Fotonic_Restore_Writer::repo();
		$has_data = $repo->count_records( Fotonic_Restore_Writer::post_types() ) > 0;
		$scenario = 'C';
		$site_mk  = null;
		if ( Fotonic_Vault::is_setup() ) {
			$scenario = 'B';
			$site_mk  = Fotonic_Vault::get_session_key();
			if ( null === $site_mk ) {
				return self::error( 'vault_locked', __( 'Unlock the Vault of this site first: the restored data is encrypted with its key.', 'eleva-crm-for-photographers' ), 423 );
			}
		} elseif ( $has_data ) {
			return self::error( 'ftnc_restore_need_vault', __( 'This site has data but no Vault. Turn the Vault on first, so a safety backup can be made and personal details stay protected, then restore.', 'eleva-crm-for-photographers' ), 409 );
		} elseif ( empty( $options['plain'] ) ) {
			return self::error( 'ftnc_restore_choose_vault', __( 'This site has no Vault. Turn the Vault on first (recommended: the restored data is then encrypted with your own key), or confirm that you want to restore without it and keep the data readable in plain text.', 'eleva-crm-for-photographers' ), 409 );
		}

		$is_total = ! empty( $options['total'] );
		if ( $is_total ) {
			if ( ! Fotonic_Restore_Wipe::confirmed( $options['confirm'] ?? '' ) ) {
				return self::error( 'ftnc_restore_confirm_needed', __( 'Type the confirmation word exactly to replace everything.', 'eleva-crm-for-photographers' ), 422 );
			}
			$blocking = Fotonic_Restore_Wipe::blocking();
			if ( ! empty( $blocking ) ) {
				return self::error( 'ftnc_restore_total_blocked', __( 'This site has data of Eleva CRM Pro that a full restore cannot replace yet, and removing only part of the data would leave it pointing at nothing. Restore without replacing, or remove that data first.', 'eleva-crm-for-photographers' ), 409 );
			}
		}

		// The datasets to write, in dependency order, and the plan the preview left.
		$registry = Fotonic_Backup_Registry::datasets();
		$counts   = array();
		foreach ( $collections as $c ) {
			if ( 'datasets' === $c['kind'] && isset( $registry[ $c['key'] ] ) && in_array( $c['key'], Fotonic_Restore_Writer::writable_datasets(), true ) ) {
				$counts[ $c['key'] ] = (int) $c['count'];
			}
		}
		$order = Fotonic_Restore_Preview::order( array_keys( $counts ), $registry );
		$plan  = Fotonic_Restore_Preview::plan( $session_id );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		foreach ( $order as $key ) {
			if ( count( $plan[ $key ] ?? array() ) < $counts[ $key ] ) {
				return self::error( 'ftnc_restore_preview_incomplete', __( 'Run the preview to the end before restoring.', 'eleva-crm-for-photographers' ), 409 );
			}
		}
		$fp = is_file( $dir . '/plan.fp' ) ? trim( (string) file_get_contents( $dir . '/plan.fp' ) ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( '' === $fp || ! hash_equals( $fp, self::fingerprint() ) ) {
			return self::error( 'ftnc_restore_preview_stale', __( 'The data of this site changed since the preview. Run the preview again.', 'eleva-crm-for-photographers' ), 409 );
		}

		// A full restore writes every record of the backup after the wipe: the plan's statuses and choices mean nothing then.
		$clean = array();
		if ( ! $is_total ) {
			$clean = self::check_decisions( $order, $plan, $decisions );
			if ( is_wp_error( $clean ) ) {
				return $clean;
			}
		}

		$total = 0;
		foreach ( $order as $key ) {
			$total += $counts[ $key ];
		}
		$job = array(
			'id'          => bin2hex( random_bytes( 16 ) ),
			'sid'         => $session_id,
			'scenario'    => $scenario,
			'mode'        => $is_total ? 'total' : 'merge',
			'wipe'        => array( 'done' => ! $is_total, 'deleted' => 0 ),
			'status'      => 'running',
			'step'        => 'start',
			'order'       => $order,
			'cursor'      => array( 'd' => 0, 'o' => 0 ),
			'decisions'   => $clean,
			'total'       => $total,
			'done'        => 0,
			'counts'      => array( 'created' => 0, 'copied' => 0, 'overwritten' => 0, 'kept' => 0, 'skipped' => 0 ),
			'report'      => array( 'unreadable' => 0, 'unmapped' => 0, 'attachments' => 0, 'files' => array( 'created' => 0, 'reused' => 0, 'missing' => 0, 'rejected' => 0, 'damaged' => 0 ), 'gcal' => false, 'refs_skipped' => array(), 'options_skipped' => array(), 'options_applied' => array(), 'unknown_fields' => array(), 'unimportable' => array() ),
			'key_check'   => null === $site_mk ? '' : self::key_check( $site_mk ),
			'safety_need' => 'B' === $scenario && $has_data,
			'safety'      => '',
			'dirty'       => false,
			'options'     => array( 'done' => false, 'prev' => null, 'prev_other' => array() ),
			'created'     => time(),
			'updated'     => time(),
			'error'       => '',
		);
		foreach ( $collections as $c ) {
			if ( 'datasets' === $c['kind'] && (int) $c['count'] > 0 && ! in_array( $c['key'], $order, true ) ) {
				$job['report']['unimportable'][] = array( 'dataset' => (string) $c['key'], 'count' => (int) $c['count'] );
			}
		}
		if ( null !== $existing && 'done' === $existing['status'] ) {
			self::purge_journals( $existing['id'] ); // The earlier restore can no longer be undone: drop its journals (old personal values).
		}
		self::save( $job );
		return self::status( $job['id'] );
	}

	/** Ids of the files a site record holds now (rows of attachments), to reuse one with the same bytes. */
	private static function site_file_ids( $repo, int $site_id, array $dataset ): array {
		$ids = array();
		foreach ( Fotonic_Restore_Files::ids_in_record( $dataset, array( 'meta' => self::site_meta( $repo, $site_id, $dataset ) ) ) as $id ) {
			$ids[] = $id;
		}
		return $ids;
	}

	private static function site_meta( $repo, int $site_id, array $dataset ): array {
		$meta = array();
		foreach ( array_keys( (array) ( $dataset['ref_fields'] ?? array() ) ) as $key ) {
			$value = $repo->get_meta( $site_id, (string) $key );
			if ( null !== $value ) {
				$meta[ $key ] = $value;
			}
		}
		return $meta;
	}

	/** Remove the labels and undo journals a job left on the records (the records themselves stay). */
	private static function purge_journals( string $job_id ): void {
		$repo = Fotonic_Restore_Writer::repo();
		for ( $i = 0; $i < 1000; $i++ ) {
			$ids = $repo->ids_with_meta( Fotonic_Restore_Writer::BATCH_META, $job_id, 500 );
			if ( empty( $ids ) ) {
				return;
			}
			foreach ( $ids as $id ) {
				foreach ( Fotonic_Restore_Writer::own_meta_keys() as $meta_key ) {
					$repo->delete_meta( $id, $meta_key );
				}
			}
		}
	}

	/**
	 * Turn the choices into the exceptions the job keeps, and refuse what does not make sense.
	 *
	 * @return array|\WP_Error dataset key => ( backup id => array( action, site_id ) )
	 */
	private static function check_decisions( array $order, array $plan, array $decisions ) {
		$allowed = array(
			'new'       => array( 'import', 'copy', 'skip' ),
			'identical' => array( 'keep_site', 'copy', 'skip' ),
			'different' => array( 'keep_site', 'use_backup', 'copy', 'skip' ),
			'possible'  => array( 'keep_site', 'use_backup', 'copy', 'skip' ),
		);
		$claimed = array();
		$clean   = array();
		$missing = 0;
		foreach ( $order as $key ) {
			foreach ( $plan[ $key ] as $line ) {
				$status = $line['s'];
				if ( 'unimportable' === $status ) {
					continue;
				}
				$choice = $decisions[ $key ][ (string) $line['b'] ] ?? ( $decisions[ $key ][ $line['b'] ] ?? null );
				$action = is_array( $choice ) && isset( $choice['action'] ) ? (string) $choice['action'] : '';
				if ( '' === $action ) {
					if ( 'possible' === $status ) {
						$missing++;
						continue;
					}
					$action = 'new' === $status ? 'import' : 'keep_site';
				}
				// A possible record that matches no record of the site (it is only "possible" because the record it points to is)
				// may also be added as it is.
				$fits = in_array( $action, $allowed[ $status ] ?? array(), true ) || ( 'possible' === $status && 'import' === $action && empty( $line['c'] ) );
				if ( ! $fits ) {
					return self::error( 'ftnc_restore_bad_decision', __( 'One of the choices does not fit that record.', 'eleva-crm-for-photographers' ), 400 );
				}
				$site_id = 0;
				if ( in_array( $action, array( 'keep_site', 'use_backup' ), true ) ) {
					$site_id = 'possible' === $status ? (int) ( $choice['site_id'] ?? 0 ) : (int) $line['i'];
					$valid   = 'possible' === $status ? in_array( $site_id, $line['c'], true ) : $site_id > 0;
					if ( ! $valid ) {
						return self::error( 'ftnc_restore_bad_decision', __( 'One of the choices does not fit that record.', 'eleva-crm-for-photographers' ), 400 );
					}
					// Several backup records may be kept as the same site record (a duplicate inside the backup); a record that is
					// OVERWRITTEN must be the target of that one choice only.
					$claimed[ $key ][ $site_id ][] = $action;
					if ( count( $claimed[ $key ][ $site_id ] ) > 1 && in_array( 'use_backup', $claimed[ $key ][ $site_id ], true ) ) {
						return self::error( 'ftnc_restore_duplicate_target', __( 'Two records of the backup would end up on the same record of this site. Change one choice.', 'eleva-crm-for-photographers' ), 400 );
					}
				}
				$default = 'new' === $status ? 'import' : 'keep_site';
				if ( $action !== $default || 'possible' === $status ) {
					$clean[ $key ][ (int) $line['b'] ] = array( 'action' => $action, 'site_id' => $site_id );
				}
			}
		}
		if ( $missing > 0 ) {
			return new \WP_Error( 'ftnc_restore_decision_required', __( 'Some records may be duplicates. Choose what to do with each one.', 'eleva-crm-for-photographers' ), array( 'status' => 400, 'missing' => $missing ) );
		}
		return $clean;
	}

	// ---------------------------------------------------------------------------
	// Run
	// ---------------------------------------------------------------------------

	/**
	 * One step of the restore: the safety backup, then up to $size records, then the options and the end.
	 * Call again until `status` is `done`.
	 *
	 * @return array|\WP_Error status()
	 */
	public static function run( string $job_id, int $size = self::BATCH ) {
		$job = self::job();
		if ( null === $job || $job['id'] !== $job_id ) {
			return self::error( 'ftnc_restore_no_job', __( 'There is no restore running.', 'eleva-crm-for-photographers' ), 404 );
		}
		if ( 'running' !== $job['status'] ) {
			return 'paused' === $job['status'] ? self::error( 'ftnc_restore_paused', __( 'The restore is paused.', 'eleva-crm-for-photographers' ), 409 ) : self::status( $job_id );
		}
		$dir = Fotonic_Restore_Reader::work_dir( $job['sid'] );
		if ( is_wp_error( $dir ) ) {
			return self::lost( $job );
		}
		$lock = fopen( $dir . '/job.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			return self::error( 'ftnc_restore_busy', __( 'The restore is already running. Wait a moment.', 'eleva-crm-for-photographers' ), 409 );
		}
		try {
			$job = self::job(); // Fresh copy under the lock (a pause may have come in).
			if ( null === $job || 'running' !== $job['status'] ) {
				return null === $job ? self::error( 'ftnc_restore_no_job', __( 'There is no restore running.', 'eleva-crm-for-photographers' ), 404 ) : self::status( $job_id );
			}
			return self::run_locked( $job, $dir, max( 1, $size ) );
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/** The restore session ended (2 hours, closed, cookie lost): the job cannot go on; undo still works. */
	private static function expire( array $job ) {
		$job['status'] = 'expired';
		$job['error']  = __( 'The restore session ended before the restore was finished. Undo it, or open the backup again.', 'eleva-crm-for-photographers' );
		self::save( $job );
		return self::error( 'ftnc_restore_expired', $job['error'], 410 );
	}

	private static function fail( array $job, \WP_Error $error ) {
		$job['status'] = 'error';
		$job['error']  = $error->get_error_message();
		self::save( $job );
		return $error;
	}

	private static function run_locked( array $job, string $dir, int $size ) {
		$sid    = $job['sid'];
		$site   = null;
		if ( 'B' === $job['scenario'] ) {
			$site = Fotonic_Vault::get_session_key();
			if ( null === $site ) {
				return self::error( 'vault_locked', __( 'The Vault is locked. Unlock it and the restore goes on from where it stopped.', 'eleva-crm-for-photographers' ), 423 );
			}
			if ( ! hash_equals( (string) $job['key_check'], self::key_check( $site ) ) ) {
				return self::fail( $job, self::error( 'ftnc_restore_key_changed', __( 'The Vault of this site changed during the restore. Undo it and start again.', 'eleva-crm-for-photographers' ), 409 ) );
			}
		}
		$old = Fotonic_Restore_Reader::old_master_key( $sid );
		if ( is_wp_error( $old ) ) {
			return self::lost( $job );
		}

		// Step 0: the safety backup.
		if ( $job['safety_need'] && '' === $job['safety'] ) {
			$job['step'] = 'safety';
			self::save( $job );
			$made = is_callable( self::$safety ) ? call_user_func( self::$safety ) : Fotonic_Backup_Archive::create();
			if ( is_wp_error( $made ) ) {
				if ( 'ftnc_backup_already_running' === $made->get_error_code() ) {
					return self::error( 'ftnc_restore_busy', __( 'A backup is running. Wait a moment.', 'eleva-crm-for-photographers' ), 409 );
				}
				$msg = sprintf(
					/* translators: %s: the reason the safety backup failed. */
					__( 'The safety backup could not be made, so nothing was changed. %s', 'eleva-crm-for-photographers' ),
					$made->get_error_message()
				);
				return self::fail( $job, self::error( 'ftnc_restore_safety_failed', $msg, 500 ) );
			}
			$job['safety'] = basename( (string) ( is_array( $made ) ? ( $made['path'] ?? 'backup' ) : 'backup' ) );
			$job['step']   = 'records';
			self::save( $job );
			return self::status( $job['id'] );
		}

		// Step 1 of a full restore: the site's own records go (after the safety backup, before anything is written).
		if ( 'total' === ( $job['mode'] ?? 'merge' ) && empty( $job['wipe']['done'] ) ) {
			$job['step'] = 'wipe';
			$gone        = Fotonic_Restore_Wipe::step( $job['id'], 200 );
			if ( ! empty( $gone['error'] ) ) {
				return self::fail( $job, self::error( 'ftnc_restore_wipe_failed', __( 'Some records could not be removed, so nothing was written. Try again.', 'eleva-crm-for-photographers' ), 500 ) );
			}
			$job['wipe']['deleted'] = (int) ( $job['wipe']['deleted'] ?? 0 ) + $gone['deleted'];
			if ( 0 === $gone['remaining'] || 0 === $gone['deleted'] ) {
				$job['wipe']['done'] = true;
			}
			self::save( $job );
			return self::status( $job['id'] );
		}

		$job['step'] = 'records';
		$idmap_file  = $dir . '/idmap.jsonl';
		$idmap       = Fotonic_Restore_Idmap::load( $idmap_file );
		$plans       = Fotonic_Restore_Preview::plan( $sid );
		if ( is_wp_error( $plans ) ) {
			return self::lost( $job );
		}
		$resumed = (bool) $job['dirty'];
		$job['dirty'] = true;
		self::save( $job );

		$opts = array(
			'scenario' => $job['scenario'],
			'old_mk'   => $old,
			'new_mk'   => $site,
			'batch'    => $job['id'],
			'idmap'    => $idmap,
			'idmap_file' => $idmap_file,
			'sid'      => $sid,
			'total'    => 'total' === ( $job['mode'] ?? 'merge' ),
			'user_id'  => get_current_user_id(),
		);
		$started   = microtime( true );
		$processed = 0;
		$order     = $job['order'];
		while ( $processed < $size && $job['cursor']['d'] < count( $order ) && ( microtime( true ) - $started ) < self::TIME_BUDGET ) {
			$key   = $order[ $job['cursor']['d'] ];
			$lines = $plans[ $key ] ?? array();
			$at    = (int) $job['cursor']['o'];
			if ( $at >= count( $lines ) ) {
				$job['cursor'] = array( 'd' => $job['cursor']['d'] + 1, 'o' => 0 );
				continue;
			}
			$take    = min( $size - $processed, count( $lines ) - $at );
			$records = Fotonic_Restore_Reader::records( $sid, 'datasets:' . $key, $at, $take );
			if ( is_wp_error( $records ) ) {
				$job['dirty'] = false;
				return self::fail( $job, $records );
			}
			if ( empty( $records ) ) {
				return self::fail( $job, self::error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), 422 ) );
			}
			foreach ( $records as $i => $record ) {
				$line = $lines[ $at + $i ];
				if ( ! is_array( $record ) || (int) ( $record['ID'] ?? 0 ) !== $line['b'] ) {
					return self::fail( $job, self::error( 'ftnc_restore_plan_mismatch', __( 'The preview no longer matches the backup. Run the preview again.', 'eleva-crm-for-photographers' ), 409 ) );
				}
				$result = self::process( $job, $key, $line, $record, $opts, $resumed );
				$idmap->flush( $idmap_file );
				if ( is_wp_error( $result ) ) {
					$job['dirty'] = false;
					return self::fail( $job, $result );
				}
				$job['cursor']['o'] = $at + $i + 1;
				$job['done']++;
				$processed++;
				if ( ( microtime( true ) - $started ) >= self::TIME_BUDGET ) {
					break; // Large files take their time: the next call goes on from the cursor.
				}
			}
		}
		$job['dirty'] = false;

		if ( $job['cursor']['d'] >= count( $order ) ) {
			return self::finalize( $job, $sid );
		}
		self::save_progress( $job );
		return self::status( $job['id'] );
	}

	/** One record: decide, write, map. @return true|\WP_Error */
	private static function process( array &$job, string $key, array $line, array $record, array $opts, bool $resumed ) {
		$choice = $job['decisions'][ $key ][ $line['b'] ] ?? null;
		if ( 'unimportable' === $line['s'] ) {
			$action = 'skip';
		} elseif ( ! empty( $opts['total'] ) ) {
			$action = 'import'; // The site was emptied: everything in the backup is new.
			$choice = null;
		} elseif ( null !== $choice ) {
			$action = $choice['action'];
		} else {
			$action = 'new' === $line['s'] ? 'import' : 'keep_site';
		}
		$idmap = $opts['idmap'];
		$repo  = Fotonic_Restore_Writer::repo();
		$bid   = (int) $line['b'];

		if ( 'skip' === $action ) {
			$job['counts']['skipped']++;
			return true;
		}
		if ( 'keep_site' === $action ) {
			$site_id = null !== $choice ? (int) $choice['site_id'] : (int) $line['i'];
			$idmap->set( $key, $bid, $site_id );
			$job['counts']['kept']++;
			self::record_done( $job, $key, $bid, $site_id, 'keep_site', $record, $opts );
			return true;
		}

		$write = $opts;
		if ( 'use_backup' === $action ) {
			$write['action']  = 'overwrite';
			$write['site_id'] = (int) $choice['site_id'];
		} else {
			$write['action'] = $action; // import or copy.
			if ( $resumed ) {
				foreach ( $repo->ids_with_meta( Fotonic_Restore_Writer::SRC_META, $key . ':' . $bid ) as $found ) {
					if ( $repo->get_meta( $found, Fotonic_Restore_Writer::BATCH_META ) === $job['id'] ) {
						$write['existing_id'] = $found; // Created before the job stopped: written again in place, never twice.
						break;
					}
				}
			}
		}

		// The files the record points to come first: the writer then finds their new ids in the map.
		$registry = Fotonic_Backup_Registry::datasets();
		if ( isset( $registry[ $key ] ) ) {
			$wanted = Fotonic_Restore_Files::ids_in_record( $registry[ $key ], $record );
			if ( ! empty( $wanted ) ) {
				$ak = Fotonic_Restore_Reader::archive_key( $opts['sid'] );
				if ( is_wp_error( $ak ) ) {
					return $ak;
				}
				$candidates = array();
				if ( 'overwrite' === $write['action'] ) {
					$candidates = self::site_file_ids( $repo, (int) $write['site_id'], $registry[ $key ] );
				}
				$work_docs = Fotonic_Restore_Files::work_file_ids( $key, $record );
				foreach ( $wanted as $old_file ) {
					$got = Fotonic_Restore_Files::import( $opts['sid'], $old_file, array(
						'work_file'  => in_array( $old_file, $work_docs, true ),
						'ak'         => $ak,
						'old_mk'     => $opts['old_mk'],
						'new_mk'     => $opts['new_mk'],
						'batch'      => $job['id'],
						'idmap'      => $idmap,
						'candidates' => $candidates,
					) );
					if ( is_wp_error( $got ) ) {
						return $got;
					}
					$idmap->flush( $opts['idmap_file'] );
					if ( isset( $job['report']['files'][ $got['state'] ] ) ) {
						$job['report']['files'][ $got['state'] ]++;
					} elseif ( 'mapped' !== $got['state'] ) {
						$job['report']['files'][ $got['state'] ] = 1;
					}
				}
			}
		}
		foreach ( array_merge( array( '_ftnc_gcal_event_id' ), self::gcal_keys( $registry[ $key ] ?? array() ) ) as $gcal_key ) {
			if ( ! empty( $record['meta'][ $gcal_key ] ) ) {
				$job['report']['gcal'] = true; // The Google Calendar link is not restored: the events stay where they are.
				break;
			}
		}
		foreach ( self::unknown_meta( $registry[ $key ] ?? array(), $record ) as $name ) {
			if ( ! in_array( $name, $job['report']['unknown_fields'], true ) ) {
				$job['report']['unknown_fields'][] = $name; // Data this site has no place for (Pro is off): listed, not lost from the backup.
			}
		}

		$result = Fotonic_Restore_Writer::write_record( $key, $record, $write );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$idmap->set( $key, $bid, $result['id'] );
		$job['report']['unreadable']  += $result['unreadable'];
		$job['report']['unmapped']    += $result['unmapped'];
		$job['report']['attachments'] += $result['attachments'];
		foreach ( $result['skipped'] as $name ) {
			if ( ! in_array( $name, $job['report']['refs_skipped'], true ) ) {
				$job['report']['refs_skipped'][] = $name; // Fields of ids nobody translates yet (Pro, phase 12).
			}
		}
		if ( 'use_backup' === $action ) {
			$job['counts']['overwritten']++;
		} else {
			$job['counts']['copy' === $action ? 'copied' : 'created']++;
		}
		self::record_done( $job, $key, $bid, (int) $result['id'], $write['action'], $record, $opts );
		return true;
	}

	/**
	 * One record is settled (written, or kept as the site has it). The id map knows it now.
	 *
	 * @param array  $job     The job (id).
	 * @param string $key     Dataset key.
	 * @param int    $bid     Id in the backup.
	 * @param int    $site_id Id on this site.
	 * @param string $action  import, copy, overwrite or keep_site.
	 * @param array  $record  The record of the backup.
	 * @param array  $opts    Options of the run (idmap, sid, user_id).
	 */
	private static function record_done( array $job, string $key, int $bid, int $site_id, string $action, array $record, array $opts ): void {
		/**
		 * A record of the backup has its counterpart on this site. Pro links a work and its quote with it.
		 *
		 * @param string $key     Dataset key.
		 * @param int    $bid     Id in the backup.
		 * @param int    $site_id Id on this site.
		 * @param string $action  import, copy, overwrite or keep_site.
		 * @param array  $record  The record as the backup holds it.
		 * @param array  $ctx     idmap, job (id), sid, user_id.
		 */
		do_action( 'ftnc_restore_record_done', $key, $bid, $site_id, $action, $record, array( 'idmap' => $opts['idmap'], 'job' => (string) $job['id'], 'sid' => (string) $opts['sid'], 'user_id' => (int) $opts['user_id'] ) );
	}

	/** Meta keys of a dataset that hold a Google Calendar event id (kind `external:gcal_event`). @return string[] */
	private static function gcal_keys( array $dataset ): array {
		$out = array();
		foreach ( (array) ( $dataset['ref_fields'] ?? array() ) as $name => $kinds ) {
			foreach ( (array) $kinds as $kind ) {
				if ( is_string( $kind ) && 'external:gcal_event' === $kind ) {
					$out[] = (string) $name;
				}
			}
		}
		return $out;
	}

	/** `_ftnc_*` meta of a backup record that this site's dataset does not declare (Pro data on a free-only site). @return string[] */
	private static function unknown_meta( array $dataset, array $record ): array {
		if ( empty( $dataset ) || empty( $record['meta'] ) || ! is_array( $record['meta'] ) ) {
			return array();
		}
		$known = array_merge( (array) ( $dataset['meta_keys'] ?? array() ), Fotonic_Restore_Writer::own_meta_keys() );
		$out   = array();
		foreach ( $record['meta'] as $name => $value ) {
			if ( is_string( $name ) && 0 === strpos( $name, '_ftnc_' ) && ! in_array( $name, $known, true ) && ! in_array( $value, array( '', null, array() ), true ) ) {
				$out[] = $name;
			}
		}
		return $out;
	}

	/**
	 * A backup option this site has no place for: not a Vault option, not on the deny list, and not on the allowlist of the
	 * plugins that are active here (a setting of Pro on a site without Pro).
	 *
	 * @param string   $name  Option name.
	 * @param string[] $allow Allowlisted option names of this site.
	 * @param string[] $deny  Never-restored option names.
	 */
	private static function is_unknown_option( string $name, array $allow, array $deny ): bool {
		return 0 !== strpos( $name, 'fotonic_vault_' ) && ! in_array( $name, $deny, true ) && ! in_array( $name, $allow, true );
	}

	/** Options, then the end: the working copy (and the old Master Key with it) goes away. */
	private static function finalize( array $job, string $sid ) {
		$job['step'] = 'options';
		if ( empty( $job['options']['done'] ) ) {
			$options = Fotonic_Restore_Reader::options( $sid );
			if ( is_wp_error( $options ) ) {
				return self::fail( $job, $options );
			}
			$allow = Fotonic_Backup_Registry::options();
			$deny  = Fotonic_Backup_Registry::denied_options();
			foreach ( $options as $name => $value ) {
				if ( 'fotonic_payment_types' === $name ) {
					$site_raw = get_option( 'fotonic_payment_types', false );
					$merged   = Fotonic_Restore_Writer::merge_payment_types( $site_raw, $value );
					$job['options']['prev'] = array( 'payment_types' => $site_raw );
					if ( null !== $merged ) {
						update_option( 'fotonic_payment_types', $merged );
					}
				} elseif ( 0 !== strpos( (string) $name, 'fotonic_vault_' ) && ! in_array( $name, $deny, true ) && in_array( $name, $allow, true ) ) {
					/**
					 * An allowlisted option of an add-on (Pro): the add-on decides what to do with it.
					 *
					 * @param array|null $handled Null = not handled. Handled: array( 'prev' => value before, or false when it did not exist )
					 *                            (undo puts it back), or array( 'kept' => true ) when the site keeps what it has.
					 * @param string     $name    Option name.
					 * @param mixed      $value   The value in the backup.
					 * @param array      $ctx     total (replace everything), user_id.
					 */
					$handled = apply_filters( 'ftnc_restore_option', null, (string) $name, $value, array( 'total' => 'total' === ( $job['mode'] ?? 'merge' ), 'user_id' => (int) ( $job['user_id'] ?? get_current_user_id() ) ) );
					if ( is_array( $handled ) && array_key_exists( 'prev', $handled ) ) {
						$job['options']['prev_other'][ (string) $name ] = $handled['prev'];
						$job['report']['options_applied'][]              = (string) $name;
					} elseif ( ! is_array( $handled ) ) {
						$job['report']['options_skipped'][] = (string) $name;
					}
				} elseif ( self::is_unknown_option( (string) $name, $allow, $deny ) ) {
					// A setting of an add-on that is not active here (Pro is off): listed in the report, not lost from the backup.
					if ( ! in_array( (string) $name, $job['report']['unknown_fields'], true ) ) {
						$job['report']['unknown_fields'][] = (string) $name;
					}
				}
			}
			$job['options']['done'] = true;
		}
		$job['status'] = 'done';
		$job['step']   = 'done';
		$job['done']   = $job['total'];
		self::save( $job );
		Fotonic_Restore_Reader::close( $sid );
		/**
		 * The restore finished: records of the backup are on this site.
		 *
		 * @param array $status The job as status() gives it.
		 */
		do_action( 'ftnc_restore_finished', self::status( $job['id'] ) );
		return self::status( $job['id'] );
	}

	// ---------------------------------------------------------------------------
	// Pause, resume, dismiss
	// ---------------------------------------------------------------------------

	/**
	 * Takes the job lock of a session: the same lock `run()` and `undo()` hold for a batch, so a pause, a resume or a
	 * dismiss never reads or rewrites the job while a batch is writing it. Waits up to `$wait_ms` for a running batch
	 * to end. Returns the handle, `false` when there is no folder to lock (no session: nothing can be running), or `null`
	 * when the lock stayed busy.
	 *
	 * @return resource|false|null
	 */
	private static function take_lock( array $job, int $wait_ms ) {
		$dir = ! empty( $job['sid'] ) ? Fotonic_Restore_Reader::shared_dir( (string) $job['sid'] ) : '';
		if ( '' === $dir ) {
			return false;
		}
		$lock = fopen( $dir . '/job.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $lock ) {
			return false;
		}
		$until = microtime( true ) + $wait_ms / 1000;
		while ( ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( microtime( true ) >= $until ) {
				fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return null;
			}
			usleep( 100000 );
		}
		return $lock;
	}

	/** @param resource|false|null $lock */
	private static function drop_lock( $lock ): void {
		if ( is_resource( $lock ) ) {
			flock( $lock, LOCK_UN );
			fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
	}

	/**
	 * Runs `$change( $job )` on a fresh copy of the job while holding the job lock (pause, resume, dismiss).
	 *
	 * @param callable $change Receives the job read under the lock, returns the result for the caller.
	 * @return array|\WP_Error
	 */
	private static function change_locked( string $job_id, callable $change ) {
		$job = self::job();
		if ( null === $job || $job['id'] !== $job_id ) {
			return self::error( 'ftnc_restore_no_job', __( 'There is no restore running.', 'eleva-crm-for-photographers' ), 404 );
		}
		$lock = self::take_lock( $job, 8000 );
		if ( null === $lock ) {
			return self::error( 'ftnc_restore_busy', __( 'The restore is already running. Wait a moment.', 'eleva-crm-for-photographers' ), 409 );
		}
		try {
			$job = self::job(); // Fresh copy under the lock: a batch may have moved the cursor or finished.
			if ( null === $job || $job['id'] !== $job_id ) {
				return self::error( 'ftnc_restore_no_job', __( 'There is no restore running.', 'eleva-crm-for-photographers' ), 404 );
			}
			return $change( $job );
		} finally {
			self::drop_lock( $lock );
		}
	}

	public static function pause( string $job_id ) {
		return self::change_locked(
			$job_id,
			function ( array $job ) use ( $job_id ) {
				if ( 'running' !== $job['status'] ) {
					return self::error( 'not_running', __( 'The restore is not running.', 'eleva-crm-for-photographers' ), 409 );
				}
				$job['status'] = 'paused';
				self::save( $job );
				return self::status( $job_id );
			}
		);
	}

	/** Resume a paused job, or retry one that stopped with an error (it goes on from its cursor). */
	public static function resume( string $job_id ) {
		return self::change_locked(
			$job_id,
			function ( array $job ) use ( $job_id ) {
				if ( ! in_array( $job['status'], array( 'paused', 'error' ), true ) ) {
					return self::error( 'not_paused', __( 'The restore is not paused.', 'eleva-crm-for-photographers' ), 409 );
				}
				$job['status'] = 'running';
				$job['error']  = '';
				$job['dirty']  = true; // The step that failed may have written a record: look before creating again.
				self::save( $job );
				return self::status( $job_id );
			}
		);
	}

	/** Accept what was done and forget the job (no undo afterwards; the records stay, their labels and journals go). */
	public static function dismiss( string $job_id ) {
		return self::change_locked(
			$job_id,
			function ( array $job ) use ( $job_id ) {
				if ( in_array( $job['status'], array( 'running', 'undoing' ), true ) ) {
					return self::error( 'ftnc_restore_busy', __( 'The restore is running. Pause it first.', 'eleva-crm-for-photographers' ), 409 );
				}
				if ( ! empty( $job['sid'] ) ) {
					Fotonic_Restore_Reader::close( $job['sid'] );
				}
				self::purge_journals( $job['id'] ); // Accepted as it is: no undo, so no journals of old values either.
				$job['status'] = 'dismissed';
				self::save( $job );
				return self::status( $job_id );
			}
		);
	}

	// ---------------------------------------------------------------------------
	// Undo
	// ---------------------------------------------------------------------------

	/**
	 * Undo up to $size records of the restore (call again until `status` is `undone`): created records are deleted,
	 * overwritten ones get their old values back, then the options. Needs no key and no session.
	 *
	 * @return array|\WP_Error status() with `undone` (records handled in this call).
	 */
	public static function undo( string $job_id, int $size = 200 ) {
		$job = self::job();
		if ( null === $job || $job['id'] !== $job_id ) {
			return self::error( 'ftnc_restore_no_job', __( 'There is no restore to undo.', 'eleva-crm-for-photographers' ), 404 );
		}
		$lock = self::take_lock( $job, 0 );
		if ( null === $lock ) {
			return self::error( 'ftnc_restore_busy', __( 'The restore is running. Wait a moment.', 'eleva-crm-for-photographers' ), 409 );
		}
		try {
			$job = self::job(); // Fresh copy under the lock: what is saved below must not be an older copy of the job.
			if ( null === $job || $job['id'] !== $job_id ) {
				return self::error( 'ftnc_restore_no_job', __( 'There is no restore to undo.', 'eleva-crm-for-photographers' ), 404 );
			}
			if ( in_array( $job['status'], array( 'undone', 'dismissed' ), true ) ) {
				return self::status( $job_id );
			}
			if ( 'total' === ( $job['mode'] ?? 'merge' ) ) {
				$msg = '' !== $job['safety']
					? sprintf(
						/* translators: %s: file name of the safety backup. */
						__( 'A full restore replaces your data and cannot be undone. To go back, restore the safety backup made before it: %s.', 'eleva-crm-for-photographers' ),
						$job['safety']
					)
					: __( 'A full restore replaces your data and cannot be undone.', 'eleva-crm-for-photographers' );
				return self::error( 'ftnc_restore_not_undoable', $msg, 409 );
			}
			$job['status'] = 'undoing';
			$job['step']   = 'undo';
			$repo          = Fotonic_Restore_Writer::repo();
			$ids           = $repo->ids_with_meta( Fotonic_Restore_Writer::BATCH_META, $job['id'], max( 1, $size ) );
			$handled       = 0;
			foreach ( $ids as $id ) {
				$what = Fotonic_Restore_Writer::undo_record( $id );
				if ( 'skipped' === $what ) {
					$repo->delete_meta( $id, Fotonic_Restore_Writer::BATCH_META ); // Not ours to touch: stop asking for it.
				}
				$handled++;
			}
			if ( empty( $ids ) || count( $ids ) < $size ) {
				if ( ! empty( $job['options']['done'] ) && is_array( $job['options']['prev'] ) && array_key_exists( 'payment_types', $job['options']['prev'] ) ) {
					$prev = $job['options']['prev']['payment_types'];
					if ( false === $prev ) {
						delete_option( 'fotonic_payment_types' );
					} else {
						update_option( 'fotonic_payment_types', $prev );
					}
					$job['options']['done'] = false;
				}
				foreach ( (array) ( $job['options']['prev_other'] ?? array() ) as $name => $prev ) {
					if ( false === $prev ) {
						delete_option( (string) $name );
					} else {
						update_option( (string) $name, $prev );
					}
				}
				$job['options']['prev_other'] = array();
				$job['status'] = 'undone';
				$job['step']   = 'undone';
				Fotonic_Restore_Reader::close( $job['sid'] ); // Safe when the session is already gone.
			}
			self::save( $job );
			$out           = self::status( $job_id );
			$out['undone'] = $handled;
			return $out;
		} finally {
			self::drop_lock( $lock );
		}
	}
}
