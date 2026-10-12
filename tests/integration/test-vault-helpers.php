<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

$key = random_bytes( 32 );
$in  = [
    [ 'first_name' => 'Łukasz', 'last_name' => 'Dvořák', 'email' => 'luca@example.com', 'phone' => '+39 333 1234567', 'nationality' => 'IT', 'instagram_username' => '@luca', 'address' => 'Via Roma 1', 'tin' => 'RSSLCU80A01H501U', 'is_main' => false ],
    [ 'first_name' => 'Anna', 'last_name' => '', 'email' => '', 'phone' => '', 'is_main' => true ],
];

$enc = Fotonic_REST_API::encrypt_people( $in, $key );
t_true( 'Łukasz' !== $enc[0]['first_name'], 'first_name is encrypted' );
t_equals( 0, strpos( $enc[0]['email'], 'v1d:' ), 'email uses deterministic encryption' );
t_equals( $enc[0]['email'], Fotonic_REST_API::encrypt_people( $in, $key )[0]['email'], 'deterministic email is stable (searchable)' );
t_equals( '', $enc[1]['last_name'], 'empty fields stay empty, never encrypted' );
t_equals( true, $enc[1]['is_main'], 'explicit main contact kept' );
t_equals( false, $enc[0]['is_main'], 'only one main contact' );

$dec = Fotonic_REST_API::decrypt_people( $enc, $key );
t_equals( 'Łukasz', $dec[0]['first_name'], 'first_name round-trips (UTF-8)' );
t_equals( 'luca@example.com', $dec[0]['email'], 'email round-trips' );
t_equals( 'RSSLCU80A01H501U', $dec[0]['tin'], 'tin round-trips' );

$locked = Fotonic_REST_API::decrypt_people( $enc, null );
t_equals( null, $locked[0]['first_name'], 'locked vault: encrypted field is null' );
t_equals( '', $locked[1]['last_name'], 'locked vault: plain empty field untouched' );

$plain = Fotonic_REST_API::encrypt_people( $in, null );
t_equals( 'Łukasz', $plain[0]['first_name'], 'no vault: sanitized plaintext' );

$no_main = Fotonic_REST_API::encrypt_people( [ [ 'first_name' => 'A' ], [ 'first_name' => 'B' ] ], null );
t_equals( true, $no_main[0]['is_main'], 'first person becomes main when none is flagged' );

$addr = Fotonic_REST_API::encrypt_addresses( [ [ 'label' => 'Chiesa', 'street' => 'Piazza Duomo 1' ], 'junk' ], $key );
t_equals( 1, count( $addr ), 'non-array address rows dropped' );
t_equals( 'Chiesa', $addr[0]['label'], 'label stays plaintext' );
t_true( 'Piazza Duomo 1' !== $addr[0]['street'], 'street is encrypted' );
t_equals( 'Piazza Duomo 1', Fotonic_REST_API::decrypt_addresses( $addr, $key )[0]['street'], 'street round-trips' );
t_equals( null, Fotonic_REST_API::decrypt_addresses( $addr, null )[0]['street'], 'locked vault: street is null' );

t_done();
