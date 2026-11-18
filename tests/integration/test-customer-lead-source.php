<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

// Lead source (and its detail text) on the customer: create, update, read. The Vault is switched off for the run
// (filter, no real option touched). Every customer carries a run tag in its title and is removed at the end.

$GLOBALS['ftnc_run']           = 'ls' . wp_generate_password( 6, false, false );
$run                           = $GLOBALS['ftnc_run'];
$GLOBALS['ftnc_no_vault_hook'] = 'pre_option_' . Fotonic_Vault::OPTION_SETUP;
add_filter( $GLOBALS['ftnc_no_vault_hook'], '__return_zero' );
unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );

register_shutdown_function( function () {
    $tagged = get_posts( [ 'post_type' => 'ftnc_customer', 'post_status' => [ 'publish', 'trash', 'draft' ], 's' => $GLOBALS['ftnc_run'], 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ] );
    foreach ( array_unique( array_merge( array_reverse( $GLOBALS['ftnc_cleanup'] ), $tagged ) ) as $post_id ) {
        wp_delete_post( $post_id, true );
    }
} );

$call = function ( string $method, array $body, int $id = 0 ) {
    $req = new WP_REST_Request( $id ? 'PUT' : 'POST' );
    $req->set_header( 'content-type', 'application/json' );
    $req->set_body( wp_json_encode( $body ) );
    if ( $id ) {
        $req->set_param( 'id', $id );
    }
    return Fotonic_REST_API::$method( $req );
};
$person = [ 'first_name' => 'Anna', 'last_name' => '', 'email' => '', 'phone' => '', 'nationality' => '', 'instagram_username' => '', 'address' => '', 'tin' => '', 'gender' => '', 'is_main' => true ];

// Create with a detail source.
$res = $call( 'create_customer', [ 'title' => "Rossi {$run}", 'people' => [ $person ], 'lead_source' => 'word_of_mouth', 'lead_source_detail' => 'Mario' ] );
t_equals( 201, $res->get_status(), 'create: 201' );
$cid = t_cleanup( (int) $res->get_data()['id'] );
$d   = $res->get_data();
t_equals( 'word_of_mouth', $d['lead_source'], 'create: slug in the payload' );
t_equals( Fotonic_Lead_Sources::label( 'word_of_mouth' ), $d['lead_source_label'], 'create: label in the payload' );
t_equals( 'Mario', $d['lead_source_detail'], 'create: detail in the payload' );
t_equals( 'word_of_mouth', get_post_meta( $cid, '_ftnc_lead_source', true ), 'create: slug stored' );

// Update with ONLY the lead source: the people stay.
$res = $call( 'update_customer', [ 'lead_source' => 'instagram' ], $cid );
$d   = $res->get_data();
t_equals( 'instagram', $d['lead_source'], 'update lead_source only: new slug' );
t_equals( '', $d['lead_source_detail'], 'update to a source without detail: detail cleared' );
t_equals( '', (string) get_post_meta( $cid, '_ftnc_lead_source_detail', true ), 'update to a source without detail: meta removed' );
t_equals( 'Anna', $d['people'][0]['first_name'] ?? null, 'update lead_source only: people kept' );

// A detail sent for a source that takes none is dropped.
$res = $call( 'update_customer', [ 'lead_source' => 'instagram', 'lead_source_detail' => 'ignored' ], $cid );
t_equals( '', $res->get_data()['lead_source_detail'], 'detail for a source without detail is dropped' );

// Back to a detail source, then a body without lead_source leaves everything.
$call( 'update_customer', [ 'lead_source' => 'other', 'lead_source_detail' => str_repeat( 'x', 200 ) ], $cid );
t_equals( 120, mb_strlen( (string) $call( 'update_customer', [ 'title' => "Rossi {$run}" ], $cid )->get_data()['lead_source_detail'] ), 'body without lead_source: detail kept, cut to 120' );
t_equals( 'other', $call( 'update_customer', [ 'title' => "Rossi {$run}" ], $cid )->get_data()['lead_source'], 'body without lead_source: slug kept' );

// Invalid values and clearing.
foreach ( [ 'bogus', 'Instagram', 42, [ 'x' ], null, '' ] as $bad ) {
    $call( 'update_customer', [ 'lead_source' => 'facebook' ], $cid );
    $d = $call( 'update_customer', [ 'lead_source' => $bad ], $cid )->get_data();
    t_equals( '', $d['lead_source'], 'invalid or empty clears the source: ' . wp_json_encode( $bad ) );
    t_equals( '', (string) get_post_meta( $cid, '_ftnc_lead_source', true ), 'meta removed: ' . wp_json_encode( $bad ) );
}

// An old customer (no meta at all) reads as empty.
$old = t_cleanup( wp_insert_post( wp_slash( [ 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => "Old {$run}" ] ) ) );
$req = new WP_REST_Request( 'GET' );
$req->set_param( 'id', $old );
$d = Fotonic_REST_API::get_customer( $req )->get_data();
t_equals( '', $d['lead_source'], 'old customer: empty slug' );
t_equals( '', $d['lead_source_label'], 'old customer: empty label' );
t_equals( '', $d['lead_source_detail'], 'old customer: empty detail' );

// The shared helper (used by Pro's conversion).
Fotonic_REST_API::save_customer_lead_source( $old, 'supplier_referral', 'Villa Rossi', null );
t_equals( 'supplier_referral', get_post_meta( $old, '_ftnc_lead_source', true ), 'helper: slug' );
t_equals( 'Villa Rossi', Fotonic_REST_API::field_for_screen( (string) get_post_meta( $old, '_ftnc_lead_source_detail', true ), null ), 'helper: detail stored and readable' );
Fotonic_REST_API::save_customer_lead_source( $old, '', '', null );
t_equals( '', (string) get_post_meta( $old, '_ftnc_lead_source', true ) . (string) get_post_meta( $old, '_ftnc_lead_source_detail', true ), 'helper: empty slug removes both' );

// A detail the key cannot read reads as null and a save that sends null keeps the stored text, byte for byte.
$key         = random_bytes( 32 );
$unreadable  = Fotonic_Field_Crypt::store( 'Secret', $key );
update_post_meta( $cid, '_ftnc_lead_source', 'word_of_mouth' );
update_post_meta( $cid, '_ftnc_lead_source_detail', wp_slash( $unreadable ) );
$d = $call( 'update_customer', [ 'title' => "Rossi {$run}" ], $cid )->get_data();
t_equals( null, $d['lead_source_detail'], 'unreadable detail: null in the payload, never ciphertext' );
$call( 'update_customer', [ 'lead_source' => 'word_of_mouth', 'lead_source_detail' => null ], $cid );
t_equals( $unreadable, get_post_meta( $cid, '_ftnc_lead_source_detail', true ), 'a null detail keeps the stored text untouched' );
t_equals( 'word_of_mouth', get_post_meta( $cid, '_ftnc_lead_source', true ), 'a null detail still saves the slug' );
delete_post_meta( $cid, '_ftnc_lead_source_detail' );

// Vault set up but locked (no cookie): a detail in clear is refused, a slug alone is fine.
remove_filter( $GLOBALS['ftnc_no_vault_hook'], '__return_zero' );
add_filter( $GLOBALS['ftnc_no_vault_hook'], '__return_true' );
$locked = $call( 'update_customer', [ 'lead_source' => 'word_of_mouth', 'lead_source_detail' => 'Mario' ], $cid );
t_equals( 423, $locked->get_status(), 'locked Vault: a non-empty detail answers 423' );
t_equals( '', (string) get_post_meta( $cid, '_ftnc_lead_source_detail', true ), 'locked Vault: nothing written for the detail' );
$slug_only = $call( 'update_customer', [ 'lead_source' => 'instagram' ], $cid );
t_equals( 200, $slug_only->get_status(), 'locked Vault: a slug alone is saved' );
t_equals( 'instagram', get_post_meta( $cid, '_ftnc_lead_source', true ), 'locked Vault: slug stored' );
remove_filter( $GLOBALS['ftnc_no_vault_hook'], '__return_true' );
add_filter( $GLOBALS['ftnc_no_vault_hook'], '__return_zero' );

t_done();
