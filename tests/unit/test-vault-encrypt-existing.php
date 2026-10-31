<?php
// Unit test for the field walker (Fotonic_Vault_Fields) and the encrypt-existing job
// (Fotonic_Vault_Encrypt_Existing). No WordPress, no database: the repository, options and
// hooks are in-memory stand-ins, so nothing here can touch a real site.
ob_start();
require __DIR__ . '/../lib/assert.php';
define( 'ABSPATH', __DIR__ . '/' );
define( 'COOKIEPATH', '/' );
define( 'COOKIE_DOMAIN', '' );
define( 'AUTH_KEY', 'unit-test-auth-key' );

$GLOBALS['t_opts']    = array();
$GLOBALS['t_actions'] = array();
$GLOBALS['t_scope']   = null;
$GLOBALS['t_filters'] = array();

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['t_opts'] ) ? $GLOBALS['t_opts'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['t_opts'][ $n ] = $v; return true; }
function add_option( $n, $v = '', $d = '', $a = null ) { if ( array_key_exists( $n, $GLOBALS['t_opts'] ) ) { return false; } $GLOBALS['t_opts'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['t_opts'][ $n ] ); return true; }
function apply_filters( $tag, $value ) {
	if ( 'ftnc_vault_encrypt_scope' === $tag ) { return $GLOBALS['t_scope']; }
	foreach ( $GLOBALS['t_filters'][ $tag ] ?? array() as $cb ) { $value = $cb( $value ); }
	return $value;
}
function do_action( $tag, ...$args ) { $GLOBALS['t_actions'][] = $tag; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function wp_generate_password( $len = 12, $special = true ) { return substr( bin2hex( random_bytes( 16 ) ), 0, $len ); }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function __( $t, $d = '' ) { return $t; }
function get_current_user_id() { return 1; }
function wp_get_session_token() { return 'tok'; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return $v; }
function is_ssl() { return false; }

$GLOBALS['t_rows'] = array(); // option_name => option_value of the raw lock row (INSERT IGNORE / compare-and-delete)
class T_Wpdb {
	public $options = 'wp_options';
	public function prepare( $q, ...$args ) {
		$i = 0;
		return preg_replace_callback( '/%s/', function () use ( &$i, $args ) { return "'" . addslashes( (string) $args[ $i++ ] ) . "'"; }, $q );
	}
	public function query( $q ) {
		if ( 0 === strpos( $q, 'INSERT IGNORE' ) ) {
			preg_match( "/VALUES \( '([^']*)', '([^']*)'/", $q, $m );
			if ( isset( $GLOBALS['t_rows'][ $m[1] ] ) ) { return 0; }
			$GLOBALS['t_rows'][ $m[1] ] = $m[2];
			return 1;
		}
		if ( 0 === strpos( $q, 'DELETE FROM' ) ) {
			if ( preg_match( "/option_name = '([^']*)' AND option_value = '([^']*)'/", $q, $m ) ) {
				if ( isset( $GLOBALS['t_rows'][ $m[1] ] ) && $GLOBALS['t_rows'][ $m[1] ] === $m[2] ) { unset( $GLOBALS['t_rows'][ $m[1] ] ); return 1; }
				return 0;
			}
			preg_match( "/option_name = '([^']*)'/", $q, $m );
			unset( $GLOBALS['t_rows'][ $m[1] ] );
			return 1;
		}
		return 0;
	}
	public function get_var( $q ) {
		preg_match( "/option_name = '([^']*)'/", $q, $m );
		return isset( $GLOBALS['t_rows'][ $m[1] ] ) ? $GLOBALS['t_rows'][ $m[1] ] : null;
	}
}
$wpdb = new T_Wpdb();

class T_Repo {
	public $posts = array(); // id => array( type, post_title, post_content, post_name )
	public $meta  = array(); // id => array( key => string )
	public function add( $id, $type, array $fields = array(), array $meta = array() ) {
		$this->posts[ $id ] = array_merge( array( 'type' => $type, 'post_title' => '', 'post_content' => '', 'post_name' => 'slug-' . $id ), $fields );
		$this->meta[ $id ]  = $meta;
	}
	private function in_scope( $id, $scope ) { return null === $scope || in_array( $id, $scope, true ); }
	public function ids_after( string $t, int $after, int $limit, ?array $scope = null ): array {
		$out = array();
		foreach ( $this->posts as $id => $p ) {
			if ( $p['type'] === $t && $id > $after && $this->in_scope( $id, $scope ) ) { $out[] = $id; }
		}
		sort( $out );
		return array_slice( $out, 0, $limit );
	}
	public function count( string $t, ?array $scope = null ): int {
		$n = 0;
		foreach ( $this->posts as $id => $p ) { if ( $p['type'] === $t && $this->in_scope( $id, $scope ) ) { $n++; } }
		return $n;
	}
	public function get_post_field( int $id, string $f ): string { return (string) $this->posts[ $id ][ $f ]; }
	public function set_post_fields( int $id, array $f ): bool { foreach ( $f as $k => $v ) { $this->posts[ $id ][ $k ] = $v; } return true; }
	public function get_meta( int $id, string $k ): string { return (string) ( $this->meta[ $id ][ $k ] ?? '' ); }
	public function set_meta( int $id, string $k, string $v ): bool { $this->meta[ $id ][ $k ] = $v; return true; }
}

require __DIR__ . '/../../includes/class-crypto.php';
require __DIR__ . '/../../includes/class-field-crypt.php';
require __DIR__ . '/../../includes/class-vault-attempts.php';
require __DIR__ . '/../../includes/class-backup-keys.php';
require __DIR__ . '/../../includes/class-vault.php';
require __DIR__ . '/../../includes/class-rest-api.php';
require __DIR__ . '/../../includes/class-vault-fields.php';
require __DIR__ . '/../../includes/class-vault-encrypt-existing.php';

$K  = random_bytes( 32 );
$K2 = random_bytes( 32 );
$repo = new T_Repo();
Fotonic_Vault_Fields::use_repo( $repo );
$groups = Fotonic_Vault_Fields::groups();

// ---- 1. is_plain / encrypt_value -------------------------------------------------------------
t_equals( false, Fotonic_Vault_Fields::is_plain( '', 'f1', $K ), 'empty: nothing to protect' );
t_equals( true, Fotonic_Vault_Fields::is_plain( 'Una nota', 'f1', $K ), 'f1: plain text is plain' );
t_equals( false, Fotonic_Vault_Fields::is_plain( Fotonic_Field_Crypt::encrypt( 'x', $K ), 'f1', $K ), 'f1: prefixed is encrypted' );
t_equals( true, Fotonic_Vault_Fields::is_plain( 'f0:f1:escaped', 'f1', $K ), 'f1: an escaped plain text is still plain' );
t_equals( true, Fotonic_Vault_Fields::is_plain( 'Anna', 'v1', $K ), 'v1: a short name is plain' );
t_equals( false, Fotonic_Vault_Fields::is_plain( Fotonic_Crypto::encrypt( 'Anna', $K ), 'v1', $K ), 'v1: ciphertext of this key is encrypted' );
t_equals( false, Fotonic_Vault_Fields::is_plain( Fotonic_Crypto::deterministic_encrypt( 'a@b.it', $K ), 'v1d', $K ), 'v1d: prefixed is encrypted' );
t_equals( false, Fotonic_Vault_Fields::is_plain( 'v2:whatever', 'v1', $K ), 'v2 browser layer is never touched' );
$ambiguous = str_repeat( 'AbCd1234', 4 ); // base64-looking plain text, 32 characters, no spaces
t_equals( true, Fotonic_Vault_Fields::is_plain( $ambiguous, 'v1', $K ), 'v1: a long base64-looking PLAIN text is not trusted as ciphertext' );
// CBC has no MAC: a wrong key sometimes (about 1 in 256) decrypts to garbage with a valid padding and the value then counts as
// encrypted, i.e. is left alone. The CBC-shaped fallback leaves it alone anyway, but keep the test deterministic:
// pick a key that fails to decrypt this value.
$stranded = Fotonic_Crypto::encrypt( 'Anna', $K2 );
while ( '' !== Fotonic_Crypto::decrypt( $stranded, $K ) ) { $stranded = Fotonic_Crypto::encrypt( 'Anna', $K2 ); }
t_equals( false, Fotonic_Vault_Fields::is_plain( $stranded, 'v1', $K ), 'v1: a ciphertext of another key (a reset Vault) is left alone, so it keeps reading as unreadable' );
$enc = Fotonic_Vault_Fields::encrypt_value( 'f0:f1:escaped', 'f1', $K );
t_equals( 'f1:escaped', Fotonic_Field_Crypt::read( $enc, $K ), 'f1: the escape is removed before encrypting' );

// ---- 2. one record of each group ---------------------------------------------------------------
$people = array(
	array( 'first_name' => 'Anna', 'last_name' => Fotonic_Crypto::encrypt( 'Rossi', $K ), 'email' => 'anna@example.com', 'phone' => Fotonic_Crypto::deterministic_encrypt( '123', $K ), 'gender' => 'female', 'is_main' => true, 'address' => $ambiguous, 'tin' => '' ),
	array( 'first_name' => 'Luca', 'email' => '' ),
);
$repo->add( 10, 'ftnc_customer', array(), array( '_ftnc_people' => json_encode( $people ) ) );
t_equals( 4, Fotonic_Vault_Fields::process_record( 10, $groups['customers'], $K, false ), 'customer: read only counts first_name, email, address of the first person and first_name of the second' );
t_equals( json_encode( $people ), $repo->meta[10]['_ftnc_people'], 'customer: counting writes nothing' );
t_equals( 4, Fotonic_Vault_Fields::process_record( 10, $groups['customers'], $K, true ), 'customer: write encrypts the same four' );
$after = json_decode( $repo->meta[10]['_ftnc_people'], true );
t_equals( 'Anna', Fotonic_Crypto::decrypt( $after[0]['first_name'], $K ), 'customer: first_name now decrypts' );
t_equals( 'Rossi', Fotonic_Crypto::decrypt( $after[0]['last_name'], $K ), 'customer: an already encrypted last_name is untouched' );
t_equals( 'anna@example.com', Fotonic_Crypto::deterministic_decrypt( $after[0]['email'], $K ), 'customer: email is deterministic and decrypts' );
t_equals( $ambiguous, Fotonic_Crypto::decrypt( $after[0]['address'], $K ), 'customer: the ambiguous plain address was encrypted and decrypts to itself' );
t_equals( 'female', $after[0]['gender'], 'customer: gender and flags stay as they were' );
t_equals( 'Luca', Fotonic_Crypto::decrypt( $after[1]['first_name'], $K ), 'customer: second person too' );
t_equals( '', $after[1]['email'], 'customer: an empty value stays empty' );
$stored = $repo->meta[10]['_ftnc_people'];
t_equals( 0, Fotonic_Vault_Fields::process_record( 10, $groups['customers'], $K, true ), 'customer: a second pass finds nothing' );
t_equals( $stored, $repo->meta[10]['_ftnc_people'], 'customer: a second pass changes nothing (no double encryption)' );

$repo->add( 20, 'ftnc_work', array( 'post_content' => '<p>Note del matrimonio</p>' ), array(
	'_ftnc_event_addresses' => json_encode( array( array( 'label' => 'Chiesa', 'street' => 'Via Roma 1' ) ) ),
	'_ftnc_memory_cards'    => json_encode( array( array( 'card_id' => 5, 'notes' => 'scheda piena' ), array( 'card_id' => 6, 'notes' => '' ) ) ),
	'_ftnc_work_services'   => json_encode( array( array( 'service_id' => 3, 'price_override' => 100, 'notes_override' => 'extra ore' ) ) ),
) );
t_equals( 4, Fotonic_Vault_Fields::process_record( 20, $groups['works'], $K, true ), 'work: content, street, one card note, one service note' );
t_equals( '<p>Note del matrimonio</p>', Fotonic_Field_Crypt::read( $repo->posts[20]['post_content'], $K ), 'work: content decrypts' );
$addr = json_decode( $repo->meta[20]['_ftnc_event_addresses'], true );
t_equals( 'Chiesa', $addr[0]['label'], 'work: the address label stays plain (it goes to Google)' );
t_equals( 'Via Roma 1', Fotonic_Crypto::decrypt( $addr[0]['street'], $K ), 'work: street decrypts' );
$cards = json_decode( $repo->meta[20]['_ftnc_memory_cards'], true );
t_equals( 5, $cards[0]['card_id'], 'work: card id untouched' );
t_equals( 'scheda piena', Fotonic_Field_Crypt::read( $cards[0]['notes'], $K ), 'work: card note decrypts' );
t_equals( '', $cards[1]['notes'], 'work: an empty card note stays empty' );
$svc = json_decode( $repo->meta[20]['_ftnc_work_services'], true );
t_equals( 100, $svc[0]['price_override'], 'work: the price override is untouched (amounts stay plain)' );
t_equals( 'extra ore', Fotonic_Field_Crypt::read( $svc[0]['notes_override'], $K ), 'work: service note decrypts' );

$repo->add( 30, 'ftnc_service', array( 'post_title' => 'Album fotografico', 'post_name' => 'album-fotografico' ), array( '_ftnc_base_price' => '120.5', '_ftnc_notes' => '<p>Stampa</p>' ) );
t_equals( 3, Fotonic_Vault_Fields::process_record( 30, $groups['services'], $K, true ), 'service: title, price, notes' );
t_equals( 'Album fotografico', Fotonic_Field_Crypt::read( $repo->posts[30]['post_title'], $K ), 'service: title decrypts' );
t_equals( 'service-30', $repo->posts[30]['post_name'], 'service: the slug no longer carries the title' );
t_equals( '120.5', Fotonic_Field_Crypt::read( $repo->meta[30]['_ftnc_base_price'], $K ), 'service: price decrypts to the same text' );
t_equals( '<p>Stampa</p>', Fotonic_Field_Crypt::read( $repo->meta[30]['_ftnc_notes'], $K ), 'service: notes decrypt' );

// Damaged JSON and non-row values are left alone.
$repo->add( 40, 'ftnc_work', array(), array( '_ftnc_memory_cards' => '{broken', '_ftnc_work_services' => json_encode( array( 'x', 5, null ) ) ) );
t_equals( 0, Fotonic_Vault_Fields::process_record( 40, $groups['works'], $K, true ), 'broken JSON and non-row entries: nothing to do, no crash' );
t_equals( '{broken', $repo->meta[40]['_ftnc_memory_cards'], 'broken JSON is left exactly as it was' );

// ---- 3. the job, from a fresh site -------------------------------------------------------------
$repo = new T_Repo();
Fotonic_Vault_Fields::use_repo( $repo );
for ( $i = 1; $i <= 5; $i++ ) {
	$repo->add( 100 + $i, 'ftnc_customer', array(), array( '_ftnc_people' => json_encode( array( array( 'first_name' => "Nome $i", 'email' => "n$i@example.com" ) ) ) ) );
	$repo->add( 200 + $i, 'ftnc_work', array( 'post_content' => "Nota $i" ), array() );
	$repo->add( 300 + $i, 'ftnc_service', array( 'post_title' => "Servizio $i", 'post_name' => "servizio-$i" ), array( '_ftnc_base_price' => (string) ( $i * 10 ) ) );
}
$snapshot_before = json_encode( array( $repo->posts, $repo->meta ) );
$GLOBALS['t_opts'] = array();

t_equals( false, Fotonic_Vault_Encrypt_Existing::is_pending(), 'no job at first' );
t_equals( 'none', Fotonic_Vault_Encrypt_Existing::status()['status'], 'status without a job' );
t_equals( true, Fotonic_Vault_Encrypt_Existing::create(), 'create(): a job is made' );
t_equals( false, Fotonic_Vault_Encrypt_Existing::create(), 'create(): a second call changes nothing' );
t_equals( 'pending', Fotonic_Vault_Encrypt_Existing::job()['status'], 'the job is pending' );
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_blocking(), 'a pending job never blocks writes' );
t_equals( true, Fotonic_Vault_Encrypt_Existing::is_pending(), 'a pending job counts as pending' );
t_equals( json_encode( array( $repo->posts, $repo->meta ) ), $snapshot_before, 'creating a job writes nothing to any record' );

t_equals( 'no_key', Fotonic_Vault_Encrypt_Existing::run_batch( 'short' )['error'], 'a key of the wrong length is refused' );
t_equals( 'pending', Fotonic_Vault_Encrypt_Existing::job()['status'], 'a refused call does not start the job' );

$phases = array(); $percents = array(); $guard = 0;
do {
	$st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 2 );
	$phases[]   = $st['phase'];
	$percents[] = $st['percent'];
	if ( 1 === count( $phases ) ) {
		t_equals( 'running', Fotonic_Vault_Encrypt_Existing::job()['status'], 'the first step starts the job' );
		t_equals( true, Fotonic_Vault_Encrypt_Existing::is_blocking(), 'a running job blocks writes' );
		t_equals( 15, $st['records'], 'scan: fifteen records counted' );
	}
} while ( 'done' !== $st['status'] && ++$guard < 200 );
t_equals( 'done', $st['status'], 'the job finishes' );
t_equals( 100, $st['percent'], 'progress ends at 100' );
t_equals( true, $percents === array_values( array_unique( $percents, SORT_REGULAR ) ) || $percents === (function ( $p ) { $s = $p; sort( $s ); return $s; })( $percents ), 'progress never goes backwards' );
t_equals( array( 'analyze', 'prepare', 'encrypt', 'finish' ), array_values( array_unique( $phases ) ), 'the phases come in order (each answer shows the phase the job is now in; scan has just run)' );
t_equals( null, Fotonic_Vault_Encrypt_Existing::job(), 'a finished job is forgotten' );
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_blocking(), 'nothing blocks after the job' );
t_equals( Fotonic_Vault_Fields::signatures(), Fotonic_Vault_Encrypt_Existing::covered(), 'the covered field signatures are recorded' );
t_equals( array( 'ftnc_vault_encrypt_finished' ), $GLOBALS['t_actions'], 'Pro is told once' );
t_equals( false, array_key_exists( Fotonic_Vault_Encrypt_Existing::OPTION_LOCK, $GLOBALS['t_rows'] ), 'the lock is released' );
for ( $i = 1; $i <= 5; $i++ ) {
	t_equals( "Nota $i", Fotonic_Field_Crypt::read( $repo->posts[ 200 + $i ]['post_content'], $K ), "work $i: note encrypted and readable" );
	t_equals( "Servizio $i", Fotonic_Field_Crypt::read( $repo->posts[ 300 + $i ]['post_title'], $K ), "service $i: title encrypted and readable" );
	$p = json_decode( $repo->meta[ 100 + $i ]['_ftnc_people'], true );
	t_equals( "Nome $i", Fotonic_Crypto::decrypt( $p[0]['first_name'], $K ), "customer $i: name encrypted and readable" );
}

// ---- 4. a second job over encrypted data does nothing ------------------------------------------
$stored = json_encode( array( $repo->posts, $repo->meta ) );
Fotonic_Vault_Encrypt_Existing::create();
$guard = 0;
do { $st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 100 ); } while ( 'done' !== $st['status'] && ++$guard < 50 );
t_equals( $stored, json_encode( array( $repo->posts, $repo->meta ) ), 'a second pass changes nothing (no double encryption)' );

// ---- 5. pause, resume and an interrupted job ---------------------------------------------------
$repo = new T_Repo();
Fotonic_Vault_Fields::use_repo( $repo );
for ( $i = 1; $i <= 7; $i++ ) { $repo->add( 500 + $i, 'ftnc_work', array( 'post_content' => "Nota $i" ), array() ); }
$GLOBALS['t_opts'] = array();
Fotonic_Vault_Encrypt_Existing::create();
for ( $n = 0; $n < 5; $n++ ) { Fotonic_Vault_Encrypt_Existing::run_batch( $K, 2 ); } // scan, analyze x4... stop mid-way
t_equals( true, Fotonic_Vault_Encrypt_Existing::pause(), 'pause() works on a running job' );
t_equals( 'paused', Fotonic_Vault_Encrypt_Existing::job()['status'], 'the job is paused' );
t_equals( true, Fotonic_Vault_Encrypt_Existing::is_blocking(), 'a paused job still blocks writes (no record half done)' );
$cursor = Fotonic_Vault_Encrypt_Existing::job()['last_id'] . '/' . Fotonic_Vault_Encrypt_Existing::job()['phase'];
$st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 2 );
t_equals( 'paused', $st['status'], 'run_batch() on a paused job does nothing' );
t_equals( $cursor, Fotonic_Vault_Encrypt_Existing::job()['last_id'] . '/' . Fotonic_Vault_Encrypt_Existing::job()['phase'], 'a paused job does not move the cursor' );
t_equals( false, Fotonic_Vault_Encrypt_Existing::pause(), 'pause() on a paused job is refused' );
t_equals( true, Fotonic_Vault_Encrypt_Existing::resume(), 'resume() works' );
t_equals( false, Fotonic_Vault_Encrypt_Existing::resume(), 'resume() on a running job is refused' );
$guard = 0;
do { $st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 2 ); } while ( 'done' !== $st['status'] && ++$guard < 100 );
t_equals( 'done', $st['status'], 'the resumed job finishes' );
$ok = 0;
for ( $i = 1; $i <= 7; $i++ ) { if ( "Nota $i" === Fotonic_Field_Crypt::read( $repo->posts[ 500 + $i ]['post_content'], $K ) ) { $ok++; } }
t_equals( 7, $ok, 'after pause and resume every note is encrypted exactly once and readable' );

// ---- 6. the step lock (an atomic INSERT IGNORE row, like the attempts lock) --------------------
$GLOBALS['t_opts'] = array(); $GLOBALS['t_rows'] = array();
Fotonic_Vault_Encrypt_Existing::create();
$LOCK = Fotonic_Vault_Encrypt_Existing::OPTION_LOCK;
$GLOBALS['t_rows'][ $LOCK ] = time() . '.someone-else';
$st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 2 );
t_equals( true, $st['busy'] ?? false, 'a held lock: the call answers busy' );
t_equals( 'pending', Fotonic_Vault_Encrypt_Existing::job()['status'], 'a busy call does nothing' );
t_equals( time() . '.someone-else' === $GLOBALS['t_rows'][ $LOCK ], true, 'a busy call leaves the other request\'s lock alone' );
$GLOBALS['t_rows'][ $LOCK ] = ( time() - 500 ) . '.crashed';
$st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 2 );
t_equals( false, $st['busy'] ?? false, 'a stale lock is taken over' );
t_equals( 'running', Fotonic_Vault_Encrypt_Existing::job()['status'], 'and the step runs' );
t_equals( false, array_key_exists( $LOCK, $GLOBALS['t_rows'] ), 'and the lock is released afterwards' );

// ---- 6b. failed states never block writes, can be retried, and can be cancelled -----------------
$repo = new T_Repo();
Fotonic_Vault_Fields::use_repo( $repo );
$repo->add( 801, 'ftnc_work', array( 'post_content' => 'plain' ), array() );
$GLOBALS['t_opts'] = array(); $GLOBALS['t_rows'] = array();
Fotonic_Vault_Encrypt_Existing::create();
$GLOBALS['t_filters']['ftnc_vault_encrypt_self_test'][] = function () { return false; };
$guard = 0;
do { $st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 10 ); } while ( 'error' !== $st['status'] && ++$guard < 10 );
t_equals( [ 'error', 'self_test_failed', 'prepare' ], [ $st['status'], $st['error'], $st['phase'] ], 'a failed self-test: status error, code self_test_failed, still in prepare' );
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_blocking(), 'an error state does not block writes' );
t_equals( true, Fotonic_Vault_Encrypt_Existing::is_pending(), 'the job is still there to retry or cancel' );
t_equals( 'plain', $repo->posts[801]['post_content'], 'nothing was encrypted before the self-test passed' );
$GLOBALS['t_filters']['ftnc_vault_encrypt_self_test'] = array(); // the cause is gone: a retry works
$guard = 0;
do { $st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 10 ); } while ( 'done' !== $st['status'] && ++$guard < 20 );
t_equals( 'plain', Fotonic_Field_Crypt::read( $repo->posts[801]['post_content'], $K ), 'a retry finishes the job' );

// Writes that never stick leave records plain: after three rounds the job stops with an error (no endless loop).
class T_Frozen_Repo extends T_Repo {
	public function set_post_fields( int $id, array $f ): bool { return true; }
	public function set_meta( int $id, string $k, string $v ): bool { return true; }
}
$repo = new T_Frozen_Repo();
Fotonic_Vault_Fields::use_repo( $repo );
$repo->add( 802, 'ftnc_work', array( 'post_content' => 'stays plain' ), array() );
$GLOBALS['t_opts'] = array(); $GLOBALS['t_rows'] = array();
Fotonic_Vault_Encrypt_Existing::create();
$guard = 0;
do { $st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 10 ); } while ( 'error' !== $st['status'] && ++$guard < 200 );
t_equals( [ 'error', 'incomplete' ], [ $st['status'], $st['error'] ], 'records that stay plain: the job ends in error "incomplete", not in a loop' );
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_blocking(), 'the incomplete state does not block writes' );
t_equals( true, Fotonic_Vault_Encrypt_Existing::cancel(), 'cancel() forgets the job' );
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_pending(), 'no job after cancel' );
t_equals( false, Fotonic_Vault_Encrypt_Existing::cancel(), 'cancel() with no job does nothing' );
t_equals( 'stays plain', $repo->posts[802]['post_content'], 'cancelling touches no record' );
// A running or paused job can be cancelled too, and its lock goes with it.
Fotonic_Vault_Encrypt_Existing::create();
Fotonic_Vault_Encrypt_Existing::run_batch( $K, 10 );
Fotonic_Vault_Encrypt_Existing::pause();
$GLOBALS['t_rows'][ $LOCK ] = time() . '.leftover';
t_equals( true, Fotonic_Vault_Encrypt_Existing::is_blocking(), 'a paused job blocks' );
Fotonic_Vault_Encrypt_Existing::cancel();
t_equals( [ false, false ], [ Fotonic_Vault_Encrypt_Existing::is_blocking(), array_key_exists( $LOCK, $GLOBALS['t_rows'] ) ], 'cancel(): nothing blocks, the lock row is gone' );
Fotonic_Vault_Fields::use_repo( new T_Repo() );

// ---- 6c. the batch size: default, filter, bounds ----------------------------------------------
$repo = new T_Repo();
Fotonic_Vault_Fields::use_repo( $repo );
for ( $i = 1; $i <= 6; $i++ ) { $repo->add( 900 + $i, 'ftnc_work', array( 'post_content' => "n$i" ), array() ); }
$GLOBALS['t_opts'] = array(); $GLOBALS['t_rows'] = array();
Fotonic_Vault_Encrypt_Existing::create();
Fotonic_Vault_Encrypt_Existing::run_batch( $K ); // scan with the default size
$GLOBALS['t_filters']['ftnc_vault_encrypt_batch_size'][] = function () { return 2; };
Fotonic_Vault_Encrypt_Existing::run_batch( $K ); // analyze: two records per step
t_equals( 2, Fotonic_Vault_Encrypt_Existing::job()['totals']['works']['seen'], 'the filter sets the batch size (2 records in one step)' );
$GLOBALS['t_filters']['ftnc_vault_encrypt_batch_size'] = array( function () { return 0; } );
Fotonic_Vault_Encrypt_Existing::run_batch( $K );
t_equals( 3, Fotonic_Vault_Encrypt_Existing::job()['totals']['works']['seen'], 'a batch size below 1 counts as 1' );
$GLOBALS['t_filters']['ftnc_vault_encrypt_batch_size'] = array( function () { return 99999; } );
Fotonic_Vault_Encrypt_Existing::run_batch( $K );
t_equals( 6, Fotonic_Vault_Encrypt_Existing::job()['totals']['works']['seen'], 'a huge batch size is capped (all six records fit under the cap)' );
$GLOBALS['t_filters']['ftnc_vault_encrypt_batch_size'] = array();
Fotonic_Vault_Encrypt_Existing::clear();

// ---- 7. the scope keeps tests (and any caller) off every other record -------------------------
$repo = new T_Repo();
Fotonic_Vault_Fields::use_repo( $repo );
$repo->add( 601, 'ftnc_work', array( 'post_content' => 'mine' ), array() );
$repo->add( 602, 'ftnc_work', array( 'post_content' => 'real data, must not change' ), array() );
$repo->add( 603, 'ftnc_service', array( 'post_title' => 'real service' ), array() );
$GLOBALS['t_opts'] = array();
$GLOBALS['t_scope'] = array( 601 );
Fotonic_Vault_Encrypt_Existing::create();
$guard = 0;
do { $st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 10 ); } while ( 'done' !== $st['status'] && ++$guard < 50 );
t_equals( 'mine', Fotonic_Field_Crypt::read( $repo->posts[601]['post_content'], $K ), 'scope: the record in scope is encrypted' );
t_equals( 'real data, must not change', $repo->posts[602]['post_content'], 'scope: a record outside the scope is untouched' );
t_equals( 'real service', $repo->posts[603]['post_title'], 'scope: another type is untouched too' );
$GLOBALS['t_scope'] = null;

// ---- 8. a record saved plain while the job ran is caught by the final re-check -----------------
$repo = new T_Repo();
Fotonic_Vault_Fields::use_repo( $repo );
$repo->add( 701, 'ftnc_work', array( 'post_content' => 'one' ), array() );
$GLOBALS['t_opts'] = array();
Fotonic_Vault_Encrypt_Existing::create();
do { $st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 10 ); } while ( 'finish' !== Fotonic_Vault_Encrypt_Existing::job()['phase'] );
$repo->add( 702, 'ftnc_work', array( 'post_content' => 'saved late' ), array() ); // lands in the finish window
$st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 10 );
t_equals( 'analyze', $st['phase'], 'finish: something plain is left, the job goes round again' );
$guard = 0;
do { $st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 10 ); } while ( 'done' !== $st['status'] && ++$guard < 50 );
t_equals( 'saved late', Fotonic_Field_Crypt::read( $repo->posts[702]['post_content'], $K ), 'the late record ends up encrypted' );

// ---- 9. ensure_job and clear --------------------------------------------------------------------
$GLOBALS['t_opts'] = array();
Fotonic_Vault_Encrypt_Existing::ensure_job();
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_pending(), 'ensure_job(): no Vault, no job' );
update_option( 'fotonic_vault_setup', true );
Fotonic_Vault_Encrypt_Existing::ensure_job();
t_equals( 'pending', Fotonic_Vault_Encrypt_Existing::job()['status'], 'ensure_job(): a Vault that has not been through this field list gets one pending job' );
Fotonic_Vault_Encrypt_Existing::clear();
update_option( Fotonic_Vault_Encrypt_Existing::OPTION_VERSION, Fotonic_Vault_Fields::signatures() );
Fotonic_Vault_Encrypt_Existing::ensure_job();
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_pending(), 'ensure_job(): a Vault already through the current list gets no job' );
Fotonic_Vault_Encrypt_Existing::create( true );
t_equals( false, array_key_exists( Fotonic_Vault_Encrypt_Existing::OPTION_VERSION, $GLOBALS['t_opts'] ), 'create(true): forgets the recorded version (a Vault was just set up)' );
$GLOBALS['t_rows'][ Fotonic_Vault_Encrypt_Existing::OPTION_LOCK ] = time() . '.x';
Fotonic_Vault_Encrypt_Existing::clear();
t_equals( array( false, false ), array( array_key_exists( Fotonic_Vault_Encrypt_Existing::OPTION_JOB, $GLOBALS['t_opts'] ), array_key_exists( Fotonic_Vault_Encrypt_Existing::OPTION_LOCK, $GLOBALS['t_rows'] ) ), 'clear(): job and lock gone' );

// ---- 10. Pro fields that appear later (signature coverage) ----------------------------------------
$GLOBALS['t_opts'] = array( 'fotonic_vault_setup' => true, Fotonic_Vault_Encrypt_Existing::OPTION_VERSION => 1 ); // what 1.6.4 stored first
t_equals( Fotonic_Vault_Fields::signatures( Fotonic_Vault_Fields::base_groups() ), Fotonic_Vault_Encrypt_Existing::covered(), 'legacy int 1: the free fields are covered' );
Fotonic_Vault_Encrypt_Existing::ensure_job();
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_pending(), 'legacy int 1 without Pro: no job' );
$GLOBALS['t_filters']['ftnc_vault_fields'][] = function ( $g ) {
	$g['collaborators'] = array( 'label' => 'Collaborators', 'post_type' => 'ftnc_collaborator', 'fields' => array( array( 'kind' => 'meta', 'meta' => '_ftnc_collab_email', 'format' => 'f1' ) ) );
	return $g;
};
t_equals( true, Fotonic_Vault_Encrypt_Existing::has_new_fields(), 'Pro switched on later: its field is new' );
Fotonic_Vault_Encrypt_Existing::ensure_job();
t_equals( 'pending', Fotonic_Vault_Encrypt_Existing::job()['status'], 'Pro switched on later: one pending job' );
Fotonic_Vault_Encrypt_Existing::clear();
update_option( Fotonic_Vault_Encrypt_Existing::OPTION_VERSION, Fotonic_Vault_Fields::signatures() );
Fotonic_Vault_Encrypt_Existing::ensure_job();
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_pending(), 'all fields covered: no job' );
$GLOBALS['t_filters']['ftnc_vault_fields'] = array();
Fotonic_Vault_Encrypt_Existing::ensure_job();
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_pending(), 'Pro switched off: a superset test raises no job' );

t_done();
