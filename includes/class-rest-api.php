<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_REST_API {
    const NAMESPACE = 'fotonic/v1';

    public static function register_routes(): void {
        register_rest_route( self::NAMESPACE, '/vault/unlock', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'vault_unlock' ],
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
            'args' => [
                'password' => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
                'otp'      => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            ],
        ] );

        register_rest_route( self::NAMESPACE, '/vault/lock', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'vault_lock' ],
            'permission_callback' => function() {
                return current_user_can( 'manage_options' );
            },
        ] );

        register_rest_route( self::NAMESPACE, '/customers', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_customers' ],
            'permission_callback' => [ __CLASS__, 'vault_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/customers/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_customer' ],
            'permission_callback' => [ __CLASS__, 'vault_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/services', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_services' ],
            'permission_callback' => [ __CLASS__, 'vault_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/works', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_works' ],
            'permission_callback' => [ __CLASS__, 'vault_permission' ],
        ] );

        register_rest_route( self::NAMESPACE, '/vault-download/(?P<id>\d+)', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'vault_download' ],
            'permission_callback' => [ __CLASS__, 'vault_permission' ],
        ] );
    }

    public static function vault_permission(): bool {
        return current_user_can( 'manage_options' ) && Fotonic_Vault::is_unlocked();
    }

    public static function vault_unlock( \WP_REST_Request $req ): \WP_REST_Response {
        $unlocked = Fotonic_Vault::unlock( $req['password'], $req['otp'] );
        if ( $unlocked ) {
            return new \WP_REST_Response( [ 'unlocked' => true ], 200 );
        }
        return new \WP_REST_Response(
            [ 'message' => __( 'Invalid vault password or OTP code.', 'fotonic' ) ],
            401
        );
    }

    public static function vault_lock( \WP_REST_Request $req ): \WP_REST_Response {
        Fotonic_Vault::lock();
        return new \WP_REST_Response( [ 'locked' => true ], 200 );
    }

    public static function get_customers( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( [], 200 );
    }

    public static function get_customer( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( [], 200 );
    }

    public static function get_services( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( [], 200 );
    }

    public static function get_works( \WP_REST_Request $req ): \WP_REST_Response {
        return new \WP_REST_Response( [], 200 );
    }

    public static function vault_download( \WP_REST_Request $req ): void {
        wp_die( esc_html__( 'File not found.', 'fotonic' ), 404 );
    }
}
