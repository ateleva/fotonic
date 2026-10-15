<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

// Saved personal details must never be blanked by a save made from a form that could not read
// them (wrong or lost key, locked Vault), and a locked Vault must never store typed details in clear.

$run = 'pp' . wp_generate_password( 6, false, false );
register_shutdown_function( function () use ( $run ) {
    remove_filter( 'pre_option_' . Fotonic_Vault::OPTION_SETUP, '__return_true' );
    unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );
    $ids = get_posts( [ 'post_type' => [ 'ftnc_customer', 'ftnc_work' ], 'post_status' => 'any', 's' => $run, 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ] );
    foreach ( $ids as $id ) {
        wp_delete_post( $id, true );
        wp_clear_scheduled_hook( 'fotonic_gcal_sync_work', [ (int) $id ] );
        wp_clear_scheduled_hook( 'fotonic_gcal_update_reminders', [ (int) $id ] );
    }
} );

$k1 = random_bytes( 32 );
$k2 = random_bytes( 32 );
$rest = function ( string $method, array $params = [], $body = null ) {
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
$stored_people = function ( int $id ): array {
    return (array) json_decode( (string) get_post_meta( $id, '_ftnc_people', true ), true );
};

// ---------------------------------------------------------------------------
// 1. People: a field the key cannot decrypt reads as null (not '') and carries a ref.
// ---------------------------------------------------------------------------
$people = [
    [ 'first_name' => 'Anna', 'last_name' => 'Rossi', 'email' => 'anna@example.com', 'phone' => '+39 1', 'gender' => 'female', 'is_main' => true ],
    [ 'first_name' => 'Luca', 'last_name' => '', 'email' => 'luca@example.com', 'gender' => 'male' ],
];
$stored = Fotonic_REST_API::encrypt_people( $people, $k1 );
$wrong  = Fotonic_REST_API::decrypt_people( $stored, $k2 );
t_equals( null, $wrong[0]['first_name'], 'wrong key: random-IV field reads as null, not an empty string' );
t_equals( null, $wrong[0]['email'], 'wrong key: deterministic field reads as null' );
t_equals( '', $wrong[1]['last_name'], 'wrong key: a field that was empty stays an empty string' );
t_equals( 'female', $wrong[0]['gender'], 'wrong key: gender stays readable' );
t_equals( [ 0, 1 ], array_column( $wrong, 'ref' ), 'each person carries its stored index as ref' );
$right = Fotonic_REST_API::decrypt_people( $stored, $k1 );
t_equals( 'Anna', $right[0]['first_name'], 'right key still reads the value' );
t_equals( 'luca@example.com', $right[1]['email'], 'right key still reads the deterministic value' );
t_true( ! array_key_exists( 'ref', $stored[0] ), 'ref is never part of what is stored' );

// ---------------------------------------------------------------------------
// 2. People: saving rows that carry null keeps the stored value byte for byte.
// ---------------------------------------------------------------------------
$edit          = $wrong;
$edit[0]['phone'] = '+39 999'; // the only thing the user could do: type a new value
$saved = Fotonic_REST_API::encrypt_people( $edit, $k2, $stored );
t_equals( $stored[0]['first_name'], $saved[0]['first_name'], 'unreadable first_name kept exactly as stored' );
t_equals( $stored[0]['last_name'], $saved[0]['last_name'], 'unreadable last_name kept exactly as stored' );
t_equals( $stored[0]['email'], $saved[0]['email'], 'unreadable email kept exactly as stored' );
t_equals( '+39 999', Fotonic_REST_API::decrypt_people( $saved, $k2 )[0]['phone'], 'the value the user typed is saved under the current key' );
t_equals( 'female', $saved[0]['gender'], 'gender kept' );
t_equals( $stored[1]['first_name'], $saved[1]['first_name'], 'second person kept too' );
t_equals( 2, count( $saved ), 'both people saved' );

// Removing the first person and keeping the second must not mix the rows up.
$removed = Fotonic_REST_API::encrypt_people( [ $wrong[1] ], $k2, $stored );
t_equals( 1, count( $removed ), 'one person left' );
t_equals( $stored[1]['first_name'], $removed[0]['first_name'], 'the remaining person keeps its own stored value (matched by ref, not by position)' );
t_equals( true, $removed[0]['is_main'], 'the remaining person becomes the main contact' );

// An empty string is a deliberate clear; a new row without ref has nothing to keep.
$cleared = Fotonic_REST_API::encrypt_people( [ array_merge( $wrong[0], [ 'last_name' => '', 'first_name' => '' ] ) ], $k2, $stored );
t_equals( '', $cleared[0]['first_name'], 'empty string clears the field' );
t_equals( $stored[0]['email'], $cleared[0]['email'], 'other unreadable fields of that row are still kept' );
$fresh = Fotonic_REST_API::encrypt_people( [ [ 'first_name' => null, 'last_name' => 'Z' ] ], $k2, $stored );
t_equals( '', $fresh[0]['first_name'], 'a new row without ref keeps nothing' );
$bad_ref = Fotonic_REST_API::encrypt_people( [ [ 'first_name' => null, 'ref' => 99 ], [ 'first_name' => null, 'ref' => 'x' ] ], $k2, $stored );
t_equals( [ '', '' ], array_column( $bad_ref, 'first_name' ), 'an unknown ref keeps nothing' );
$no_stored = Fotonic_REST_API::encrypt_people( [ [ 'first_name' => null, 'ref' => 0 ] ], $k2 );
t_equals( '', $no_stored[0]['first_name'], 'no stored rows given: nothing to keep' );
$old_client = Fotonic_REST_API::encrypt_people( [ [ 'first_name' => 'Anna', 'ref' => 0 ] ], $k1, $stored );
t_equals( 'female', $old_client[0]['gender'], 'a client that does not send gender does not clear it' );
t_equals( $stored[0]['email'], $old_client[0]['email'], 'a client that does not send email does not clear it' );
t_true( 'Anna' !== $old_client[0]['first_name'], 'a value that is sent is still encrypted' );

// ---------------------------------------------------------------------------
// 3. Addresses.
// ---------------------------------------------------------------------------
$addr_stored = Fotonic_REST_API::encrypt_addresses( [ [ 'label' => 'Chiesa', 'street' => 'Piazza Duomo 1' ], [ 'label' => 'Sala', 'street' => 'Via Roma 2' ] ], $k1 );
$addr_wrong  = Fotonic_REST_API::decrypt_addresses( $addr_stored, $k2 );
t_equals( [ null, null ], array_column( $addr_wrong, 'street' ), 'wrong key: streets read as null' );
t_equals( [ 0, 1 ], array_column( $addr_wrong, 'ref' ), 'addresses carry their ref' );
$addr_saved = Fotonic_REST_API::encrypt_addresses( [ array_merge( $addr_wrong[1], [ 'label' => 'Sala nuova' ] ) ], $k2, $addr_stored );
t_equals( $addr_stored[1]['street'], $addr_saved[0]['street'], 'unreadable street kept exactly as stored' );
t_equals( 'Sala nuova', $addr_saved[0]['label'], 'the label edit is saved' );
t_equals( '', Fotonic_REST_API::encrypt_addresses( [ [ 'label' => 'x', 'street' => '' ] ], $k2, $addr_stored )[0]['street'], 'empty street clears' );
t_equals( 'Via Nuova 3', Fotonic_REST_API::decrypt_addresses( Fotonic_REST_API::encrypt_addresses( [ array_merge( $addr_wrong[0], [ 'street' => 'Via Nuova 3' ] ) ], $k2, $addr_stored ), $k2 )[0]['street'], 'a typed street replaces the unreadable one' );

// ---------------------------------------------------------------------------
// 4. Locked Vault (set up, no session key): personal details are refused, nothing is written.
// ---------------------------------------------------------------------------
add_filter( 'pre_option_' . Fotonic_Vault::OPTION_SETUP, '__return_true' );
unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );
t_equals( true, Fotonic_Vault::is_setup() && null === Fotonic_Vault::get_session_key(), 'Vault is set up and locked' );

$before = (int) wp_count_posts( 'ftnc_customer' )->publish;
$res    = Fotonic_REST_API::create_customer( $rest( 'POST', [], [ 'title' => "Locked {$run}", 'people' => [ [ 'first_name' => 'Plain' ] ] ] ) );
t_equals( 423, $res->get_status(), 'locked: create_customer with typed details is 423' );
t_equals( 'vault_locked', $res->get_data()['code'] ?? null, 'locked: error code' );
t_equals( $before, (int) wp_count_posts( 'ftnc_customer' )->publish, 'locked: no customer was created' );

$cid = t_cleanup( (int) wp_insert_post( wp_slash( [ 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => "Cust {$run}" ] ) ) );
update_post_meta( $cid, '_ftnc_people', wp_slash( wp_json_encode( $stored ) ) );
$res = Fotonic_REST_API::update_customer( $rest( 'PUT', [ 'id' => $cid ], [ 'title' => "Renamed {$run}", 'people' => [ [ 'first_name' => 'Plain', 'ref' => 0 ] ] ] ) );
t_equals( 423, $res->get_status(), 'locked: update_customer with typed details is 423' );
t_equals( "Cust {$run}", get_post_field( 'post_title', $cid, 'raw' ), 'locked: nothing written on a refused update (title too)' );
t_equals( $stored, $stored_people( $cid ), 'locked: stored people untouched after a refused update' );

// What a form shows while locked is null for the encrypted fields: saving that keeps everything.
$locked_view = Fotonic_REST_API::decrypt_people( $stored, null );
t_equals( null, $locked_view[0]['first_name'], 'locked: encrypted field is null in the form' );
$res = Fotonic_REST_API::update_customer( $rest( 'PUT', [ 'id' => $cid ], [ 'title' => "Renamed {$run}", 'people' => $locked_view ] ) );
t_equals( 200, $res->get_status(), 'locked: saving the form exactly as shown (nulls) is allowed' );
t_equals( $stored, $stored_people( $cid ), 'locked: nothing in the stored people changed' );
t_equals( "Renamed {$run}", get_post_field( 'post_title', $cid, 'raw' ), 'locked: the title edit was saved' );

$wid = t_cleanup( (int) wp_insert_post( wp_slash( [ 'post_type' => 'ftnc_work', 'post_status' => 'publish', 'post_title' => "Work {$run}" ] ) ) );
update_post_meta( $wid, '_ftnc_event_addresses', wp_slash( wp_json_encode( $addr_stored ) ) );
$res = Fotonic_REST_API::update_work( $rest( 'PUT', [ 'id' => $wid ], [ 'title' => "Work {$run}", 'event_addresses' => [ [ 'label' => 'x', 'street' => 'Via Chiaro 1', 'ref' => 0 ] ] ] ) );
t_equals( 423, $res->get_status(), 'locked: update_work with a typed street is 423' );
t_equals( $addr_stored, (array) json_decode( (string) get_post_meta( $wid, '_ftnc_event_addresses', true ), true ), 'locked: stored addresses untouched after a refused update' );
$res = Fotonic_REST_API::update_work( $rest( 'PUT', [ 'id' => $wid ], [ 'title' => "Work {$run}", 'event_addresses' => Fotonic_REST_API::decrypt_addresses( $addr_stored, null ) ] ) );
t_equals( 200, $res->get_status(), 'locked: saving a work with the addresses exactly as shown (nulls) is allowed' );
t_equals( $addr_stored, (array) json_decode( (string) get_post_meta( $wid, '_ftnc_event_addresses', true ), true ), 'locked: stored addresses unchanged' );
$res = Fotonic_REST_API::create_work( $rest( 'POST', [], [ 'title' => "NewWork {$run}", 'event_addresses' => [ [ 'label' => 'x', 'street' => 'Via Chiaro 1' ] ] ] ) );
t_equals( 423, $res->get_status(), 'locked: create_work with a typed street is 423' );

// Classic meta boxes follow the same rules.
$_POST = [
    'ftnc_meta_nonce'   => wp_create_nonce( 'ftnc_save_meta' ),
    '_wp_http_referer'  => '/',
    'ftnc_people_json'  => wp_json_encode( [ [ 'first_name' => 'Plain', 'ref' => 0, 'is_main' => true ] ] ),
];
$_REQUEST = $_POST;
Fotonic_Meta_Boxes::save_customer( $cid, get_post( $cid ) );
t_equals( $stored, $stored_people( $cid ), 'locked: the classic editor does not store typed details either' );
$_POST['ftnc_people_json'] = wp_json_encode( $locked_view );
$_REQUEST                  = $_POST;
Fotonic_Meta_Boxes::save_customer( $cid, get_post( $cid ) );
t_equals( $stored, $stored_people( $cid ), 'locked: the classic editor keeps what it could not read' );
$_POST = $_REQUEST = [];
remove_filter( 'pre_option_' . Fotonic_Vault::OPTION_SETUP, '__return_true' );

// ---------------------------------------------------------------------------
// 5. Unlocked with a key that cannot read the stored data (the case that blanked customers).
// ---------------------------------------------------------------------------
add_filter( 'pre_option_' . Fotonic_Vault::OPTION_SETUP, '__return_true' );
ob_start();
Fotonic_Vault::update_session_key( $k2 );
ob_end_clean();
t_equals( $k2, Fotonic_Vault::get_session_key(), 'session key active' );
try {
    $view = Fotonic_REST_API::update_customer( $rest( 'PUT', [ 'id' => $cid ], [ 'title' => "Renamed {$run}" ] ) )->get_data();
    t_true( array_key_exists( 'first_name', $view['people'][0] ) && null === $view['people'][0]['first_name'], 'the form receives null for what the key cannot read' );
    $res = Fotonic_REST_API::update_customer( $rest( 'PUT', [ 'id' => $cid ], [ 'title' => "Renamed {$run}", 'people' => $view['people'] ] ) );
    t_equals( 200, $res->get_status(), 'saving the form as received succeeds' );
    t_equals( $stored, $stored_people( $cid ), 'the unreadable data is still exactly as it was (nothing was blanked)' );

    $edited = $view['people'];
    $edited[1]['last_name'] = 'Bianchi';
    Fotonic_REST_API::update_customer( $rest( 'PUT', [ 'id' => $cid ], [ 'people' => $edited ] ) );
    $now = $stored_people( $cid );
    t_equals( $stored[0], $now[0], 'the other person is untouched' );
    t_equals( $stored[1]['first_name'], $now[1]['first_name'], 'unreadable first_name of the edited person is untouched' );
    t_equals( 'Bianchi', Fotonic_REST_API::decrypt_people( $now, $k2 )[1]['last_name'], 'the edited field was saved under the current key' );

    // Same for a work's addresses.
    $wview = Fotonic_REST_API::update_work( $rest( 'PUT', [ 'id' => $wid ], [ 'title' => "Work {$run}" ] ) )->get_data();
    t_equals( [ null, null ], array_column( $wview['event_addresses'], 'street' ), 'the work form receives null streets' );
    Fotonic_REST_API::update_work( $rest( 'PUT', [ 'id' => $wid ], [ 'title' => "Work {$run}", 'event_addresses' => $wview['event_addresses'] ] ) );
    t_equals( $addr_stored, (array) json_decode( (string) get_post_meta( $wid, '_ftnc_event_addresses', true ), true ), 'the unreadable streets are still exactly as they were' );

    // The classic editor shows readable values and keeps unreadable ones.
    $_POST = [
        'ftnc_meta_nonce'  => wp_create_nonce( 'ftnc_save_meta' ),
        '_wp_http_referer' => '/',
        'ftnc_people_json' => wp_json_encode( $view['people'] ),
    ];
    $_REQUEST = $_POST;
    Fotonic_Meta_Boxes::save_customer( $cid, get_post( $cid ) );
    t_equals( $now[0]['first_name'], $stored_people( $cid )[0]['first_name'], 'the classic editor keeps the unreadable value (no double encryption)' );
} finally {
    $_POST = $_REQUEST = [];
    unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );
    remove_filter( 'pre_option_' . Fotonic_Vault::OPTION_SETUP, '__return_true' );
}
t_equals( null, Fotonic_Vault::get_session_key(), 'session key removed' );

t_done();
