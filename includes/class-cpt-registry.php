<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_CPT_Registry {
    public static function register(): void {
        self::register_customer_cpt();
        self::register_service_cpt();
        self::register_work_cpt();
        self::register_payment_status_taxonomy();
    }

    private static function register_customer_cpt(): void {
        $labels = [
            'name'               => __( 'Customers', 'fotonic' ),
            'singular_name'      => __( 'Customer', 'fotonic' ),
            'add_new'            => __( 'Add New', 'fotonic' ),
            'add_new_item'       => __( 'Add New Customer', 'fotonic' ),
            'edit_item'          => __( 'Edit Customer', 'fotonic' ),
            'new_item'           => __( 'New Customer', 'fotonic' ),
            'view_item'          => __( 'View Customer', 'fotonic' ),
            'search_items'       => __( 'Search Customers', 'fotonic' ),
            'not_found'          => __( 'No customers found.', 'fotonic' ),
            'not_found_in_trash' => __( 'No customers found in trash.', 'fotonic' ),
            'all_items'          => __( 'All Customers', 'fotonic' ),
            'menu_name'          => __( 'Customers', 'fotonic' ),
        ];
        register_post_type( 'ftnc_customer', [
            'labels'       => $labels,
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => false,
            'supports'     => [ 'title' ],
            'show_in_rest' => false,
            'rewrite'      => false,
        ] );
    }

    private static function register_service_cpt(): void {
        $labels = [
            'name'               => __( 'Services', 'fotonic' ),
            'singular_name'      => __( 'Service', 'fotonic' ),
            'add_new'            => __( 'Add New', 'fotonic' ),
            'add_new_item'       => __( 'Add New Service', 'fotonic' ),
            'edit_item'          => __( 'Edit Service', 'fotonic' ),
            'new_item'           => __( 'New Service', 'fotonic' ),
            'view_item'          => __( 'View Service', 'fotonic' ),
            'search_items'       => __( 'Search Services', 'fotonic' ),
            'not_found'          => __( 'No services found.', 'fotonic' ),
            'not_found_in_trash' => __( 'No services found in trash.', 'fotonic' ),
            'all_items'          => __( 'All Services', 'fotonic' ),
            'menu_name'          => __( 'Services', 'fotonic' ),
        ];
        register_post_type( 'ftnc_service', [
            'labels'       => $labels,
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => false,
            'supports'     => [ 'title' ],
            'show_in_rest' => false,
            'rewrite'      => false,
        ] );
    }

    private static function register_work_cpt(): void {
        $labels = [
            'name'               => __( 'Works', 'fotonic' ),
            'singular_name'      => __( 'Work', 'fotonic' ),
            'add_new'            => __( 'Add New', 'fotonic' ),
            'add_new_item'       => __( 'Add New Work', 'fotonic' ),
            'edit_item'          => __( 'Edit Work', 'fotonic' ),
            'new_item'           => __( 'New Work', 'fotonic' ),
            'view_item'          => __( 'View Work', 'fotonic' ),
            'search_items'       => __( 'Search Works', 'fotonic' ),
            'not_found'          => __( 'No works found.', 'fotonic' ),
            'not_found_in_trash' => __( 'No works found in trash.', 'fotonic' ),
            'all_items'          => __( 'All Works', 'fotonic' ),
            'menu_name'          => __( 'Works', 'fotonic' ),
        ];
        register_post_type( 'ftnc_work', [
            'labels'       => $labels,
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => false,
            'supports'     => [ 'title', 'editor' ],
            'show_in_rest' => false,
            'rewrite'      => false,
        ] );
    }

    private static function register_payment_status_taxonomy(): void {
        $labels = [
            'name'          => __( 'Payment Status', 'fotonic' ),
            'singular_name' => __( 'Payment Status', 'fotonic' ),
        ];
        register_taxonomy( 'work_payment_status', 'ftnc_work', [
            'labels'        => $labels,
            'public'        => false,
            'show_ui'       => false,
            'show_in_rest'  => false,
            'rewrite'       => false,
            'hierarchical'  => false,
        ] );
    }
}
