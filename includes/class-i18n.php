<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_i18n {
    public static function load(): void {
        load_plugin_textdomain(
            'eleva-crm-for-photographers',
            false,
            dirname( plugin_basename( FOTONIC_DIR . 'fotonic.php' ) ) . '/languages'
        );
    }
}
