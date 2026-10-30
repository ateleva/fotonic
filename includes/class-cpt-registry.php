<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_CPT_Registry {
    public static function register(): void {
        self::register_customer_cpt();
        self::register_service_cpt();
        self::register_payment_status_taxonomy(); // must register before CPT
        self::register_work_cpt();
    }

    private static function register_customer_cpt(): void {
        $labels = [
            'name'               => __( 'Customers', 'eleva-crm-for-photographers' ),
            'singular_name'      => __( 'Customer', 'eleva-crm-for-photographers' ),
            'add_new'            => __( 'Add New', 'eleva-crm-for-photographers' ),
            'add_new_item'       => __( 'Add New Customer', 'eleva-crm-for-photographers' ),
            'edit_item'          => __( 'Edit Customer', 'eleva-crm-for-photographers' ),
            'new_item'           => __( 'New Customer', 'eleva-crm-for-photographers' ),
            'view_item'          => __( 'View Customer', 'eleva-crm-for-photographers' ),
            'search_items'       => __( 'Search Customers', 'eleva-crm-for-photographers' ),
            'not_found'          => __( 'No customers found.', 'eleva-crm-for-photographers' ),
            'not_found_in_trash' => __( 'No customers found in trash.', 'eleva-crm-for-photographers' ),
            'all_items'          => __( 'All Customers', 'eleva-crm-for-photographers' ),
            'menu_name'          => __( 'Customers', 'eleva-crm-for-photographers' ),
        ];
        register_post_type( 'ftnc_customer', [
            'labels'       => $labels,
            'public'       => false,
            'show_ui'      => false,
            'show_in_menu' => false,
            'supports'     => [ 'title' ],
            'show_in_rest' => false,
            'rewrite'      => false,
            'capabilities' => self::admin_only_caps(),
            'map_meta_cap' => true,
        ] );
    }

    private static function register_service_cpt(): void {
        $labels = [
            'name'               => __( 'Services', 'eleva-crm-for-photographers' ),
            'singular_name'      => __( 'Service', 'eleva-crm-for-photographers' ),
            'add_new'            => __( 'Add New', 'eleva-crm-for-photographers' ),
            'add_new_item'       => __( 'Add New Service', 'eleva-crm-for-photographers' ),
            'edit_item'          => __( 'Edit Service', 'eleva-crm-for-photographers' ),
            'new_item'           => __( 'New Service', 'eleva-crm-for-photographers' ),
            'view_item'          => __( 'View Service', 'eleva-crm-for-photographers' ),
            'search_items'       => __( 'Search Services', 'eleva-crm-for-photographers' ),
            'not_found'          => __( 'No services found.', 'eleva-crm-for-photographers' ),
            'not_found_in_trash' => __( 'No services found in trash.', 'eleva-crm-for-photographers' ),
            'all_items'          => __( 'All Services', 'eleva-crm-for-photographers' ),
            'menu_name'          => __( 'Services', 'eleva-crm-for-photographers' ),
        ];
        register_post_type( 'ftnc_service', [
            'labels'       => $labels,
            'public'       => false,
            'show_ui'      => false,
            'show_in_menu' => false,
            'supports'     => [ 'title' ],
            'show_in_rest' => false,
            'rewrite'      => false,
            'capabilities' => self::admin_only_caps(),
            'map_meta_cap' => true,
        ] );
    }

    private static function register_work_cpt(): void {
        $labels = [
            'name'               => __( 'Works', 'eleva-crm-for-photographers' ),
            'singular_name'      => __( 'Work', 'eleva-crm-for-photographers' ),
            'add_new'            => __( 'Add New', 'eleva-crm-for-photographers' ),
            'add_new_item'       => __( 'Add New Work', 'eleva-crm-for-photographers' ),
            'edit_item'          => __( 'Edit Work', 'eleva-crm-for-photographers' ),
            'new_item'           => __( 'New Work', 'eleva-crm-for-photographers' ),
            'view_item'          => __( 'View Work', 'eleva-crm-for-photographers' ),
            'search_items'       => __( 'Search Works', 'eleva-crm-for-photographers' ),
            'not_found'          => __( 'No works found.', 'eleva-crm-for-photographers' ),
            'not_found_in_trash' => __( 'No works found in trash.', 'eleva-crm-for-photographers' ),
            'all_items'          => __( 'All Works', 'eleva-crm-for-photographers' ),
            'menu_name'          => __( 'Works', 'eleva-crm-for-photographers' ),
        ];
        register_post_type( 'ftnc_work', [
            'labels'       => $labels,
            'public'       => false,
            'show_ui'      => false,
            'show_in_menu' => false,
            'supports'     => [ 'title', 'editor' ],
            'taxonomies'   => [ 'ftnc_work_payment_status' ],
            'show_in_rest' => false,
            'rewrite'      => false,
            'capabilities' => self::admin_only_caps(),
            'map_meta_cap' => true,
        ] );
    }

    private static function register_payment_status_taxonomy(): void {
        $labels = [
            'name'          => __( 'Payment Status', 'eleva-crm-for-photographers' ),
            'singular_name' => __( 'Payment Status', 'eleva-crm-for-photographers' ),
            'all_items'     => __( 'All Payment Statuses', 'eleva-crm-for-photographers' ),
            'edit_item'     => __( 'Edit Payment Status', 'eleva-crm-for-photographers' ),
            'update_item'   => __( 'Update Payment Status', 'eleva-crm-for-photographers' ),
            'add_new_item'  => __( 'Add New Payment Status', 'eleva-crm-for-photographers' ),
            'new_item_name' => __( 'New Payment Status Name', 'eleva-crm-for-photographers' ),
            'search_items'  => __( 'Search Payment Statuses', 'eleva-crm-for-photographers' ),
        ];
        register_taxonomy( 'ftnc_work_payment_status', 'ftnc_work', [
            'labels'            => $labels,
            'public'            => false,
            'show_ui'           => false,
            'show_in_menu'      => false,
            'show_admin_column' => false,
            'show_in_rest'      => false,
            'rewrite'           => false,
            'hierarchical'      => false,
            'capabilities'      => self::admin_only_term_caps(),
        ] );
    }

    /**
     * Capabilities for the plugin's own post types. Every primitive capability maps to
     * manage_options, so only administrators can read or write these records through any
     * WordPress path (core screens, admin-ajax, XML-RPC). Passed to register_post_type()
     * together with map_meta_cap, it applies to the post type that receives it only:
     * user roles and other plugins' post types are not changed.
     *
     * @return array<string,string>
     */
    public static function admin_only_caps(): array {
        $cap = 'manage_options';
        return [
            'edit_posts'             => $cap,
            'edit_others_posts'      => $cap,
            'edit_private_posts'     => $cap,
            'edit_published_posts'   => $cap,
            'publish_posts'          => $cap,
            'read_private_posts'     => $cap,
            'delete_posts'           => $cap,
            'delete_others_posts'    => $cap,
            'delete_private_posts'   => $cap,
            'delete_published_posts' => $cap,
            'create_posts'           => $cap,
        ];
    }

    /**
     * Same rule for the plugin's own taxonomies.
     *
     * @return array<string,string>
     */
    public static function admin_only_term_caps(): array {
        $cap = 'manage_options';
        return [
            'manage_terms' => $cap,
            'edit_terms'   => $cap,
            'delete_terms' => $cap,
            'assign_terms' => $cap,
        ];
    }

    /**
     * Ensure the three canonical payment-status terms exist.
     * Safe to call on every init — term_exists() prevents duplicates.
     */
    public static function ensure_payment_terms(): void {
        $terms = [ 'paid' => __( 'Paid', 'eleva-crm-for-photographers' ), 'partial' => __( 'Partial', 'eleva-crm-for-photographers' ), 'unpaid' => __( 'Unpaid', 'eleva-crm-for-photographers' ) ];
        foreach ( $terms as $slug => $name ) {
            if ( ! term_exists( $slug, 'ftnc_work_payment_status' ) ) {
                wp_insert_term( $name, 'ftnc_work_payment_status', [ 'slug' => $slug ] );
            }
        }
    }

    /**
     * Delegates to Fotonic_Meta_Boxes::auto_assign_payment_status().
     * Kept here because the hook was originally registered in register().
     * The meta-boxes class is the single source of truth for this logic.
     *
     * @param int $post_id Post ID.
     */
    public static function auto_assign_payment_status( int $post_id ): void {
        if ( class_exists( 'Fotonic_Meta_Boxes' ) ) {
            Fotonic_Meta_Boxes::auto_assign_payment_status( $post_id );
        }
    }
}
