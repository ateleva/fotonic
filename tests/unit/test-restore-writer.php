<?php
// Unit test for the restore writer and job (Fotonic_Restore_Writer, _Job, _Idmap), end to end: a real archive, the real preview, an in-memory site. No WordPress, no database, never the real Vault key:
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
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['t_base'], 'path' => $GLOBALS['t_base'] . '/2026/11', 'subdir' => '/2026/11' ); }
function wp_mkdir_p( $d ) { return is_dir( $d ) || mkdir( $d, 0777, true ); }
function do_action( $tag, ...$a ) {}
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function get_current_user_id() { return $GLOBALS['t_user']; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function apply_filters( $tag, $value ) { return isset( $GLOBALS['t_filters'][ $tag ] ) ? call_user_func( $GLOBALS['t_filters'][ $tag ], $value ) : $value; }
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
final class Fotonic_Vault_Encrypt_Existing { public static function job() { return $GLOBALS['t_enc_job'] ?? null; } }
// A fake site database: posts by id.
$GLOBALS['t_posts'] = array();
function wp_delete_file( $f ) { @unlink( $f ); }
function wp_basename( $p ) { return basename( $p ); }
function sanitize_file_name( $n ) { return preg_replace( '/[^A-Za-z0-9._-]/', '-', $n ); }
function wp_check_filetype( $n ) {
	$ext = strtolower( pathinfo( $n, PATHINFO_EXTENSION ) );
	$map = array( 'pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'txt' => 'text/plain', 'zip' => 'application/zip' );
	return isset( $map[ $ext ] ) ? array( 'ext' => $ext, 'type' => $map[ $ext ] ) : array( 'ext' => false, 'type' => false );
}
function wp_unique_filename( $dir, $name ) { $i = 0; $out = $name; while ( file_exists( $dir . '/' . $out ) ) { $i++; $out = pathinfo( $name, PATHINFO_FILENAME ) . '-' . $i . '.' . pathinfo( $name, PATHINFO_EXTENSION ); } return $out; }
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

function wp_generate_password( $length = 12, $special = true, $extra = false ) { return substr( bin2hex( random_bytes( (int) ceil( $length / 2 ) ) ), 0, $length ); }
function wp_strip_all_tags( $v ) { return trim( strip_tags( (string) $v ) ); }
foreach ( array( 'uploads', 'work-files', 'crypto', 'field-crypt', 'vault-fields', 'backup-registry', 'vault-fingerprint', 'backup-keys', 'backup-cipher', 'backup-archive', 'restore-splitter', 'restore-upload', 'restore-reader', 'restore-matcher', 'restore-preview', 'record-uid', 'file-crypt', 'restore-idmap', 'restore-files', 'restore-wipe', 'restore-writer', 'restore-job' ) as $f ) {
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

	$extra_files = array();
	foreach ( (array) ( $mod['files'] ?? array() ) as $fid => $body ) {
		$in = fopen( 'data://application/octet-stream;base64,' . base64_encode( $body ), 'rb' ); $out = fopen( $tmp . '/f' . $fid . '.enc', 'wb' );
		Fotonic_Backup_Cipher::encrypt_stream( $in, $out, $ak );
		fclose( $in ); fclose( $out );
		$extra_files[ $fid ] = $tmp . '/f' . $fid . '.enc';
	}
	$key_json = $k->invoke( null, Fotonic_Backup_Keys::seal( $ak ) );
	if ( isset( $mod['scheme'] ) ) {
		$kd = json_decode( $key_json, true ); $kd['scheme'] = $mod['scheme']; $key_json = json_encode( $kd );
	}
	$members = array(
		array( 'name' => 'data.enc', 'size' => filesize( $tmp . '/data.enc' ), 'sha256' => hash_file( 'sha256', $tmp . '/data.enc' ) ),
		array( 'name' => 'key.json', 'size' => strlen( $key_json ), 'sha256' => hash( 'sha256', $key_json ) ),
		array( 'name' => 'files/5.enc', 'size' => filesize( $tmp . '/file.enc' ), 'sha256' => hash_file( 'sha256', $tmp . '/file.enc' ) ),
	);
	if ( isset( $mod['files'] ) ) {
		array_pop( $members );
		foreach ( $extra_files as $fid => $f ) { $members[] = array( 'name' => 'files/' . $fid . '.enc', 'size' => filesize( $f ), 'sha256' => hash_file( 'sha256', $f ) ); }
	}
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
	if ( isset( $mod['files'] ) ) {
		foreach ( $extra_files as $fid => $f ) {
			if ( in_array( $fid, (array) ( $mod['tamper_files'] ?? array() ), true ) ) { $d = file_get_contents( $f ); $d[40] = chr( ord( $d[40] ) ^ 1 ); file_put_contents( $f, $d ); }
			$zip->addFile( $f, 'files/' . $fid . '.enc' );
		}
	} else {
		$zip->addFile( $tmp . '/file.enc', 'files/5.enc' );
	}
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

// ---------------------------------------------------------------------------
// The in-memory site the writer works on (the same store the preview reads).
// ---------------------------------------------------------------------------
final class MemRepo {
	public $next = 5000;
	public $inserts = 0;
	public function insert_post( array $f ): int {
		$id = $this->next++;
		$this->inserts++;
		$GLOBALS['t_posts'][ $id ] = array( 'post_type' => $f['post_type'], 'post_status' => $f['post_status'], 'post_title' => $f['post_title'], 'post_content' => $f['post_content'] ?? '', 'post_name' => '', 'meta' => array(), 'terms' => array() );
		return $id;
	}
	public function get_post_fields( int $id ): ?array {
		$p = $GLOBALS['t_posts'][ $id ] ?? null;
		return null === $p ? null : array( 'post_type' => $p['post_type'], 'post_status' => $p['post_status'], 'post_title' => $p['post_title'], 'post_content' => $p['post_content'], 'post_name' => $p['post_name'] ?? '' );
	}
	public function update_post_fields( int $id, array $f ): bool {
		foreach ( $f as $k => $v ) { $GLOBALS['t_posts'][ $id ][ $k ] = $v; }
		return true;
	}
	public function get_meta( int $id, string $k ): ?string { return array_key_exists( $k, $GLOBALS['t_posts'][ $id ]['meta'] ?? array() ) ? (string) $GLOBALS['t_posts'][ $id ]['meta'][ $k ] : null; }
	public $fail_at = 0;
	public $sets = 0;
	public function set_meta( int $id, string $k, string $v ): void {
		if ( $this->fail_at > 0 && ++$this->sets === $this->fail_at ) { $this->fail_at = 0; throw new RuntimeException( 'simulated crash' ); }
		$GLOBALS['t_posts'][ $id ]['meta'][ $k ] = $v;
	}
	public function delete_meta( int $id, string $k ): void { unset( $GLOBALS['t_posts'][ $id ]['meta'][ $k ] ); }
	public function get_terms( int $id, string $t ): array { return $GLOBALS['t_posts'][ $id ]['terms'][ $t ] ?? array(); }
	public function set_terms( int $id, string $t, array $slugs ): void { $GLOBALS['t_posts'][ $id ]['terms'][ $t ] = $slugs; }
	public function delete_post( int $id ): bool { unset( $GLOBALS['t_posts'][ $id ] ); return true; }
	public function ids_with_meta( string $k, string $v, int $limit = 0 ): array {
		$out = array();
		foreach ( $GLOBALS['t_posts'] as $id => $p ) { if ( ( $p['meta'][ $k ] ?? null ) === $v ) { $out[] = $id; } }
		return $limit > 0 ? array_slice( $out, 0, $limit ) : $out;
	}
	public function count_records( array $types ): int { $n = 0; foreach ( $GLOBALS['t_posts'] as $p ) { if ( in_array( $p['post_type'], $types, true ) ) { $n++; } } return $n; }
	public function fingerprint( array $types ): string { $o = array(); foreach ( $GLOBALS['t_posts'] as $id => $p ) { if ( in_array( $p['post_type'], $types, true ) ) { $o[ $id ] = $p; } } ksort( $o ); return md5( json_encode( $o ) ); }
	public function finish( string $type, int $id ): void {}
	public $meta_generated = array();
	public function insert_attachment( array $f, string $file, array $meta ): int {
		$id = $this->next++;
		$GLOBALS['t_posts'][ $id ] = array( 'post_type' => 'attachment', 'post_status' => $f['post_status'], 'post_title' => $f['post_title'], 'post_content' => '', 'post_name' => '', 'mime' => $f['post_mime_type'], 'file' => $file, 'meta' => $meta, 'terms' => array() );
		return $id;
	}
	public function generate_attachment_metadata( int $id, string $file ): void { $this->meta_generated[] = $id; }
	public function attachment_path( int $id ): string { return $GLOBALS['t_posts'][ $id ]['file'] ?? ''; }
	public function delete_attachment( int $id ): bool { @unlink( $GLOBALS['t_posts'][ $id ]['file'] ?? '' ); unset( $GLOBALS['t_posts'][ $id ] ); return true; }
	public function count_unknown_records( array $known ): int { $n = 0; foreach ( $GLOBALS['t_posts'] as $p ) { if ( 0 === strpos( $p['post_type'], 'ftnc_' ) && ! in_array( $p['post_type'], $known, true ) ) { $n++; } } return $n; }
	public function wipe_batch( array $types, string $keep, int $limit ): array {
		$n = 0;
		foreach ( array_keys( $GLOBALS['t_posts'] ) as $id ) {
			$p = $GLOBALS['t_posts'][ $id ];
			if ( $n < $limit && in_array( $p['post_type'], $types, true ) && ( '' === $keep || ( $p['meta']['_ftnc_import_batch'] ?? '' ) !== $keep ) ) { unset( $GLOBALS['t_posts'][ $id ] ); $n++; }
		}
		$left = 0;
		foreach ( $GLOBALS['t_posts'] as $p ) { if ( in_array( $p['post_type'], $types, true ) && ( '' === $keep || ( $p['meta']['_ftnc_import_batch'] ?? '' ) !== $keep ) ) { $left++; } }
		return array( 'deleted' => $n, 'remaining' => $left );
	}
}
$repo = new MemRepo();
Fotonic_Restore_Writer::use_repo( $repo );
$W = 'Fotonic_Restore_Writer';
$J = 'Fotonic_Restore_Job';

$u = array( 'c11' => '11111111-1111-4111-8111-111111111111', 'c12' => '22222222-2222-4222-8222-222222222222', 'c13' => '33333333-3333-4333-8333-333333333333', 's31' => '44444444-4444-4444-8444-444444444444', 'w21' => '55555555-5555-4555-8555-555555555555' );
function backup2( string $key ): array {
	global $anna, $gianni, $luca, $u;
	$work = mk_work( 21, $u['w21'], 11, 31 );
	$work['meta']['_ftnc_owner_type'] = 'admin';
	$work['meta']['_ftnc_owner_id']   = '99';
	$work['meta']['_ftnc_delivery_notes'] = Fotonic_Field_Crypt::encrypt( 'Link \\ "scuro" è', $key );
	$work['post_content'] = Fotonic_Field_Crypt::encrypt( 'Note è private', $key );
	return array(
		'ftnc_customer' => array( mk_customer( 11, $u['c11'], 'Verdi', $anna, $key ), mk_customer( 12, $u['c12'], 'Bianchi', $gianni, $key ), mk_customer( 13, $u['c13'], 'Neri', $luca, $key, 'trash' ) ),
		'ftnc_service'  => array( mk_service( 31, $u['s31'], 'Wedding', '1500', $key ) ),
		'ftnc_work'     => array( $work ),
	);
}
function preview_all( string $sid ): array {
	for ( $i = 0; $i < 80; $i++ ) {
		$r = Fotonic_Restore_Preview::step( $sid );
		if ( is_wp_error( $r ) ) { return array( 'failed' => $r ); }
		if ( $r['done'] ) { return $r; }
	}
	return array( 'failed' => 'no end' );
}
function run_job( string $id, int $size = 25 ): array {
	for ( $i = 0; $i < 100; $i++ ) {
		$r = Fotonic_Restore_Job::run( $id, $size );
		if ( is_wp_error( $r ) ) { return array( 'failed' => $r ); }
		if ( 'done' === $r['status'] ) { $r['calls'] = $i + 1; return $r; }
	}
	return array( 'failed' => 'no end' );
}
function undo_job( string $id, int $size = 2 ): array {
	for ( $i = 0; $i < 100; $i++ ) {
		$r = Fotonic_Restore_Job::undo( $id, $size );
		if ( is_wp_error( $r ) ) { return array( 'failed' => $r ); }
		if ( 'undone' === $r['status'] ) { return $r; }
	}
	return array( 'failed' => 'no end' );
}
function site_ids_of( string $type ): array {
	$o = array();
	foreach ( $GLOBALS['t_posts'] as $id => $p ) { if ( $p['post_type'] === $type ) { $o[] = $id; } }
	return $o;
}
function open_preview( string $archive, ?string $secret = null ): string {
	global $R;
	Fotonic_Restore_Preview::flush_cache(); // A real request starts with an empty cache.
	$o = $R::open( $archive, $secret );
	if ( is_wp_error( $o ) ) { echo '  (open failed: ' . $o->get_error_code() . ")\n"; return ''; }
	$r = preview_all( $o['session_id'] );
	if ( isset( $r['failed'] ) ) { echo '  (preview failed)' . "\n"; }
	return $o['session_id'];
}

$dir     = $GLOBALS['t_base'] . '/arch';
mkdir( $dir );
$archive = $dir . '/eleva-backup-test.zip';
build_archive( $archive, $mk, backup2( $mk ), array(), array( 'fotonic_payment_types' => json_encode( array( array( 'id' => 1, 'slug' => 'default', 'label' => 'Payment' ), array( 'id' => 2, 'slug' => 'bonifico', 'label' => 'Bank transfer' ) ) ), 'fotonic_vault_wrap_pw' => 'x', 'fotonic_pro_thing' => 'y' ), array(), '' );
$site_key = random_bytes( 32 );
$safety_calls = 0;
$J::use_safety_backup( function () use ( &$safety_calls ) { $safety_calls++; return array( 'path' => '/x/eleva-backup-safety.zip' ); } );

// ---------------------------------------------------------------------------
echo "idmap\n";
// ---------------------------------------------------------------------------
$m = new Fotonic_Restore_Idmap();
t_equals( null, $m->get( 'ftnc_customer', 11 ), 'an unknown id maps to nothing' );
$m->set( 'ftnc_customer', 11, 1011 );
$m->set( 'ftnc_service', 11, 2011 );
t_equals( 1011, $m->get( 'ftnc_customer', 11 ), 'a mapped id is found' );
t_equals( 2011, $m->get( 'ftnc_service', 11 ), 'kinds do not mix' );
$m->set( 'ftnc_customer', 0, 5 );
t_equals( 1, $m->count( 'ftnc_customer' ), 'ids below 1 are ignored' );
$file = $GLOBALS['t_base'] . '/idmap.jsonl';
$m->flush( $file );
$m->set( 'ftnc_customer', 12, 1012 );
$m->flush( $file );
$again = Fotonic_Restore_Idmap::load( $file );
t_equals( 1012, $again->get( 'ftnc_customer', 12 ), 'the map is loaded again from the file, appended in two flushes' );
t_equals( 2011, $again->get( 'ftnc_service', 11 ), 'with every earlier entry' );

// ---------------------------------------------------------------------------
echo "payment types merge\n";
// ---------------------------------------------------------------------------
$site_pt = json_encode( array( array( 'id' => 1, 'slug' => 'default', 'label' => 'Mine' ), array( 'id' => 5, 'slug' => 'cash', 'label' => 'Cash' ) ) );
$bk_pt   = json_encode( array( array( 'id' => 1, 'slug' => 'default', 'label' => 'Theirs' ), array( 'id' => 2, 'slug' => 'bonifico', 'label' => 'Bank' ) ) );
$merged  = json_decode( $W::merge_payment_types( $site_pt, $bk_pt ), true );
t_equals( 3, count( $merged ), 'a new slug is added, the existing one is not' );
t_equals( 'Mine', $merged[0]['label'], 'the site keeps its own entry for a shared slug' );
t_equals( 6, $merged[2]['id'], 'a new entry gets the next free id' );
t_equals( null, $W::merge_payment_types( $bk_pt, $bk_pt ), 'nothing to add gives null' );

// ---------------------------------------------------------------------------
echo "scenario B on an empty site\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_site_mk']     = $site_key;
$GLOBALS['t_vault_setup'] = true;
$GLOBALS['t_posts']       = array();
$GLOBALS['t_opts']['fotonic_payment_types'] = $site_pt;
$sid = open_preview( $archive, $password );
$c   = $J::create( $sid, array() );
t_true( is_array( $c ) && 'running' === $c['status'] && 'B' === $c['scenario'], 'the job starts as scenario B (the site has a Vault)' );
t_equals( 5, $c['total'] ?? 0, 'five records to write' );
t_true( $J::is_blocking(), 'writes of protected data are blocked while the job runs' );
$job_id = $c['job_id'];
$done = run_job( $job_id, 2 );
if ( isset( $done['failed'] ) ) { echo '  (error: ' . ( is_object( $done['failed'] ) ? $done['failed']->get_error_code() . ' ' . $done['failed']->get_error_message() : $done['failed'] ) . ")\n"; }
t_true( ! isset( $done['failed'] ) && 'done' === $done['status'], 'the job runs to the end in small steps' );
t_equals( array( 'created' => 5, 'copied' => 0, 'overwritten' => 0, 'kept' => 0, 'skipped' => 0 ), $done['counts'], 'five records created' );
t_equals( 0, $safety_calls, 'an empty site needs no safety backup' );
t_true( ! $J::is_blocking(), 'the block ends with the job' );
t_equals( 3, count( site_ids_of( 'ftnc_customer' ) ), 'three customers on the site' );
$ids = array();
foreach ( $GLOBALS['t_posts'] as $id => $p ) { $ids[ $p['meta']['_ftnc_import_src'] ?? '' ] = $id; }
$cust11 = $ids['ftnc_customer:11'];
$svc31  = $ids['ftnc_service:31'];
$work21 = $ids['ftnc_work:21'];
$people = json_decode( $GLOBALS['t_posts'][ $cust11 ]['meta']['_ftnc_people'], true );
t_equals( 'anna@x.it', Fotonic_Crypto::deterministic_decrypt( $people[0]['email'], $site_key ), 'personal fields are encrypted with the key of THIS site' );
t_equals( '', Fotonic_Crypto::deterministic_decrypt( $people[0]['email'], $mk ), 'and no longer open with the old key' );
t_equals( 'Wedding', Fotonic_Field_Crypt::read( $GLOBALS['t_posts'][ $svc31 ]['post_title'], $site_key ), 'the service title is encrypted for this site' );
t_equals( 'service-' . $svc31, $GLOBALS['t_posts'][ $svc31 ]['post_name'], 'with a neutral slug, not one derived from the title' );
t_equals( (string) $cust11, $GLOBALS['t_posts'][ $work21 ]['meta']['_ftnc_customer_id'], 'the work points to the customer as it is now on this site' );
$rows = json_decode( $GLOBALS['t_posts'][ $work21 ]['meta']['_ftnc_work_services'], true );
t_equals( $svc31, $rows[0]['service_id'], 'and to the service' );
t_equals( '5', $GLOBALS['t_posts'][ $work21 ]['meta']['_ftnc_owner_id'], 'the owner of an admin work is the person doing the restore' );
t_equals( $u['w21'], $GLOBALS['t_posts'][ $work21 ]['meta']['_ftnc_uid'], 'the uid of the backup goes with the record' );
t_equals( 'Link \\ "scuro" è', Fotonic_Field_Crypt::read( $GLOBALS['t_posts'][ $work21 ]['meta']['_ftnc_delivery_notes'], $site_key ), 'backslash, quotes and accents survive' );
t_equals( 'Note è private', Fotonic_Field_Crypt::read( $GLOBALS['t_posts'][ $work21 ]['post_content'], $site_key ), 'the notes of the work are encrypted for this site' );
t_equals( 'trash', $GLOBALS['t_posts'][ $ids['ftnc_customer:13'] ]['post_status'], 'a trashed record stays in the trash' );
$all_tagged = true;
foreach ( $GLOBALS['t_posts'] as $p ) { if ( ( $p['meta']['_ftnc_import_batch'] ?? '' ) !== $job_id ) { $all_tagged = false; } }
t_true( $all_tagged, 'every record carries the batch label' );
t_equals( 3, count( json_decode( $GLOBALS['t_opts']['fotonic_payment_types'], true ) ), 'the payment types were merged' );
t_true( 'x' !== ( $GLOBALS['t_opts']['fotonic_vault_wrap_pw'] ?? '' ) && ! isset( $GLOBALS['t_opts']['fotonic_pro_thing'] ), 'no Vault option and no unknown option was written' );
t_true( is_wp_error( $R::collections( $sid ) ), 'the restore session (and the old key with it) is closed at the end' );

// ---------------------------------------------------------------------------
echo "the same backup again: everything identical (a restore leaves nothing to restore)\n";
// ---------------------------------------------------------------------------
$sid2 = open_preview( $archive, $password );
$plan = $P = Fotonic_Restore_Preview::plan( $sid2 );
$counts = array();
foreach ( $plan as $lines ) { foreach ( $lines as $l ) { $counts[ $l['s'] ] = ( $counts[ $l['s'] ] ?? 0 ) + 1; } }
t_equals( array( 'identical' => 5 ), $counts, 'the preview after a real restore says identical for all five' );
$again = $J::create( $sid2, array() );
t_true( is_array( $again ), 'a finished job does not block the next one' );
if ( is_array( $again ) ) {
	$r = run_job( $again['job_id'] );
	t_equals( array( 'created' => 0, 'copied' => 0, 'overwritten' => 0, 'kept' => 5, 'skipped' => 0 ), $r['counts'] ?? array(), 'importing it again keeps the five site records and creates nothing' );
	t_equals( 3, count( site_ids_of( 'ftnc_customer' ) ), 'no duplicate customer' );
	undo_job( $again['job_id'] );
	t_equals( 5, count( $GLOBALS['t_posts'] ), 'undoing that empty restore removes nothing: the records of the first restore are not its own' );
}

// ---------------------------------------------------------------------------
echo "undo\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_posts'] = array();
$GLOBALS['t_opts']['fotonic_payment_types'] = $site_pt;
$sid = open_preview( $archive, $password );
$c   = $J::create( $sid, array() );
run_job( $c['job_id'] );
t_equals( 5, count( $GLOBALS['t_posts'] ), 'a restore to undo' );
$r = undo_job( $c['job_id'], 2 );
t_equals( 'undone', $r['status'] ?? '', 'undo runs to the end' );
t_equals( 0, count( $GLOBALS['t_posts'] ), 'the records the restore created are gone' );
t_equals( $site_pt, $GLOBALS['t_opts']['fotonic_payment_types'], 'and the payment types are as they were' );

// ---------------------------------------------------------------------------
echo "scenario C: a site with no Vault\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_site_mk']     = null;
$GLOBALS['t_vault_setup'] = false;
$GLOBALS['t_posts']       = array();
$sid = open_preview( $archive, $password );
$noconf = $J::create( $sid, array() );
t_equals( 'ftnc_restore_choose_vault', is_wp_error( $noconf ) ? $noconf->get_error_code() : '', 'no Vault and no confirmation: the person must choose (Vault first, or plain text on purpose)' );
$c   = $J::create( $sid, array(), array( 'plain' => true ) );
t_equals( 'C', $c['scenario'] ?? '', 'no Vault on the site: scenario C' );
$done = run_job( $c['job_id'] );
t_equals( 5, $done['counts']['created'] ?? 0, 'five records created' );
$ids = array();
foreach ( $GLOBALS['t_posts'] as $id => $p ) { $ids[ $p['meta']['_ftnc_import_src'] ?? '' ] = $id; }
$people = json_decode( $GLOBALS['t_posts'][ $ids['ftnc_customer:11'] ]['meta']['_ftnc_people'], true );
t_equals( 'anna@x.it', $people[0]['email'], 'personal fields are stored as plain text' );
t_equals( 'Wedding', $GLOBALS['t_posts'][ $ids['ftnc_service:31'] ]['post_title'], 'and so is the service title' );
$sid2 = open_preview( $archive, $password );
$counts = array();
foreach ( Fotonic_Restore_Preview::plan( $sid2 ) as $lines ) { foreach ( $lines as $l ) { $counts[ $l['s'] ] = ( $counts[ $l['s'] ] ?? 0 ) + 1; } }
t_equals( array( 'identical' => 5 ), $counts, 'a site with no Vault also reads back identical' );
$R::close( $sid2 );
// A site with data and no Vault is refused.
$sid3 = open_preview( $archive, $password );
$refused = $J::create( $sid3, array() );
$refused = $J::create( $sid3, array() );
t_equals( 'ftnc_restore_need_vault', is_wp_error( $refused ) ? $refused->get_error_code() : '', 'a site with data and no Vault is refused: turn the Vault on first' );
$R::close( $sid3 );

// ---------------------------------------------------------------------------
echo "scenario B on a site with data: choices, safety backup, undo of an overwrite\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_site_mk']     = $site_key;
$GLOBALS['t_vault_setup'] = true;
$site_set = backup2( $site_key );
// A changed customer (same uid) and a "possible" one: same email, other uid, other name.
$site_set['ftnc_customer'][1] = mk_customer( 12, $u['c12'], 'Bianchi', array( array( 'first_name' => 'Gianni', 'last_name' => 'Bianchi', 'email' => 'g@x.it', 'phone' => '0212345678', 'is_main' => true ) ), $site_key );
put_site( $site_set, 1000 );
$GLOBALS['t_posts'][1011]['meta']['_ftnc_uid'] = '99999999-9999-4999-8999-999999999999'; // Verdi on the site has another uid.
$GLOBALS['t_posts'][1011]['post_title'] = 'Verdi Anna';
$before_count = count( $GLOBALS['t_posts'] );
$sid = open_preview( $archive, $password );
$plan = Fotonic_Restore_Preview::plan( $sid );
t_equals( 'possible', $plan['ftnc_customer'][0]['s'], 'precondition: customer 11 is a possible duplicate' );
t_equals( 'different', $plan['ftnc_customer'][1]['s'], 'precondition: customer 12 is different' );
$none = $J::create( $sid, array() );
t_equals( 'ftnc_restore_decision_required', is_wp_error( $none ) ? $none->get_error_code() : '', 'a possible duplicate needs an explicit choice' );
$bad = $J::create( $sid, array( 'ftnc_customer' => array( 11 => array( 'action' => 'use_backup', 'site_id' => 424242 ) ) ) );
t_equals( 'ftnc_restore_bad_decision', is_wp_error( $bad ) ? $bad->get_error_code() : '', 'a site record that is not a candidate is refused' );
$c = $J::create( $sid, array( 'ftnc_customer' => array(
	11 => array( 'action' => 'keep_site', 'site_id' => 1011 ),
	12 => array( 'action' => 'use_backup' ),
), 'ftnc_work' => array( 21 => array( 'action' => 'import' ) ) ) );
if ( is_wp_error( $c ) ) { echo '  (error: ' . $c->get_error_code() . ' ' . $c->get_error_message() . ")\n"; }
t_true( is_array( $c ), 'the choices are accepted' );
$old_12 = $GLOBALS['t_posts'][1012]['meta'];
$safety_before = $safety_calls;
$done = run_job( $c['job_id'] );
t_equals( 1, $safety_calls - $safety_before, 'a site with data gets a safety backup first' );
t_equals( array( 'created' => 1, 'copied' => 0, 'overwritten' => 1, 'kept' => 3, 'skipped' => 0 ), $done['counts'] ?? array(), 'one record overwritten, the work added as asked, the rest kept' );
$p12 = json_decode( $GLOBALS['t_posts'][1012]['meta']['_ftnc_people'], true );
t_equals( '', $p12[0]['phone'] ?? '', 'the site customer now has the values of the backup (no phone)' );
t_equals( $c['job_id'], $GLOBALS['t_posts'][1012]['meta']['_ftnc_import_batch'], 'the overwritten record carries the batch label' );
t_equals( $before_count + 1, count( $GLOBALS['t_posts'] ), 'only the work chosen as "import" was added' );
$r = undo_job( $c['job_id'] );
t_equals( 'undone', $r['status'] ?? '', 'undo works' );
t_equals( $before_count, count( $GLOBALS['t_posts'] ), 'undo deletes the added work and not the record that was overwritten' );
t_equals( $old_12, $GLOBALS['t_posts'][1012]['meta'], 'it gets exactly its old values back, the journal and label removed' );

// A copy gets a new uid and a possible one can be skipped.
$sid = open_preview( $archive, $password );
$c   = $J::create( $sid, array( 'ftnc_customer' => array( 11 => array( 'action' => 'copy' ), 12 => array( 'action' => 'skip' ) ), 'ftnc_work' => array( 21 => array( 'action' => 'skip' ) ) ) );
if ( is_wp_error( $c ) ) { echo '  (error: ' . $c->get_error_code() . ")\n"; }
$done = run_job( $c['job_id'] );
t_equals( array( 'created' => 0, 'copied' => 1, 'overwritten' => 0, 'kept' => 2, 'skipped' => 2 ), $done['counts'] ?? array(), 'a copy, two skips and the rest kept' );
$copy = 0;
foreach ( $GLOBALS['t_posts'] as $id => $p ) { if ( ( $p['meta']['_ftnc_import_src'] ?? '' ) === 'ftnc_customer:11' ) { $copy = $id; } }
t_true( $copy > 0 && $GLOBALS['t_posts'][ $copy ]['meta']['_ftnc_uid'] !== $u['c11'] && Fotonic_Record_Uid::is_valid( $GLOBALS['t_posts'][ $copy ]['meta']['_ftnc_uid'] ), 'the copy has a new valid uid' );
undo_job( $c['job_id'] );
t_equals( $before_count, count( $GLOBALS['t_posts'] ), 'undo removes the copy' );

// ---------------------------------------------------------------------------
echo "safety backup fails: nothing is written\n";
// ---------------------------------------------------------------------------
$J::use_safety_backup( function () { return new WP_Error( 'ftnc_backup_keys_not_ready', 'Backup keys are not set up yet.' ); } );
$sid = open_preview( $archive, $password );
$c   = $J::create( $sid, array( 'ftnc_customer' => array( 11 => array( 'action' => 'keep_site', 'site_id' => 1011 ), 12 => array( 'action' => 'use_backup' ) ), 'ftnc_work' => array( 21 => array( 'action' => 'skip' ) ) ) );
$snap = json_encode( $GLOBALS['t_posts'] );
$r = $J::run( $c['job_id'] );
t_equals( 'ftnc_restore_safety_failed', is_wp_error( $r ) ? $r->get_error_code() : '', 'the job stops with a clear error' );
t_equals( $snap, json_encode( $GLOBALS['t_posts'] ), 'and the site is exactly as it was' );
t_equals( 'error', $J::job()['status'], 'the job is in error' );
t_true( ! $J::is_blocking(), 'a failed job does not block the site' );
$J::dismiss( $c['job_id'] );
$J::use_safety_backup( function () { return array( 'path' => '/x/eleva-backup-safety.zip' ); } );

// ---------------------------------------------------------------------------
echo "the site changed after the preview\n";
// ---------------------------------------------------------------------------
$sid = open_preview( $archive, $password );
$GLOBALS['t_posts'][1012]['post_title'] = 'Changed meanwhile';
$r = $J::create( $sid, array( 'ftnc_customer' => array( 11 => array( 'action' => 'keep_site', 'site_id' => 1011 ) ) ) );
t_equals( 'ftnc_restore_preview_stale', is_wp_error( $r ) ? $r->get_error_code() : '', 'a changed site means: run the preview again' );
$R::close( $sid );

// ---------------------------------------------------------------------------
echo "pause, a locked Vault and resuming after a stop\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_posts'] = array();
$sid = open_preview( $archive, $password );
$c   = $J::create( $sid, array() );
$id  = $c['job_id'];
$r = $J::run( $id, 2 );
t_equals( 2, $r['done'] ?? 0, 'two records written' );
$J::pause( $id );
t_equals( 'ftnc_restore_paused', ( $x = $J::run( $id ) ) instanceof WP_Error ? $x->get_error_code() : '', 'a paused job does not write' );
t_true( $J::is_blocking(), 'a paused restore still blocks the site' );
$J::resume( $id );
$GLOBALS['t_site_mk'] = null;
t_equals( 'vault_locked', ( $x = $J::run( $id ) ) instanceof WP_Error ? $x->get_error_code() : '', 'with the Vault locked the job waits (423)' );
t_equals( 'running', $J::job()['status'], 'and stays running' );
$GLOBALS['t_site_mk'] = random_bytes( 32 );
t_equals( 'ftnc_restore_key_changed', ( $x = $J::run( $id ) ) instanceof WP_Error ? $x->get_error_code() : '', 'another Vault key in the middle of the job is refused' );
$GLOBALS['t_site_mk'] = $site_key;
$J::resume( $id ); // The job is in error: retry.
// Simulate a crash after a record was created but before the map was saved: delete the map, step back.
$state = $J::job();
$GLOBALS['t_opts']['fotonic_restore_job']['cursor'] = array( 'd' => 0, 'o' => 0 );
$GLOBALS['t_opts']['fotonic_restore_job']['done']   = 0;
foreach ( glob( $R::work_dir( $sid ) . '/idmap.jsonl' ) as $f ) { unlink( $f ); }
$done = run_job( $id );
t_equals( 'done', $done['status'] ?? '', 'the job finishes after the stop' );
t_equals( 3, count( site_ids_of( 'ftnc_customer' ) ), 'no customer was created twice' );
t_equals( 1, count( site_ids_of( 'ftnc_work' ) ), 'and no work either' );
$ids = array();
foreach ( $GLOBALS['t_posts'] as $pid => $p ) { $ids[ $p['meta']['_ftnc_import_src'] ?? '' ] = $pid; }
t_equals( (string) $ids['ftnc_customer:11'], $GLOBALS['t_posts'][ $ids['ftnc_work:21'] ]['meta']['_ftnc_customer_id'], 'the references were rebuilt from the records found' );

// ---------------------------------------------------------------------------
echo "the Vault is encrypting: no restore starts\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_enc_job'] = array( 'status' => 'running' );
$sid = open_preview( $archive, $password );
$r = $J::create( $sid, array() );
t_equals( 'encrypt_in_progress', is_wp_error( $r ) ? $r->get_error_code() : '', 'refused while the encrypt-existing job runs' );
unset( $GLOBALS['t_enc_job'] );
$R::close( $sid );

// ---------------------------------------------------------------------------
echo "a stop in the middle of one record (the process dies between two writes)\n";
// ---------------------------------------------------------------------------
function crashing_run( string $id ): void {
	try { Fotonic_Restore_Job::run( $id, 25 ); } catch ( RuntimeException $e ) { /* the "process" died */ }
}
// 1. A record being CREATED.
$GLOBALS['t_posts'] = array();
$GLOBALS['t_opts']['fotonic_payment_types'] = $site_pt;
$sid = open_preview( $archive, $password );
$c   = $J::create( $sid, array() );
$repo->sets = 0; $repo->fail_at = 3; // The batch label, the source label, then the personal fields: dies there.
crashing_run( $c['job_id'] );
t_equals( 1, count( $GLOBALS['t_posts'] ), 'precondition: one half-written record exists' );
$half = array_key_first( $GLOBALS['t_posts'] );
t_equals( $c['job_id'], $GLOBALS['t_posts'][ $half ]['meta']['_ftnc_import_batch'] ?? '', 'and it already carries the batch label (undo can find it)' );
$done = run_job( $c['job_id'] );
t_equals( 'done', $done['status'] ?? '', 'the job resumes and finishes' );
t_equals( 3, count( site_ids_of( 'ftnc_customer' ) ), 'exactly three customers: the half-written one was completed in place, not duplicated' );
t_true( isset( $GLOBALS['t_posts'][ $half ]['meta']['_ftnc_people'] ) && isset( $GLOBALS['t_posts'][ $half ]['meta']['_ftnc_uid'] ), 'and it is complete' );
$sid2 = open_preview( $archive, $password );
$counts = array();
foreach ( Fotonic_Restore_Preview::plan( $sid2 ) as $lines ) { foreach ( $lines as $l ) { $counts[ $l['s'] ] = ( $counts[ $l['s'] ] ?? 0 ) + 1; } }
t_equals( array( 'identical' => 5 ), $counts, 'a preview afterwards says identical for all of it' );
$R::close( $sid2 );
undo_job( $c['job_id'] );
t_equals( 0, count( $GLOBALS['t_posts'] ), 'undo removes everything the restore created' );

// 2. A record being OVERWRITTEN, then undone without resuming.
$site_set = backup2( $site_key );
$site_set['ftnc_customer'][1] = mk_customer( 12, $u['c12'], 'Bianchi', array( array( 'first_name' => 'Gianni', 'last_name' => 'Bianchi', 'email' => 'g@x.it', 'phone' => '0212345678', 'is_main' => true ) ), $site_key );
foreach ( array( 'resume', 'undo' ) as $how ) {
	put_site( $site_set, 1000 );
	$orig_12 = $GLOBALS['t_posts'][1012];
	$sid = open_preview( $archive, $password );
	$c   = $J::create( $sid, array( 'ftnc_customer' => array( 12 => array( 'action' => 'use_backup' ) ) ) );
	$J::run( $c['job_id'] ); // The safety backup.
	$repo->sets = 0; $repo->fail_at = 4; // The journal, the label, then in the middle of the values.
	crashing_run( $c['job_id'] );
	t_true( isset( $GLOBALS['t_posts'][1012]['meta']['_ftnc_restore_prev'] ) && ( $GLOBALS['t_posts'][1012]['meta']['_ftnc_import_batch'] ?? '' ) === $c['job_id'], "($how) the record is journaled and labeled before it is changed" );
	if ( 'resume' === $how ) {
		$done = run_job( $c['job_id'] );
		t_equals( 'done', $done['status'] ?? '', '(resume) the job finishes' );
		$journal = json_decode( $GLOBALS['t_posts'][1012]['meta']['_ftnc_restore_prev'], true );
		t_true( ! isset( $journal['o'] ), '(resume) the journal still holds the ORIGINAL values, not a half-written state' );
	}
	undo_job( $c['job_id'] );
	t_equals( $orig_12, $GLOBALS['t_posts'][1012], "($how) undo gives the record back exactly as it was" );
}

// ---------------------------------------------------------------------------
echo "fields of ids nobody translates are not written\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_filters']['ftnc_backup_datasets'] = function ( $d ) {
	$d['ftnc_work']['meta_keys'][]    = '_ftnc_work_suppliers';
	$d['ftnc_work']['ref_fields']['_ftnc_work_suppliers'] = 'json:[].id|post';
	return $d;
};
$GLOBALS['t_posts'] = array();
$rec = backup2( $mk )['ftnc_work'][0];
$rec['meta']['_ftnc_work_suppliers'] = '[{"id":7,"fee":"10"}]';
$map = new Fotonic_Restore_Idmap();
$res = $W::write_record( 'ftnc_work', $rec, array( 'action' => 'import', 'scenario' => 'C', 'old_mk' => $mk, 'batch' => 'b1', 'idmap' => $map, 'user_id' => 5 ) );
t_equals( array( '_ftnc_work_suppliers' ), $res['skipped'], 'the field is reported by name' );
t_true( ! array_key_exists( '_ftnc_work_suppliers', $GLOBALS['t_posts'][ $res['id'] ]['meta'] ), 'and nothing of it reaches the site (the raw backup id could point to an unrelated record)' );
unset( $GLOBALS['t_filters']['ftnc_backup_datasets'] );

// ---------------------------------------------------------------------------
echo "an abandoned job does not block the CRM for ever\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_posts'] = array();
$sid = open_preview( $archive, $password );
$c   = $J::create( $sid, array() );
t_true( $J::is_blocking(), 'a fresh job blocks' );
$GLOBALS['t_opts']['fotonic_restore_job']['updated'] -= 7300;
t_true( ! $J::is_blocking(), 'a job nobody touched for the length of a session stops blocking' );
t_equals( 'expired', $J::job()['status'], 'and is marked expired (undo still works)' );
$J::dismiss( $c['job_id'] );
$GLOBALS['t_posts'] = array();
$sid = open_preview( $archive, $password );
$c   = $J::create( $sid, array() );
$R::close( $sid );
t_true( ! $J::is_blocking(), 'a job whose session is gone stops blocking too' );
$J::dismiss( $c['job_id'] );

// ---------------------------------------------------------------------------
echo "a duplicate inside the backup, and the journals of an accepted restore\n";
// ---------------------------------------------------------------------------
$dup = backup2( $mk );
$dup['ftnc_customer'][] = mk_customer( 14, '', 'Verdi', $anna, $mk );
$dup_archive = $dir . '/eleva-backup-dup.zip';
build_archive( $dup_archive, $mk, $dup, array(), array(), array(), '' );
$GLOBALS['t_site_mk'] = $mk;
put_site( backup2( $mk ), 1000 );
$sid = open_preview( $dup_archive );
$plan = Fotonic_Restore_Preview::plan( $sid );
t_equals( 'possible', $plan['ftnc_customer'][3]['s'], 'precondition: the second Verdi of the backup is a possible duplicate' );
$bad = $J::create( $sid, array( 'ftnc_customer' => array( 14 => array( 'action' => 'use_backup', 'site_id' => 1011 ) ) ) );
t_equals( 'ftnc_restore_duplicate_target', is_wp_error( $bad ) ? $bad->get_error_code() : '', 'overwriting a site record that another backup record is kept as is refused' );
$ok = $J::create( $sid, array( 'ftnc_customer' => array( 14 => array( 'action' => 'keep_site', 'site_id' => 1011 ) ) ) );
t_true( is_array( $ok ), 'but both can be kept as the same site record' );
$done = run_job( $ok['job_id'] );
t_equals( 6, $done['counts']['kept'] ?? 0, 'six kept, nothing written' );
$J::undo( $ok['job_id'] );
$J::dismiss( $ok['job_id'] );
t_equals( 'dismissed', $J::job()['status'], 'a job can be dismissed' );

// A real overwrite, accepted: the journals go, the new values stay.
put_site( $site_set, 1000 );
$sid = open_preview( $archive, $password );
$c   = $J::create( $sid, array( 'ftnc_customer' => array( 12 => array( 'action' => 'use_backup' ) ) ) );
run_job( $c['job_id'] );
t_true( isset( $GLOBALS['t_posts'][1012]['meta']['_ftnc_restore_prev'] ), 'precondition: the overwritten record has a journal of old personal values' );
$J::dismiss( $c['job_id'] );
t_true( ! isset( $GLOBALS['t_posts'][1012]['meta']['_ftnc_restore_prev'] ) && ! isset( $GLOBALS['t_posts'][1012]['meta']['_ftnc_import_batch'] ), 'accepting the result removes the journal and the label' );
t_true( empty( json_decode( $GLOBALS['t_posts'][1012]['meta']['_ftnc_people'], true )[0]['phone'] ), 'the new values stay (the phone of the backup, none)' );


// ---------------------------------------------------------------------------
echo "the old key is never stored\n";
// ---------------------------------------------------------------------------
$dump = json_encode( $GLOBALS['t_opts'] );
t_true( false === strpos( $dump, base64_encode( $mk ) ) && false === strpos( $dump, bin2hex( $mk ) ), 'the job option holds no Master Key' );

// ===========================================================================
// Phase 10: files, the PDFs under the Vault layer, "replace everything"
// ===========================================================================
$pdf_plain  = "%PDF-1.4 contract \xc3\xa8 body";
$quote_plain = "%PDF-1.4 quote for Anna";
$quote_blob = Fotonic_File_Crypt::encrypt( $quote_plain, $mk );
function files_backup( string $mk, array $rows ): array {
	$data = backup2( $mk );
	$data['ftnc_work'][0]['meta']['_ftnc_work_files'] = json_encode( $rows );
	return $data;
}
function att( int $id, string $title, string $mime, string $file, string $body ): array {
	return array( 'id' => $id, 'post_title' => $title, 'mime' => $mime, 'attached_file' => $file, 'metadata' => array(), 'size' => strlen( $body ), 'sha256' => hash( 'sha256', $body ) );
}
function attachment_posts(): array {
	$o = array();
	foreach ( $GLOBALS['t_posts'] as $id => $p ) { if ( 'attachment' === $p['post_type'] ) { $o[ $id ] = $p; } }
	return $o;
}
$good_rows = array(
	array( 'type' => 'attachment', 'id' => 77, 'label' => 'Contratto' ),
	array( 'type' => 'attachment', 'id' => 78, 'label' => 'A-1' ),
	array( 'type' => 'link', 'url' => 'https://example.test/gallery', 'label' => 'Galleria' ),
	array( 'type' => 'attachment', 'id' => 76, 'label' => 'Nel Vault' ),
);
$good_atts  = array( att( 77, 'Contratto', 'application/pdf', '2026/10/contratto.pdf', $pdf_plain ), att( 78, 'A-1', 'application/pdf', 'fotonic/quotes/a-1-abc123.pdf.enc', $quote_blob ), att( 76, 'Nel Vault', 'application/pdf', 'fotonic/vault/sub/clienti.pdf', 'vault pdf' ) );
$files_zip  = $dir . '/eleva-backup-files.zip';
build_archive( $files_zip, $mk, files_backup( $mk, $good_rows ), array(), array(), $good_atts, '', array( 'files' => array( 77 => $pdf_plain, 78 => $quote_blob, 76 => 'vault pdf' ) ) );

// ---------------------------------------------------------------------------
echo "files: a work with a file and a protected PDF (scenario B, other key)\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_vault_setup'] = true;
$GLOBALS['t_site_mk']     = $site_key;
$GLOBALS['t_posts']       = array();
Fotonic_Restore_Files::flush_cache();
$sid = open_preview( $files_zip, $password );
$c   = $J::create( $sid, array() );
$done = run_job( $c['job_id'] );
t_equals( 3, $done['report']['files']['created'] ?? -1, 'three files were made' );
t_equals( 0, $done['report']['attachments'] ?? -1, 'no row was left out' );
$atts = attachment_posts();
t_equals( 3, count( $atts ), 'three attachments in the library' );
$work_id = site_ids_of( 'ftnc_work' )[0];
$rows    = json_decode( $GLOBALS['t_posts'][ $work_id ]['meta']['_ftnc_work_files'], true );
t_equals( 4, count( $rows ), 'the work lists its three files and the link' );
t_true( isset( $atts[ $rows[0]['id'] ] ) && isset( $atts[ $rows[1]['id'] ] ), 'the rows carry the NEW ids' );
t_equals( 'Contratto', Fotonic_Field_Crypt::read( $rows[0]['label'], $site_key ), 'labels stay (now a protected field: sealed with this site key)' );
t_equals( 'link', $rows[2]['type'], 'the link row stays' );
$first = $atts[ $rows[0]['id'] ];
t_equals( $pdf_plain, Fotonic_File_Crypt::decrypt_file( $first['file'], $site_key ), 'the file content is the same (sealed with this site key)' );
t_true( 0 === strpos( $first['file'], $GLOBALS['t_base'] . '/fotonic/work-files/' ) && '.pdf.enc' === substr( $first['file'], -8 ), 'a document of a work from an old backup (it sat in the media library) goes to the private work folder, encrypted' );
t_true( false === strpos( basename( $first['file'] ), 'contratto' ), 'under a name that says nothing about the document' );
t_equals( 'contratto.pdf', Fotonic_Field_Crypt::read( $first['post_title'], $site_key ), 'its real name is kept in the protected title' );
t_equals( 'application/pdf', $first['mime'], 'mime kept' );
$second = $atts[ $rows[1]['id'] ];
t_true( 0 === strpos( $second['file'], $GLOBALS['t_base'] . '/fotonic/quotes/' ) && '.pdf.enc' === substr( $second['file'], -8 ), 'a protected PDF stays in the private folder, as .pdf.enc, under a new random name' );
t_true( basename( $second['file'] ) !== 'a-1-abc123.pdf.enc', 'new name' );
t_true( file_exists( $GLOBALS['t_base'] . '/fotonic/quotes/.htaccess' ) && file_exists( $GLOBALS['t_base'] . '/fotonic/quotes/index.php' ), 'the private folder is guarded' );
t_equals( $quote_plain, Fotonic_File_Crypt::decrypt_file( $second['file'], $site_key ), 'the PDF opens with the key of THIS site' );
t_true( false === Fotonic_File_Crypt::decrypt_file( $second['file'], $mk ), 'and no longer with the old key' );
t_equals( 'private', $second['post_status'], 'a private file stays private' );
$vault_att = $atts[ $rows[3]['id'] ];
t_true( 0 === strpos( $vault_att['file'], $GLOBALS['t_base'] . '/fotonic/vault/' ) && 'private' === $vault_att['post_status'], 'a file of the Vault folder (even in a subfolder) stays in the private folder, never the public month' );
t_equals( 'vault pdf', file_get_contents( $vault_att['file'] ), 'with its content' );
t_equals( 'batch', ( $first['meta']['_ftnc_import_batch'] ?? '' ) === $c['job_id'] ? 'batch' : 'none', 'labelled with the job' );
t_equals( 'attachment:77', $first['meta']['_ftnc_import_src'], 'and with its backup id' );
t_equals( hash( 'sha256', $pdf_plain ), $first['meta']['_ftnc_import_sha'], 'and the checksum of the backup bytes' );
t_equals( array(), glob( $GLOBALS['t_base'] . '/restore/*/att-*' ) ?: array(), 'no temporary file is left in the session folder' );

// The preview after a restore says identical, files included.
Fotonic_Restore_Files::flush_cache();
$sid2   = open_preview( $files_zip, $password );
$counts = array();
foreach ( Fotonic_Restore_Preview::plan( $sid2 ) as $lines ) { foreach ( $lines as $l ) { $counts[ $l['s'] ] = ( $counts[ $l['s'] ] ?? 0 ) + 1; } }
t_equals( array( 'identical' => 5 ), $counts, 'after the restore the whole backup reads identical, work files included' );

// A copy of the work reuses the files of the backup (same bytes): no second library entry.
$before = count( attachment_posts() );
$c2   = $J::create( $sid2, array( 'ftnc_work' => array( 21 => array( 'action' => 'copy' ) ) ) );
$done2 = run_job( $c2['job_id'] );
t_equals( $before, count( attachment_posts() ), 'a copied work reuses the files: no duplicate in the library' );
t_equals( 3, $done2['report']['files']['reused'] ?? -1, 'reported as reused' );
$copy = null;
foreach ( site_ids_of( 'ftnc_work' ) as $wid ) { if ( $wid !== $work_id ) { $copy = $wid; } }
t_equals( $rows[0]['id'], json_decode( $GLOBALS['t_posts'][ $copy ]['meta']['_ftnc_work_files'], true )[0]['id'], 'both works point at the same file' );
$undone = undo_job( $c2['job_id'] );
t_equals( $before, count( attachment_posts() ), 'undoing the copy does not delete files the first restore made' );
t_true( file_exists( $first['file'] ), 'the file is still on disk' );
$J::dismiss( $c2['job_id'] );
$R::close( $sid2 );

// ---------------------------------------------------------------------------
echo "files: undo removes the files it made (and only those)\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_posts'] = array();
$GLOBALS['t_posts'][900] = array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'Mine', 'post_content' => '', 'file' => $GLOBALS['t_base'] . '/mine.txt', 'meta' => array(), 'terms' => array() );
file_put_contents( $GLOBALS['t_base'] . '/mine.txt', 'mine' );
Fotonic_Restore_Files::flush_cache();
$sid = open_preview( $files_zip, $password );
$c   = $J::create( $sid, array() );
$done = run_job( $c['job_id'] );
$made = array_keys( attachment_posts() );
$files_on_disk = array();
foreach ( attachment_posts() as $id => $p ) { $files_on_disk[ $id ] = $p['file']; }
t_equals( 4, count( $made ), 'one of the site plus three made' );
$un = undo_job( $c['job_id'], 2 );
$left = attachment_posts();
t_equals( array( 900 ), array_keys( $left ), 'undo deleted the three files it made and not the one that was there' );
$gone = true;
foreach ( $files_on_disk as $id => $f ) { if ( 900 !== $id && file_exists( $f ) ) { $gone = false; } }
t_true( $gone, 'and their files are off the disk' );
t_true( file_exists( $GLOBALS['t_base'] . '/mine.txt' ), 'the site own file is untouched' );

// ---------------------------------------------------------------------------
echo "files: same key = copied as it is; no Vault = a plain PDF\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_posts'] = array();
$GLOBALS['t_site_mk'] = $mk;
Fotonic_Restore_Files::flush_cache();
$sid = open_preview( $files_zip, $password );
$c   = $J::create( $sid, array() );
run_job( $c['job_id'] );
$pdf_att = null;
foreach ( attachment_posts() as $p ) { if ( 'private' === $p['post_status'] && false !== strpos( $p['file'], '/fotonic/quotes/' ) ) { $pdf_att = $p; } }
t_equals( $quote_blob, file_get_contents( $pdf_att['file'] ), 'with the same Vault the protected file is copied byte for byte' );
$GLOBALS['t_posts'] = array();
$GLOBALS['t_site_mk'] = null;
$GLOBALS['t_vault_setup'] = false;
Fotonic_Restore_Files::flush_cache();
$sid = open_preview( $files_zip, $password );
$c   = $J::create( $sid, array(), array( 'plain' => true ) );
run_job( $c['job_id'] );
$pdf_att = null;
foreach ( attachment_posts() as $p ) { if ( 'private' === $p['post_status'] && false !== strpos( $p['file'], '/fotonic/quotes/' ) ) { $pdf_att = $p; } }
t_true( '.pdf' === substr( $pdf_att['file'], -4 ) && $quote_plain === file_get_contents( $pdf_att['file'] ), 'with no Vault the PDF is stored as a plain .pdf' );
t_true( 0 === strpos( $pdf_att['file'], $GLOBALS['t_base'] . '/fotonic/quotes/' ), 'still in the private folder' );
$GLOBALS['t_vault_setup'] = true;
$GLOBALS['t_site_mk'] = $site_key;

// ---------------------------------------------------------------------------
echo "files: missing, refused and damaged files\n";
// ---------------------------------------------------------------------------
$bad_rows = array(
	array( 'type' => 'attachment', 'id' => 79, 'label' => 'Not in the backup' ),
	array( 'type' => 'attachment', 'id' => 80, 'label' => 'Script' ),
	array( 'type' => 'attachment', 'id' => 81, 'label' => 'Escapes' ),
	array( 'type' => 'attachment', 'id' => 82, 'label' => 'Tampered' ),
	array( 'type' => 'attachment', 'id' => 83, 'label' => 'Fine' ),
);
$bad_atts = array(
	att( 80, 'Script', 'application/x-php', '2026/10/shell.php', '<?php echo 1;' ),
	att( 81, 'Escapes', 'application/pdf', '../../wp-config.pdf', 'x' ),
	att( 82, 'Tampered', 'application/pdf', '2026/10/t.pdf', 'tampered body' ),
	att( 83, 'Fine', 'text/plain', '2026/10/fine.txt', 'fine' ),
);
$bad_zip = $dir . '/eleva-backup-badfiles.zip';
build_archive( $bad_zip, $mk, files_backup( $mk, $bad_rows ), array(), array(), $bad_atts, '', array( 'files' => array( 80 => '<?php echo 1;', 81 => 'x', 82 => 'tampered body', 83 => 'fine' ), 'tamper_files' => array( 82 ) ) );
$GLOBALS['t_posts'] = array();
Fotonic_Restore_Files::flush_cache();
$sid = open_preview( $bad_zip, $password );
$c   = $J::create( $sid, array() );
$done = run_job( $c['job_id'] );
$f = $done['report']['files'] ?? array();
t_equals( array( 'created' => 1, 'reused' => 0, 'missing' => 1, 'rejected' => 2, 'damaged' => 1 ), $f, 'one made, one missing, a script and an unsafe path refused, one damaged' );
t_equals( 4, $done['report']['attachments'] ?? -1, 'the four rows without a file are left out and counted' );
$wid  = site_ids_of( 'ftnc_work' )[0];
$rows = json_decode( $GLOBALS['t_posts'][ $wid ]['meta']['_ftnc_work_files'], true );
t_equals( 1, count( $rows ), 'only the good row stays' );
t_equals( 'Fine', Fotonic_Field_Crypt::read( $rows[0]['label'], $site_key ), 'it is the right one' );
t_equals( array(), glob( $GLOBALS['t_base'] . '/2026/11/*.php' ) ?: array(), 'no script was written' );
t_true( ! file_exists( $GLOBALS['t_base'] . '/wp-config.pdf' ) && ! file_exists( dirname( $GLOBALS['t_base'] ) . '/wp-config.pdf' ), 'nothing escaped the uploads folder' );
$U2 = undo_job( $c['job_id'] );

// ---------------------------------------------------------------------------
echo "replace everything: confirmation, the Pro data check, the wipe\n";
// ---------------------------------------------------------------------------
t_true( Fotonic_Restore_Wipe::confirmed( 'replace' ) && Fotonic_Restore_Wipe::confirmed( ' REPLACE ' ), 'the word is not case sensitive' );
t_true( ! Fotonic_Restore_Wipe::confirmed( 'yes' ) && ! Fotonic_Restore_Wipe::confirmed( '' ) && ! Fotonic_Restore_Wipe::confirmed( null ), 'anything else is refused' );
$GLOBALS['t_posts'] = array();
$GLOBALS['t_posts'][1] = array( 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => 'Rossi', 'post_content' => '', 'meta' => array( '_ftnc_uid' => '99999999-9999-4999-8999-999999999999' ), 'terms' => array() );
$GLOBALS['t_posts'][2] = array( 'post_type' => 'ftnc_work', 'post_status' => 'trash', 'post_title' => 'In the bin', 'post_content' => '', 'meta' => array(), 'terms' => array() );
$GLOBALS['t_posts'][3] = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_content' => '', 'meta' => array(), 'terms' => array() );
$GLOBALS['t_posts'][4] = array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_title' => 'logo', 'post_content' => '', 'file' => $GLOBALS['t_base'] . '/mine.txt', 'meta' => array(), 'terms' => array() );
t_true( is_wp_error( Fotonic_Restore_Wipe::run( 'nope' ) ), 'the wipe refuses without the word' );
t_equals( 4, count( $GLOBALS['t_posts'] ), 'and removes nothing' );
t_equals( 2, Fotonic_Restore_Wipe::run( 'REPLACE' ), 'with the word it removes the records of the plugin, the bin included' );
t_equals( array( 3, 4 ), array_keys( $GLOBALS['t_posts'] ), 'a page of another plugin and a library file are untouched' );
$GLOBALS['t_posts'] = array();

// A full restore on a site that has data and a Vault.
$GLOBALS['t_posts'][1] = array( 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => 'Rossi', 'post_content' => '', 'meta' => array( '_ftnc_uid' => '99999999-9999-4999-8999-999999999999', '_ftnc_people' => blob( array( array( 'first_name' => 'Mario', 'last_name' => 'Rossi', 'is_main' => true ) ), $site_key ) ), 'terms' => array() );
$GLOBALS['t_posts'][3] = array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'About', 'post_content' => '', 'meta' => array(), 'terms' => array() );
Fotonic_Restore_Files::flush_cache();
$safety_calls = 0;
$J::use_safety_backup( function () use ( &$safety_calls ) { $safety_calls++; return array( 'path' => '/x/eleva-backup-safety.zip' ); } );
$sid = open_preview( $files_zip, $password );
$no_word = $J::create( $sid, array(), array( 'total' => true ) );
t_equals( 'ftnc_restore_confirm_needed', is_wp_error( $no_word ) ? $no_word->get_error_code() : '', 'a full restore needs the typed word' );
$GLOBALS['t_filters']['ftnc_backup_datasets'] = function ( $d ) { $d['ftnc_quote'] = array( 'post_type' => 'ftnc_quote', 'label' => 'Quotes', 'supports' => array( 'title' ), 'meta_keys' => array( '_ftnc_uid' ), 'taxonomies' => array(), 'ref_fields' => array() ); return $d; };
$GLOBALS['t_posts'][50] = array( 'post_type' => 'ftnc_quote', 'post_status' => 'publish', 'post_title' => 'Q', 'post_content' => '', 'meta' => array(), 'terms' => array() );
$blocked = $J::create( $sid, array(), array( 'total' => true, 'confirm' => 'REPLACE' ) );
t_equals( 'ftnc_restore_total_blocked', is_wp_error( $blocked ) ? $blocked->get_error_code() : '', 'refused while the site holds data of a dataset this version cannot restore' );
unset( $GLOBALS['t_filters']['ftnc_backup_datasets'] );
$off = Fotonic_Restore_Wipe::blocking();
t_equals( 'other', $off[0]['dataset'] ?? '', 'Pro data is seen even when its dataset is not registered (Pro switched off)' );
$blocked = $J::create( $sid, array(), array( 'total' => true, 'confirm' => 'REPLACE' ) );
t_equals( 'ftnc_restore_total_blocked', is_wp_error( $blocked ) ? $blocked->get_error_code() : '', 'so a full restore is refused then too' );
unset( $GLOBALS['t_posts'][50] );
Fotonic_Restore_Preview::flush_cache();
$sid_t = open_preview( $files_zip, $password );
$c = $J::create( $sid_t, array(), array( 'total' => true, 'confirm' => 'replace' ) );
t_equals( 'total', $c['mode'] ?? '', 'a full restore job' );
t_true( false === ( $c['undoable'] ?? true ), 'announced as not undoable' );
$un = $J::undo( $c['job_id'] );
t_equals( 'ftnc_restore_not_undoable', is_wp_error( $un ) ? $un->get_error_code() : '', 'undo is refused' );
$done = run_job( $c['job_id'] );
t_equals( 1, $safety_calls, 'the safety backup was made first' );
t_equals( 1, $done['deleted'] ?? -1, 'the site own customer was removed' );
t_equals( 5, $done['counts']['created'] ?? -1, 'every record of the backup was written' );
$titles = array();
foreach ( $GLOBALS['t_posts'] as $p ) { if ( 'ftnc_customer' === $p['post_type'] ) { $titles[] = $p['post_title']; } }
sort( $titles );
t_equals( array( 'Bianchi', 'Neri', 'Verdi' ), $titles, 'the site now holds the customers of the backup and nobody else' );
t_true( isset( $GLOBALS['t_posts'][3] ), 'a page of another plugin is untouched' );
t_equals( 3, count( attachment_posts() ), 'files came back too' );
$un = $J::undo( $done['job_id'] );
t_true( is_wp_error( $un ) && false !== strpos( $un->get_error_message(), 'eleva-backup-safety.zip' ), 'after the restore, undo still says where the way back is (the safety backup)' );
$d = $J::dismiss( $done['job_id'] );
t_equals( 'dismissed', $d['status'] ?? '', 'the result can be accepted' );

// The wipe does not remove what the job already wrote when it is resumed.
$GLOBALS['t_posts'] = array();
$GLOBALS['t_posts'][7] = array( 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => 'Written', 'post_content' => '', 'meta' => array( '_ftnc_import_batch' => 'job-x' ), 'terms' => array() );
$GLOBALS['t_posts'][8] = array( 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => 'Old', 'post_content' => '', 'meta' => array(), 'terms' => array() );
$r = Fotonic_Restore_Wipe::step( 'job-x', 10 );
t_equals( array( 7 ), array_keys( $GLOBALS['t_posts'] ), 'a resumed wipe keeps the records labelled with its own job' );
t_equals( 0, $r['remaining'], 'and reports nothing left to do' );


echo "the files a record points to include the images inside rows (a product gallery)\n";
$g_ds  = array( 'ref_fields' => array( '_ftnc_product_galleries' => 'json:[].images[]|attachment', '_ftnc_quote_pdf_id' => 'attachment' ) );
$g_rec = array( 'meta' => array( '_ftnc_product_galleries' => json_encode( array( array( 'images' => array( 7, 8 ) ), array( 'images' => array( 8, 9 ) ) ) ), '_ftnc_quote_pdf_id' => '4' ) );
t_equals( array( 7, 8, 9, 4 ), Fotonic_Restore_Files::ids_in_record( $g_ds, $g_rec ), 'every gallery image and the single file, each once' );

// ---------------------------------------------------------------------------
echo "work documents (fotonic/work-files): names, labels and bytes follow the key of the site\n";
// ---------------------------------------------------------------------------
$doc_plain  = "%PDF-1.4 contract for Rossi";
$doc_blob   = Fotonic_File_Crypt::encrypt( $doc_plain, $mk );
$img_plain  = "\x89PNG\r\n\x1a\nplain image";
$wf_rows    = array(
	array( 'type' => 'attachment', 'id' => 91, 'label' => Fotonic_Field_Crypt::encrypt( 'Etichetta', $mk ) ),
	array( 'type' => 'attachment', 'id' => 92, 'label' => '' ),
	array( 'type' => 'link', 'url' => 'https://example.test/g', 'label' => Fotonic_Field_Crypt::encrypt( 'Galleria', $mk ) ),
);
$wf_atts    = array(
	att( 91, Fotonic_Field_Crypt::encrypt( "L'«Contratto» \\ Rossi.pdf", $mk ), 'application/pdf', 'fotonic/work-files/AAAAAAAAAAAAAAAAAAAAAAAA.pdf.enc', $doc_blob ),
	att( 92, 'foto.png', 'image/png', 'fotonic/work-files/BBBBBBBBBBBBBBBBBBBBBBBB.png', $img_plain ),
);
$wf_zip     = $dir . '/eleva-backup-workfiles.zip';
build_archive( $wf_zip, $mk, files_backup( $mk, $wf_rows ), array(), array(), $wf_atts, '', array( 'files' => array( 91 => $doc_blob, 92 => $img_plain ) ) );

// Scenario B, another key: the encrypted document and its name and label are sealed with THIS site's key; the plain
// image is encrypted at import; no preview image is ever made in a private folder.
$GLOBALS['t_vault_setup'] = true;
$GLOBALS['t_site_mk']     = $site_key;
$GLOBALS['t_posts']       = array();
$repo->meta_generated     = array();
Fotonic_Restore_Files::flush_cache();
$sid  = open_preview( $wf_zip, $password );
$c    = $J::create( $sid, array() );
$done = run_job( $c['job_id'] );
t_equals( 2, $done['report']['files']['created'] ?? -1, 'two documents were made' );
$wf_atts_site = array();
foreach ( attachment_posts() as $id => $p ) { $wf_atts_site[ $id ] = $p; }
t_equals( 2, count( $wf_atts_site ), 'two attachments' );
$work_id = site_ids_of( 'ftnc_work' )[0];
$rows    = json_decode( $GLOBALS['t_posts'][ $work_id ]['meta']['_ftnc_work_files'], true );
$doc     = $wf_atts_site[ $rows[0]['id'] ];
$img     = $wf_atts_site[ $rows[1]['id'] ];
t_true( 0 === strpos( $doc['file'], $GLOBALS['t_base'] . '/fotonic/work-files/' ) && '.pdf.enc' === substr( $doc['file'], -8 ), 'the document stays in the work folder as .pdf.enc' );
t_equals( $doc_plain, Fotonic_File_Crypt::decrypt_file( $doc['file'], $site_key ), 'it opens with the key of this site' );
t_true( false === Fotonic_File_Crypt::decrypt_file( $doc['file'], $mk ), 'and no longer with the old key' );
t_equals( "L'«Contratto» \\ Rossi.pdf", Fotonic_Field_Crypt::read( $doc['post_title'], $site_key ), 'its name was sealed again with this site key, symbols included' );
t_equals( null, Fotonic_Field_Crypt::read( $doc['post_title'], null ), 'and does not read without the key' );
t_equals( 'Etichetta', Fotonic_Field_Crypt::read( $rows[0]['label'], $site_key ), 'the label of the document row was converted too' );
t_equals( 'Galleria', Fotonic_Field_Crypt::read( $rows[2]['label'], $site_key ), 'and the label of the link row' );
t_true( '.png.enc' === substr( $img['file'], -8 ) && false !== strpos( $img['file'], '/fotonic/work-files/' ), 'a plain image becomes .png.enc in the work folder' );
t_equals( $img_plain, Fotonic_File_Crypt::decrypt_file( $img['file'], $site_key ), 'it opens with the key of this site' );
t_equals( 'foto.png', Fotonic_Field_Crypt::read( $img['post_title'], $site_key ), 'its plain name was encrypted' );
t_equals( array(), $repo->meta_generated, 'no preview image was made for a private file' );
$J::dismiss( $c['job_id'] );
$R::close( $sid );

// Scenario C: a site with no Vault gets plain files and plain names.
$GLOBALS['t_vault_setup'] = false;
$GLOBALS['t_site_mk']     = null;
$GLOBALS['t_posts']       = array();
Fotonic_Restore_Files::flush_cache();
$sid  = open_preview( $wf_zip, $password );
$c    = $J::create( $sid, array(), array( 'plain' => true ) );
run_job( $c['job_id'] );
$work_id = site_ids_of( 'ftnc_work' )[0];
$rows    = json_decode( $GLOBALS['t_posts'][ $work_id ]['meta']['_ftnc_work_files'], true );
$atts    = attachment_posts();
$doc     = $atts[ $rows[0]['id'] ];
$img     = $atts[ $rows[1]['id'] ];
t_true( '.pdf' === substr( $doc['file'], -4 ) && $doc_plain === file_get_contents( $doc['file'] ) && false !== strpos( $doc['file'], '/fotonic/work-files/' ), 'no Vault: a plain .pdf in the work folder' );
t_equals( "L'«Contratto» \\ Rossi.pdf", $doc['post_title'], 'no Vault: the name is plain' );
t_equals( 'Etichetta', $rows[0]['label'], 'no Vault: the label is plain' );
t_true( '.png' === substr( $img['file'], -4 ) && $img_plain === file_get_contents( $img['file'] ), 'no Vault: the image stays plain' );
t_equals( array(), $repo->meta_generated, 'no preview image here either' );
$GLOBALS['t_vault_setup'] = true;
$GLOBALS['t_site_mk']     = $site_key;


t_done();
