<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Restore, files (phase 10): the media-library files a record points to (the files of a work, and in phase 12 the
 * quote PDFs) are brought back from the backup (`files/N.enc`) and given a new attachment id on this site.
 *
 * One call, `import( $sid, $old_id, $ctx )`, returns `array( id, state )`; `state` is one of
 *   created   a new attachment was made;
 *   reused    an attachment this restore (or an earlier one) already made from the same backup bytes, or a file the
 *             target record already held with the same bytes, is used again: no duplicate;
 *   missing   the backup has no such file (the row is left out and counted);
 *   rejected  a type the site does not accept (a script, an unknown extension) or an unsafe path;
 *   damaged   the member does not match its checksum, does not decrypt or a protected file cannot be opened.
 * Only a lost session or key is an error (WP_Error), because nothing more can be done.
 *
 * Rules:
 *   - the member is checked against the manifest SHA-256 first, then stream-decrypted with the archive key into a
 *     temporary file inside the private session folder (always removed), and checked against the SHA-256 the
 *     attachment record carries;
 *   - a file of a private plugin folder (`fotonic/vault`, Pro's `fotonic/quotes`: the folders Site Health probes, filter
 *     `ftnc_restore_private_dirs`) goes back to that folder under a new random name; everything else goes to the current uploads month with a safe unique name;
 *   - a file under the Vault layer (`name.pdf.enc`, `FTNCFIL1`) is opened with the old Master Key and sealed again
 *     with this site's key (B), kept as it is when both keys are the same, or stored as a plain `name.pdf` on a site
 *     with no Vault (C);
 *   - extensions go through wp_check_filetype(), so no PHP or other server script lands in the library (an administrator may still upload html or js through WordPress itself, and so may a restore they run); `.enc` is accepted only for a
 *     private folder file whose inner name passes the same test;
 *   - every attachment made carries `_ftnc_import_batch` (job id: undo deletes it, file included), `_ftnc_import_src`
 *     (`attachment:<old id>`: a stop and resume finds it again) and `_ftnc_import_sha` (SHA-256 of the backup bytes:
 *     a second restore of the same backup finds it, this label outlives the job);
 *   - images get their metadata and thumbnails generated; other files get no metadata.
 */
final class Fotonic_Restore_Files {

	const SRC_PREFIX   = 'attachment:';
	const SHA_META     = '_ftnc_import_sha';
	const MAX_PROTECTED = 67108864; // A file under the Vault layer is handled in memory.
	const WORK_DIR      = 'fotonic/work-files'; // The documents of the works (Fotonic_Work_Files::DIR): file and name are protected.

	/** @var array<string,array<int,int>> Per session: old attachment id => record number in the `attachments` collection. */
	private static $index = array();

	/**
	 * The old attachment ids a WORK lists among its files (`_ftnc_work_files`): they are documents of the work, and
	 * land in the private work folder even when the backup (made before 1.7.0) kept them in the media library.
	 *
	 * @return int[]
	 */
	public static function work_file_ids( string $dataset_key, array $record ): array {
		if ( 'ftnc_work' !== $dataset_key ) {
			return array();
		}
		$raw     = $record['meta']['_ftnc_work_files'] ?? '';
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
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
	 * The old attachment ids a record points to, in the order they appear.
	 *
	 * @param array $dataset Registry entry of the dataset (`ref_fields`).
	 * @param array $record  The record as the archive holds it.
	 * @return int[]
	 */
	public static function ids_in_record( array $dataset, array $record ): array {
		$ids = array();
		foreach ( (array) ( $dataset['ref_fields'] ?? array() ) as $key => $kinds ) {
			foreach ( (array) $kinds as $kind ) {
				if ( ! is_string( $kind ) ) {
					continue;
				}
				$raw = $record['meta'][ $key ] ?? '';
				if ( 'attachment' === $kind ) {
					if ( is_scalar( $raw ) && (int) $raw > 0 ) {
						$ids[] = (int) $raw;
					}
				} elseif ( 0 === strpos( $kind, 'json:[].' ) && '|attachment' === substr( $kind, -11 ) && '[]' === substr( $kind, -13, 2 ) && false === strpos( substr( $kind, 8, -13 ), '[]' ) ) {
					// A list of ids inside each row (a product gallery: json:[].images[]|attachment).
					$field   = substr( $kind, 8, -13 );
					$decoded = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
					foreach ( (array) $decoded as $row ) {
						foreach ( is_array( $row ) && isset( $row[ $field ] ) && is_array( $row[ $field ] ) ? $row[ $field ] : array() as $one ) {
							if ( is_numeric( $one ) && (int) $one > 0 ) {
								$ids[] = (int) $one;
							}
						}
					}
				} elseif ( 0 === strpos( $kind, 'json:[].' ) && '|attachment' === substr( $kind, -11 ) && false === strpos( $kind, '[]', 8 ) ) {
					$field   = substr( $kind, 8, -11 );
					$decoded = is_string( $raw ) ? json_decode( $raw, true ) : ( is_array( $raw ) ? $raw : null );
					foreach ( (array) $decoded as $row ) {
						if ( is_numeric( $row ) ) {
							$ids[] = (int) $row;
						} elseif ( is_array( $row ) && 'link' !== ( $row['type'] ?? 'attachment' ) && (int) ( $row[ $field ] ?? 0 ) > 0 ) {
							$ids[] = (int) $row[ $field ];
						}
					}
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/** Forget the per-session lookup (tests; a new session never reuses it). */
	public static function flush_cache(): void {
		self::$index = array();
	}

	/**
	 * @param string $sid    Restore session.
	 * @param int    $old_id Attachment id in the backup.
	 * @param array  $ctx    ak (archive key), old_mk, new_mk (null on a site with no Vault), batch (job id),
	 *                       idmap (Fotonic_Restore_Idmap), candidates (ids of files the target record holds now).
	 * @return array|\WP_Error array( id, state )
	 */
	public static function import( string $sid, int $old_id, array $ctx ) {
		$idmap = $ctx['idmap'];
		$known = $idmap->get( 'attachment', $old_id );
		if ( null !== $known ) {
			return array( 'id' => $known, 'state' => 'mapped' );
		}
		$att = self::attachment_record( $sid, $old_id );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		$name = 'files/' . $old_id . '.enc';
		$path = Fotonic_Restore_Reader::source_path( $sid );
		$work = Fotonic_Restore_Reader::work_dir( $sid );
		if ( is_wp_error( $path ) ) {
			return $path;
		}
		if ( is_wp_error( $work ) ) {
			return $work;
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return new \WP_Error( 'ftnc_restore_bad_file', __( 'The backup file is no longer there. Upload it again.', 'eleva-crm-for-photographers' ), array( 'status' => 410 ) );
		}
		$present = false !== $zip->locateName( $name );
		$zip->close();
		if ( null === $att || ! $present ) {
			return array( 'id' => 0, 'state' => 'missing' );
		}
		$sha = strtolower( (string) $att['sha256'] );
		$rel = self::safe_relative( (string) $att['attached_file'] );
		if ( null === $rel ) {
			return array( 'id' => 0, 'state' => 'rejected' );
		}

		// Already made from these bytes (a stop and resume, or an earlier restore of this backup)?
		$repo  = Fotonic_Restore_Writer::repo();
		$found = self::find_existing( $repo, $old_id, $sha, (string) $ctx['batch'], (array) ( $ctx['candidates'] ?? array() ) );
		if ( $found > 0 ) {
			$idmap->set( 'attachment', $old_id, $found );
			return array( 'id' => $found, 'state' => 'reused' );
		}

		$private = self::private_dir_of( $rel );
		$base    = sanitize_file_name( wp_basename( $rel ) );
		$is_enc  = self::ends_with( strtolower( $base ), Fotonic_File_Crypt::SUFFIX );
		$inner   = $is_enc ? Fotonic_File_Crypt::plain_name( $base ) : $base;
		$type    = wp_check_filetype( $inner );
		if ( '' === $base || empty( $type['ext'] ) || ( $is_enc && null === $private ) ) {
			return array( 'id' => 0, 'state' => 'rejected' );
		}
		// A document of a work (`work_file`) lands in the private work folder, whatever the backup said: a backup made
		// before 1.7.0 keeps it in the media library, and putting it back there would undo the protection. Under a name
		// that says nothing: the real name goes into the (protected) title.
		$was_library = null === $private;
		if ( $was_library && ! $is_enc && ! empty( $ctx['work_file'] ) && class_exists( 'Fotonic_Work_Files' ) && false !== Fotonic_Work_Files::allowed_type( $inner ) ) {
			$private = self::WORK_DIR;
		} else {
			$was_library = false;
		}
		$is_work = self::WORK_DIR === $private;
		if ( $is_work ) {
			$inner = 'file.' . strtolower( (string) pathinfo( $inner, PATHINFO_EXTENSION ) );
			$base  = $is_enc ? $inner . Fotonic_File_Crypt::SUFFIX : $inner;
		}

		$verified = Fotonic_Restore_Reader::verify_member( $sid, $name );
		if ( is_wp_error( $verified ) ) {
			return array( 'id' => 0, 'state' => 'damaged' );
		}

		$tmp = $work . '/att-' . bin2hex( random_bytes( 8 ) ) . '.tmp';
		try {
			if ( ! self::decrypt_member( $path, $name, (string) $ctx['ak'], $tmp ) || ! is_file( $tmp ) || ! hash_equals( $sha, hash_file( 'sha256', $tmp ) ) ) {
				return array( 'id' => 0, 'state' => 'damaged' );
			}
			// What is stored: the bytes and the name's last part.
			$store_name = $base;
			$source     = $tmp;
			$blob_file  = '';
			if ( $is_enc ) {
				if ( ! Fotonic_File_Crypt::is_encrypted( $tmp ) || filesize( $tmp ) > self::MAX_PROTECTED ) {
					return array( 'id' => 0, 'state' => 'damaged' );
				}
				$old_mk = isset( $ctx['old_mk'] ) ? (string) $ctx['old_mk'] : '';
				$new_mk = isset( $ctx['new_mk'] ) && is_string( $ctx['new_mk'] ) ? $ctx['new_mk'] : null;
				if ( null !== $new_mk && hash_equals( $old_mk, $new_mk ) ) {
					$source = $tmp; // The same key: the file is already right.
				} else {
					$plain = Fotonic_File_Crypt::decrypt_file( $tmp, $old_mk );
					if ( ! is_string( $plain ) ) {
						return array( 'id' => 0, 'state' => 'damaged' );
					}
					$blob_file = $work . '/att-' . bin2hex( random_bytes( 8 ) ) . '.out';
					if ( null !== $new_mk ) {
						$out = Fotonic_File_Crypt::encrypt( $plain, $new_mk );
						if ( false === $out ) {
							return array( 'id' => 0, 'state' => 'damaged' );
						}
					} else {
						$out        = $plain; // No Vault here: a plain file, named as a plain file.
						$store_name = $inner;
					}
					if ( strlen( $out ) !== file_put_contents( $blob_file, $out ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private session folder.
						return array( 'id' => 0, 'state' => 'damaged' );
					}
					$source = $blob_file;
				}
			}

			$new_key = isset( $ctx['new_mk'] ) && is_string( $ctx['new_mk'] ) ? $ctx['new_mk'] : null;
			if ( $is_work && ! $is_enc && null !== $new_key ) {
				// A plain document (the backup came from a site whose files were not encrypted yet) landing on a site
				// with a Vault is sealed with this site's key, like the rest of a work's documents.
				if ( filesize( $tmp ) > self::MAX_PROTECTED ) {
					return array( 'id' => 0, 'state' => 'damaged' );
				}
				$plain_bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- private session folder.
				$sealed      = false === $plain_bytes ? false : Fotonic_File_Crypt::encrypt( $plain_bytes, $new_key );
				$blob_file   = $work . '/att-' . bin2hex( random_bytes( 8 ) ) . '.out';
				if ( false === $sealed || strlen( $sealed ) !== file_put_contents( $blob_file, $sealed ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private session folder.
					return array( 'id' => 0, 'state' => 'damaged' );
				}
				$source     = $blob_file;
				$store_name = $base . Fotonic_File_Crypt::SUFFIX;
			}

			$placed = self::place( $source, $store_name, $private );
			if ( null === $placed ) {
				return new \WP_Error( 'ftnc_restore_file_write', __( 'A file could not be saved in the uploads folder.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
			}
			$kept_enc = $is_enc && self::ends_with( $store_name, Fotonic_File_Crypt::SUFFIX );
			$mime     = (string) $att['mime'];
			if ( '' === $mime ) {
				$mime = $kept_enc ? 'application/pdf' : (string) $type['type'];
			}
			$title = (string) $att['post_title'];
			if ( $is_work ) {
				// The name of a document is stored under the Vault layer: opened with the old key, sealed with this site's
				// (or plain on a site with no Vault). A name that cannot be opened is left empty, never invented.
				if ( $was_library ) {
					$title = wp_basename( $rel ); // The title of a library file has no extension: its file name is the useful name.
				}
				$cx    = array( 'old' => isset( $ctx['old_mk'] ) ? (string) $ctx['old_mk'] : '', 'new' => $new_key, 'unreadable' => 0, 'error' => null );
				$title = Fotonic_Restore_Writer::convert( $title, 'f1', $cx );
				if ( is_wp_error( $cx['error'] ) ) {
					wp_delete_file( $placed );
					return $cx['error'];
				}
			}
			$id = $repo->insert_attachment(
				array(
					'post_title'     => $title,
					'post_name'      => $is_work && class_exists( 'Fotonic_Work_Files' ) ? Fotonic_Work_Files::neutral_slug() : '',
					'post_mime_type' => $mime,
					'post_status'    => null !== $private ? 'private' : 'inherit',
				),
				$placed,
				array(
					Fotonic_Restore_Writer::BATCH_META => (string) $ctx['batch'],
					Fotonic_Restore_Writer::SRC_META   => self::SRC_PREFIX . $old_id,
					self::SHA_META                     => $sha,
				)
			);
			if ( $id <= 0 ) {
				wp_delete_file( $placed );
				return array( 'id' => 0, 'state' => 'rejected' );
			}
			if ( ! $is_enc && null === $private && 0 === strpos( (string) $type['type'], 'image/' ) ) { // Never in a private folder: a resized copy would be plain.
				$repo->generate_attachment_metadata( $id, $placed );
			}
			$idmap->set( 'attachment', $old_id, $id );
			return array( 'id' => $id, 'state' => 'created' );
		} finally {
			foreach ( array( $tmp, isset( $blob_file ) ? $blob_file : '' ) as $leftover ) {
				if ( '' !== $leftover && is_file( $leftover ) ) {
					wp_delete_file( $leftover );
				}
			}
		}
	}

	// ---------------------------------------------------------------------------
	// Pieces
	// ---------------------------------------------------------------------------

	/** An attachment of this site that already holds these bytes: made by this job before a stop, by an earlier restore, or held by the target record. */
	private static function find_existing( $repo, int $old_id, string $sha, string $batch, array $candidates ): int {
		foreach ( $repo->ids_with_meta( Fotonic_Restore_Writer::SRC_META, self::SRC_PREFIX . $old_id ) as $id ) {
			if ( $repo->get_meta( $id, Fotonic_Restore_Writer::BATCH_META ) === $batch && self::file_ok( $repo, $id ) ) {
				return $id;
			}
		}
		foreach ( $repo->ids_with_meta( self::SHA_META, $sha ) as $id ) {
			if ( self::file_ok( $repo, $id ) ) {
				return $id;
			}
		}
		foreach ( $candidates as $id ) {
			$file = $repo->attachment_path( (int) $id );
			if ( '' !== $file && is_file( $file ) && hash_equals( $sha, hash_file( 'sha256', $file ) ) ) {
				return (int) $id;
			}
		}
		return 0;
	}

	private static function file_ok( $repo, int $id ): bool {
		$file = $repo->attachment_path( $id );
		return '' !== $file && is_file( $file );
	}

	/** The record of one attachment in the backup (null when the backup does not list it). */
	private static function attachment_record( string $sid, int $old_id ) {
		if ( ! isset( self::$index[ $sid ] ) ) {
			$map  = array();
			$cols = Fotonic_Restore_Reader::collections( $sid );
			if ( is_wp_error( $cols ) ) {
				return $cols;
			}
			$count = 0;
			foreach ( $cols as $c ) {
				if ( 'attachments' === $c['id'] ) {
					$count = (int) $c['count'];
				}
			}
			for ( $at = 0; $at < $count; $at += 500 ) {
				$page = Fotonic_Restore_Reader::records( $sid, 'attachments', $at, 500 );
				if ( is_wp_error( $page ) ) {
					return $page;
				}
				foreach ( $page as $i => $rec ) {
					if ( is_array( $rec ) && isset( $rec['id'] ) ) {
						$map[ (int) $rec['id'] ] = $at + $i;
					}
				}
			}
			self::$index[ $sid ] = $map;
		}
		if ( ! isset( self::$index[ $sid ][ $old_id ] ) ) {
			return null;
		}
		$rows = Fotonic_Restore_Reader::records( $sid, 'attachments', self::$index[ $sid ][ $old_id ], 1 );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$rec = $rows[0] ?? null;
		if ( ! is_array( $rec ) || (int) ( $rec['id'] ?? 0 ) !== $old_id || empty( $rec['sha256'] ) ) {
			return null;
		}
		$rec += array( 'post_title' => '', 'mime' => '', 'attached_file' => '' );
		return $rec;
	}

	/** A relative path from the backup that cannot escape the uploads folder; null otherwise. */
	private static function safe_relative( string $rel ): ?string {
		$rel = str_replace( '\\', '/', $rel );
		if ( '' === $rel || false !== strpos( $rel, "\0" ) || '/' === $rel[0] || preg_match( '#(^|/)\.\.?(/|$)#', $rel ) || preg_match( '#^[A-Za-z]:#', $rel ) ) {
			return null;
		}
		return $rel;
	}

	/**
	 * The private plugin folder (`fotonic/<name>`) a file lived in, or null for the ordinary library. The names come from
	 * the same list Site Health probes (`ftnc_protected_folders`: `vault`, and Pro's `quotes`), without the folders of
	 * backups and restores (no attachment lives there), and the filter `ftnc_restore_private_dirs`. A file in a
	 * subfolder of one of them counts.
	 */
	private static function private_dir_of( string $rel ): ?string {
		$names = class_exists( 'Fotonic_Site_Health' ) ? Fotonic_Site_Health::protected_folders() : array( 'vault', 'quotes', 'work-files' );
		$bases = array();
		foreach ( $names as $name ) {
			if ( is_string( $name ) && ! in_array( $name, array( 'backups', 'restore' ), true ) && preg_match( '/^[a-z0-9_-]+$/', $name ) ) {
				$bases[] = 'fotonic/' . $name;
			}
		}
		$bases = (array) apply_filters( 'ftnc_restore_private_dirs', $bases );
		$dir   = trim( dirname( $rel ), '/' );
		foreach ( $bases as $base ) {
			if ( is_string( $base ) && 0 === strpos( $base, 'fotonic/' ) && ( $dir === $base || 0 === strpos( $dir, $base . '/' ) ) ) {
				return $base;
			}
		}
		return null;
	}

	/** Stream-decrypt `files/N.enc` of the archive into $out. */
	private static function decrypt_member( string $zip_path, string $name, string $ak, string $out ): bool {
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			return false;
		}
		$in = $zip->getStream( $name );
		$fh = is_resource( $in ) ? fopen( $out, 'wb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$ok = false;
		if ( is_resource( $in ) && false !== $fh ) {
			try {
				Fotonic_Backup_Cipher::decrypt_stream( $in, $fh, $ak );
				$ok = true;
			} catch ( \Throwable $e ) {
				$ok = false;
			}
		}
		if ( is_resource( $in ) ) {
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		if ( is_resource( $fh ) ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$zip->close();
		return $ok;
	}

	/**
	 * Put the file in its place: a private plugin folder (deny-all guards, random name) or the current uploads month.
	 *
	 * @return string|null Absolute path, null when it could not be saved.
	 */
	private static function place( string $source, string $name, ?string $private ): ?string {
		$up = wp_upload_dir();
		if ( null !== $private ) {
			$dir = rtrim( (string) $up['basedir'], '/' ) . '/' . $private;
			if ( ! wp_mkdir_p( $dir ) ) {
				return null;
			}
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', "Require all denied\n# Block direct access to Eleva CRM files\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- own fixed path.
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- own fixed path.
			}
			$suffix = self::ends_with( $name, Fotonic_File_Crypt::SUFFIX ) ? '.' . pathinfo( Fotonic_File_Crypt::plain_name( $name ), PATHINFO_EXTENSION ) . Fotonic_File_Crypt::SUFFIX : '.' . pathinfo( $name, PATHINFO_EXTENSION );
			$stem   = sanitize_file_name( substr( $name, 0, strlen( $name ) - strlen( $suffix ) ) );
			$target = $dir . '/' . ( '' === $stem ? 'file' : $stem ) . '-' . bin2hex( random_bytes( 12 ) ) . $suffix;
		} else {
			$dir = (string) ( $up['path'] ?? '' );
			if ( '' === $dir || ! wp_mkdir_p( $dir ) ) {
				return null;
			}
			$target = rtrim( $dir, '/' ) . '/' . wp_unique_filename( $dir, $name );
		}
		if ( ! @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.rename_rename -- same disk; falls back to a copy.
			if ( ! @copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_system_operations_copy -- last resort.
				return null;
			}
		}
		return $target;
	}

	private static function ends_with( string $text, string $end ): bool {
		return strlen( $text ) >= strlen( $end ) && substr( $text, -strlen( $end ) ) === $end;
	}
}
