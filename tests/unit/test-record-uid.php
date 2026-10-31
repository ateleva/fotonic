<?php
// Unit test for Fotonic_Record_Uid. No WordPress, no database: posts, postmeta, options and
// get_posts() are in-memory stand-ins.
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_posts']   = array(); // id => post_type
$GLOBALS['t_meta']    = array(); // id => array( key => value )
$GLOBALS['t_opts']    = array();
$GLOBALS['t_filters'] = array();
$GLOBALS['t_can']     = true;
$GLOBALS['t_ajax']    = false;
$GLOBALS['t_cron']    = false;
$GLOBALS['t_fail_writes'] = false;

class WP_Post { public $ID; public $post_type; function __construct( $id, $type ) { $this->ID = $id; $this->post_type = $type; } }

function apply_filters( $tag, $value ) { foreach ( $GLOBALS['t_filters'][ $tag ] ?? array() as $cb ) { $value = $cb( $value ); } return $value; }
function get_post_type( $id ) { return $GLOBALS['t_posts'][ $id ] ?? false; }
function get_post_meta( $id, $key, $single = false ) { return $GLOBALS['t_meta'][ $id ][ $key ] ?? ''; }
function update_post_meta( $id, $key, $value ) { if ( $GLOBALS['t_fail_writes'] ) { return false; } $GLOBALS['t_meta'][ $id ][ $key ] = $value; return true; }
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['t_opts'] ) ? $GLOBALS['t_opts'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['t_opts'][ $n ] = $v; return true; }
function wp_is_post_revision( $id ) { return false; }
function wp_is_post_autosave( $id ) { return false; }
function wp_doing_ajax() { return $GLOBALS['t_ajax']; }
function wp_doing_cron() { return $GLOBALS['t_cron']; }
function current_user_can( $c ) { return $GLOBALS['t_can']; }
function get_posts( $args ) {
	$out = array();
	foreach ( $GLOBALS['t_posts'] as $id => $type ) {
		if ( ! in_array( $type, (array) $args['post_type'], true ) ) { continue; }
		if ( array_key_exists( Fotonic_Record_Uid::META_KEY, $GLOBALS['t_meta'][ $id ] ?? array() ) ) { continue; }
		$out[] = $id;
		if ( count( $out ) >= $args['posts_per_page'] ) { break; }
	}
	return $out;
}
function t_add_post( $id, $type ) { $GLOBALS['t_posts'][ $id ] = $type; }

require __DIR__ . '/../../includes/class-record-uid.php';

// 1. UUID shape.
$a = Fotonic_Record_Uid::generate();
$b = Fotonic_Record_Uid::generate();
t_true( Fotonic_Record_Uid::is_valid( $a ), 'generate(): a valid UUID v4' );
t_true( $a !== $b, 'generate(): two calls differ' );
t_equals( strtolower( $a ), $a, 'generate(): lowercase' );
t_equals( false, Fotonic_Record_Uid::is_valid( strtoupper( $a ) ), 'is_valid(): uppercase is refused' );
t_equals( false, Fotonic_Record_Uid::is_valid( '123' ), 'is_valid(): garbage is refused' );
t_equals( false, Fotonic_Record_Uid::is_valid( null ), 'is_valid(): null is refused' );

// 2. ensure() is idempotent and only for tracked types.
t_add_post( 1, 'ftnc_customer' );
t_add_post( 2, 'post' );
$u1 = Fotonic_Record_Uid::ensure( 1 );
t_true( Fotonic_Record_Uid::is_valid( $u1 ), 'ensure(): a customer gets a uid' );
t_equals( $u1, Fotonic_Record_Uid::ensure( 1 ), 'ensure(): second call returns the same uid' );
t_equals( $u1, get_post_meta( 1, '_ftnc_uid', true ), 'ensure(): stored in _ftnc_uid' );
t_equals( '', Fotonic_Record_Uid::ensure( 2 ), 'ensure(): a normal post gets nothing' );
t_equals( '', Fotonic_Record_Uid::ensure( 999 ), 'ensure(): a missing post gets nothing' );
t_equals( false, array_key_exists( '_ftnc_uid', $GLOBALS['t_meta'][2] ?? array() ), 'ensure(): nothing written on a normal post' );
$GLOBALS['t_meta'][1]['_ftnc_uid'] = 'not-a-uuid';
$fixed = Fotonic_Record_Uid::ensure( 1 );
t_true( Fotonic_Record_Uid::is_valid( $fixed ) && 'not-a-uuid' !== $fixed, 'ensure(): an invalid stored value is replaced' );

// 3. The insert hook.
t_add_post( 3, 'ftnc_work' );
Fotonic_Record_Uid::on_insert( 3, new WP_Post( 3, 'ftnc_work' ) );
t_true( Fotonic_Record_Uid::is_valid( get_post_meta( 3, '_ftnc_uid', true ) ), 'on_insert(): a new work gets a uid' );
t_add_post( 4, 'page' );
Fotonic_Record_Uid::on_insert( 4, new WP_Post( 4, 'page' ) );
t_equals( '', get_post_meta( 4, '_ftnc_uid', true ), 'on_insert(): a page gets nothing' );
$before = get_post_meta( 3, '_ftnc_uid', true );
Fotonic_Record_Uid::on_insert( 3, new WP_Post( 3, 'ftnc_work' ) );
t_equals( $before, get_post_meta( 3, '_ftnc_uid', true ), 'on_insert(): saving again keeps the uid' );

// 4. Filter adds a type (Pro).
t_add_post( 5, 'ftnc_quote' );
t_equals( '', Fotonic_Record_Uid::ensure( 5 ), 'a Pro type is untracked until the filter adds it' );
$GLOBALS['t_filters']['ftnc_uid_post_types'][] = function ( $t ) { $t[] = 'ftnc_quote'; return $t; };
t_true( Fotonic_Record_Uid::is_valid( Fotonic_Record_Uid::ensure( 5 ) ), 'after the filter the Pro type gets a uid' );
$GLOBALS['t_filters']['ftnc_uid_post_types'][] = function ( $t ) { $t[] = 'ftnc_quote'; $t[] = 7; return $t; };
t_equals( 1, count( array_keys( Fotonic_Record_Uid::post_types(), 'ftnc_quote', true ) ), 'post_types(): duplicates and non-strings are dropped' );

// 5. ensure_all() over many records, in batches.
$GLOBALS['t_posts'] = array(); $GLOBALS['t_meta'] = array();
for ( $i = 1; $i <= 450; $i++ ) { t_add_post( $i, 0 === $i % 2 ? 'ftnc_customer' : 'ftnc_service' ); }
t_add_post( 900, 'ftnc_memory_card' );
t_equals( 451, count( Fotonic_Record_Uid::missing_ids( 1000 ) ), 'missing_ids(): every record lacks a uid' );
t_equals( 5, Fotonic_Record_Uid::ensure_all( 5 ), 'ensure_all(5): stops at the limit' );
t_equals( 446, count( Fotonic_Record_Uid::missing_ids( 1000 ) ), 'ensure_all(5): five written' );
t_equals( 446, Fotonic_Record_Uid::ensure_all(), 'ensure_all(): the rest, across several batches' );
t_equals( 0, count( Fotonic_Record_Uid::missing_ids( 1000 ) ), 'ensure_all(): none left' );
$uids = array();
foreach ( $GLOBALS['t_meta'] as $id => $m ) { $uids[] = $m['_ftnc_uid']; }
t_equals( 451, count( array_unique( $uids ) ), 'every record has its own uid' );
t_equals( 0, Fotonic_Record_Uid::ensure_all(), 'ensure_all(): a second run writes nothing' );

// 6. A write that fails must not loop forever.
$GLOBALS['t_meta'] = array();
$GLOBALS['t_fail_writes'] = true;
t_equals( 0, Fotonic_Record_Uid::ensure_all(), 'ensure_all(): failing writes end the loop with 0' );
$GLOBALS['t_fail_writes'] = false;

// 7. Admin backfill: one batch per request, flag only when nothing is left.
$GLOBALS['t_meta'] = array();
$GLOBALS['t_can'] = false;
Fotonic_Record_Uid::maybe_backfill();
t_equals( 451, count( Fotonic_Record_Uid::missing_ids( 1000 ) ), 'backfill: not for a non-admin' );
$GLOBALS['t_can'] = true; $GLOBALS['t_ajax'] = true;
Fotonic_Record_Uid::maybe_backfill();
t_equals( 451, count( Fotonic_Record_Uid::missing_ids( 1000 ) ), 'backfill: not during ajax' );
$GLOBALS['t_ajax'] = false; $GLOBALS['t_cron'] = true;
Fotonic_Record_Uid::maybe_backfill();
t_equals( 451, count( Fotonic_Record_Uid::missing_ids( 1000 ) ), 'backfill: not during cron' );
$GLOBALS['t_cron'] = false;
Fotonic_Record_Uid::maybe_backfill();
t_equals( 251, count( Fotonic_Record_Uid::missing_ids( 1000 ) ), 'backfill: one batch of 200 per request' );
t_equals( false, (bool) get_option( 'fotonic_uid_backfill_done' ), 'backfill: flag not set while records are left' );
Fotonic_Record_Uid::maybe_backfill();
Fotonic_Record_Uid::maybe_backfill();
t_equals( 0, count( Fotonic_Record_Uid::missing_ids( 1000 ) ), 'backfill: finished after three requests' );
t_equals( true, (bool) get_option( 'fotonic_uid_backfill_done' ), 'backfill: flag set when nothing is left' );

t_done();
