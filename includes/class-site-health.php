<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Tools > Site Health test: can the web reach the folders where the plugin keeps private files?
 *
 * Backup archives, quote PDFs (Pro) and Vault files live in uploads/fotonic/<folder>/, each with an
 * .htaccess that denies every request. Apache honours it; nginx ignores it, and there the only
 * protection is the long random file name. The test puts a harmless file with a random name in each
 * folder that exists, asks for it over HTTP the way a visitor would, and removes it again. If the
 * server hands the file back, the folder is public and the test says how to close it.
 */
class Fotonic_Site_Health {
    const TEST = 'ftnc_private_files';

    /** @param array $tests Site Health tests. */
    public static function register_test( $tests ) {
        $tests['direct'][ self::TEST ] = [
            'label' => __( 'Eleva CRM private files are protected', 'eleva-crm-for-photographers' ),
            'test'  => [ __CLASS__, 'run_test' ],
        ];
        return $tests;
    }

    /**
     * Folders under uploads/fotonic/ whose files must never be served. Pro adds its quotes folder
     * through the ftnc_protected_folders filter.
     *
     * @return string[]
     */
    public static function protected_folders(): array {
        $folders = apply_filters( 'ftnc_protected_folders', [ 'backups', 'vault' ] );
        $clean   = [];
        foreach ( (array) $folders as $folder ) {
            $folder = is_string( $folder ) ? trim( $folder, '/' ) : '';
            if ( '' !== $folder && 1 === preg_match( '/^[a-z0-9_-]+$/', $folder ) ) {
                $clean[] = $folder;
            }
        }
        return array_values( array_unique( $clean ) );
    }

    /**
     * Ask for a probe file in one folder.
     *
     * @return string 'public' (the server returned the file), 'protected', or 'unknown' (not checked).
     */
    public static function probe_folder( string $folder ): string {
        $uploads = wp_upload_dir();
        $dir     = $uploads['basedir'] . '/fotonic/' . $folder;
        if ( ! is_dir( $dir ) || ! wp_is_writable( $dir ) ) {
            return 'unknown';
        }
        $token = bin2hex( random_bytes( 8 ) );
        $name  = 'ftnc-probe-' . $token . '.txt';
        $path  = $dir . '/' . $name;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- own folder, random name, removed below.
        if ( false === file_put_contents( $path, 'ftnc-probe-' . $token ) ) {
            return 'unknown';
        }
        try {
            $response = wp_remote_get(
                $uploads['baseurl'] . '/fotonic/' . $folder . '/' . $name,
                [
                    'timeout'     => 5,
                    'redirection' => 0,
                    'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, same call as core's own loopback tests.
                ]
            );
            if ( is_wp_error( $response ) ) {
                return 'unknown';
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( 200 === $code && false !== strpos( (string) wp_remote_retrieve_body( $response ), 'ftnc-probe-' . $token ) ) {
                return 'public';
            }
            // A 200 without our marker is a catch-all page, not the file. A 5xx says nothing about the folder.
            return $code >= 500 ? 'unknown' : 'protected';
        } finally {
            wp_delete_file( $path );
        }
    }

    /** nginx rules that close the folders. ^~ makes them win over any regex location for static files. */
    public static function nginx_rules( array $folders ): string {
        $uploads = wp_upload_dir();
        $base    = rtrim( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ), '/' ) . '/fotonic/';
        $lines   = [];
        foreach ( $folders as $folder ) {
            $lines[] = 'location ^~ ' . $base . $folder . '/ { deny all; }';
        }
        return implode( "\n", $lines );
    }

    /** @return array Site Health result. */
    public static function run_test(): array {
        $public  = [];
        $unknown = 0;
        $checked = 0;
        foreach ( self::protected_folders() as $folder ) {
            if ( ! is_dir( wp_upload_dir()['basedir'] . '/fotonic/' . $folder ) ) {
                continue; // Nothing was ever stored there.
            }
            $checked++;
            $result = self::probe_folder( $folder );
            if ( 'public' === $result ) {
                $public[] = $folder;
            } elseif ( 'unknown' === $result ) {
                $unknown++;
            }
        }

        $result = [
            'label'       => __( 'Eleva CRM private files are protected', 'eleva-crm-for-photographers' ),
            'status'      => 'good',
            'badge'       => [ 'label' => __( 'Security', 'eleva-crm-for-photographers' ), 'color' => 'blue' ],
            'description' => '<p>' . esc_html__( 'Eleva CRM keeps backups, quote PDFs and Vault files in folders that the web must not serve. A test file placed in each folder was refused by your web server.', 'eleva-crm-for-photographers' ) . '</p>',
            'actions'     => '',
            'test'        => self::TEST,
        ];

        if ( $public ) {
            $result['label']       = __( 'Eleva CRM private files can be downloaded without logging in', 'eleva-crm-for-photographers' );
            $result['status']      = 'recommended';
            $result['description'] = '<p>' . esc_html(
                sprintf(
                    /* translators: %s: comma-separated folder names, e.g. backups, quotes */
                    __( 'A test file placed in these Eleva CRM folders was served to anyone who knows its address: %s. Your web server ignores the .htaccess file that protects them (this happens on nginx). Until you block the folders, backups and quote PDFs are protected only by their long random file names.', 'eleva-crm-for-photographers' ),
                    implode( ', ', $public )
                )
            ) . '</p>';
            $result['actions']     = '<p>' . esc_html__( 'Add these rules to the server block of your site in the nginx configuration (or ask your hosting provider to), then reload nginx. Apache servers need nothing.', 'eleva-crm-for-photographers' ) . '</p>'
                . '<pre>' . esc_html( self::nginx_rules( self::protected_folders() ) ) . '</pre>';
        } elseif ( $checked > 0 && $unknown === $checked ) {
            $result['label']       = __( 'Eleva CRM could not check whether its private files are protected', 'eleva-crm-for-photographers' );
            $result['status']      = 'recommended';
            $result['description'] = '<p>' . esc_html__( 'The check could not reach your own site over HTTP, so nothing was verified. Run the test again later. If it keeps failing, make sure a file inside wp-content/uploads/fotonic/backups cannot be opened in a browser.', 'eleva-crm-for-photographers' ) . '</p>';
        }
        return $result;
    }
}
