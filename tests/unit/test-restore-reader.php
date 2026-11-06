<?php
// Unit test for Fotonic_Restore_Reader. No WordPress, no database, never the real Vault key:
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
}
final class Fotonic_Vault_Attempts {
	private $bucket;
	public static function acquire( $bucket ) { $a = new self(); $a->bucket = $bucket; return $a; }
	public function exhausted() { return ( $GLOBALS['t_fail'][ $this->bucket ] ?? 0 ) >= 5; }
	public function failed() { $GLOBALS['t_fail'][ $this->bucket ] = ( $GLOBALS['t_fail'][ $this->bucket ] ?? 0 ) + 1; }
	public function clear() { unset( $GLOBALS['t_fail'][ $this->bucket ] ); }
}

foreach ( array( 'crypto', 'vault-fingerprint', 'backup-keys', 'backup-cipher', 'backup-archive', 'restore-splitter', 'restore-upload', 'restore-reader' ) as $f ) {
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

$datasets = array(
	'customer' => array(
		array( 'ID' => 11, 'post_status' => 'publish', 'post_title' => 'Rossi Mario wedding', 'meta' => array( '_ftnc_people' => 'ENCRYPTED-BLOB-1', '_ftnc_uid' => 'u-1' ), 'terms' => array(), 'derived_terms' => array() ),
		array( 'ID' => 12, 'post_status' => 'publish', 'post_title' => 'Bianchi', 'meta' => array( '_ftnc_people' => 'ENCRYPTED-BLOB-2', '_ftnc_uid' => 'u-2' ), 'terms' => array(), 'derived_terms' => array() ),
		array( 'ID' => 13, 'post_status' => 'trash', 'post_title' => 'Verdi', 'meta' => array(), 'terms' => array(), 'derived_terms' => array() ),
	),
	'work'     => array( array( 'ID' => 21, 'post_title' => "Matrimonio \u{00e8} \"speciale\"", 'meta' => array( '_ftnc_customer_id' => '11' ), 'terms' => array(), 'derived_terms' => array() ) ),
);
$terms       = array( 'ftnc_work_payment_status' => array( array( 'term_id' => 4, 'slug' => 'paid' ) ) );
$options     = array( 'fotonic_payment_types' => array( array( 'slug' => 'cash', 'label' => 'Cash' ) ) );
$file_body   = random_bytes( 5000 );
$attachments = array( array( 'id' => 5, 'post_title' => 'photo', 'mime' => 'image/jpeg', 'size' => 5000, 'sha256' => hash( 'sha256', $file_body ) ) );

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

$dir = $GLOBALS['t_base'] . '/arch';
mkdir( $dir );
$archive = $dir . '/eleva-backup-test.zip';
$ak      = build_archive( $archive, $mk, $datasets, $terms, $options, $attachments, $file_body );

// ---------------------------------------------------------------------------
echo "without a credential\n";
// ---------------------------------------------------------------------------
$r = $R::open( $archive );
t_equals( 'ftnc_restore_credentials_needed', $r->code ?? '', 'locked site, no secret: credentials are needed' );
t_equals( 401, $r->data['status'] ?? 0, 'status 401' );
t_equals( 'https://old.example.test', $r->data['manifest']['site_url'] ?? '', 'the answer still carries the manifest summary' );
t_equals( 3, $r->data['manifest']['datasets']['customer'] ?? 0, 'with the counts' );
t_true( false === ( $r->data['vault_unlocked'] ?? null ), 'and says the site Vault is locked' );
$GLOBALS['t_site_mk'] = $other_mk;
$r = $R::open( $archive, '' );
t_equals( 'ftnc_restore_credentials_needed', $r->code ?? '', 'an unlocked Vault with ANOTHER key does not open it' );
t_true( true === ( $r->data['vault_unlocked'] ?? null ), 'and the answer says the Vault is unlocked, just not the same one' );
t_equals( array(), array_keys( $GLOBALS['t_opts'] ) === array() ? array() : array_filter( array_keys( $GLOBALS['t_opts'] ), function ( $n ) { return 0 === strpos( $n, 'fotonic_restore_s_' ); } ), 'no session was created' );
$GLOBALS['t_site_mk'] = null;

// ---------------------------------------------------------------------------
echo "wrong credentials and the limit\n";
// ---------------------------------------------------------------------------
for ( $i = 1; $i <= 5; $i++ ) {
	$r = $R::open( $archive, 'wrong password ' . $i );
	t_equals( 'ftnc_restore_wrong_credentials', $r->code ?? '', "wrong password {$i} refused" );
}
t_equals( 5, $GLOBALS['t_fail']['restore'] ?? 0, 'five failures counted in the restore bucket' );
t_true( ! isset( $GLOBALS['t_fail']['credentials'] ), 'and none in the unlock bucket' );
$r = $R::open( $archive, $password );
t_equals( 'ftnc_restore_locked', $r->code ?? '', 'after five failures even the right password waits' );
$GLOBALS['t_fail'] = array();

// ---------------------------------------------------------------------------
echo "password opens it\n";
// ---------------------------------------------------------------------------
$r = $R::open( $archive, $password );
t_true( is_array( $r ), 'the right password opens the archive' );
$sid = $r['session_id'] ?? '';
t_true( 1 === preg_match( '/^[a-f0-9]{32}$/', $sid ), 'session id is 128 random bits' );
t_true( false === $r['same_vault'], 'not the same Vault' );
t_equals( 'https://old.example.test', $r['manifest']['site_url'], 'manifest summary' );
$ids = array_map( function ( $c ) { return $c['id']; }, $r['collections'] );
t_equals( array( 'datasets:customer', 'datasets:work', 'terms:ftnc_work_payment_status', 'attachments' ), $ids, 'collections of the backup' );
t_true( ! isset( $r['collections'][0]['file'] ), 'no file names leave the server' );
t_equals( array( 'customer' => 3, 'work' => 1 ), $R::datasets( $sid ), 'dataset counts' );
t_true( ! isset( $GLOBALS['t_fail']['restore'] ), 'a success clears the failures' );
t_true( 0 === strpos( $_COOKIE['fotonic_restore_key'] ?? '', $sid . '.' ), 'the session key cookie is set for this session' );
$restore_dir = $U::restore_dir() . '/s-' . $sid;
t_true( is_dir( $restore_dir ) && ! file_exists( $restore_dir . '/data.json' ), 'the readable copy of the data is gone' );

$all = $R::records( $sid, 'datasets:customer', 0, 100 );
t_equals( $datasets['customer'], $all, 'every customer record comes back intact' );
t_equals( array( $datasets['customer'][1] ), $R::records( $sid, 'datasets:customer', 1, 1 ), 'paging: second record only' );
t_equals( array( $datasets['customer'][2] ), $R::records( $sid, 'datasets:customer', 2, 50 ), 'paging: last record' );
t_equals( array(), $R::records( $sid, 'datasets:customer', 3, 10 ), 'paging: past the end is empty' );
t_equals( $datasets['work'], $R::records( $sid, 'datasets:work', 0, 10 ), 'accents and quotes survive' );
t_equals( $terms['ftnc_work_payment_status'], $R::records( $sid, 'terms:ftnc_work_payment_status', 0, 10 ), 'terms' );
t_equals( $attachments, $R::records( $sid, 'attachments', 0, 10 ), 'attachment records' );
t_equals( 'ftnc_restore_unknown_collection', $R::records( $sid, 'datasets:nope', 0, 10 )->code ?? '', 'an unknown collection is refused' );
t_equals( $options, $R::options( $sid ), 'the archive options' );
t_equals( $mk, $R::old_master_key( $sid ), 'the old Master Key is available to the caller' );
t_equals( $ak, $R::archive_key( $sid ), 'and the archive key' );
t_equals( $archive, $R::source_path( $sid ), 'the archive path for attachments' );

$at_rest = '';
foreach ( glob( $restore_dir . '/*' ) as $f ) { $at_rest .= file_get_contents( $f ); }
t_true( '' !== $at_rest && false === strpos( $at_rest, 'Rossi' ) && false === strpos( $at_rest, 'Matrimonio' ) && false === strpos( $at_rest, 'wedding' ), 'nothing readable is left on disk' );
$stored = serialize( $GLOBALS['t_opts'] );
t_true( false === strpos( $stored, $mk ) && false === strpos( $stored, $ak ) && false === strpos( $stored, $password ), 'neither the Master Key, the archive key nor the password is stored in the clear' );

echo "the session needs its cookie and its user\n";
$cookie = $_COOKIE['fotonic_restore_key'];
unset( $_COOKIE['fotonic_restore_key'] );
t_equals( 'ftnc_restore_session_key_missing', $R::records( $sid, 'datasets:customer', 0, 1 )->code ?? '', 'no cookie: records are refused' );
t_equals( 'ftnc_restore_session_key_missing', $R::old_master_key( $sid )->code ?? '', 'no cookie: the old Master Key is not available' );
$_COOKIE['fotonic_restore_key'] = substr( $sid, 0, 31 ) . 'x.' . explode( '.', $cookie )[1];
t_equals( 'ftnc_restore_session_key_missing', $R::old_master_key( $sid )->code ?? '', 'a cookie of another session does not count' );
$_COOKIE['fotonic_restore_key'] = $sid . '.' . Fotonic_Vault_Fingerprint::b64u_encode( random_bytes( 32 ) );
t_equals( 'ftnc_restore_session_key_missing', $R::old_master_key( $sid )->code ?? '', 'a wrong key does not unwrap anything' );
t_true( is_wp_error( $R::records( $sid, 'datasets:customer', 0, 1 ) ), 'and does not decrypt a record' );
$_COOKIE['fotonic_restore_key'] = $cookie;
$GLOBALS['t_user'] = 99;
t_equals( 'ftnc_restore_session_expired', $R::records( $sid, 'datasets:customer', 0, 1 )->code ?? '', 'another WordPress user cannot read the session' );
$GLOBALS['t_user'] = 5;
t_true( is_array( $R::records( $sid, 'datasets:customer', 0, 1 ) ), 'the owner reads it again' );

echo "verify_member\n";
t_true( true === $R::verify_member( $sid, 'files/5.enc' ), 'an attachment matches its checksum' );
t_equals( 'ftnc_restore_damaged', $R::verify_member( $sid, '../key.json' )->code ?? '', 'an unsafe name is refused' );
t_equals( 'ftnc_restore_damaged', $R::verify_member( $sid, 'files/77.enc' )->code ?? '', 'a member the manifest does not list is refused' );

echo "a second open closes the first\n";
$r2 = $R::open( $archive, $password );
t_true( is_array( $r2 ) && $r2['session_id'] !== $sid, 'a new session' );
t_equals( 'ftnc_restore_session_expired', $R::records( $sid, 'datasets:customer', 0, 1 )->code ?? '', 'the old one is closed' );
t_true( ! is_dir( $U::restore_dir() . '/s-' . $sid ), 'with its folder' );
t_true( is_file( $archive ), 'an archive from the backups folder is never deleted' );
$sid = $r2['session_id'];

// ---------------------------------------------------------------------------
echo "recovery code and phrase\n";
// ---------------------------------------------------------------------------
$r = $R::open( $archive, $code );
t_true( is_array( $r ), 'the recovery code opens it' );
t_equals( $mk, $R::old_master_key( $r['session_id'] ), 'same Master Key through the code' );
$r = $R::open( $archive, strtoupper( $phrase ) . '  ' );
t_true( is_array( $r ), 'the recovery phrase opens it (case and spaces ignored)' );
$sid = $r['session_id'];

// ---------------------------------------------------------------------------
echo "same Vault, no credential\n";
// ---------------------------------------------------------------------------
$GLOBALS['t_site_mk'] = $mk;
$r = $R::open( $archive );
t_true( is_array( $r ) && true === $r['same_vault'], 'the unlocked Vault of the same site opens it without asking' );
t_true( ! isset( $GLOBALS['t_fail']['restore'] ), 'no attempt counted' );
t_equals( $mk, $R::old_master_key( $r['session_id'] ), 'the old Master Key is the site key' );
t_true( true === $R::is_same_vault( $r['session_id'] ), 'the session remembers it' );
$GLOBALS['t_site_mk'] = null;
$sid = $r['session_id'];

// ---------------------------------------------------------------------------
echo "close removes everything\n";
// ---------------------------------------------------------------------------
$up = $U::start( filesize( $archive ) );
$U::chunk( $up['upload_id'], 0, substr( file_get_contents( $archive ), 0, $U::CHUNK_SIZE ) );
for ( $i = 1; $i < $up['total_chunks']; $i++ ) { $U::chunk( $up['upload_id'], $i, substr( file_get_contents( $archive ), $i * $U::CHUNK_SIZE, $U::CHUNK_SIZE ) ); }
$uploaded = $U::finish( $up['upload_id'] );
t_true( is_string( $uploaded ), 'the archive arrives by upload' );
$r   = $R::open( $uploaded, $password );
$sid = $r['session_id'];
$R::close( $sid );
t_true( ! is_dir( $U::restore_dir() . '/s-' . $sid ), 'working folder removed' );
t_true( ! isset( $GLOBALS['t_opts'][ $R::OPTION_PREFIX . $sid ] ), 'session option removed' );
t_true( ! is_file( $uploaded ), 'the uploaded archive is removed' );
t_true( ! isset( $_COOKIE['fotonic_restore_key'] ), 'and the cookie' );
t_equals( 'ftnc_restore_session_expired', $R::datasets( $sid )->code ?? '', 'the session is gone' );
$R::close( $sid );
$R::close( 'not-an-id' );
t_true( true, 'closing twice, or a bad id, is harmless' );

echo "expiry\n";
$r   = $R::open( $archive, $password );
$sid = $r['session_id'];
$GLOBALS['t_opts'][ $R::OPTION_PREFIX . $sid ]['created'] = time() - $R::TTL - 1;
t_equals( 'ftnc_restore_session_expired', $R::datasets( $sid )->code ?? '', 'a session older than two hours is refused' );
t_true( ! is_dir( $U::restore_dir() . '/s-' . $sid ), 'and removed' );
$r   = $R::open( $archive, $password );
$sid = $r['session_id'];
$GLOBALS['t_opts'][ $R::OPTION_REGISTRY ][ $sid ]['created'] = time() - $R::TTL - 1;
mkdir( $U::restore_dir() . '/s-' . str_repeat( 'd', 32 ) );
t_equals( 1, $R::cleanup_all(), 'cleanup removes the expired session' );
t_true( ! is_dir( $U::restore_dir() . '/s-' . $sid ) && ! is_dir( $U::restore_dir() . '/s-' . str_repeat( 'd', 32 ) ), 'and a folder nobody owns' );

// ---------------------------------------------------------------------------
echo "bad archives\n";
// ---------------------------------------------------------------------------
file_put_contents( $dir . '/notzip.zip', 'this is not a zip' );
t_equals( 'ftnc_restore_bad_file', $R::open( $dir . '/notzip.zip', $password )->code ?? '', 'not a zip' );
t_equals( 'ftnc_restore_bad_file', $R::open( $dir . '/missing.zip', $password )->code ?? '', 'missing file' );
file_put_contents( $dir . '/truncated.zip', substr( file_get_contents( $archive ), 0, 600 ) );
t_equals( 'ftnc_restore_bad_file', $R::open( $dir . '/truncated.zip', $password )->code ?? '', 'truncated zip' );

$z = new ZipArchive();
$z->open( $dir . '/random.zip', ZipArchive::CREATE | ZipArchive::OVERWRITE );
$z->addFromString( 'notes.txt', 'hello' );
$z->addFromString( 'photo.jpg', 'not really' );
$z->close();
t_equals( 'ftnc_restore_not_a_backup', $R::open( $dir . '/random.zip', $password )->code ?? '', 'an ordinary zip of other files is "not a backup", not "damaged"' );

$cases = array(
	'tamper_data'  => array( 'ftnc_restore_damaged', 'a changed data.enc fails its checksum' ),
	'extra_member' => array( 'ftnc_restore_damaged', 'an unlisted member is refused' ),
	'bad_name'     => array( 'ftnc_restore_damaged', 'a member name with .. is refused' ),
	'format'       => array( 'ftnc_restore_newer_format', 'a newer archive format is refused' ),
	'scheme'       => array( 'ftnc_restore_newer_format', 'a newer key scheme is refused' ),
	'no_key'       => array( 'ftnc_restore_not_a_backup', 'an archive without key.json is refused' ),
);
foreach ( $cases as $mod => $expect ) {
	$p = $dir . '/case-' . $mod . '.zip';
	build_archive( $p, $mk, $datasets, $terms, $options, $attachments, $file_body, array( $mod => ( 'format' === $mod ? 2 : ( 'scheme' === $mod ? 3 : true ) ) ) );
	$r = $R::open( $p, $password );
	t_equals( $expect[0], $r->code ?? '', $expect[1] );
}
$p = $dir . '/case-oldformat.zip';
build_archive( $p, $mk, $datasets, $terms, $options, $attachments, $file_body, array( 'format' => 0 ) );
t_equals( 'ftnc_restore_damaged', $R::open( $p, $password )->code ?? '', 'format 0 is damaged, not newer' );

$p = $dir . '/case-file.zip';
build_archive( $p, $mk, $datasets, $terms, $options, $attachments, $file_body, array( 'tamper_file' => true ) );
$r = $R::open( $p, $password );
t_true( is_array( $r ), 'a changed attachment does not stop the archive from opening (checked per file)' );
t_equals( 'ftnc_restore_damaged', $R::verify_member( $r['session_id'], 'files/5.enc' )->code ?? '', 'but verify_member catches it' );

echo "an archive of another Vault\n";
$p = $dir . '/other.zip';
build_archive( $p, $mk, $datasets, $terms, $options, $attachments, $file_body );
$r = $R::open( $p, 'some other vault password' );
t_equals( 'ftnc_restore_wrong_credentials', $r->code ?? '', 'a password of another Vault never opens it' );

echo "purge_all (plugin deactivation)\n";
$r1  = $R::open( $archive, $password );
$up2 = $U::start( 100 );
$R::purge_all();
t_true( ! is_dir( $U::restore_dir() . '/s-' . $r1['session_id'] ), 'a session is ended' );
t_equals( 'ftnc_restore_unknown_upload', $U::status( $up2['upload_id'] )->code ?? '', 'an upload is removed' );
t_true( is_file( $archive ), 'the backups folder is untouched' );

t_done();
