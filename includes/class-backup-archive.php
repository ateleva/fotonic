<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Builds a single encrypted backup archive from everything Fotonic_Backup_Registry
 * declares. No UI, no REST route, no destination — create() writes a .zip to disk
 * and returns its path. Phase 6 (manual download) and Phase 9 (Pro's scheduler)
 * both call create() directly.
 *
 * MUST run correctly with the vault LOCKED: cron fires with no session cookie, so
 * this class never reads the vault's unlock state or session key, and never
 * routes a value through the REST layer's decrypt helper. Every value collected
 * is copied verbatim from postmeta/options —
 * PII stays exactly as ciphertext as it already sits in the DB. Only
 * Fotonic_Backup_Keys::seal() (public-key only) and Fotonic_Backup_Cipher
 * (raw AK only) touch cryptography here, and neither needs the vault.
 *
 * =============================================================================
 * ARCHIVE LAYOUT (CONTRACT: Phase 5's tool and the future v2 importer both read
 * manifest.json and data.enc as documented here.)
 * =============================================================================
 *
 *   manifest.json   plaintext. Format version, plugin/WP versions, site URL,
 *                   timestamp, which post_status values were included, per-dataset
 *                   record counts, per-member size + SHA-256, missing_files.
 *                   CONTAINS NO PII: no customer name/email/phone, no work title,
 *                   no attachment filename. Only opaque IDs, counts, sizes, hashes,
 *                   MIME types. A user must be able to sanity-check an archive
 *                   without ever unlocking the vault.
 *
 *   key.json        plaintext (it IS the key material — see class-backup-keys.php
 *                   for why sealing to a public key is safe to store unencrypted).
 *                   sealed_ak (base64), backup_pubkey, backup_wrap_priv, and the
 *                   vault wrap/salt options needed to unwrap everything above on a
 *                   brand-new machine with brand-new wp-config.php salts.
 *
 *   data.enc        FTNCBK01 stream (Fotonic_Backup_Cipher), key = AK. Decrypts to
 *                   a JSON document: { format, generated_at, datasets: { <dataset
 *                   key>: [ <post record>, ... ] }, terms: { <taxonomy>: [ <term
 *                   definition>, ... ] }, options: { <name>: <value> }, attachments:
 *                   [ <attachment record>, ... ] }. THIS is where attachment
 *                   filenames/titles live — they can be identifying, so they never
 *                   touch the plaintext manifest.
 *
 *   files/{id}.enc  FTNCBK01 stream, key = AK, one per referenced attachment.
 *                   {id} is the WP attachment post ID. No size limit, ever.
 *
 * Post record shape: { ID, post_status, post_date_gmt, post_modified_gmt,
 *   post_title? (if 'title' in supports), post_content? (if 'content' in
 *   supports), meta: { <declared key>: <verbatim raw meta value> },
 *   terms: { <non-derived taxonomy>: [ {term_id, slug}, ... ] },
 *   derived_terms: { <derived=true taxonomy>: [ {term_id, slug}, ... ] } }
 *
 * A derived taxonomy's terms are recorded under derived_terms specifically so a
 * future importer cannot mistake them for something to restore — ftnc_work_payment
 * status must be recomputed by auto_assign_payment_status(), never written back.
 *
 * Attachment record shape: { id, post_title, mime, attached_file,
 *   metadata (the raw _wp_attachment_metadata array), size (plaintext bytes),
 *   sha256 (of the plaintext file, so Phase 5 can verify restore fidelity by
 *   hashing what it decrypts and comparing — GCM's tag already guarantees
 *   decrypt-or-throw, but this additionally proves the ORIGINAL upload matches
 *   what got archived, end to end) }.
 *
 * =============================================================================
 */
class Fotonic_Backup_Archive {

    const FORMAT_VERSION = 1;
    const LOCK_KEY        = 'fotonic_backup_running';
    const LOCK_TTL         = 1800; // 30 minutes.
    const POSTS_PER_PAGE   = 200;

    // Every non-trash status plus trash itself — see class docblock: a disaster
    // recovery may well need a work the user trashed by accident.
    const POST_STATUSES = array( 'publish', 'draft', 'pending', 'private', 'trash' );

    /**
     * Build one backup archive.
     *
     * @param array $args Reserved for future use (e.g. dataset filtering). Unused today.
     * @return array|\WP_Error array( 'path' => string, 'size' => int, 'manifest' => array )
     */
    public static function create( $args = array() ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new \WP_Error( 'ftnc_backup_no_zip', __( 'The PHP Zip extension is not available on this server.', 'eleva-crm-for-photographers' ) );
        }
        if ( ! Fotonic_Backup_Cipher::is_supported() ) {
            return new \WP_Error( 'ftnc_backup_no_openssl', __( 'AES-256-GCM is not available on this server.', 'eleva-crm-for-photographers' ) );
        }
        if ( ! Fotonic_Backup_Keys::is_supported() ) {
            return new \WP_Error( 'ftnc_backup_no_sodium', Fotonic_Backup_Keys::unavailable_reason() );
        }
        if ( ! Fotonic_Backup_Keys::is_ready() ) {
            return new \WP_Error( 'ftnc_backup_keys_not_ready', __( 'Backup keys are not set up yet. Unlock the vault once to generate them.', 'eleva-crm-for-photographers' ) );
        }

        if ( ! self::acquire_lock() ) {
            return new \WP_Error( 'ftnc_backup_already_running', __( 'A backup is already running.', 'eleva-crm-for-photographers' ) );
        }

        $backup_dir = self::backup_dir();
        $tmp_path   = $backup_dir . '/.tmp-' . wp_generate_password( 16, false, false ) . '.zip';
        $final_path = null;
        $temp_files = array();

        // Long-running, non-interactive job: no execution-time cap, and a client
        // disconnect (cron has none anyway) must not abort a half-written archive.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort only; disable_functions may block this on some hosts.
        }
        ignore_user_abort( true );

        try {
            $ak = random_bytes( 32 );

            $zip    = new \ZipArchive();
            $opened = $zip->open( $tmp_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );
            if ( true !== $opened ) {
                throw new \RuntimeException( 'Could not create archive (ZipArchive error ' . $opened . ').' );
            }

            $manifest = array(
                'format'                 => self::FORMAT_VERSION,
                'generated_at'           => gmdate( 'c' ),
                'site_url'               => home_url(),
                'wp_version'             => get_bloginfo( 'version' ),
                'plugin_version'         => defined( 'FOTONIC_VERSION' ) ? FOTONIC_VERSION : null,
                'pro_version'            => defined( 'FOTO_PRO_VERSION' ) ? FOTO_PRO_VERSION : null,
                'included_post_statuses' => self::POST_STATUSES,
                'datasets'               => array(),
                'members'                => array(),
                'missing_files'          => array(),
                'total_size'             => 0,
            );

            $datasets         = Fotonic_Backup_Registry::datasets();
            $collected        = self::collect_all_datasets( $datasets, $manifest );
            $payload_datasets = $collected['payload'];
            $attachment_ids   = $collected['attachment_ids'];
            $manifest         = $collected['manifest'];

            $terms   = self::collect_terms( $datasets );
            $options = self::collect_options();

            list( $attachments_payload, $manifest ) = self::collect_attachments( $attachment_ids, $zip, $ak, $manifest, $temp_files );

            // ---- data.enc ---------------------------------------------------
            $data_tmp = self::new_temp_file( $temp_files );
            self::write_data_json_stream( $data_tmp, $payload_datasets, $terms, $options, $attachments_payload );

            $data_enc_tmp = self::new_temp_file( $temp_files );
            self::encrypt_file_to_file( $data_tmp, $data_enc_tmp, $ak );
            self::add_member( $zip, $manifest, $data_enc_tmp, 'data.enc', hash_file( 'sha256', $data_enc_tmp ) );

            // ---- key.json ----------------------------------------------------
            $sealed_ak = Fotonic_Backup_Keys::seal( $ak );
            if ( false === $sealed_ak ) {
                throw new \RuntimeException( 'Could not seal the archive key.' );
            }
            $key_json = self::build_key_json( $sealed_ak );
            $key_tmp  = self::new_temp_file( $temp_files );
            file_put_contents( $key_tmp, $key_json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- temp file, not user-facing, same rationale as class-activator.php.
            self::add_member( $zip, $manifest, $key_tmp, 'key.json', hash( 'sha256', $key_json ) );

            self::memzero( $ak );

            // ---- manifest.json last, so it can hash everything above it -----
            $manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
            $zip->addFromString( 'manifest.json', $manifest_json );
            $zip->setCompressionName( 'manifest.json', \ZipArchive::CM_STORE );

            $zip->close();

            self::cleanup_temp_files( $temp_files );

            $final_name = 'eleva-backup-' . gmdate( 'Y-m-d-His' ) . '-' . bin2hex( random_bytes( 4 ) ) . '.zip';
            $final_path = $backup_dir . '/' . $final_name;
            if ( ! rename( $tmp_path, $final_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rename -- local filesystem move within our own backup dir, no user input.
                throw new \RuntimeException( 'Could not finalize the archive file.' );
            }

            self::release_lock();

            return array(
                'path'     => $final_path,
                'size'     => filesize( $final_path ),
                'manifest' => $manifest,
            );
        } catch ( \Throwable $e ) {
            self::cleanup_temp_files( $temp_files );
            // The half-written temp zip must never survive a failure — it would
            // look like a backup and not be one.
            if ( file_exists( $tmp_path ) ) {
                @unlink( $tmp_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup; nothing more to do if this fails.
            }
            self::release_lock();
            return new \WP_Error( 'ftnc_backup_failed', $e->getMessage() );
        }
    }

    /**
     * uploads/fotonic/backups/ — created with a deny-all .htaccess and an empty
     * index.php on first use. Same direct-filesystem pattern as the vault dir in
     * Fotonic_Activator::activate() (WP_Filesystem needs interactive credentials
     * this code path never has).
     *
     * @return string Absolute path, no trailing slash.
     */
    public static function backup_dir(): string {
        $upload_dir = wp_upload_dir();
        $dir        = $upload_dir['basedir'] . '/fotonic/backups';

        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- one-time write to our own controlled path, no user input; WP_Filesystem needs interactive auth unavailable here.
            file_put_contents( $dir . '/.htaccess', "Require all denied\n# Block direct access to Fotonic backup archives\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>" );
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- see above.
            file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
        }

        return $dir;
    }

    // ---------------------------------------------------------------------------
    // Locking
    // ---------------------------------------------------------------------------

    /**
     * @return bool True if the lock was acquired.
     */
    private static function acquire_lock(): bool {
        if ( false !== get_transient( self::LOCK_KEY ) ) {
            return false;
        }
        set_transient( self::LOCK_KEY, time(), self::LOCK_TTL );
        return true;
    }

    /**
     * @return void
     */
    private static function release_lock(): void {
        delete_transient( self::LOCK_KEY );
    }

    // ---------------------------------------------------------------------------
    // Dataset collection (posts + their own meta/terms)
    // ---------------------------------------------------------------------------

    /**
     * Walk every registered dataset, paged, collecting records for data.json and
     * every referenced attachment ID. Never decrypts anything — meta is copied
     * with get_post_meta() exactly as stored.
     *
     * @param array $datasets Fotonic_Backup_Registry::datasets() output.
     * @param array $manifest Manifest being built; per-dataset counts are added.
     * @return array{payload: array, attachment_ids: int[], manifest: array}
     */
    private static function collect_all_datasets( array $datasets, array $manifest ): array {
        $payload        = array();
        $attachment_ids = array();

        foreach ( $datasets as $dataset_key => $dataset ) {
            $records = array();
            $paged   = 1;

            while ( true ) {
                $query = new \WP_Query( array(
                    'post_type'              => $dataset['post_type'],
                    'post_status'            => self::POST_STATUSES,
                    'posts_per_page'         => self::POSTS_PER_PAGE,
                    'paged'                  => $paged,
                    'no_found_rows'          => true,
                    'orderby'                => 'ID',
                    'order'                  => 'ASC',
                    'update_post_term_cache' => false,
                    'suppress_filters'       => true,
                ) );

                if ( empty( $query->posts ) ) {
                    break;
                }

                foreach ( $query->posts as $post ) {
                    $record            = self::collect_post_record( $post, $dataset );
                    $records[]         = $record;
                    $attachment_ids    = array_merge( $attachment_ids, self::extract_attachment_ids( $dataset, $record ) );
                }

                if ( count( $query->posts ) < self::POSTS_PER_PAGE ) {
                    break;
                }
                $paged++;
            }

            $payload[ $dataset_key ]            = $records;
            $manifest['datasets'][ $dataset_key ] = array( 'count' => count( $records ) );
        }

        return array(
            'payload'        => $payload,
            'attachment_ids' => array_values( array_unique( $attachment_ids ) ),
            'manifest'       => $manifest,
        );
    }

    /**
     * @param \WP_Post $post    Post being captured.
     * @param array    $dataset Dataset definition from the registry.
     * @return array Record shape documented in the class docblock.
     */
    private static function collect_post_record( \WP_Post $post, array $dataset ): array {
        $record = array(
            'ID'                => $post->ID,
            'post_status'       => $post->post_status,
            'post_date_gmt'     => $post->post_date_gmt,
            'post_modified_gmt' => $post->post_modified_gmt,
        );

        if ( in_array( 'title', $dataset['supports'], true ) ) {
            $record['post_title'] = $post->post_title;
        }
        if ( in_array( 'content', $dataset['supports'], true ) ) {
            $record['post_content'] = $post->post_content;
        }

        $meta = array();
        foreach ( $dataset['meta_keys'] as $key ) {
            // Verbatim: no decryption, no vault access. Ciphertext stays ciphertext.
            $meta[ $key ] = get_post_meta( $post->ID, $key, true );
        }
        $record['meta'] = $meta;

        $terms         = array();
        $derived_terms = array();
        foreach ( $dataset['taxonomies'] as $taxonomy => $tax_opts ) {
            $assigned = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'all' ) );
            if ( is_wp_error( $assigned ) ) {
                $assigned = array();
            }
            $entries = array();
            foreach ( $assigned as $term ) {
                $entries[] = array( 'term_id' => $term->term_id, 'slug' => $term->slug );
            }
            if ( ! empty( $tax_opts['derived'] ) ) {
                $derived_terms[ $taxonomy ] = $entries;
            } else {
                $terms[ $taxonomy ] = $entries;
            }
        }
        $record['terms']         = $terms;
        $record['derived_terms'] = $derived_terms;

        return $record;
    }

    /**
     * Pull attachment IDs out of a record's meta, driven entirely by the
     * registry's ref_fields — no field names are hardcoded here, so a future
     * Pro dataset (e.g. product galleries) is picked up automatically as long as
     * it declares its attachment-bearing field the same way free's ftnc_work_files
     * does: 'json:[].<key>|attachment'.
     *
     * A ref_fields value is usually a single kind string, but a field can carry
     * more than one reference kind at once (e.g. Pro's _ftnc_collaborators holds
     * both a collaborator post id and term ids) — declared as an array of kind
     * strings in that case. `(array) $kind` normalizes a bare string to a
     * single-element array so both shapes go through the same loop unchanged.
     *
     * @param array $dataset Dataset definition.
     * @param array $record  Record already collected for this post (reads ->meta).
     * @return int[] Attachment IDs referenced by this record.
     */
    private static function extract_attachment_ids( array $dataset, array $record ): array {
        $ids = array();

        foreach ( $dataset['ref_fields'] as $meta_key => $kinds ) {
            foreach ( (array) $kinds as $kind ) {
                if ( 'attachment' === $kind ) {
                    $id = (int) ( $record['meta'][ $meta_key ] ?? 0 );
                    if ( $id > 0 ) {
                        $ids[] = $id;
                    }
                    continue;
                }

                if ( 0 === strpos( $kind, 'json:[].' ) && self::str_ends_with( $kind, '|attachment' ) ) {
                    $field = substr( $kind, 8, -11 ); // strip 'json:[].' prefix and '|attachment' suffix.
                    $raw   = $record['meta'][ $meta_key ] ?? '';
                    foreach ( self::extract_json_array_ids( $raw, $field ) as $id ) {
                        $ids[] = $id;
                    }
                }
            }
        }

        return $ids;
    }

    /**
     * Generic "array of {field: id} or bare-id" JSON extractor, matching the
     * exact normalization class-rest-api.php already applies to _ftnc_work_files
     * (legacy bare-integer entries treated as {type: 'attachment', id: N}; 'link'
     * type entries excluded since a URL is not a local attachment).
     *
     * @param string $raw_json  Raw meta value (a JSON-encoded array, or empty).
     * @param string $id_field  Object key holding the ID (usually 'id').
     * @return int[]
     */
    private static function extract_json_array_ids( string $raw_json, string $id_field ): array {
        $ids = array();
        if ( '' === $raw_json ) {
            return $ids;
        }
        $decoded = json_decode( $raw_json, true );
        if ( ! is_array( $decoded ) ) {
            return $ids;
        }
        foreach ( $decoded as $entry ) {
            if ( is_numeric( $entry ) ) {
                $entry = array( 'type' => 'attachment', $id_field => (int) $entry );
            }
            if ( ! is_array( $entry ) ) {
                continue;
            }
            if ( 'id' === $id_field && ( $entry['type'] ?? 'attachment' ) === 'link' ) {
                continue; // External URL, not a local attachment.
            }
            $id = (int) ( $entry[ $id_field ] ?? 0 );
            if ( $id > 0 ) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    // ---------------------------------------------------------------------------
    // Term vocabularies
    // ---------------------------------------------------------------------------

    /**
     * Full term list for every taxonomy any dataset declares — not just the
     * terms currently assigned to a post. Needed so a future ID-referenced
     * taxonomy (e.g. Pro's collaborator services) round-trips completely even
     * for terms no post currently uses.
     *
     * @param array $datasets Registry datasets.
     * @return array<string, array> taxonomy => list of term definitions.
     */
    private static function collect_terms( array $datasets ): array {
        $taxonomies = array();
        foreach ( $datasets as $dataset ) {
            foreach ( array_keys( $dataset['taxonomies'] ) as $tax ) {
                $taxonomies[ $tax ] = true;
            }
        }

        $out = array();
        foreach ( array_keys( $taxonomies ) as $taxonomy ) {
            $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
            if ( is_wp_error( $terms ) ) {
                $terms = array();
            }
            $list = array();
            foreach ( $terms as $term ) {
                $list[] = array(
                    'term_id'     => $term->term_id,
                    'slug'        => $term->slug,
                    'name'        => $term->name,
                    'description' => $term->description,
                    'parent'      => $term->parent,
                );
            }
            $out[ $taxonomy ] = $list;
        }
        return $out;
    }

    // ---------------------------------------------------------------------------
    // Options
    // ---------------------------------------------------------------------------

    /**
     * @return array<string, mixed> option name => raw stored value.
     */
    private static function collect_options(): array {
        $out = array();
        foreach ( Fotonic_Backup_Registry::options() as $name ) {
            $out[ $name ] = get_option( $name );
        }
        return $out;
    }

    // ---------------------------------------------------------------------------
    // Attachments — no size cap, ever
    // ---------------------------------------------------------------------------

    /**
     * Stream every referenced attachment into files/{id}.enc. A missing file is
     * recorded in manifest['missing_files'] and skipped — never a hard failure.
     *
     * @param int[]        $attachment_ids Deduplicated attachment IDs to collect.
     * @param \ZipArchive  $zip            Open archive to add members to.
     * @param string       $ak             Raw 32-byte archive key.
     * @param array        $manifest       Manifest being built.
     * @param array        $temp_files     Accumulator of temp paths to clean up.
     * @return array{0: array, 1: array} [attachments payload for data.json, updated manifest]
     */
    private static function collect_attachments( array $attachment_ids, \ZipArchive $zip, string $ak, array $manifest, array &$temp_files ): array {
        $payload = array();

        foreach ( $attachment_ids as $id ) {
            $post = get_post( $id );
            $path = $post ? get_attached_file( $id ) : false;

            if ( ! $post || 'attachment' !== $post->post_type || ! $path || ! file_exists( $path ) ) {
                $manifest['missing_files'][] = array(
                    'attachment_id' => $id,
                    'reason'        => ! $post ? 'attachment post not found' : ( 'attachment' !== $post->post_type ? 'post is not an attachment' : 'file missing on disk' ),
                );
                continue;
            }

            $sha256 = hash_file( 'sha256', $path );
            $size   = filesize( $path );

            $enc_tmp = self::new_temp_file( $temp_files );
            self::encrypt_file_to_file( $path, $enc_tmp, $ak );

            $member_name = 'files/' . $id . '.enc';
            self::add_member( $zip, $manifest, $enc_tmp, $member_name, hash_file( 'sha256', $enc_tmp ), array(
                'attachment_id'  => $id,
                'mime'           => $post->post_mime_type,
                'plaintext_size' => $size,
                'plaintext_sha256' => $sha256,
            ) );

            $payload[] = array(
                'id'            => $id,
                'post_title'    => $post->post_title,
                'mime'          => $post->post_mime_type,
                'attached_file' => (string) get_post_meta( $id, '_wp_attached_file', true ),
                'metadata'      => get_post_meta( $id, '_wp_attachment_metadata', true ),
                'size'          => $size,
                'sha256'        => $sha256,
            );
        }

        return array( $payload, $manifest );
    }

    // ---------------------------------------------------------------------------
    // data.json (plaintext payload, before encryption)
    // ---------------------------------------------------------------------------

    /**
     * Stream-write the JSON payload record by record so a large data.json never
     * has to exist as one contiguous in-memory string — only individually bounded
     * fragments (one post, one term, one attachment) are ever json_encode()'d.
     *
     * @param string $path         Destination file path (overwritten).
     * @param array  $datasets     dataset key => records, from collect_all_datasets().
     * @param array  $terms        taxonomy => term definitions, from collect_terms().
     * @param array  $options      name => value, from collect_options().
     * @param array  $attachments  Attachment records, from collect_attachments().
     * @return void
     */
    private static function write_data_json_stream( string $path, array $datasets, array $terms, array $options, array $attachments ): void {
        $fh = fopen( $path, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- temp file on local disk, not user-facing.
        if ( false === $fh ) {
            throw new \RuntimeException( 'Could not open a temp file for the archive payload.' );
        }

        fwrite( $fh, '{' );
        fwrite( $fh, '"format":' . self::FORMAT_VERSION . ',' );
        fwrite( $fh, '"generated_at":' . wp_json_encode( gmdate( 'c' ) ) . ',' );

        fwrite( $fh, '"datasets":{' );
        self::write_json_object_of_arrays( $fh, $datasets );
        fwrite( $fh, '},' );

        fwrite( $fh, '"terms":{' );
        self::write_json_object_of_arrays( $fh, $terms );
        fwrite( $fh, '},' );

        fwrite( $fh, '"options":' . wp_json_encode( $options ) . ',' );

        fwrite( $fh, '"attachments":' );
        self::write_json_array( $fh, $attachments );

        fwrite( $fh, '}' );
        fclose( $fh );
    }

    /**
     * Write {"key1":[...],"key2":[...]} body (no outer braces) one record at a
     * time, so no single call ever holds more than one record's JSON in memory.
     *
     * @param resource $fh   Open writable file handle.
     * @param array    $map  key => list of records.
     * @return void
     */
    private static function write_json_object_of_arrays( $fh, array $map ): void {
        $first_key = true;
        foreach ( $map as $key => $records ) {
            if ( ! $first_key ) {
                fwrite( $fh, ',' );
            }
            $first_key = false;
            fwrite( $fh, wp_json_encode( (string) $key ) . ':' );
            self::write_json_array( $fh, $records );
        }
    }

    /**
     * Write a JSON array one element at a time.
     *
     * @param resource $fh      Open writable file handle.
     * @param array    $records List of individually-encodable values.
     * @return void
     */
    private static function write_json_array( $fh, array $records ): void {
        fwrite( $fh, '[' );
        $first = true;
        foreach ( $records as $record ) {
            if ( ! $first ) {
                fwrite( $fh, ',' );
            }
            $first = false;
            fwrite( $fh, wp_json_encode( $record ) );
        }
        fwrite( $fh, ']' );
    }

    // ---------------------------------------------------------------------------
    // key.json
    // ---------------------------------------------------------------------------

    /**
     * @param string $sealed_ak Sealed archive key, raw bytes.
     * @return string JSON.
     */
    private static function build_key_json( string $sealed_ak ): string {
        return wp_json_encode( array(
            'scheme'           => 2,
            'sealed_ak'        => base64_encode( $sealed_ak ),
            'backup_pubkey'    => get_option( Fotonic_Backup_Keys::OPTION_PUBKEY, '' ),
            'backup_wrap_priv' => Fotonic_Backup_Keys::wrapped_private_key(),
            'vault_salt'       => get_option( 'fotonic_vault_salt', '' ),
            'vault_wrap_pw'    => get_option( 'fotonic_vault_wrap_pw', '' ),
            'vault_wrap_totp'  => get_option( 'fotonic_vault_wrap_totp', '' ),
            'vault_salt_rec'   => get_option( 'fotonic_vault_salt_rec', '' ),
            'vault_wrap_rec'   => get_option( 'fotonic_vault_wrap_rec', '' ),
            'vault_salt_phrase' => get_option( 'fotonic_vault_salt_phrase', '' ),
            'vault_wrap_phrase' => get_option( 'fotonic_vault_wrap_phrase', '' ),
        ), JSON_PRETTY_PRINT );
    }

    // ---------------------------------------------------------------------------
    // Cipher / zip / temp-file plumbing
    // ---------------------------------------------------------------------------

    /**
     * @param string $src Source plaintext file path.
     * @param string $dst Destination ciphertext file path (overwritten).
     * @param string $ak  Raw 32-byte archive key.
     * @return void
     */
    private static function encrypt_file_to_file( string $src, string $dst, string $ak ): void {
        $in  = fopen( $src, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- local file, streamed for memory safety per the master plan's file-size policy.
        $out = fopen( $dst, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- temp file, not user-facing.
        if ( false === $in || false === $out ) {
            throw new \RuntimeException( 'Could not open a stream for ' . $src );
        }
        try {
            Fotonic_Backup_Cipher::encrypt_stream( $in, $out, $ak );
        } finally {
            fclose( $in );
            fclose( $out );
        }
    }

    /**
     * Add a temp file to the zip under $name (uncompressed — payload is already
     * encrypted and therefore incompressible) and record it in the manifest.
     *
     * @param \ZipArchive $zip        Open archive.
     * @param array       $manifest   Manifest being built, passed by reference.
     * @param string      $temp_path  Local file to add.
     * @param string      $name       Entry name inside the zip.
     * @param string      $sha256     Hash to record for this member.
     * @param array       $extra      Extra fields merged into the member's manifest entry.
     * @return void
     */
    private static function add_member( \ZipArchive $zip, array &$manifest, string $temp_path, string $name, string $sha256, array $extra = array() ): void {
        if ( ! $zip->addFile( $temp_path, $name ) ) {
            throw new \RuntimeException( 'Could not add ' . $name . ' to the archive.' );
        }
        $zip->setCompressionName( $name, \ZipArchive::CM_STORE );

        $size = filesize( $temp_path );
        $manifest['members'][] = array_merge( array(
            'name'   => $name,
            'size'   => $size,
            'sha256' => $sha256,
        ), $extra );
        $manifest['total_size'] += $size;
    }

    /**
     * Plain tempnam(), not wp_tempnam(): the latter lives in
     * wp-admin/includes/file.php, which is not loaded during a cron request —
     * and Phase 9's scheduler calls create() from cron. No WP dependency here.
     *
     * @param array $temp_files Accumulator, appended to and returned by reference.
     * @return string New unique temp file path.
     */
    private static function new_temp_file( array &$temp_files ): string {
        $path = tempnam( sys_get_temp_dir(), 'ftnc-backup-' );
        if ( false === $path ) {
            throw new \RuntimeException( 'Could not create a temp file.' );
        }
        $temp_files[] = $path;
        return $path;
    }

    /**
     * @param array $temp_files Paths to remove.
     * @return void
     */
    private static function cleanup_temp_files( array $temp_files ): void {
        foreach ( $temp_files as $path ) {
            if ( file_exists( $path ) ) {
                @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort temp cleanup.
            }
        }
    }

    /**
     * @param string $secret Passed by reference; emptied in place.
     * @return void
     */
    private static function memzero( string &$secret ): void {
        if ( function_exists( 'sodium_memzero' ) ) {
            sodium_memzero( $secret );
            return;
        }
        $secret = str_repeat( "\0", strlen( $secret ) );
    }

    /**
     * str_ends_with() polyfill — PHP 7.4 doesn't have it (added in 8.0), and the
     * plugin supports 7.4+.
     *
     * @param string $haystack Full string.
     * @param string $needle   Suffix to check for.
     * @return bool
     */
    private static function str_ends_with( string $haystack, string $needle ): bool {
        $length = strlen( $needle );
        if ( 0 === $length ) {
            return true;
        }
        return substr( $haystack, -$length ) === $needle;
    }
}
