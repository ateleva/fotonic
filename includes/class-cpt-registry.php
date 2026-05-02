<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_CPT_Registry {
    public static function register(): void {
        self::register_customer_cpt();
        self::register_service_cpt();
        self::register_payment_status_taxonomy(); // must register before CPT
        self::register_work_cpt();
        add_action( 'save_post_ftnc_work', [ __CLASS__, 'auto_assign_payment_status' ] );
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
            'taxonomies'   => [ 'ftnc_work_payment_status' ],
            'show_in_rest' => false,
            'rewrite'      => false,
        ] );
    }

    private static function register_payment_status_taxonomy(): void {
        $labels = [
            'name'          => __( 'Payment Status', 'fotonic' ),
            'singular_name' => __( 'Payment Status', 'fotonic' ),
            'all_items'     => __( 'All Payment Statuses', 'fotonic' ),
            'edit_item'     => __( 'Edit Payment Status', 'fotonic' ),
            'update_item'   => __( 'Update Payment Status', 'fotonic' ),
            'add_new_item'  => __( 'Add New Payment Status', 'fotonic' ),
            'new_item_name' => __( 'New Payment Status Name', 'fotonic' ),
            'search_items'  => __( 'Search Payment Statuses', 'fotonic' ),
        ];
        register_taxonomy( 'ftnc_work_payment_status', 'ftnc_work', [
            'labels'            => $labels,
            'public'            => false,
            'show_ui'           => true,
            'show_in_menu'      => true,
            'show_admin_column' => true,
            'show_in_rest'      => false,
            'rewrite'           => false,
            'hierarchical'      => false,
        ] );
    }

    public static function auto_assign_payment_status( int $post_id ): void {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        $installments = get_post_meta( $post_id, '_fotonic_installments', true );
        if ( empty( $installments ) || ! is_array( $installments ) ) {
            return;
        }
        $total = count( $installments );
        $paid  = count( array_filter( $installments, function( $i ) { return ( $i['status'] ?? '' ) === 'paid'; } ) );
        if ( $paid === $total ) {
            $term = 'paid';
        } elseif ( $paid > 0 ) {
            $term = 'partial';
        } else {
            $term = 'to-be-paid';
        }
        wp_set_object_terms( $post_id, $term, 'ftnc_work_payment_status' );
    }
}
