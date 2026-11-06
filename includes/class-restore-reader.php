<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Opens a backup archive for restore and serves its records in batches. Writes nothing to the
 * site's data: it only prepares a private, short-lived working copy.
 *
 * Mirrors tools/eleva-backup-decrypt.php (the offline tool), which stays the independent reference
 * for the formats; the format itself is documented in class-backup-archive.php and
 * class-backup-cipher.php. Differences by design: this class reuses Fotonic_Crypto and
 * Fotonic_Backup_Cipher instead of reimplementing them, never asks for the 6-digit code (the
 * archive opens with the Vault password, the recovery code or the recovery phrase alone; the code
 * is not part of any key), and checks the SHA-256 of key.json and data.enc at open() but leaves
 * the attachments' SHA-256 to verify_member(), because hashing every attachment of a multi-gigabyte
 * archive would not fit in one request (their AES-GCM tags still authenticate them on decryption).
 *
 * Two ways in:
 *  - Same Vault: if the Vault of this site is unlocked and its Master Key opens the archive's
 *    wrapped backup key, the archive belongs to this Vault and no credential is asked.
 *  - Credentials of the Vault that made the archive: password, recovery code or recovery phrase.
 *    Wrong ones count in the 'restore' bucket of Fotonic_Vault_Attempts.
 *
 * Working copy (uploads/fotonic/restore/s-<session>/): one c<N>.jsonl and c<N>.idx per collection,
 * written by Fotonic_Restore_Splitter, and options.enc. Every record is sealed with AES-256-GCM
 * under a random per-session key RK, so the files are unreadable at rest. RK lives only in the
 * HttpOnly SameSite=Strict cookie fotonic_restore_key ("<session>.<RK base64url>"). The old Master
 * Key and the archive key are stored wrapped under RK in the option fotonic_restore_s_<session>,
 * which never works without that cookie. This works whether or not the site has a Vault of its
 * own, which a wrap under the site's Master Key could not (scenarios A and C have none).
 * Everything is deleted by close(), and after TTL by cleanup_all() (cron).
 */
final class Fotonic_Restore_Reader {

	const TTL              = 7200;
	const OPTION_PREFIX    = 'fotonic_restore_s_';
	const OPTION_REGISTRY  = 'fotonic_restore_sessions'; // session id => { user, created }.
	const COOKIE_NAME      = 'fotonic_restore_key';
	const SUPPORTED_SCHEME = 2;
	const MAX_MANIFEST     = 67108864;
	const MAX_KEY_JSON     = 1048576;
	const MAX_MEMBERS      = 500000;
	const MAX_PAGE         = 1000;
	const GCM              = 'aes-256-gcm';

	/** Name of an archive member we accept: nothing else may be in the zip. */
	const MEMBER_PATTERN = '#^(manifest\.json|key\.json|data\.enc|files/[0-9]{1,12}\.enc)$#';

	// ---------------------------------------------------------------------------
	// Open
	// ---------------------------------------------------------------------------

	/**
	 * Check an archive, unlock it and build the working copy.
	 *
	 * @param string      $path   Archive path (an upload from Fotonic_Restore_Upload, or a file of the backups folder).
	 * @param string|null $secret Vault password, recovery code or recovery phrase of the site that made the archive.
	 *                            null or '' = try this site's unlocked Vault.
	 * @return array|\WP_Error array( 'session_id', 'same_vault', 'manifest', 'collections', 'expires_at' ).
	 *                         Code ftnc_restore_credentials_needed (401) means: ask for a credential.
	 */
	public static function open( string $path, ?string $secret = null ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return self::error( 'ftnc_restore_no_zip', __( 'The PHP Zip extension is not available on this server.', 'eleva-crm-for-photographers' ), 500 );
		}
		if ( ! Fotonic_Backup_Keys::is_supported() ) {
			return self::error( 'ftnc_restore_no_sodium', __( 'Unavailable on this server: the Sodium extension is missing.', 'eleva-crm-for-photographers' ), 500 );
		}
		if ( ! Fotonic_Backup_Cipher::is_supported() ) {
			return self::error( 'ftnc_restore_no_openssl', __( 'AES-256-GCM is not available on this server.', 'eleva-crm-for-photographers' ), 500 );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best effort; key derivation and decryption of a large archive take a while.
		}

		// A new session closes the person's earlier ones: not while a restore still needs one of them.
		foreach ( array_keys( self::registry() ) as $busy_id ) {
			$busy = get_option( self::OPTION_PREFIX . $busy_id, null );
			if ( is_array( $busy ) && (int) ( $busy['user_id'] ?? 0 ) === (int) get_current_user_id() && class_exists( 'Fotonic_Restore_Job' ) && Fotonic_Restore_Job::uses_session( (string) $busy_id ) ) {
				return self::error( 'ftnc_restore_in_use', __( 'A restore is still going on. Finish it or undo it before you open another backup.', 'eleva-crm-for-photographers' ), 409 );
			}
		}

		$zip = new \ZipArchive();
		if ( ! is_file( $path ) || true !== $zip->open( $path ) ) {
			return self::error( 'ftnc_restore_bad_file', __( 'This file is not a readable backup. If it came from a cloud drive, make sure the download finished.', 'eleva-crm-for-photographers' ), 422 );
		}

		$checked = self::check_archive( $zip );
		if ( is_wp_error( $checked ) ) {
			$zip->close();
			return $checked;
		}
		list( $manifest, $key_data ) = $checked;
		$summary                     = self::summarize( $manifest );

		$unlocked = self::unlock( $key_data, $secret );
		if ( is_wp_error( $unlocked ) ) {
			$zip->close();
			$data            = (array) $unlocked->get_error_data();
			$data['manifest'] = $summary;
			return new \WP_Error( $unlocked->get_error_code(), $unlocked->get_error_message(), $data );
		}
		list( $mk, $same_vault ) = $unlocked;

		$ak = self::archive_key_from( $key_data, $mk );
		if ( false === $ak ) {
			$zip->close();
			return self::error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), 422 );
		}

		// One restore at a time per person: a new one closes the old one.
		$user_id = (int) get_current_user_id();
		foreach ( self::registry() as $old_id => $info ) {
			if ( (int) $info['user'] === $user_id ) {
				self::close( (string) $old_id, $path ); // The file we are opening now must survive.
			}
		}

		$session_id = bin2hex( random_bytes( 16 ) );
		$rk         = random_bytes( 32 );
		$dir        = self::session_dir( $session_id );
		if ( ! wp_mkdir_p( $dir ) ) {
			$zip->close();
			return self::error( 'ftnc_restore_write_failed', __( 'The server could not store the backup file.', 'eleva-crm-for-photographers' ), 500 );
		}

		$plain  = $dir . '/data.json';
		$failed = null;
		try {
			$in  = $zip->getStream( 'data.enc' );
			$out = fopen( $plain, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- own private folder.
			if ( ! is_resource( $in ) || false === $out ) {
				throw new \RuntimeException( 'Could not read data.enc.' );
			}
			try {
				Fotonic_Backup_Cipher::decrypt_stream( $in, $out, $ak );
			} finally {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			$index = Fotonic_Restore_Splitter::split( $plain, $dir, function ( string $raw ) use ( $rk ): string {
				return self::seal( $raw, $rk );
			} );
		} catch ( \Throwable $e ) {
			$index = new \WP_Error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), array( 'status' => 422, 'detail' => $e->getMessage() ) );
		}
		if ( file_exists( $plain ) ) {
			@unlink( $plain ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- the readable copy never stays.
		}
		$zip->close();

		if ( ! is_wp_error( $index ) && (int) $index['format'] !== (int) $manifest['format'] ) {
			$index = new \WP_Error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), array( 'status' => 422 ) );
		}
		if ( is_wp_error( $index ) ) {
			self::remove_tree( $dir );
			self::memzero( $ak );
			$data           = (array) $index->get_error_data();
			$data['status'] = isset( $data['status'] ) ? $data['status'] : 422;
			return new \WP_Error( $index->get_error_code(), $index->get_error_message(), $data );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- own private folder.
		file_put_contents( $dir . '/options.enc', self::seal( (string) wp_json_encode( $index['options'] ), $rk ) );

		$wrap = Fotonic_Crypto::wrap( $mk . $ak, $rk );
		self::memzero( $ak );
		if ( '' === $wrap ) {
			self::remove_tree( $dir );
			return self::error( 'ftnc_restore_write_failed', __( 'The server could not store the backup file.', 'eleva-crm-for-photographers' ), 500 );
		}

		$collections = array();
		foreach ( $index['collections'] as $c ) {
			$collections[] = array(
				'id'    => $c['id'],
				'kind'  => $c['kind'],
				'key'   => $c['key'],
				'file'  => $c['file'],
				'count' => $c['count'],
			);
		}
		$now = time();
		update_option(
			self::OPTION_PREFIX . $session_id,
			array(
				'created'     => $now,
				'user_id'     => $user_id,
				'path'        => $path,
				'same_vault'  => $same_vault,
				'wrap'        => $wrap,
				'manifest'    => $summary,
				'collections' => $collections,
			),
			false
		);
		$registry                = self::registry();
		$registry[ $session_id ] = array( 'user' => $user_id, 'created' => $now );
		update_option( self::OPTION_REGISTRY, $registry, false );
		self::emit_cookie( $session_id . '.' . Fotonic_Vault_Fingerprint::b64u_encode( $rk ), $now + self::TTL );
		self::memzero( $rk );
		Fotonic_Restore_Upload::schedule_cleanup();

		return array(
			'session_id'  => $session_id,
			'same_vault'  => $same_vault,
			'manifest'    => $summary,
			'collections' => self::public_collections( $collections ),
			'expires_at'  => $now + self::TTL,
		);
	}

	// ---------------------------------------------------------------------------
	// Reading the working copy
	// ---------------------------------------------------------------------------

	/** The collections of an open session, with their record counts. */
	public static function collections( string $session_id ) {
		$s = self::session( $session_id );
		return is_wp_error( $s ) ? $s : self::public_collections( $s['collections'] );
	}

	/** Only the datasets (post types), as key => record count. */
	public static function datasets( string $session_id ) {
		$s = self::session( $session_id );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$out = array();
		foreach ( $s['collections'] as $c ) {
			if ( 'datasets' === $c['kind'] ) {
				$out[ $c['key'] ] = $c['count'];
			}
		}
		return $out;
	}

	/** The non-personal summary of the archive (what the manifest says). */
	public static function manifest( string $session_id ) {
		$s = self::session( $session_id );
		return is_wp_error( $s ) ? $s : $s['manifest'];
	}

	/**
	 * A batch of records of one collection, decoded. Personal fields are still encrypted with the
	 * old Master Key (see old_master_key()): nothing is decrypted here.
	 *
	 * @param string $collection_id An id from collections(): 'datasets:<dataset>', 'terms:<taxonomy>' or 'attachments'.
	 * @return array|\WP_Error List of records (arrays); empty past the end.
	 */
	public static function records( string $session_id, string $collection_id, int $offset, int $size ) {
		$s = self::session( $session_id );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$rk = self::session_key( $session_id );
		if ( is_wp_error( $rk ) ) {
			return $rk;
		}
		$found = null;
		foreach ( $s['collections'] as $c ) {
			if ( $c['id'] === $collection_id ) {
				$found = $c;
				break;
			}
		}
		if ( null === $found ) {
			return self::error( 'ftnc_restore_unknown_collection', __( 'This backup has no such data.', 'eleva-crm-for-photographers' ), 404 );
		}
		$size = max( 1, min( self::MAX_PAGE, $size ) );
		if ( $offset < 0 || $offset >= $found['count'] ) {
			return array();
		}

		$dir = self::session_dir( $session_id );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$idx = fopen( $dir . '/' . $found['file'] . '.idx', 'rb' );
		$jl  = fopen( $dir . '/' . $found['file'] . '.jsonl', 'rb' );
		if ( false === $idx || false === $jl ) {
			return self::error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), 422 );
		}
		fseek( $idx, $offset * 8 );
		$start = unpack( 'J', (string) fread( $idx, 8 ) );
		fclose( $idx );
		fseek( $jl, $start ? $start[1] : 0 );
		$out = array();
		while ( count( $out ) < $size && false !== ( $line = fgets( $jl ) ) ) {
			$record = self::unseal( rtrim( $line, "\r\n" ), $rk );
			if ( null === $record ) {
				fclose( $jl );
				return self::error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), 422 );
			}
			$out[] = $record;
		}
		fclose( $jl );
		// phpcs:enable
		return $out;
	}

	/** The archive's allowlisted options (name => value), as written by the backup. */
	public static function options( string $session_id ) {
		$s = self::session( $session_id );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$rk = self::session_key( $session_id );
		if ( is_wp_error( $rk ) ) {
			return $rk;
		}
		$raw = @file_get_contents( self::session_dir( $session_id ) . '/options.enc' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a missing file is reported below.
		$opt = false === $raw ? null : self::unseal( trim( $raw ), $rk );
		return is_array( $opt ) ? $opt : self::error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), 422 );
	}

	/**
	 * The Master Key of the Vault that made the archive, for decrypting personal fields. Held in
	 * memory by the caller for one request only; never store it anywhere.
	 *
	 * @return string|\WP_Error 32 raw bytes.
	 */
	public static function old_master_key( string $session_id ) {
		$k = self::unwrap_session( $session_id );
		return is_wp_error( $k ) ? $k : $k['mk'];
	}

	/** The archive key, for decrypting attachments (files/N.enc). Same care as old_master_key(). */
	public static function archive_key( string $session_id ) {
		$k = self::unwrap_session( $session_id );
		return is_wp_error( $k ) ? $k : $k['ak'];
	}

	/**
	 * Private folder of an open session (removed with it by close() and cleanup_all()). The preview keeps
	 * its plan files there: record ids and statuses only, never a decrypted value.
	 *
	 * @return string|\WP_Error
	 */
	public static function work_dir( string $session_id ) {
		$s = self::session( $session_id );
		return is_wp_error( $s ) ? $s : self::session_dir( $session_id );
	}

	/**
	 * True while a session exists and has not run out, whoever opened it. The job of a restore is shared by the whole
	 * site, but a session answers only to the person (and browser) that opened it: a request from anyone else must not
	 * take the restore for finished.
	 */
	public static function session_alive( string $session_id ): bool {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $session_id ) ) {
			return false;
		}
		$s = get_option( self::OPTION_PREFIX . $session_id, null );
		return is_array( $s ) && isset( $s['created'] ) && ( time() - (int) $s['created'] ) <= self::TTL;
	}

	/**
	 * Working folder of a live session, whoever opened it ('' when the session is gone). The job lock lives there: a
	 * pause or a resume from another person's browser must wait for a running batch like any other request.
	 */
	public static function shared_dir( string $session_id ): string {
		if ( ! self::session_alive( $session_id ) ) {
			return '';
		}
		$dir = self::session_dir( $session_id );
		return is_dir( $dir ) ? $dir : '';
	}

	/**
	 * Closes every session that no unfinished restore needs (the backup was opened but nothing is being written): the
	 * old Master Key of such a session must not outlive the Lock button. A session a running, paused or failed job
	 * still needs stays, so a restore interrupted by a lock goes on after the next unlock. Returns how many were closed.
	 */
	public static function close_unused(): int {
		$closed = 0;
		foreach ( array_keys( self::registry() ) as $id ) {
			if ( Fotonic_Restore_Job::uses_session( (string) $id ) ) {
				continue;
			}
			self::close( (string) $id );
			++$closed;
		}
		return $closed;
	}

	/** True when an open session reads this archive file (an upload must not be cleaned away under it). */
	public static function path_in_use( string $path ): bool {
		foreach ( array_keys( self::registry() ) as $id ) {
			$s = get_option( self::OPTION_PREFIX . $id, null );
			if ( is_array( $s ) && isset( $s['path'], $s['created'] ) && (string) $s['path'] === $path && ( time() - (int) $s['created'] ) <= self::TTL ) {
				return true;
			}
		}
		return false;
	}

	/** Path of the archive file this session reads (attachments are streamed from it). */
	public static function source_path( string $session_id ) {
		$s = self::session( $session_id );
		return is_wp_error( $s ) ? $s : $s['path'];
	}

	/** True when this session was opened with the unlocked Vault of this site (same Vault). */
	public static function is_same_vault( string $session_id ): bool {
		$s = self::session( $session_id );
		return ! is_wp_error( $s ) && ! empty( $s['same_vault'] );
	}

	/**
	 * Check the SHA-256 and size of one archive member against the manifest.
	 *
	 * @return true|\WP_Error
	 */
	public static function verify_member( string $session_id, string $name ) {
		$s = self::session( $session_id );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $s['path'] ) ) {
			return self::error( 'ftnc_restore_bad_file', __( 'The backup file is no longer there. Upload it again.', 'eleva-crm-for-photographers' ), 410 );
		}
		$manifest = self::read_json_member( $zip, 'manifest.json', self::MAX_MANIFEST );
		$result   = true;
		if ( ! is_array( $manifest ) || ! preg_match( self::MEMBER_PATTERN, $name ) ) {
			$result = self::error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), 422 );
		} else {
			$result = self::verify_one( $zip, $manifest, $name );
		}
		$zip->close();
		return $result;
	}

	/**
	 * End a restore: working copy, temporary keys, the uploaded archive and the cookie. Safe to repeat.
	 *
	 * @param string $keep_path An archive path that must not be deleted (the one being opened again).
	 */
	public static function close( string $session_id, string $keep_path = '' ): void {
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $session_id ) ) {
			return;
		}
		$opt  = get_option( self::OPTION_PREFIX . $session_id, null );
		$path = is_array( $opt ) && isset( $opt['path'] ) ? (string) $opt['path'] : '';
		self::remove_tree( self::session_dir( $session_id ) );
		delete_option( self::OPTION_PREFIX . $session_id );
		$registry = self::registry();
		if ( isset( $registry[ $session_id ] ) ) {
			unset( $registry[ $session_id ] );
			update_option( self::OPTION_REGISTRY, $registry, false );
		}
		// An archive that came from the upload folder goes with the session; one in the backups folder stays.
		$upload_id = '' !== $path ? Fotonic_Restore_Upload::id_of_path( $path ) : '';
		if ( '' !== $upload_id && $path !== $keep_path ) {
			Fotonic_Restore_Upload::discard( $upload_id );
		}
		if ( self::cookie_session_id() === $session_id ) {
			self::emit_cookie( '', time() - 3600 );
		}
	}

	/**
	 * Remove sessions and uploads older than TTL, and working folders nobody owns. Cron entry point.
	 *
	 * @return int Sessions removed.
	 */
	public static function cleanup_all(): int {
		Fotonic_Restore_Upload::cleanup();
		$removed  = 0;
		$now      = time();
		$registry = self::registry();
		foreach ( $registry as $id => $info ) {
			if ( $now - (int) $info['created'] > self::TTL || ! is_array( get_option( self::OPTION_PREFIX . $id, null ) ) ) {
				self::close( (string) $id );
				$removed++;
			}
		}
		$registry = self::registry();
		foreach ( (array) glob( Fotonic_Restore_Upload::restore_dir() . '/s-*', GLOB_ONLYDIR ) as $folder ) {
			if ( 1 === preg_match( '/^s-([a-f0-9]{32})$/', basename( $folder ), $m ) && ! isset( $registry[ $m[1] ] ) ) {
				self::remove_tree( $folder );
			}
		}
		if ( ! empty( $registry ) || ! empty( glob( Fotonic_Restore_Upload::restore_dir() . '/up-*.json' ) ) ) {
			Fotonic_Restore_Upload::schedule_cleanup();
		}
		return $removed;
	}

	/** Ends every restore and removes every upload (plugin deactivation). */
	public static function purge_all(): void {
		foreach ( array_keys( self::registry() ) as $id ) {
			self::close( (string) $id );
		}
		foreach ( (array) glob( Fotonic_Restore_Upload::restore_dir() . '/up-*.json' ) as $meta ) {
			Fotonic_Restore_Upload::discard( substr( basename( $meta ), 3, 32 ) );
		}
		Fotonic_Restore_Upload::cleanup();
		foreach ( (array) glob( Fotonic_Restore_Upload::restore_dir() . '/s-*', GLOB_ONLYDIR ) as $folder ) {
			self::remove_tree( $folder );
		}
	}

	// ---------------------------------------------------------------------------
	// Archive checks
	// ---------------------------------------------------------------------------

	/**
	 * Structure and integrity of the archive, before any key is involved.
	 *
	 * @return array|\WP_Error array( manifest, key_data )
	 */
	private static function check_archive( \ZipArchive $zip ) {
		$damaged = self::error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), 422 );

		// A zip with none of the backup's own files is simply not a backup: say so, not "damaged".
		if ( false === $zip->locateName( 'manifest.json' ) || false === $zip->locateName( 'key.json' ) || false === $zip->locateName( 'data.enc' ) ) {
			return self::error( 'ftnc_restore_not_a_backup', __( 'This file is not an Eleva CRM backup, or it is incomplete.', 'eleva-crm-for-photographers' ), 422 );
		}

		// Nothing but the four kinds of member the backup writes, and no path tricks.
		$names = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false === $name ) {
				return $damaged;
			}
			if ( '/' === substr( $name, -1 ) && false === strpos( $name, '..' ) ) {
				continue; // A folder entry is harmless.
			}
			if ( $i >= self::MAX_MEMBERS || 1 !== preg_match( self::MEMBER_PATTERN, $name ) ) {
				return $damaged;
			}
			$names[ $name ] = true;
		}
		if ( ! isset( $names['manifest.json'], $names['key.json'], $names['data.enc'] ) ) {
			return self::error( 'ftnc_restore_not_a_backup', __( 'This file is not an Eleva CRM backup, or it is incomplete.', 'eleva-crm-for-photographers' ), 422 );
		}

		$manifest = self::read_json_member( $zip, 'manifest.json', self::MAX_MANIFEST );
		if ( ! is_array( $manifest ) || ! isset( $manifest['format'], $manifest['members'] ) || ! is_array( $manifest['members'] ) ) {
			return $damaged;
		}
		$format = (int) $manifest['format'];
		if ( $format > Fotonic_Backup_Archive::FORMAT_VERSION ) {
			return self::error( 'ftnc_restore_newer_format', __( 'This backup was made by a newer version of Eleva CRM. Update the plugin, then try again.', 'eleva-crm-for-photographers' ), 422 );
		}
		if ( Fotonic_Backup_Archive::FORMAT_VERSION !== $format ) {
			return $damaged;
		}

		$listed = array();
		foreach ( $manifest['members'] as $member ) {
			if ( ! is_array( $member ) || ! isset( $member['name'], $member['sha256'], $member['size'] ) || ! is_string( $member['name'] )
				|| 1 !== preg_match( self::MEMBER_PATTERN, $member['name'] ) || 1 !== preg_match( '/^[a-f0-9]{64}$/i', (string) $member['sha256'] ) ) {
				return $damaged;
			}
			$stat = $zip->statName( $member['name'] );
			if ( false === $stat || (int) $stat['size'] !== (int) $member['size'] ) {
				return $damaged;
			}
			$listed[ $member['name'] ] = true;
		}
		foreach ( array_keys( $names ) as $name ) {
			if ( 'manifest.json' !== $name && ! isset( $listed[ $name ] ) ) {
				return $damaged; // Something in the zip that the manifest does not know.
			}
		}
		if ( ! isset( $listed['key.json'], $listed['data.enc'] ) ) {
			return $damaged;
		}
		foreach ( array( 'key.json', 'data.enc' ) as $name ) {
			$ok = self::verify_one( $zip, $manifest, $name );
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}

		$key_data = self::read_json_member( $zip, 'key.json', self::MAX_KEY_JSON );
		if ( ! is_array( $key_data ) ) {
			return $damaged;
		}
		if ( (int) ( $key_data['scheme'] ?? 0 ) > self::SUPPORTED_SCHEME ) {
			return self::error( 'ftnc_restore_newer_format', __( 'This backup was made by a newer version of Eleva CRM. Update the plugin, then try again.', 'eleva-crm-for-photographers' ), 422 );
		}
		if ( self::SUPPORTED_SCHEME !== (int) ( $key_data['scheme'] ?? 0 ) ) {
			return $damaged;
		}
		foreach ( array( 'sealed_ak', 'backup_pubkey', 'backup_wrap_priv' ) as $field ) {
			if ( empty( $key_data[ $field ] ) || ! is_string( $key_data[ $field ] ) ) {
				return $damaged;
			}
		}
		$pub    = base64_decode( $key_data['backup_pubkey'], true );
		$sealed = base64_decode( $key_data['sealed_ak'], true );
		if ( false === $pub || 32 !== strlen( $pub ) || false === $sealed || 0 !== strncmp( $key_data['backup_wrap_priv'], 'w1:', 3 ) ) {
			return $damaged;
		}
		if ( empty( $key_data['vault_wrap_pw'] ) && empty( $key_data['vault_wrap_rec'] ) && empty( $key_data['vault_wrap_phrase'] ) ) {
			return $damaged;
		}
		return array( $manifest, $key_data );
	}

	/** SHA-256 and size of one member against the manifest entry. */
	private static function verify_one( \ZipArchive $zip, array $manifest, string $name ) {
		$entry = null;
		foreach ( $manifest['members'] as $member ) {
			if ( is_array( $member ) && isset( $member['name'] ) && $member['name'] === $name ) {
				$entry = $member;
				break;
			}
		}
		$stat = $zip->statName( $name );
		if ( null === $entry || false === $stat || (int) $stat['size'] !== (int) $entry['size'] ) {
			return self::error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), 422 );
		}
		$stream = $zip->getStream( $name );
		if ( ! is_resource( $stream ) ) {
			return self::error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), 422 );
		}
		$ctx = hash_init( 'sha256' );
		while ( ! feof( $stream ) ) {
			$block = fread( $stream, 1048576 );
			if ( false === $block ) {
				break;
			}
			hash_update( $ctx, $block );
		}
		fclose( $stream ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! hash_equals( strtolower( (string) $entry['sha256'] ), hash_final( $ctx ) ) ) {
			return self::error( 'ftnc_restore_damaged', __( 'The backup is damaged: a part of it does not match its checksum.', 'eleva-crm-for-photographers' ), 422 );
		}
		return true;
	}

	/** A small JSON member, size-capped so a hostile archive cannot make us allocate gigabytes. */
	private static function read_json_member( \ZipArchive $zip, string $name, int $cap ) {
		$stat = $zip->statName( $name );
		if ( false === $stat || (int) $stat['size'] > $cap ) {
			return null;
		}
		$raw = $zip->getFromName( $name );
		return false === $raw ? null : json_decode( $raw, true );
	}

	/** What the screen may show before anything is decrypted: counts and versions, no personal data. */
	private static function summarize( array $manifest ): array {
		return array(
			'generated_at'   => isset( $manifest['generated_at'] ) ? (string) $manifest['generated_at'] : '',
			'site_url'       => isset( $manifest['site_url'] ) ? (string) $manifest['site_url'] : '',
			'wp_version'     => isset( $manifest['wp_version'] ) ? (string) $manifest['wp_version'] : '',
			'plugin_version' => isset( $manifest['plugin_version'] ) ? (string) $manifest['plugin_version'] : '',
			'pro_version'    => isset( $manifest['pro_version'] ) ? (string) $manifest['pro_version'] : '',
			'datasets'       => isset( $manifest['datasets'] ) && is_array( $manifest['datasets'] ) ? $manifest['datasets'] : array(),
			'total_size'     => isset( $manifest['total_size'] ) ? (int) $manifest['total_size'] : 0,
			'missing_files'  => isset( $manifest['missing_files'] ) && is_array( $manifest['missing_files'] ) ? count( $manifest['missing_files'] ) : 0,
		);
	}

	// ---------------------------------------------------------------------------
	// Keys
	// ---------------------------------------------------------------------------

	/**
	 * Master Key of the archive's Vault: from this site's unlocked Vault when it is the same one,
	 * otherwise from a credential.
	 *
	 * @return array|\WP_Error array( mk, same_vault )
	 */
	private static function unlock( array $key_data, ?string $secret ) {
		$secret = null === $secret ? '' : trim( $secret );

		if ( '' === $secret ) {
			$site_mk = class_exists( 'Fotonic_Vault' ) ? Fotonic_Vault::get_session_key() : null;
			if ( is_string( $site_mk ) && 32 === strlen( $site_mk ) && false !== Fotonic_Crypto::unwrap( $key_data['backup_wrap_priv'], $site_mk ) ) {
				return array( $site_mk, true );
			}
			return self::error(
				'ftnc_restore_credentials_needed',
				__( 'To open this backup, enter the password, the recovery code or the recovery phrase of the Vault that made it.', 'eleva-crm-for-photographers' ),
				401,
				array( 'vault_unlocked' => is_string( $site_mk ) )
			);
		}

		$attempts = Fotonic_Vault_Attempts::acquire( 'restore' );
		if ( null === $attempts ) {
			return self::error( 'ftnc_restore_busy', __( 'Another attempt is in progress. Wait a few seconds and try again.', 'eleva-crm-for-photographers' ), 429 );
		}
		if ( $attempts->exhausted() ) {
			return self::error( 'ftnc_restore_locked', __( 'Too many wrong attempts. Try again in 15 minutes.', 'eleva-crm-for-photographers' ), 429 );
		}

		// The salt is the base64 text as stored, not its decoded bytes (the Vault does the same).
		$candidates = array();
		if ( ! empty( $key_data['vault_salt'] ) && ! empty( $key_data['vault_wrap_pw'] ) ) {
			$candidates[] = array( $secret, (string) $key_data['vault_salt'], (string) $key_data['vault_wrap_pw'] );
		}
		$normalized = Fotonic_Crypto::normalize_recovery_code( $secret );
		if ( '' !== $normalized ) {
			if ( ! empty( $key_data['vault_salt_phrase'] ) && ! empty( $key_data['vault_wrap_phrase'] ) ) {
				$candidates[] = array( $normalized, (string) $key_data['vault_salt_phrase'], (string) $key_data['vault_wrap_phrase'] );
			}
			if ( ! empty( $key_data['vault_salt_rec'] ) && ! empty( $key_data['vault_wrap_rec'] ) ) {
				$candidates[] = array( $normalized, (string) $key_data['vault_salt_rec'], (string) $key_data['vault_wrap_rec'] );
			}
		}
		foreach ( $candidates as $c ) {
			$mk = Fotonic_Crypto::unwrap( $c[2], Fotonic_Crypto::derive_key( $c[0], $c[1] ) );
			if ( false === $mk || 32 !== strlen( $mk ) ) {
				continue;
			}
			// The Master Key must also open the archive's own key, or this archive is internally inconsistent.
			if ( false === Fotonic_Crypto::unwrap( $key_data['backup_wrap_priv'], $mk ) ) {
				return self::error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), 422 );
			}
			// No clear() here: opening a backup of your own with a known password must not reset the count of wrong tries.
			return array( $mk, false );
		}
		$attempts->failed();
		return self::error( 'ftnc_restore_wrong_credentials', __( 'That password, recovery code or recovery phrase does not open this backup.', 'eleva-crm-for-photographers' ), 401 );
	}

	/** Archive key (AK) from the sealed box in key.json; false when it does not open. */
	private static function archive_key_from( array $key_data, string $mk ) {
		$priv = Fotonic_Crypto::unwrap( $key_data['backup_wrap_priv'], $mk );
		if ( false === $priv || 32 !== strlen( $priv ) ) {
			return false;
		}
		$pub     = base64_decode( $key_data['backup_pubkey'], true );
		$sealed  = base64_decode( $key_data['sealed_ak'], true );
		$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey( $priv, $pub );
		$ak      = sodium_crypto_box_seal_open( $sealed, $keypair );
		self::memzero( $priv );
		self::memzero( $keypair );
		return ( false === $ak || 32 !== strlen( $ak ) ) ? false : $ak;
	}

	/** @return array|\WP_Error array( mk, ak ) */
	private static function unwrap_session( string $session_id ) {
		$s = self::session( $session_id );
		if ( is_wp_error( $s ) ) {
			return $s;
		}
		$rk = self::session_key( $session_id );
		if ( is_wp_error( $rk ) ) {
			return $rk;
		}
		$both = Fotonic_Crypto::unwrap( (string) $s['wrap'], $rk );
		if ( false === $both || 64 !== strlen( $both ) ) {
			return self::error( 'ftnc_restore_session_key_missing', __( 'This restore can no longer continue. Open the backup again.', 'eleva-crm-for-photographers' ), 409 );
		}
		return array( 'mk' => substr( $both, 0, 32 ), 'ak' => substr( $both, 32, 32 ) );
	}

	/** The per-session key from the cookie, only when the cookie belongs to this session. */
	private static function session_key( string $session_id ) {
		$value = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? (string) $_COOKIE[ self::COOKIE_NAME ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- read-only, strictly validated below.
		$parts = explode( '.', $value, 2 );
		if ( 2 === count( $parts ) && hash_equals( $session_id, $parts[0] ) ) {
			$rk = Fotonic_Vault_Fingerprint::b64u_decode( $parts[1] );
			if ( false !== $rk && 32 === strlen( $rk ) ) {
				return $rk;
			}
		}
		return self::error( 'ftnc_restore_session_key_missing', __( 'This restore can no longer continue. Open the backup again.', 'eleva-crm-for-photographers' ), 409 );
	}

	private static function cookie_session_id(): string {
		$value = isset( $_COOKIE[ self::COOKIE_NAME ] ) ? (string) $_COOKIE[ self::COOKIE_NAME ] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.NonceVerification.Recommended -- compared with a stored id only.
		$parts = explode( '.', $value, 2 );
		return ( 2 === count( $parts ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $parts[0] ) ) ? $parts[0] : '';
	}

	private static function emit_cookie( string $value, int $expires ): void {
		if ( ! headers_sent() ) {
			setcookie(
				self::COOKIE_NAME,
				$value,
				array(
					'expires'  => $expires,
					'path'     => defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) && COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
					'secure'   => function_exists( 'is_ssl' ) && is_ssl(),
					'httponly' => true,
					'samesite' => 'Strict',
				)
			);
		}
		if ( '' === $value ) {
			unset( $_COOKIE[ self::COOKIE_NAME ] );
		} else {
			$_COOKIE[ self::COOKIE_NAME ] = $value;
		}
	}

	// ---------------------------------------------------------------------------
	// Sealing of the working copy
	// ---------------------------------------------------------------------------

	/** One record, or the options, as base64( iv | tag | AES-256-GCM ciphertext ): one line, no line breaks. */
	private static function seal( string $plain, string $rk ): string {
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, self::GCM, $rk, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $ct ) {
			throw new \RuntimeException( 'Could not seal a record.' );
		}
		return base64_encode( $iv . $tag . $ct );
	}

	/** The decoded record, or null when the line is damaged or the key is wrong. */
	private static function unseal( string $line, string $rk ): ?array {
		$raw = base64_decode( $line, true );
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), self::GCM, $rk, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		if ( false === $plain ) {
			return null;
		}
		$data = json_decode( $plain, true );
		return is_array( $data ) ? $data : null;
	}

	// ---------------------------------------------------------------------------
	// Sessions and files
	// ---------------------------------------------------------------------------

	/** The stored session, when it exists, is not expired and belongs to the signed-in user. */
	private static function session( string $session_id ) {
		$expired = self::error( 'ftnc_restore_session_expired', __( 'This restore has expired. Open the backup again.', 'eleva-crm-for-photographers' ), 410 );
		if ( 1 !== preg_match( '/^[a-f0-9]{32}$/', $session_id ) ) {
			return $expired;
		}
		$s = get_option( self::OPTION_PREFIX . $session_id, null );
		if ( ! is_array( $s ) || ! isset( $s['created'], $s['user_id'], $s['wrap'], $s['collections'], $s['path'] ) ) {
			return $expired;
		}
		if ( time() - (int) $s['created'] > self::TTL ) {
			self::close( $session_id );
			return $expired;
		}
		if ( (int) $s['user_id'] !== (int) get_current_user_id() ) {
			return $expired;
		}
		return $s;
	}

	private static function session_dir( string $session_id ): string {
		return Fotonic_Restore_Upload::restore_dir() . '/s-' . $session_id;
	}

	private static function registry(): array {
		$r = get_option( self::OPTION_REGISTRY, array() );
		return is_array( $r ) ? $r : array();
	}

	/** Collections as the screen needs them: no file names. */
	private static function public_collections( array $collections ): array {
		$out = array();
		foreach ( $collections as $c ) {
			$out[] = array(
				'id'    => $c['id'],
				'kind'  => $c['kind'],
				'key'   => $c['key'],
				'count' => $c['count'],
			);
		}
		return $out;
	}

	/** Deletes a folder and everything in it. Only ever called with a path under the restore folder. */
	private static function remove_tree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			if ( $item->isDir() ) {
				@rmdir( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort, own private folder.
			} else {
				@unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- best effort, own private folder.
			}
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- best effort, own private folder.
	}

	private static function error( string $code, string $message, int $status, array $extra = array() ): \WP_Error {
		return new \WP_Error( $code, $message, array_merge( array( 'status' => $status ), $extra ) );
	}

	private static function memzero( string &$secret ): void {
		if ( function_exists( 'sodium_memzero' ) ) {
			try {
				sodium_memzero( $secret );
				return;
			} catch ( \Throwable $e ) {
				unset( $e ); // WordPress's sodium_compat (no libsodium extension on the server) cannot wipe memory and throws.
			}
		}
		$secret = str_repeat( "\0", strlen( $secret ) );
	}
}
