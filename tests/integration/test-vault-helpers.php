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


// Gender: plain text, 'male'/'female'/'' only, never encrypted, readable with the Vault locked.
$g_in  = [
    [ 'first_name' => 'Luca', 'gender' => 'male', 'is_main' => true ],
    [ 'first_name' => 'Anna', 'gender' => 'female' ],
    [ 'first_name' => 'Old' ],
    [ 'first_name' => 'Bad', 'gender' => 'other' ],
    [ 'first_name' => 'Arr', 'gender' => [ 'male' ] ],
];
$g_enc = Fotonic_REST_API::encrypt_people( $g_in, $key );
t_equals( 'male', $g_enc[0]['gender'], 'gender male stored in plain text with a Vault key' );
t_equals( 'female', $g_enc[1]['gender'], 'gender female stored in plain text with a Vault key' );
t_equals( '', $g_enc[2]['gender'], 'missing gender stored as empty' );
t_equals( '', $g_enc[3]['gender'], 'unknown gender value stored as empty' );
t_equals( '', $g_enc[4]['gender'], 'non-string gender stored as empty' );
t_equals( 'female', Fotonic_REST_API::encrypt_people( $g_in, null )[1]['gender'], 'gender kept without a Vault' );
$g_locked = Fotonic_REST_API::decrypt_people( $g_enc, null );
t_equals( 'male', $g_locked[0]['gender'], 'locked vault: gender still readable' );
t_equals( null, $g_locked[0]['first_name'], 'locked vault: name still hidden' );
$legacy = [ [ 'first_name' => 'x', 'last_name' => '', 'email' => '', 'phone' => '', 'is_main' => true ] ];
t_equals( '', Fotonic_REST_API::decrypt_people( $legacy, $key )[0]['gender'], 'legacy row without gender reads as empty' );
t_equals( 'female', Fotonic_REST_API::decrypt_people( $g_enc, $key )[1]['gender'], 'gender round-trips with the key' );

t_done();
