<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

// Paging and order of the works and customers lists (1.6.6). Every post carries a run tag in its title, the
// searches below use it, so the real data of the site never takes part. Everything is removed at the end.

$GLOBALS['ftnc_run']           = 'pg' . wp_generate_password( 6, false, false );
$run                           = $GLOBALS['ftnc_run'];
$GLOBALS['ftnc_no_vault_hook'] = 'pre_option_' . Fotonic_Vault::OPTION_SETUP;
add_filter( $GLOBALS['ftnc_no_vault_hook'], '__return_zero' );
unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );

register_shutdown_function( function () {
    $tagged = get_posts( [
        'post_type'        => [ 'ftnc_work', 'ftnc_customer' ],
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

$rest = function ( array $params ) {
    $req = new WP_REST_Request( 'GET' );
    foreach ( $params as $k => $v ) {
        $req->set_param( $k, $v );
    }
    return $req;
};

// Five works, two of them on the same day (the tie must not break the paging).
$dates = [ 'A' => '2026-01-10', 'B' => '2026-03-05', 'C' => '2026-03-05', 'D' => '2026-06-20', 'E' => '2026-09-01' ];
$ids   = [];
foreach ( $dates as $letter => $date ) {
    $ids[ $letter ] = t_cleanup( wp_insert_post( wp_slash( [ 'post_type' => 'ftnc_work', 'post_status' => 'publish', 'post_title' => "Work {$letter} {$run}" ] ) ) );
    update_post_meta( $ids[ $letter ], '_ftnc_event_date', $date );
}

$list = function ( array $extra ) use ( $rest, $run ) {
    $res = Fotonic_REST_API::get_works( $rest( array_merge( [ 'search' => $run, 'page' => 1, 'per_page' => 20, 'order' => 'asc' ], $extra ) ) );
    return $res->get_data();
};

$asc = $list( [] );
t_equals( [ $ids['A'], $ids['B'], $ids['C'], $ids['D'], $ids['E'] ], array_column( $asc['data'], 'id' ), 'ascending is still the default order' );

$desc = $list( [ 'order' => 'desc' ] );
t_equals( [ $ids['E'], $ids['D'], $ids['C'], $ids['B'], $ids['A'] ], array_column( $desc['data'], 'id' ), 'order=desc puts the newest event first, ties by id' );

$p1 = $list( [ 'order' => 'desc', 'per_page' => 2, 'page' => 1 ] );
$p2 = $list( [ 'order' => 'desc', 'per_page' => 2, 'page' => 2 ] );
$p3 = $list( [ 'order' => 'desc', 'per_page' => 2, 'page' => 3 ] );
t_equals( 5, $p1['total'], 'the total counts every match' );
t_equals( 3, $p1['pages'], 'and the pages follow per_page' );
$seen = array_merge( array_column( $p1['data'], 'id' ), array_column( $p2['data'], 'id' ), array_column( $p3['data'], 'id' ) );
t_equals( [ $ids['E'], $ids['D'], $ids['C'], $ids['B'], $ids['A'] ], $seen, 'three pages together: every work once, in order' );
t_equals( 1, count( $p3['data'] ), 'the last page holds the rest' );

// Customers: names that tie are split by id, so no customer repeats or goes missing between pages.
$cids = [];
foreach ( [ 1, 2, 3 ] as $n ) {
    $cids[] = t_cleanup( wp_insert_post( wp_slash( [ 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => "Same name {$run}" ] ) ) );
}
$c1 = Fotonic_REST_API::get_customers( $rest( [ 'search' => $run, 'page' => 1, 'per_page' => 2 ] ) )->get_data();
$c2 = Fotonic_REST_API::get_customers( $rest( [ 'search' => $run, 'page' => 2, 'per_page' => 2 ] ) )->get_data();
$got = array_merge( array_column( $c1['data'], 'id' ), array_column( $c2['data'], 'id' ) );
sort( $got );
t_equals( $cids, $got, 'customers with the same name: all three, none twice' );
t_equals( 2, $c1['pages'], 'customer pages' );

t_done();
