<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Encrypts the plain values that already exist when the Vault is switched on (or when a release
 * brings new protected fields), in small batches, with pause and resume.
 *
 * One job at a time, kept in the option `fotonic_vault_encrypt_job`:
 *   status  pending (created, not started: never blocks) | running | paused | error (a step failed:
 *           never blocks; run_batch() retries it, cancel() forgets the job)
 *   phase   scan (count) -> analyze (read only: how much is plain) -> prepare (key self-test)
 *           -> encrypt (write) -> finish (re-check, hooks, forget the job)
 * Every call of run_batch() does one small step, so a closed tab loses nothing: the next call
 * continues from the saved cursor. While running or paused, writes of protected data are refused
 * with 423 `encrypt_in_progress` (see Fotonic_REST_API::locked_write_response()), so a record is
 * never edited while it is being encrypted.
 *
 * Safety: the filter `ftnc_vault_encrypt_scope` limits every query to a list of record IDs. Tests
 * set it to their own fixtures; production leaves it alone (null = all records).
 *
 * Cancelling is always safe for the data: records that are part plain and part encrypted read fine, and
 * the next status read creates a new pending job. A job stuck for any reason is cleared with
 * Fotonic_Vault_Encrypt_Existing::cancel() (REST: POST /vault/encrypt-existing/cancel) or clear().
 */
class Fotonic_Vault_Encrypt_Existing {

	const OPTION_JOB     = 'fotonic_vault_encrypt_job';
	const OPTION_VERSION = 'fotonic_vault_encrypt_version'; // string[]: signatures of the fields this site has been through (1.6.4 first stored the int 1).
	const OPTION_LOCK    = 'fotonic_vault_encrypt_lock';
	const LOCK_TTL       = 60;  // Seconds before a lock left by a crashed request counts as stale.
	const BATCH          = 25;
	const PHASES         = array( 'scan', 'analyze', 'prepare', 'encrypt', 'finish' );
	// Share of the progress bar given to each phase.
	const WEIGHTS        = array( 'scan' => 5, 'analyze' => 15, 'prepare' => 5, 'encrypt' => 70, 'finish' => 5 );

	/** @return int[]|null Record IDs every query is limited to, or null for all records. */
	public static function scope(): ?array {
		$scope = apply_filters( 'ftnc_vault_encrypt_scope', null );
		return is_array( $scope ) ? array_values( array_map( 'intval', $scope ) ) : null;
	}

	// ---------------------------------------------------------------------------
	// Job lifecycle
	// ---------------------------------------------------------------------------

	public static function job(): ?array {
		$job = get_option( self::OPTION_JOB, null );
		return is_array( $job ) && isset( $job['status'] ) ? $job : null;
	}

	/** A job exists and has not finished. */
	public static function is_pending(): bool {
		return null !== self::job();
	}

	/** Writes of protected data are refused only while the job is running or paused. */
	public static function is_blocking(): bool {
		$job = self::job();
		return null !== $job && in_array( $job['status'], array( 'running', 'paused' ), true );
	}

	/**
	 * Create the job (status pending, nothing blocked yet). Does nothing when one exists.
	 *
	 * @param bool $reset Forget that this site went through the current field list (a Vault was just set up).
	 */
	public static function create( bool $reset = false ): bool {
		if ( $reset ) {
			delete_option( self::OPTION_VERSION );
		}
		if ( null !== self::job() ) {
			return false;
		}
		$job = array(
			'status'  => 'pending',
			'phase'   => 'scan',
			'groups'  => array_keys( Fotonic_Vault_Fields::groups() ),
			'gi'      => 0,
			'last_id' => 0,
			'totals'  => array(),
			'started' => 0,
			'updated' => time(),
			'error'   => '',
		);
		update_option( self::OPTION_JOB, $job, false );
		return true;
	}

	/**
	 * Called when the status is read: a Vault with a protected field it has not been through gets one
	 * pending job (a site that upgraded and already had a Vault, or Pro switched on later). It is a
	 * superset test: a Pro plugin switched off removes fields and never raises a job. Never starts or
	 * blocks anything.
	 */
	public static function ensure_job(): void {
		if ( ! Fotonic_Vault::is_setup() || ! self::has_new_fields() ) {
			return;
		}
		self::create();
	}

	/**
	 * Signatures of the protected fields this site has already been through.
	 * Before the signature list, the option held the int 1: the free fields were covered, Pro's were not.
	 *
	 * @return string[]
	 */
	public static function covered(): array {
		$stored = get_option( self::OPTION_VERSION, array() );
		if ( is_array( $stored ) ) {
			return array_values( array_map( 'strval', $stored ) );
		}
		return (int) $stored >= 1 ? Fotonic_Vault_Fields::signatures( Fotonic_Vault_Fields::base_groups() ) : array();
	}

	/** Whether the registry holds a protected field this site has not been through yet. */
	public static function has_new_fields(): bool {
		return count( array_diff( Fotonic_Vault_Fields::signatures(), self::covered() ) ) > 0;
	}

	/** Forget the job and its lock (a Vault reset, or a stuck job). */
	public static function clear(): void {
		delete_option( self::OPTION_JOB );
		self::delete_lock_row( null );
	}

	/** The person gave up (or the job is stuck): forget it. Safe for the data, see the class comment. */
	public static function cancel(): bool {
		if ( null === self::job() ) {
			return false;
		}
		self::clear();
		return true;
	}

	public static function pause(): bool {
		$job = self::job();
		if ( null === $job || 'running' !== $job['status'] ) {
			return false;
		}
		$job['status'] = 'paused';
		self::save( $job );
		return true;
	}

	public static function resume(): bool {
		$job = self::job();
		if ( null === $job || 'paused' !== $job['status'] ) {
			return false;
		}
		$job['status'] = 'running';
		self::save( $job );
		return true;
	}

	private static function save( array $job ): void {
		$job['updated'] = time();
		update_option( self::OPTION_JOB, $job, false );
	}

	// ---------------------------------------------------------------------------
	// Status for the screen
	// ---------------------------------------------------------------------------

	/**
	 * @return array{status:string,phase:string,percent:int,eta_seconds:?int,to_encrypt:int,records:int,done:int,error:string}
	 */
	public static function status(): array {
		$job = self::job();
		if ( null === $job ) {
			return array( 'status' => 'none', 'phase' => '', 'percent' => 100, 'eta_seconds' => 0, 'to_encrypt' => 0, 'records' => 0, 'done' => 0, 'error' => '' );
		}
		$records = 0;
		$todo    = 0;
		$done    = 0;
		foreach ( (array) $job['totals'] as $t ) {
			$records += (int) ( $t['total'] ?? 0 );
			$todo    += (int) ( $t['todo'] ?? 0 );
			$done    += (int) ( $t['done'] ?? 0 );
		}
		$percent = 0;
		foreach ( self::PHASES as $phase ) {
			if ( $phase === $job['phase'] ) {
				$inside = 0;
				if ( 'analyze' === $phase ) {
					$inside = $records > 0 ? min( 1, self::progress_in( $job, 'seen' ) / $records ) : 1;
				} elseif ( 'encrypt' === $phase ) {
					$inside = $todo > 0 ? min( 1, $done / $todo ) : 1;
				}
				$percent += (int) round( self::WEIGHTS[ $phase ] * $inside );
				break;
			}
			$percent += self::WEIGHTS[ $phase ];
		}
		$eta = null;
		if ( 'encrypt' === $job['phase'] && $done > 0 && ! empty( $job['enc_started'] ) ) {
			$elapsed = max( 1, time() - (int) $job['enc_started'] );
			$eta     = (int) ceil( $elapsed / $done * max( 0, $todo - $done ) );
		}
		return array(
			'status'      => (string) $job['status'],
			'phase'       => (string) $job['phase'],
			'percent'     => min( 100, $percent ),
			'eta_seconds' => $eta,
			'to_encrypt'  => $todo,
			'records'     => $records,
			'done'        => $done,
			'error'       => (string) $job['error'],
		);
	}

	private static function progress_in( array $job, string $key ): int {
		$n = 0;
		foreach ( (array) $job['totals'] as $t ) {
			$n += (int) ( $t[ $key ] ?? 0 );
		}
		return $n;
	}

	// ---------------------------------------------------------------------------
	// One step
	// ---------------------------------------------------------------------------

	/**
	 * Do one small step of the job. Needs the Master Key.
	 *
	 * @param string $key  Master Key (32 bytes).
	 * @param int    $size Records per batch (0 = the default, filter ftnc_vault_encrypt_batch_size).
	 * @return array Status (see status()), plus 'busy' => true when another request holds the step.
	 */
	public static function run_batch( string $key, int $size = 0 ): array {
		/**
		 * Records per step. A slow database can lower it; a local test can set it to 1 to watch the job.
		 *
		 * @param int $size Default batch size.
		 */
		$size = $size > 0 ? $size : (int) apply_filters( 'ftnc_vault_encrypt_batch_size', self::BATCH );
		$size = max( 1, min( 200, $size ) );
		$job = self::job();
		if ( null === $job ) {
			return self::status();
		}
		if ( 'paused' === $job['status'] ) {
			return self::status();
		}
		if ( 32 !== strlen( $key ) ) {
			return self::fail( $job, 'no_key' );
		}
		$token = self::acquire();
		if ( null === $token ) {
			$status         = self::status();
			$status['busy'] = true;
			return $status;
		}
		try {
			$job = self::job();
			if ( null === $job || 'paused' === $job['status'] ) {
				return self::status();
			}
			if ( 'pending' === $job['status'] ) {
				$job['status']  = 'running';
				$job['started'] = time();
			} elseif ( 'error' === $job['status'] ) {
				$job['status'] = 'running'; // A retry.
			}
			$job['error'] = '';
			$scope        = self::scope();
			$groups       = Fotonic_Vault_Fields::groups();
			switch ( $job['phase'] ) {
				case 'scan':
					$job = self::step_scan( $job, $groups, $scope );
					break;
				case 'analyze':
					$job = self::step_walk( $job, $groups, $scope, $key, $size, false );
					break;
				case 'prepare':
					$job = self::step_prepare( $job, $key );
					break;
				case 'encrypt':
					$job = self::step_walk( $job, $groups, $scope, $key, $size, true );
					break;
				case 'finish':
					return self::step_finish( $job, $groups, $scope, $key );
			}
			// Pause and cancel do not take the step lock: look again before saving, so a click that arrived during
			// this step is not undone by it.
			$fresh = self::job();
			if ( null === $fresh ) {
				return self::status(); // Cancelled meanwhile: do not bring the job back.
			}
			if ( 'paused' === $fresh['status'] ) {
				$job['status'] = 'paused';
			}
			self::save( $job );
		} finally {
			self::release( $token );
		}
		return self::status();
	}

	private static function step_scan( array $job, array $groups, ?array $scope ): array {
		$repo = Fotonic_Vault_Fields::repo();
		$job['groups'] = array_keys( $groups );
		$job['totals'] = array();
		foreach ( $groups as $id => $group ) {
			$job['totals'][ $id ] = array( 'total' => $repo->count( $group['post_type'], $scope ), 'seen' => 0, 'todo' => 0, 'done' => 0 );
		}
		$job['phase']   = 'analyze';
		$job['gi']      = 0;
		$job['last_id'] = 0;
		return $job;
	}

	/**
	 * Analyze (read only, counts what is plain) or encrypt (writes) one batch, moving the cursor.
	 */
	private static function step_walk( array $job, array $groups, ?array $scope, string $key, int $size, bool $write ): array {
		$repo = Fotonic_Vault_Fields::repo();
		while ( $job['gi'] < count( $job['groups'] ) ) {
			$gid = $job['groups'][ $job['gi'] ];
			if ( ! isset( $groups[ $gid ] ) ) { // A group that is gone (a Pro plugin switched off): skip it.
				$job['gi']++;
				$job['last_id'] = 0;
				continue;
			}
			$ids = $repo->ids_after( $groups[ $gid ]['post_type'], (int) $job['last_id'], $size, $scope );
			if ( empty( $ids ) ) {
				$job['gi']++;
				$job['last_id'] = 0;
				continue;
			}
			foreach ( $ids as $post_id ) {
				$n = Fotonic_Vault_Fields::process_record( $post_id, $groups[ $gid ], $key, $write );
				if ( $write ) {
					if ( $n > 0 ) {
						$job['totals'][ $gid ]['done']++;
					}
				} else {
					$job['totals'][ $gid ]['seen']++;
					if ( $n > 0 ) {
						$job['totals'][ $gid ]['todo']++;
					}
				}
				$job['last_id'] = $post_id;
			}
			return $job; // One batch per call.
		}
		// Every group walked: next phase.
		$job['gi']      = 0;
		$job['last_id'] = 0;
		if ( $write ) {
			$job['phase'] = 'finish';
		} else {
			$job['phase']       = 'prepare';
		}
		return $job;
	}

	private static function step_prepare( array $job, string $key ): array {
		$probe = Fotonic_Field_Crypt::encrypt( 'ftnc-self-test', $key );
		$ok    = false !== $probe && 'ftnc-self-test' === Fotonic_Field_Crypt::decrypt( $probe, $key );
		/**
		 * Tests force a failed self-test here; nothing else should hook it.
		 *
		 * @param bool $ok Whether the key encrypted and decrypted a probe text.
		 */
		if ( ! apply_filters( 'ftnc_vault_encrypt_self_test', $ok ) ) {
			$job['status'] = 'error'; // Never blocks writes; the screen offers a retry and a cancel.
			$job['error']  = 'self_test_failed';
			return $job;
		}
		$job['phase']       = 'encrypt';
		$job['enc_started'] = time();
		$job['gi']          = 0;
		$job['last_id']     = 0;
		return $job;
	}

	/** Re-check a sample, tell Pro, record the field list this site has been through, forget the job. */
	private static function step_finish( array $job, array $groups, ?array $scope, string $key ): array {
		$repo = Fotonic_Vault_Fields::repo();
		foreach ( $groups as $group ) {
			foreach ( $repo->ids_after( $group['post_type'], 0, 100, $scope ) as $post_id ) {
				if ( Fotonic_Vault_Fields::process_record( $post_id, $group, $key, false ) > 0 ) {
					// Something is still plain: go round once more, but never forever.
					$job['rescans'] = (int) ( $job['rescans'] ?? 0 ) + 1;
					if ( $job['rescans'] > 3 ) {
						$job['status']  = 'error'; // Never blocks writes; the screen offers a retry and a cancel.
						$job['error']   = 'incomplete';
						$job['rescans'] = 0;
					} else {
						$job['phase']   = 'analyze';
						$job['gi']      = 0;
						$job['last_id'] = 0;
						$job['error']   = 'rescan';
						foreach ( $job['totals'] as $gid => $t ) {
							$job['totals'][ $gid ] = array( 'total' => $t['total'], 'seen' => 0, 'todo' => 0, 'done' => 0 );
						}
					}
					self::save( $job );
					return self::status();
				}
			}
		}
		/**
		 * The job is done: Pro refreshes whatever depends on the stored (now encrypted) values.
		 *
		 * @param int[]|null $scope The record scope of the pass (null = all).
		 */
		do_action( 'ftnc_vault_encrypt_finished', $scope );
		update_option( self::OPTION_VERSION, array_values( array_unique( array_merge( self::covered(), Fotonic_Vault_Fields::signatures( $groups ) ) ) ), false );
		delete_option( self::OPTION_JOB );
		return array( 'status' => 'done', 'phase' => 'finish', 'percent' => 100, 'eta_seconds' => 0, 'to_encrypt' => 0, 'records' => 0, 'done' => 0, 'error' => '' );
	}

	private static function fail( array $job, string $code ): array {
		$job['error'] = $code;
		self::save( $job );
		return self::status();
	}

	// ---------------------------------------------------------------------------
	// One step at a time: an atomic lock row (INSERT IGNORE on the unique option_name, the pattern of
	// Fotonic_Vault_Attempts; add_option() is not atomic, it ends in ON DUPLICATE KEY UPDATE)
	// ---------------------------------------------------------------------------

	private static $held = null;

	private static function acquire(): ?string {
		global $wpdb;
		$token = time() . '.' . wp_generate_password( 12, false );
		for ( $try = 0; $try < 2; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock row, see the section comment.
			$created = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'no' ) /* FOTONIC ENCRYPT LOCK */", self::OPTION_LOCK, $token ) );
			if ( $created ) {
				self::$held = $token;
				return $token;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the lock row straight from the table.
			$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", self::OPTION_LOCK ) );
			if ( '' !== $held && (int) $held > time() - self::LOCK_TTL ) {
				return null; // A live request holds it.
			}
			self::delete_lock_row( $held ); // Stale (or gone between the two queries): remove exactly that row, retry once.
		}
		return null;
	}

	private static function release( ?string $token ): void {
		if ( null !== $token ) {
			self::delete_lock_row( $token );
			self::$held = null;
		}
	}

	/** Delete the lock row only if it still carries $value (compare-and-delete); null = whatever it carries. */
	private static function delete_lock_row( ?string $value ): void {
		global $wpdb;
		if ( null === $value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Forget the lock row.
			$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name = %s", self::OPTION_LOCK ) );
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete on the lock row.
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND option_value = %s", self::OPTION_LOCK, $value ) );
	}
}
