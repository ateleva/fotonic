<?php
// Unit test for Fotonic_Restore_Matcher. No WordPress, no database, never the real Vault key:
// every key is random and the "site" is an in-memory index.
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );
function __( $s, $d = null ) { return $s; }
function apply_filters( $tag, $value ) { return isset( $GLOBALS['t_filters'][ $tag ] ) ? call_user_func( $GLOBALS['t_filters'][ $tag ], $value ) : $value; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }

foreach ( array( 'crypto', 'field-crypt', 'vault-fields', 'backup-registry', 'restore-matcher' ) as $f ) {
	require __DIR__ . '/../../includes/class-' . $f . '.php';
}

$M        = 'Fotonic_Restore_Matcher';
$site_key = random_bytes( 32 );
$old_key  = random_bytes( 32 );
$datasets = Fotonic_Backup_Registry::datasets();

/** Personal fields the way the plugin stores them. */
function people_blob( array $people, string $key ): string {
	$cols = array( 'first_name' => 'v1', 'last_name' => 'v1', 'nationality' => 'v1', 'instagram_username' => 'v1', 'address' => 'v1', 'tin' => 'v1', 'email' => 'v1d', 'phone' => 'v1d' );
	foreach ( $people as $i => $p ) {
		foreach ( $cols as $c => $fmt ) {
			if ( isset( $p[ $c ] ) && '' !== $p[ $c ] ) {
				$people[ $i ][ $c ] = Fotonic_Vault_Fields::encrypt_value( $p[ $c ], $fmt, $key );
			}
		}
	}
	return json_encode( $people );
}

function customer( int $id, string $uid, string $title, array $people, ?string $key ): array {
	return array(
		'ID' => $id, 'post_status' => 'publish', 'post_title' => $title,
		'meta' => array( '_ftnc_people' => null === $key ? json_encode( $people ) : people_blob( $people, $key ), '_ftnc_uid' => $uid ),
		'terms' => array(), 'derived_terms' => array(),
	);
}

function service( int $id, string $uid, string $title, string $price, ?string $key ): array {
	return array(
		'ID' => $id, 'post_status' => 'publish',
		'post_title' => null === $key ? $title : Fotonic_Field_Crypt::encrypt( $title, $key ),
		'meta' => array( '_ftnc_base_price' => null === $key ? $price : Fotonic_Field_Crypt::encrypt( $price, $key ), '_ftnc_notes' => '', '_ftnc_uid' => $uid ),
		'terms' => array(), 'derived_terms' => array(),
	);
}

function work( int $id, string $uid, string $title, string $date, $customer_id, string $extra_files = '[]', string $owner = '1' ): array {
	return array(
		'ID' => $id, 'post_status' => 'publish', 'post_title' => $title, 'post_content' => '',
		'meta' => array(
			'_ftnc_uid' => $uid, '_ftnc_event_date' => $date, '_ftnc_customer_id' => (string) $customer_id,
			'_ftnc_owner_id' => $owner, '_ftnc_work_files' => $extra_files, '_ftnc_total_price' => '1200',
			'_ftnc_work_services' => '[]', '_ftnc_installments' => '[]',
		),
		'terms' => array(), 'derived_terms' => array( 'ftnc_work_payment_status' => array( array( 'term_id' => 3, 'slug' => 'unpaid' ) ) ),
	);
}

/** Build the site side for a dataset: index + a loader. */
function make_site( string $dataset_key, array $records, ?string $key, ?array $ds_override = null, ?array $exist = null ): array {
	global $datasets;
	$ds    = null !== $ds_override ? $ds_override : $datasets[ $dataset_key ];
	$rules = Fotonic_Restore_Matcher::rules( $dataset_key, $ds );
	$specs = Fotonic_Restore_Matcher::field_specs( $ds['post_type'] );
	$index = Fotonic_Restore_Matcher::new_index();
	$views = array();
	foreach ( $records as $r ) {
		$v = Fotonic_Restore_Matcher::view( $r, $ds, $rules, $specs, $key, null, $exist );
		$views[ $r['ID'] ] = $v;
		Fotonic_Restore_Matcher::index_add( $index, $r['ID'], $v, $rules );
	}
	return array( 'index' => $index, 'exist' => $exist, 'load' => function ( $id ) use ( $views ) { return $views[ $id ]; }, 'rules' => $rules, 'specs' => $specs, 'ds' => $ds );
}

function backup_view( array $site, array $record, ?string $key, array $refmap = array() ): array {
	return Fotonic_Restore_Matcher::view( $record, $site['ds'], $site['rules'], $site['specs'], $key, $refmap );
}

function run( array $site, array $record, ?string $key, array $claimed = array(), array $refmap = array() ): array {
	return Fotonic_Restore_Matcher::classify( $site['rules'], backup_view( $site, $record, $key, $refmap ), $site['index'], $site['load'], $claimed );
}

$ann = array( array( 'first_name' => 'Anna', 'last_name' => 'Verdi', 'email' => 'Anna@Example.com', 'phone' => '+39 333 111 2222', 'is_main' => true, 'ref' => 0 ) );

// ---------------------------------------------------------------------------
echo "Reading one value\n";
t_equals( 'plain', $M::read_value( 'plain', 'f1', $site_key ), 'plain f1 value stays' );
t_equals( 'plain', $M::read_value( Fotonic_Field_Crypt::encrypt( 'plain', $site_key ), 'f1', $site_key ), 'f1 ciphertext opens' );
t_equals( null, $M::read_value( Fotonic_Field_Crypt::encrypt( 'plain', $old_key ), 'f1', $site_key ), 'f1 of another key is unreadable' );
t_equals( 'Mario', $M::read_value( Fotonic_Crypto::encrypt( 'Mario', $old_key ), 'v1', $old_key ), 'CBC ciphertext opens' );
t_equals( null, $M::read_value( Fotonic_Crypto::encrypt( 'Mario', $old_key ), 'v1', $site_key ), 'CBC of another key is unreadable (never base64 noise)' );
t_equals( null, $M::read_value( Fotonic_Crypto::deterministic_encrypt( 'a@b.it', $old_key ), 'v1d', $site_key ), 'v1d of another key is unreadable' );
t_equals( 'short text', $M::read_value( 'short text', 'v1', $site_key ), 'plain CBC-format value stays' );
t_equals( 'Mario', $M::read_value( 'Mario', 'v1', null ), 'no Vault: plain stays' );
$long = base64_encode( str_repeat( 'x', 30 ) );
t_equals( $long, $M::read_value( $long, 'v1', $site_key ), 'plain base64-looking text (not CBC shape) stays plain' );
t_equals( null, $M::read_value( 'v2:abcdefghijklmnopqrstuvwxyz', 'v1', $site_key ), 'browser-side layer is unreadable' );
t_equals( '1200', $M::scalar( '1200.00' ), 'number 1200.00 = 1200' );
t_equals( '1200.5', $M::scalar( '1200.50' ), 'number 1200.50 = 1200.5' );
t_equals( '00100', $M::scalar( '00100' ), 'a value with a leading zero stays text' );

// ---------------------------------------------------------------------------
echo "Customers: uid\n";
$site = make_site( 'ftnc_customer', array( customer( 100, 'uid-a', 'Verdi', $ann, $site_key ) ), $site_key );
$r = run( $site, customer( 7, 'uid-a', 'Verdi', $ann, $old_key ), $old_key );
t_equals( 'identical', $r['status'], 'same uid, same content (different keys, random IVs) is identical' );
t_equals( 100, $r['site_id'], 'site id returned' );

$ann2 = $ann; $ann2[0]['phone'] = '+39 333 999 0000';
$r = run( $site, customer( 7, 'uid-a', 'Verdi', $ann2, $old_key ), $old_key );
t_equals( 'different', $r['status'], 'same uid, other phone is different' );
t_equals( 'personal', $r['diff'][0]['personal'] ? 'personal' : 'plain', 'a personal field is marked personal' );
t_equals( '_ftnc_people', $r['diff'][0]['field'], 'the field name is in the diff' );

$r = run( $site, customer( 7, 'uid-b', 'Nuovo', array( array( 'first_name' => 'Luca', 'last_name' => 'Neri', 'email' => 'luca@x.it', 'is_main' => true ) ), $old_key ), $old_key );
t_equals( 'new', $r['status'], 'unknown uid, nothing in common is new' );

echo "Customers: no uid in the backup (the real backups of 30 October)\n";
$r = run( $site, customer( 7, '', 'Verdi', $ann, $old_key ), $old_key );
t_equals( 'identical', $r['status'], 'site has a uid, backup has none, same content is identical' );
$ann3 = $ann; $ann3[0]['email'] = ' ANNA@example.com ';
$r = run( $site, customer( 7, '', 'Verdi', $ann3, $old_key ), $old_key );
t_equals( 'identical', $r['status'], 'email compared lower case and trimmed' );
$ann4 = $ann; $ann4[0]['phone'] = '+39 333-111-2222'; $ann4[0]['email'] = 'other@x.it';
$r = run( $site, customer( 7, '', 'Verdi', $ann4, $old_key ), $old_key );
t_equals( 'different', $r['status'], 'same phone (digits) found, other email is different' );

$r = run( $site, customer( 7, '', 'Verdi', array( array( 'first_name' => 'Anna', 'last_name' => 'Verdi', 'email' => 'anna.verdi@else.it', 'is_main' => true ) ), $old_key ), $old_key );
t_equals( 'new', $r['status'], 'same title and name but different emails on both sides is new' );

// Same name, backup has no contact details at all.
$r = run( $site, customer( 7, '', 'Verdi', array( array( 'first_name' => 'Anna', 'last_name' => 'Verdi', 'is_main' => true ) ), $old_key ), $old_key );
t_equals( 'possible', $r['status'], 'weak hit with a different content is a possible duplicate' );

// A second person of the family matches too.
$fam = array( array( 'first_name' => 'Gianni', 'last_name' => 'Bianchi', 'email' => 'g@x.it', 'is_main' => true ), array( 'first_name' => 'Rita', 'last_name' => 'Bianchi', 'email' => 'rita@x.it' ) );
$site_f = make_site( 'ftnc_customer', array( customer( 200, '', 'Bianchi', $fam, $site_key ) ), $site_key );
$r = run( $site_f, customer( 9, '', 'Bianchi', array( array( 'first_name' => 'Rita', 'last_name' => 'Bianchi', 'email' => 'RITA@x.it', 'is_main' => true ) ), $old_key ), $old_key );
t_equals( 'different', $r['status'], 'any person email matches (found, content differs)' );
t_equals( 200, $r['site_id'], 'matched on the second person' );

echo "Customers: uids that disagree, claims, several candidates\n";
$site_u = make_site( 'ftnc_customer', array( customer( 100, 'uid-site', 'Verdi', $ann, $site_key ) ), $site_key );
$r = run( $site_u, customer( 7, 'uid-other', 'Verdi', $ann, $old_key ), $old_key );
t_equals( 'possible', $r['status'], 'backup uid not on the site but heuristic hit: possible, never identical' );
$r = run( $site, customer( 7, '', 'Verdi', $ann, $old_key ), $old_key, array( 100 ) );
t_equals( 'possible', $r['status'], 'a site record already claimed: the second backup record is possible' );
$r = run( $site, customer( 7, 'uid-a', 'Verdi', $ann, $old_key ), $old_key, array( 100 ) );
t_equals( 'possible', $r['status'], 'same uid but already claimed: possible' );
$twice = make_site( 'ftnc_customer', array( customer( 1, 'u1', 'Verdi', $ann, $site_key ), customer( 2, 'u2', 'Verdi', $ann, $site_key ) ), $site_key );
$r = run( $twice, customer( 7, '', 'Verdi', $ann, $old_key ), $old_key );
t_equals( 'possible', $r['status'], 'two site candidates: possible' );
t_equals( 2, count( $r['candidates'] ), 'both candidates listed' );

echo "Site without a Vault, backup from a Vault\n";
$site_plain = make_site( 'ftnc_customer', array( customer( 100, '', 'Verdi', $ann, null ) ), null );
$r = run( $site_plain, customer( 7, '', 'Verdi', $ann, $old_key ), $old_key );
t_equals( 'identical', $r['status'], 'plain site record equals the decrypted backup record' );

echo "Unreadable values\n";
$bad = customer( 7, 'uid-a', 'Verdi', $ann, random_bytes( 32 ) ); // Not the key we are given.
$r = run( $site, $bad, $old_key );
t_true( 'different' === $r['status'] && isset( $r['diff'][0]['unreadable'] ) && 'backup' === $r['diff'][0]['unreadable'], 'unreadable backup value is never identical and is flagged' );
$site_bad = make_site( 'ftnc_customer', array( customer( 100, 'uid-a', 'Verdi', $ann, random_bytes( 32 ) ) ), $site_key );
$r = run( $site_bad, customer( 7, 'uid-a', 'Verdi', $ann, $old_key ), $old_key );
t_true( 'different' === $r['status'] && 'site' === ( $r['diff'][0]['unreadable'] ?? '' ), 'unreadable site value is flagged' );

// ---------------------------------------------------------------------------
echo "Services\n";
$site_s = make_site( 'ftnc_service', array( service( 50, 'sv-1', 'Wedding Full', '1500', $site_key ) ), $site_key );
$r = run( $site_s, service( 3, '', 'Wedding Full', '1500.00', null ), null );
t_equals( 'identical', $r['status'], 'plain backup title and price against f1 site: identical (plain against f1, number form)' );
$r = run( $site_s, service( 3, '', 'Wedding Full', '1800', $old_key ), $old_key );
t_equals( 'different', $r['status'], 'same title, other price is different' );
$r = run( $site_s, service( 3, '', 'Engagement', '300', $old_key ), $old_key );
t_equals( 'new', $r['status'], 'other title is new' );
$a = Fotonic_Field_Crypt::encrypt( 'Wedding Full', $old_key );
$b = Fotonic_Field_Crypt::encrypt( 'Wedding Full', $old_key );
t_true( $a !== $b, 'precondition: two encryptions of the same text differ' );

// ---------------------------------------------------------------------------
echo "Works\n";
$site_w = make_site( 'ftnc_work', array( work( 900, 'w-1', 'Matrimonio Verdi', '2026-06-01', 100, json_encode( array( array( 'type' => 'attachment', 'id' => 55, 'label' => 'Contratto' ) ) ), '1' ) ), null );
$bw = work( 12, '', 'Matrimonio Verdi', '2026-06-01', 7, json_encode( array( array( 'type' => 'attachment', 'id' => 8, 'label' => 'Contratto' ) ) ), '99' );
$r = run( $site_w, $bw, null, array(), array( 'ftnc_customer' => array( 7 => '100' ) ) );
t_equals( 'identical', $r['status'], 'other attachment id and owner id, derived terms: identical' );
$bw['meta']['_ftnc_total_price'] = '1500';
$r = run( $site_w, $bw, null, array(), array( 'ftnc_customer' => array( 7 => '100' ) ) );
t_equals( 'different', $r['status'], 'same date, title and customer, other price: different' );
$r = run( $site_w, $bw, null, array(), array( 'ftnc_customer' => array( 7 => 'new:7' ) ) );
t_equals( 'new', $r['status'], 'customer is new: the work is new' );
$r = run( $site_w, $bw, null, array(), array( 'ftnc_customer' => array( 7 => 'possible:7' ) ) );
t_equals( 'possible', $r['status'], 'customer is possible: the work is at most possible' );

echo "Dangling references, empty values, datasets without rules\n";
$exist = array( 'ftnc_customer' => array( 100 => true ), 'ftnc_service' => array() );
$gone  = work( 900, 'w-1', 'Orfano', '2026-07-01', 5555 ); // Customer 5555 was emptied from the trash on the site.
$gone['meta']['_ftnc_work_services'] = json_encode( array( array( 'service_id' => 777, 'price_override' => '', 'notes_override' => '' ) ) );
$site_o = make_site( 'ftnc_work', array( $gone ), null, null, $exist );
$bk = work( 12, '', 'Orfano', '2026-07-01', 8 ); // Customer 8 is not in the backup.
$bk['meta']['_ftnc_work_services'] = json_encode( array( array( 'service_id' => 66, 'price_override' => '', 'notes_override' => '' ) ) );
$r = run( $site_o, $bk, null, array(), array( 'ftnc_customer' => array(), 'ftnc_service' => array() ) );
t_equals( 'identical', $r['status'], 'a reference to a record that is gone on both sides is no reference: identical, not new' );
$site_e = make_site( 'ftnc_work', array( work( 900, 'w-1', 'Vuoto', '2026-07-01', 100 ) ), null, null, $exist );
$be = work( 12, '', 'Vuoto', '2026-07-01', 7 );
$be['meta']['_ftnc_work_files'] = ''; // An older backup stores nothing where the site stores '[]'.
$r = run( $site_e, $be, null, array(), array( 'ftnc_customer' => array( 7 => '100' ) ) );
t_equals( 'identical', $r['status'], "'' and '[]' are the same empty value" );
$extra = array( 'post_type' => 'ftnc_extra', 'supports' => array( 'title' ), 'meta_keys' => array( '_ftnc_x' ), 'taxonomies' => array(), 'ref_fields' => array() );
$rec = function ( $id, $title, $x ) { return array( 'ID' => $id, 'post_status' => 'publish', 'post_title' => $title, 'meta' => array( '_ftnc_x' => $x ), 'terms' => array() ); };
$site_x = make_site( 'ftnc_extra', array( $rec( 5, 'Pacchetto A', '10' ) ), null, $extra );
t_equals( array( 'title' ), $site_x['rules']['weak'], 'a dataset with no rules and a title gets the title as weak key' );
t_equals( 'identical', run( $site_x, $rec( 1, 'Pacchetto A', '10.00' ), null )['status'], 'same title and content (number written another way): identical' );
t_equals( 'possible', run( $site_x, $rec( 1, 'pacchetto a', '10' ), null )['status'], 'title found in another case, content not equal: possible' );
t_equals( 'possible', run( $site_x, $rec( 1, 'Pacchetto A', '99' ), null )['status'], 'same title, other content: possible duplicate, not new' );
t_equals( 'new', run( $site_x, $rec( 1, 'Altro', '10' ), null )['status'], 'other title: new' );

echo "Registry and filter\n";
t_true( ! empty( $datasets['ftnc_work']['identity']['refs'] ), 'work declares its references' );
$GLOBALS['t_filters']['ftnc_restore_identity_rules'] = function ( $all ) { foreach ( $all as $k => $v ) { $all[ $k ]['ignore'][] = '_ftnc_total_price'; } return $all; };
$rules2 = $M::rules( 'ftnc_work', $datasets['ftnc_work'] );
t_true( in_array( '_ftnc_total_price', $rules2['ignore'], true ), 'filter ftnc_restore_identity_rules changes the rules' );
unset( $GLOBALS['t_filters']['ftnc_restore_identity_rules'] );
$rules3 = $M::rules( 'ftnc_unknown', array() );
t_equals( array(), $rules3['strong'], 'a dataset without rules has empty rule lists' );

echo "a handler describes a field the generic engine cannot (phase 12)\n";
$h_rules = $M::rules( 'ftnc_unknown', array() );
$h_rules['handlers']['_ftnc_x'] = array( 'view' => function ( $raw, $env ) { return array( 'value' => array( 'n' => (string) count( (array) json_decode( $raw, true ) ) ), 'dep' => true ); } );
$h_view = $M::view( array( 'ID' => 1, 'meta' => array( '_ftnc_x' => '[1,2,3]' ) ), array( 'meta_keys' => array( '_ftnc_x' ), 'supports' => array() ), $h_rules, array( 'post' => array(), 'meta' => array(), 'rows' => array() ), null, array() );
t_equals( array( 'n' => '3' ), $h_view['fields']['_ftnc_x'], 'the handler gives the value of the field' );
t_true( $h_view['possible_dep'], 'and can say the record depends on a possible match' );
t_equals( array(), $M::rules( 'ftnc_unknown', array() )['after'], 'a dataset has no extra dependency by default' );

t_done();
