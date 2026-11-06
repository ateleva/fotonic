<?php
/**
 * Documents already in the media library: moved into the private folder, encrypted, the original gone; files that
 * are used elsewhere stay; a stop in the middle repeats safely; the job does not count what must stay.
 * A test master key is passed by hand: the live Vault is never opened. Everything made here is removed.
 */
require __DIR__ . '/../lib/wp-bootstrap.php';

t_preserve_options( array( Fotonic_Work_Files::OPTION_SKIPPED, Fotonic_Work_Files::OPTION_SWEEP, 'fotonic_ftnc_wf_test_settings' ) );
$key = random_bytes( 32 );

$library = function ( $name, $content = "%PDF-1.7\nlibrary file" ) {
	$file = wp_upload_dir()['path'] . '/ftnc-mig-' . bin2hex( random_bytes( 3 ) ) . '-' . $name;
	file_put_contents( $file, $content );
	$id    = wp_insert_attachment( array( 'post_title' => 'ftnc mig', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $file );
	$thumb = preg_replace( '/\.pdf$/', '-150x150.jpg', $file );
	file_put_contents( $thumb, 'jpg' );
	wp_update_attachment_metadata( $id, array( 'sizes' => array( 'thumbnail' => array( 'file' => basename( $thumb ) ) ) ) );
	t_cleanup( (int) $id );
	return array( (int) $id, $file, $thumb );
};
$work = function ( array $ids ) {
	$w = wp_insert_post( array( 'post_type' => 'ftnc_work', 'post_title' => 'ftnc mig work', 'post_status' => 'publish' ) );
	t_cleanup( (int) $w );
	$rows = array();
	foreach ( $ids as $id ) {
		$rows[] = array( 'type' => 'attachment', 'id' => $id, 'label' => 'Etichetta ' . $id );
	}
	update_post_meta( $w, '_ftnc_work_files', wp_slash( wp_json_encode( $rows ) ) );
	return (int) $w;
};
$ids_of = function ( $w ) {
	return Fotonic_Work_Files::ids_in( (string) get_post_meta( $w, '_ftnc_work_files', true ) );
};
$count_private = function () {
	return count( glob( Fotonic_Work_Files::dir() . '/*.enc' ) ?: array() );
};

// 1. A library file in one work: encrypted copy, work repointed, original and thumbnail gone, label kept.
list( $a, $a_file, $a_thumb ) = $library( 'a.pdf' );
$w1 = $work( array( $a ) );
t_equals( array( $a => 'move' ), Fotonic_Work_Files::classify( $w1 ), 'a plain library file is to be moved' );
t_equals( 1, Fotonic_Work_Files::secure_record( $w1, $key, false ), 'analysis counts it' );
Fotonic_Work_Files::reset_budget();
t_equals( 1, Fotonic_Work_Files::secure_record( $w1, $key, true ), 'the write handles it' );
$new = $ids_of( $w1 )[0];
t_true( $new !== $a && Fotonic_Work_Files::is_work_file( $new ), 'the work lists a private copy' );
t_true( Fotonic_File_Crypt::is_encrypted( (string) get_attached_file( $new ) ), 'the copy is under the Vault layer' );
t_equals( "%PDF-1.7\nlibrary file", Fotonic_File_Crypt::decrypt_file( (string) get_attached_file( $new ), $key ), 'the copy holds the same bytes' );
t_equals( basename( $a_file ), Fotonic_Work_Files::name_of( $new, $key ), 'the name is the old file name, protected' );
t_equals( null, get_post( $a ), 'the original attachment is gone' );
t_equals( false, is_file( $a_file ) || is_file( $a_thumb ), 'the original file and its thumbnail are gone' );
t_equals( 'Etichetta ' . $a, json_decode( (string) get_post_meta( $w1, '_ftnc_work_files', true ), true )[0]['label'], 'the row keeps its label' );
t_equals( 0, Fotonic_Work_Files::secure_record( $w1, $key, false ), 'nothing is left to do for this work' );
t_cleanup( $new );

// 2. One file in two works: one copy, both works repointed, the original deleted once.
list( $s, $s_file ) = $library( 's.pdf' );
$wa = $work( array( $s ) );
$wb = $work( array( $s ) );
$before = $count_private();
Fotonic_Work_Files::reset_budget();
Fotonic_Work_Files::secure_record( $wa, $key, true );
$na = $ids_of( $wa )[0];
$nb = $ids_of( $wb )[0];
t_true( $na === $nb && Fotonic_Work_Files::is_work_file( $na ), 'both works point at the same private copy' );
t_equals( $before + 1, $count_private(), 'one copy was made, not two' );
t_equals( false, is_file( $s_file ), 'the original is gone' );
t_equals( 0, Fotonic_Work_Files::secure_record( $wb, $key, true ), 'the second work has nothing left' );
t_cleanup( $na );

// 3. Files that stay: used elsewhere (a thumbnail, a setting), too big, wrong type. Not counted, listed.
Fotonic_Work_Files::clear_skipped();
list( $t, $t_file ) = $library( 't.pdf' );
$post = wp_insert_post( array( 'post_type' => 'post', 'post_title' => 'ftnc mig post', 'post_status' => 'draft' ) );
t_cleanup( (int) $post );
update_post_meta( $post, '_thumbnail_id', $t );
list( $l, $l_file ) = $library( 'l.pdf' );
update_option( 'fotonic_ftnc_wf_test_settings', array( 'logo_id' => $l, 'price' => 99 ), false );
list( $m, $m_file ) = $library( 'm.pdf', "%PDF-1.7\n" . str_repeat( 'x', 3000 ) );
$wk = $work( array( $t, $l, $m ) );
add_filter( 'ftnc_max_upload_bytes', $limit = function () { return 1024; } );
$classes = Fotonic_Work_Files::classify( $wk );
t_equals( 'kept_shared', $classes[ $t ], 'a featured image stays in the library' );
t_equals( 'kept_shared', $classes[ $l ], 'a plugin setting that holds the id keeps the file in the library' );
t_equals( 'too_big', $classes[ $m ], 'a file over the limit stays' );
t_equals( 0, Fotonic_Work_Files::secure_record( $wk, $key, false ), 'the job does not count files that must stay (or it would never finish)' );
Fotonic_Work_Files::reset_budget();
Fotonic_Work_Files::secure_record( $wk, $key, true );
Fotonic_Work_Files::secure_record( $wk, $key, true );
remove_filter( 'ftnc_max_upload_bytes', $limit );
$why = array();
foreach ( Fotonic_Work_Files::skipped_list() as $row ) {
	$why[ $row['id'] ] = $row['why'];
}
t_equals( array( $t => 'kept_shared', $l => 'kept_shared', $m => 'too_big' ), $why, 'each is listed once, with its reason, and nothing else' );
t_equals( true, is_file( $t_file ) && is_file( $l_file ) && is_file( $m_file ), 'their files are untouched' );
t_equals( array( $t, $l, $m ), $ids_of( $wk ), 'the work still lists them' );
t_true( 0 === strpos( implode( ',', Fotonic_Work_Files::skipped_list()[1]['uses'] ), 'option:fotonic_ftnc_wf_test_settings' ), 'the list says where a shared file is used (the setting)' );
// A price equal to an id is not a use.
update_option( 'fotonic_ftnc_wf_test_settings', array( 'price' => $l ), false );
t_equals( array(), array_filter( Fotonic_Work_Files::other_uses( $l ), function ( $u ) { return 0 === strpos( $u, 'option:fotonic_ftnc_wf_test_settings' ); } ), 'a bare number equal to the id is not a use' );

// 4. A stop after the copy was made: repeating reuses it, no second copy, the original goes.
list( $r, $r_file ) = $library( 'r.pdf' );
$wr = $work( array( $r ) );
Fotonic_Work_Files::reset_budget();
$ensure = Fotonic_Work_Files::ensure_dir();
$target = $ensure . '/' . Fotonic_Work_Files::stored_basename( 'pdf', true );
Fotonic_File_Crypt::encrypt_file( $r_file, $target, $key );
$copy = Fotonic_Work_Files::insert_attachment( $target, 'application/pdf', (string) Fotonic_Field_Crypt::store( basename( $r_file ), $key ) );
t_cleanup( $copy );
update_post_meta( $r, Fotonic_Work_Files::META_MOVED, $copy );
$before = $count_private();
Fotonic_Work_Files::secure_record( $wr, $key, true );
t_equals( array( $copy ), $ids_of( $wr ), 'the work points at the copy made before the stop' );
t_equals( $before, $count_private(), 'no second copy was made' );
t_equals( null, get_post( $r ), 'the original is deleted' );

// 5. A plain private file (the Vault was set up after the upload): bytes and name are encrypted once.
$p     = sys_get_temp_dir() . '/ftnc-mig-plain.pdf';
file_put_contents( $p, "%PDF-1.7\nplain private" );
$plain = Fotonic_Work_Files::store_upload( array( 'name' => 'Contratto Rossi.pdf', 'tmp_name' => $p, 'size' => filesize( $p ), 'error' => 0 ), null, get_current_user_id() );
$pid   = (int) $plain['id'];
t_cleanup( $pid );
$old_path = (string) get_attached_file( $pid );
$wp       = $work( array( $pid ) );
t_equals( array( $pid => 'encrypt' ), Fotonic_Work_Files::classify( $wp ), 'a plain private file is to be encrypted' );
Fotonic_Work_Files::reset_budget();
t_equals( 1, Fotonic_Work_Files::secure_record( $wp, $key, true ), 'it is encrypted' );
t_true( Fotonic_File_Crypt::is_encrypted( (string) get_attached_file( $pid ) ) && ! is_file( $old_path ), 'a new encrypted file replaced the plain one' );
t_equals( 'Contratto Rossi.pdf', Fotonic_Work_Files::name_of( $pid, $key ), 'the name is encrypted and reads back' );
t_equals( 0, Fotonic_Work_Files::secure_record( $wp, $key, true ), 'a second pass does nothing: no double encryption' );

// 6. A Pro file (quote PDF) is left alone.
$qdir = wp_upload_dir()['basedir'] . '/fotonic/quotes';
wp_mkdir_p( $qdir );
$qfile = $qdir . '/ftnc-mig-' . bin2hex( random_bytes( 3 ) ) . '.pdf';
file_put_contents( $qfile, "%PDF-1.7\nquote" );
$q = wp_insert_attachment( array( 'post_title' => 'ftnc mig quote', 'post_mime_type' => 'application/pdf', 'post_status' => 'private' ), $qfile );
t_cleanup( (int) $q );
t_equals( array(), Fotonic_Work_Files::classify( $work( array( (int) $q ) ) ), 'a file of the quotes folder needs nothing' );

// 7. Labels go through the registry walker (plain label becomes f1), and the file step runs after them.
$groups = Fotonic_Vault_Fields::groups();
list( $z, $z_file ) = $library( 'z.pdf' );
$wz = $work( array( $z ) );
Fotonic_Work_Files::reset_budget();
$n = Fotonic_Vault_Fields::process_record( $wz, $groups['works'], $key, true );
t_true( $n >= 2, 'the walker counts the label and the file' );
$row = json_decode( (string) get_post_meta( $wz, '_ftnc_work_files', true ), true )[0];
t_true( 0 === strncmp( $row['label'], 'f1:', 3 ) && Fotonic_Work_Files::is_work_file( $row['id'] ), 'label encrypted and the row points at the private copy' );
t_equals( 'Etichetta ' . $z, Fotonic_Field_Crypt::read( $row['label'], $key ), 'the label reads back' );
t_cleanup( (int) $row['id'] );

// 8. Budget: six files in one work take two calls, none is handled twice, none is lost.
$many = array();
foreach ( range( 1, 6 ) as $i ) {
	$many[] = $library( "b$i.pdf" )[0];
}
$wm = $work( $many );
Fotonic_Work_Files::reset_budget();
t_equals( Fotonic_Work_Files::FILE_BUDGET, Fotonic_Work_Files::secure_record( $wm, $key, true ), 'the first call stops at the file budget' );
Fotonic_Work_Files::reset_budget();
t_equals( 6 - Fotonic_Work_Files::FILE_BUDGET, Fotonic_Work_Files::secure_record( $wm, $key, true ), 'the second call finishes the rest' );
t_equals( 0, Fotonic_Work_Files::secure_record( $wm, $key, false ), 'nothing is left' );
foreach ( $ids_of( $wm ) as $id ) {
	t_cleanup( $id );
}

@unlink( $p );
t_done();
