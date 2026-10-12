<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

$customer = t_cleanup( wp_insert_post( [ 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => 'Filter Test' ] ) );
$req      = new WP_REST_Request( 'GET' );
$req->set_param( 'id', $customer );

t_equals( true, Fotonic_REST_API::can_delete_customer( $req )->get_data()['can_delete'], 'deletable with no blockers' );

add_filter( 'ftnc_can_delete_customer', function ( $reason, $id ) use ( $customer ) {
    return $id === $customer ? 'Linked to 1 quote: Test' : $reason;
}, 10, 2 );
$res = Fotonic_REST_API::can_delete_customer( $req )->get_data();
t_equals( false, $res['can_delete'], 'filter can block deletion' );
t_equals( 'Linked to 1 quote: Test', $res['reason'], 'filter reason surfaced' );

$work = t_cleanup( wp_insert_post( [ 'post_type' => 'ftnc_work', 'post_status' => 'publish', 'post_title' => 'Filter Work' ] ) );
add_filter( 'ftnc_format_work', function ( $data ) {
    $data['probe'] = 'ok';
    return $data;
} );
$wreq = new WP_REST_Request( 'GET' );
$wreq->set_param( 'id', $work );
t_equals( 'ok', Fotonic_REST_API::get_work( $wreq )->get_data()['probe'] ?? null, 'ftnc_format_work filter applied' );

t_done();
