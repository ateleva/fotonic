<?php
/**
 * Work documents (Fotonic_Work_Files): upload, ownership, names, save and delete rules, formatter,
 * pending purge. A test master key is passed by hand: the live Vault is never opened or changed.
 * Work posts and attachments made here are removed at the end.
 */
require __DIR__ . '/../lib/wp-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$key  = random_bytes( 32 );
$name = "L'«Contratto» \"Rossi\" è.pdf"; // A backslash is cut away on purpose: it only appears in the fake path of old browsers.
$tmp  = function ( $content ) {
	$p = sys_get_temp_dir() . '/ftnc-wf-' . bin2hex( random_bytes( 4 ) ) . '.bin';
	file_put_contents( $p, $content );
	return $p;
};
$upload = function ( $file_name, $content, $k ) use ( $tmp ) {
	$p = $tmp( $content );
	return Fotonic_Work_Files::store_upload( array( 'name' => $file_name, 'tmp_name' => $p, 'size' => filesize( $p ), 'error' => 0 ), $k, get_current_user_id() );
};
$work = function () {
	$id = wp_insert_post( array( 'post_type' => 'ftnc_work', 'post_title' => 'ftnc wf test', 'post_status' => 'publish' ) );
	t_cleanup( (int) $id );
	return (int) $id;
};
$save = new ReflectionMethod( 'Fotonic_REST_API', 'save_work_meta' );
$save->setAccessible( true );
$fmt = new ReflectionMethod( 'Fotonic_REST_API', 'format_work' );
$fmt->setAccessible( true );
$files_of = function ( $w ) {
	return json_decode( (string) get_post_meta( $w, '_ftnc_work_files', true ), true );
};
$exists = function ( $id ) {
	return null !== get_post( $id ) && is_file( (string) get_attached_file( $id ) );
};

// Upload with a key: encrypted file with a random name, no thumbnails, name under the Vault layer.
$a = $upload( $name, "%PDF-1.7\nsecret contract text", $key );
t_true( is_array( $a ), 'upload with a key works' );
$A = (int) $a['id'];
t_cleanup( $A );
$path = (string) get_attached_file( $A );
t_true( Fotonic_Work_Files::is_work_file( $A ), 'it is one of our files' );
t_equals( 1, preg_match( '#/fotonic/work-files/[A-Za-z0-9]{24}\.pdf\.enc$#', $path ), 'stored as <random>.pdf.enc in the private folder' );
t_true( Fotonic_File_Crypt::is_encrypted( $path ), 'the file is under the Vault layer' );
t_true( 0 === strncmp( (string) get_post_field( 'post_title', $A ), 'f1:', 3 ), 'the title is encrypted' );
t_equals( $name, Fotonic_Work_Files::name_of( $A, $key ), 'the name comes back exactly, quotes and accents included' );
t_equals( null, Fotonic_Work_Files::name_of( $A, null ), 'no key: the name is null, never an empty string' );
t_equals( array(), glob( dirname( $path ) . '/*-150x150*' ) ?: array(), 'no thumbnail was written' );
t_equals( 'private', get_post_status( $A ), 'the attachment is private' );
t_equals( 'secret contract text', substr( (string) Fotonic_File_Crypt::decrypt_file( $path, $key ), 9 ), 'the content decrypts with the key' );

// Upload with no key (site with no Vault): a plain file, plain title.
$b = $upload( 'plain.pdf', "%PDF-1.7\nplain one", null );
$B = (int) $b['id'];
t_cleanup( $B );
t_equals( 1, preg_match( '#/[A-Za-z0-9]{24}\.pdf$#', (string) get_attached_file( $B ) ), 'no key: <random>.pdf, not .enc' );
t_equals( 'plain.pdf', get_post_field( 'post_title', $B ), 'no key: plain title' );
t_equals( 'plain.pdf', Fotonic_Work_Files::name_of( $B, null ), 'no key: the name reads without a key' );

// Refusals.
add_filter( 'ftnc_max_upload_bytes', $limit = function () { return 1024; } );
$big = $upload( 'big.pdf', "%PDF-1.7\n" . str_repeat( 'x', 4000 ), $key );
remove_filter( 'ftnc_max_upload_bytes', $limit );
t_equals( 'ftnc_file_too_big', is_wp_error( $big ) ? $big->get_error_code() : '', 'a file over the limit is refused' );
t_equals( 'ftnc_file_type', ( $r = $upload( 'x.php', '<?php echo 1;', $key ) ) && is_wp_error( $r ) ? $r->get_error_code() : '', 'a script is refused' );
t_equals( 'ftnc_file_type', ( $r = $upload( 'fake.pdf', '<?php echo 1;', $key ) ) && is_wp_error( $r ) ? $r->get_error_code() : '', 'a script named pdf is refused' );

// Ownership: the uploader opens it at once; another user not until a work lists it.
$other = wp_insert_user( array( 'user_login' => 'ftnc_wf_' . bin2hex( random_bytes( 3 ) ), 'user_pass' => wp_generate_password(), 'role' => 'administrator' ) );
t_equals( true, Fotonic_Work_Files::can_open( $A, get_current_user_id() ), 'the uploader can open an unsaved file' );
t_equals( false, Fotonic_Work_Files::can_open( $A, (int) $other ), 'another user cannot open an unsaved file' );

// Saving a work that lists two files: pending is cleared, the label is stored, links work.
$w1 = $work();
$save->invoke( null, $w1, array( 'files' => array(
	array( 'type' => 'attachment', 'id' => $A, 'label' => 'Contratto' ),
	array( 'type' => 'attachment', 'id' => $B, 'label' => '' ),
	array( 'type' => 'link', 'url' => 'https://example.test/a', 'label' => 'Galleria' ),
) ) );
t_equals( '', (string) get_post_meta( $A, Fotonic_Work_Files::META_PENDING, true ), 'a saved file is no longer waiting' );
t_equals( true, Fotonic_Work_Files::can_open( $A, (int) $other ), 'once a work lists it, any administrator can open it' );
t_equals( array( $w1 ), Fotonic_Work_Files::works_using( $A ), 'works_using finds the work' );

// Formatter: no address for our files; locked (no key) name and label are null, plain ones read.
$locked_label = Fotonic_Field_Crypt::encrypt( 'Segreto', $key );
update_post_meta( $w1, '_ftnc_work_files', wp_slash( wp_json_encode( array(
	array( 'type' => 'attachment', 'id' => $A, 'label' => $locked_label ),
	array( 'type' => 'attachment', 'id' => $B, 'label' => 'Plain label' ),
	array( 'type' => 'link', 'url' => 'https://example.test/a', 'label' => $locked_label ),
) ) ) );
$out   = $fmt->invoke( null, get_post( $w1 ) );
$files = $out['files'];
t_equals( '', $files[0]['url'], 'a private file has no public address' );
t_equals( null, $files[0]['filename'], 'encrypted name with no key: null' );
t_equals( null, $files[0]['label'], 'encrypted label with no key: null' );
t_equals( 'plain.pdf', $files[1]['filename'], 'a plain name reads' );
t_equals( 'Plain label', $files[1]['label'], 'a plain label reads' );
t_equals( null, $files[2]['label'], 'the label of a link is protected too' );

// A save from a screen that could not read the labels (null) keeps them, attachments and links.
$save->invoke( null, $w1, array( 'files' => array(
	array( 'type' => 'attachment', 'id' => $A, 'label' => null ),
	array( 'type' => 'attachment', 'id' => $B, 'label' => null ),
	array( 'type' => 'link', 'url' => 'https://example.test/a', 'label' => null ),
) ) );
$rows = $files_of( $w1 );
t_equals( $locked_label, $rows[0]['label'], 'an unreadable attachment label is kept as stored' );
t_equals( 'Plain label', $rows[1]['label'], 'a plain label sent as null is kept' );
t_equals( $locked_label, $rows[2]['label'], 'an unreadable link label is kept as stored' );

// Shared file: removing it from one work keeps it while another lists it; then it goes.
$w2 = $work();
update_post_meta( $w2, '_ftnc_work_files', wp_slash( wp_json_encode( array( array( 'type' => 'attachment', 'id' => $B, 'label' => '' ) ) ) ) );
$save->invoke( null, $w1, array( 'files' => array( array( 'type' => 'attachment', 'id' => $A, 'label' => null ) ) ) );
t_equals( true, $exists( $B ), 'a file another work still lists is kept' );
$save->invoke( null, $w2, array( 'files' => array() ) );
t_equals( false, $exists( $B ), 'the last work to drop a file deletes it from disk and database' );
t_equals( true, $exists( $A ), 'the file still listed stays' );

// A Pro file (a quote PDF in fotonic/quotes) is never deleted by a save.
$qdir = $uploads_dir = wp_upload_dir()['basedir'] . '/fotonic/quotes';
wp_mkdir_p( $qdir );
$qfile = $qdir . '/ftnc-wf-test-' . bin2hex( random_bytes( 3 ) ) . '.pdf';
file_put_contents( $qfile, "%PDF-1.7\nquote" );
$Q = wp_insert_attachment( array( 'post_title' => 'ftnc wf quote', 'post_mime_type' => 'application/pdf', 'post_status' => 'private' ), $qfile );
t_cleanup( (int) $Q );
update_post_meta( $w1, '_ftnc_work_files', wp_slash( wp_json_encode( array( array( 'type' => 'attachment', 'id' => (int) $Q, 'label' => '' ), array( 'type' => 'attachment', 'id' => $A, 'label' => '' ) ) ) ) );
$save->invoke( null, $w1, array( 'files' => array( array( 'type' => 'attachment', 'id' => $A, 'label' => null ) ) ) );
t_equals( true, $exists( (int) $Q ), 'a file of the quotes folder is never deleted by a save' );
t_equals( false, Fotonic_Work_Files::release( (int) $Q ), 'release() refuses a file that is not ours' );

// Deleting a work for good takes its unshared files; the Bin does not.
wp_trash_post( $w1 );
t_equals( true, $exists( $A ), 'a work in the Bin keeps its files' );
wp_delete_post( $w1, true );
t_equals( false, $exists( $A ), 'a work deleted for good takes its files' );

// Pending purge: old and unsaved goes; recent stays; one a work lists keeps its file and loses the mark.
$old = $upload( 'old.pdf', "%PDF-1.7\nold", null );
$new = $upload( 'new.pdf', "%PDF-1.7\nnew", null );
$lnk = $upload( 'lnk.pdf', "%PDF-1.7\nlnk", null );
foreach ( array( $old, $new, $lnk ) as $x ) {
	t_cleanup( (int) $x['id'] );
}
update_post_meta( (int) $old['id'], Fotonic_Work_Files::META_PENDING, get_current_user_id() . ':' . ( time() - 90000 ) );
update_post_meta( (int) $new['id'], Fotonic_Work_Files::META_PENDING, get_current_user_id() . ':' . ( time() - 3600 ) );
update_post_meta( (int) $lnk['id'], Fotonic_Work_Files::META_PENDING, get_current_user_id() . ':' . ( time() - 90000 ) );
$w3 = $work();
update_post_meta( $w3, '_ftnc_work_files', wp_slash( wp_json_encode( array( array( 'type' => 'attachment', 'id' => (int) $lnk['id'], 'label' => '' ) ) ) ) );
Fotonic_Work_Files::purge_pending();
t_equals( false, $exists( (int) $old['id'] ), 'an upload nobody saved is dropped after a day' );
t_equals( true, $exists( (int) $new['id'] ), 'a recent upload stays' );
t_equals( true, $exists( (int) $lnk['id'] ) && '' === (string) get_post_meta( (int) $lnk['id'], Fotonic_Work_Files::META_PENDING, true ), 'a listed file stays and is no longer marked' );

// A running job or restore blocks uploads with the standard 423 answer.
t_equals( null === Fotonic_REST_API::encrypt_job_response(), ! Fotonic_Vault_Encrypt_Existing::is_blocking(), 'the upload route uses the same block as every protected write' );

wp_delete_user( $other );
t_done();
