<?php
/**
 * Boot WordPress from the CLI for integration tests. tests/run.sh passes Local's
 * per-site MySQL socket as -d mysqli.default_socket=... (wp-config uses
 * DB_HOST=localhost, which makes mysqli fall back to that default socket).
 */
$_SERVER['HTTP_HOST']   = 'fotonic.local';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_USE_THEMES', false );
require dirname( __DIR__, 5 ) . '/wp-load.php';
require __DIR__ . '/assert.php';

$ftnc_admin = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) ( $ftnc_admin[0] ?? 0 ) );
