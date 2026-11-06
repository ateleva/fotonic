<?php
// Unit test for Fotonic_Restore_Preview (and the Matcher through it), on a real archive and a fake site. No WordPress, no database, never the real Vault key:
// options are in memory, every key is random, the archive is built in a temp folder with the
// plugin's own writer (data layout, key.json) and cipher.
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );
$GLOBALS['t_base'] = sys_get_temp_dir() . '/ftnc-reader-' . bin2hex( random_bytes( 6 ) );
$GLOBALS['t_opts'] = array();
$GLOBALS['t_user'] = 5;
$GLOBALS['t_site_mk'] = null;  // The unlocked Vault of "this site".
$GLOBALS['t_fail'] = array();  // Failed attempts per bucket.
mkdir( $GLOBALS['t_base'] );
register_shutdown_function( function () {
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $GLOBALS['t_base'], RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $it as $f ) { $f->isDir() ? @rmdir( $f->getPathname() ) : @unlink( $f->getPathname() ); }
	@rmdir( $GLOBALS['t_base'] );
} );

function __( $s, $d = null ) { return $s; }
function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['t_opts'] ) ? $GLOBALS['t_opts'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['t_opts'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['t_opts'][ $n ] ); return true; }
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['t_base'] ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function get_current_user_id() { return $GLOBALS['t_user']; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function apply_filters( $tag, $value ) { return $value; }
class WP_Error {
	public $code; public $message; public $data;
	public function __construct( $code = '', $message = '', $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
	public function get_error_data() { return $this->data; }
}
function is_wp_error( $v ) { return $v instanceof WP_Error; }

class Fotonic_Vault {
	public static function get_session_key() { return $GLOBALS['t_site_mk']; }
	public static function is_setup() { return $GLOBALS['t_vault_setup']; }
}
$GLOBALS['t_vault_setup'] = true;
// A fake site database: posts by id.
$GLOBALS['t_posts'] = array();
function wp_delete_file( $f ) { @unlink( $f ); }
function _prime_post_caches( $ids, $a = true, $b = true ) {}
function update_meta_cache( $t, $ids ) {}
function update_object_term_cache( $ids, $t ) {}
function get_post( $id ) { return isset( $GLOBALS['t_posts'][ $id ] ) ? (object) $GLOBALS['t_posts'][ $id ] : null; }
function get_post_meta( $id, $k, $single = true ) { return $GLOBALS['t_posts'][ $id ]['meta'][ $k ] ?? ''; }
function get_the_terms( $id, $tax ) { $out = array(); foreach ( $GLOBALS['t_posts'][ $id ]['terms'][ $tax ] ?? array() as $slug ) { $out[] = (object) array( 'slug' => $slug ); } return $out; }
class FakeWpdb {
	public $posts = 'wp_posts'; private $type = '';
	public function prepare( $q, $args ) { $this->type = $args[0]; return 'Q'; }
	public function get_col( $q ) { $o = array(); foreach ( $GLOBALS['t_posts'] as $id => $p ) { if ( $p['post_type'] === $this->type && 'trash' !== 'zz' ) { $o[] = $id; } } return $o; }
}
$GLOBALS['wpdb'] = new FakeWpdb();
final class Fotonic_Vault_Attempts {
	private $bucket;
	public static function acquire( $bucket ) { $a = new self(); $a->bucket = $bucket; return $a; }
	public function exhausted() { return ( $GLOBALS['t_fail'][ $this->bucket ] ?? 0 ) >= 5; }
	public function failed() { $GLOBALS['t_fail'][ $this->bucket ] = ( $GLOBALS['t_fail'][ $this->bucket ] ?? 0 ) + 1; }
	public function clear() { unset( $GLOBALS['t_fail'][ $this->bucket ] ); }
}

foreach ( array( 'crypto', 'field-crypt', 'vault-fields', 'backup-registry', 'vault-fingerprint', 'backup-keys', 'backup-cipher', 'backup-archive', 'restore-splitter', 'restore-upload', 'restore-reader', 'restore-matcher', 'restore-preview' ) as $f ) {
	require __DIR__ . '/../../includes/class-' . $f . '.php';
}

$R = 'Fotonic_Restore_Reader';
$U = 'Fotonic_Restore_Upload';

// ---------------------------------------------------------------------------
// Build a Vault and a backup the way the plugin does.
// ---------------------------------------------------------------------------

$password = 'correct horse battery';
$mk       = random_bytes( 32 );
$other_mk = random_bytes( 32 );
$salt     = base64_encode( random_bytes( 32 ) );
$code     = Fotonic_Crypto::generate_recovery_code();
$salt_rec = base64_encode( random_bytes( 32 ) );
$phrase   = 'amber tiger ocean violet maple harbor quiet river candle forest meadow lantern';
$salt_ph  = base64_encode( random_bytes( 32 ) );
update_option( 'fotonic_vault_salt', $salt );
update_option( 'fotonic_vault_wrap_pw', Fotonic_Crypto::wrap( $mk, Fotonic_Crypto::derive_key( $password, $salt ) ) );
update_option( 'fotonic_vault_wrap_totp', Fotonic_Crypto::wrap( 'JBSWY3DPEHPK3PXP', $mk ) );
update_option( 'fotonic_vault_salt_rec', $salt_rec );
update_option( 'fotonic_vault_wrap_rec', Fotonic_Crypto::wrap( $mk, Fotonic_Crypto::derive_key( Fotonic_Crypto::normalize_recovery_code( $code ), $salt_rec ) ) );
update_option( 'fotonic_vault_salt_phrase', $salt_ph );
update_option( 'fotonic_vault_wrap_phrase', Fotonic_Crypto::wrap( $mk, Fotonic_Crypto::derive_key( Fotonic_Crypto::normalize_recovery_code( $phrase ), $salt_ph ) ) );
Fotonic_Backup_Keys::setup( $mk );

/** Builds an archive. $mod can change pieces: 'tamper_data', 'tamper_file', 'extra_member', 'format', 'scheme', 'no_key', 'bad_name'. */
function build_archive( $path, $mk, $datasets, $terms, $options, $attachments, $file_body, array $mod = array() ) {
	$ak   = random_bytes( 32 );
	$tmp  = $path . '.work';
	@mkdir( $tmp );

	$ref  = new ReflectionClass( 'Fotonic_Backup_Archive' );
	$w    = $ref->getMethod( 'write_data_json_stream' ); $w->setAccessible( true );
	$k    = $ref->getMethod( 'build_key_json' ); $k->setAccessible( true );

	$w->invoke( null, $tmp . '/data.json', $datasets, $terms, $options, $attachments );
	$in = fopen( $tmp . '/data.json', 'rb' ); $out = fopen( $tmp . '/data.enc', 'wb' );
	Fotonic_Backup_Cipher::encrypt_stream( $in, $out, $ak );
	fclose( $in ); fclose( $out );
	$in = fopen( 'data://application/octet-stream;base64,' . base64_encode( $file_body ), 'rb' ); $out = fopen( $tmp . '/file.enc', 'wb' );
	Fotonic_Backup_Cipher::encrypt_stream( $in, $out, $ak );
	fclose( $in ); fclose( $out );

	$key_json = $k->invoke( null, Fotonic_Backup_Keys::seal( $ak ) );
	if ( isset( $mod['scheme'] ) ) {
		$kd = json_decode( $key_json, true ); $kd['scheme'] = $mod['scheme']; $key_json = json_encode( $kd );
	}
	$members = array(
		array( 'name' => 'data.enc', 'size' => filesize( $tmp . '/data.enc' ), 'sha256' => hash_file( 'sha256', $tmp . '/data.enc' ) ),
		array( 'name' => 'key.json', 'size' => strlen( $key_json ), 'sha256' => hash( 'sha256', $key_json ) ),
		array( 'name' => 'files/5.enc', 'size' => filesize( $tmp . '/file.enc' ), 'sha256' => hash_file( 'sha256', $tmp . '/file.enc' ) ),
	);
	$manifest = array(
		'format' => isset( $mod['format'] ) ? $mod['format'] : 1, 'generated_at' => '2026-11-05T09:00:00+00:00', 'site_url' => 'https://old.example.test',
		'wp_version' => '6.8', 'plugin_version' => '1.6.8', 'pro_version' => null,
		'datasets' => array( 'customer' => 3, 'work' => 1 ), 'members' => $members, 'missing_files' => array(), 'total_size' => 1,
	);
	if ( isset( $mod['bad_name'] ) ) {
		$manifest['members'][] = array( 'name' => '../evil.php', 'size' => 1, 'sha256' => str_repeat( 'a', 64 ) );
	}
	if ( isset( $mod['tamper_data'] ) ) {
		$d = file_get_contents( $tmp . '/data.enc' ); $d[40] = chr( ord( $d[40] ) ^ 1 ); file_put_contents( $tmp . '/data.enc', $d );
	}
	if ( isset( $mod['tamper_file'] ) ) {
		$d = file_get_contents( $tmp . '/file.enc' ); $d[40] = chr( ord( $d[40] ) ^ 1 ); file_put_contents( $tmp . '/file.enc', $d );
	}
	$zip = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFile( $tmp . '/data.enc', 'data.enc' );
	$zip->addFile( $tmp . '/file.enc', 'files/5.enc' );
	if ( ! isset( $mod['no_key'] ) ) { $zip->addFromString( 'key.json', $key_json ); }
	if ( isset( $mod['extra_member'] ) ) { $zip->addFromString( 'files/99.enc', 'unlisted' ); }
	$zip->addFromString( 'manifest.json', json_encode( $manifest ) );
	$zip->close();
	foreach ( glob( $tmp . '/*' ) as $f ) { unlink( $f ); }
	rmdir( $tmp );
	return $ak;
}


// ---------------------------------------------------------------------------
// Data
// ---------------------------------------------------------------------------
$cols = array( 'first_name' => 'v1', 'last_name' => 'v1', 'email' => 'v1d', 'phone' => 'v1d' );
function blob( array $people, ?string $key ): string {
	global $cols;
	foreach ( $people as $i => $p ) {
		foreach ( $cols as $c => $fmt ) {
			if ( null !== $key && isset( $p[ $c ] ) && '' !== $p[ $c ] ) {
				$people[ $i ][ $c ] = Fotonic_Vault_Fields::encrypt_value( $p[ $c ], $fmt, $key );
			}
		}
	}
	return json_encode( $people );
}
function mk_customer( int $id, string $uid, string $title, array $people, ?string $key, string $status = 'publish' ): array {
	return array( 'ID' => $id, 'post_status' => $status, 'post_title' => $title, 'meta' => array( '_ftnc_people' => blob( $people, $key ), '_ftnc_uid' => $uid ), 'terms' => array(), 'derived_terms' => array() );
}
function mk_service( int $id, string $uid, string $title, string $price, ?string $key ): array {
	return array( 'ID' => $id, 'post_status' => 'publish', 'post_title' => null === $key ? $title : Fotonic_Field_Crypt::encrypt( $title, $key ),
		'meta' => array( '_ftnc_base_price' => null === $key ? $price : Fotonic_Field_Crypt::encrypt( $price, $key ), '_ftnc_notes' => '', '_ftnc_uid' => $uid ), 'terms' => array(), 'derived_terms' => array() );
}
function mk_work( int $id, string $uid, int $customer, int $service ): array {
	return array( 'ID' => $id, 'post_status' => 'publish', 'post_title' => 'Matrimonio Verdi', 'post_content' => '',
		'meta' => array( '_ftnc_uid' => $uid, '_ftnc_event_date' => '2026-06-01', '_ftnc_customer_id' => (string) $customer, '_ftnc_total_price' => '1500.00',
			'_ftnc_work_services' => json_encode( array( array( 'service_id' => $service, 'price_override' => '', 'notes_override' => '' ) ) ), '_ftnc_installments' => '[]' ),
		'terms' => array(), 'derived_terms' => array() );
}
$anna    = array( array( 'first_name' => 'Anna', 'last_name' => 'Verdi', 'email' => 'anna@x.it', 'phone' => '333 111 2222', 'is_main' => true ) );
$gianni  = array( array( 'first_name' => 'Gianni', 'last_name' => 'Bianchi', 'email' => 'g@x.it', 'is_main' => true ) );
$luca    = array( array( 'first_name' => 'Luca', 'last_name' => 'Neri', 'email' => 'l@x.it', 'is_main' => true ) );
function backup_set( string $key ): array {
	global $anna, $gianni, $luca;
	return array(
		'ftnc_customer' => array( mk_customer( 11, 'c-11', 'Verdi', $anna, $key ), mk_customer( 12, 'c-12', 'Bianchi', $gianni, $key ), mk_customer( 13, 'c-13', 'Neri', $luca, $key, 'trash' ) ),
		'ftnc_service'  => array( mk_service( 31, 's-31', 'Wedding', '1500', $key ) ),
		'ftnc_work'     => array( mk_work( 21, 'w-21', 11, 31 ) ),
		'ftnc_quote'    => array( array( 'ID' => 41, 'post_status' => 'publish', 'post_title' => 'Q-1', 'meta' => array() ), array( 'ID' => 42, 'post_status' => 'publish', 'post_title' => 'Q-2', 'meta' => array() ) ),
	);
}
/** Put records on the fake site, ids shifted by $shift. A work's customer and service ids are shifted too. */
function put_site( array $records_by_type, int $shift ): void {
	Fotonic_Restore_Preview::flush_cache(); // A real request starts with an empty cache.
	$GLOBALS['t_posts'] = array();
	foreach ( $records_by_type as $type => $records ) {
		foreach ( $records as $r ) {
			$id   = $r['ID'] + $shift;
			$meta = $r['meta'];
			if ( 'ftnc_work' === $type ) {
				$meta['_ftnc_customer_id']   = (string) ( (int) $meta['_ftnc_customer_id'] + $shift );
				$rows                        = json_decode( $meta['_ftnc_work_services'], true );
				$rows[0]['service_id']       = $rows[0]['service_id'] + $shift;
				$meta['_ftnc_work_services'] = json_encode( $rows );
			}
			$GLOBALS['t_posts'][ $id ] = array( 'post_type' => $type, 'post_status' => $r['post_status'], 'post_title' => $r['post_title'], 'post_content' => $r['post_content'] ?? '', 'meta' => $meta, 'terms' => array() );
		}
	}
}
function run_all( string $sid ): array {
	$items = array();
	for ( $i = 0; $i < 60; $i++ ) {
		$r = Fotonic_Restore_Preview::step( $sid );
		if ( is_wp_error( $r ) ) { return array( 'error' => $r ); }
		$items = array_merge( $items, $r['items'] );
		if ( $r['done'] ) { $r['all_items'] = $items; $r['steps'] = $i + 1; return $r; }
	}
	return array( 'error' => 'no end' );
}
function item_for( array $r, string $dataset, int $bid ): ?array {
	foreach ( $r['all_items'] as $it ) { if ( $it['dataset'] === $dataset && $it['backup_id'] === $bid ) { return $it; } }
	return null;
}

$dir = $GLOBALS['t_base'] . '/arch';
mkdir( $dir );
$archive = $dir . '/eleva-backup-test.zip';
$data    = backup_set( $mk );
build_archive( $archive, $mk, $data, array(), array(), array(), '' );
$P = 'Fotonic_Restore_Preview';
$site_key = random_bytes( 32 );

// ---------------------------------------------------------------------------
echo "an empty site\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_site_mk'] = $site_key;
$GLOBALS['t_posts']   = array();
$o   = $R::open( $archive, $password );
t_true( is_array( $o ), 'the backup opens (other Vault, site unlocked)' );
$sid = $o['session_id'];
$r   = run_all( $sid );
t_true( ! isset( $r['error'] ) && $r['done'], 'the preview runs to the end' );
t_equals( array( 'new' => 5, 'identical' => 0, 'different' => 0, 'possible' => 0, 'unimportable' => 2 ), $r['counts'], 'every record is new; the unknown dataset is unimportable' );
t_equals( array( array( 'dataset' => 'ftnc_quote', 'count' => 2 ) ), $r['unimportable'], 'the unknown dataset is listed by name' );
t_equals( 'new', item_for( $r, 'ftnc_work', 21 )['status'] ?? '', 'the work is new' );
t_equals( 'Verdi', item_for( $r, 'ftnc_customer', 11 )['title'] ?? '', 'the item carries the title' );

$plan = $P::plan( $sid );
t_equals( 3, count( $plan['ftnc_customer'] ), 'the plan has a line per customer' );
$plan_files = '';
foreach ( glob( $R::work_dir( $sid ) . '/plan-*.jsonl' ) as $f ) { $plan_files .= file_get_contents( $f ); }
t_true( '' !== $plan_files && false === strpos( $plan_files, 'Verdi' ) && false === strpos( $plan_files, 'anna' ) && false === strpos( $plan_files, 'Wedding' ), 'the plan files hold no readable data' );
$R::close( $sid );

// ---------------------------------------------------------------------------
echo "the site already has everything (same backup imported again)\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_site_mk'] = $mk;
put_site( backup_set( $mk ), 1000 );
$o   = $R::open( $archive );
t_true( is_array( $o ) && true === $o['same_vault'], 'same Vault opens with no credential' );
$sid = $o['session_id'];
$r   = run_all( $sid );
t_equals( array( 'new' => 0, 'identical' => 5, 'different' => 0, 'possible' => 0, 'unimportable' => 2 ), $r['counts'], 'all identical, nothing new' );
t_equals( array(), $r['all_items'], 'identical records are not listed' );
$plan = $P::plan( $sid );
t_equals( 1011, $plan['ftnc_customer'][0]['i'], 'the plan maps backup customer 11 to site 1011' );
t_equals( 1021, $plan['ftnc_work'][0]['i'], 'and the work to 1021' );
$r2 = $P::step( $sid, true );
t_equals( 3, $r2['counts']['identical'], 'restart forgets the plan (only the first batch, the 3 customers, is done again)' );
$r3 = run_all( $sid );
t_equals( 5, $r3['counts']['identical'], 'and the second run gives the same result' );
$R::close( $sid );

// ---------------------------------------------------------------------------
echo "a customer changed on the site\n";
// ---------------------------------------------------------------------------
$set = backup_set( $mk );
$set['ftnc_customer'][1] = mk_customer( 12, 'c-12', 'Bianchi', array( array( 'first_name' => 'Gianni', 'last_name' => 'Bianchi', 'email' => 'g@x.it', 'phone' => '0212345678', 'is_main' => true ) ), $mk );
put_site( $set, 1000 );
$o   = $R::open( $archive );
$sid = $o['session_id'];
$r   = run_all( $sid );
t_equals( array( 'new' => 0, 'identical' => 4, 'different' => 1, 'possible' => 0, 'unimportable' => 2 ), $r['counts'], 'one customer is different' );
$it = item_for( $r, 'ftnc_customer', 12 );
t_equals( 'different', $it['status'] ?? '', 'the item is different' );
t_equals( 1012, $it['site_id'] ?? 0, 'with its site id' );
t_equals( '_ftnc_people', $it['diff'][0]['field'] ?? '', 'the field is named' );
t_true( true === ( $it['diff'][0]['personal'] ?? false ), 'and marked personal' );
t_true( false !== strpos( (string) ( $it['diff'][0]['backup'] ?? '' ), 'Gianni' ) && false !== strpos( (string) ( $it['diff'][0]['site'] ?? '' ), '0212345678' ), 'both values are shown in plain text' );
$R::close( $sid );

// ---------------------------------------------------------------------------
echo "batches and resuming\n";
// ---------------------------------------------------------------------------
$P::set_batch_size( 2 );
$o   = $R::open( $archive );
$sid = $o['session_id'];
$a   = $P::step( $sid );
t_true( false === $a['done'] && 2 === $a['processed'], 'a small batch is not the end' );
t_equals( array( 'dataset' => 'ftnc_customer', 'done' => 2, 'total' => 3 ), $a['phase'], 'the progress is reported' );
$a = $P::step( $sid );
t_equals( 'ftnc_customer', $a['phase']['dataset'], 'the next call continues the same dataset' );
$r = run_all( $sid );
t_equals( array( 'new' => 0, 'identical' => 4, 'different' => 1, 'possible' => 0, 'unimportable' => 2 ), $r['counts'], 'same totals as in one go' );
$P::set_batch_size( 250 );
$R::close( $sid );

// ---------------------------------------------------------------------------
echo "a duplicate in the backup is possible, not a second identical\n";
// ---------------------------------------------------------------------------
$dup = backup_set( $mk );
$dup['ftnc_customer'][] = mk_customer( 14, '', 'Verdi', $anna, $mk );
$dup_archive = $dir . '/eleva-backup-dup.zip';
build_archive( $dup_archive, $mk, $dup, array(), array(), array(), '' );
put_site( backup_set( $mk ), 1000 );
$o   = $R::open( $dup_archive );
$sid = $o['session_id'];
$r   = run_all( $sid );
t_equals( 'possible', item_for( $r, 'ftnc_customer', 14 )['status'] ?? '', 'the second record for the same site customer is a possible duplicate' );
t_equals( 5, $r['counts']['identical'], 'the other records stay identical' );
t_equals( 1, $r['counts']['possible'], 'and exactly one is possible' );
$R::close( $sid );

// ---------------------------------------------------------------------------
echo "the shape of the real backups of 30 October: no uid in the backup, dangling references\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_site_mk'] = $mk;
$old = backup_set( $mk );
foreach ( $old as $type => $records ) {
	foreach ( $records as $i => $rec ) { $old[ $type ][ $i ]['meta']['_ftnc_uid'] = ''; }
}
$w22 = mk_work( 22, '', 99, 98 ); // Customer 99 and service 98 are in no dataset: they were deleted for good.
$w22['post_title'] = 'Battesimo';
$old['ftnc_work'][] = $w22;
unset( $old['ftnc_quote'] );
$old_archive = $dir . '/eleva-backup-old.zip';
build_archive( $old_archive, $mk, $old, array(), array(), array(), '' );
$site_set = backup_set( $mk ); // The site has uids (the backfill of 30 October).
$site_set['ftnc_work'][] = mk_work( 22, 'w-22', 99, 98 );
$site_set['ftnc_work'][1]['post_title'] = 'Battesimo';
put_site( $site_set, 1000 ); // The shift also moves 99 and 98 to 1099 and 1098: records that do not exist on the site either.
$o   = $R::open( $old_archive );
$sid = $o['session_id'];
$r   = run_all( $sid );
t_equals( array( 'new' => 0, 'identical' => 6, 'different' => 0, 'possible' => 0, 'unimportable' => 0 ), $r['counts'], 'without uids, heuristics only: every record is identical, nothing would be added again' );
t_equals( array(), $r['all_items'], 'and nothing is listed' );
$R::close( $sid );

// ---------------------------------------------------------------------------
echo "keys of the site\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_site_mk'] = null; // Vault set up, locked.
$o   = $R::open( $archive, $password );
$sid = $o['session_id'];
$e   = $P::step( $sid );
t_equals( 'vault_locked', $e->code ?? '', 'a locked Vault of the site cannot be compared' );
t_equals( 423, $e->data['status'] ?? 0, 'status 423' );
$GLOBALS['t_vault_setup'] = false; // No Vault at all: plain site records.
$plain = array(
	'ftnc_customer' => array( mk_customer( 11, 'c-11', 'Verdi', $anna, null ), mk_customer( 12, 'c-12', 'Bianchi', $gianni, null ), mk_customer( 13, 'c-13', 'Neri', $luca, null, 'trash' ) ),
	'ftnc_service'  => array( mk_service( 31, 's-31', 'Wedding', '1500', null ) ),
	'ftnc_work'     => array( mk_work( 21, 'w-21', 11, 31 ) ),
);
put_site( $plain, 1000 );
$r = run_all( $sid );
t_equals( array( 'new' => 0, 'identical' => 5, 'different' => 0, 'possible' => 0, 'unimportable' => 2 ), $r['counts'], 'a site without a Vault compares its plain records with the decrypted backup' );
$GLOBALS['t_vault_setup'] = true;
$R::close( $sid );

t_equals( 'ftnc_restore_session_expired', $P::step( $sid )->code ?? '', 'a closed session is refused' );

t_done();
