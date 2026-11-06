<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

// The database side of phase 10 on the real WordPress: a library file made by the restore (labels, path, undo removes
// the file from disk) and the wipe query. The wipe runs ONLY on a throwaway post type registered here, never on the
// plugin's own records, and the test checks that the real records were not touched. Everything this test creates
// carries a batch label of its own and a shutdown sweep removes it even if the script dies.

$batch = 'test' . bin2hex( random_bytes( 6 ) );
$repo  = Fotonic_Restore_Writer::repo();
$files = array();
register_post_type( 'ftnc_wipetest', array( 'public' => false ) );
register_shutdown_function( function () use ( $batch, $repo, &$files ) {
	foreach ( $repo->ids_with_meta( Fotonic_Restore_Writer::BATCH_META, $batch ) as $id ) {
		'attachment' === get_post_type( $id ) ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
	}
	foreach ( get_posts( array( 'post_type' => 'ftnc_wipetest', 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $files as $f ) {
		if ( is_file( $f ) ) {
			wp_delete_file( $f );
		}
	}
} );

$real_before = $repo->fingerprint( Fotonic_Restore_Writer::post_types() );

echo "a library file made by the restore\n";
$up   = wp_upload_dir();
$path = $up['path'] . '/eleva-test-' . bin2hex( random_bytes( 4 ) ) . '.txt';
wp_mkdir_p( $up['path'] );
file_put_contents( $path, "ciao è\n" );
$files[] = $path;
$id = $repo->insert_attachment(
	array( 'post_title' => 'Contratto è', 'post_mime_type' => 'text/plain', 'post_status' => 'inherit' ),
	$path,
	array( Fotonic_Restore_Writer::BATCH_META => $batch, Fotonic_Restore_Writer::SRC_META => 'attachment:77', Fotonic_Restore_Files::SHA_META => hash( 'sha256', "ciao è\n" ) )
);
t_true( $id > 0, 'the attachment is made' );
t_equals( 'attachment', get_post_type( $id ), 'it is an attachment' );
t_equals( 'Contratto è', get_post( $id )->post_title, 'the title keeps its accent' );
t_equals( wp_normalize_path( $path ), wp_normalize_path( $repo->attachment_path( $id ) ), 'the path points at the file' );
t_equals( 'attachment:77', get_post_meta( $id, '_ftnc_import_src', true ), 'labelled with its backup id' );
t_equals( array( $id ), $repo->ids_with_meta( Fotonic_Restore_Files::SHA_META, hash( 'sha256', "ciao è\n" ) ), 'findable by the checksum of the backup bytes' );
$path2 = $up['path'] . '/eleva-test-' . bin2hex( random_bytes( 4 ) ) . '.txt';
file_put_contents( $path2, 'stranger' );
$files[] = $path2;
$stranger = wp_insert_attachment( array( 'post_title' => 'Not ours', 'post_mime_type' => 'text/plain', 'post_status' => 'inherit' ), $path2 );
update_post_meta( $stranger, Fotonic_Restore_Writer::BATCH_META, $batch ); // Swept at the end, not by the undo.
t_equals( 'skipped', Fotonic_Restore_Writer::undo_record( $stranger ), 'an attachment without our source label is never deleted by the undo' );
t_true( is_file( $path2 ), 'and its file stays' );

t_equals( 'deleted', Fotonic_Restore_Writer::undo_record( $id ), 'undo deletes the attachment it made' );
t_true( null === get_post( $id ) || false === get_post( $id ), 'it is gone from the database' );
t_true( ! is_file( $path ), 'and its file is off the disk' );

echo "the wipe query, on a throwaway post type\n";
$ids = array();
foreach ( array( 'draft', 'publish', 'trash' ) as $status ) {
	$ids[ $status ] = $repo->insert_post( array( 'post_type' => 'ftnc_wipetest', 'post_status' => $status, 'post_title' => 'w-' . $status ) );
	update_post_meta( $ids[ $status ], '_ftnc_uid', 'x-' . $status );
}
$kept = $repo->insert_post( array( 'post_type' => 'ftnc_wipetest', 'post_status' => 'publish', 'post_title' => 'kept' ) );
update_post_meta( $kept, Fotonic_Restore_Writer::BATCH_META, $batch );
$other = $repo->insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'eleva-test-page' ) );
update_post_meta( $other, Fotonic_Restore_Writer::BATCH_META, $batch ); // Swept at the end.

$r = $repo->wipe_batch( array( 'ftnc_wipetest' ), $batch, 2 );
t_equals( 2, $r['deleted'], 'a batch of two deletes two' );
t_equals( 1, $r['remaining'], 'one is left to do (the labelled one is not counted)' );
$r = $repo->wipe_batch( array( 'ftnc_wipetest' ), $batch, 2 );
t_equals( 1, $r['deleted'], 'the next batch finishes' );
t_equals( 0, $r['remaining'], 'nothing left' );
foreach ( $ids as $status => $pid ) {
	t_true( null === get_post( $pid ) || false === get_post( $pid ), "the $status record is gone (every status, the bin too)" );
	t_equals( '', (string) get_post_meta( $pid, '_ftnc_uid', true ), "and its meta with it ($status)" );
}
t_true( (bool) get_post( $kept ), 'a record labelled with the job stays' );
t_true( (bool) get_post( $other ), 'a post of another type is untouched' );
t_equals( array( 'deleted' => 0, 'remaining' => 0, 'error' => false ), $repo->wipe_batch( array(), $batch, 5 ), 'no post type, no action' );
t_true( $repo->count_unknown_records( array() ) >= $repo->count_unknown_records( Fotonic_Restore_Writer::post_types() ), 'the count of unknown plugin records never exceeds the count of all of them' );
$pro_like = $repo->insert_post( array( 'post_type' => 'ftnc_wipetest', 'post_status' => 'publish', 'post_title' => 'unknown type' ) );
update_post_meta( $pro_like, Fotonic_Restore_Writer::BATCH_META, $batch );
t_equals( 2, $repo->count_unknown_records( array_merge( array( 'ftnc_customer' ), array() ) ) - $repo->count_unknown_records( array_merge( array( 'ftnc_customer' ), array( 'ftnc_wipetest' ) ) ), 'a record of an unregistered ftnc_ type is counted as unknown data (Pro off; two here: the labelled one kept above and this one)' );

echo "the real records were not touched\n";
t_equals( $real_before, $repo->fingerprint( Fotonic_Restore_Writer::post_types() ), 'the fingerprint of the plugin records is the same as before' );

t_done();
