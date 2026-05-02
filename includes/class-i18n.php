<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_i18n {
    public static function load(): void {
        load_plugin_textdomain(
            'fotonic',
            false,
            dirname( plugin_basename( FOTONIC_DIR . 'fotonic.php' ) ) . '/languages/'
        );
    }
}
