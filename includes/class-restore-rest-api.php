<?php
/**
 * REST routes of the backup restore: upload in pieces, open an archive, close the session.
 * Namespace: fotonic/v1/restore. The work is in Fotonic_Restore_Upload and Fotonic_Restore_Reader;
 * this class only checks permissions and input and shapes the answers.
 *
 * Routes (all manage_options + the REST nonce):
 *   POST   /restore/upload/start    { size, sha256? }               -> { upload_id, chunk_size, total_chunks }
 *   GET    /restore/upload/status   ?upload_id                      -> { received, total_chunks, next_missing }
 *   POST   /restore/upload/chunk    ?upload_id&index, raw body      -> { received, total_chunks, next_missing }
 *   POST   /restore/upload/finish   { upload_id }                   -> { upload_id, ready: true }
 *   GET    /restore/backups                                         -> { backups: [ { file, size, modified } ] }
 *   GET    /restore/sources                                         -> { sources: [ { source, label, items: [ { ref, name, size, created } ] } ] }  (Pro: Google Drive)
 *   POST   /restore/fetch           { source, ref, upload_id? }     -> { upload_id, received, total_chunks, next_missing, done }  (brings a remote backup into an upload; call until done)
 *   POST   /restore/open            { upload_id | backup_file, secret? } -> { session_id, same_vault, manifest, collections, expires_at }
 *   POST   /restore/preview         { session_id, restart? }        -> one batch of the preview (phase 08)
 *   POST   /restore/start           { session_id, decisions?, plain?, total?, confirm? } -> job status (phase 09/10; scenario B or C chosen by the server)
 *   POST   /restore/run|pause|resume|undo|dismiss  { job_id }       -> job status (run and undo: call until done / undone)
 *   GET    /restore/status                                          -> { job: status|null }
 *   DELETE /restore/{id}            id = a session id or an upload id -> { closed: true }
 *
 * @package Fotonic
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_Restore_REST_API {

	const NAMESPACE = 'fotonic/v1';

	public static function register_routes(): void {
		$perm = [ __CLASS__, 'admin_permission' ];

		register_rest_route( self::NAMESPACE, '/restore/upload/start', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'upload_start' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( self::NAMESPACE, '/restore/upload/status', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'upload_status' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( self::NAMESPACE, '/restore/upload/chunk', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'upload_chunk' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( self::NAMESPACE, '/restore/upload/finish', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'upload_finish' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( self::NAMESPACE, '/restore/backups', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'list_backups' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( self::NAMESPACE, '/restore/sources', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'list_sources' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( self::NAMESPACE, '/restore/fetch', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'fetch_source' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( self::NAMESPACE, '/restore/open', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'open' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( self::NAMESPACE, '/restore/preview', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'preview' ],
			'permission_callback' => $perm,
		] );
		foreach ( [ 'start', 'run', 'pause', 'resume', 'undo', 'dismiss' ] as $action ) {
			register_rest_route( self::NAMESPACE, '/restore/' . $action, [
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'job_' . $action ],
				'permission_callback' => $perm,
			] );
		}
		register_rest_route( self::NAMESPACE, '/restore/status', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'job_status' ],
			'permission_callback' => $perm,
		] );
		register_rest_route( self::NAMESPACE, '/restore/(?P<id>[a-f0-9]{32})', [
			'methods'             => \WP_REST_Server::DELETABLE,
			'callback'            => [ __CLASS__, 'close' ],
			'permission_callback' => $perm,
		] );
	}

	public static function admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	// ---------------------------------------------------------------------------
	// Upload
	// ---------------------------------------------------------------------------

	public static function upload_start( \WP_REST_Request $request ) {
		$result = Fotonic_Restore_Upload::start( (int) $request->get_param( 'size' ), (string) $request->get_param( 'sha256' ) );
		return self::respond( $result );
	}

	public static function upload_status( \WP_REST_Request $request ) {
		return self::respond( Fotonic_Restore_Upload::status( (string) $request->get_param( 'upload_id' ) ) );
	}

	public static function upload_chunk( \WP_REST_Request $request ) {
		$result = Fotonic_Restore_Upload::chunk(
			(string) $request->get_param( 'upload_id' ),
			(int) $request->get_param( 'index' ),
			(string) $request->get_body()
		);
		return self::respond( $result );
	}

	public static function upload_finish( \WP_REST_Request $request ) {
		$id   = (string) $request->get_param( 'upload_id' );
		$path = Fotonic_Restore_Upload::finish( $id );
		if ( is_wp_error( $path ) ) {
			return self::respond( $path );
		}
		return self::respond( [ 'upload_id' => $id, 'ready' => true ] );
	}

	/** Archives already in the backups folder, newest first (the alternative to uploading). */
	public static function list_backups() {
		$dir   = Fotonic_Backup_Archive::backup_dir();
		$files = glob( $dir . '/eleva-backup-*.zip' );
		$out   = [];
		foreach ( is_array( $files ) ? $files : [] as $file ) {
			if ( is_file( $file ) && self::valid_backup_name( basename( $file ) ) ) {
				$out[] = [
					'file'     => basename( $file ),
					'size'     => (int) filesize( $file ),
					'modified' => (int) filemtime( $file ),
				];
			}
		}
		usort( $out, function ( $a, $b ) {
			return $b['modified'] <=> $a['modified'];
		} );
		return self::respond( [ 'backups' => $out ] );
	}

	/**
	 * Backups that live somewhere else, offered next to the local ones. Nothing here knows about any provider: the
	 * filter `ftnc_restore_sources` returns groups `[ { source, label, items: [ { ref, name, size, created } ] } ]`
	 * (Eleva CRM Pro adds Google Drive). A source that fails to list is simply left out.
	 */
	public static function list_sources() {
		$groups = apply_filters( 'ftnc_restore_sources', [] );
		$out    = [];
		foreach ( is_array( $groups ) ? $groups : [] as $group ) {
			if ( is_array( $group ) && ! empty( $group['source'] ) && isset( $group['items'] ) && is_array( $group['items'] ) ) {
				$out[] = [
					'source' => (string) $group['source'],
					'label'  => (string) ( $group['label'] ?? $group['source'] ),
					'items'  => array_values( $group['items'] ),
				];
			}
		}
		return self::respond( [ 'sources' => $out ] );
	}

	/**
	 * Brings one backup from a remote source into an upload (the same upload the browser fills piece by piece), a few
	 * pieces per call. Call again with the returned `upload_id` until `done`; then open it with `upload_id`. The work is
	 * done by whoever answers the filter `ftnc_restore_fetch( $default, $source, $ref, $upload_id )`: it returns the
	 * progress array or a WP_Error. The `ref` is never trusted here: the answering code must check it against its own
	 * listing.
	 */
	public static function fetch_source( \WP_REST_Request $request ) {
		$source    = sanitize_key( (string) $request->get_param( 'source' ) );
		$ref       = (string) $request->get_param( 'ref' );
		$upload_id = (string) $request->get_param( 'upload_id' );
		if ( '' !== $upload_id && 1 !== preg_match( '/^[a-f0-9]{32}$/', $upload_id ) ) {
			$upload_id = '';
		}
		$default = new \WP_Error( 'ftnc_restore_unknown_source', __( 'This place for backups is not available.', 'eleva-crm-for-photographers' ), [ 'status' => 404 ] );
		return self::respond( apply_filters( 'ftnc_restore_fetch', $default, $source, $ref, $upload_id ) );
	}

	// ---------------------------------------------------------------------------
	// Open and close
	// ---------------------------------------------------------------------------

	public static function open( \WP_REST_Request $request ) {
		$upload_id = (string) $request->get_param( 'upload_id' );
		$file      = (string) $request->get_param( 'backup_file' );
		$secret    = $request->get_param( 'secret' );
		$secret    = is_string( $secret ) ? $secret : null; // A password is taken as typed: no sanitizing.

		if ( '' !== $upload_id ) {
			$path = Fotonic_Restore_Upload::ready_path( $upload_id );
		} elseif ( '' !== $file ) {
			$path = self::backup_path( $file );
		} else {
			$path = new \WP_Error( 'ftnc_restore_no_source', __( 'Choose a backup file first.', 'eleva-crm-for-photographers' ), [ 'status' => 400 ] );
		}
		if ( is_wp_error( $path ) ) {
			return self::respond( $path );
		}
		$opened = Fotonic_Restore_Reader::open( $path, $secret );
		if ( is_array( $opened ) ) {
			// What the screen needs for "replace everything": the word to type and what stands in the way.
			$opened['total'] = [ 'word' => Fotonic_Restore_Wipe::confirm_word(), 'blocking' => Fotonic_Restore_Wipe::blocking() ];
		}
		return self::respond( $opened );
	}

	/**
	 * One batch of the preview (phase 08): new / identical / different / possible duplicate, nothing written.
	 * Call again until `done`. `restart: true` forgets the plan. 423 `vault_locked` when this site's Vault is
	 * set up and locked.
	 */
	public static function preview( \WP_REST_Request $request ) {
		$session = (string) $request->get_param( 'session_id' );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $session ) ) {
			return self::respond( new \WP_Error( 'ftnc_restore_expired', __( 'This restore session has ended. Open the backup again.', 'eleva-crm-for-photographers' ), [ 'status' => 410 ] ) );
		}
		return self::respond( Fotonic_Restore_Preview::step( $session, rest_sanitize_boolean( $request->get_param( 'restart' ) ) ) );
	}

	// ---------------------------------------------------------------------------
	// The restore job (phase 09)
	// ---------------------------------------------------------------------------

	/**
	 * `{ session_id, decisions, plain?, total?, confirm? }`: checks the plan and the choices, creates the job. Scenario B or C
	 * is decided here, by the server. `plain: true` is the person's confirmation to restore into a site with no Vault;
	 * `total: true` + `confirm` (the word the server gave in `open`) replaces every record of the site.
	 */
	public static function job_start( \WP_REST_Request $request ) {
		$session = (string) $request->get_param( 'session_id' );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $session ) ) {
			return self::respond( new \WP_Error( 'ftnc_restore_expired', __( 'This restore session has ended. Open the backup again.', 'eleva-crm-for-photographers' ), [ 'status' => 410 ] ) );
		}
		$decisions = $request->get_param( 'decisions' );
		$options   = [
			'plain'   => rest_sanitize_boolean( $request->get_param( 'plain' ) ),
			'total'   => rest_sanitize_boolean( $request->get_param( 'total' ) ),
			'confirm' => is_string( $request->get_param( 'confirm' ) ) ? (string) $request->get_param( 'confirm' ) : '',
		];
		return self::respond( Fotonic_Restore_Job::create( $session, is_array( $decisions ) ? $decisions : [], $options ) );
	}

	/** `{ job_id }`: one step (the safety backup, or a batch of records) until `status` is `done`. */
	public static function job_run( \WP_REST_Request $request ) {
		return self::respond( Fotonic_Restore_Job::run( self::job_id( $request ) ) );
	}

	public static function job_pause( \WP_REST_Request $request ) {
		return self::respond( Fotonic_Restore_Job::pause( self::job_id( $request ) ) );
	}

	public static function job_resume( \WP_REST_Request $request ) {
		return self::respond( Fotonic_Restore_Job::resume( self::job_id( $request ) ) );
	}

	/** Call until `status` is `undone`. */
	public static function job_undo( \WP_REST_Request $request ) {
		return self::respond( Fotonic_Restore_Job::undo( self::job_id( $request ) ) );
	}

	public static function job_dismiss( \WP_REST_Request $request ) {
		return self::respond( Fotonic_Restore_Job::dismiss( self::job_id( $request ) ) );
	}

	/** The current job (or `{ job: null }`): lets the screen find its way back after a reload. */
	public static function job_status() {
		return self::respond( [ 'job' => Fotonic_Restore_Job::status() ] );
	}

	private static function job_id( \WP_REST_Request $request ): string {
		$id = (string) $request->get_param( 'job_id' );
		return 1 === preg_match( '/^[a-f0-9]{32}$/', $id ) ? $id : '';
	}

	/** Ends a restore (session id) or abandons an upload (upload id). Both are 32 hex characters. */
	public static function close( \WP_REST_Request $request ) {
		$id  = (string) $request->get_param( 'id' );
		$job = Fotonic_Restore_Job::job();
		if ( null !== $job && Fotonic_Restore_Job::is_blocking() && ( $job['sid'] ?? '' ) === $id ) {
			return self::respond( new \WP_Error( 'ftnc_restore_busy', __( 'The restore is running. Pause it first.', 'eleva-crm-for-photographers' ), [ 'status' => 409 ] ) );
		}
		Fotonic_Restore_Reader::close( $id );
		Fotonic_Restore_Upload::discard( $id );
		return self::respond( [ 'closed' => true ] );
	}

	// ---------------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------------

	/** Backup archives are written as eleva-backup-DATE-RANDOM.zip; nothing else is opened from the backups folder. */
	private static function valid_backup_name( string $name ): bool {
		return 1 === preg_match( '/^eleva-backup-[0-9]{4}-[0-9]{2}-[0-9]{2}-[0-9]{6}-[a-f0-9]{8,32}\.zip$/', $name );
	}

	private static function backup_path( string $file ) {
		$dir  = Fotonic_Backup_Archive::backup_dir();
		$path = $dir . '/' . $file;
		if ( $file !== basename( $file ) || ! self::valid_backup_name( $file ) || ! is_file( $path ) || dirname( (string) realpath( $path ) ) !== (string) realpath( $dir ) ) {
			return new \WP_Error( 'ftnc_restore_no_such_backup', __( 'That backup file was not found.', 'eleva-crm-for-photographers' ), [ 'status' => 404 ] );
		}
		return $path;
	}

	/** Errors keep their own status (WordPress turns the WP_Error into the response); anything else is a 200. */
	private static function respond( $result ) {
		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			unset( $data['detail'] ); // The detail is for the log, not the browser.
			if ( ! isset( $data['status'] ) ) {
				$data['status'] = 400;
			}
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), $data );
		}
		$response = new \WP_REST_Response( $result );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
