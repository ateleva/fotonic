<?php
// Unit test for Fotonic_Field_Crypt: the authenticated, prefixed format of the Vault's free-text
// fields. No WordPress, no database.
require __DIR__ . '/../lib/assert.php';
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../includes/class-field-crypt.php';

$k  = random_bytes( 32 );
$k2 = random_bytes( 32 );

// Round trip, accents, quotes, backslashes, HTML, newlines, a long text.
foreach ( array( 'Note semplice', "Perch\u{e8} \"s\u{ec}\" 'no' \\ <p>HTML &amp; <b>bold</b></p>\nsecond line", str_repeat( 'x', 100000 ), '0', 'f2:not ours' ) as $i => $plain ) {
	$enc = Fotonic_Field_Crypt::encrypt( $plain, $k );
	t_true( is_string( $enc ) && 0 === strpos( $enc, 'f1:' ), "case $i: encrypted with the prefix" );
	t_equals( $plain, Fotonic_Field_Crypt::decrypt( $enc, $k ), "case $i: round trip" );
	t_equals( $plain, Fotonic_Field_Crypt::read( $enc, $k ), "case $i: read() with the key" );
	t_equals( null, Fotonic_Field_Crypt::read( $enc, null ), "case $i: read() without a key is null, never ''" );
	t_equals( null, Fotonic_Field_Crypt::read( $enc, $k2 ), "case $i: read() with the wrong key is null" );
}

// Not deterministic (random IV), and nothing of the plain text is visible.
$a = Fotonic_Field_Crypt::encrypt( 'same text', $k );
$b = Fotonic_Field_Crypt::encrypt( 'same text', $k );
t_true( $a !== $b, 'two encryptions of the same text differ' );
t_true( false === strpos( $a, 'same' ), 'the stored value does not contain the text' );

// Empty text stays empty and is not "encrypted".
t_equals( '', Fotonic_Field_Crypt::encrypt( '', $k ), 'empty stays empty' );
t_equals( '', Fotonic_Field_Crypt::read( '', $k ), 'empty reads as empty' );
t_equals( '', Fotonic_Field_Crypt::read( '', null ), 'empty reads as empty without a key' );

// Tamper: flipping one bit anywhere makes decryption fail (authenticated).
$raw = base64_decode( substr( $a, 3 ) );
foreach ( array( 0, 14, 30 ) as $pos ) {
	$t = $raw; $t[ $pos ] = chr( ord( $t[ $pos ] ) ^ 1 );
	t_equals( false, Fotonic_Field_Crypt::decrypt( 'f1:' . base64_encode( $t ), $k ), "a changed byte at $pos is refused" );
}
t_equals( false, Fotonic_Field_Crypt::decrypt( 'f1:AAAA', $k ), 'a too short value is refused' );
t_equals( false, Fotonic_Field_Crypt::decrypt( 'f1:not base64 !!', $k ), 'bad base64 is refused' );
t_equals( false, Fotonic_Field_Crypt::decrypt( 'plain', $k ), 'a plain value is not ciphertext' );
t_equals( false, Fotonic_Field_Crypt::encrypt( 'x', 'short' ), 'a key of the wrong length is refused' );

// The field key is not the Master Key, and a file key (another layer) does not open a field.
t_true( Fotonic_Field_Crypt::field_key( $k ) !== $k, 'the field key is a sub-key' );
t_equals( 32, strlen( Fotonic_Field_Crypt::field_key( $k ) ), 'the field key is 32 bytes' );

// Plain text that looks like ciphertext, stored without a key, is escaped and read back whole.
foreach ( array( 'f1:looks like ours', 'f0:already escaped', 'f1:', 'ordinary text' ) as $plain ) {
	$stored = Fotonic_Field_Crypt::store( $plain, null );
	t_equals( $plain, Fotonic_Field_Crypt::read( $stored, null ), "no key, '$plain': round trip" );
	t_equals( $plain, Fotonic_Field_Crypt::read( $stored, $k ), "no key, '$plain': also readable with a key" );
}
t_equals( 'ordinary text', Fotonic_Field_Crypt::store( 'ordinary text', null ), 'ordinary text is stored as it is without a key' );
t_equals( 'f0:f1:x', Fotonic_Field_Crypt::store( 'f1:x', null ), 'an f1: text is escaped with f0:' );
t_true( ! Fotonic_Field_Crypt::is_encrypted( Fotonic_Field_Crypt::store( 'f1:x', null ) ), 'the escaped text is not seen as ciphertext' );

// With a key store() encrypts, also a text that starts with the prefix.
$s = Fotonic_Field_Crypt::store( 'f1:abc', $k );
t_true( Fotonic_Field_Crypt::is_encrypted( $s ), 'store() with a key encrypts' );
t_equals( 'f1:abc', Fotonic_Field_Crypt::read( $s, $k ), 'an f1: text survives encryption' );
t_equals( false, Fotonic_Field_Crypt::store( 'x', 'short' ), 'store() with a bad key fails instead of storing plain' );

t_done();
