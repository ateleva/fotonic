<?php
// Unit test for Fotonic_Restore_Splitter. No WordPress, no database: only temp files.
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );
function __( $s, $d = null ) { return $s; }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

require __DIR__ . '/../../includes/class-restore-splitter.php';

$S      = 'Fotonic_Restore_Splitter';
$tmp    = sys_get_temp_dir() . '/ftnc-split-' . bin2hex( random_bytes( 6 ) );
mkdir( $tmp );
register_shutdown_function( function () use ( $tmp ) {
	foreach ( glob( $tmp . '/*' ) as $f ) { @unlink( $f ); }
	@rmdir( $tmp );
} );
$encode = function ( $raw ) { return base64_encode( $raw ); };

function run_split( $S, $tmp, $json, $encode, $block = 262144 ) {
	foreach ( glob( $tmp . '/*' ) as $f ) { unlink( $f ); }
	$S::$read_size = $block;
	file_put_contents( $tmp . '/in.json', $json );
	return $S::split( $tmp . '/in.json', $tmp, $encode );
}
function read_all( $tmp, $file ) {
	$out = array();
	foreach ( file( $tmp . '/' . $file . '.jsonl', FILE_IGNORE_NEW_LINES ) as $line ) {
		$out[] = json_decode( base64_decode( $line ), true );
	}
	return $out;
}

$nasty = "br}ace] \"quote\" back\\slash [x] {y} \u{00e8}\u{1F600} \n line";
$doc   = array(
	'format'       => 1,
	'generated_at' => '2026-11-05T10:00:00+00:00',
	'datasets'     => array(
		'customer' => array(
			array( 'ID' => 1, 'post_title' => $nasty, 'meta' => array( '_a' => '{"k":["v"]}' ), 'terms' => array() ),
			array( 'ID' => 2, 'post_title' => 'Second', 'meta' => array(), 'terms' => array() ),
		),
		'work'     => array( array( 'ID' => 10, 'meta' => array( 'x' => $nasty ) ) ),
		'empty'    => array(),
	),
	'terms'        => array( 'ftnc_work_payment_status' => array( array( 'term_id' => 3, 'slug' => 'paid' ) ) ),
	'options'      => array( 'fotonic_payment_types' => array( array( 'slug' => 'cash', 'label' => $nasty ) ) ),
	'attachments'  => array( array( 'id' => 77, 'sha256' => str_repeat( 'a', 64 ) ) ),
);
$json = json_encode( $doc );

foreach ( array( 262144, 7, 1 ) as $block ) {
	echo "basic layout, read block {$block} bytes\n";
	$r = run_split( $S, $tmp, $json, $encode, $block );
	t_true( is_array( $r ), 'splits without error' );
	t_equals( 1, $r['format'], 'format number' );
	t_equals( '2026-11-05T10:00:00+00:00', $r['generated_at'], 'generated_at' );
	t_equals( $doc['options'], $r['options'], 'options object kept as is' );
	$byid = array();
	foreach ( $r['collections'] as $c ) { $byid[ $c['id'] ] = $c; }
	t_equals( array( 'datasets:customer', 'datasets:work', 'datasets:empty', 'terms:ftnc_work_payment_status', 'attachments' ), array_keys( $byid ), 'collection ids, in order' );
	t_equals( 2, $byid['datasets:customer']['count'], 'two customers' );
	t_equals( 0, $byid['datasets:empty']['count'], 'an empty dataset is still listed' );
	t_equals( 'terms', $byid['terms:ftnc_work_payment_status']['kind'], 'kind of a taxonomy' );
	t_equals( 'ftnc_work_payment_status', $byid['terms:ftnc_work_payment_status']['key'], 'key of a taxonomy' );
	t_equals( $doc['datasets']['customer'], read_all( $tmp, $byid['datasets:customer']['file'] ), 'customer records round-trip, nasty text included' );
	t_equals( $doc['datasets']['work'], read_all( $tmp, $byid['datasets:work']['file'] ), 'work records round-trip' );
	t_equals( $doc['attachments'], read_all( $tmp, $byid['attachments']['file'] ), 'attachments round-trip' );
}

echo "offsets file\n";
$r   = run_split( $S, $tmp, $json, $encode );
$c   = $r['collections'][0];
$idx = file_get_contents( $tmp . '/' . $c['file'] . '.idx' );
t_equals( 16, strlen( $idx ), 'eight bytes per record' );
$second = unpack( 'J', substr( $idx, 8, 8 ) )[1];
$h      = fopen( $tmp . '/' . $c['file'] . '.jsonl', 'rb' );
fseek( $h, $second );
t_equals( 'Second', json_decode( base64_decode( rtrim( fgets( $h ) ) ), true )['post_title'], 'the offset points at the second record' );
fclose( $h );

echo "layout variations\n";
$pretty = json_encode( $doc, JSON_PRETTY_PRINT );
$r      = run_split( $S, $tmp, $pretty, $encode, 13 );
t_true( is_array( $r ) && 5 === count( $r['collections'] ), 'pretty-printed document (line breaks between tokens)' );
t_equals( $doc['datasets']['customer'], read_all( $tmp, $r['collections'][0]['file'] ), 'pretty-printed records still round-trip' );

$other = '{"extra":{"datasets":[{"a":1}],"deep":[[1,2],{"x":"]}"}]},"format":2,"datasets":{"a":[{"id":1}],"b":null,"c":"text"},"attachments":null,"zzz":12.5e3,"options":{}}';
$r     = run_split( $S, $tmp, $other, $encode, 5 );
t_true( is_array( $r ), 'unknown keys, null and scalar values are skipped' );
t_equals( 2, $r['format'], 'format read wherever it sits' );
t_equals( array( 'datasets:a' ), array_map( function ( $c ) { return $c['id']; }, $r['collections'] ), 'only the real collection is listed' );

$dup = '{"format":1,"datasets":{"a":[{"id":1}],"a":[{"id":2}]}}';
$r   = run_split( $S, $tmp, $dup, $encode );
t_equals( 2, $r['collections'][0]['count'], 'a repeated dataset name appends to the same collection' );
t_equals( 1, count( $r['collections'] ), 'and is listed once' );

$r = run_split( $S, $tmp, '{"format":1,"datasets":{"a":[1,2]}}', $encode );
t_true( is_wp_error( $r ), 'a record that is not an object is refused' );

$r = run_split( $S, $tmp, '{"format":1,"datasets":{"a":[ {"id":1}, ', $encode );
t_true( is_wp_error( $r ), 'a truncated document is refused' );
$r = run_split( $S, $tmp, '{"format":1,"datasets":{"a":[{"id":"abc', $encode );
t_true( is_wp_error( $r ), 'a truncated string is refused' );
$r = run_split( $S, $tmp, '{"format":1} garbage', $encode );
t_true( is_wp_error( $r ), 'content after the document is refused' );
$r = run_split( $S, $tmp, '{"datasets":{}}', $encode );
t_true( is_wp_error( $r ), 'a document without a format number is refused' );
$r = run_split( $S, $tmp, '["format"]', $encode );
t_true( is_wp_error( $r ), 'a document that is not an object is refused' );
$r = run_split( $S, $tmp, '{"format":1,"datasets":{"a":[{"id":1}]]}}', $encode );
t_true( is_wp_error( $r ), 'mismatched brackets are refused' );
$r = run_split( $S, $tmp, '{"format":"1"}', $encode );
t_true( is_wp_error( $r ), 'a format number written as text is refused' );
$r = $S::split( $tmp . '/does-not-exist.json', $tmp, $encode );
t_true( is_wp_error( $r ), 'a missing file is an error, not a warning' );

$r = run_split( $S, $tmp, $json, function ( $raw ) { return "has\nbreak"; } );
t_true( is_wp_error( $r ), 'an encoder that returns a line break is refused' );

echo "size caps\n";
$huge = '{"format":1,"datasets":{"a":[{"v":"' . str_repeat( 'x', Fotonic_Restore_Splitter::MAX_RECORD + 10 ) . '"}]}}';
$r    = run_split( $S, $tmp, $huge, $encode );
t_true( is_wp_error( $r ), 'one record over the cap is refused' );
$many = array();
for ( $i = 0; $i <= Fotonic_Restore_Splitter::MAX_COLLECTIONS; $i++ ) { $many[] = '"k' . $i . '":[{"a":1}]'; }
$r = run_split( $S, $tmp, '{"format":1,"datasets":{' . implode( ',', $many ) . '}}', $encode );
t_true( is_wp_error( $r ), 'too many collections are refused' );

echo "bigger than one block\n";
$records = array();
for ( $i = 0; $i < 3000; $i++ ) {
	$records[] = array( 'ID' => $i, 'meta' => array( 'note' => str_repeat( "w\"{}[]\\ \u{00e8}", 10 ) . $i ) );
}
$big = json_encode( array( 'format' => 1, 'datasets' => array( 'work' => $records ) ) );
$r   = run_split( $S, $tmp, $big, $encode, 262144 );
t_true( strlen( $big ) > 262144 * 2, 'the test document spans several default blocks (' . strlen( $big ) . ' bytes)' );
t_equals( 3000, $r['collections'][0]['count'], 'every record counted' );
t_equals( $records, read_all( $tmp, $r['collections'][0]['file'] ), 'every record intact across block boundaries' );

echo "random fuzz: documents survive any block size\n";
mt_srand( 5 );
$alphabet = array( '"', '\\', '{', '}', '[', ']', ',', ':', ' ', "\n", "\u{00e8}", 'a', '0' );
for ( $round = 0; $round < 30; $round++ ) {
	$recs = array();
	for ( $i = 0, $n = mt_rand( 1, 12 ); $i < $n; $i++ ) {
		$t = '';
		for ( $k = 0, $m = mt_rand( 0, 40 ); $k < $m; $k++ ) { $t .= $alphabet[ mt_rand( 0, count( $alphabet ) - 1 ) ]; }
		$recs[] = array( 'ID' => $i, 'meta' => array( 'k' => $t, 'n' => array( $i, $t ) ) );
	}
	$d = json_encode( array( 'format' => 1, 'datasets' => array( 'd' => $recs ), 'attachments' => $recs ) );
	$r = run_split( $S, $tmp, $d, $encode, mt_rand( 1, 40 ) );
	if ( ! is_array( $r ) || $recs !== read_all( $tmp, $r['collections'][0]['file'] ) || $recs !== read_all( $tmp, $r['collections'][1]['file'] ) ) {
		t_equals( 'round trip', 'broken in round ' . $round, 'fuzz round' );
		break;
	}
}
t_equals( 30, $round, 'thirty random documents round-trip with random block sizes' );

t_done();
