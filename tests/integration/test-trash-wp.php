<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

// The Bin (phase 16, 1.7.0): list, expiry, restore status, warnings, blockers, single / several / all delete, the word,
// the guard. Drives the REST handlers in-process.
//
// SAFETY: the real Bin of the site may hold the admin's own records. EVERY read and write of Fotonic_Trash is limited to
// the records this test creates through the test seam `ftnc_trash_scope`, so "delete all" can never touch anything else.
// Google is blocked (pre_http_request) and the Vault is simulated as "never set up" (in memory, no DB write).
// Take a DB dump first (tests/run.sh, see master-plan).

$run = 'tr' . wp_generate_password( 6, false, false );
add_filter( 'pre_option_' . Fotonic_Vault::OPTION_SETUP, '__return_zero' );
unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );
add_filter( 'pre_http_request', function () { return new WP_Error( 'ftnc_test_blocked', 'blocked by test' ); }, 1 );

$GLOBALS['t_scope'] = array();
add_filter( 'ftnc_trash_scope', function () { return $GLOBALS['t_scope']; } );

$made = array(); // every id this test created, for the cleanup
$make = function ( string $type, string $title, array $meta = array(), string $status = 'publish' ) use ( &$made, $run ) {
	$id = wp_insert_post( wp_slash( array( 'post_type' => $type, 'post_title' => "{$title} {$run}", 'post_status' => $status ) ) );
	foreach ( $meta as $k => $v ) {
		update_post_meta( $id, $k, wp_slash( $v ) );
	}
	$made[]                = $id;
	$GLOBALS['t_scope'][]  = $id;
	return (int) $id;
};

register_shutdown_function( function () use ( &$made ) {
	foreach ( array_reverse( $made ) as $id ) {
		wp_delete_post( $id, true );
		wp_clear_scheduled_hook( 'fotonic_gcal_sync_work', array( (int) $id ) );
		wp_clear_scheduled_hook( 'fotonic_gcal_update_reminders', array( (int) $id ) );
	}
} );

$rest = function ( array $params = array() ) {
	$req = new WP_REST_Request( 'POST' );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	return $req;
};
$exists = function ( int $id ) { return null !== get_post( $id ); };

// ----- Fixtures -----
$c1  = $make( 'ftnc_customer', 'Customer one' );
$s1  = $make( 'ftnc_service', 'Service one' );
$w1  = $make( 'ftnc_work', 'Work one', array( '_ftnc_customer_id' => $c1, '_ftnc_event_date' => '2031-05-01', '_ftnc_gcal_sync' => '1', '_ftnc_work_services' => wp_json_encode( array( array( 'service_id' => $s1 ) ) ) ) );
$s2  = $make( 'ftnc_service', 'Service two' );
$k1  = $make( 'ftnc_memory_card', 'Card one' );
$l1  = $make( 'ftnc_backup_loc', 'Place one' );
$c3  = $make( 'ftnc_customer', 'Customer three' );
$s3  = $make( 'ftnc_service', 'Service three' );
$w2  = $make( 'ftnc_work', 'Work two (stays alive)', array( '_ftnc_customer_id' => $c3, '_ftnc_work_services' => wp_json_encode( array( array( 'service_id' => $s3 ) ) ) ) );
$wd  = $make( 'ftnc_work', 'Work draft', array(), 'draft' );
$has_pro = class_exists( 'Fotonic_Quotes' ) && post_type_exists( 'ftnc_quote' );
$t1  = $has_pro ? $make( 'ftnc_task', 'Task one' ) : 0;
$q1  = 0;
if ( $has_pro ) {
	$q1 = $make( 'ftnc_quote', 'Quote one', array( '_ftnc_quote_work_id' => $w2 ) );
}
$plain = wp_insert_post( wp_slash( array( 'post_type' => 'post', 'post_title' => "Plain post {$run}", 'post_status' => 'publish' ) ) );
$made[] = $plain;
$outside = wp_insert_post( wp_slash( array( 'post_type' => 'ftnc_service', 'post_title' => "Outside scope {$run}", 'post_status' => 'publish' ) ) );
$made[] = $outside; // a plugin record in the Bin that is NOT in the scope: no call may touch it

// Different "deleted on" times, newest last created: w1 oldest, c1 newest of the first group.
$to_bin = array( $w1, $c1, $s1, $s2, $k1, $l1, $c3, $s3, $wd, $t1, $q1 );
$time   = time() - 20 * 86400;
foreach ( array_filter( $to_bin ) as $id ) {
	wp_trash_post( $id );
	update_post_meta( $id, '_wp_trash_meta_time', $time );
	$time += 3600;
}
wp_trash_post( $plain );
wp_trash_post( $outside );
$in_scope = count( array_filter( $to_bin ) );

// ----- Summary, list, expiry -----
$sum = Fotonic_Trash_REST_API::summary()->get_data();
t_equals( $in_scope, $sum['count'], 'summary counts only the plugin records of the scope' );
t_equals( 'DELETE', $sum['word'], 'the server owns the word' );
$page = Fotonic_Trash::items( 1, 100 );
t_equals( $in_scope, $page['total'], 'list total = records in the Bin' );
$ids = wp_list_pluck( $page['data'], 'id' );
t_true( ! in_array( $plain, $ids, true ), 'a regular WordPress post in the Bin is not listed' );
t_true( ! in_array( $outside, $ids, true ), 'a plugin record outside the scope is not listed' );
$last = array_filter( $to_bin );
t_equals( end( $last ), $ids[0], 'the most recently deleted comes first' );
$row = $page['data'][ array_search( $c1, $ids, true ) ];
t_true( '' !== $row['type_label'], 'the type has a readable name' );
t_equals( 'ftnc_customer', $row['type'], 'the type is the record type' );
t_true( false !== strpos( (string) $row['title'], 'Customer one' ), 'the title is read (Vault never set up)' );
if ( Fotonic_Trash::auto_days() > 0 ) {
	t_equals( (int) get_post_meta( $c1, '_wp_trash_meta_time', true ) + Fotonic_Trash::auto_days() * 86400, $row['expires_at'], 'expires_at = deleted time + EMPTY_TRASH_DAYS' );
}
$small = Fotonic_Trash::items( 1, 3 );
t_equals( 3, count( $small['data'] ), 'paging: per_page is respected' );
t_equals( (int) ceil( $in_scope / 3 ), $small['pages'], 'paging: pages' );

// ----- By type and the type filter -----
$expect = array();
foreach ( array_filter( $to_bin ) as $id ) {
	$t = get_post_type( $id );
	$expect[ $t ] = ( $expect[ $t ] ?? 0 ) + 1;
}
$by = Fotonic_Trash::by_type();
ksort( $expect );
ksort( $by );
t_equals( $expect, $by, 'by_type counts every type of the scope, in one query' );
$sum = Fotonic_Trash_REST_API::summary()->get_data();
t_equals( $expect['ftnc_customer'], (array) $sum['by_type'] ? ( (array) $sum['by_type'] )['ftnc_customer'] : 0, 'summary carries by_type' );
t_true( '' !== ( (array) $sum['names'] )['ftnc_customer'], 'summary carries the plural name of each type' );
$cust = Fotonic_Trash::items( 1, 100, 'ftnc_customer' );
t_equals( $expect['ftnc_customer'], $cust['total'], 'the list can be filtered to one type' );
t_true( ! array_diff( wp_list_pluck( $cust['data'], 'type' ), array( 'ftnc_customer' ) ), 'and shows only that type' );
$req = new WP_REST_Request( 'GET' );
$req->set_param( 'type', 'post' );
t_equals( 400, Fotonic_Trash_REST_API::items( $req )->get_status(), 'a type that is not one of the Bin: 400 on the list' );
$req->set_param( 'type', 'ftnc_nothing' );
t_equals( 400, Fotonic_Trash_REST_API::items( $req )->get_status(), 'an unknown type: 400, never silently "everything"' );
t_equals( 0, Fotonic_Trash::count( 'post' ), 'count of an invalid type is 0' );

// ----- Blockers -----
$by = array();
foreach ( $page['data'] as $r ) { $by[ $r['id'] ] = $r; }
t_true( null === $by[ $c1 ]['blocked'], 'a customer whose only work is in the Bin is not blocked' );
t_true( is_string( $by[ $c3 ]['blocked'] ), 'a customer with a live work is blocked, with a sentence' );
t_true( is_string( $by[ $s3 ]['blocked'] ), 'a service used by a live work is blocked' );
t_true( null === $by[ $s1 ]['blocked'], 'a service used only by a work in the Bin is not blocked' );
t_true( null === $by[ $wd ]['blocked'], 'a work is never blocked' );
if ( $has_pro ) {
	t_true( is_string( $by[ $q1 ]['blocked'] ), 'Pro: a quote converted into a live work is blocked' );
}
// A DRAFT work counts as alive (older restores left drafts behind).
$wdraft = $make( 'ftnc_work', 'Draft using customer one', array( '_ftnc_customer_id' => $c1 ), 'draft' );
t_true( is_string( Fotonic_Trash::blocker( get_post( $c1 ) ) ), 'a draft work still blocks its customer' );
wp_delete_post( $wdraft, true );
t_true( null === Fotonic_Trash::blocker( get_post( $c1 ) ), 'without it the customer is free again' );

// ----- Restore -----
$cron_before = count( _get_cron_array() );
$res = Fotonic_Trash_REST_API::restore( $rest( array( 'ids' => array( $w1 ) ) ) )->get_data()['results'][0];
t_true( $res['ok'], 'restore works' );
t_equals( 'publish', get_post_status( $w1 ), 'a restored work is PUBLISHED again, not a draft' );
t_equals( $c1, (int) get_post_meta( $w1, '_ftnc_customer_id', true ), 'the link to the customer is intact' );
t_true( false !== strpos( get_post( $w1 )->post_title, 'Work one' ), 'the title is intact' );
t_equals( 'parent_in_bin', $res['warnings'][0]['code'] ?? '', 'the customer is still in the Bin: a warning' );
$wreq = new WP_REST_Request( 'GET' );
$wreq->set_param( 'id', $w1 );
t_equals( 'trash', Fotonic_REST_API::get_work( $wreq )->get_data()['customer_state'] ?? '', 'the work says its customer is in the Bin (the form shows a notice)' );
wp_clear_scheduled_hook( 'fotonic_gcal_sync_work', array( $w1 ) ); // untrash of a work with the Google tick re-creates its event (cron, no HTTP now)
$res = Fotonic_Trash_REST_API::restore( $rest( array( 'ids' => array( $wd ) ) ) )->get_data()['results'][0];
t_equals( 'draft', get_post_status( $wd ), 'a record that was a draft goes back to a draft' );
// The customer goes, the restored work is alive and points at nothing: parent_missing.
wp_trash_post( $w1 );
update_post_meta( $w1, '_wp_trash_meta_time', time() );
$ghost = $make( 'ftnc_customer', 'Ghost customer' );
update_post_meta( $w1, '_ftnc_customer_id', $ghost );
wp_delete_post( $ghost, true );
$res = Fotonic_Trash::restore( $w1 );
t_equals( 'parent_missing', $res['warnings'][0]['code'] ?? '', 'the customer no longer exists: a warning' );
$wreq->set_param( 'id', $w1 );
t_equals( 'missing', Fotonic_REST_API::get_work( $wreq )->get_data()['customer_state'] ?? '', 'the work says its customer no longer exists' );
update_post_meta( $w1, '_ftnc_customer_id', $c1 );
wp_trash_post( $w1 );
update_post_meta( $w1, '_wp_trash_meta_time', time() );
wp_trash_post( $wd );

$bad = Fotonic_Trash::restore( $plain );
t_true( ! $bad['ok'], 'a record of another type is not restored' );
t_equals( 'trash', get_post_status( $plain ), 'and stays in the Bin' );
$bad = Fotonic_Trash::restore( $outside );
t_true( ! $bad['ok'], 'a record outside the scope is not restored' );
$bad = Fotonic_Trash::restore( $c3 + 99999999 );
t_true( ! $bad['ok'], 'an unknown id is not found' );
// The status filter belongs to the plugin's types only.
wp_untrash_post( $plain );
t_equals( 'draft', get_post_status( $plain ), 'WordPress default untouched for other post types (draft)' );
wp_trash_post( $plain );

// ----- Delete -----
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'ids' => array() ) ) );
t_equals( 400, $r->get_status(), 'nothing chosen: 400' );
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'ids' => array( $s2, $k1 ) ) ) );
t_equals( 400, $r->get_status(), 'two records without the word: 400' );
t_equals( 'confirm_required', $r->get_data()['code'], 'confirm_required' );
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'ids' => array( $s2, $k1 ), 'confirm' => 'delete' ) ) );
t_equals( 400, $r->get_status(), 'a wrong word: 400' );
t_true( $exists( $s2 ) && $exists( $k1 ), 'nothing was deleted without the word' );
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'all' => true ) ) );
t_equals( 400, $r->get_status(), 'all without the word: 400' );
t_equals( $in_scope, Fotonic_Trash::count(), 'nothing deleted after the refusals' );

$r = Fotonic_Trash_REST_API::delete( $rest( array( 'ids' => array( $s2 ) ) ) )->get_data();
t_equals( 1, $r['deleted'], 'one record: no word needed' );
t_true( ! $exists( $s2 ), 'and it is gone' );
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'ids' => array( $c3 ) ) ) )->get_data();
t_equals( 0, $r['deleted'], 'a blocked record is not deleted' );
t_equals( $c3, $r['blocked'][0]['id'], 'it is reported as blocked' );
t_true( '' !== $r['blocked'][0]['reason'], 'with its reason' );
t_true( $exists( $c3 ), 'and it still exists' );
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'ids' => array( $plain ) ) ) )->get_data();
t_equals( 0, $r['deleted'], 'a record of another type is never deleted through the Bin' );
t_true( $exists( $plain ), 'it still exists' );

// Several with the word.
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'ids' => array( $k1, $l1 ), 'confirm' => 'DELETE' ) ) )->get_data();
t_equals( 2, $r['deleted'], 'two records with the word' );
t_true( ! $exists( $k1 ) && ! $exists( $l1 ), 'both gone' );

// The guard: a running restore / encryption refuses everything (simulated in memory).
add_filter( 'pre_option_' . Fotonic_Vault_Encrypt_Existing::OPTION_JOB, function () { return array( 'status' => 'running' ); } );
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'ids' => array( $s1 ) ) ) );
t_equals( 423, $r->get_status(), 'guard: delete refused while a job runs' );
$r = Fotonic_Trash_REST_API::restore( $rest( array( 'ids' => array( $s1 ) ) ) );
t_equals( 423, $r->get_status(), 'guard: restore refused too' );
remove_all_filters( 'pre_option_' . Fotonic_Vault_Encrypt_Existing::OPTION_JOB );
t_true( $exists( $s1 ), 'nothing changed under the guard' );

// ALL + TYPE: only that kind of record, and an invalid type deletes nothing.
$tc1 = $make( 'ftnc_memory_card', 'Type card A' );
$tc2 = $make( 'ftnc_memory_card', 'Type card B' );
$ts1 = $make( 'ftnc_service', 'Type service A' );
$ts2 = $make( 'ftnc_service', 'Type service B' );
foreach ( array( $tc1, $tc2, $ts1, $ts2 ) as $id ) { wp_trash_post( $id ); }
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'all' => true, 'type' => 'post', 'confirm' => 'DELETE' ) ) );
t_equals( 400, $r->get_status(), 'all + a type that is not one of the Bin: 400' );
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'all' => true, 'type' => 'ftnc_nothing', 'confirm' => 'DELETE' ) ) );
t_equals( 400, $r->get_status(), 'all + an unknown type: 400' );
t_true( $exists( $tc1 ) && $exists( $ts1 ) && $exists( $plain ), 'nothing was deleted by the refused calls' );
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'all' => true, 'type' => 'ftnc_memory_card', 'confirm' => 'DELETE' ) ) )->get_data();
t_true( ! $exists( $tc1 ) && ! $exists( $tc2 ), 'all + type: the records of that type are gone' );
t_true( $exists( $ts1 ) && $exists( $ts2 ), 'all + type: the other types are untouched (services)' );
t_true( $exists( $c3 ) && $exists( $s1 ) && $exists( $w1 ) && $exists( $wd ), 'all + type: customers, works and the rest are untouched' );
t_true( $r['deleted'] >= 2, 'all + type: reports what it deleted' );
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'all' => true, 'type' => 'ftnc_memory_card' ) ) );
t_equals( 400, $r->get_status(), 'all + type without the word: 400' );

// ALL: the server picks the ids itself, in order, in batches; blocked ones are skipped and reported.
$extra = array();
for ( $i = 0; $i < Fotonic_Trash::BATCH + 5; $i++ ) { // more than one batch
	$id = $make( 'ftnc_service', "Bulk {$i}" );
	wp_trash_post( $id );
	$extra[] = $id;
}
$calls   = 0;
$deleted = 0;
$blocked = array();
$skip    = array();
do {
	$r = Fotonic_Trash_REST_API::delete( $rest( array( 'all' => true, 'confirm' => 'DELETE', 'skip' => $skip, 'ids' => array( $plain, $outside ) ) ) )->get_data();
	$deleted += $r['deleted'];
	$blocked  = array_merge( $blocked, $r['blocked'] );
	$skip     = array_merge( $skip, wp_list_pluck( $r['blocked'], 'id' ) );
	$calls++;
} while ( $r['remaining'] > 0 && $calls < 20 );
t_true( $calls >= 2, 'all: more than one batch was needed (' . $calls . ' calls)' );
t_true( $deleted >= count( $extra ), 'all: the bulk records were deleted (' . $deleted . ')' );
foreach ( $extra as $id ) {
	t_true( ! $exists( $id ), "all: bulk record {$id} is gone" );
}
t_true( $exists( $c3 ) && $exists( $s3 ), 'all: the blocked customer and service are still there' );
$blocked_ids = wp_list_pluck( $blocked, 'id' );
t_true( in_array( $c3, $blocked_ids, true ) && in_array( $s3, $blocked_ids, true ), 'all: they are reported with a reason' );
t_true( ! $exists( $c1 ) && ! $exists( $s1 ) && ! $exists( $w1 ), 'all: works first, then the customer and service they used (no longer held back)' );
t_true( $exists( $plain ), 'all: a regular post is untouched (the client list is ignored)' );
t_true( $exists( $outside ) && 'trash' === get_post_status( $outside ), 'all: a plugin record outside the scope is untouched' );
t_true( $exists( $w2 ), 'all: a live work is untouched' );
if ( $has_pro ) {
	t_true( $exists( $q1 ) && in_array( $q1, $blocked_ids, true ), 'Pro: the converted quote was held back by its live work' );
}

// Once the live work goes to the Bin, what it held can go too.
wp_trash_post( $w2 );
update_post_meta( $w2, '_wp_trash_meta_time', time() );
$r = Fotonic_Trash_REST_API::delete( $rest( array( 'all' => true, 'confirm' => 'DELETE' ) ) )->get_data();
t_true( ! $exists( $c3 ) && ! $exists( $s3 ) && ! $exists( $w2 ), 'the held-back records go once nothing alive points at them' );
if ( $has_pro ) {
	t_true( ! $exists( $q1 ), 'Pro: and so does the quote, once its work is in the Bin' );
}
t_equals( 0, Fotonic_Trash::count(), 'the scope of the test is empty' );
t_true( $exists( $plain ) && $exists( $outside ), 'the records outside the scope are still there' );

// The deletion hooks of the plugin are the ones doing the file cleanup.
t_true( false !== has_action( 'before_delete_post', array( 'Fotonic_Work_Files', 'on_delete_post' ) ), 'documents of a deleted work are removed by the existing deletion hook' );

// No Google event was scheduled and left behind.
$left = 0;
foreach ( _get_cron_array() as $events ) {
	foreach ( $events as $hook => $e ) {
		if ( 0 === strpos( $hook, 'fotonic_gcal_' ) ) {
			foreach ( $e as $ev ) {
				if ( array_intersect( array_map( 'intval', (array) ( $ev['args'] ?? array() ) ), $made ) ) {
					$left++;
				}
			}
		}
	}
}
t_equals( 0, $left, 'no Google cron event left for any record of this test' );

exit( t_done() );
