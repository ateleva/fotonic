<?php
// Unit test for the title fields of the Vault registry (phase 15): the gate, the group fields, the signatures, the job writing
// ciphertext + neutral slug, and the readable slugs WordPress keeps elsewhere. In-memory repository: nothing here touches a real site.
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
	public $neutralized = array();
	public function neutralize_slug_meta( string $type, ?array $scope = null ): void {
		$this->neutralized[] = $type;
		foreach ( $this->posts as $id => $p ) {
			if ( $p['type'] !== $type || ( null !== $scope && ! in_array( $id, $scope, true ) ) ) { continue; }
			unset( $this->meta[ $id ]['_wp_old_slug'] );
			if ( ( $p['status'] ?? '' ) === 'trash' && isset( $this->meta[ $id ]['_wp_desired_post_slug'] ) ) {
				$this->meta[ $id ]['_wp_desired_post_slug'] = Fotonic_Vault_Fields::neutral_slug( $type, $id );
			}
		}
	}
}


require __DIR__ . '/../../includes/class-crypto.php';
require __DIR__ . '/../../includes/class-field-crypt.php';
require __DIR__ . '/../../includes/class-titles.php';
require __DIR__ . '/../../includes/class-vault-attempts.php';
require __DIR__ . '/../../includes/class-backup-keys.php';
require __DIR__ . '/../../includes/class-vault.php';
require __DIR__ . '/../../includes/class-rest-api.php';
require __DIR__ . '/../../includes/class-vault-fields.php';
require __DIR__ . '/../../includes/class-work-files.php';
require __DIR__ . '/../../includes/class-vault-encrypt-existing.php';

$K = random_bytes( 32 );
$title_field = array( 'kind' => 'post', 'field' => 'post_title', 'format' => 'f1', 'neutral_slug' => true );

// ---- gate open (free alone) ----
$g = Fotonic_Vault_Fields::groups();
t_true( in_array( $title_field, $g['customers']['fields'], true ), 'customers: the title is a protected field' );
t_true( in_array( $title_field, $g['works']['fields'], true ), 'works: the title is a protected field' );
t_equals( $title_field, end( $g['customers']['fields'] ), 'customers: the title is the LAST field (the people stay first)' );
t_equals( '_ftnc_people', $g['customers']['fields'][0]['meta'], 'customers: the people field is still first' );
$open_sigs = Fotonic_Vault_Fields::signatures();

// ---- gate closed (an old Pro that does not say it copes) ----
define( 'FOTO_PRO_VERSION', '1.6.7' );
$closed = Fotonic_Vault_Fields::groups();
t_true( ! in_array( $title_field, $closed['customers']['fields'], true ), 'gate closed: no customer title field' );
t_true( ! in_array( $title_field, $closed['works']['fields'], true ), 'gate closed: no work title field' );
$closed_sigs = Fotonic_Vault_Fields::signatures();
t_true( 0 === count( array_diff( $closed_sigs, $open_sigs ) ), 'signatures: the open list is a superset of the closed one' );
t_true( count( $open_sigs ) > count( $closed_sigs ), 'signatures: opening the gate adds fields (so the job runs again)' );
$GLOBALS['t_filters']['ftnc_titles_encryptable'][] = function () { return true; };
t_equals( $open_sigs, Fotonic_Vault_Fields::signatures(), 'Pro opening the gate gives the same list as free alone' );

// ---- the walker writes ciphertext and a neutral slug ----
$repo = new T_Repo();
Fotonic_Vault_Fields::use_repo( $repo );
$repo->add( 1, 'ftnc_customer', array( 'post_title' => 'Anna Rossi', 'post_name' => 'anna-rossi' ), array( '_wp_old_slug' => 'old-anna', '_ftnc_people' => '' ) );
$repo->add( 2, 'ftnc_work', array( 'post_title' => 'Matrimonio Anna', 'post_name' => 'matrimonio-anna' ) );
$repo->add( 3, 'ftnc_customer', array( 'post_title' => 'Gone', 'post_name' => 'gone__trashed', 'status' => 'trash' ), array( '_wp_desired_post_slug' => 'gone' ) );
$repo->add( 4, 'ftnc_customer', array( 'post_title' => Fotonic_Field_Crypt::encrypt( 'Already', $K ), 'post_name' => 'customer-4' ) );
$groups = Fotonic_Vault_Fields::groups();
t_equals( 1, Fotonic_Vault_Fields::process_record( 1, $groups['customers'], $K, false ), 'a plain title counts as one thing to encrypt' );
t_equals( 'Anna Rossi', $repo->posts[1]['post_title'], 'counting writes nothing' );
t_equals( 1, Fotonic_Vault_Fields::process_record( 1, $groups['customers'], $K, true ), 'encrypting a customer title' );
t_true( Fotonic_Field_Crypt::is_encrypted( $repo->posts[1]['post_title'] ), 'the title is now ciphertext' );
t_equals( 'Anna Rossi', Fotonic_Field_Crypt::read( $repo->posts[1]['post_title'], $K ), 'and reads back' );
t_equals( 'customer-1', $repo->posts[1]['post_name'], 'neutral slug' );
t_equals( 0, Fotonic_Vault_Fields::process_record( 1, $groups['customers'], $K, true ), 'second pass: no change' );
t_equals( 0, Fotonic_Vault_Fields::process_record( 4, $groups['customers'], $K, true ), 'an already encrypted title is left alone' );
t_equals( 1, Fotonic_Vault_Fields::process_record( 2, $groups['works'], $K, true ), 'a work title' );
t_equals( 'work-2', $repo->posts[2]['post_name'], 'work slug' );
Fotonic_Vault_Fields::process_record( 3, $groups['customers'], $K, true );
t_equals( 'customer-3', $repo->posts[3]['post_name'], 'a trashed record gets the neutral slug too' );

// ---- the job: readable slugs elsewhere are cleaned once at the end, every group with a neutral slug ----
$repo2 = new T_Repo();
Fotonic_Vault_Fields::use_repo( $repo2 );
$repo2->add( 10, 'ftnc_customer', array( 'post_title' => 'Plain', 'post_name' => 'plain' ), array( '_wp_old_slug' => 'was-plain' ) );
$repo2->add( 11, 'ftnc_customer', array( 'post_title' => 'In the bin', 'post_name' => 'in-the-bin__trashed', 'status' => 'trash' ), array( '_wp_desired_post_slug' => 'in-the-bin' ) );
$repo2->add( 12, 'ftnc_work', array( 'post_title' => 'Job', 'post_name' => 'job' ), array( '_wp_old_slug' => 'old-job' ) );
$GLOBALS['t_scope'] = array( 10, 11, 12 );
Fotonic_Vault_Encrypt_Existing::create( true );
$guard = 0;
do {
	$st = Fotonic_Vault_Encrypt_Existing::run_batch( $K, 50 );
} while ( 'done' !== ( $st['status'] ?? '' ) && ++$guard < 50 );
t_equals( 'done', $st['status'], 'the job finishes' );
t_true( Fotonic_Field_Crypt::is_encrypted( $repo2->posts[10]['post_title'] ) && Fotonic_Field_Crypt::is_encrypted( $repo2->posts[12]['post_title'] ), 'titles encrypted by the job' );
t_true( ! isset( $repo2->meta[10]['_wp_old_slug'] ) && ! isset( $repo2->meta[12]['_wp_old_slug'] ), 'no earlier plain slug is left' );
t_equals( 'customer-11', $repo2->meta[11]['_wp_desired_post_slug'], 'the slug a bin record gets back is neutral' );
t_true( in_array( 'ftnc_customer', $repo2->neutralized, true ) && in_array( 'ftnc_work', $repo2->neutralized, true ), 'the cleanup ran for customers and works' );
t_equals( 1, count( array_keys( $repo2->neutralized, 'ftnc_customer', true ) ), 'and once per group, not once per record' );
$GLOBALS['t_scope'] = null;

exit( t_done() );
