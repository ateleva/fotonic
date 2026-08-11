<?php
/**
 * Eleva CRM for Photographers: standalone backup decryptor.
 *
 * Decrypts an encrypted backup archive produced by the plugin, on ANY machine,
 * with NO WordPress, NO database, NO plugin code and NO Composer packages.
 * All it needs is PHP 7.4+ with the openssl, sodium, zip, json and hash
 * extensions, the archive .zip, and your vault password (or a recovery code /
 * recovery phrase).
 *
 *     php eleva-backup-decrypt.php eleva-backup-2026-08-11-120000-ab12cd34.zip
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * v1 of the backup feature writes archives; restoring them back INTO WordPress
 * lands in v2. Until then this tool is the restore path: it turns an archive
 * into a plain data.json plus your original files. It is deliberately a single
 * file with zero dependencies so that it still works years from now, on a
 * borrowed laptop, with the plugin long uninstalled.
 *
 * It is also the oracle for the archive format: everything below was written
 * from the format documentation alone (includes/class-backup-cipher.php and
 * includes/class-backup-archive.php docblocks), never by calling plugin code.
 * If this tool and the plugin ever disagree, the format spec is wrong.
 *
 * WHAT IT CANNOT DO
 * -----------------
 * Nothing here can recover an archive if the vault password, the recovery code
 * and the recovery phrase are all lost. That is the design: the site that
 * produced the archive cannot read it either, and neither can anyone who gets
 * into your Google Drive. There is no back door and no reset.
 *
 * THE KEY CHAIN (all of it comes out of the archive itself, no server salts,
 * no wp-config.php, nothing machine-bound):
 *
 *     KEK  = PBKDF2-SHA256(password, vault_salt, 600000, 32 bytes)
 *     MK   = AES-256-GCM-unwrap(vault_wrap_pw,    KEK)   <- wrong password dies here
 *     totp = AES-256-GCM-unwrap(vault_wrap_totp,  MK)    <- verifies your 6-digit code
 *     priv = AES-256-GCM-unwrap(backup_wrap_priv, MK)
 *     AK   = sodium_crypto_box_seal_open(sealed_ak, keypair(priv, backup_pubkey))
 *     data.json / files = AES-256-GCM streams under AK
 *
 * A recovery code uses vault_salt_rec + vault_wrap_rec instead of the first two
 * lines; a recovery phrase uses vault_salt_phrase + vault_wrap_phrase. Every
 * other step is identical.
 *
 * EXIT CODES
 *     0  success
 *     1  usage or environment problem (bad flags, missing PHP extension)
 *     2  archive problem (missing member, checksum mismatch, corruption)
 *     3  credential problem (wrong password, wrong recovery code/phrase, wrong OTP)
 *
 * @package Eleva CRM for Photographers
 */

// This file lives inside a plugin directory, so it is reachable over HTTP.
// It must never execute in a web request. (Nothing may be printed above this
// line, not even a #! shebang, or the 403 header cannot be sent. Run it as
// `php eleva-backup-decrypt.php`, which is what the README documents.)
if ( 'cli' !== PHP_SAPI && 'phpdbg' !== PHP_SAPI ) {
	if ( ! headers_sent() ) {
		http_response_code( 403 );
	}
	exit( 'This tool runs only from the command line.' );
}

// Everything this tool writes is sensitive: decrypted customer data and files.
umask( 0077 );

define( 'ELEVA_BD_VERSION', '1.0.0' );

// Archive/crypto constants, all fixed by the format spec.
define( 'ELEVA_BD_MAGIC', 'FTNCBK01' );
define( 'ELEVA_BD_HEADER_LEN', 20 );
define( 'ELEVA_BD_PREFIX_LEN', 8 );
define( 'ELEVA_BD_NONCE_LEN', 12 );
define( 'ELEVA_BD_TAG_LEN', 16 );
define( 'ELEVA_BD_MAX_COUNTER', 4294967295 );
define( 'ELEVA_BD_GCM', 'aes-256-gcm' );
define( 'ELEVA_BD_PBKDF2_ITERATIONS', 600000 );
define( 'ELEVA_BD_TOTP_STEP', 30 );
define( 'ELEVA_BD_TOTP_DIGITS', 6 );
define( 'ELEVA_BD_TOTP_WINDOW', 2 ); // +/- 60 s, same tolerance the plugin allows.
define( 'ELEVA_BD_READ_CHUNK', 1048576 );
define( 'ELEVA_BD_SUPPORTED_FORMAT', 1 );

// Exit codes.
define( 'ELEVA_BD_EXIT_USAGE', 1 );
define( 'ELEVA_BD_EXIT_ARCHIVE', 2 );
define( 'ELEVA_BD_EXIT_CREDENTIALS', 3 );

exit( eleva_bd_main( $argv ) );

// ---------------------------------------------------------------------------
// Entry point
// ---------------------------------------------------------------------------

/**
 * @param array $argv Raw CLI arguments.
 * @return int Exit code.
 */
function eleva_bd_main( array $argv ) {
	$options = eleva_bd_parse_args( $argv );

	if ( isset( $options['help'] ) ) {
		eleva_bd_usage();
		return 0;
	}
	if ( isset( $options['version'] ) ) {
		eleva_bd_say( 'eleva-backup-decrypt ' . ELEVA_BD_VERSION );
		return 0;
	}

	try {
		eleva_bd_check_environment();
		eleva_bd_validate_options( $options );
		return eleva_bd_run( $options );
	} catch ( Exception $e ) {
		$code = $e->getCode();
		if ( ELEVA_BD_EXIT_USAGE !== $code && ELEVA_BD_EXIT_ARCHIVE !== $code && ELEVA_BD_EXIT_CREDENTIALS !== $code ) {
			$code = ELEVA_BD_EXIT_ARCHIVE;
		}
		eleva_bd_error( $e->getMessage() );
		return $code;
	}
}

/**
 * The whole flow, in the order the format demands.
 *
 * @param array $options Parsed CLI options.
 * @return int Exit code.
 * @throws Exception On any failure.
 */
function eleva_bd_run( array $options ) {
	$archive_path = $options['archive'];
	$verify_only  = isset( $options['verify-only'] );
	$data_only    = isset( $options['data-only'] );

	eleva_bd_say( 'Eleva CRM backup decryptor ' . ELEVA_BD_VERSION );
	eleva_bd_say( 'Archive: ' . $archive_path );
	eleva_bd_say( '' );

	// --- 1. Open the archive and read its two plaintext members --------------
	$zip      = eleva_bd_open_zip( $archive_path );
	$manifest = eleva_bd_read_manifest( $zip );
	$key_data = eleva_bd_read_key_json( $zip );

	eleva_bd_say( 'Created:        ' . eleva_bd_manifest_string( $manifest, 'generated_at' ) );
	eleva_bd_say( 'From site:      ' . eleva_bd_manifest_string( $manifest, 'site_url' ) );
	eleva_bd_say( 'Plugin version: ' . eleva_bd_manifest_string( $manifest, 'plugin_version' ) );
	if ( isset( $manifest['datasets'] ) && is_array( $manifest['datasets'] ) ) {
		foreach ( $manifest['datasets'] as $dataset => $info ) {
			$count = isset( $info['count'] ) ? (int) $info['count'] : 0;
			eleva_bd_say( '  ' . str_pad( (string) $dataset, 24 ) . $count . ' record(s)' );
		}
	}
	eleva_bd_say( '' );

	// --- 2. Integrity: every member matches the manifest ---------------------
	eleva_bd_verify_members( $zip, $manifest );

	if ( ! empty( $manifest['missing_files'] ) && is_array( $manifest['missing_files'] ) ) {
		eleva_bd_say( '' );
		eleva_bd_warn( count( $manifest['missing_files'] ) . ' referenced file(s) were already missing on the source machine when this backup ran:' );
		foreach ( $manifest['missing_files'] as $missing ) {
			$id     = isset( $missing['attachment_id'] ) ? $missing['attachment_id'] : '?';
			$reason = isset( $missing['reason'] ) ? $missing['reason'] : 'unknown reason';
			eleva_bd_warn( '  attachment ' . $id . ': ' . $reason );
		}
	}

	// --- 3. Key material sanity (no secrets needed) --------------------------
	eleva_bd_check_key_shape( $key_data );

	// --- 4. Credentials ------------------------------------------------------
	$credential = eleva_bd_resolve_credential( $options );

	if ( $verify_only && null === $credential ) {
		eleva_bd_say( '' );
		eleva_bd_say( '[ok] Archive integrity verified: every member matches its checksum,' );
		eleva_bd_say( '     and the key material is present and well-formed.' );
		eleva_bd_say( '     The password/OTP key chain was NOT tested (no credentials were given).' );
		eleva_bd_say( '     To test it too, add --recovery-code=..., --recovery-phrase=... or' );
		eleva_bd_say( '     --password-stdin (with --no-totp to skip the 6-digit code).' );
		return 0;
	}

	$mk = eleva_bd_unwrap_master_key( $key_data, $credential );

	// --- 5. TOTP -------------------------------------------------------------
	if ( isset( $options['no-totp'] ) ) {
		eleva_bd_warn( '--no-totp given: skipping the 6-digit code check (key-chain test only).' );
	} else {
		eleva_bd_verify_totp( $key_data, $mk );
	}

	// --- 6/7/8. Private key -> archive key -----------------------------------
	$ak = eleva_bd_recover_archive_key( $key_data, $mk );
	eleva_bd_say( '[ok] Archive key recovered.' );

	if ( $verify_only ) {
		// Prove the key actually opens the payload without writing anything out.
		eleva_bd_decrypt_member( $zip, 'data.enc', $ak, eleva_bd_null_stream(), null );
		eleva_bd_say( '' );
		eleva_bd_say( '[ok] Full verification passed: checksums, key chain, and data.enc decrypts.' );
		eleva_bd_say( '     Nothing was written to disk (--verify-only).' );
		return 0;
	}

	// --- 9. Output -----------------------------------------------------------
	$out_dir = eleva_bd_prepare_out_dir( $options );
	eleva_bd_say( '' );
	eleva_bd_say( 'Writing to: ' . $out_dir );

	$data_json_path = $out_dir . DIRECTORY_SEPARATOR . 'data.json';
	eleva_bd_decrypt_member_to_file( $zip, 'data.enc', $ak, $data_json_path, null );
	eleva_bd_say( '[ok] data.json  (' . eleva_bd_human_bytes( filesize( $data_json_path ) ) . ')' );

	// A copy of the plaintext manifest, so the output directory is self-describing.
	eleva_bd_write_file( $out_dir . DIRECTORY_SEPARATOR . 'manifest.json', eleva_bd_zip_get( $zip, 'manifest.json', 'manifest.json' ) );

	$file_members = eleva_bd_file_members( $manifest );

	if ( $data_only ) {
		if ( ! empty( $file_members ) ) {
			eleva_bd_say( '--data-only given: skipped ' . count( $file_members ) . ' attachment(s).' );
		}
	} elseif ( ! empty( $file_members ) ) {
		$names = eleva_bd_attachment_names( $data_json_path );
		$files_dir = $out_dir . DIRECTORY_SEPARATOR . 'files';
		eleva_bd_make_dir( $files_dir );

		$done = 0;
		foreach ( $file_members as $member ) {
			$attachment_id = (int) $member['attachment_id'];
			$basename      = isset( $names[ $attachment_id ] ) ? $names[ $attachment_id ] : ( $attachment_id . '.bin' );
			$target        = $files_dir . DIRECTORY_SEPARATOR . $attachment_id . '-' . $basename;

			$hash_ctx = hash_init( 'sha256' );
			eleva_bd_decrypt_member_to_file( $zip, $member['name'], $ak, $target, $hash_ctx );
			$actual = hash_final( $hash_ctx );

			if ( isset( $member['plaintext_sha256'] ) && ! hash_equals( (string) $member['plaintext_sha256'], $actual ) ) {
				throw new Exception(
					'archive corrupted at ' . $member['name'] . ': it decrypted, but the result does not match the '
					. 'SHA-256 of the original file recorded in the manifest (expected ' . $member['plaintext_sha256']
					. ', got ' . $actual . ').',
					ELEVA_BD_EXIT_ARCHIVE
				);
			}

			$done++;
			eleva_bd_say( '[ok] files/' . $attachment_id . '-' . $basename . '  (' . eleva_bd_human_bytes( filesize( $target ) ) . ', SHA-256 matches the original)' );
		}
		eleva_bd_say( '[ok] ' . $done . ' file(s) restored, all byte-identical to the originals.' );
	}

	eleva_bd_say( '' );
	eleva_bd_say( 'Done. Your data is in: ' . $out_dir );
	eleva_bd_say( 'Treat that directory like the vault itself: it is plaintext now.' );

	return 0;
}

// ---------------------------------------------------------------------------
// Archive reading
// ---------------------------------------------------------------------------

/**
 * @param string $path Archive path.
 * @return ZipArchive
 * @throws Exception If the file is missing or is not a readable zip.
 */
function eleva_bd_open_zip( $path ) {
	if ( ! file_exists( $path ) ) {
		throw new Exception( 'No such file: ' . $path, ELEVA_BD_EXIT_USAGE );
	}
	if ( ! is_readable( $path ) ) {
		throw new Exception( 'Cannot read (check permissions): ' . $path, ELEVA_BD_EXIT_USAGE );
	}

	$zip    = new ZipArchive();
	$opened = $zip->open( $path, ZipArchive::CHECKCONS );
	if ( true !== $opened ) {
		// CHECKCONS is strict; retry plainly so a merely-picky reading still
		// reports the real structural problem later instead of a bare code.
		$opened = $zip->open( $path );
	}
	if ( true !== $opened ) {
		throw new Exception(
			'This file is not a readable zip archive (ZipArchive error ' . $opened . '). '
			. 'If it came from a cloud drive, make sure the download finished.',
			ELEVA_BD_EXIT_ARCHIVE
		);
	}
	return $zip;
}

/**
 * @param ZipArchive $zip Open archive.
 * @return array Decoded manifest.
 * @throws Exception If manifest.json is missing or unusable.
 */
function eleva_bd_read_manifest( ZipArchive $zip ) {
	if ( false === $zip->locateName( 'manifest.json' ) ) {
		throw new Exception(
			'This archive has no manifest.json, so it is not an Eleva CRM backup '
			. '(or it is truncated).',
			ELEVA_BD_EXIT_ARCHIVE
		);
	}

	$manifest = json_decode( eleva_bd_zip_get( $zip, 'manifest.json', 'manifest.json' ), true );
	if ( ! is_array( $manifest ) ) {
		throw new Exception( 'manifest.json is not valid JSON. The archive is damaged.', ELEVA_BD_EXIT_ARCHIVE );
	}

	$format = isset( $manifest['format'] ) ? (int) $manifest['format'] : 0;
	if ( ELEVA_BD_SUPPORTED_FORMAT !== $format ) {
		throw new Exception(
			'This archive uses format version ' . $format . '; this tool understands version '
			. ELEVA_BD_SUPPORTED_FORMAT . '. Use the decrypt tool that shipped with the plugin version '
			. 'that wrote the archive.',
			ELEVA_BD_EXIT_ARCHIVE
		);
	}
	if ( ! isset( $manifest['members'] ) || ! is_array( $manifest['members'] ) ) {
		throw new Exception( 'manifest.json lists no members. The archive is damaged.', ELEVA_BD_EXIT_ARCHIVE );
	}

	return $manifest;
}

/**
 * @param ZipArchive $zip Open archive.
 * @return array Decoded key.json.
 * @throws Exception If key.json is missing or unusable.
 */
function eleva_bd_read_key_json( ZipArchive $zip ) {
	if ( false === $zip->locateName( 'key.json' ) ) {
		throw new Exception(
			'This archive is missing its key material (key.json). Without it the contents '
			. 'cannot be decrypted by anyone, including you. Use another copy of the archive.',
			ELEVA_BD_EXIT_ARCHIVE
		);
	}

	$key_data = json_decode( eleva_bd_zip_get( $zip, 'key.json', 'key.json' ), true );
	if ( ! is_array( $key_data ) ) {
		throw new Exception( 'key.json is not valid JSON. The archive is damaged.', ELEVA_BD_EXIT_ARCHIVE );
	}
	return $key_data;
}

/**
 * Read a small member fully into memory. Guarded so a hostile or corrupt
 * archive cannot make the tool allocate a gigabyte for "manifest.json".
 *
 * @param ZipArchive $zip   Open archive.
 * @param string     $name  Member name.
 * @param string     $label Human label for errors.
 * @return string Member contents.
 * @throws Exception If the member is absent, oversized or unreadable.
 */
function eleva_bd_zip_get( ZipArchive $zip, $name, $label ) {
	$stat = $zip->statName( $name );
	if ( false === $stat ) {
		throw new Exception( 'The archive is missing ' . $label . '.', ELEVA_BD_EXIT_ARCHIVE );
	}
	if ( $stat['size'] > 8388608 ) {
		throw new Exception( $label . ' is implausibly large (' . eleva_bd_human_bytes( $stat['size'] ) . '). The archive is damaged.', ELEVA_BD_EXIT_ARCHIVE );
	}

	$contents = $zip->getFromName( $name );
	if ( false === $contents ) {
		throw new Exception( 'Could not read ' . $label . ' out of the archive.', ELEVA_BD_EXIT_ARCHIVE );
	}
	return $contents;
}

/**
 * Every member listed in the manifest must exist, be the recorded size, and
 * hash to the recorded SHA-256, and the archive must contain nothing else.
 *
 * @param ZipArchive $zip      Open archive.
 * @param array      $manifest Decoded manifest.
 * @return void
 * @throws Exception On any mismatch.
 */
function eleva_bd_verify_members( ZipArchive $zip, array $manifest ) {
	eleva_bd_say( 'Checking archive integrity...' );

	$expected = array( 'manifest.json' => true );

	foreach ( $manifest['members'] as $member ) {
		if ( ! isset( $member['name'], $member['sha256'] ) ) {
			throw new Exception( 'manifest.json has a malformed member entry. The archive is damaged.', ELEVA_BD_EXIT_ARCHIVE );
		}
		$name              = (string) $member['name'];
		$expected[ $name ] = true;

		$stat = $zip->statName( $name );
		if ( false === $stat ) {
			throw new Exception( 'The archive is missing ' . $name . ', which its own manifest says should be there.', ELEVA_BD_EXIT_ARCHIVE );
		}
		if ( isset( $member['size'] ) && (int) $member['size'] !== (int) $stat['size'] ) {
			throw new Exception(
				'archive corrupted at ' . $name . ': it is ' . $stat['size'] . ' bytes but the manifest records '
				. (int) $member['size'] . '.',
				ELEVA_BD_EXIT_ARCHIVE
			);
		}

		$actual = eleva_bd_hash_member( $zip, $name );
		if ( ! hash_equals( strtolower( (string) $member['sha256'] ), $actual ) ) {
			throw new Exception(
				'archive corrupted at ' . $name . ': its SHA-256 does not match the manifest '
				. '(expected ' . $member['sha256'] . ', got ' . $actual . '). The archive was damaged '
				. 'in transit or altered.',
				ELEVA_BD_EXIT_ARCHIVE
			);
		}

		eleva_bd_say( '  [ok] ' . str_pad( $name, 28 ) . eleva_bd_human_bytes( $stat['size'] ) );
	}

	// Anything not accounted for by the manifest is an intruder.
	$unexpected = array();
	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$name = $zip->getNameIndex( $i );
		if ( false === $name || isset( $expected[ $name ] ) ) {
			continue;
		}
		if ( '/' === substr( $name, -1 ) ) {
			continue; // Directory entry, harmless.
		}
		$unexpected[] = $name;
	}
	if ( ! empty( $unexpected ) ) {
		throw new Exception(
			'The archive contains ' . count( $unexpected ) . ' member(s) its manifest does not list ('
			. implode( ', ', array_slice( $unexpected, 0, 5 ) ) . '). Refusing to continue: this archive '
			. 'was modified after it was written.',
			ELEVA_BD_EXIT_ARCHIVE
		);
	}
}

/**
 * SHA-256 of a member, streamed so a huge attachment never loads into memory.
 *
 * @param ZipArchive $zip  Open archive.
 * @param string     $name Member name.
 * @return string Lowercase hex digest.
 * @throws Exception If the member cannot be streamed.
 */
function eleva_bd_hash_member( ZipArchive $zip, $name ) {
	$stream = $zip->getStream( $name );
	if ( ! is_resource( $stream ) ) {
		throw new Exception( 'Could not read ' . $name . ' out of the archive.', ELEVA_BD_EXIT_ARCHIVE );
	}

	$ctx = hash_init( 'sha256' );
	while ( ! feof( $stream ) ) {
		$buffer = fread( $stream, ELEVA_BD_READ_CHUNK );
		if ( false === $buffer ) {
			fclose( $stream );
			throw new Exception( 'Read error inside the archive at ' . $name . '.', ELEVA_BD_EXIT_ARCHIVE );
		}
		if ( '' === $buffer ) {
			break;
		}
		hash_update( $ctx, $buffer );
	}
	fclose( $stream );

	return hash_final( $ctx );
}

/**
 * The manifest entries for encrypted attachments, in ID order.
 *
 * @param array $manifest Decoded manifest.
 * @return array List of member entries carrying an attachment_id.
 */
function eleva_bd_file_members( array $manifest ) {
	$members = array();
	foreach ( $manifest['members'] as $member ) {
		if ( isset( $member['attachment_id'] ) && isset( $member['name'] ) && 0 === strncmp( (string) $member['name'], 'files/', 6 ) ) {
			$members[] = $member;
		}
	}
	usort(
		$members,
		function ( $a, $b ) {
			return (int) $a['attachment_id'] - (int) $b['attachment_id'];
		}
	);
	return $members;
}

// ---------------------------------------------------------------------------
// Key chain
// ---------------------------------------------------------------------------

/**
 * Structural checks on key.json that need no password at all: they turn a
 * mangled archive into a clear message before anyone types a secret.
 *
 * @param array $key_data Decoded key.json.
 * @return void
 * @throws Exception If a required field is absent or malformed.
 */
function eleva_bd_check_key_shape( array $key_data ) {
	$scheme = isset( $key_data['scheme'] ) ? (int) $key_data['scheme'] : 0;
	if ( 2 !== $scheme ) {
		throw new Exception(
			'key.json declares vault scheme ' . $scheme . '; this tool only understands scheme 2.',
			ELEVA_BD_EXIT_ARCHIVE
		);
	}

	foreach ( array( 'sealed_ak', 'backup_pubkey', 'backup_wrap_priv' ) as $required ) {
		if ( empty( $key_data[ $required ] ) ) {
			throw new Exception(
				'key.json is missing "' . $required . '", so the archive key cannot be recovered. '
				. 'This archive is not decryptable. Use another copy.',
				ELEVA_BD_EXIT_ARCHIVE
			);
		}
	}

	$pub = base64_decode( (string) $key_data['backup_pubkey'], true );
	if ( false === $pub || 32 !== strlen( $pub ) ) {
		throw new Exception( 'key.json holds a malformed backup public key. The archive is damaged.', ELEVA_BD_EXIT_ARCHIVE );
	}
	if ( false === base64_decode( (string) $key_data['sealed_ak'], true ) ) {
		throw new Exception( 'key.json holds a malformed sealed archive key. The archive is damaged.', ELEVA_BD_EXIT_ARCHIVE );
	}
	eleva_bd_assert_wrapped( $key_data['backup_wrap_priv'], 'backup_wrap_priv' );

	if ( empty( $key_data['vault_wrap_pw'] ) && empty( $key_data['vault_wrap_rec'] ) && empty( $key_data['vault_wrap_phrase'] ) ) {
		throw new Exception(
			'key.json carries no way to unlock the master key (no password, recovery code or '
			. 'recovery phrase wrap). This archive is not decryptable.',
			ELEVA_BD_EXIT_ARCHIVE
		);
	}
}

/**
 * @param mixed  $blob  Value that should be a 'w1:' wrapped blob.
 * @param string $label Field name for the error message.
 * @return void
 * @throws Exception If the blob is not a well-formed wrap.
 */
function eleva_bd_assert_wrapped( $blob, $label ) {
	if ( ! is_string( $blob ) || 0 !== strncmp( $blob, 'w1:', 3 ) ) {
		throw new Exception( 'key.json field "' . $label . '" is not in the expected wrapped format. The archive is damaged.', ELEVA_BD_EXIT_ARCHIVE );
	}
	$raw = base64_decode( substr( $blob, 3 ), true );
	if ( false === $raw || strlen( $raw ) < 29 ) {
		throw new Exception( 'key.json field "' . $label . '" is truncated. The archive is damaged.', ELEVA_BD_EXIT_ARCHIVE );
	}
}

/**
 * Decide which credential the user is unlocking with, and obtain it.
 *
 * @param array $options Parsed CLI options.
 * @return array|null array( 'kind', 'secret' ), or null when --verify-only was
 *                    given with nothing to unlock with.
 * @throws Exception On a bad combination.
 */
function eleva_bd_resolve_credential( array $options ) {
	if ( isset( $options['recovery-code'] ) ) {
		return array( 'kind' => 'code', 'secret' => $options['recovery-code'] );
	}
	if ( isset( $options['recovery-phrase'] ) ) {
		return array( 'kind' => 'phrase', 'secret' => $options['recovery-phrase'] );
	}

	$env = getenv( 'ELEVA_BACKUP_PASSWORD' );
	if ( is_string( $env ) && '' !== $env ) {
		return array( 'kind' => 'password', 'secret' => $env );
	}
	if ( isset( $options['password-stdin'] ) ) {
		$line = fgets( STDIN );
		if ( false === $line ) {
			throw new Exception( '--password-stdin was given but nothing arrived on standard input.', ELEVA_BD_EXIT_USAGE );
		}
		return array( 'kind' => 'password', 'secret' => rtrim( $line, "\r\n" ) );
	}

	if ( isset( $options['verify-only'] ) ) {
		return null; // Integrity-only run: never prompt.
	}

	return array( 'kind' => 'password', 'secret' => null ); // Prompt later, with retries.
}

/**
 * Turn the credential into the vault master key.
 *
 * @param array $key_data   Decoded key.json.
 * @param array $credential Credential descriptor from eleva_bd_resolve_credential().
 * @return string Raw 32-byte master key.
 * @throws Exception If the credential is wrong or the wrap is missing.
 */
function eleva_bd_unwrap_master_key( array $key_data, array $credential ) {
	if ( 'code' === $credential['kind'] ) {
		$salt  = eleva_bd_key_field( $key_data, 'vault_salt_rec', 'recovery code' );
		$wrap  = eleva_bd_key_field( $key_data, 'vault_wrap_rec', 'recovery code' );
		$label = 'recovery code';
	} elseif ( 'phrase' === $credential['kind'] ) {
		$salt  = eleva_bd_key_field( $key_data, 'vault_salt_phrase', 'recovery phrase' );
		$wrap  = eleva_bd_key_field( $key_data, 'vault_wrap_phrase', 'recovery phrase' );
		$label = 'recovery phrase';
	} else {
		$salt  = eleva_bd_key_field( $key_data, 'vault_salt', 'password' );
		$wrap  = eleva_bd_key_field( $key_data, 'vault_wrap_pw', 'password' );
		$label = 'password';
	}
	eleva_bd_assert_wrapped( $wrap, 'password' === $credential['kind'] ? 'vault_wrap_pw' : ( 'code' === $credential['kind'] ? 'vault_wrap_rec' : 'vault_wrap_phrase' ) );

	$attempts_left = ( null === $credential['secret'] ) ? 3 : 1;
	$secret        = $credential['secret'];

	while ( true ) {
		if ( null === $secret ) {
			$secret = eleva_bd_prompt_secret( 'Vault password: ' );
		}

		$input = ( 'password' === $credential['kind'] ) ? $secret : eleva_bd_normalize_code( $secret );

		eleva_bd_say( 'Deriving the key (600,000 PBKDF2 rounds, this takes a moment)...' );

		// The salt is the base64 STRING exactly as stored, not its decoded bytes:
		// the plugin passes the b64 text straight into hash_pbkdf2(). Decoding it
		// first yields a wrong-but-plausible key that fails with a confusing
		// "wrong password" for a correct password.
		$kek = hash_pbkdf2( 'sha256', $input, $salt, ELEVA_BD_PBKDF2_ITERATIONS, 32, true );
		$mk  = eleva_bd_gcm_unwrap( $wrap, $kek );

		if ( false !== $mk && 32 === strlen( $mk ) ) {
			eleva_bd_say( '[ok] ' . ucfirst( $label ) . ' accepted; master key recovered.' );
			return $mk;
		}

		$attempts_left--;
		if ( $attempts_left < 1 ) {
			throw new Exception(
				'Wrong ' . $label . '. The master key could not be unwrapped (the authentication tag '
				. 'did not verify). Nothing was decrypted.'
				. ( 'password' === $label ? ' If you have your recovery code or recovery phrase, try --recovery-code=... or --recovery-phrase=...' : '' ),
				ELEVA_BD_EXIT_CREDENTIALS
			);
		}
		eleva_bd_warn( 'Wrong ' . $label . '. ' . $attempts_left . ' attempt(s) left.' );
		$secret = null;
	}
}

/**
 * @param array  $key_data Decoded key.json.
 * @param string $field    Field name.
 * @param string $path     Human name of the unlock path, for the error.
 * @return string Field value.
 * @throws Exception If the field is absent.
 */
function eleva_bd_key_field( array $key_data, $field, $path ) {
	if ( empty( $key_data[ $field ] ) ) {
		throw new Exception(
			'This archive carries no ' . $path . ' credential (key.json has no "' . $field . '"), '
			. 'so it cannot be opened that way. Try one of the other unlock methods.',
			ELEVA_BD_EXIT_CREDENTIALS
		);
	}
	return (string) $key_data[ $field ];
}

/**
 * Unwrap the TOTP secret and check the 6-digit code against it.
 *
 * @param array  $key_data Decoded key.json.
 * @param string $mk       Raw master key.
 * @return void
 * @throws Exception If the code is wrong.
 */
function eleva_bd_verify_totp( array $key_data, $mk ) {
	if ( empty( $key_data['vault_wrap_totp'] ) ) {
		eleva_bd_warn( 'This archive carries no TOTP secret; skipping the 6-digit code check.' );
		return;
	}

	$totp_secret = eleva_bd_gcm_unwrap( (string) $key_data['vault_wrap_totp'], $mk );
	if ( false === $totp_secret || '' === $totp_secret ) {
		throw new Exception(
			'The master key is correct but the stored TOTP secret could not be unwrapped. '
			. 'key.json is damaged.',
			ELEVA_BD_EXIT_ARCHIVE
		);
	}

	$env           = getenv( 'ELEVA_BACKUP_OTP' );
	$code          = ( is_string( $env ) && '' !== $env ) ? $env : null;
	$attempts_left = ( null === $code ) ? 3 : 1;

	while ( true ) {
		if ( null === $code ) {
			$code = eleva_bd_prompt_secret( '6-digit code from your authenticator app: ', false );
		}

		if ( eleva_bd_totp_verify( trim( $code ), $totp_secret, ELEVA_BD_TOTP_WINDOW ) ) {
			eleva_bd_say( '[ok] Code accepted.' );
			return;
		}

		$attempts_left--;
		if ( $attempts_left < 1 ) {
			throw new Exception(
				'Wrong OTP. The 6-digit code did not match (a 60-second window either side was '
				. 'allowed, so this is not clock drift unless this machine\'s clock is badly off). '
				. 'Nothing was decrypted.',
				ELEVA_BD_EXIT_CREDENTIALS
			);
		}
		eleva_bd_warn( 'Wrong code. ' . $attempts_left . ' attempt(s) left.' );
		$code = null;
	}
}

/**
 * Unwrap the backup private key with the master key, then open the sealed box
 * to get the archive key.
 *
 * @param array  $key_data Decoded key.json.
 * @param string $mk       Raw master key.
 * @return string Raw 32-byte archive key.
 * @throws Exception If either step fails.
 */
function eleva_bd_recover_archive_key( array $key_data, $mk ) {
	$priv = eleva_bd_gcm_unwrap( (string) $key_data['backup_wrap_priv'], $mk );
	if ( false === $priv || 32 !== strlen( $priv ) ) {
		throw new Exception(
			'The vault opened, but the backup private key inside key.json could not be unwrapped. '
			. 'The archive is damaged, or key.json was replaced with one from a different vault.',
			ELEVA_BD_EXIT_ARCHIVE
		);
	}

	$pub    = base64_decode( (string) $key_data['backup_pubkey'], true );
	$sealed = base64_decode( (string) $key_data['sealed_ak'], true );

	// sodium_crypto_box_seal_open() wants a full keypair, not a bare secret key.
	$keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey( $priv, $pub );
	$ak      = sodium_crypto_box_seal_open( $sealed, $keypair );

	sodium_memzero( $priv );
	sodium_memzero( $keypair );

	if ( false === $ak || 32 !== strlen( $ak ) ) {
		throw new Exception(
			'The sealed archive key could not be opened. key.json is internally inconsistent: '
			. 'its sealed key does not belong to its own keypair.',
			ELEVA_BD_EXIT_ARCHIVE
		);
	}
	return $ak;
}

// ---------------------------------------------------------------------------
// The FTNCBK01 stream format
// ---------------------------------------------------------------------------

/**
 * Decrypt one archive member into a file, atomically: output lands on a .part
 * file and is promoted only after the stream ends cleanly. Authenticated
 * plaintext is emitted chunk by chunk as it is verified, so a failure halfway
 * through would otherwise leave a plausible-looking partial file behind.
 *
 * @param ZipArchive       $zip      Open archive.
 * @param string           $name     Member name.
 * @param string           $key      Raw 32-byte archive key.
 * @param string           $target   Destination path.
 * @param HashContext|null $hash_ctx Optional running hash of the plaintext.
 * @return int Plaintext bytes written.
 * @throws Exception On any corruption or write failure.
 */
function eleva_bd_decrypt_member_to_file( ZipArchive $zip, $name, $key, $target, $hash_ctx ) {
	$part = $target . '.part';
	$out  = fopen( $part, 'wb' );
	if ( false === $out ) {
		throw new Exception( 'Could not write to ' . $part . ' (check permissions and free space).', ELEVA_BD_EXIT_USAGE );
	}

	try {
		$written = eleva_bd_decrypt_member( $zip, $name, $key, $out, $hash_ctx );
	} catch ( Exception $e ) {
		fclose( $out );
		@unlink( $part );
		throw $e;
	}

	fclose( $out );

	if ( ! @rename( $part, $target ) ) {
		@unlink( $part );
		throw new Exception( 'Could not finalise ' . $target . '.', ELEVA_BD_EXIT_USAGE );
	}
	@chmod( $target, 0600 );

	return $written;
}

/**
 * Decrypt one FTNCBK01 stream from the archive into an open output stream.
 *
 * Reimplemented from the format documentation, not from plugin code:
 *
 *   header : "FTNCBK01" | chunk_size uint32 | nonce_prefix[8]
 *   record : ct_len uint32 | nonce[12] | ciphertext | tag[16]
 *   nonce  : nonce_prefix || counter uint32   (counter starts at 0)
 *   aad    : header[20] || counter[4] || final_flag[1]
 *   the final record is a terminator: empty plaintext, ct_len == 16, flag 0x01
 *
 * @param ZipArchive       $zip      Open archive.
 * @param string           $name     Member name.
 * @param string           $key      Raw 32-byte archive key.
 * @param resource         $out      Writable stream.
 * @param HashContext|null $hash_ctx Optional running hash of the plaintext.
 * @return int Plaintext bytes written.
 * @throws Exception On truncation, reordering, tampering or any auth failure.
 */
function eleva_bd_decrypt_member( ZipArchive $zip, $name, $key, $out, $hash_ctx ) {
	$in = $zip->getStream( $name );
	if ( ! is_resource( $in ) ) {
		throw new Exception( 'Could not read ' . $name . ' out of the archive.', ELEVA_BD_EXIT_ARCHIVE );
	}

	try {
		$header = eleva_bd_read( $in, ELEVA_BD_HEADER_LEN );
		if ( ELEVA_BD_HEADER_LEN !== strlen( $header ) ) {
			throw new Exception( 'archive corrupted at ' . $name . ': the stream header is truncated.', ELEVA_BD_EXIT_ARCHIVE );
		}
		if ( 0 !== strncmp( $header, ELEVA_BD_MAGIC, 8 ) ) {
			throw new Exception(
				'archive corrupted at ' . $name . ': this is not an Eleva CRM encrypted stream '
				. '(bad magic bytes), or it uses a newer format.',
				ELEVA_BD_EXIT_ARCHIVE
			);
		}

		// chunk_size (bytes 8-11) is informational only: each record carries its
		// own length and the spec says readers must not rely on it.
		$prefix         = substr( $header, 12, ELEVA_BD_PREFIX_LEN );
		$counter        = 0;
		$saw_terminator = false;
		$total          = 0;

		while ( true ) {
			$length_field = eleva_bd_read( $in, 4 );
			if ( '' === $length_field ) {
				break; // Clean end of stream.
			}
			if ( 4 !== strlen( $length_field ) ) {
				throw new Exception( 'archive corrupted at ' . $name . ': truncated record length.', ELEVA_BD_EXIT_ARCHIVE );
			}
			if ( $saw_terminator ) {
				throw new Exception(
					'archive corrupted at ' . $name . ': there is data after the end-of-stream marker. '
					. 'Something was appended to this member.',
					ELEVA_BD_EXIT_ARCHIVE
				);
			}
			if ( $counter > ELEVA_BD_MAX_COUNTER ) {
				throw new Exception( 'archive corrupted at ' . $name . ': record counter overflow.', ELEVA_BD_EXIT_ARCHIVE );
			}

			$unpacked = unpack( 'N', $length_field );
			$ct_len   = (int) $unpacked[1];
			if ( $ct_len < ELEVA_BD_TAG_LEN ) {
				throw new Exception( 'archive corrupted at ' . $name . ': record ' . $counter . ' is shorter than an authentication tag.', ELEVA_BD_EXIT_ARCHIVE );
			}

			$nonce = eleva_bd_read( $in, ELEVA_BD_NONCE_LEN );
			if ( ELEVA_BD_NONCE_LEN !== strlen( $nonce ) ) {
				throw new Exception( 'archive corrupted at ' . $name . ': truncated nonce on record ' . $counter . '.', ELEVA_BD_EXIT_ARCHIVE );
			}
			if ( ! hash_equals( $prefix . pack( 'N', $counter ), $nonce ) ) {
				throw new Exception(
					'archive corrupted at ' . $name . ': record ' . $counter . ' is out of order, comes from '
					. 'a different stream, or its nonce was altered.',
					ELEVA_BD_EXIT_ARCHIVE
				);
			}

			$body = eleva_bd_read( $in, $ct_len - ELEVA_BD_TAG_LEN );
			if ( strlen( $body ) !== $ct_len - ELEVA_BD_TAG_LEN ) {
				throw new Exception( 'archive corrupted at ' . $name . ': record ' . $counter . ' is truncated.', ELEVA_BD_EXIT_ARCHIVE );
			}
			$tag = eleva_bd_read( $in, ELEVA_BD_TAG_LEN );
			if ( ELEVA_BD_TAG_LEN !== strlen( $tag ) ) {
				throw new Exception( 'archive corrupted at ' . $name . ': record ' . $counter . ' has no authentication tag.', ELEVA_BD_EXIT_ARCHIVE );
			}

			$is_terminator = ( ELEVA_BD_TAG_LEN === $ct_len );
			$flag          = $is_terminator ? "\x01" : "\x00";
			$aad           = $header . pack( 'N', $counter ) . $flag;

			$plain = openssl_decrypt( $body, ELEVA_BD_GCM, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad );
			$body  = '';

			if ( false === $plain ) {
				throw new Exception(
					'archive corrupted at ' . $name . ': authentication failed on record ' . $counter . '. '
					. 'This member was damaged or tampered with; its contents are not trustworthy and '
					. 'nothing from it was kept.',
					ELEVA_BD_EXIT_ARCHIVE
				);
			}

			if ( $is_terminator ) {
				$saw_terminator = true;
			} else {
				$length = strlen( $plain );
				if ( $length > 0 ) {
					if ( null !== $hash_ctx ) {
						hash_update( $hash_ctx, $plain );
					}
					eleva_bd_write( $out, $plain, $name );
					$total += $length;
				}
			}

			$plain = '';
			$counter++;
		}

		if ( ! $saw_terminator ) {
			throw new Exception(
				'archive corrupted at ' . $name . ': the end-of-stream marker is missing, so this member '
				. 'is incomplete. Part of the archive was cut off.',
				ELEVA_BD_EXIT_ARCHIVE
			);
		}

		return $total;
	} finally {
		fclose( $in );
	}
}

/**
 * Read exactly $length bytes, looping over short reads. Returns fewer bytes
 * only at end of stream, since zip streams routinely return short reads.
 *
 * @param resource $handle Stream to read.
 * @param int      $length Bytes wanted.
 * @return string
 */
function eleva_bd_read( $handle, $length ) {
	if ( $length <= 0 ) {
		return '';
	}
	$buffer = '';
	while ( strlen( $buffer ) < $length ) {
		$piece = fread( $handle, $length - strlen( $buffer ) );
		if ( false === $piece || '' === $piece ) {
			break;
		}
		$buffer .= $piece;
	}
	return $buffer;
}

/**
 * @param resource $handle Stream to write.
 * @param string   $data   Bytes to write.
 * @param string   $label  Member name for the error message.
 * @return void
 * @throws Exception If the write cannot complete.
 */
function eleva_bd_write( $handle, $data, $label ) {
	$length  = strlen( $data );
	$written = 0;
	while ( $written < $length ) {
		$result = fwrite( $handle, substr( $data, $written ) );
		if ( false === $result || 0 === $result ) {
			throw new Exception( 'Write failed while restoring ' . $label . ': out of disk space?', ELEVA_BD_EXIT_USAGE );
		}
		$written += $result;
	}
}

/**
 * Unwrap a 'w1:' blob: base64( iv[12] || tag[16] || ciphertext ).
 *
 * Note the byte order: the GCM tag sits BEFORE the ciphertext here, which is
 * unusual (most libraries append it). Cross-checked against the plugin's
 * Fotonic_Crypto::wrap().
 *
 * @param string $wrapped Wrapped blob.
 * @param string $kek     Raw 32-byte key-encryption key.
 * @return string|false Plaintext, or false when authentication fails.
 */
function eleva_bd_gcm_unwrap( $wrapped, $kek ) {
	if ( 0 !== strncmp( $wrapped, 'w1:', 3 ) ) {
		return false;
	}
	$raw = base64_decode( substr( $wrapped, 3 ), true );
	if ( false === $raw || strlen( $raw ) < 29 ) {
		return false;
	}
	return openssl_decrypt(
		substr( $raw, 28 ),
		ELEVA_BD_GCM,
		$kek,
		OPENSSL_RAW_DATA,
		substr( $raw, 0, 12 ),
		substr( $raw, 12, 16 )
	);
}

// ---------------------------------------------------------------------------
// TOTP (RFC 6238 / RFC 4226): HMAC-SHA1, 30 s step, 6 digits
// ---------------------------------------------------------------------------

/**
 * @param string $code   6-digit code as typed.
 * @param string $secret Base32 TOTP secret.
 * @param int    $window Steps of tolerance on each side.
 * @return bool
 */
function eleva_bd_totp_verify( $code, $secret, $window ) {
	if ( ! preg_match( '/^\d{6}$/', $code ) ) {
		return false;
	}
	$now = (int) floor( time() / ELEVA_BD_TOTP_STEP );
	for ( $i = -$window; $i <= $window; $i++ ) {
		if ( hash_equals( eleva_bd_hotp( $secret, $now + $i ), $code ) ) {
			return true;
		}
	}
	return false;
}

/**
 * @param string $secret  Base32 secret.
 * @param int    $counter Time step counter.
 * @return string 6-digit zero-padded code.
 */
function eleva_bd_hotp( $secret, $counter ) {
	$key   = eleva_bd_base32_decode( $secret );
	$bytes = pack( 'N', 0 ) . pack( 'N', $counter ); // 64-bit big-endian counter.
	$hash  = hash_hmac( 'sha1', $bytes, $key, true );

	$offset = ord( $hash[19] ) & 0x0F;
	$binary = ( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 )
		| ( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 )
		| ( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8 )
		| ( ord( $hash[ $offset + 3 ] ) & 0xFF );

	return str_pad( (string) ( $binary % 1000000 ), ELEVA_BD_TOTP_DIGITS, '0', STR_PAD_LEFT );
}

/**
 * RFC 4648 base32 decode; padding stripped, unknown characters ignored.
 *
 * @param string $input Base32 text.
 * @return string Raw bytes.
 */
function eleva_bd_base32_decode( $input ) {
	$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
	$map      = array_flip( str_split( $alphabet ) );
	$input    = rtrim( strtoupper( trim( $input ) ), '=' );

	$buffer = 0;
	$bits   = 0;
	$output = '';

	$length = strlen( $input );
	for ( $i = 0; $i < $length; $i++ ) {
		$char = $input[ $i ];
		if ( ! isset( $map[ $char ] ) ) {
			continue;
		}
		$buffer = ( $buffer << 5 ) | $map[ $char ];
		$bits  += 5;
		if ( $bits >= 8 ) {
			$bits   -= 8;
			$output .= chr( ( $buffer >> $bits ) & 0xFF );
		}
	}
	return $output;
}

/**
 * Recovery codes and phrases are matched after stripping every separator and
 * upper-casing, so "abcd-2345 wxyz" and "ABCD2345WXYZ" derive the same key.
 *
 * @param string $value Raw user input.
 * @return string
 */
function eleva_bd_normalize_code( $value ) {
	return strtoupper( preg_replace( '/[^a-zA-Z0-9]/', '', $value ) );
}

// ---------------------------------------------------------------------------
// Output directory
// ---------------------------------------------------------------------------

/**
 * @param array $options Parsed CLI options.
 * @return string Absolute output directory.
 * @throws Exception If it exists with content and --force was not given.
 */
function eleva_bd_prepare_out_dir( array $options ) {
	$dir = isset( $options['out'] ) ? $options['out'] : 'out';

	if ( is_dir( $dir ) ) {
		$entries = array_diff( (array) scandir( $dir ), array( '.', '..' ) );
		if ( ! empty( $entries ) && ! isset( $options['force'] ) ) {
			throw new Exception(
				$dir . ' already exists and is not empty. Choose another --out=DIR, or add --force '
				. 'to write into it anyway.',
				ELEVA_BD_EXIT_USAGE
			);
		}
	} else {
		eleva_bd_make_dir( $dir );
	}

	$real = realpath( $dir );
	return ( false === $real ) ? $dir : $real;
}

/**
 * @param string $dir Directory to create (0700, this holds plaintext PII).
 * @return void
 * @throws Exception If creation fails.
 */
function eleva_bd_make_dir( $dir ) {
	if ( is_dir( $dir ) ) {
		return;
	}
	if ( ! mkdir( $dir, 0700, true ) && ! is_dir( $dir ) ) {
		throw new Exception( 'Could not create the output directory: ' . $dir, ELEVA_BD_EXIT_USAGE );
	}
}

/**
 * @param string $path     File to write.
 * @param string $contents Bytes.
 * @return void
 * @throws Exception If the write fails.
 */
function eleva_bd_write_file( $path, $contents ) {
	if ( false === file_put_contents( $path, $contents ) ) {
		throw new Exception( 'Could not write ' . $path, ELEVA_BD_EXIT_USAGE );
	}
	@chmod( $path, 0600 );
}

/**
 * Map attachment ID -> original filename, read out of the decrypted data.json
 * (the manifest deliberately carries no filenames, because they routinely
 * contain customer names). Falls back to an empty map for very large payloads
 * so a huge data.json is never loaded into memory just for nicer names.
 *
 * @param string $data_json_path Path to the decrypted data.json.
 * @return array
 */
function eleva_bd_attachment_names( $data_json_path ) {
	$names = array();

	$size = filesize( $data_json_path );
	if ( false === $size || $size > 67108864 ) {
		return $names;
	}

	$decoded = json_decode( (string) file_get_contents( $data_json_path ), true );
	if ( ! is_array( $decoded ) || empty( $decoded['attachments'] ) || ! is_array( $decoded['attachments'] ) ) {
		return $names;
	}

	foreach ( $decoded['attachments'] as $attachment ) {
		if ( ! isset( $attachment['id'] ) ) {
			continue;
		}
		$source = '';
		if ( ! empty( $attachment['attached_file'] ) ) {
			$source = (string) $attachment['attached_file'];
		} elseif ( ! empty( $attachment['post_title'] ) ) {
			$source = (string) $attachment['post_title'];
		}
		$basename = eleva_bd_safe_basename( $source );
		if ( '' !== $basename ) {
			$names[ (int) $attachment['id'] ] = $basename;
		}
	}
	return $names;
}

/**
 * Filenames come from the archive, so they are untrusted input: strip any path
 * component and anything that could escape the output directory.
 *
 * @param string $value Raw name from data.json.
 * @return string Safe basename, possibly ''.
 */
function eleva_bd_safe_basename( $value ) {
	$value = str_replace( '\\', '/', $value );
	$value = substr( $value, (int) strrpos( '/' . $value, '/' ) );
	$value = preg_replace( '/[^A-Za-z0-9._-]+/', '_', $value );
	$value = ltrim( (string) $value, '.' );
	return ( strlen( $value ) > 100 ) ? substr( $value, -100 ) : $value;
}

/**
 * @return resource A stream that discards everything written to it.
 */
function eleva_bd_null_stream() {
	return fopen( 'php://memory', 'wb' );
}

// ---------------------------------------------------------------------------
// CLI plumbing
// ---------------------------------------------------------------------------

/**
 * @param array $argv Raw arguments.
 * @return array Parsed options; the archive path lands under 'archive'.
 */
function eleva_bd_parse_args( array $argv ) {
	$options = array();
	$count   = count( $argv );

	for ( $i = 1; $i < $count; $i++ ) {
		$arg = $argv[ $i ];

		if ( 0 === strncmp( $arg, '--', 2 ) ) {
			$body  = substr( $arg, 2 );
			$eq    = strpos( $body, '=' );
			$name  = ( false === $eq ) ? $body : substr( $body, 0, $eq );
			$value = ( false === $eq ) ? true : substr( $body, $eq + 1 );
			$options[ $name ] = $value;
			continue;
		}
		if ( '-h' === $arg ) {
			$options['help'] = true;
			continue;
		}
		if ( ! isset( $options['archive'] ) ) {
			$options['archive'] = $arg;
			continue;
		}
		$options['extra'] = $arg;
	}

	return $options;
}

/**
 * @param array $options Parsed options.
 * @return void
 * @throws Exception On an unusable combination.
 */
function eleva_bd_validate_options( array $options ) {
	$known = array(
		'archive'         => true,
		'out'             => true,
		'data-only'       => true,
		'verify-only'     => true,
		'no-totp'         => true,
		'recovery-code'   => true,
		'recovery-phrase' => true,
		'password-stdin'  => true,
		'force'           => true,
		'help'            => true,
		'version'         => true,
	);

	foreach ( array_keys( $options ) as $name ) {
		if ( ! isset( $known[ $name ] ) ) {
			throw new Exception( 'Unknown option: --' . $name . '. Run with --help for the list.', ELEVA_BD_EXIT_USAGE );
		}
	}
	if ( isset( $options['extra'] ) ) {
		throw new Exception( 'Only one archive can be decrypted at a time.', ELEVA_BD_EXIT_USAGE );
	}
	if ( ! isset( $options['archive'] ) || ! is_string( $options['archive'] ) ) {
		throw new Exception( 'Which archive? Usage: php eleva-backup-decrypt.php <archive.zip>', ELEVA_BD_EXIT_USAGE );
	}
	if ( isset( $options['recovery-code'] ) && isset( $options['recovery-phrase'] ) ) {
		throw new Exception( 'Use either --recovery-code or --recovery-phrase, not both.', ELEVA_BD_EXIT_USAGE );
	}
	foreach ( array( 'out', 'recovery-code', 'recovery-phrase' ) as $needs_value ) {
		if ( isset( $options[ $needs_value ] ) && ! is_string( $options[ $needs_value ] ) ) {
			throw new Exception( '--' . $needs_value . ' needs a value, e.g. --' . $needs_value . '=...', ELEVA_BD_EXIT_USAGE );
		}
	}

	// The OTP check is what proves the person holding the archive also holds the
	// authenticator. Skipping it is only ever acceptable for an integrity/key
	// chain test that produces no plaintext output.
	if ( isset( $options['no-totp'] ) && ! isset( $options['verify-only'] ) ) {
		throw new Exception(
			'--no-totp may only be used together with --verify-only. It exists to test the key chain '
			. 'without an authenticator; it is never a way to extract data without one.',
			ELEVA_BD_EXIT_USAGE
		);
	}
	if ( isset( $options['verify-only'] ) && isset( $options['out'] ) ) {
		throw new Exception( '--verify-only writes nothing, so --out has no effect. Drop one of them.', ELEVA_BD_EXIT_USAGE );
	}
}

/**
 * @return void
 * @throws Exception If PHP is too old or an extension is missing.
 */
function eleva_bd_check_environment() {
	if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
		throw new Exception( 'This tool needs PHP 7.4 or newer; this is PHP ' . PHP_VERSION . '.', ELEVA_BD_EXIT_USAGE );
	}

	$missing = array();
	if ( ! function_exists( 'openssl_decrypt' ) ) {
		$missing[] = 'openssl';
	}
	if ( ! function_exists( 'sodium_crypto_box_seal_open' ) ) {
		$missing[] = 'sodium';
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		$missing[] = 'zip';
	}
	if ( ! function_exists( 'json_decode' ) ) {
		$missing[] = 'json';
	}
	if ( ! function_exists( 'hash_pbkdf2' ) ) {
		$missing[] = 'hash';
	}
	if ( ! empty( $missing ) ) {
		throw new Exception(
			'This PHP is missing the ' . implode( ', ', $missing ) . ' extension(s), which the archive '
			. 'format needs. Install a standard PHP build (the official packages include all of them).',
			ELEVA_BD_EXIT_USAGE
		);
	}
	if ( ! in_array( ELEVA_BD_GCM, openssl_get_cipher_methods(), true ) ) {
		throw new Exception( 'This PHP build cannot do AES-256-GCM, which the archive format needs.', ELEVA_BD_EXIT_USAGE );
	}
}

/**
 * Read a secret from the terminal without echoing it.
 *
 * Echo stays off from the first hidden prompt until either a visible prompt asks
 * for it back or the tool exits. It is NOT restored between retries: restoring
 * it in between opens a window where a password typed (or pasted) a moment
 * before the next prompt appears is echoed to the screen by the terminal itself.
 *
 * @param string $label  Prompt text.
 * @param bool   $hidden Whether to suppress echo.
 * @return string
 * @throws Exception If nothing can be read.
 */
function eleva_bd_prompt_secret( $label, $hidden = true ) {
	eleva_bd_tty_echo( ! $hidden );

	fwrite( STDOUT, $label );
	$line = fgets( STDIN );

	if ( $hidden ) {
		fwrite( STDOUT, PHP_EOL ); // The user's own Enter was not echoed.
	}

	if ( false === $line ) {
		throw new Exception( 'No input received. Nothing was decrypted.', ELEVA_BD_EXIT_CREDENTIALS );
	}
	return rtrim( $line, "\r\n" );
}

/**
 * Turn terminal echo on or off, remembering the original settings the first
 * time so they can be put back however the tool ends.
 *
 * A no-op when stdin is not a terminal, or where stty is unavailable (Windows
 * without a POSIX shell); in that case a password is unavoidably visible, which
 * is why --password-stdin and ELEVA_BACKUP_PASSWORD exist.
 *
 * @param bool $enabled True to echo typing, false to hide it.
 * @return void
 */
function eleva_bd_tty_echo( $enabled ) {
	if ( ! function_exists( 'shell_exec' ) || ! function_exists( 'stream_isatty' ) || ! stream_isatty( STDIN ) ) {
		return;
	}

	if ( null === eleva_bd_tty_saved() ) {
		$saved = shell_exec( 'stty -g 2>/dev/null' );
		if ( ! is_string( $saved ) || '' === trim( $saved ) ) {
			eleva_bd_tty_saved( false ); // No usable stty; give up quietly, once.
			return;
		}
		eleva_bd_tty_saved( trim( $saved ) );

		// However this run ends (success, refusal, uncaught error), the terminal
		// must not be left unable to echo what the user types next.
		register_shutdown_function( 'eleva_bd_tty_restore' );
		if ( function_exists( 'pcntl_async_signals' ) && function_exists( 'pcntl_signal' ) ) {
			pcntl_async_signals( true );
			pcntl_signal( SIGINT, 'eleva_bd_tty_signal' );
			pcntl_signal( SIGTERM, 'eleva_bd_tty_signal' );
		}
	}

	if ( false === eleva_bd_tty_saved() ) {
		return;
	}
	shell_exec( $enabled ? 'stty echo 2>/dev/null' : 'stty -echo 2>/dev/null' );
}

/**
 * Holder for the terminal settings captured before echo was first touched.
 *
 * @param string|bool|null $set New value, or null to read.
 * @return string|bool|null
 */
function eleva_bd_tty_saved( $set = null ) {
	static $saved = null;
	if ( null !== $set ) {
		$saved = $set;
	}
	return $saved;
}

/**
 * @return void
 */
function eleva_bd_tty_restore() {
	$saved = eleva_bd_tty_saved();
	if ( is_string( $saved ) && '' !== $saved ) {
		shell_exec( 'stty ' . escapeshellarg( $saved ) . ' 2>/dev/null' );
	}
}

/**
 * @param int $signal Signal number.
 * @return void
 */
function eleva_bd_tty_signal( $signal ) {
	eleva_bd_tty_restore();
	fwrite( STDERR, PHP_EOL . 'Interrupted. Nothing was decrypted.' . PHP_EOL );
	exit( 128 + (int) $signal );
}

/**
 * @param array  $manifest Decoded manifest.
 * @param string $field    Field name.
 * @return string Printable value.
 */
function eleva_bd_manifest_string( array $manifest, $field ) {
	if ( ! isset( $manifest[ $field ] ) || ! is_scalar( $manifest[ $field ] ) ) {
		return '(unknown)';
	}
	return (string) $manifest[ $field ];
}

/**
 * @param int|float $bytes Byte count.
 * @return string
 */
function eleva_bd_human_bytes( $bytes ) {
	$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
	$value = (float) $bytes;
	$index = 0;
	while ( $value >= 1024 && $index < count( $units ) - 1 ) {
		$value /= 1024;
		$index++;
	}
	return ( 0 === $index ) ? ( (int) $value . ' B' ) : ( number_format( $value, 1 ) . ' ' . $units[ $index ] );
}

/**
 * @param string $message Line for stdout.
 * @return void
 */
function eleva_bd_say( $message ) {
	fwrite( STDOUT, $message . PHP_EOL );
}

/**
 * @param string $message Line for stderr, prefixed.
 * @return void
 */
function eleva_bd_warn( $message ) {
	fwrite( STDERR, '[warn] ' . $message . PHP_EOL );
}

/**
 * @param string $message Failure text.
 * @return void
 */
function eleva_bd_error( $message ) {
	fwrite( STDERR, PHP_EOL . '[FAILED] ' . $message . PHP_EOL );
}

/**
 * @return void
 */
function eleva_bd_usage() {
	$usage = <<<'TXT'
Eleva CRM for Photographers: standalone backup decryptor

  Decrypts a backup archive on any machine. No WordPress, no database, no
  plugin, no internet. Just PHP 7.4+ with openssl, sodium and zip.

USAGE
  php eleva-backup-decrypt.php <archive.zip> [options]

WHAT YOU NEED
  - the archive .zip
  - your vault password AND the 6-digit code from your authenticator app
      ...or your recovery code       (--recovery-code=ABCD-2345-...)
      ...or your recovery phrase     (--recovery-phrase="A1B2C3-D4E5F6-...")

OPTIONS
  --out=DIR              Where to write the result (default: ./out).
  --force                Write into --out even if it already has files in it.
  --data-only            Restore data.json only; skip the attached files.
  --verify-only          Check the archive without decrypting anything to disk.
                         With no credentials it checks the checksums and the key
                         material's shape, and never prompts.
  --recovery-code=CODE   Unlock with the recovery code instead of the password.
  --recovery-phrase=P    Unlock with the recovery phrase instead of the password.
  --password-stdin       Read the password from standard input (for scripts).
  --no-totp              Skip the 6-digit code. Only allowed with --verify-only.
  --help, --version

ENVIRONMENT (for scripts; prefer the prompts when working by hand)
  ELEVA_BACKUP_PASSWORD  Vault password.
  ELEVA_BACKUP_OTP       6-digit code.

OUTPUT
  out/data.json       every record: customers, works, services, memory cards,
                      settings. Personal fields inside it stay encrypted exactly
                      as the plugin stored them.
  out/files/          your attachments (contracts, invoices, photos), decrypted
                      and byte-for-byte identical to the originals.
  out/manifest.json   a copy of the archive's plaintext inventory.

EXIT CODES
  0 success   1 usage/environment   2 archive problem   3 wrong credentials

TXT;
	fwrite( STDOUT, $usage . PHP_EOL );
}
