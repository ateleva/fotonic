<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

// Backup locations, shots and delivery on a work (1.6.6). Drives the REST handlers in-process with the Vault
// "never set up" (simulated in memory, no DB write), and removes every post it creates.

$GLOBALS['ftnc_run']           = 'bl' . wp_generate_password( 6, false, false );
$run                           = $GLOBALS['ftnc_run'];
$GLOBALS['ftnc_no_vault_hook'] = 'pre_option_' . Fotonic_Vault::OPTION_SETUP;
add_filter( $GLOBALS['ftnc_no_vault_hook'], '__return_zero' );
unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );

register_shutdown_function( function () {
    $tagged = get_posts( [
        'post_type'        => [ 'ftnc_work', 'ftnc_backup_loc' ],
        'post_status'      => [ 'publish', 'trash', 'draft' ],
        's'                => $GLOBALS['ftnc_run'],
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'suppress_filters' => true,
    ] );
    foreach ( array_unique( array_merge( array_reverse( $GLOBALS['ftnc_cleanup'] ), $tagged ) ) as $post_id ) {
        wp_delete_post( $post_id, true );
        wp_clear_scheduled_hook( 'fotonic_gcal_sync_work', [ (int) $post_id ] );
        wp_clear_scheduled_hook( 'fotonic_gcal_update_reminders', [ (int) $post_id ] );
    }
} );

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

// ----- Location CRUD -----
$res = Fotonic_Backup_Loc_REST_API::create_location( $rest( 'POST', [], [ 'title' => "NAS {$run}", 'type' => 'NAS', 'capacity' => '8 TB', 'status' => 'full', 'notes' => "Shelf \\ 2 {$run}" ] ) );
t_equals( 201, $res->get_status(), 'create location is 201' );
$loc = t_cleanup( (int) ( $res->get_data()['id'] ?? 0 ) );
$d   = $res->get_data();
t_equals( 'NAS', $d['type'], 'type is stored' );
t_equals( '8 TB', $d['capacity'], 'capacity is stored' );
t_equals( 'full', $d['status'], 'status is stored' );
t_equals( "Shelf \\ 2 {$run}", $d['notes'], 'notes round trip keeps the backslash' );

$res = Fotonic_Backup_Loc_REST_API::create_location( $rest( 'POST', [], [ 'title' => '  ' ] ) );
t_equals( 400, $res->get_status(), 'a name is required' );

$res = Fotonic_Backup_Loc_REST_API::update_location( $rest( 'PUT', [ 'id' => $loc ], [ 'status' => 'nonsense' ] ) );
t_equals( 'full', $res->get_data()['status'], 'an unknown status is ignored' );

$loc2 = t_cleanup( (int) Fotonic_Backup_Loc_REST_API::create_location( $rest( 'POST', [], [ 'title' => "Drive {$run}" ] ) )->get_data()['id'] );
t_equals( 'active', get_post_meta( $loc2, '_ftnc_loc_status', true ) ?: 'active', 'default status reads as active' );

// ----- Work: locations, shots, delivery -----
$body = [
    'title'             => "Wedding {$run}",
    'event_date'        => '2026-05-10',
    'storage_locations' => [ [ 'location_id' => $loc ], [ 'location_id' => $loc ], [ 'location_id' => $loc2 ], [ 'location_id' => 999999999 ] ],
    'shots_taken'       => '2400',
    'shots_delivered'   => 350,
    'delivery'          => [ 'title' => "Pixieset {$run}", 'url' => 'https://example.com/g/abc', 'notes' => "Pw \\ 1 {$run}", 'expires' => '2026-06-30' ],
];
$res = Fotonic_REST_API::create_work( $rest( 'POST', [], $body ) );
t_equals( 201, $res->get_status(), 'create work is 201' );
$wid = t_cleanup( (int) ( $res->get_data()['id'] ?? 0 ) );
$w   = $res->get_data();
t_equals( 2, count( $w['storage_locations'] ), 'duplicate and unknown location rows are dropped' );
t_equals( false, $w['storage_alert'], 'no alert while the locations exist' );
t_equals( 2400, $w['shots_taken'], 'shots taken is an int' );
t_equals( 350, $w['shots_delivered'], 'shots delivered is an int' );
t_equals( 'https://example.com/g/abc', $w['delivery']['url'], 'delivery url is stored' );
t_equals( '2026-06-30', $w['delivery']['expires'], 'delivery expiry is a date' );
t_equals( "Pw \\ 1 {$run}", $w['delivery']['notes'], 'delivery notes round trip' );

$res = Fotonic_REST_API::update_work( $rest( 'PUT', [ 'id' => $wid ], [ 'delivery' => [ 'url' => 'javascript:alert(1)', 'expires' => '2026-02-31' ], 'shots_taken' => '' ] ) );
$w   = $res->get_data();
t_equals( '', $w['delivery']['url'], 'a javascript: link is refused' );
foreach ( [ 'apple.com' => 'https://apple.com', 'www.example.co.uk/g/1' => 'https://www.example.co.uk/g/1', 'http://example.ch' => 'http://example.ch', 'https://swisstransfer.com/d/abc' => 'https://swisstransfer.com/d/abc' ] as $in => $out ) {
    t_equals( $out, Fotonic_REST_API::normalize_link( $in ), "link '{$in}' is accepted" );
}
foreach ( [ 'apple', 'apple.', 'a b.com', 'javascript:alert(1)', 'mailto:a@b.it', 'ftp://example.com', 'http://localhost', 'example.c' ] as $bad ) {
    t_equals( '', Fotonic_REST_API::normalize_link( $bad ), "link '{$bad}' is refused" );
}
t_equals( '', $w['delivery']['expires'], 'an impossible date is refused' );
t_equals( null, $w['shots_taken'], 'an empty shot count reads back as null' );
t_equals( 350, $w['shots_delivered'], 'a field left out of the body is untouched' );

// ----- Shots history -----
$res = Fotonic_Backup_Loc_REST_API::get_shots( $rest( 'GET', [ 'year' => '2026' ] ) );
$ids = array_column( $res->get_data()['data'], 'id' );
t_true( in_array( $wid, $ids, true ), 'the work shows in the shots history of its year' );
$res = Fotonic_Backup_Loc_REST_API::get_shots( $rest( 'GET', [ 'year' => '1999' ] ) );
t_true( ! in_array( $wid, array_column( $res->get_data()['data'], 'id' ), true ), 'and not in another year' );

// ----- Location detail lists the work -----
$res = Fotonic_Backup_Loc_REST_API::get_location( $rest( 'GET', [ 'id' => $loc ] ) );
t_equals( [ $wid ], array_column( $res->get_data()['works'], 'id' ), 'the location lists the work that uses it' );

// ----- Trash the location: nothing is blocked, the work is flagged -----
$res = Fotonic_Backup_Loc_REST_API::delete_location( $rest( 'DELETE', [ 'id' => $loc ] ) );
t_equals( 200, $res->get_status(), 'deleting a location in use is allowed' );
$w = Fotonic_REST_API::get_work( $rest( 'GET', [ 'id' => $wid ] ) )->get_data();
t_equals( 2, count( $w['storage_locations'] ), 'the row stays on the work' );
t_equals( true, $w['storage_locations'][0]['deleted'], 'and is marked deleted' );
t_equals( true, $w['storage_alert'], 'the work carries the alert' );

$res = Fotonic_REST_API::get_works( $rest( 'GET', [ 'page' => 1, 'per_page' => 100, 'storage_alert' => true ] ) );
t_true( in_array( $wid, array_column( $res->get_data()['data'], 'id' ), true ), 'the alert filter lists the work' );
t_true( $res->get_data()['alert_count'] >= 1, 'and the counter counts it' );
foreach ( $res->get_data()['data'] as $row ) {
    t_true( $row['storage_alert'], 'every row of the filter carries the alert' );
}

// Fixing the row (drop the deleted location, keep the other) clears the alert.
$res = Fotonic_REST_API::update_work( $rest( 'PUT', [ 'id' => $wid ], [ 'storage_locations' => [ [ 'location_id' => $loc2 ] ] ] ) );
t_equals( false, $res->get_data()['storage_alert'], 'saving without the deleted row clears the alert' );
Fotonic_Backup_Loc_CPT::flush_states();
$res = Fotonic_REST_API::get_works( $rest( 'GET', [ 'page' => 1, 'per_page' => 100, 'storage_alert' => true ] ) );
t_true( ! in_array( $wid, array_column( $res->get_data()['data'], 'id' ), true ), 'a fixed work leaves the alert filter' );

// Restoring from the trash clears it too.
wp_untrash_post( $loc );
Fotonic_Backup_Loc_CPT::flush_states();
update_post_meta( $wid, '_ftnc_storage_locations', wp_slash( wp_json_encode( [ [ 'location_id' => $loc ] ] ) ) );
t_equals( false, Fotonic_REST_API::get_work( $rest( 'GET', [ 'id' => $wid ] ) )->get_data()['storage_alert'], 'a location back from the trash is no alert' );

// ----- Declared for backup and protected by the Vault -----
$ds = Fotonic_Backup_Registry::datasets();
t_true( isset( $ds['ftnc_backup_loc'] ), 'the backup registry knows the location type' );
t_true( in_array( '_ftnc_storage_locations', $ds['ftnc_work']['meta_keys'], true ), 'and the work storage meta' );
t_equals( 'json:[].location_id|post', $ds['ftnc_work']['ref_fields']['_ftnc_storage_locations'], 'with its reference remapped on restore' );
t_true( isset( Fotonic_Vault_Fields::groups()['backup_locations'] ), 'the Vault protects the location notes' );
t_true( '' !== Fotonic_Record_Uid::ensure( $loc2 ), 'locations get a uid' );

t_done();
