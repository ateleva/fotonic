<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

// Payments model 2: the installment rows decide the total. Real site DB: every post created is removed even
// if the script dies, and the payment types option is restored.
// Titles are under the Vault since phase 15 (Pro opens the gate): creating a work with a title needs the key, which a CLI run
// never has. Simulate "Vault never set up" in memory (no DB write), as the other REST tests do.
add_filter( 'pre_option_' . Fotonic_Vault::OPTION_SETUP, '__return_zero' );
unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );
$GLOBALS['ftnc_inserted'] = [];
add_action( 'save_post_ftnc_work', function ( $post_id, $post, $update ) {
    if ( ! $update ) {
        $GLOBALS['ftnc_inserted'][] = (int) $post_id;
    }
}, PHP_INT_MIN, 3 );
register_shutdown_function( function () {
    foreach ( array_unique( array_merge( array_reverse( $GLOBALS['ftnc_cleanup'] ), $GLOBALS['ftnc_inserted'] ) ) as $post_id ) {
        wp_delete_post( $post_id, true );
    }
    t_restore_options();
} );
t_preserve_options( [ 'fotonic_payment_types' ] );
// Pro may be active on this site with VAT on: the free engine is checked without any.
add_filter( 'ftnc_work_vat_for_installments', function () { return [ 'mode' => 'none', 'rate' => 0 ]; }, 99 );

// 1. The row math against the case table shared with the JS mirror.
$cases = json_decode( (string) file_get_contents( __DIR__ . '/../fixtures/payments-cases.json' ), true );
foreach ( $cases as $case ) {
    $types = [];
    foreach ( $case['types'] as $slug => $pct ) {
        $types[] = [ 'slug' => $slug, 'surcharge_pct' => $pct ];
    }
    $calc = Fotonic_Payments::compute( $case['rows'], $types, $case['vat'] );
    $e    = $case['expect'];
    t_money( $e['total'], $calc['total'], $case['name'] . ': total' );
    t_money( $e['taxable'], $calc['taxable'], $case['name'] . ': taxable' );
    t_money( $e['vat'], $calc['vat'], $case['name'] . ': vat' );
    t_money( $e['to_collect'], $calc['to_collect'], $case['name'] . ': to collect' );
    t_equals( array_map( 'floatval', $e['amounts'] ), array_map( 'floatval', array_column( $calc['rows'], 'amount' ) ), $case['name'] . ': row amounts' );
    t_equals( $e['taxables'], array_column( $calc['rows'], 'taxable' ), $case['name'] . ': row taxable flags' );
}

// 2. The check: services total versus the shares, in whole cents.
$svc = [ [ 'service_id' => 1, 'price_override' => 500 ], [ 'service_id' => 2, 'price_override' => 1000 ] ];
t_equals( null, Fotonic_Payments::check( $svc, [ [ 'base' => 500 ], [ 'base' => 1000 ] ], 0.0 ), 'shares equal to the services pass' );
t_equals( 'installments_mismatch', Fotonic_Payments::check( $svc, [ [ 'base' => 500 ], [ 'base' => 900 ] ], 0.0 )['code'], 'short shares are refused' );
t_equals( null, Fotonic_Payments::check( $svc, [ [ 'base' => 500 ], [ 'base' => 1100 ] ], 100.0 ), 'supplier fees marked added raise what must be covered' );
t_equals( null, Fotonic_Payments::check( [ [ 'price_override' => 1000 ] ], [ [ 'base' => 333.33 ], [ 'base' => 333.33 ], [ 'base' => 333.34 ] ], 0.0 ), '333.33 + 333.33 + 333.34 covers 1000' );
t_equals( 'installments_mismatch', Fotonic_Payments::check( [ [ 'price_override' => 1000 ] ], [ [ 'base' => 333.33 ], [ 'base' => 333.33 ], [ 'base' => 333.33 ] ], 0.0 )['code'], 'three times 333.33 does not cover 1000' );
t_equals( 'no_services', Fotonic_Payments::check( [], [ [ 'base' => 10 ] ], 0.0 )['code'], 'no service row' );
t_equals( 'no_installments', Fotonic_Payments::check( $svc, [], 0.0 )['code'], 'no installment row' );
foreach ( [ '', 'abc', -5, 0 ] as $bad ) {
    t_equals( 'invalid_installment', Fotonic_Payments::check( $svc, [ [ 'base' => 1500 ], [ 'base' => $bad ] ], 0.0 )['code'], 'refused share: ' . var_export( $bad, true ) );
}

// 3. Through the REST handlers.
update_option( 'fotonic_payment_types', wp_json_encode( [
    [ 'id' => 1, 'slug' => 'cash', 'label' => 'Cash' ],
    [ 'id' => 2, 'slug' => 'inps', 'label' => 'With surcharge', 'surcharge_pct' => 4 ],
] ) );
function ftnc_t_rest( string $method, array $body, int $id = 0 ): array {
    $req = new WP_REST_Request( 'POST' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( $body ) );
    if ( $id ) {
        $req->set_param( 'id', $id );
    }
    $res = call_user_func( [ 'Fotonic_REST_API', $method ], $req );
    return array_merge( $res->get_data(), [ '_status' => $res->get_status() ] );
}
$s1 = t_cleanup( wp_insert_post( [ 'post_type' => 'ftnc_service', 'post_status' => 'publish', 'post_title' => 'Pay A' ] ) );
$s2 = t_cleanup( wp_insert_post( [ 'post_type' => 'ftnc_service', 'post_status' => 'publish', 'post_title' => 'Pay B' ] ) );
$rows = [ [ 'service_id' => $s1, 'price_override' => 500 ], [ 'service_id' => $s2, 'price_override' => 1000 ] ];

$bad = ftnc_t_rest( 'create_work', [ 'title' => 'Pay work', 'services' => $rows, 'installments' => [ [ 'base' => 500, 'type' => 'cash' ], [ 'base' => 900, 'type' => 'inps' ] ] ] );
t_equals( 400, $bad['_status'], 'create with short shares answers 400' );
t_equals( 'installments_mismatch', $bad['code'] ?? '', 'create error code' );
t_equals( 1500.0, (float) ( $bad['required'] ?? 0 ), 'create error carries required' );
t_equals( 1400.0, (float) ( $bad['planned'] ?? 0 ), 'create error carries planned' );
t_equals( 400, ftnc_t_rest( 'create_work', [ 'title' => 'Pay work', 'services' => [], 'installments' => [ [ 'base' => 10 ] ] ] )['_status'], 'create with no service answers 400' );

$ok = ftnc_t_rest( 'create_work', [ 'title' => 'Pay work', 'services' => $rows, 'installments' => [ [ 'base' => 500, 'type' => 'cash', 'status' => 'paid' ], [ 'base' => 1000, 'type' => 'inps' ] ] ] );
t_equals( 201, $ok['_status'], 'create with matching shares answers 201' );
$id = t_cleanup( (int) $ok['id'] );
t_money( 1540, $ok['total_price'], 'work total is the sum of the rows plus surcharge' );
t_money( 1040, $ok['installments'][1]['amount'], 'row with surcharge pays 1040' );
t_money( 40, $ok['installments'][1]['surcharge'], 'row surcharge is 40' );
t_equals( true, $ok['installments'][1]['taxable'], 'a surcharged row is taxable' );
t_equals( false, $ok['legacy_payments'], 'a work saved now is not legacy' );
t_equals( 2, (int) get_post_meta( $id, '_ftnc_pay_model', true ), 'model marker written' );

// Partial update (no services, no installments): untouched and not checked.
$up = ftnc_t_rest( 'update_work', [ 'title' => 'Pay work renamed' ], $id );
t_equals( 200, $up['_status'], 'partial update passes' );
t_money( 1540, $up['total_price'], 'partial update keeps the total' );
// Services change alone: the stored rows no longer cover them.
$up = ftnc_t_rest( 'update_work', [ 'services' => [ [ 'service_id' => $s1, 'price_override' => 600 ], [ 'service_id' => $s2, 'price_override' => 1000 ] ] ], $id );
t_equals( 400, $up['_status'], 'services change alone is checked against the stored rows' );
t_money( 1540, (float) get_post_meta( $id, '_ftnc_total_price', true ), 'a refused save changes nothing' );

// Legacy work: saved before the model, rows with only an amount.
$old = t_cleanup( wp_insert_post( [ 'post_type' => 'ftnc_work', 'post_status' => 'publish', 'post_title' => 'Old pay work' ] ) );
update_post_meta( $old, '_ftnc_work_services', wp_json_encode( $rows ) );
update_post_meta( $old, '_ftnc_installments', wp_json_encode( [ [ 'title' => 'x', 'amount' => 1200, 'status' => 'unpaid', 'type' => 'cash', 'date' => '' ] ] ) );
update_post_meta( $old, '_ftnc_total_price', 1500 );
$req = new WP_REST_Request( 'GET' );
$req->set_param( 'id', $old );
$legacy = Fotonic_REST_API::get_work( $req )->get_data();
t_equals( true, $legacy['legacy_payments'], 'a work without the marker is legacy' );
t_money( 1200, $legacy['installments'][0]['base'], 'legacy row shows its amount as its share' );
t_equals( 400, ftnc_t_rest( 'update_work', [ 'event_date' => '2098-01-01', 'services' => $rows, 'installments' => [ [ 'base' => 1200, 'type' => 'cash' ] ] ], $old )['_status'], 'legacy first save must fix the rows, even for a date change' );
$fixed = ftnc_t_rest( 'update_work', [ 'event_date' => '2098-01-01', 'services' => $rows, 'installments' => [ [ 'base' => 1500, 'type' => 'cash' ] ] ], $old );
t_equals( 200, $fixed['_status'], 'legacy work saves once the rows add up' );
t_equals( false, $fixed['legacy_payments'], 'and is no longer legacy' );

// Payment type route accepts and returns the surcharge percentage.
$tr = new WP_REST_Request( 'POST' );
$tr->set_param( 'label', 'Surcharge test type' );
$tr->set_param( 'surcharge_pct', 4 );
$created = Fotonic_REST_API::create_payment_type( $tr )->get_data();
t_money( 4, $created['surcharge_pct'] ?? -1, 'payment type stores surcharge_pct' );
$list = Fotonic_REST_API::get_payment_types( new WP_REST_Request( 'GET' ) )->get_data();
t_money( 0, $list[0]['surcharge_pct'], 'a type without it reads 0' );

t_done();
