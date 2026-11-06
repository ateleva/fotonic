<?php
// Unit test for Fotonic_Restore_Upload. No WordPress, no database: a temp folder stands in for uploads/.
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['t_base']  = sys_get_temp_dir() . '/ftnc-upload-' . bin2hex( random_bytes( 6 ) );
$GLOBALS['t_free']  = null; // Overrides the free space seen by start().
$GLOBALS['t_user']  = 3;
mkdir( $GLOBALS['t_base'] );
register_shutdown_function( function () {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['t_base'], RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); }
	@rmdir( $GLOBALS['t_base'] );
} );

function __( $s, $d = null ) { return $s; }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['t_base'] ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function get_current_user_id() { return $GLOBALS['t_user']; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function apply_filters( $tag, $value ) { return ( 'ftnc_restore_free_space' === $tag && null !== $GLOBALS['t_free'] ) ? $GLOBALS['t_free'] : $value; }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

require __DIR__ . '/../../includes/class-restore-upload.php';
$U = 'Fotonic_Restore_Upload';
$C = Fotonic_Restore_Upload::CHUNK_SIZE;

// A fake archive: 'PK' then random bytes, a bit over two chunks.
$size  = 2 * $C + 12345;
$file  = 'PK' . random_bytes( $size - 2 );
$sha   = hash( 'sha256', $file );
$piece = function ( $i ) use ( $file, $C ) { return substr( $file, $i * $C, $C ); };

echo "start\n";
t_true( is_wp_error( $U::start( 0 ) ), 'an empty file is refused' );
t_true( is_wp_error( $U::start( 100, 'not-a-hash' ) ), 'a malformed checksum is refused' );
$GLOBALS['t_free'] = $size * 2 - 1;
$r = $U::start( $size, $sha );
t_equals( 'ftnc_restore_no_space', $r->code ?? '', 'not enough free space is refused' );
$GLOBALS['t_free'] = $size * 2;
$s = $U::start( $size, strtoupper( $sha ) );
t_true( is_array( $s ), 'exactly enough space is accepted (upper-case checksum too)' );
$GLOBALS['t_free'] = null;
t_equals( 3, $s['total_chunks'], 'three pieces' );
t_equals( $C, $s['chunk_size'], 'piece size announced' );
t_true( $U::valid_id( $s['upload_id'] ), 'the id is 128 random bits in hex' );
$dir = $U::restore_dir();
t_true( file_exists( $dir . '/.htaccess' ) && file_exists( $dir . '/index.php' ), 'the folder gets its deny-all guards' );
unlink( $dir . '/.htaccess' );
$U::restore_dir();
t_true( file_exists( $dir . '/.htaccess' ), 'a deleted guard comes back' );
$id = $s['upload_id'];

echo "pieces\n";
t_equals( 'ftnc_restore_unknown_upload', $U::chunk( str_repeat( 'a', 32 ), 0, 'x' )->code ?? '', 'an unknown id is refused' );
t_equals( 'ftnc_restore_unknown_upload', $U::chunk( '../etc/passwd', 0, 'x' )->code ?? '', 'a path as id is refused' );
t_equals( 'ftnc_restore_bad_chunk', $U::chunk( $id, 3, 'x' )->code ?? '', 'an index out of range is refused' );
t_equals( 'ftnc_restore_bad_chunk', $U::chunk( $id, -1, 'x' )->code ?? '', 'a negative index is refused' );
t_equals( 'ftnc_restore_bad_chunk', $U::chunk( $id, 0, 'short' )->code ?? '', 'a piece of the wrong size is refused' );
t_equals( 'ftnc_restore_bad_chunk', $U::chunk( $id, 2, $piece( 0 ) )->code ?? '', 'the last piece must have exactly the remaining size' );

$r = $U::chunk( $id, 2, $piece( 2 ) );
t_equals( 1, $r['received'], 'last piece first: one received' );
t_equals( 0, $r['next_missing'], 'next missing is the first piece' );
$r = $U::chunk( $id, 2, $piece( 2 ) );
t_equals( 1, $r['received'], 'a repeated piece is not counted twice' );
$r = $U::chunk( $id, 0, $piece( 0 ) );
t_equals( 1, $r['next_missing'], 'resume point: first piece still missing is 1' );
$st = $U::status( $id );
t_equals( 2, $st['received'], 'status shows two pieces' );
t_equals( 1, $st['next_missing'], 'status shows where to resume' );

$fin = $U::finish( $id );
t_equals( 'ftnc_restore_incomplete', $fin->code ?? '', 'finish with a missing piece is refused' );
t_equals( 1, $fin->data['next_missing'] ?? -1, 'and says which piece to send' );

$r = $U::chunk( $id, 1, $piece( 1 ) );
t_equals( null, $r['next_missing'], 'all pieces in: nothing missing' );

$path = $U::finish( $id );
t_true( is_string( $path ) && is_file( $path ), 'finish returns the archive path' );
t_equals( $sha, hash_file( 'sha256', $path ), 'the assembled file is identical, whatever the order' );
t_equals( $dir, dirname( $path ), 'it sits in the private folder' );
t_equals( $id, $U::id_of_path( $path ), 'the id can be recovered from the path' );
t_equals( '', $U::id_of_path( '/somewhere/else/eleva-backup-x.zip' ), 'a file elsewhere has no upload id' );
t_equals( $path, $U::finish( $id ), 'finish is repeatable' );
t_equals( $path, $U::ready_path( $id ), 'ready_path finds it' );
t_equals( 'ftnc_restore_upload_done', $U::chunk( $id, 0, $piece( 0 ) )->code ?? '', 'no more pieces after finish' );

echo "checks at finish\n";
$bad  = $file; $bad[ 100 ] = $bad[ 100 ] === 'a' ? 'b' : 'a';
$s2   = $U::start( $size, $sha );
foreach ( array( 0, 1, 2 ) as $i ) { $U::chunk( $s2['upload_id'], $i, substr( $bad, $i * $C, $C ) ); }
$r = $U::finish( $s2['upload_id'] );
t_equals( 'ftnc_restore_hash_mismatch', $r->code ?? '', 'a different checksum is refused' );
t_equals( 'ftnc_restore_unknown_upload', $U::status( $s2['upload_id'] )->code ?? '', 'and the upload is removed' );

$text = 'This is not a zip. ' . str_repeat( 'x', 1000 );
$s3   = $U::start( strlen( $text ), hash( 'sha256', $text ) );
$U::chunk( $s3['upload_id'], 0, $text );
t_equals( 'ftnc_restore_not_zip', $U::finish( $s3['upload_id'] )->code ?? '', 'a file that is not a zip is refused' );

$s4 = $U::start( strlen( $file ) );
foreach ( array( 0, 1, 2 ) as $i ) { $U::chunk( $s4['upload_id'], $i, $piece( $i ) ); }
t_true( is_string( $U::finish( $s4['upload_id'] ) ), 'no checksum sent: the transfer is accepted (the archive checks its own parts)' );

echo "limits and cleanup\n";
$U::discard( $s4['upload_id'] );
$U::discard( $id );
$ids = array();
for ( $k = 0; $k < $U::MAX_ACTIVE; $k++ ) { $x = $U::start( 100 ); $ids[] = $x['upload_id']; }
t_equals( 'ftnc_restore_too_many', $U::start( 100 )->code ?? '', 'at most ' . $U::MAX_ACTIVE . ' uploads at a time' );
// Age the first one beyond the limit: the next start removes it and goes through.
$mp   = $dir . '/up-' . $ids[0] . '.json';
$meta = json_decode( file_get_contents( $mp ), true );
$meta['created'] = time() - $U::TTL - 5;
file_put_contents( $mp, json_encode( $meta ) );
$x = $U::start( 100 );
t_true( is_array( $x ), 'an expired upload makes room' );
t_true( ! file_exists( $mp ) && ! file_exists( $dir . '/up-' . $ids[0] . '.part' ), 'its files are gone' );
file_put_contents( $dir . '/up-' . str_repeat( 'b', 32 ) . '.part', 'orphan' );
file_put_contents( $dir . '/up-' . str_repeat( 'c', 32 ) . '.zip', 'orphan' );
file_put_contents( $dir . '/keep-me.txt', 'not ours' );
$U::cleanup();
t_true( ! file_exists( $dir . '/up-' . str_repeat( 'b', 32 ) . '.part' ) && ! file_exists( $dir . '/up-' . str_repeat( 'c', 32 ) . '.zip' ), 'files without bookkeeping are removed' );
t_true( file_exists( $dir . '/keep-me.txt' ) && file_exists( $dir . '/index.php' ), 'other files are left alone' );
file_put_contents( $dir . '/up-' . $ids[1] . '.json', '{broken' );
$U::cleanup();
t_true( ! file_exists( $dir . '/up-' . $ids[1] . '.json' ), 'damaged bookkeeping is removed with its upload' );

t_done();
