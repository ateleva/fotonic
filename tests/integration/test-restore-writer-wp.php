<?php
require __DIR__ . '/../lib/wp-bootstrap.php';
// These tests check other behaviour with plain titles. Since phase 15 Pro opens the titles gate on this site, so the gate is
// closed here for the run (the titles themselves are covered by test-titles-wp.php and test-pro-titles.php).
add_filter( 'ftnc_titles_encryptable', '__return_false' );

// The restore writer on the real WordPress database (the unit test uses an in-memory site): the repository that does
// raw $wpdb->insert()/update() and update_post_meta( wp_slash ), the fingerprint query, and undo. Accents, quotes,
// ampersands and backslashes must come back byte for byte. Every record this test creates carries a batch label of its
// own, and a shutdown sweep deletes them even if the script dies. The keys are random: the real Vault key is never used,
// no real record is read for writing, no option is written.

$batch = 'test' . bin2hex( random_bytes( 6 ) );
register_shutdown_function( function () use ( $batch ) {
	$repo = Fotonic_Restore_Writer::repo();
	foreach ( $repo->ids_with_meta( Fotonic_Restore_Writer::BATCH_META, $batch ) as $id ) {
		$repo->delete_post( $id );
	}
} );

$mk  = random_bytes( 32 ); // The "old Vault".
$new = random_bytes( 32 ); // The "site Vault" (random, never the real one).
$W   = 'Fotonic_Restore_Writer';

echo "the fingerprint\n";
$a = $W::repo()->fingerprint( $W::post_types() );
$b = $W::repo()->fingerprint( $W::post_types() );
t_true( 32 === strlen( $a ) && $a === $b, 'two reads of the fingerprint agree (and are not empty)' );
t_true( $W::repo()->count_records( $W::post_types() ) >= 0, 'counting records works' );

echo "write a customer and a work with awkward text\n";
$title  = 'Zoë "Verdi" & Co \\ <b>';
$people = array( array(
	'first_name' => Fotonic_Crypto::encrypt( 'Zoë', $mk ), 'last_name' => Fotonic_Crypto::encrypt( 'Dell\'Èrba \\ "x"', $mk ),
	'email' => Fotonic_Crypto::deterministic_encrypt( 'zoe@example.test', $mk ), 'phone' => Fotonic_Crypto::deterministic_encrypt( '333 000 1111', $mk ), 'is_main' => true,
) );
$cust = array( 'ID' => 9001, 'post_status' => 'publish', 'post_title' => $title, 'meta' => array( '_ftnc_people' => wp_json_encode( $people ), '_ftnc_uid' => '66666666-6666-4666-8666-666666666666' ), 'terms' => array(), 'derived_terms' => array() );
$map  = new Fotonic_Restore_Idmap();
$opts = array( 'action' => 'import', 'scenario' => 'B', 'old_mk' => $mk, 'new_mk' => $new, 'batch' => $batch, 'idmap' => $map, 'user_id' => get_current_user_id() );
$res  = $W::write_record( 'ftnc_customer', $cust, $opts );
t_true( is_array( $res ) && $res['id'] > 0, 'the customer is written' );
$cid = $res['id'];
$map->set( 'ftnc_customer', 9001, $cid );
t_equals( $title, get_post( $cid )->post_title, 'the title comes back byte for byte (quotes, ampersand, backslash, accent)' );
t_equals( 'customer-' . $cid, get_post( $cid )->post_name, 'with a neutral slug' );
t_equals( '66666666-6666-4666-8666-666666666666', get_post_meta( $cid, '_ftnc_uid', true ), 'the uid of the backup' );

$work = array( 'ID' => 9002, 'post_status' => 'publish', 'post_title' => 'Matrimonio è', 'post_content' => Fotonic_Field_Crypt::encrypt( 'Note \\ "è" & più', $mk ),
	'meta' => array(
		'_ftnc_uid' => '77777777-7777-4777-8777-777777777777', '_ftnc_event_date' => '2026-06-01', '_ftnc_customer_id' => '9001', '_ftnc_total_price' => '1500.00',
		'_ftnc_event_addresses' => wp_json_encode( array( array( 'label' => 'Chiesa', 'street' => Fotonic_Crypto::encrypt( 'Via dell\'Èdera 3 \\ B', $mk ) ) ) ),
		'_ftnc_delivery_notes' => Fotonic_Field_Crypt::encrypt( 'Link \\ "scuro" è', $mk ),
		'_ftnc_installments' => '[]',
	), 'terms' => array(), 'derived_terms' => array() );
$res2 = $W::write_record( 'ftnc_work', $work, $opts );
t_true( is_array( $res2 ) && $res2['id'] > 0, 'the work is written' );
$wid = $res2['id'];
t_equals( (string) $cid, get_post_meta( $wid, '_ftnc_customer_id', true ), 'the work points to the new customer' );
t_equals( 'Note \\ "è" & più', Fotonic_Field_Crypt::read( get_post( $wid )->post_content, $new ), 'the notes come back byte for byte, opened with the NEW key' );
$addr = json_decode( get_post_meta( $wid, '_ftnc_event_addresses', true ), true );
t_equals( 'Via dell\'Èdera 3 \\ B', Fotonic_Crypto::decrypt( $addr[0]['street'], $new ), 'an address (JSON with accents) survives wp_slash' );
t_equals( 'unpaid', ( wp_get_object_terms( $wid, 'ftnc_work_payment_status', array( 'fields' => 'slugs' ) )[0] ?? '' ), 'the payment status of the work was assigned' );

echo "read back as the preview would: nothing differs\n";
$registry = Fotonic_Backup_Registry::datasets();
foreach ( array( array( 'ftnc_customer', $cust, $cid ), array( 'ftnc_work', $work, $wid ) ) as $row ) {
	list( $key, $backup, $id ) = $row;
	$dataset = $registry[ $key ];
	$rules   = Fotonic_Restore_Matcher::rules( $key, $dataset );
	$specs   = Fotonic_Restore_Matcher::field_specs( $dataset['post_type'] );
	$site    = array( 'ID' => $id, 'post_status' => get_post( $id )->post_status, 'post_title' => get_post( $id )->post_title, 'post_content' => get_post( $id )->post_content, 'meta' => array(), 'terms' => array() );
	foreach ( $dataset['meta_keys'] as $mkey ) { $site['meta'][ $mkey ] = get_post_meta( $id, $mkey, true ); }
	$refmap  = array( 'ftnc_customer' => array( 9001 => (string) $cid ) );
	$bview   = Fotonic_Restore_Matcher::view( $backup, $dataset, $rules, $specs, $mk, $refmap );
	$sview   = Fotonic_Restore_Matcher::view( $site, $dataset, $rules, $specs, $new, null, array( 'ftnc_customer' => array( $cid => true ) ) );
	t_equals( 'identical', Fotonic_Restore_Matcher::compare( $bview, $sview, $id )['status'], $key . ': the written record reads as identical to the backup record' );
}

echo "undo\n";
t_equals( 'deleted', $W::undo_record( $wid ), 'the work is deleted' );
t_equals( 'deleted', $W::undo_record( $cid ), 'the customer is deleted' );
t_true( null === get_post( $wid ) && null === get_post( $cid ), 'both are gone' );
t_equals( array(), $W::repo()->ids_with_meta( Fotonic_Restore_Writer::BATCH_META, $batch ), 'and nothing carries the batch label' );

t_done();
