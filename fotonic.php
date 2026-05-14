<?php
/**
 * Plugin Name:       Fotonic
 * Plugin URI:        https://github.com/ateleva/fotonic
 * Description:       CRM and workflow manager for professional event photographers.
 * Version:           1.2.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Alessandro Bonacina
 * Author URI:        https://eleva.alessandrobonacina.com
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fotonic
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'FOTONIC_VERSION', '1.2.0' );
define( 'FOTONIC_DIR', plugin_dir_path( __FILE__ ) );
define( 'FOTONIC_URL', plugin_dir_url( __FILE__ ) );

require_once FOTONIC_DIR . 'includes/class-i18n.php';
require_once FOTONIC_DIR . 'includes/class-activator.php';
require_once FOTONIC_DIR . 'includes/class-cpt-registry.php';
require_once FOTONIC_DIR . 'includes/class-crypto.php';
require_once FOTONIC_DIR . 'includes/class-totp.php';
require_once FOTONIC_DIR . 'includes/class-vault.php';
require_once FOTONIC_DIR . 'includes/class-rest-api.php';
require_once FOTONIC_DIR . 'includes/class-admin-page.php';
require_once FOTONIC_DIR . 'includes/class-meta-boxes.php';

register_activation_hook( __FILE__, [ 'Fotonic_Activator', 'activate' ] );
register_deactivation_hook( __FILE__, [ 'Fotonic_Activator', 'deactivate' ] );

add_action( 'plugins_loaded', [ 'Fotonic_i18n', 'load' ] );
add_action( 'init', [ 'Fotonic_CPT_Registry', 'register' ] );
add_action( 'init', [ 'Fotonic_CPT_Registry', 'ensure_payment_terms' ], 20 );
add_action( 'rest_api_init', [ 'Fotonic_REST_API', 'register_routes' ] );
add_action( 'admin_menu', [ 'Fotonic_Admin_Page', 'add_menu' ] );
add_action( 'admin_enqueue_scripts', [ 'Fotonic_Admin_Page', 'enqueue_assets' ] );

// Meta box registration and save hooks.
add_action( 'add_meta_boxes', [ 'Fotonic_Meta_Boxes', 'register' ] );
add_action( 'save_post_ftnc_customer', [ 'Fotonic_Meta_Boxes', 'save_customer' ], 10, 2 );
add_action( 'save_post_ftnc_service',  [ 'Fotonic_Meta_Boxes', 'save_service' ],  10, 2 );
add_action( 'save_post_ftnc_work',     [ 'Fotonic_Meta_Boxes', 'save_work' ],     10, 2 );
add_action( 'save_post_ftnc_work',     [ 'Fotonic_Meta_Boxes', 'auto_assign_payment_status' ], 20, 1 );
add_filter( 'posts_search', [ 'Fotonic_Meta_Boxes', 'extend_customer_search' ], 10, 2 );
add_filter( 'posts_join',   [ 'Fotonic_Meta_Boxes', 'extend_customer_search_join' ], 10, 2 );
