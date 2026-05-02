<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_Admin_Page {
    public static function add_menu(): void {
        add_menu_page(
            esc_html__( 'Fotonic', 'fotonic' ),
            esc_html__( 'Fotonic', 'fotonic' ),
            'manage_options',
            'fotonic',
            [ __CLASS__, 'render' ],
            'dashicons-camera',
            25
        );
    }

    public static function render(): void {
        echo '<div id="fotonic-app-root"></div>';
    }

    public static function enqueue_assets( string $hook ): void {
        if ( 'toplevel_page_fotonic' !== $hook ) {
            return;
        }

        wp_enqueue_script(
            'fotonic-app-js',
            FOTONIC_URL . 'dist/fotonic-app.js',
            [ 'wp-i18n' ],
            FOTONIC_VERSION,
            true
        );

        wp_enqueue_style(
            'fotonic-app-css',
            FOTONIC_URL . 'dist/fotonic-app.css',
            [],
            FOTONIC_VERSION
        );

        wp_set_script_translations( 'fotonic-app-js', 'fotonic', FOTONIC_DIR . 'languages' );

        wp_localize_script( 'fotonic-app-js', 'FotonicApp', [
            'restUrl'  => rest_url( 'fotonic/v1/' ),
            'nonce'    => wp_create_nonce( 'wp_rest' ),
            'isPro'    => defined( 'FOTO_PRO_VERSION' ),
            'locale'   => get_locale(),
            'features' => [
                'kanban'        => defined( 'FOTO_PRO_VERSION' ),
                'collaborators' => defined( 'FOTO_PRO_VERSION' ),
                'analytics'     => defined( 'FOTO_PRO_VERSION' ),
                'notifications' => defined( 'FOTO_PRO_VERSION' ),
            ],
        ] );
    }
}
