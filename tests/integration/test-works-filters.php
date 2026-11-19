<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

// Year and owner filters of the works list, and the list of years (1.7.x). Every post carries a run tag in its
// title and the searches use it, so the real data of the site never takes part. Everything is removed at the end.

$GLOBALS['ftnc_run']           = 'wf' . wp_generate_password( 6, false, false );
$run                           = $GLOBALS['ftnc_run'];
$GLOBALS['ftnc_no_vault_hook'] = 'pre_option_' . Fotonic_Vault::OPTION_SETUP;
add_filter( $GLOBALS['ftnc_no_vault_hook'], '__return_zero' );
unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );

register_shutdown_function( function () {
    $tagged = get_posts( [
        'post_type'        => 'ftnc_work',
        'post_status'      => [ 'publish', 'trash', 'draft' ],
        's'                => $GLOBALS['ftnc_run'],
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'suppress_filters' => true,
    ] );
    foreach ( array_unique( array_merge( array_reverse( $GLOBALS['ftnc_cleanup'] ), $tagged ) ) as $post_id ) {
        wp_delete_post( $post_id, true );
    }
} );

$make = function ( string $label, string $date, array $meta = [] ) use ( $run ) {
    $id = t_cleanup( wp_insert_post( wp_slash( [ 'post_type' => 'ftnc_work', 'post_status' => 'publish', 'post_title' => "{$label} {$run}" ] ) ) );
    if ( '' !== $date ) {
        update_post_meta( $id, '_ftnc_event_date', $date );
    }
    foreach ( $meta as $k => $v ) {
        update_post_meta( $id, $k, $v );
    }
    return $id;
};

// 2031 and 2030 are far from any real work, so the years list can be checked without the site's own data.
$w31      = $make( 'Admin 2031', '2031-05-10', [ '_ftnc_owner_type' => 'admin', '_ftnc_owner_id' => 1 ] );
$w30      = $make( 'NoOwner 2030', '2030-03-01' );
$wcollab  = $make( 'Collab 2031', '2031-07-01', [ '_ftnc_owner_type' => 'collaborator', '_ftnc_owner_id' => 987654 ] );
$wnodate  = $make( 'NoDate', '' );

$list = function ( array $extra ) use ( $run ) {
    $req = new WP_REST_Request( 'GET' );
    foreach ( array_merge( [ 'search' => $run, 'page' => 1, 'per_page' => 50, 'order' => 'asc' ], $extra ) as $k => $v ) {
        $req->set_param( $k, $v );
    }
    return Fotonic_REST_API::get_works( $req )->get_data();
};
$ids = function ( array $data ) {
    $out = array_column( $data['data'], 'id' );
    sort( $out );
    return $out;
};
$sorted = function ( array $a ) {
    sort( $a );
    return $a;
};

// The list is ordered by the event date (a meta join), so a work with no date never shows in /works at all: that is how it
// always was, and Dashboard and Calendar rely on that query. The year check below still pins the dateless case.
t_equals( $sorted( [ $w31, $w30, $wcollab ] ), $ids( $list( [] ) ), 'no filter: every work that has a date' );
t_equals( $sorted( [ $w31, $wcollab ] ), $ids( $list( [ 'year' => '2031' ] ) ), 'year 2031: only the works of that year' );
t_equals( [ $w30 ], $ids( $list( [ 'year' => '2030' ] ) ), 'year 2030' );
t_equals( [], $ids( $list( [ 'year' => '2029' ] ) ), 'a year with no work is empty' );
t_true( ! in_array( $wnodate, $ids( $list( [ 'year' => '2031' ] ) ), true ), 'a work without a date belongs to no year' );

t_equals( $sorted( [ $w31, $w30 ] ), $ids( $list( [ 'owner' => 'admin' ] ) ), 'owner admin: explicit admin and works with no owner saved' );
t_equals( [ $wcollab ], $ids( $list( [ 'owner' => 'collaborator:987654' ] ) ), 'owner collaborator: that id only' );
t_equals( [], $ids( $list( [ 'owner' => 'collaborator:111111' ] ) ), 'a collaborator that owns nothing (or is gone): empty, no error' );
t_equals( [ $w31 ], $ids( $list( [ 'owner' => 'admin', 'year' => '2031' ] ) ), 'owner and year together' );
$cust = t_cleanup( wp_insert_post( wp_slash( [ 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => "Cust {$run}" ] ) ) );
update_post_meta( $w31, '_ftnc_customer_id', $cust );
t_equals( [ $w31 ], $ids( $list( [ 'owner' => 'admin', 'year' => '2031', 'customer_id' => $cust ] ) ), 'owner, year and customer together' );

// The search also finds a work by its customer (the customer's title), not only by the work's own title.
$cust2 = t_cleanup( wp_insert_post( wp_slash( [ 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => "Zeffiro e Ondina {$run}" ] ) ) );
update_post_meta( $w30, '_ftnc_customer_id', $cust2 );
t_equals( [ $w30 ], $ids( $list( [ 'search' => 'Zeffiro' ] ) ), 'search by the customer name finds the work, whatever its own title' );
t_equals( [ $w30 ], $ids( $list( [ 'search' => 'ondina zeffiro' ] ) ), 'every word, any order' );
t_equals( [], $ids( $list( [ 'search' => 'Zeffiro nonexistentword' ] ) ), 'a word the customer does not have finds nothing' );

$years = Fotonic_REST_API::get_works_years( new WP_REST_Request( 'GET' ) )->get_data()['years'];
t_true( in_array( '2031', $years, true ) && in_array( '2030', $years, true ), 'years list holds the years of the works' );
t_true( array_search( '2031', $years, true ) < array_search( '2030', $years, true ), 'years list is newest first' );
t_equals( array_values( array_unique( $years ) ), $years, 'every year once' );
t_true( ! in_array( '', $years, true ) && ! in_array( '0000', $years, true ), 'no empty year' );

t_done();
