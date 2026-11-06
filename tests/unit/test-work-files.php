<?php
// Unit test for the pure parts of Fotonic_Work_Files (types, file names, size limit). No WordPress, no database.
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['ftnc_filters'] = array();
function apply_filters( $tag, $value ) { return isset( $GLOBALS['ftnc_filters'][ $tag ] ) ? $GLOBALS['ftnc_filters'][ $tag ] : $value; }
function wp_max_upload_size() { return 1073741824; }
function wp_generate_password( $length = 12, $special = true, $extra = false ) {
	$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
	$out   = '';
	for ( $i = 0; $i < $length; $i++ ) { $out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ]; }
	return $out;
}
function wp_strip_all_tags( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_basename( $path ) { return basename( $path ); }
function wp_delete_file( $f ) { if ( is_file( $f ) ) { unlink( $f ); } }
require __DIR__ . '/../../includes/class-uploads.php';
require __DIR__ . '/../../includes/class-file-crypt.php';
require __DIR__ . '/../../includes/class-restore-files.php';
require __DIR__ . '/../../includes/class-work-files.php';

// Types.
$pdf = Fotonic_Work_Files::allowed_type( 'contratto.pdf' );
t_equals( 'application/pdf', $pdf['type'], 'pdf is accepted' );
t_equals( 'image/jpeg', Fotonic_Work_Files::allowed_type( 'foto.JPG' )['type'], 'extension case does not matter' );
t_equals( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', Fotonic_Work_Files::allowed_type( 'a.docx' )['type'], 'docx is accepted' );
t_equals( false, Fotonic_Work_Files::allowed_type( 'x.php' ), 'php is refused' );
t_equals( false, Fotonic_Work_Files::allowed_type( 'x.exe' ), 'exe is refused' );
t_equals( false, Fotonic_Work_Files::allowed_type( 'noext' ), 'no extension is refused' );
t_equals( false, Fotonic_Work_Files::allowed_type( 'a.php.txt' ), 'txt is not accepted' );
t_equals( 'application/pdf', Fotonic_Work_Files::allowed_type( 'evil.php.pdf' )['type'], 'only the last extension counts' );

// Content must match the extension.
$dir = sys_get_temp_dir() . '/ftnc-wf-' . bin2hex( random_bytes( 4 ) );
mkdir( $dir );
file_put_contents( $dir . '/a.pdf', "%PDF-1.7\nrest" );
file_put_contents( $dir . '/b.pdf', "<?php echo 1;" );
file_put_contents( $dir . '/c.png', "\x89PNG\r\n\x1a\nrest" );
file_put_contents( $dir . '/d.docx', "PK\x03\x04rest" );
file_put_contents( $dir . '/e.doc', "\xD0\xCF\x11\xE0rest" );
file_put_contents( $dir . '/f.jpg', "\xFF\xD8\xFF\xE0rest" );
t_equals( true, Fotonic_Work_Files::content_matches( $dir . '/a.pdf', 'pdf' ), 'a real pdf matches' );
t_equals( false, Fotonic_Work_Files::content_matches( $dir . '/b.pdf', 'pdf' ), 'a script named pdf does not match' );
t_equals( true, Fotonic_Work_Files::content_matches( $dir . '/c.png', 'png' ), 'a real png matches' );
t_equals( false, Fotonic_Work_Files::content_matches( $dir . '/c.png', 'pdf' ), 'a png named pdf does not match' );
t_equals( true, Fotonic_Work_Files::content_matches( $dir . '/d.docx', 'docx' ), 'a real docx matches' );
t_equals( true, Fotonic_Work_Files::content_matches( $dir . '/e.doc', 'doc' ), 'a real doc matches' );
t_equals( true, Fotonic_Work_Files::content_matches( $dir . '/f.jpg', 'jpg' ), 'a real jpg matches' );
t_equals( false, Fotonic_Work_Files::content_matches( $dir . '/missing.pdf', 'pdf' ), 'a missing file does not match' );

// Stored names.
$enc   = Fotonic_Work_Files::stored_basename( 'pdf', true );
$plain = Fotonic_Work_Files::stored_basename( 'PDF', false );
t_equals( 1, preg_match( '/^[A-Za-z0-9]{24}\.pdf\.enc$/', $enc ), 'encrypted name: 24 random characters, extension, .enc' );
t_equals( 1, preg_match( '/^[A-Za-z0-9]{24}\.pdf$/', $plain ), 'plain name: extension in lower case, no .enc' );
t_true( Fotonic_Work_Files::stored_basename( 'pdf', true ) !== $enc, 'a new name every time' );
t_equals( 'x.pdf', Fotonic_File_Crypt::plain_name( 'x.pdf.enc' ), 'the restore recovers the inner name (type check)' );

// Browser names.
t_equals( 'contratto.pdf', Fotonic_Work_Files::clean_name( 'C:\\fakepath\\contratto.pdf' ), 'a Windows path is cut to the name' );
t_equals( 'a.pdf', Fotonic_Work_Files::clean_name( '../../a.pdf' ), 'a Unix path is cut to the name' );
t_equals( 'document', Fotonic_Work_Files::clean_name( '' ), 'an empty name gets a neutral one' );
t_equals( "L'«Contratto» Rossi.pdf", Fotonic_Work_Files::clean_name( "L'«Contratto» Rossi.pdf" ), 'quotes and accents are kept' );
t_equals( 'ab.pdf', Fotonic_Work_Files::clean_name( "a\x01b.pdf" ), 'control characters are removed' );
t_true( mb_strlen( Fotonic_Work_Files::clean_name( str_repeat( 'è', 400 ) . '.pdf' ) ) <= 200, 'a very long name is cut' );

// Size limit: never above what a restore can take.
ini_set( 'memory_limit', '1024M' ); // The test box may have little memory: the memory cap has its own checks below.
t_equals( 52428800, Fotonic_Uploads::DEFAULT_MAX_BYTES, 'the default limit is 50 MB' );
t_equals( Fotonic_Uploads::DEFAULT_MAX_BYTES, Fotonic_Work_Files::max_bytes(), 'a document can be as big as the default limit when memory allows' );
$GLOBALS['ftnc_filters']['ftnc_max_upload_bytes'] = 500 * 1024 * 1024;
t_equals( Fotonic_Restore_Files::MAX_PROTECTED, Fotonic_Work_Files::max_bytes(), 'a raised limit stops at the restore limit' );

// The memory of the server caps the limit: a document is held about two times over while it is encrypted.
ini_set( 'memory_limit', '128M' );
$cap = Fotonic_Work_Files::memory_cap();
t_true( $cap > 10 * 1048576 && $cap < 52428800, 'with 128M of memory a document may still pass 10 MB but not 50 MB (cap ' . round( $cap / 1048576 ) . ' MB)' );
t_equals( $cap, Fotonic_Work_Files::max_bytes(), 'the memory cap wins when it is the smallest' );
ini_set( 'memory_limit', '-1' );
t_equals( PHP_INT_MAX, Fotonic_Work_Files::memory_cap(), 'no memory limit, no cap' );
ini_set( 'memory_limit', '1024M' );

// ids_in: the exact ids a value lists.
t_equals( array( 5, 7, 9 ), Fotonic_Work_Files::ids_in( '[5,{"type":"attachment","id":7},{"type":"link","url":"https://x.test","label":"a"},{"id":9}]' ), 'ids_in reads bare ids, rows and untyped rows, not links' );
t_equals( array(), Fotonic_Work_Files::ids_in( '' ), 'empty value lists nothing' );
t_equals( array(), Fotonic_Work_Files::ids_in( 'not json' ), 'a broken value lists nothing' );

// value_names_file: an id counts only under a key that talks about an image; a bare number is not the file.
t_equals( true, Fotonic_Work_Files::value_names_file( array( 'logo_id' => 35 ), 35, '2026/05/a.pdf', '' ), 'logo_id = the id names the file' );
t_equals( true, Fotonic_Work_Files::value_names_file( array( 'signature' => array( 'image_id' => '35' ) ), 35, '2026/05/a.pdf', '' ), 'a nested image_id as text names the file' );
t_equals( true, Fotonic_Work_Files::value_names_file( array( 'images' => array( 12, 35 ) ), 35, '2026/05/a.pdf', '' ), 'a list of ids under images names the file' );
t_equals( true, Fotonic_Work_Files::value_names_file( '[{"images":[35]}]', 35, '2026/05/a.pdf', '' ), 'a JSON string is read too' );
t_equals( false, Fotonic_Work_Files::value_names_file( array( 'price' => 35, 'owner_id' => 35, 'term_ids' => array( 35 ) ), 35, '2026/05/a.pdf', '' ), 'a price, a user id or a term id equal to the id is not the file' );
t_equals( true, Fotonic_Work_Files::value_names_file( '35', 35, '2026/05/a.pdf', '_ftnc_quote_pdf_id' ), 'a record key ending in _id with exactly the id (a quote PDF) names the file' );
t_equals( false, Fotonic_Work_Files::value_names_file( '35', 35, '2026/05/a.pdf', '_ftnc_owner_id' ), 'but not the owner (a user id)' );
t_equals( true, Fotonic_Work_Files::value_names_file( array( 'note' => 'see /uploads/2026/05/a.pdf here' ), 99, '2026/05/a.pdf', '' ), 'a text that holds the file path names the file' );
t_equals( false, Fotonic_Work_Files::value_names_file( array( 'logo_id' => 36 ), 35, '2026/05/a.pdf', '' ), 'another id does not' );

array_map( 'unlink', glob( $dir . '/*' ) );
rmdir( $dir );
t_done();
