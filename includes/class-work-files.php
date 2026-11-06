<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Documents attached to a work (contracts, invoices, quotes): kept in a private folder, under the Vault layer
 * when a Vault is set up, and shown or downloaded only through the plugin (vault-download).
 *
 * Every document stays a WordPress attachment (its id is what `_ftnc_work_files` and the backup point to). Only
 * the bytes and the place of the name change:
 *   - file: `uploads/fotonic/work-files/<24 random characters>.<ext>.enc` (FTNCFIL1, see Fotonic_File_Crypt), or
 *     `<random>.<ext>` on a site with no Vault. The extension is kept so a restore can check the type. Never a
 *     thumbnail, never wp_generate_attachment_metadata(): a resized copy would be plain.
 *   - name: the attachment's post_title, in the `f1` format of Fotonic_Field_Crypt (plain with no Vault). The name
 *     says too much about the customer to stay readable in the database.
 *   - a file only the person who uploaded it can open until a work lists it (META_PENDING), then dropped after a day.
 *
 * Files of the Pro (quote PDFs in `fotonic/quotes`) and of the media library are never touched by anything here that
 * deletes: is_work_file() is the one test of "this file is ours".
 */
class Fotonic_Work_Files {

	const DIR          = 'fotonic/work-files';
	const META_PENDING = '_ftnc_wf_pending'; // "<user id>:<unix time>" until a work lists the file.
	const META_MOVED   = '_ftnc_wf_moved_to'; // On a library file being moved: the id of its private copy.
	const PENDING_TTL  = 86400;
	const CRON_HOOK    = 'fotonic_work_files_purge'; // Single event, set after an upload, repeated while uploads still wait.

	/** Extensions a work accepts (as in the old picker: images, PDF, Word). */
	private static function types(): array {
		return array(
			'pdf'  => 'application/pdf',
			'doc'  => 'application/msword',
			'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
			'jpg'  => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'jpe'  => 'image/jpeg',
			'png'  => 'image/png',
			'gif'  => 'image/gif',
			'webp' => 'image/webp',
			'heic' => 'image/heic',
			'tif'  => 'image/tiff',
			'tiff' => 'image/tiff',
			'bmp'  => 'image/bmp',
		);
	}

	/** Absolute path of the folder, no trailing slash. */
	public static function dir(): string {
		return rtrim( wp_upload_dir()['basedir'], '/' ) . '/' . self::DIR;
	}

	/**
	 * The folder, created with a deny-all .htaccess and an index.php (put back when missing). Nginx ignores
	 * .htaccess: there the random names are the second line of defence (Site Health tells the owner).
	 *
	 * @return string|\WP_Error Absolute path, no trailing slash.
	 */
	public static function ensure_dir() {
		$dir = self::dir();
		if ( ! wp_mkdir_p( $dir ) || ! wp_is_writable( $dir ) ) {
			return new \WP_Error( 'ftnc_file_write', __( 'The uploads folder is not writable, so the file cannot be saved.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- own fixed path, no user input.
			file_put_contents( $dir . '/.htaccess', "Require all denied\n# Block direct access to Eleva CRM files\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>" );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- own fixed path, no user input.
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/**
	 * Largest file accepted: what the site allows, never above what a restore can handle (the layer works in memory),
	 * and never above what this server's memory can encrypt (the file is held about two times over, plus the rest of
	 * the request): a limit the server cannot honour would end in a white page instead of a message.
	 */
	public static function max_bytes(): int {
		return min( Fotonic_Uploads::max_file_bytes(), Fotonic_Restore_Files::MAX_PROTECTED, self::memory_cap() );
	}

	/** Bytes of file the PHP memory limit leaves room for (a very large number when the limit is off). */
	public static function memory_cap(): int {
		$raw = trim( (string) ini_get( 'memory_limit' ) );
		if ( '' === $raw || '-1' === $raw ) {
			return PHP_INT_MAX;
		}
		$unit  = strtolower( substr( $raw, -1 ) );
		$limit = (int) $raw;
		if ( 'g' === $unit ) {
			$limit *= 1073741824;
		} elseif ( 'm' === $unit ) {
			$limit *= 1048576;
		} elseif ( 'k' === $unit ) {
			$limit *= 1024;
		}
		if ( $limit <= 0 ) {
			return PHP_INT_MAX;
		}
		return max( 1048576, (int) floor( ( $limit - memory_get_usage( true ) - 24 * 1048576 ) / 2.5 ) );
	}

	/**
	 * @return array|false array( 'ext' => string, 'type' => string ), or false when the name is not a type we accept.
	 */
	public static function allowed_type( string $name ) {
		$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
		$all = self::types();
		return isset( $all[ $ext ] ) ? array( 'ext' => $ext, 'type' => $all[ $ext ] ) : false;
	}

	/** True when the first bytes of the file are what its extension says (a script renamed to .pdf is refused). */
	public static function content_matches( string $path, string $ext ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- reads a few bytes of an upload.
		$h = is_readable( $path ) ? fopen( $path, 'rb' ) : false;
		if ( false === $h ) {
			return false;
		}
		$head = (string) fread( $h, 16 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		switch ( strtolower( $ext ) ) {
			case 'pdf':
				return 0 === strncmp( $head, '%PDF', 4 );
			case 'doc':
				return 0 === strncmp( $head, "\xD0\xCF\x11\xE0", 4 );
			case 'docx':
				return 0 === strncmp( $head, 'PK', 2 );
			case 'jpg':
			case 'jpeg':
			case 'jpe':
				return 0 === strncmp( $head, "\xFF\xD8\xFF", 3 );
			case 'png':
				return 0 === strncmp( $head, "\x89PNG", 4 );
			case 'gif':
				return 0 === strncmp( $head, 'GIF8', 4 );
			case 'webp':
				return 0 === strncmp( $head, 'RIFF', 4 ) && 'WEBP' === substr( $head, 8, 4 );
			case 'heic':
				return 'ftyp' === substr( $head, 4, 4 );
			case 'tif':
			case 'tiff':
				return 0 === strncmp( $head, "II*\x00", 4 ) || 0 === strncmp( $head, "MM\x00*", 4 );
			case 'bmp':
				return 0 === strncmp( $head, 'BM', 2 );
		}
		return false;
	}

	/** A new file name for the folder: random, with the real extension, `.enc` added when the Vault layer is used. */
	public static function stored_basename( string $ext, bool $encrypted ): string {
		return wp_generate_password( 24, false, false ) . '.' . strtolower( $ext ) . ( $encrypted ? Fotonic_File_Crypt::SUFFIX : '' );
	}

	/** True when the attachment's file lives in our private folder. */
	public static function is_work_file( int $attachment_id ): bool {
		if ( $attachment_id <= 0 ) {
			return false;
		}
		$rel = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		return 0 === strpos( $rel, self::DIR . '/' );
	}

	/**
	 * The name to show or to download.
	 *
	 * @param string|null $key Master Key or null.
	 * @return string|null The name; null when it is encrypted and cannot be read now (never ''.)
	 */
	public static function name_of( int $attachment_id, ?string $key ): ?string {
		$file = (string) get_attached_file( $attachment_id );
		if ( ! self::is_work_file( $attachment_id ) ) {
			return Fotonic_REST_API::attachment_download_name( $attachment_id, $file ); // A library file keeps its own name.
		}
		$plain = Fotonic_Field_Crypt::read( (string) get_post_field( 'post_title', $attachment_id, 'raw' ), $key );
		if ( null === $plain ) {
			return null;
		}
		return '' !== $plain ? $plain : Fotonic_File_Crypt::plain_name( wp_basename( $file ) );
	}

	/**
	 * The works whose `_ftnc_work_files` lists this attachment (exact id, not a substring of a longer one).
	 *
	 * @return int[]
	 */
	public static function works_using( int $attachment_id ): array {
		global $wpdb;
		// LIKE narrows the candidates; the JSON is decoded to confirm the exact id (1 is not in [10,11]).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- the check must be real-time, a stale answer would be an IDOR.
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_ftnc_work_files' AND meta_value LIKE %s",
			'%' . $wpdb->esc_like( (string) $attachment_id ) . '%'
		) );
		$works = array();
		foreach ( (array) $rows as $row ) {
			if ( in_array( $attachment_id, self::ids_in( (string) $row->meta_value ), true ) ) {
				$works[] = (int) $row->post_id;
			}
		}
		return array_values( array_unique( $works ) );
	}

	/**
	 * The attachment ids a `_ftnc_work_files` value lists (bare ids of the old shape and rows; links have none).
	 *
	 * @return int[]
	 */
	public static function ids_in( string $raw ): array {
		$decoded = '' === $raw ? null : json_decode( $raw, true );
		$ids     = array();
		foreach ( is_array( $decoded ) ? $decoded : array() as $entry ) {
			if ( is_numeric( $entry ) ) {
				$ids[] = (int) $entry;
			} elseif ( is_array( $entry ) && 'link' !== ( $entry['type'] ?? 'attachment' ) && (int) ( $entry['id'] ?? 0 ) > 0 ) {
				$ids[] = (int) $entry['id'];
			}
		}
		return $ids;
	}

	/**
	 * Delete the attachment and its file when it is one of ours and no work lists it any more.
	 * Never touches a library file or a Pro file (a quote PDF).
	 *
	 * @return bool True when it was deleted.
	 */
	public static function release( int $attachment_id ): bool {
		if ( ! self::is_work_file( $attachment_id ) || ! empty( self::works_using( $attachment_id ) ) ) {
			return false;
		}
		return false !== wp_delete_attachment( $attachment_id, true );
	}

	// ---------------------------------------------------------------------------
	// Upload
	// ---------------------------------------------------------------------------

	/**
	 * Store an uploaded document: checked, encrypted when there is a key, registered as a private attachment.
	 *
	 * @param array       $file    One entry of $_FILES (name, tmp_name, size, error).
	 * @param string|null $key     Master Key (Vault set up and unlocked) or null (no Vault).
	 * @param int         $user_id Who uploads (only they can open it until a work lists it).
	 * @return array|\WP_Error array( 'id' => int, 'filename' => string, 'mime' => string )
	 */
	public static function store_upload( array $file, ?string $key, int $user_id ) {
		$tmp = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		if ( ! empty( $file['error'] ) || '' === $tmp || ! is_file( $tmp ) ) {
			return new \WP_Error( 'ftnc_file_upload', __( 'The file could not be uploaded.', 'eleva-crm-for-photographers' ), array( 'status' => 400 ) );
		}
		$size = (int) filesize( $tmp );
		if ( $size <= 0 ) {
			return new \WP_Error( 'ftnc_file_upload', __( 'The file is empty.', 'eleva-crm-for-photographers' ), array( 'status' => 400 ) );
		}
		if ( $size > self::max_bytes() ) {
			return new \WP_Error( 'ftnc_file_too_big', __( 'The file is too big.', 'eleva-crm-for-photographers' ), array( 'status' => 413 ) );
		}
		$name = self::clean_name( (string) ( $file['name'] ?? '' ) );
		$type = self::allowed_type( $name );
		if ( false === $type || ! self::content_matches( $tmp, $type['ext'] ) ) {
			return new \WP_Error( 'ftnc_file_type', __( 'This type of file is not accepted. Use a PDF, a Word document or an image.', 'eleva-crm-for-photographers' ), array( 'status' => 415 ) );
		}
		$title = Fotonic_Field_Crypt::store( $name, $key );
		if ( false === $title ) {
			return new \WP_Error( 'ftnc_file_write', __( 'The file could not be saved.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
		}
		$dir = self::ensure_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$target = $dir . '/' . self::stored_basename( $type['ext'], null !== $key );
		if ( null !== $key ) {
			$ok = Fotonic_File_Crypt::encrypt_file( $tmp, $target, $key );
		} else {
			$ok = copy( $tmp, $target ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- private folder, random name.
		}
		if ( ! $ok ) {
			wp_delete_file( $target );
			return new \WP_Error( 'ftnc_file_write', __( 'The file could not be saved.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
		}
		$id = self::insert_attachment( $target, $type['type'], $title );
		if ( $id <= 0 ) {
			wp_delete_file( $target );
			return new \WP_Error( 'ftnc_file_write', __( 'The file could not be saved.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
		}
		update_post_meta( $id, self::META_PENDING, $user_id . ':' . time() );
		self::schedule_purge( self::PENDING_TTL + 300 );
		return array( 'id' => $id, 'filename' => $name, 'mime' => $type['type'] );
	}

	/**
	 * Register a file already in our folder as a private attachment. 'private': no public attachment page. No
	 * wp_generate_attachment_metadata(): it would write preview images next to it.
	 *
	 * @param string $stored_title The title in its stored form (already f1 or plain).
	 * @return int The attachment id, or 0.
	 */
	public static function insert_attachment( string $path, string $mime, string $stored_title ): int {
		$inserted = wp_insert_attachment(
			wp_slash( array(
				'post_mime_type' => $mime,
				'post_title'     => $stored_title,
				'post_name'      => self::neutral_slug(),
				'post_status'    => 'private',
				'post_author'    => get_current_user_id(),
			) ),
			$path,
			0,
			true
		);
		return is_wp_error( $inserted ) ? 0 : (int) $inserted;
	}

	/** A slug that says nothing about the document (WordPress would derive it from the title). */
	public static function neutral_slug(): string {
		return 'doc-' . bin2hex( random_bytes( 6 ) );
	}

	/** A name from the browser: no path, no tags, no control characters, a sensible length. */
	public static function clean_name( string $name ): string {
		$name = wp_strip_all_tags( wp_basename( str_replace( '\\', '/', $name ) ) );
		$name = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $name );
		$name = trim( $name );
		if ( function_exists( 'mb_substr' ) && mb_strlen( $name ) > 200 ) {
			$ext  = (string) pathinfo( $name, PATHINFO_EXTENSION );
			$name = mb_substr( $name, 0, 190 ) . ( '' !== $ext ? '.' . $ext : '' );
		}
		return '' === $name ? 'document' : $name;
	}

	/**
	 * Can this person open this file now? Yes when a work lists it, or when it is their own upload not yet saved.
	 */
	public static function can_open( int $attachment_id, int $user_id ): bool {
		if ( ! empty( self::works_using( $attachment_id ) ) ) {
			return true;
		}
		$pending = (string) get_post_meta( $attachment_id, self::META_PENDING, true );
		return '' !== $pending && 0 === strpos( $pending, $user_id . ':' );
	}

	/** A work now lists these files: they are no longer "waiting". */
	public static function claim( array $ids ): void {
		foreach ( $ids as $id ) {
			if ( self::is_work_file( (int) $id ) ) {
				delete_post_meta( (int) $id, self::META_PENDING );
			}
		}
	}

	/**
	 * Drop uploads nobody saved into a work within a day (a form that was closed). Run by WP-Cron.
	 *
	 * @return int How many were deleted.
	 */
	public static function purge_pending(): int {
		$ids     = get_posts( array(
			'post_type'      => 'attachment',
			'post_status'    => array( 'private', 'inherit' ),
			'posts_per_page' => 200,
			'fields'         => 'ids',
			'meta_key'       => self::META_PENDING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- a small, indexed-by-key lookup run hourly.
		) );
		$deleted = 0;
		$waiting = false;
		foreach ( $ids as $id ) {
			$parts = explode( ':', (string) get_post_meta( (int) $id, self::META_PENDING, true ) );
			$when  = isset( $parts[1] ) ? (int) $parts[1] : 0;
			if ( ! empty( self::works_using( (int) $id ) ) ) {
				delete_post_meta( (int) $id, self::META_PENDING );
				continue;
			}
			if ( $when > 0 && ( time() - $when ) > self::PENDING_TTL && self::is_work_file( (int) $id ) ) {
				if ( false !== wp_delete_attachment( (int) $id, true ) ) {
					$deleted++;
				}
			} else {
				$waiting = true;
			}
		}
		if ( $waiting ) {
			self::schedule_purge( 3600 );
		}
		return $deleted;
	}

	/** One WP-Cron event, like the restore cleanup: set when an upload waits, never a standing schedule. */
	public static function schedule_purge( int $in ): void {
		if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + $in, self::CRON_HOOK );
		}
	}

	/**
	 * before_delete_post: a work deleted for good takes with it the documents no other work lists. The Bin
	 * (wp_trash_post) does not fire this hook: a work in the Bin keeps its files until it is deleted for good.
	 */
	public static function on_delete_post( $post_id ): void {
		if ( 'ftnc_work' !== get_post_type( (int) $post_id ) ) {
			return;
		}
		$ids = self::ids_in( (string) get_post_meta( (int) $post_id, '_ftnc_work_files', true ) );
		// The work itself is still in the table: list the other works only.
		foreach ( array_unique( $ids ) as $id ) {
			if ( ! self::is_work_file( $id ) ) {
				continue;
			}
			$others = array_diff( self::works_using( $id ), array( (int) $post_id ) );
			if ( empty( $others ) ) {
				wp_delete_attachment( $id, true );
			}
		}
	}

	// ---------------------------------------------------------------------------
	// Existing files: move the library files of the works into the folder, encrypt the plain ones
	// (run by the "encrypt existing data" job, Vault set up and unlocked)
	// ---------------------------------------------------------------------------

	const OPTION_SKIPPED = 'ftnc_work_files_skipped'; // array of { id, why, uses[] }: ids and reasons only, never names.
	const OPTION_SWEEP   = 'ftnc_work_files_sweep';    // library ids whose private copy exists and whose delete is still due.
	const FILE_BUDGET    = 4;                          // Files handled per job call: a batch must end inside the 60 s lock.
	const BYTE_BUDGET    = 20971520;                   // ... or about 20 MB, whichever comes first.

	/** @var int Files and bytes handled since reset_budget(). */
	private static $spent_files = 0;
	private static $spent_bytes = 0;

	/** Start of a job call. */
	public static function reset_budget(): void {
		self::$spent_files = 0;
		self::$spent_bytes = 0;
	}

	/** True once this call has handled enough: the job stops before the next file and comes back to the same work. */
	public static function budget_exhausted(): bool {
		return self::$spent_files >= self::FILE_BUDGET || self::$spent_bytes >= self::BYTE_BUDGET;
	}

	private static function spend( int $bytes ): void {
		self::$spent_files++;
		self::$spent_bytes += $bytes;
	}

	/**
	 * What a work's `_ftnc_work_files` still needs.
	 *
	 * @return array<int,string> attachment id => 'encrypt' (ours, plain file or plain name), 'move' (a library
	 *                          file that can go), or a refusal: 'kept_shared', 'too_big', 'missing', 'type'.
	 *                          Files that need nothing (already encrypted, a Pro file, no file) are left out.
	 */
	public static function classify( int $work_id ): array {
		$out    = array();
		$failed = array();
		foreach ( self::skipped_list() as $row ) {
			$failed[ (int) $row['id'] ] = (string) $row['why'];
		}
		foreach ( array_unique( self::ids_in( (string) get_post_meta( $work_id, '_ftnc_work_files', true ) ) ) as $id ) {
			if ( isset( $failed[ $id ] ) && 'damaged' === $failed[ $id ] ) {
				$out[ $id ] = 'damaged'; // Failed in this job: not counted and not tried again (or the job would never end).
				continue;
			}
			$post = get_post( $id );
			$path = (string) get_attached_file( $id );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				continue;
			}
			if ( self::is_work_file( $id ) ) {
				$title = (string) $post->post_title;
				// An empty name has nothing to protect (a restore leaves it empty when it could not be opened).
				if ( is_file( $path ) && ( ! Fotonic_File_Crypt::is_encrypted( $path ) || ( '' !== $title && ! Fotonic_Field_Crypt::is_encrypted( $title ) ) ) ) {
					$out[ $id ] = ( (int) filesize( $path ) <= Fotonic_Restore_Files::MAX_PROTECTED ) ? 'encrypt' : 'too_big';
				}
				continue;
			}
			$rel = (string) get_post_meta( $id, '_wp_attached_file', true );
			if ( 0 === strpos( $rel, 'fotonic/' ) ) {
				continue; // A file of a plugin folder (a quote PDF of the Pro): not ours to move.
			}
			if ( '' === $path || ! is_file( $path ) ) {
				$out[ $id ] = 'missing';
			} elseif ( false === self::allowed_type( wp_basename( $path ) ) ) {
				$out[ $id ] = 'type';
			} elseif ( (int) filesize( $path ) > self::max_bytes() ) {
				$out[ $id ] = 'too_big';
			} elseif ( ! empty( self::other_uses( $id ) ) ) {
				$out[ $id ] = 'kept_shared';
			} else {
				$out[ $id ] = 'move';
			}
		}
		return $out;
	}

	/**
	 * Analysis (write false): how many files of this work can still be moved or encrypted (the ones that stay in the
	 * library are NOT counted, or the job would never finish). Write: do the work, within the call budget.
	 *
	 * @return int Files that need work (analysis) or that were handled (write).
	 */
	public static function secure_record( int $work_id, string $key, bool $write ): int {
		if ( 32 !== strlen( $key ) ) {
			return 0;
		}
		if ( $write ) {
			self::sweep();
		}
		$todo = 0;
		foreach ( self::classify( $work_id ) as $id => $what ) {
			if ( 'encrypt' !== $what && 'move' !== $what ) {
				if ( $write ) {
					self::note_skipped( (int) $id, $what );
				}
				continue;
			}
			if ( ! $write ) {
				$todo++;
				continue;
			}
			if ( self::budget_exhausted() ) {
				break; // The job comes back to this work on its next call.
			}
			$size   = (int) filesize( (string) get_attached_file( $id ) );
			$result = 'encrypt' === $what ? self::encrypt_in_place( (int) $id, $key ) : self::migrate_attachment( (int) $id, $key );
			self::spend( $size );
			if ( 'encrypted' === $result || 'moved' === $result ) {
				$todo++;
			} else {
				self::note_skipped( (int) $id, $result );
			}
		}
		return $todo;
	}

	/** One of our plain files: encrypt the bytes (new random name, checked) and the name. */
	public static function encrypt_in_place( int $id, string $key ): string {
		$path = (string) get_attached_file( $id );
		if ( ! self::is_work_file( $id ) || ! is_file( $path ) ) {
			return 'missing';
		}
		$repo = Fotonic_Vault_Fields::repo();
		if ( ! Fotonic_File_Crypt::is_encrypted( $path ) ) {
			$ext    = strtolower( (string) pathinfo( Fotonic_File_Crypt::plain_name( wp_basename( $path ) ), PATHINFO_EXTENSION ) );
			$target = self::dir() . '/' . self::stored_basename( '' !== $ext ? $ext : 'bin', true );
			if ( ! Fotonic_File_Crypt::encrypt_file( $path, $target, $key ) ) {
				wp_delete_file( $target );
				return 'damaged';
			}
			update_attached_file( $id, $target );
			wp_delete_file( $path );
		}
		$title  = (string) $repo->get_post_field( $id, 'post_title' );
		$fields = array();
		if ( '' !== $title && ! Fotonic_Field_Crypt::is_encrypted( $title ) ) {
			$plain_title = Fotonic_Field_Crypt::read( $title, null );
			$enc         = Fotonic_Field_Crypt::store( null === $plain_title ? $title : $plain_title, $key );
			if ( false === $enc ) {
				return 'damaged';
			}
			$fields['post_title'] = $enc;
		}
		// WordPress builds the slug from the title: a slug made from the plain name would give it away.
		if ( 0 !== strpos( (string) get_post_field( 'post_name', $id, 'raw' ), 'doc-' ) ) {
			$fields['post_name'] = self::neutral_slug();
		}
		if ( ! empty( $fields ) ) {
			$repo->set_post_fields( $id, $fields );
		}
		return 'encrypted';
	}

	/**
	 * A library file of a work: one encrypted private copy, every work that lists it pointed at the copy, then the
	 * original (and its thumbnails) deleted. Safe to repeat after a stop: the copy is remembered on the original
	 * (META_MOVED) before anything else changes.
	 *
	 * @return string 'moved', or why not: 'kept_shared', 'too_big', 'missing', 'type', 'damaged'.
	 */
	public static function migrate_attachment( int $old, string $key ): string {
		$path = (string) get_attached_file( $old );
		if ( '' === $path || ! is_file( $path ) ) {
			return 'missing';
		}
		$type = self::allowed_type( wp_basename( $path ) );
		if ( false === $type ) {
			return 'type';
		}
		if ( (int) filesize( $path ) > self::max_bytes() ) {
			return 'too_big';
		}
		if ( ! empty( self::other_uses( $old ) ) ) {
			return 'kept_shared';
		}
		$new = (int) get_post_meta( $old, self::META_MOVED, true );
		if ( $new <= 0 || ! self::is_work_file( $new ) || ! is_file( (string) get_attached_file( $new ) ) ) {
			$name   = self::clean_name( wp_basename( $path ) );
			$title  = Fotonic_Field_Crypt::store( $name, $key );
			$ensure = self::ensure_dir();
			if ( false === $title || is_wp_error( $ensure ) ) {
				return 'damaged';
			}
			$target = $ensure . '/' . self::stored_basename( $type['ext'], true );
			if ( ! Fotonic_File_Crypt::encrypt_file( $path, $target, $key ) ) {
				wp_delete_file( $target );
				return 'damaged';
			}
			$check = Fotonic_File_Crypt::decrypt_file( $target, $key );
			if ( ! is_string( $check ) || ! hash_equals( (string) hash_file( 'sha256', $path ), hash( 'sha256', $check ) ) ) {
				wp_delete_file( $target );
				return 'damaged';
			}
			$new = self::insert_attachment( $target, $type['type'], $title );
			if ( $new <= 0 ) {
				wp_delete_file( $target );
				return 'damaged';
			}
			update_post_meta( $old, self::META_MOVED, $new );
		}
		// Due for deletion even if the request dies from here to the end.
		$due   = (array) get_option( self::OPTION_SWEEP, array() );
		$due[] = $old;
		update_option( self::OPTION_SWEEP, array_values( array_unique( array_map( 'intval', $due ) ) ), false );
		foreach ( self::works_using( $old ) as $work ) {
			self::replace_id( $work, $old, $new );
		}
		self::sweep();
		return 'moved';
	}

	/** Point one work's `_ftnc_work_files` at another attachment, keeping the row (its protected label) as it is. */
	private static function replace_id( int $work_id, int $old, int $new ): void {
		$rows = json_decode( (string) get_post_meta( $work_id, '_ftnc_work_files', true ), true );
		if ( ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $i => $row ) {
			if ( is_numeric( $row ) && (int) $row === $old ) {
				$rows[ $i ] = array( 'type' => 'attachment', 'id' => $new, 'label' => '' );
			} elseif ( is_array( $row ) && 'link' !== ( $row['type'] ?? 'attachment' ) && (int) ( $row['id'] ?? 0 ) === $old ) {
				$rows[ $i ]['id'] = $new;
			}
		}
		update_post_meta( $work_id, '_ftnc_work_files', wp_slash( wp_json_encode( array_values( $rows ) ) ) );
	}

	/** Delete the library originals whose private copy exists and that no work lists any more. */
	public static function sweep(): void {
		$due = (array) get_option( self::OPTION_SWEEP, array() );
		if ( empty( $due ) ) {
			return;
		}
		$left = array();
		foreach ( $due as $old ) {
			$old = (int) $old;
			$new = (int) get_post_meta( $old, self::META_MOVED, true );
			if ( null === get_post( $old ) ) {
				continue;
			}
			if ( $new > 0 && self::is_work_file( $new ) && empty( self::works_using( $old ) ) ) {
				wp_delete_attachment( $old, true ); // The file and every thumbnail WordPress made.
			} else {
				$left[] = $old;
			}
		}
		if ( empty( $left ) ) {
			delete_option( self::OPTION_SWEEP );
		} else {
			update_option( self::OPTION_SWEEP, $left, false );
		}
	}

	// ---------------------------------------------------------------------------
	// Files that stay in the library: the list for the owner
	// ---------------------------------------------------------------------------

	/** Remember a file the job left alone (ids and reasons only, once per file). */
	private static function note_skipped( int $id, string $why ): void {
		$list = self::skipped_list();
		foreach ( $list as $row ) {
			if ( (int) $row['id'] === $id ) {
				return;
			}
		}
		$list[] = array(
			'id'   => $id,
			'why'  => $why,
			'uses' => 'kept_shared' === $why ? self::other_uses( $id ) : array(),
		);
		update_option( self::OPTION_SKIPPED, $list, false );
	}

	/** @return array<int,array{id:int,why:string,uses:string[]}> */
	public static function skipped_list(): array {
		$list = get_option( self::OPTION_SKIPPED, array() );
		return is_array( $list ) ? array_values( $list ) : array();
	}

	/** A new job starts: forget the list of the last one. */
	public static function clear_skipped(): void {
		delete_option( self::OPTION_SKIPPED );
	}

	/**
	 * Where an attachment is used outside the files of the works. Each answer is a short code, never a title:
	 * `thumbnail:<post id>`, `post:<post id>` (its text names the file), `meta:<key>` (a plugin record) or
	 * `option:<name>` (a plugin setting such as the logo of the quotes). The Pro can add its own through the
	 * filter `ftnc_work_file_other_uses`.
	 *
	 * @return string[]
	 */
	public static function other_uses( int $id ): array {
		global $wpdb;
		$uses = array();
		$rel  = (string) get_post_meta( $id, '_wp_attached_file', true );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- a one-off lookup by the job, never cached.
		$thumb = $wpdb->get_var( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 1", (string) $id ) );
		if ( null !== $thumb ) {
			$uses[] = 'thumbnail:' . (int) $thumb;
		}
		if ( '' !== $rel ) {
			$post = $wpdb->get_var( $wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type NOT IN ( 'attachment', 'revision' ) AND post_status NOT IN ( 'trash', 'auto-draft' ) AND post_content LIKE %s LIMIT 1",
				'%' . $wpdb->esc_like( $rel ) . '%'
			) );
			if ( null !== $post ) {
				$uses[] = 'post:' . (int) $post;
			}
		}
		// Records and settings of the plugin that hold the id (a number or a list) or the file address.
		$needle = $wpdb->esc_like( (string) $id );
		$metas  = $wpdb->get_results( $wpdb->prepare(
			"SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s AND meta_key NOT IN ( '_ftnc_work_files' ) AND meta_key NOT LIKE %s AND meta_key NOT LIKE %s AND post_id <> %d AND ( meta_value LIKE %s OR meta_value LIKE %s )",
			$wpdb->esc_like( '_ftnc_' ) . '%',
			$wpdb->esc_like( '_ftnc_wf_' ) . '%',
			$wpdb->esc_like( '_ftnc_import_' ) . '%',
			$id,
			'%' . $needle . '%',
			'%' . $wpdb->esc_like( '' !== $rel ? $rel : "\x01" ) . '%'
		) );
		foreach ( (array) $metas as $row ) {
			if ( self::value_names_file( maybe_unserialize( (string) $row->meta_value ), $id, $rel, (string) $row->meta_key ) ) {
				$uses[] = 'meta:' . $row->meta_key;
			}
		}
		$opts = $wpdb->get_results( $wpdb->prepare(
			"SELECT option_name, option_value FROM {$wpdb->options} WHERE ( option_name LIKE %s OR option_name LIKE %s ) AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND option_name NOT LIKE %s AND ( option_value LIKE %s OR option_value LIKE %s )",
			$wpdb->esc_like( 'fotonic_' ) . '%',
			$wpdb->esc_like( 'ftnc_' ) . '%',
			$wpdb->esc_like( 'fotonic_restore_' ) . '%',
			$wpdb->esc_like( 'fotonic_vault_' ) . '%',
			$wpdb->esc_like( 'ftnc_work_files_' ) . '%',
			'%' . $needle . '%',
			'%' . $wpdb->esc_like( '' !== $rel ? $rel : "\x01" ) . '%'
		) );
		// phpcs:enable
		foreach ( (array) $opts as $row ) {
			if ( self::value_names_file( maybe_unserialize( (string) $row->option_value ), $id, $rel, '' ) ) {
				$uses[] = 'option:' . $row->option_name;
			}
		}
		$uses = (array) apply_filters( 'ftnc_work_file_other_uses', array_values( array_unique( $uses ) ), $id );
		return array_values( array_filter( array_map( 'strval', $uses ) ) );
	}

	/**
	 * Does a stored value (any depth) name this file? A string that holds the file's relative path does. A whole
	 * number equal to the id does only under a key that talks about an image (logo, photo, signature, gallery,
	 * attachment, thumbnail, header background) or under an `..._id` key that is not a user or a term: a bare "35"
	 * in a price is not the file.
	 *
	 * @param mixed  $value Decoded value.
	 * @param string $ctx   Key of the parent, for the lists of ids (`images` => [12, 35]).
	 */
	public static function value_names_file( $value, int $id, string $rel, string $ctx ): bool {
		// A whole number counts under a key about an image, or under any `..._id` key that is not a user or a term
		// (posts and attachments share one id space, so such a number can only mean this file). Leaning this way is
		// safe: a false match only keeps the file in the library and lists it.
		$image_key = 1 === preg_match( '/(logo|photo|image|signature|gallery|attachment|thumbnail|header_bg|_bg)/i', $ctx )
			|| ( 1 === preg_match( '/_id$/i', $ctx ) && 1 !== preg_match( '/(owner|user|term)/i', $ctx ) );
		if ( is_array( $value ) ) {
			foreach ( $value as $k => $v ) {
				if ( self::value_names_file( $v, $id, $rel, is_string( $k ) ? $k : $ctx ) ) {
					return true;
				}
			}
			return false;
		}
		if ( is_string( $value ) && '' !== $rel && false !== strpos( $value, $rel ) ) {
			return true;
		}
		if ( is_string( $value ) && '' !== $value && ( '[' === $value[0] || '{' === $value[0] ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				return self::value_names_file( $decoded, $id, $rel, $ctx );
			}
		}
		if ( $image_key && ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) ) {
			return (int) $value === $id;
		}
		return false;
	}
}
