<?php
// The offline tool opens the 'f1:' titles of customers, works and quotes in data.json (phase 15), also across the 1 MB blocks it
// reads the file in. No WordPress, no archive: the function is lifted out of the tool (which runs itself when loaded).
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../lib/assert.php';
require __DIR__ . '/../../includes/class-field-crypt.php';

$src = file_get_contents( __DIR__ . '/../../tools/eleva-backup-decrypt.php' );
define( 'ELEVA_BD_GCM', 'aes-256-gcm' );
foreach ( array( 'eleva_bd_open_titles', 'eleva_bd_open_field' ) as $fn ) {
	preg_match( '/function ' . $fn . '\(.*?\n}\n/s', $src, $m );
	eval( $m[0] );
}

$mk  = random_bytes( 32 );
$mk2 = random_bytes( 32 );
$titles = array( "Nicol\u{f2} d'Aosta", 'Rossi \\ "Bianchi"', 'Via/Dante & co', "\u{4e2d}\u{6587}", 'x' );
$expect = array();
$records = array();
$all = array();
for ( $i = 0; $i < 400; $i++ ) {
	$t = $titles[ $i % count( $titles ) ] . " $i";
	$all[] = array( 'ID' => $i, 'post_title' => Fotonic_Field_Crypt::encrypt( $t, $mk ), 'filler' => str_repeat( 'abcdefghij', 800 ), 'meta' => array( '_ftnc_quote_customer_title' => Fotonic_Field_Crypt::encrypt( "Cliente $i", $mk ), '_ftnc_notes' => Fotonic_Field_Crypt::encrypt( "Nota $i", $mk ) ) );
	$expect[ $i ] = array( $t, "Cliente $i" );
}
// One title no key opens (another Vault) and a plain one.
$all[] = array( 'ID' => 900, 'post_title' => Fotonic_Field_Crypt::encrypt( 'Altro', $mk2 ) );
$all[] = array( 'ID' => 901, 'post_title' => 'In chiaro' );
$json = json_encode( array( 'datasets' => array( 'ftnc_work' => $all ) ) );
t_true( strlen( $json ) > 3 * 1048576, 'the data.json is bigger than three blocks (' . strlen( $json ) . ' bytes)' );
$path = sys_get_temp_dir() . '/ftnc-titles-' . bin2hex( random_bytes( 4 ) ) . '.json';
file_put_contents( $path, $json );

$n = eleva_bd_open_titles( $path, $mk );
t_equals( 800, $n, '400 titles and 400 new customer names opened' );
$out = json_decode( (string) file_get_contents( $path ), true );
t_true( is_array( $out ), 'the file is still valid JSON' );
$bad = 0;
foreach ( $out['datasets']['ftnc_work'] as $r ) {
	if ( $r['ID'] < 400 && array( $r['post_title'], $r['meta']['_ftnc_quote_customer_title'] ) !== $expect[ $r['ID'] ] ) {
		$bad++;
	}
}
t_equals( 0, $bad, 'every title is the plain text, across every block boundary' );
t_true( 0 === strncmp( $out['datasets']['ftnc_work'][5]['meta']['_ftnc_notes'], 'f1:', 3 ), 'notes stay encrypted as stored' );
$last = array_slice( $out['datasets']['ftnc_work'], -2 );
t_equals( true, Fotonic_Field_Crypt::is_encrypted( $last[0]['post_title'] ), 'a title of another Vault stays as stored' );
t_equals( 'In chiaro', $last[1]['post_title'], 'a plain title is untouched' );
t_equals( 0, eleva_bd_open_titles( $path, random_bytes( 32 ) ), 'a run with a key that fits nothing opens nothing and changes nothing' );
t_equals( 1, eleva_bd_open_titles( $path, $mk2 ), 'the title of the other Vault opens with its own key' );
@unlink( $path );

t_done();
