<?php
// Unit test for Fotonic_Vault_Attempts. No WordPress, no database: $wpdb and the
// transient functions are small in-memory stand-ins that mimic what the class needs
// (INSERT IGNORE on a unique option_name, compare-and-delete, transients).
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_rows']       = array(); // option_name => option_value
$GLOBALS['t_transients'] = array();

class T_Wpdb {
	public $options = 'wp_options';
	public function prepare( $q, ...$args ) {
		$i = 0;
		return preg_replace_callback( '/%s/', function () use ( &$i, $args ) {
			return "'" . addslashes( (string) $args[ $i++ ] ) . "'";
		}, $q );
	}
	public function query( $q ) {
		if ( 0 === strpos( $q, 'INSERT IGNORE' ) ) {
			preg_match( "/VALUES \( '([^']*)', '([^']*)'/", $q, $m );
			if ( isset( $GLOBALS['t_rows'][ $m[1] ] ) ) { return 0; }
			$GLOBALS['t_rows'][ $m[1] ] = $m[2];
			return 1;
		}
		if ( 0 === strpos( $q, 'DELETE FROM' ) ) {
			preg_match( "/option_name = '([^']*)' AND option_value = '([^']*)'/", $q, $m );
			if ( isset( $GLOBALS['t_rows'][ $m[1] ] ) && $GLOBALS['t_rows'][ $m[1] ] === $m[2] ) {
				unset( $GLOBALS['t_rows'][ $m[1] ] );
				return 1;
			}
			return 0;
		}
		return 0;
	}
	public function get_var( $q ) {
		preg_match( "/option_name = '([^']*)'/", $q, $m );
		return isset( $GLOBALS['t_rows'][ $m[1] ] ) ? $GLOBALS['t_rows'][ $m[1] ] : null;
	}
}
$wpdb = new T_Wpdb();

function wp_generate_password( $len = 12, $special = true ) { return substr( bin2hex( random_bytes( 8 ) ), 0, $len ); }
function get_transient( $k ) { return isset( $GLOBALS['t_transients'][ $k ] ) ? $GLOBALS['t_transients'][ $k ] : false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['t_transients'][ $k ] = $v; return true; }
function delete_transient( $k ) { unset( $GLOBALS['t_transients'][ $k ] ); return true; }

require __DIR__ . '/../../includes/class-vault-attempts.php';

// 1. One attempt at a time: a second acquire while the first is held is refused.
$a = Fotonic_Vault_Attempts::acquire( 'credentials' );
t_true( null !== $a, 'first acquire gets the lock' );
t_true( null === Fotonic_Vault_Attempts::acquire( 'credentials' ), 'second acquire while held is refused' );
t_true( null === Fotonic_Vault_Attempts::acquire( 'recovery' ), 'the lock is per vault, not per bucket' );

// 2. Releasing (object goes out of scope) frees the lock.
$a = null;
t_equals( 0, count( $GLOBALS['t_rows'] ), 'lock row is gone after release' );
$b = Fotonic_Vault_Attempts::acquire( 'credentials' );
t_true( null !== $b, 'acquire works again after release' );
$b = null;

// 3. Five failures exhaust the bucket; the sixth attempt is refused; success clears it.
for ( $i = 1; $i <= 5; $i++ ) {
	$x = Fotonic_Vault_Attempts::acquire( 'credentials' );
	t_true( ! $x->exhausted(), "attempt $i is allowed" );
	$x->failed();
	$x = null;
}
$x = Fotonic_Vault_Attempts::acquire( 'credentials' );
t_true( $x->exhausted(), 'after 5 failures the bucket is exhausted' );
$x = null;

$r = Fotonic_Vault_Attempts::acquire( 'recovery' );
t_true( ! $r->exhausted(), 'the recovery bucket is independent' );
$r = null;

Fotonic_Vault_Attempts::clear_all();
$x = Fotonic_Vault_Attempts::acquire( 'credentials' );
t_true( ! $x->exhausted(), 'clear_all resets the counters' );
$x->failed();
$x->failed();
$x->clear();
t_true( ! $x->exhausted() && false === get_transient( 'fotonic_vault_attempts_credentials' ), 'clear() after a success removes the counter' );
$x = null;

// 4. The counter does not depend on which WordPress user asks (it is per vault).
$GLOBALS['t_transients'] = array();
foreach ( array( 1, 2, 3, 4, 5 ) as $uid ) {
	$x = Fotonic_Vault_Attempts::acquire( 'credentials' );
	$x->failed();
	$x = null;
}
$x = Fotonic_Vault_Attempts::acquire( 'credentials' );
t_true( $x->exhausted(), 'failures from five different users add up to one limit' );
$x = null;

// 5. A stale lock (crashed request) is taken over; a fresh one is not.
$GLOBALS['t_rows']['fotonic_vault_attempt_lock'] = ( time() - 120 ) . '.deadbeef';
$s = Fotonic_Vault_Attempts::acquire( 'credentials' );
t_true( null !== $s, 'a stale lock is taken over' );
$s = null;
$GLOBALS['t_rows']['fotonic_vault_attempt_lock'] = ( time() - 2 ) . '.cafe';
t_true( null === Fotonic_Vault_Attempts::acquire( 'credentials' ), 'a lock younger than the TTL is respected' );
t_true( isset( $GLOBALS['t_rows']['fotonic_vault_attempt_lock'] ), 'the other request\'s lock is left untouched' );

// 6. Only the owner releases: a taken-over lock is not deleted by the old owner.
$GLOBALS['t_rows'] = array();
$old = Fotonic_Vault_Attempts::acquire( 'credentials' );
$GLOBALS['t_rows']['fotonic_vault_attempt_lock'] = time() . '.newowner'; // someone else took over
$old = null;
t_equals( 'newowner', substr( $GLOBALS['t_rows']['fotonic_vault_attempt_lock'], -8 ), 'old owner does not delete the new owner\'s lock' );

t_done();
