<?php
if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit;
}
/**
 * Child process of the vault-download integration test: boots the live site with "no Vault"
 * simulated through the pre_option filter, runs the download handler for one attachment ID and
 * lets it stream (the handler ends with exit, so it cannot run inside the test process).
 * Usage: php download-child.php <attachment_id>
 */
$_SERVER['HTTP_HOST']   = 'fotonic.local';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_USE_THEMES', false );
require dirname( __DIR__, 5 ) . '/wp-load.php';
add_filter( 'pre_option_fotonic_vault_setup', '__return_zero' );
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) $admins[0] );
$req = new WP_REST_Request( 'GET', '/fotonic/v1/vault-download/' . (int) $argv[1] );
$req->set_param( 'id', (int) $argv[1] );
$res = Fotonic_REST_API::vault_download( $req );
// Only reached on an error response (success exits).
echo 'ERROR_STATUS=' . $res->get_status() . ' CODE=' . ( $res->get_data()['code'] ?? '' );
