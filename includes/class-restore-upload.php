<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Chunked upload of a backup archive into a private folder, for the restore feature.
 *
 * The browser cuts the file into CHUNK_SIZE pieces (about 2 MB) and sends them one by one, so no
 * PHP upload limit applies and a dropped connection resumes from the first missing piece. The
 * server writes each piece at its own offset, so pieces may arrive in any order or twice.
 *
 * Files live in uploads/fotonic/restore/ (deny-all .htaccess, empty index.php, and on nginx the
 * 128-bit random name is the only protection, exactly like the backups folder):
 *   up-<id>.part   the file being assembled (sparse until complete)
 *   up-<id>.json   bookkeeping: size, expected SHA-256, which pieces arrived, owner, time
 *   up-<id>.zip    the finished archive, after finish() has checked it
 * <id> is 32 hex characters (128 random bits). Nothing here opens the archive: that is
 * Fotonic_Restore_Reader. Everything older than TTL is removed by cleanup() (cron and every start).
 */
final class Fotonic_Restore_Upload {

	const CHUNK_SIZE  = 2097152; // 2 MiB.
	const TTL         = 7200;    // 2 hours.
	const MAX_ACTIVE  = 3;       // Unfinished or unused uploads one site keeps at the same time.
	const DIR_NAME    = 'restore';
	const CRON_HOOK   = 'fotonic_restore_cleanup';

	/**
	 * uploads/fotonic/restore/, created with the same guards as the backups folder. The guards are
	 * checked on every call, so a deleted one comes back.
	 */
	public static function restore_dir(): string {
		$upload_dir = wp_upload_dir();
		$dir        = $upload_dir['basedir'] . '/fotonic/' . self::DIR_NAME;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- own controlled path, no user input.
			file_put_contents( $dir . '/.htaccess', "Require all denied\n# Block direct access to Eleva CRM restore files\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see above.
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/** True for a well-formed upload id (32 lower-case hex characters). */
	public static function valid_id( $id ): bool {
		return is_string( $id ) && 1 === preg_match( '/^[a-f0-9]{32}$/', $id );
	}

	// ---------------------------------------------------------------------------
	// Steps
	// ---------------------------------------------------------------------------

	/**
	 * Begin an upload.
	 *
	 * @param int    $size   File size in bytes.
	 * @param string $sha256 Hex SHA-256 of the whole file, or '' when the browser cannot compute it
	 *                       (a multi-gigabyte file cannot be hashed in one WebCrypto call). Without it
	 *                       the transfer is still protected: the archive's own manifest holds a SHA-256
	 *                       for every part and the Reader checks them.
	 * @return array|\WP_Error array( 'upload_id', 'chunk_size', 'total_chunks' )
	 */
	public static function start( int $size, string $sha256 = '' ) {
		if ( $size < 1 ) {
			return new \WP_Error( 'ftnc_restore_bad_size', __( 'The file is empty.', 'eleva-crm-for-photographers' ), array( 'status' => 400 ) );
		}
		$sha256 = strtolower( trim( $sha256 ) );
		if ( '' !== $sha256 && 1 !== preg_match( '/^[a-f0-9]{64}$/', $sha256 ) ) {
			return new \WP_Error( 'ftnc_restore_bad_hash', __( 'The file checksum is not valid.', 'eleva-crm-for-photographers' ), array( 'status' => 400 ) );
		}

		$dir = self::restore_dir();
		self::cleanup();
		if ( count( self::meta_files( $dir ) ) >= self::MAX_ACTIVE ) {
			return new \WP_Error( 'ftnc_restore_too_many', __( 'Other uploads are still waiting. Finish or cancel them first, or wait a couple of hours.', 'eleva-crm-for-photographers' ), array( 'status' => 409 ) );
		}

		// The archive itself, plus room to open it: the readable copy of its data is written next to it.
		$free = apply_filters( 'ftnc_restore_free_space', @disk_free_space( $dir ), $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- returns false on hosts that block it; then the check is skipped.
		if ( false !== $free && null !== $free && (float) $free < $size * 2 ) {
			return new \WP_Error(
				'ftnc_restore_no_space',
				__( 'There is not enough free space on the server to receive this backup.', 'eleva-crm-for-photographers' ),
				array(
					'status'    => 507,
					'needed'    => $size * 2,
					'available' => (int) $free,
				)
			);
		}

		$id    = bin2hex( random_bytes( 16 ) );
		$total = (int) ceil( $size / self::CHUNK_SIZE );
		$meta  = array(
			'size'    => $size,
			'sha256'  => $sha256,
			'total'   => $total,
			'got'     => str_repeat( '0', $total ),
			'created' => time(),
			'user_id' => (int) get_current_user_id(),
			'state'   => 'uploading',
		);
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- own private folder.
		if ( false === file_put_contents( self::meta_path( $dir, $id ), wp_json_encode( $meta ) ) || false === file_put_contents( self::part_path( $dir, $id ), '' ) ) {
			self::discard( $id );
			return new \WP_Error( 'ftnc_restore_write_failed', __( 'The server could not store the backup file.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
		}
		self::schedule_cleanup();

		return array(
			'upload_id'    => $id,
			'chunk_size'   => self::CHUNK_SIZE,
			'total_chunks' => $total,
		);
	}

	/**
	 * Store one piece. Safe to repeat and to send out of order.
	 *
	 * @return array|\WP_Error array( 'received', 'total_chunks', 'next_missing' ) where next_missing
	 *                         is the first piece not yet stored, or null when all have arrived.
	 */
	public static function chunk( string $upload_id, int $index, string $bytes ) {
		$dir = self::restore_dir();
		$res = self::locked_update( $dir, $upload_id, function ( array $meta ) use ( $dir, $upload_id, $index, $bytes ) {
			if ( 'uploading' !== $meta['state'] ) {
				return new \WP_Error( 'ftnc_restore_upload_done', __( 'This upload is already complete.', 'eleva-crm-for-photographers' ), array( 'status' => 409 ) );
			}
			if ( $index < 0 || $index >= $meta['total'] ) {
				return new \WP_Error( 'ftnc_restore_bad_chunk', __( 'A piece of the file arrived out of range.', 'eleva-crm-for-photographers' ), array( 'status' => 400 ) );
			}
			$expected = ( $index === $meta['total'] - 1 ) ? $meta['size'] - $index * self::CHUNK_SIZE : self::CHUNK_SIZE;
			if ( strlen( $bytes ) !== $expected ) {
				return new \WP_Error( 'ftnc_restore_bad_chunk', __( 'A piece of the file has the wrong size.', 'eleva-crm-for-photographers' ), array( 'status' => 400 ) );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- own private folder.
			$fh = fopen( self::part_path( $dir, $upload_id ), 'c+b' );
			if ( false === $fh ) {
				return new \WP_Error( 'ftnc_restore_write_failed', __( 'The server could not store the backup file.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
			}
			$ok = 0 === fseek( $fh, $index * self::CHUNK_SIZE ) && strlen( $bytes ) === fwrite( $fh, $bytes ) && fflush( $fh );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( ! $ok ) {
				return new \WP_Error( 'ftnc_restore_write_failed', __( 'The server could not store the backup file. The disk may be full.', 'eleva-crm-for-photographers' ), array( 'status' => 507 ) );
			}
			$meta['got'][ $index ] = '1';
			return $meta;
		} );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return self::progress( $res );
	}

	/** Where an upload stands, so a browser that lost its place can resume. */
	public static function status( string $upload_id ) {
		$meta = self::read_meta( self::restore_dir(), $upload_id );
		if ( null === $meta ) {
			return self::not_found();
		}
		return self::progress( $meta );
	}

	/**
	 * Check the assembled file and make it ready to open.
	 *
	 * @return string|\WP_Error Path of the finished archive.
	 */
	public static function finish( string $upload_id ) {
		$dir  = self::restore_dir();
		$meta = self::read_meta( $dir, $upload_id );
		if ( null === $meta ) {
			return self::not_found();
		}
		$final = self::final_path( $dir, $upload_id );
		if ( 'ready' === $meta['state'] && is_file( $final ) ) {
			return $final;
		}
		if ( false !== strpos( $meta['got'], '0' ) ) {
			$first = strpos( $meta['got'], '0' );
			return new \WP_Error( 'ftnc_restore_incomplete', __( 'Some pieces of the file are missing.', 'eleva-crm-for-photographers' ), array( 'status' => 409, 'next_missing' => (int) $first ) );
		}
		$part = self::part_path( $dir, $upload_id );
		clearstatcache( true, $part );
		if ( ! is_file( $part ) || filesize( $part ) !== $meta['size'] ) {
			self::discard( $upload_id );
			return new \WP_Error( 'ftnc_restore_size_mismatch', __( 'The file that arrived has a different size than the one that was sent. Try again.', 'eleva-crm-for-photographers' ), array( 'status' => 422 ) );
		}
		if ( '' !== $meta['sha256'] && ! hash_equals( $meta['sha256'], (string) hash_file( 'sha256', $part ) ) ) {
			self::discard( $upload_id );
			return new \WP_Error( 'ftnc_restore_hash_mismatch', __( 'The file was damaged on the way. Try again.', 'eleva-crm-for-photographers' ), array( 'status' => 422 ) );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- own private folder.
		$fh    = fopen( $part, 'rb' );
		$magic = false === $fh ? '' : (string) fread( $fh, 2 );
		if ( false !== $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( 'PK' !== $magic ) {
			self::discard( $upload_id );
			return new \WP_Error( 'ftnc_restore_not_zip', __( 'This is not a backup file. Pick the .zip file that Eleva CRM created.', 'eleva-crm-for-photographers' ), array( 'status' => 422 ) );
		}
		if ( ! rename( $part, $final ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename -- inside the private folder.
			return new \WP_Error( 'ftnc_restore_write_failed', __( 'The server could not store the backup file.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
		}
		$meta['state'] = 'ready';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- own private folder.
		file_put_contents( self::meta_path( $dir, $upload_id ), wp_json_encode( $meta ) );
		return $final;
	}

	/**
	 * Path of a finished upload, or an error when it is unknown or unfinished. The id is 128 random bits and every restore
	 * route is for administrators, so the upload is not tied to the person who made it: any administrator who holds the
	 * id may open it.
	 */
	public static function ready_path( string $upload_id ) {
		$dir  = self::restore_dir();
		$meta = self::read_meta( $dir, $upload_id );
		if ( null === $meta || 'ready' !== $meta['state'] || ! is_file( self::final_path( $dir, $upload_id ) ) ) {
			return self::not_found();
		}
		return self::final_path( $dir, $upload_id );
	}

	/** Remove every file of one upload. Safe on an unknown id. */
	public static function discard( string $upload_id ): void {
		if ( ! self::valid_id( $upload_id ) ) {
			return;
		}
		$dir = self::restore_dir();
		foreach ( array( self::part_path( $dir, $upload_id ), self::meta_path( $dir, $upload_id ), self::final_path( $dir, $upload_id ) ) as $path ) {
			if ( file_exists( $path ) ) {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- best effort, own private folder.
			}
		}
	}

	/** Upload id that owns this archive path, or '' (a file from the backups folder has none). */
	public static function id_of_path( string $path ): string {
		$dir = self::restore_dir();
		if ( dirname( $path ) === $dir && 1 === preg_match( '/^up-([a-f0-9]{32})\.zip$/', basename( $path ), $m ) ) {
			return $m[1];
		}
		return '';
	}

	/**
	 * Remove uploads older than TTL, and any stray file of ours with no bookkeeping.
	 *
	 * @return int Number of uploads removed.
	 */
	public static function cleanup(): int {
		$dir     = self::restore_dir();
		$removed = 0;
		$now     = time();
		foreach ( self::meta_files( $dir ) as $meta_file ) {
			$id   = substr( basename( $meta_file ), 3, 32 );
			$meta = self::read_meta( $dir, $id );
			if ( null !== $meta && $now - (int) $meta['created'] > self::TTL && class_exists( 'Fotonic_Restore_Reader' ) && Fotonic_Restore_Reader::path_in_use( self::final_path( $dir, $id ) ) ) {
				continue; // An open restore still reads this archive (the upload may be older than the session).
			}
			if ( null === $meta || $now - (int) $meta['created'] > self::TTL ) {
				self::discard( $id );
				$removed++;
			}
		}
		// A part or zip left without its bookkeeping (a crash between two writes).
		foreach ( (array) glob( $dir . '/up-*' ) as $file ) {
			if ( 1 === preg_match( '/^up-([a-f0-9]{32})\.(part|zip)$/', basename( $file ), $m ) && ! file_exists( self::meta_path( $dir, $m[1] ) ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.unlink_unlink -- best effort, own private folder.
			}
		}
		return $removed;
	}

	/** Ask WordPress cron to run the cleanup in an hour. Harmless without cron (tests). */
	public static function schedule_cleanup(): void {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 3600, self::CRON_HOOK );
		}
	}

	// ---------------------------------------------------------------------------
	// Files and bookkeeping
	// ---------------------------------------------------------------------------

	private static function part_path( string $dir, string $id ): string {
		return $dir . '/up-' . $id . '.part';
	}

	private static function meta_path( string $dir, string $id ): string {
		return $dir . '/up-' . $id . '.json';
	}

	private static function final_path( string $dir, string $id ): string {
		return $dir . '/up-' . $id . '.zip';
	}

	/** @return string[] */
	private static function meta_files( string $dir ): array {
		$files = glob( $dir . '/up-*.json' );
		return is_array( $files ) ? $files : array();
	}

	/** The bookkeeping of one upload, or null when it is unknown or damaged. */
	private static function read_meta( string $dir, string $id ): ?array {
		if ( ! self::valid_id( $id ) || ! is_file( self::meta_path( $dir, $id ) ) ) {
			return null;
		}
		$meta = json_decode( (string) file_get_contents( self::meta_path( $dir, $id ) ), true );
		if ( ! is_array( $meta ) || ! isset( $meta['size'], $meta['total'], $meta['got'], $meta['state'], $meta['created'] )
			|| strlen( (string) $meta['got'] ) !== (int) $meta['total'] ) {
			return null;
		}
		$meta['size']  = (int) $meta['size'];
		$meta['total'] = (int) $meta['total'];
		$meta['got']   = (string) $meta['got'];
		return $meta;
	}

	/**
	 * Read, change and write the bookkeeping under a file lock, so two pieces arriving together
	 * cannot overwrite each other's mark.
	 *
	 * @param callable $change function ( array $meta ): array|\WP_Error; an error leaves the file as it was.
	 * @return array|\WP_Error The bookkeeping after the change.
	 */
	private static function locked_update( string $dir, string $id, callable $change ) {
		if ( ! self::valid_id( $id ) || ! is_file( self::meta_path( $dir, $id ) ) ) {
			return self::not_found();
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- own private folder.
		$fh = fopen( self::meta_path( $dir, $id ), 'c+b' );
		if ( false === $fh || ! flock( $fh, LOCK_EX ) ) {
			return new \WP_Error( 'ftnc_restore_write_failed', __( 'The server could not store the backup file.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
		}
		rewind( $fh );
		$meta = json_decode( (string) stream_get_contents( $fh ), true );
		if ( ! is_array( $meta ) || ! isset( $meta['total'], $meta['got'], $meta['size'], $meta['state'] ) || strlen( (string) $meta['got'] ) !== (int) $meta['total'] ) {
			flock( $fh, LOCK_UN );
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return self::not_found();
		}
		$meta['size']  = (int) $meta['size'];
		$meta['total'] = (int) $meta['total'];
		$meta['got']   = (string) $meta['got'];

		$new = call_user_func( $change, $meta );
		if ( is_array( $new ) ) {
			rewind( $fh );
			ftruncate( $fh, 0 );
			fwrite( $fh, wp_json_encode( $new ) );
			fflush( $fh );
		}
		flock( $fh, LOCK_UN );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $new;
	}

	private static function progress( array $meta ): array {
		$next = strpos( $meta['got'], '0' );
		return array(
			'received'     => substr_count( $meta['got'], '1' ),
			'total_chunks' => $meta['total'],
			'next_missing' => false === $next ? null : (int) $next,
			'chunk_size'   => self::CHUNK_SIZE,
		);
	}

	private static function not_found(): \WP_Error {
		return new \WP_Error( 'ftnc_restore_unknown_upload', __( 'This upload was not found. It may have expired. Start again.', 'eleva-crm-for-photographers' ), array( 'status' => 404 ) );
	}
}
