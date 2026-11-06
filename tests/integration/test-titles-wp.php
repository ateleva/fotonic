<?php
/**
 * Phase 15, free plugin: encrypted titles of customers and works through the real REST handlers.
 * A throwaway key stands in for the session key (cookie in memory only): the live Vault is never opened or changed.
 * Everything created here is tracked by ID and removed at the end. Google is never reached: works are made without an event date
 * and without the Google tick, and outgoing calls to googleapis.com are blocked anyway.
 */
require __DIR__ . '/../lib/wp-bootstrap.php';

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
	return false !== strpos( (string) $url, 'googleapis.com' ) ? new WP_Error( 'ftnc_test_blocked', 'blocked by the test' ) : $pre;
}, 1, 3 );

$GLOBALS['ftnc_ids'] = array();
register_shutdown_function( function () {
	unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );
	foreach ( array_reverse( array_unique( $GLOBALS['ftnc_ids'] ) ) as $id ) {
		wp_delete_post( $id, true );
		wp_clear_scheduled_hook( 'fotonic_gcal_sync_work', array( (int) $id ) );
		wp_clear_scheduled_hook( 'fotonic_gcal_update_reminders', array( (int) $id ) );
	}
	Fotonic_Titles::flush_cache();
} );
$track = function ( $id ) {
	$GLOBALS['ftnc_ids'][] = (int) $id;
	return (int) $id;
};

// The gate is open for the free plugin alone; with Pro active it needs the filter Pro 1.7.0 registers.
add_filter( 'ftnc_titles_encryptable', '__return_true' );

$rest = function ( $method, array $params = array(), $body = null ) {
	$req = new WP_REST_Request( $method );
	foreach ( $params as $k => $v ) {
		$req->set_param( $k, $v );
	}
	if ( null !== $body ) {
		$req->set_header( 'content-type', 'application/json' );
		$req->set_body( wp_json_encode( $body ) );
	}
	return $req;
};
$data = function ( $res ) {
	return $res instanceof WP_REST_Response ? $res->get_data() : $res;
};

$key = random_bytes( 32 );
ob_start();
Fotonic_Vault::update_session_key( $key );
ob_end_clean();
t_equals( $key, Fotonic_Vault::get_session_key(), 'test key is the session key' );
$was_setup = Fotonic_Vault::is_setup();

$raw = function ( $id, $f = 'post_title' ) {
	global $wpdb;
	return (string) $wpdb->get_var( $wpdb->prepare( "SELECT {$f} FROM {$wpdb->posts} WHERE ID = %d", $id ) );
};

// A service and a one-installment payment that covers it: create_work() refuses a work whose rows do not add up.
$svc = pt_svc_id( $rest, $data, $track );
function pt_svc_id( $rest, $data, $track ) {
	$res = $data( Fotonic_REST_API::create_service( $rest( 'POST', array(), array( 'title' => 'Servizio ftnc-t15', 'base_price' => 100 ) ) ) );
	return $track( $res['id'] );
}
$work_body = function ( array $more ) use ( $svc ) {
	return array_merge( array( 'services' => array( array( 'service_id' => $svc, 'price_override' => 100 ) ), 'installments' => array( array( 'title' => 'Saldo', 'base' => 100, 'status' => 'unpaid', 'type' => 'default', 'date' => '' ) ) ), $more );
};

// ---- 1. Customers: create, read, rename ----
$nasty = "L'Anna \\ \"Rossi\" Nicol\u{f2}";
$res   = $data( Fotonic_REST_API::create_customer( $rest( 'POST', array(), array( 'title' => $nasty, 'people' => array( array( 'first_name' => 'Giulia', 'last_name' => 'Verdi', 'email' => 'giulia.verdi@example.com', 'phone' => '3331234567', 'is_main' => true ) ) ) ) ) );
$c1    = $track( $res['id'] );
t_equals( $nasty, $res['title'], 'create: the response carries the plain title' );
t_true( 0 === strncmp( $raw( $c1 ), 'f1:', 3 ), 'create: the stored title is ciphertext' );
t_true( 1 === preg_match( '/^customer-[a-z0-9]{10}$/', $raw( $c1, 'post_name' ) ), 'create: random neutral slug, nothing of the title' );
t_equals( '', (string) get_post_meta( $c1, '_wp_old_slug', true ), 'create: no old slug' );
$get = $data( Fotonic_REST_API::get_customer( $rest( 'GET', array( 'id' => $c1 ) ) ) );
t_equals( $nasty, $get['title'], 'read: plain title with the key' );

$upd = $data( Fotonic_REST_API::update_customer( $rest( 'PUT', array( 'id' => $c1 ), array( 'title' => $nasty ) ) ) );
$stored_before = $raw( $c1 );
$data( Fotonic_REST_API::update_customer( $rest( 'PUT', array( 'id' => $c1 ), array( 'title' => $nasty ) ) ) );
t_equals( $stored_before, $raw( $c1 ), 'update: the same text keeps the stored ciphertext' );
$data( Fotonic_REST_API::update_customer( $rest( 'PUT', array( 'id' => $c1 ), array( 'title' => 'Rossi Bianchi' ) ) ) );
t_equals( 'Rossi Bianchi', Fotonic_Titles::read( $c1, $key ), 'update: renamed' );
t_equals( 'customer-' . $c1, $raw( $c1, 'post_name' ), 'update: slug is now customer-ID' );
t_equals( '', (string) get_post_meta( $c1, '_wp_old_slug', true ), 'update: no old slug (WordPress would have kept the plain one)' );
$data( Fotonic_REST_API::update_customer( $rest( 'PUT', array( 'id' => $c1 ), array( 'people' => array( array( 'first_name' => 'Giulia', 'last_name' => 'Verdi', 'email' => 'giulia.verdi@example.com', 'phone' => '3331234567', 'is_main' => true ) ) ) ) ) );
t_equals( 'Rossi Bianchi', Fotonic_Titles::read( $c1, $key ), 'update: a body without a title leaves the title alone' );

// An old record with a plain title and a slug made from it.
$old = $track( wp_insert_post( wp_slash( array( 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => 'Vecchio Cliente Verdi' ) ) ) );
t_equals( 'vecchio-cliente-verdi', $raw( $old, 'post_name' ), 'old record: the slug came from the title' );
$data( Fotonic_REST_API::update_customer( $rest( 'PUT', array( 'id' => $old ), array( 'title' => 'Vecchio Cliente Verdi 2' ) ) ) );
t_true( 0 === strncmp( $raw( $old ), 'f1:', 3 ), 'old record: encrypted on the first edit' );
t_equals( 'customer-' . $old, $raw( $old, 'post_name' ), 'old record: slug replaced' );
t_equals( '', (string) get_post_meta( $old, '_wp_old_slug', true ), 'old record: the plain slug was NOT kept as _wp_old_slug' );

// An unreadable title (another key) is null, and not overwritten by a body without a title.
$other = random_bytes( 32 );
$unr   = $track( wp_insert_post( wp_slash( array( 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => Fotonic_Field_Crypt::encrypt( 'Illeggibile', $other ), 'post_name' => 'customer-unreadable-x' ) ) ) );
$got   = $data( Fotonic_REST_API::get_customer( $rest( 'GET', array( 'id' => $unr ) ) ) );
t_equals( null, $got['title'], 'unreadable: the title is null, never ciphertext or empty' );
$stored_unr = $raw( $unr );
$data( Fotonic_REST_API::update_customer( $rest( 'PUT', array( 'id' => $unr ), array( 'people' => array() ) ) ) );
t_equals( $stored_unr, $raw( $unr ), 'unreadable: an update without a title does not overwrite it' );

// Vault set up and locked: a body with a title is refused.
if ( $was_setup ) {
	unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );
	$locked = Fotonic_REST_API::create_customer( $rest( 'POST', array(), array( 'title' => 'Non deve entrare' ) ) );
	t_equals( 423, $locked->get_status(), 'locked: a title is refused with 423' );
	ob_start();
	Fotonic_Vault::update_session_key( $key );
	ob_end_clean();
}

// ---- 2. Works ----
$w1 = $data( Fotonic_REST_API::create_work( $rest( 'POST', array(), $work_body( array( 'title' => "Matrimonio d'Anna & Luca", 'customer_id' => $c1, 'notes' => 'nota' ) ) ) ) );
$w1 = $track( $w1['id'] );
t_true( 0 === strncmp( $raw( $w1 ), 'f1:', 3 ) && 0 === strncmp( $raw( $w1, 'post_name' ), 'work-', 5 ), 'work: encrypted title and a neutral slug' );
$fw = $data( Fotonic_REST_API::get_work( $rest( 'GET', array( 'id' => $w1 ) ) ) );
t_equals( array( "Matrimonio d'Anna & Luca", 'Rossi Bianchi' ), array( $fw['title'], $fw['customer_title'] ), 'work: title and customer_title are plain' );
$data( Fotonic_REST_API::update_work( $rest( 'PUT', array( 'id' => $w1 ), array( 'title' => 'Battesimo Luca' ) ) ) );
t_equals( 'Battesimo Luca', Fotonic_Titles::read( $w1, $key ), 'work: renamed' );
t_equals( 'work-' . $w1, $raw( $w1, 'post_name' ), 'work: slug is work-ID after a rename' );
t_equals( '', (string) get_post_meta( $w1, '_wp_old_slug', true ), 'work: no old slug' );
$linked = $data( Fotonic_REST_API::can_delete_customer( $rest( 'GET', array( 'id' => $c1 ) ) ) );
t_true( false !== strpos( (string) ( $linked['reason'] ?? '' ), 'Battesimo Luca' ) && false === strpos( (string) ( $linked['reason'] ?? '' ), 'f1:' ), 'can-delete customer: the message names the decrypted work' );
$cal = $data( Fotonic_REST_API::get_calendar_works( $rest( 'GET', array( 'from' => '2000-01-01', 'to' => '2000-01-02' ) ) ) );
t_true( is_array( $cal ), 'calendar: answers' );
// Calendar with a dated work.
update_post_meta( $w1, '_ftnc_event_date', '2099-05-05' );
$cal = $data( Fotonic_REST_API::get_calendar_works( $rest( 'GET', array( 'from' => '2099-05-01', 'to' => '2099-05-30' ) ) ) );
$row = null;
foreach ( $cal as $e ) {
	if ( (int) $e['id'] === $w1 ) {
		$row = $e;
	}
}
t_equals( array( 'Battesimo Luca', 'Rossi Bianchi' ), $row ? array( $row['title'], $row['customer_title'] ) : null, 'calendar: plain work and customer titles' );
delete_post_meta( $w1, '_ftnc_event_date' );

// ---- 3. Lists: 30 customers, paging, search, order ----
$names = array( 'Anna 10', 'Anna 2', "Nicol\u{f2} Verdi", 'Zeta', 'alpha', 'Ürsula' );
for ( $i = 0; $i < 30; $i++ ) {
	$res = $data( Fotonic_REST_API::create_customer( $rest( 'POST', array(), array( 'title' => ( $names[ $i ] ?? 'Cliente ' . sprintf( '%02d', $i ) ) . ' ftnc-t15' ) ) ) );
	$track( $res['id'] );
}
$p1 = $data( Fotonic_REST_API::get_customers( $rest( 'GET', array( 'search' => 'ftnc-t15', 'page' => 1, 'per_page' => 24 ) ) ) );
$p2 = $data( Fotonic_REST_API::get_customers( $rest( 'GET', array( 'search' => 'ftnc-t15', 'page' => 2, 'per_page' => 24 ) ) ) );
$ids1 = array_column( $p1['data'], 'id' );
$ids2 = array_column( $p2['data'], 'id' );
t_equals( array( 30, 2, 24, 6 ), array( $p1['total'], $p1['pages'], count( $ids1 ), count( $ids2 ) ), 'paging: 30 customers in 24 + 6' );
t_equals( 0, count( array_intersect( $ids1, $ids2 ) ), 'paging: no repeats between the pages' );
$titles = array_merge( array_column( $p1['data'], 'title' ), array_column( $p2['data'], 'title' ) );
$sorted = $titles;
usort( $sorted, array( 'Fotonic_Titles', 'compare' ) );
t_equals( $sorted, $titles, 'order: natural, case and accent insensitive' );
t_true( array_search( 'Anna 2 ftnc-t15', $titles, true ) < array_search( 'Anna 10 ftnc-t15', $titles, true ), 'order: Anna 2 before Anna 10' );
$s = $data( Fotonic_REST_API::get_customers( $rest( 'GET', array( 'search' => 'nicolo ftnc-t15', 'per_page' => 24 ) ) ) );
t_equals( array( "Nicol\u{f2} Verdi ftnc-t15" ), array_column( $s['data'], 'title' ), 'search: Nicolo finds Nicolò' );
$s = $data( Fotonic_REST_API::get_customers( $rest( 'GET', array( 'search' => 'giulia', 'per_page' => 24 ) ) ) );
t_true( in_array( $c1, array_column( $s['data'], 'id' ), true ), 'search: a customer is found by the first name of a person' );
$s = $data( Fotonic_REST_API::get_customers( $rest( 'GET', array( 'search' => 'verdi example.com', 'per_page' => 24 ) ) ) );
t_true( in_array( $c1, array_column( $s['data'], 'id' ), true ), 'search: by last name and email together' );
$s = $data( Fotonic_REST_API::get_customers( $rest( 'GET', array( 'search' => '3331234567', 'per_page' => 24 ) ) ) );
t_true( in_array( $c1, array_column( $s['data'], 'id' ), true ), 'search: by phone' );
$s = $data( Fotonic_REST_API::get_customers( $rest( 'GET', array( 'search' => 'rossi bianchi', 'per_page' => 24 ) ) ) );
t_true( in_array( $c1, array_column( $s['data'], 'id' ), true ), 'search: all the words of the title' );
$s = $data( Fotonic_REST_API::get_customers( $rest( 'GET', array( 'search' => 'zzz-nothing', 'per_page' => 24 ) ) ) );
t_equals( array( 0, 0, array() ), array( $s['total'], $s['pages'], $s['data'] ), 'search: nothing found' );

// Works: search + customer filter.
$w2 = $data( Fotonic_REST_API::create_work( $rest( 'POST', array(), $work_body( array( 'title' => 'Ritratti Nicol' . "\u{f2}", 'customer_id' => $c1 ) ) ) ) );
$w2 = $track( $w2['id'] );
$w3 = $data( Fotonic_REST_API::create_work( $rest( 'POST', array(), $work_body( array( 'title' => 'Ritratti Altrove' ) ) ) ) );
$w3 = $track( $w3['id'] );
// The works list only shows works that have an event date (it orders by it); set it straight in the meta so no Google job is scheduled.
update_post_meta( $w2, '_ftnc_event_date', '2099-07-01' );
update_post_meta( $w3, '_ftnc_event_date', '2099-07-02' );
$r = $data( Fotonic_REST_API::get_works( $rest( 'GET', array( 'search' => 'ritratti nicolo', 'page' => 1, 'per_page' => 48 ) ) ) );
t_equals( array( $w2 ), array_column( $r['data'], 'id' ), 'works search: both words, accents ignored' );
$r = $data( Fotonic_REST_API::get_works( $rest( 'GET', array( 'search' => 'ritratti', 'customer_id' => $c1, 'page' => 1, 'per_page' => 48 ) ) ) );
t_equals( array( $w2 ), array_column( $r['data'], 'id' ), 'works search + customer filter combine' );
$r = $data( Fotonic_REST_API::get_works( $rest( 'GET', array( 'search' => 'ritratti', 'payment_status' => 'unpaid', 'page' => 1, 'per_page' => 48 ) ) ) );
t_true( 2 === count( array_intersect( array_column( $r['data'], 'id' ), array( $w2, $w3 ) ) ), 'works search + payment status combine' );
$r = $data( Fotonic_REST_API::get_works( $rest( 'GET', array( 'search' => 'ritratti', 'storage_alert' => 'true', 'page' => 1, 'per_page' => 48 ) ) ) );
t_equals( 0, count( array_intersect( array_column( $r['data'], 'id' ), array( $w2, $w3 ) ) ), 'works: storage_alert intersects the search instead of replacing it' );

// ---- 4. Slugs elsewhere: the Bin ----
wp_trash_post( $w3 );
t_true( false === strpos( $raw( $w3, 'post_name' ), 'ritratti' ), 'bin: the trashed work slug says nothing of the title' );
t_true( false === strpos( (string) get_post_meta( $w3, '_wp_desired_post_slug', true ), 'ritratti' ), 'bin: _wp_desired_post_slug says nothing of the title' );
wp_untrash_post( $w3 );
t_true( false === strpos( $raw( $w3, 'post_name' ), 'ritratti' ), 'bin: restored work keeps a slug without the title' );

// ---- 5. The cleanup of readable slugs elsewhere (the job runs it at its end), scoped to this test's own records ----
$s1 = $track( wp_insert_post( wp_slash( array( 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => 'Slug Cliente ftnc-t15' ) ) ) );
update_post_meta( $s1, '_wp_old_slug', 'vecchio-slug-in-chiaro' );
$s2 = $track( wp_insert_post( wp_slash( array( 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => 'Slug Cestino ftnc-t15' ) ) ) );
wp_trash_post( $s2 );
t_true( '' !== (string) get_post_meta( $s2, '_wp_desired_post_slug', true ), 'bin: WordPress remembered the plain slug of the trashed record' );
$s3 = $track( wp_insert_post( wp_slash( array( 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => 'Fuori portata ftnc-t15' ) ) ) );
update_post_meta( $s3, '_wp_old_slug', 'fuori-portata' );
( new Fotonic_Vault_Fields_Repo() )->neutralize_slug_meta( 'ftnc_customer', array( $s1, $s2 ) );
t_equals( '', (string) get_post_meta( $s1, '_wp_old_slug', true ), 'cleanup: the old slug is deleted' );
t_equals( 'customer-' . $s2, (string) get_post_meta( $s2, '_wp_desired_post_slug', true ), 'cleanup: the slug a record in the Bin gets back is neutral' );
t_equals( 'fuori-portata', (string) get_post_meta( $s3, '_wp_old_slug', true ), 'cleanup: a record outside the scope is not touched' );

t_done();
