<?php
/**
 * The Vault's free-text fields (work notes, memory card notes, service notes, service titles and
 * prices) and the encrypt-existing job, on the live site.
 *
 * Safety: the real Vault is never touched. An unlocked session is simulated with a RANDOM Master Key
 * (a cookie that exists only in this process), every record is a fixture this test creates and deletes,
 * and the job runs with the `ftnc_vault_encrypt_scope` filter limited to those fixtures. At the start
 * and the end the test fingerprints every real customer, service, work and memory card and fails if
 * anything changed. Run it only after a database dump.
 */
ob_start(); // the simulated session sets a cookie
require __DIR__ . '/../lib/wp-bootstrap.php';
t_preserve_options( [ 'fotonic_vault_encrypt_job', 'fotonic_vault_encrypt_version', 'fotonic_vault_encrypt_lock' ] );

$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
$ck = Fotonic_Vault::COOKIE_NAME;

function ftnc_t_rest( string $method, string $route, $body = null ): WP_REST_Response {
    $req = new WP_REST_Request( $method, $route );
    $req->set_header( 'x_wp_nonce', $_SERVER['HTTP_X_WP_NONCE'] );
    if ( null !== $body ) {
        $req->set_header( 'content-type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $req );
}
function ftnc_t_fingerprint(): string {
    global $wpdb;
    $types = "'ftnc_customer','ftnc_service','ftnc_work','ftnc_memory_card'";
    $posts = $wpdb->get_results( "SELECT ID, post_title, post_content, post_name, post_status, post_modified_gmt FROM {$wpdb->posts} WHERE post_type IN ($types) ORDER BY ID", ARRAY_A );
    $meta  = $wpdb->get_results( "SELECT pm.post_id, pm.meta_key, pm.meta_value FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type IN ($types) ORDER BY pm.post_id, pm.meta_key, pm.meta_id", ARRAY_A );
    return md5( wp_json_encode( [ $posts, $meta ] ) );
}
function ftnc_t_raw( int $id ): array {
    global $wpdb;
    return (array) $wpdb->get_row( $wpdb->prepare( "SELECT post_title, post_content, post_name FROM {$wpdb->posts} WHERE ID = %d", $id ), ARRAY_A );
}
function ftnc_t_meta( int $id, string $key ): string { return (string) get_post_meta( $id, $key, true ); }
$unlock = function () use ( $ck ) { $mk = $GLOBALS['t_mk']; Fotonic_Vault::update_session_key( $mk ); };
$lock   = function () use ( $ck ) { unset( $_COOKIE[ $ck ] ); };

$real_before = ftnc_t_fingerprint();
$GLOBALS['t_mk'] = random_bytes( 32 );
t_equals( true, Fotonic_Vault::is_setup(), 'the live site has a Vault (it is never touched)' );
$real_key_state = Fotonic_Vault::get_session_key();
t_equals( null, $real_key_state, 'the test starts locked (this process holds no Vault session)' );

$fixture_ids = [];
$track = function ( int $id ) use ( &$fixture_ids ) { $fixture_ids[] = $id; return t_cleanup( $id ); };

// ---- 1. services, unlocked with the simulated key ---------------------------------------------
$unlock();
t_true( null !== Fotonic_Vault::get_session_key(), 'a simulated unlocked session' );
$res = ftnc_t_rest( 'POST', '/fotonic/v1/services', [ 'title' => 'Zeta album', 'base_price' => 120.5, 'notes' => '<p>Stampa <b>lusso</b></p>' ] );
t_equals( 201, $res->get_status(), 'create service: 201' );
$svc_a = $track( (int) $res->get_data()['id'] );
$raw = ftnc_t_raw( $svc_a );
t_true( 0 === strpos( $raw['post_title'], 'f1:' ), 'service title is stored encrypted' );
t_true( false === strpos( $raw['post_name'], 'zeta' ), 'the slug does not spell the title' );
t_true( 0 === strpos( ftnc_t_meta( $svc_a, '_ftnc_base_price' ), 'f1:' ), 'service price is stored encrypted' );
t_true( 0 === strpos( ftnc_t_meta( $svc_a, '_ftnc_notes' ), 'f1:' ), 'service notes are stored encrypted' );
t_equals( [ 'Zeta album', 120.5, '<p>Stampa <b>lusso</b></p>' ], [ $res->get_data()['title'], $res->get_data()['base_price'], $res->get_data()['notes'] ], 'the create answer is readable' );
$res2 = ftnc_t_rest( 'POST', '/fotonic/v1/services', [ 'title' => 'alfa ritratti', 'base_price' => 80 ] );
$svc_b = $track( (int) $res2->get_data()['id'] );
$list = ftnc_t_rest( 'GET', '/fotonic/v1/services' )->get_data()['data'];
$titles = array_values( array_filter( array_column( $list, 'title' ), function ( $t ) { return in_array( $t, [ 'Zeta album', 'alfa ritratti' ], true ); } ) );
t_equals( [ 'alfa ritratti', 'Zeta album' ], $titles, 'the list is sorted by the decrypted title, not by the stored text' );
$upd = ftnc_t_rest( 'PUT', '/fotonic/v1/services/' . $svc_a, [ 'title' => 'Zeta album 2', 'base_price' => 99, 'notes' => 'n2' ] );
t_equals( 200, $upd->get_status(), 'update service: 200' );
t_equals( [ 'Zeta album 2', 99.0, 'n2' ], [ $upd->get_data()['title'], $upd->get_data()['base_price'], $upd->get_data()['notes'] ], 'the update answer is readable' );
t_equals( "service-{$svc_a}", ftnc_t_raw( $svc_a )['post_name'], 'an update keeps the slug neutral' );

// ---- 2. the same record, locked ------------------------------------------------------------------
$lock();
$g = ftnc_t_rest( 'GET', '/fotonic/v1/services/' . $svc_a )->get_data();
t_equals( [ null, null, null ], [ $g['title'], $g['base_price'], $g['notes'] ], 'locked: title, price and notes are null (never "" or 0)' );
$before = [ ftnc_t_raw( $svc_a ), ftnc_t_meta( $svc_a, '_ftnc_base_price' ), ftnc_t_meta( $svc_a, '_ftnc_notes' ) ];
$blocked = ftnc_t_rest( 'PUT', '/fotonic/v1/services/' . $svc_a, [ 'title' => 'hacked' ] );
t_equals( 423, $blocked->get_status(), 'locked: a service write is refused with 423' );
t_equals( 'vault_locked', $blocked->get_data()['code'] ?? '', 'locked: the code is vault_locked' );
t_equals( 423, ftnc_t_rest( 'POST', '/fotonic/v1/services', [ 'title' => 'new while locked' ] )->get_status(), 'locked: creating a service is refused too' );
t_equals( $before, [ ftnc_t_raw( $svc_a ), ftnc_t_meta( $svc_a, '_ftnc_base_price' ), ftnc_t_meta( $svc_a, '_ftnc_notes' ) ], 'locked: nothing changed in the database' );

// ---- 3. works and memory cards -----------------------------------------------------------------
$card = $track( (int) wp_insert_post( [ 'post_type' => 'ftnc_memory_card', 'post_status' => 'publish', 'post_title' => 'ftnc test card' ] ) );
$unlock();
$res = ftnc_t_rest( 'POST', '/fotonic/v1/works', [
    'title' => 'ftnc test work', 'notes' => '<p>Nota "riservata" \\ è</p>', 'event_date' => '2099-01-01',
    'services' => [ [ 'service_id' => $svc_a, 'price_override' => 99, 'notes_override' => 'extra ore' ], [ 'service_id' => $svc_a, 'price_override' => 50, 'notes_override' => 'seconda riga' ] ],
    'memory_cards' => [ [ 'card_id' => $card, 'notes' => 'scheda 1 piena' ] ],
] );
t_equals( 201, $res->get_status(), 'create work: 201' );
$work = $track( (int) $res->get_data()['id'] );
$d = $res->get_data();
t_equals( '<p>Nota "riservata" \\ è</p>', $d['notes'], 'the work notes read back whole (quotes, backslash, accents)' );
t_true( 0 === strpos( ftnc_t_raw( $work )['post_content'], 'f1:' ), 'work notes are stored encrypted' );
$svc_rows = json_decode( ftnc_t_meta( $work, '_ftnc_work_services' ), true );
t_equals( [ true, true ], [ 0 === strpos( $svc_rows[0]['notes_override'], 'f1:' ), 0 === strpos( $svc_rows[1]['notes_override'], 'f1:' ) ], 'service notes of the work are stored encrypted' );
t_equals( 99.0, (float) $svc_rows[0]['price_override'], 'the price override stays a plain number' );
$card_rows = json_decode( ftnc_t_meta( $work, '_ftnc_memory_cards' ), true );
t_true( 0 === strpos( $card_rows[0]['notes'], 'f1:' ), 'the memory card note is stored encrypted' );
t_equals( [ 'extra ore', 'seconda riga' ], [ $d['services'][0]['notes_override'], $d['services'][1]['notes_override'] ], 'service notes read back' );
t_equals( 'Zeta album 2', $d['services'][0]['service_title'], 'the service title shows decrypted inside the work' );
t_equals( 'scheda 1 piena', $d['memory_cards'][0]['notes'], 'the card note reads back' );

// Locked: reads are null; writes that carry text are refused; writes that do not must not lose anything.
$lock();
$dl = ftnc_t_rest( 'GET', '/fotonic/v1/works/' . $work )->get_data();
t_equals( [ null, null, null, null ], [ $dl['notes'], $dl['services'][0]['notes_override'], $dl['services'][0]['service_title'], $dl['memory_cards'][0]['notes'] ], 'locked: work notes, service note, service title and card note are null' );
$stored_work = [ ftnc_t_raw( $work )['post_content'], ftnc_t_meta( $work, '_ftnc_work_services' ), ftnc_t_meta( $work, '_ftnc_memory_cards' ) ];
t_equals( 423, ftnc_t_rest( 'PUT', '/fotonic/v1/works/' . $work, [ 'notes' => 'new text' ] )->get_status(), 'locked: new notes are refused (423)' );
t_equals( 423, ftnc_t_rest( 'PUT', '/fotonic/v1/works/' . $work, [ 'memory_cards' => [ [ 'card_id' => $card, 'notes' => 'x' ] ] ] )->get_status(), 'locked: a new card note is refused (423)' );
t_equals( 423, ftnc_t_rest( 'PUT', '/fotonic/v1/works/' . $work, [ 'services' => [ [ 'service_id' => $svc_a, 'price_override' => 1, 'notes_override' => 'x' ] ] ] )->get_status(), 'locked: a new service note is refused (423)' );
// What the screen sends back for what it could not read: null, or '' (the screen turns null into '').
$keep = ftnc_t_rest( 'PUT', '/fotonic/v1/works/' . $work, [ 'title' => 'ftnc test work renamed', 'notes' => '', 'memory_cards' => [ [ 'card_id' => $card, 'notes' => '' ] ], 'services' => [ [ 'service_id' => $svc_a, 'price_override' => 99, 'notes_override' => '' ], [ 'service_id' => $svc_a, 'price_override' => 50, 'notes_override' => null ] ] ] );
t_equals( 200, $keep->get_status(), 'locked: a save with empty/null text for unreadable fields goes through' );
t_equals( $stored_work, [ ftnc_t_raw( $work )['post_content'], ftnc_t_meta( $work, '_ftnc_work_services' ), ftnc_t_meta( $work, '_ftnc_memory_cards' ) ], 'locked: no encrypted note was overwritten by empty text or null' );
t_equals( 'ftnc test work renamed', ftnc_t_raw( $work )['post_title'], 'locked: the title (plain) did change' );
// Unlocked again: everything is still there.
$unlock();
$dn = ftnc_t_rest( 'GET', '/fotonic/v1/works/' . $work )->get_data();
t_equals( [ '<p>Nota "riservata" \\ è</p>', 'extra ore', 'seconda riga', 'scheda 1 piena' ], [ $dn['notes'], $dn['services'][0]['notes_override'], $dn['services'][1]['notes_override'], $dn['memory_cards'][0]['notes'] ], 'unlocked again: every note is intact' );
$old_ct = ftnc_t_raw( $work )['post_content'];
ftnc_t_rest( 'PUT', '/fotonic/v1/works/' . $work, [ 'notes' => 'testo nuovo' ] );
t_equals( 'testo nuovo', ftnc_t_rest( 'GET', '/fotonic/v1/works/' . $work )->get_data()['notes'], 'unlocked: new notes replace the old ones' );
t_true( $old_ct !== ftnc_t_raw( $work )['post_content'], 'unlocked: the stored text changed' );
t_equals( 200, ftnc_t_rest( 'PUT', '/fotonic/v1/works/' . $work, [ 'notes' => '' ] )->get_status(), 'unlocked: empty notes are accepted' );
t_equals( '', ftnc_t_raw( $work )['post_content'], 'unlocked: empty notes are stored empty' );

// ---- 4. no Vault at all (simulated): plain storage, with the escape ----------------------------
$lock();
add_filter( 'pre_option_fotonic_vault_setup', '__return_zero' );
t_equals( null, Fotonic_Vault::get_session_key(), 'no Vault: no key' );
$plain_svc = ftnc_t_rest( 'POST', '/fotonic/v1/services', [ 'title' => 'Servizio in chiaro', 'base_price' => 10, 'notes' => 'f1:sembra cifrato' ] );
t_equals( 201, $plain_svc->get_status(), 'no Vault: create service' );
$svc_p = $track( (int) $plain_svc->get_data()['id'] );
t_equals( 'Servizio in chiaro', ftnc_t_raw( $svc_p )['post_title'], 'no Vault: the title is stored plain' );
t_equals( 'f0:f1:sembra cifrato', ftnc_t_meta( $svc_p, '_ftnc_notes' ), 'no Vault: a text starting with f1: is stored escaped' );
t_equals( 'f1:sembra cifrato', ftnc_t_rest( 'GET', '/fotonic/v1/services/' . $svc_p )->get_data()['notes'], 'no Vault: and read back exactly' );
$plain_work = ftnc_t_rest( 'POST', '/fotonic/v1/works', [ 'title' => 'ftnc plain work', 'notes' => 'nota in chiaro', 'services' => [ [ 'service_id' => $svc_p, 'price_override' => 10, 'notes_override' => 'n' ] ] ] );
$work_p = $track( (int) $plain_work->get_data()['id'] );
t_equals( 'nota in chiaro', ftnc_t_raw( $work_p )['post_content'], 'no Vault: work notes are stored plain' );
// An encrypted text from a destroyed Vault is unreadable, and a save may replace it (there is nothing to protect).
global $wpdb;
$wpdb->update( $wpdb->posts, [ 'post_content' => Fotonic_Field_Crypt::encrypt( 'lost', random_bytes( 32 ) ) ], [ 'ID' => $work_p ] );
clean_post_cache( $work_p );
t_equals( null, ftnc_t_rest( 'GET', '/fotonic/v1/works/' . $work_p )->get_data()['notes'], 'no Vault, ciphertext of a lost key: null' );
ftnc_t_rest( 'PUT', '/fotonic/v1/works/' . $work_p, [ 'notes' => 'riscritta' ] );
t_equals( 'riscritta', ftnc_t_raw( $work_p )['post_content'], 'no Vault: a save replaces the unreadable text' );
remove_filter( 'pre_option_fotonic_vault_setup', '__return_zero' );

// ---- 5. the job on live data, limited to fixtures ----------------------------------------------
$plain_ids = [ $svc_p, $work_p ];
$customer  = $track( (int) wp_insert_post( [ 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => 'ftnc test customer' ] ) );
update_post_meta( $customer, '_ftnc_people', wp_slash( wp_json_encode( [ [ 'first_name' => 'Anna', 'last_name' => 'Test', 'email' => 'anna.test@example.com', 'is_main' => true ] ] ) ) );
$scope = [ $svc_p, $work_p, $customer ];
add_filter( 'ftnc_vault_encrypt_scope', function () use ( $scope ) { return $scope; } );
t_equals( $scope, Fotonic_Vault_Encrypt_Existing::scope(), 'the job is limited to the fixtures' );
delete_option( 'fotonic_vault_encrypt_job' );
delete_option( 'fotonic_vault_encrypt_version' );
$unlock();
Fotonic_Vault_Encrypt_Existing::create();
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_blocking(), 'a pending job blocks nothing' );
t_equals( 201, ftnc_t_rest( 'POST', '/fotonic/v1/services', [ 'title' => 'durante il job pending' ] )->get_status(), 'a pending job: writes are allowed' );
$track( (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'ftnc_service'" ) );
$st = Fotonic_Vault_Encrypt_Existing::run_batch( $GLOBALS['t_mk'], 1 ); // scan
t_equals( true, Fotonic_Vault_Encrypt_Existing::is_blocking(), 'a running job blocks writes' );
foreach ( [ [ 'PUT', '/fotonic/v1/services/' . $svc_a, [ 'title' => 'x' ] ], [ 'POST', '/fotonic/v1/services', [ 'title' => 'y' ] ], [ 'PUT', '/fotonic/v1/works/' . $work, [ 'title' => 'z' ] ], [ 'POST', '/fotonic/v1/works', [ 'title' => 'w' ] ], [ 'PUT', '/fotonic/v1/customers/' . $customer, [ 'title' => 'c' ] ] ] as $i => $c ) {
    $r = ftnc_t_rest( $c[0], $c[1], $c[2] );
    t_equals( [ 423, 'encrypt_in_progress' ], [ $r->get_status(), $r->get_data()['code'] ?? '' ], "while the job runs, write $i is refused with encrypt_in_progress" );
}
t_equals( 200, ftnc_t_rest( 'GET', '/fotonic/v1/works/' . $work )->get_status(), 'reads stay open during the job' );
t_equals( 'paused', ( ftnc_t_rest( 'POST', '/fotonic/v1/vault/encrypt-existing/pause' )->get_data()['status'] ?? '' ), 'pause over REST' );
t_equals( 423, ftnc_t_rest( 'PUT', '/fotonic/v1/services/' . $svc_a, [ 'title' => 'x' ] )->get_status(), 'a paused job still blocks writes' );
t_equals( 409, ftnc_t_rest( 'POST', '/fotonic/v1/vault/encrypt-existing/pause' )->get_status(), 'pausing a paused job: 409' );
t_equals( 'running', ( ftnc_t_rest( 'POST', '/fotonic/v1/vault/encrypt-existing/resume' )->get_data()['status'] ?? '' ), 'resume over REST' );
t_equals( 409, ftnc_t_rest( 'POST', '/fotonic/v1/vault/encrypt-existing/resume' )->get_status(), 'resuming a running job: 409' );
$guard = 0;
do { $st = ftnc_t_rest( 'POST', '/fotonic/v1/vault/encrypt-existing' ); $data = $st->get_data(); } while ( 'done' !== ( $data['status'] ?? '' ) && ++$guard < 60 );
t_equals( 'done', $data['status'], 'the job finishes over REST' );
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_blocking(), 'nothing blocks after the job' );
t_equals( 'Servizio in chiaro', Fotonic_Field_Crypt::read( ftnc_t_raw( $svc_p )['post_title'], $GLOBALS['t_mk'] ), 'job: the plain service title is now encrypted and readable' );
t_true( 0 === strpos( ftnc_t_raw( $svc_p )['post_title'], 'f1:' ), 'job: the stored title is ciphertext' );
t_equals( "service-{$svc_p}", ftnc_t_raw( $svc_p )['post_name'], 'job: the slug no longer spells the title' );
t_equals( 'f1:sembra cifrato', Fotonic_Field_Crypt::read( ftnc_t_meta( $svc_p, '_ftnc_notes' ), $GLOBALS['t_mk'] ), 'job: the escaped note decrypts to the original text' );
t_equals( 'riscritta', Fotonic_Field_Crypt::read( ftnc_t_raw( $work_p )['post_content'], $GLOBALS['t_mk'] ), 'job: the work notes are encrypted and readable' );
$people = json_decode( ftnc_t_meta( $customer, '_ftnc_people' ), true );
t_equals( 'Anna', Fotonic_Crypto::decrypt( $people[0]['first_name'], $GLOBALS['t_mk'] ), 'job: customer names are encrypted' );
t_equals( 'anna.test@example.com', Fotonic_Crypto::deterministic_decrypt( $people[0]['email'], $GLOBALS['t_mk'] ), 'job: customer email is encrypted (deterministic)' );
t_equals( true, count( array_diff( Fotonic_Vault_Fields::signatures(), Fotonic_Vault_Encrypt_Existing::covered() ) ) === 0, 'job: the covered field signatures are recorded' );

// The way out: a job that cannot finish is cancelled over REST, and writes are free again.
Fotonic_Vault_Encrypt_Existing::create();
Fotonic_Vault_Encrypt_Existing::run_batch( $GLOBALS['t_mk'], 1 );
t_equals( 423, ftnc_t_rest( 'POST', '/fotonic/v1/services', [ 'title' => 'q' ] )->get_status(), 'a running job blocks...' );
$c = ftnc_t_rest( 'POST', '/fotonic/v1/vault/encrypt-existing/cancel' );
t_equals( [ 200, 'none' ], [ $c->get_status(), $c->get_data()['status'] ?? '' ], '...cancel over REST ends it' );
t_equals( 201, ftnc_t_rest( 'POST', '/fotonic/v1/services', [ 'title' => 'dopo cancel' ] )->get_status(), 'writes work again after the cancel' );
$track( (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type = 'ftnc_service'" ) );
t_equals( false, (bool) $wpdb->get_var( "SELECT 1 FROM {$wpdb->options} WHERE option_name = 'fotonic_vault_encrypt_lock'" ), 'no lock row is left behind' );

// ---- 6. the status route creates one pending job for a Vault from before the field list ----------
delete_option( 'fotonic_vault_encrypt_version' );
delete_option( 'fotonic_vault_encrypt_job' );
$s = ftnc_t_rest( 'GET', '/fotonic/v1/vault/status' )->get_data();
t_equals( [ true, 'pending' ], [ $s['encrypt_pending'], $s['encrypt']['status'] ], 'status: a Vault with no recorded version gets one pending job' );
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_blocking(), 'status: that job blocks nothing' );
Fotonic_Vault_Encrypt_Existing::clear();

// ---- 7. every real record is exactly as it was -------------------------------------------------
$lock();
foreach ( array_reverse( $fixture_ids ) as $id ) { wp_delete_post( $id, true ); }
$GLOBALS['ftnc_cleanup'] = [];
t_equals( $real_before, ftnc_t_fingerprint(), 'no real customer, service, work or memory card was changed' );

t_done();
