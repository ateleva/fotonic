<?php
// Unit test for Fotonic_File_Crypt (the Vault layer for stored files). No WordPress, no database.
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );
if ( ! function_exists( 'wp_delete_file' ) ) {
	function wp_delete_file( $f ) { if ( is_file( $f ) ) { unlink( $f ); } }
}
require __DIR__ . '/../../includes/class-file-crypt.php';

$mk    = random_bytes( 32 );
$other = random_bytes( 32 );
$plain = "%PDF-1.7\n" . random_bytes( 5000 ) . "\n%%EOF";

$blob = Fotonic_File_Crypt::encrypt( $plain, $mk );
t_true( is_string( $blob ) && 0 === strncmp( $blob, 'FTNCFIL1', 8 ), 'starts with the magic' );
t_equals( strlen( $plain ) + 36, strlen( $blob ), 'size is the plaintext plus 36 bytes of header' );
t_true( false === strpos( $blob, '%PDF' ), 'no plaintext inside' );
t_equals( $plain, Fotonic_File_Crypt::decrypt( $blob, $mk ), 'round trip' );
t_equals( false, Fotonic_File_Crypt::decrypt( $blob, $other ), 'wrong key is refused' );
t_true( Fotonic_File_Crypt::encrypt( $plain, $mk ) !== $blob, 'a new iv every time' );

$flip = function ( $offset ) use ( $blob ) { $b = $blob; $b[ $offset ] = chr( ord( $b[ $offset ] ) ^ 1 ); return $b; };
t_equals( false, Fotonic_File_Crypt::decrypt( $flip( 10 ), $mk ), 'altered iv is refused' );
t_equals( false, Fotonic_File_Crypt::decrypt( $flip( 25 ), $mk ), 'altered tag is refused' );
t_equals( false, Fotonic_File_Crypt::decrypt( $flip( 100 ), $mk ), 'altered ciphertext is refused' );
t_equals( false, Fotonic_File_Crypt::decrypt( 'FTNCFIL2' . substr( $blob, 8 ), $mk ), 'another magic is refused' );
t_equals( false, Fotonic_File_Crypt::decrypt( substr( $blob, 0, 30 ), $mk ), 'truncated blob is refused' );
t_equals( false, Fotonic_File_Crypt::decrypt( $plain, $mk ), 'a plain file is not ours' );
t_equals( false, Fotonic_File_Crypt::encrypt( $plain, 'short' ), 'a key of the wrong size is refused' );
t_true( Fotonic_File_Crypt::file_key( $mk ) !== $mk && 32 === strlen( Fotonic_File_Crypt::file_key( $mk ) ), 'the file key is derived, never the master key' );
t_equals( Fotonic_File_Crypt::file_key( $mk ), Fotonic_File_Crypt::file_key( $mk ), 'the derivation is stable' );
t_equals( '', Fotonic_File_Crypt::decrypt( Fotonic_File_Crypt::encrypt( '', $mk ), $mk ) === '' ? '' : 'x', 'an empty file round-trips' );

$dir = sys_get_temp_dir() . '/ftnc-fc-' . bin2hex( random_bytes( 4 ) );
mkdir( $dir );
$src = $dir . '/a.pdf';
file_put_contents( $src, $plain );
t_equals( false, Fotonic_File_Crypt::is_encrypted( $src ), 'a plain file is not encrypted' );
t_equals( true, Fotonic_File_Crypt::encrypt_file( $src, $src . '.enc', $mk ), 'encrypt_file works' );
t_equals( true, Fotonic_File_Crypt::is_encrypted( $src . '.enc' ), 'the result starts with the magic' );
t_equals( $plain, Fotonic_File_Crypt::decrypt_file( $src . '.enc', $mk ), 'decrypt_file returns the original bytes' );
t_equals( false, file_exists( $src . '.enc.part' ), 'no temp file is left behind' );
t_equals( false, Fotonic_File_Crypt::encrypt_file( $dir . '/missing.pdf', $dir . '/m.enc', $mk ), 'a missing source fails' );
t_equals( false, Fotonic_File_Crypt::is_encrypted( $dir . '/missing.pdf' ), 'a missing file is not encrypted' );
t_equals( false, Fotonic_File_Crypt::decrypt_file( $dir . '/missing.pdf', $mk ), 'a missing file does not decrypt' );

t_equals( 'q-2026.pdf', Fotonic_File_Crypt::plain_name( 'q-2026.pdf.enc' ), 'plain_name strips the suffix' );
t_equals( 'q-2026.pdf', Fotonic_File_Crypt::plain_name( 'q-2026.pdf' ), 'plain_name leaves other names alone' );
t_equals( '.enc', Fotonic_File_Crypt::plain_name( '.enc' ), 'a name that is only the suffix is kept' );

array_map( 'unlink', glob( $dir . '/*' ) );
rmdir( $dir );
t_done();
