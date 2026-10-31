<?php
// Unit test for the Vault first-run choice (Fotonic_Vault::get_choice / set_choice), the
// POST /vault/choice handler, the vault-download permission without a Vault, and the reset rule.
// No WordPress, no database: options and the REST classes are in-memory stand-ins.
ob_start();
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );
define( 'COOKIEPATH', '/' );
define( 'COOKIE_DOMAIN', '' );
define( 'AUTH_KEY', 'unit-test-auth-key' );

$GLOBALS['t_opts']    = array();
$GLOBALS['t_can']     = true;
$GLOBALS['t_nonce_ok'] = true;

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['t_opts'] ) ? $GLOBALS['t_opts'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['t_opts'][ $n ] = $v; $GLOBALS['t_autoload'][ $n ] = $a; return true; }
function delete_option( $n ) { unset( $GLOBALS['t_opts'][ $n ] ); return true; }
function delete_transient( $n ) { return true; }
function get_current_user_id() { return 1; }
function wp_get_session_token() { return 'tok'; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $v ) { return $v; }
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function is_ssl() { return false; }
function current_user_can( $c ) { return $GLOBALS['t_can']; }
function wp_verify_nonce( $n, $a ) { return $GLOBALS['t_nonce_ok'] && 'good' === $n; }
function __( $t, $d = '' ) { return $t; }
function esc_html__( $t, $d = '' ) { return $t; }
function apply_filters( $tag, $value ) { return $value; }
function add_option( $n, $v = '', $d = '', $a = null ) { if ( array_key_exists( $n, $GLOBALS['t_opts'] ) ) { return false; } $GLOBALS['t_opts'][ $n ] = $v; return true; }

class T_Wpdb { public $options = 'wp_options'; public function prepare( $q, ...$a ) { return $q; } public function query( $q ) { return 0; } }
$wpdb = new T_Wpdb(); // reset_vault() clears the encrypt-existing lock row

class WP_Error { public $code; public $data; function __construct( $c = '', $m = '', $d = '' ) { $this->code = $c; $this->data = $d; } }
class WP_REST_Response { public $data; public $status; function __construct( $d = null, $s = 200 ) { $this->data = $d; $this->status = $s; } }
class WP_REST_Server { const CREATABLE = 'POST'; const READABLE = 'GET'; }
class WP_REST_Request {
	public $headers = array(); public $params = array(); public $json = array();
	function get_header( $k ) { return $this->headers[ $k ] ?? null; }
	function get_param( $k ) { return $this->params[ $k ] ?? null; }
	function get_json_params() { return $this->json; }
}

require __DIR__ . '/../../includes/class-crypto.php';
require __DIR__ . '/../../includes/class-vault-attempts.php';
require __DIR__ . '/../../includes/class-backup-keys.php';
require __DIR__ . '/../../includes/class-field-crypt.php';
require __DIR__ . '/../../includes/class-vault-fields.php';
require __DIR__ . '/../../includes/class-vault-encrypt-existing.php';
require __DIR__ . '/../../includes/class-vault.php';
require __DIR__ . '/../../includes/class-rest-api.php';

// 1. Fresh site: never asked.
t_equals( '', Fotonic_Vault::get_choice(), 'fresh site: choice is empty' );

// 2. Only "skipped" can be stored.
t_equals( false, Fotonic_Vault::set_choice( 'enabled' ), 'enabled cannot be stored' );
t_equals( false, Fotonic_Vault::set_choice( '' ), 'empty cannot be stored' );
t_equals( false, Fotonic_Vault::set_choice( 'x' ), 'garbage cannot be stored' );
t_equals( '', Fotonic_Vault::get_choice(), 'a refused value changes nothing' );
t_equals( true, Fotonic_Vault::set_choice( 'skipped' ), 'skipped is stored' );
t_equals( 'skipped', Fotonic_Vault::get_choice(), 'choice reads back as skipped' );
t_equals( false, $GLOBALS['t_autoload']['fotonic_vault_choice'], 'the option is not autoloaded' );

// 3. A set-up Vault reports enabled and refuses a choice.
update_option( 'fotonic_vault_setup', true );
t_equals( 'enabled', Fotonic_Vault::get_choice(), 'set-up Vault: choice is enabled' );
t_equals( false, Fotonic_Vault::set_choice( 'skipped' ), 'no choice can be stored once the Vault exists' );

// 4. Reset: the person knows the Vault, the first-run screen must not ask again.
delete_option( 'fotonic_vault_choice' );
Fotonic_Vault::reset_vault();
t_equals( false, Fotonic_Vault::is_setup(), 'reset: no Vault' );
t_equals( 'skipped', Fotonic_Vault::get_choice(), 'reset: choice is skipped, not empty' );

// 5. REST handler POST /vault/choice.
delete_option( 'fotonic_vault_choice' );
$req = new WP_REST_Request(); $req->json = array( 'choice' => 'skipped' );
$res = Fotonic_REST_API::vault_choice( $req );
t_equals( 200, $res->status, 'choice handler: skipped is accepted' );
t_equals( array( 'choice' => 'skipped' ), $res->data, 'choice handler: returns the choice' );
$req->json = array( 'choice' => 'enabled' );
t_equals( 400, Fotonic_REST_API::vault_choice( $req )->status, 'choice handler: enabled is refused with 400' );
$req->json = array();
t_equals( 400, Fotonic_REST_API::vault_choice( $req )->status, 'choice handler: missing choice is refused with 400' );
update_option( 'fotonic_vault_setup', true );
$req->json = array( 'choice' => 'skipped' );
t_equals( 409, Fotonic_REST_API::vault_choice( $req )->status, 'choice handler: 409 when the Vault is set up' );
delete_option( 'fotonic_vault_setup' );

// 6. vault-download permission.
$dl = new WP_REST_Request(); $dl->headers = array( 'x_wp_nonce' => 'good' );
t_equals( true, Fotonic_REST_API::vault_download_permission( $dl ), 'download, no Vault: allowed for an admin with the nonce' );
$GLOBALS['t_can'] = false;
t_equals( false, Fotonic_REST_API::vault_download_permission( $dl ), 'download, no Vault: refused without the capability' );
$GLOBALS['t_can'] = true;
$bad = new WP_REST_Request(); $bad->headers = array( 'x_wp_nonce' => 'bad' );
t_equals( false, Fotonic_REST_API::vault_download_permission( $bad ), 'download, no Vault: refused with a bad nonce' );
$none = new WP_REST_Request();
t_equals( false, Fotonic_REST_API::vault_download_permission( $none ), 'download, no Vault: refused without a nonce' );
update_option( 'fotonic_vault_setup', true );
$locked = Fotonic_REST_API::vault_download_permission( $dl );
t_true( $locked instanceof WP_Error && 'vault_locked' === $locked->code && 423 === $locked->data['status'], 'download, Vault set and locked: 423 vault_locked' );
$GLOBALS['t_can'] = false;
t_equals( false, Fotonic_REST_API::vault_download_permission( $dl ), 'download, Vault locked: still refused without the capability' );

t_done();
