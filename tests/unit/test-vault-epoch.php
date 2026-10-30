<?php
// Unit test for the Vault session cookie: epoch (global lock), issued-at (server-side 24 h) and the
// binding to the WordPress login. No WordPress, no database: the option and WP functions are
// in-memory stand-ins.
ob_start(); // setcookie() needs no output sent yet.
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'COOKIEPATH', '/' );
define( 'COOKIE_DOMAIN', '' );
define( 'AUTH_KEY', 'unit-test-auth-key' );

$GLOBALS['t_opts']  = array();
$GLOBALS['t_token'] = 'token-A';
$GLOBALS['t_uid']   = 7;

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['t_opts'] ) ? $GLOBALS['t_opts'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['t_opts'][ $n ] = $v; return true; }
function add_option( $n, $v = '', $d = '', $a = null ) { if ( array_key_exists( $n, $GLOBALS['t_opts'] ) ) { return false; } $GLOBALS['t_opts'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['t_opts'][ $n ] ); return true; }
function get_current_user_id() { return $GLOBALS['t_uid']; }
function wp_get_session_token() { return $GLOBALS['t_token']; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return $v; }
function is_ssl() { return false; }

require __DIR__ . '/../../includes/class-vault.php';

$mk = random_bytes( 32 );
$ck = Fotonic_Vault::COOKIE_NAME;

// A reflection helper to craft cookies the way set_session_cookie() does, with chosen parts.
$craft = function ( string $plain ): string {
	$m = new ReflectionMethod( 'Fotonic_Vault', 'server_secret' );
	$m->setAccessible( true );
	$key = hash( 'sha256', $m->invoke( null ), true );
	$iv  = random_bytes( 12 );
	$tag = '';
	$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
	return base64_encode( $iv . $tag . $ct );
};
$binding = function (): string {
	$m = new ReflectionMethod( 'Fotonic_Vault', 'session_binding' );
	$m->setAccessible( true );
	return $m->invoke( null );
};
$epoch_raw = function () { return hex2bin( (string) get_option( Fotonic_Vault::OPTION_EPOCH, '' ) ); };

// 1. No cookie, no epoch: locked.
t_equals( null, Fotonic_Vault::get_session_key(), 'no cookie: locked' );

// 2. A fresh session cookie opens; the epoch was created.
Fotonic_Vault::update_session_key( $mk );
t_equals( 32, strlen( (string) get_option( Fotonic_Vault::OPTION_EPOCH, '' ) ), 'the epoch is 16 bytes in hex' );
t_equals( $mk, Fotonic_Vault::get_session_key(), 'a fresh cookie opens the Vault' );
t_equals( true, Fotonic_Vault::is_unlocked(), 'is_unlocked() agrees' );
$old_cookie = $_COOKIE[ $ck ];

// 3. Another browser holding the same cookie is valid until the global lock.
unset( $_COOKIE[ $ck ] );
t_equals( null, Fotonic_Vault::get_session_key(), 'no cookie in the request: locked' );
$_COOKIE[ $ck ] = $old_cookie;
t_equals( $mk, Fotonic_Vault::get_session_key(), 'a cookie issued before is valid while the epoch is unchanged' );
$e1 = get_option( Fotonic_Vault::OPTION_EPOCH );
Fotonic_Vault::update_session_key( $mk ); // unlocking elsewhere does not change the epoch
t_equals( $e1, get_option( Fotonic_Vault::OPTION_EPOCH ), 'a new cookie does not change the epoch' );
$_COOKIE[ $ck ] = $old_cookie;
t_equals( $mk, Fotonic_Vault::get_session_key(), 'the older cookie still opens after another unlock' );

// 4. Global lock: the old cookie is refused everywhere, also when replayed.
Fotonic_Vault::lock_everywhere();
t_true( get_option( Fotonic_Vault::OPTION_EPOCH ) !== $e1, 'lock_everywhere() changes the epoch' );
t_equals( null, Fotonic_Vault::get_session_key(), 'this browser is locked' );
$_COOKIE[ $ck ] = $old_cookie;
t_equals( null, Fotonic_Vault::get_session_key(), 'a copy of the old cookie is refused after the global lock' );

// 5. A new unlock works again.
Fotonic_Vault::update_session_key( $mk );
t_equals( $mk, Fotonic_Vault::get_session_key(), 'a cookie issued after the lock opens' );
t_equals( null, ( function () use ( $old_cookie, $ck ) { $_COOKIE[ $ck ] = $old_cookie; return Fotonic_Vault::get_session_key(); } )(), 'while the old one stays refused' );

// 6. lock() clears this browser only: the epoch is kept.
Fotonic_Vault::update_session_key( $mk );
$keep_cookie = $_COOKIE[ $ck ];
$e2 = get_option( Fotonic_Vault::OPTION_EPOCH );
Fotonic_Vault::lock();
t_equals( $e2, get_option( Fotonic_Vault::OPTION_EPOCH ), 'lock() keeps the epoch (idle timer, WP login/logout)' );
$_COOKIE[ $ck ] = $keep_cookie;
t_equals( $mk, Fotonic_Vault::get_session_key(), 'a cookie held elsewhere still opens after a device-only lock' );

// 7. Rotation by hand.
Fotonic_Vault::rotate_epoch();
t_equals( null, Fotonic_Vault::get_session_key(), 'rotate_epoch() refuses every earlier cookie' );

// 8. Old cookie format (64-byte plaintext, no epoch): locked.
$_COOKIE[ $ck ] = $craft( $mk . $binding() );
t_equals( null, Fotonic_Vault::get_session_key(), 'a cookie from before the epoch existed counts as locked' );

// 9. Wrong epoch, wrong binding, aged cookie, future cookie.
$cur = $epoch_raw();
$_COOKIE[ $ck ] = $craft( $mk . $binding() . $cur . pack( 'J', time() ) );
t_equals( $mk, Fotonic_Vault::get_session_key(), 'crafted cookie with the right parts opens (control)' );
$_COOKIE[ $ck ] = $craft( $mk . $binding() . random_bytes( 16 ) . pack( 'J', time() ) );
t_equals( null, Fotonic_Vault::get_session_key(), 'wrong epoch is refused' );
$_COOKIE[ $ck ] = $craft( $mk . hash( 'sha256', 'other', true ) . $cur . pack( 'J', time() ) );
t_equals( null, Fotonic_Vault::get_session_key(), 'wrong login binding is refused' );
$_COOKIE[ $ck ] = $craft( $mk . $binding() . $cur . pack( 'J', time() - 86400 - 60 ) );
t_equals( null, Fotonic_Vault::get_session_key(), 'a cookie older than 24 hours is refused by the server' );
$_COOKIE[ $ck ] = $craft( $mk . $binding() . $cur . pack( 'J', time() - 86400 + 120 ) );
t_equals( $mk, Fotonic_Vault::get_session_key(), 'a cookie just inside the 24 hours is accepted' );
$_COOKIE[ $ck ] = $craft( $mk . $binding() . $cur . pack( 'J', time() + 3600 ) );
t_equals( null, Fotonic_Vault::get_session_key(), 'a cookie issued in the future is refused' );
$_COOKIE[ $ck ] = 'not-base64!!';
t_equals( null, Fotonic_Vault::get_session_key(), 'garbage is refused' );

// 10. Login session change (new WP login): the cookie of the previous login is refused.
Fotonic_Vault::update_session_key( $mk );
t_equals( $mk, Fotonic_Vault::get_session_key(), 'cookie opens under the login it was issued for' );
$GLOBALS['t_token'] = 'token-B';
t_equals( null, Fotonic_Vault::get_session_key(), 'a new WordPress login session needs a new unlock' );
$GLOBALS['t_token'] = 'token-A';

// 11. Missing epoch (Vault reset deletes it): every cookie is refused.
Fotonic_Vault::update_session_key( $mk );
delete_option( Fotonic_Vault::OPTION_EPOCH );
t_equals( null, Fotonic_Vault::get_session_key(), 'no epoch option: every cookie is refused' );
t_equals( '', ( function () { $m = new ReflectionMethod( 'Fotonic_Vault', 'epoch' ); $m->setAccessible( true ); return $m->invoke( null, false ); } )(), 'epoch() without create returns nothing' );

// 12. A corrupt epoch option is replaced by a good one when a cookie is made.
update_option( Fotonic_Vault::OPTION_EPOCH, 'zz', false );
Fotonic_Vault::update_session_key( $mk );
t_equals( $mk, Fotonic_Vault::get_session_key(), 'a corrupt epoch is not accepted, a new cookie fixes it' );
t_done();
