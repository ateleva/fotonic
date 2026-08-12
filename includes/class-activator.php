<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_Activator {
    public static function activate(): void {
        if ( ! extension_loaded( 'openssl' ) ) {
            deactivate_plugins( 'fotonic/fotonic.php' );
            wp_die( esc_html__( 'Fotonic requires the PHP OpenSSL extension. Please enable it on your server.', 'eleva-crm-for-photographers' ) );
        }

        $upload_dir = wp_upload_dir();
        $vault_dir  = $upload_dir['basedir'] . '/fotonic/vault';

        if ( ! file_exists( $vault_dir ) ) {
            wp_mkdir_p( $vault_dir );
            // Direct file write used intentionally: WP_Filesystem requires form-based authentication
            // prompts that are unavailable during activation hooks. The path is fully controlled
            // (built from wp_upload_dir()), contains no user input, and is a one-time write.
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            file_put_contents( $vault_dir . '/.htaccess', "Require all denied\n# Block direct access to Fotonic vault files\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>" );
        }

        add_option( 'fotonic_vault_enabled', false );
        add_option( 'fotonic_vault_salt', wp_generate_password( 64, true, true ) );

        // Best-effort recurring backup tick. Free schedules it unconditionally
        // (it has no way to know whether Pro is installed at all, let alone
        // active); the hook does nothing until Pro's Fotonic_Backup_Scheduler
        // registers a listener for it. wp_next_scheduled() guards against
        // double-scheduling if activate() ever runs more than once.
        if ( ! wp_next_scheduled( 'fotonic_backup_run' ) ) {
            wp_schedule_event( time(), 'daily', 'fotonic_backup_run' );
        }

        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        wp_clear_scheduled_hook( 'fotonic_backup_run' );
        flush_rewrite_rules();
    }
}
