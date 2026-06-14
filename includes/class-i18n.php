<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_i18n {
    public static function load(): void {
        // WP 6.7+ auto-loads bundled translations via the Domain Path header, and the wp.org
        // language pack (when present) always takes precedence. This explicit call restores the
        // bundled-translation fallback on WP 6.0–6.6 (which predate JIT loading from the plugin
        // folder). Must run on `init` (not `plugins_loaded`) to avoid the 6.7+ "too early" notice.
        load_plugin_textdomain(
            'eleva-crm-for-photographers',
            false,
            dirname( plugin_basename( FOTONIC_DIR . 'fotonic.php' ) ) . '/languages'
        );
    }
}
