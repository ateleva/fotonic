<?php
// Unit test for Fotonic_Vault_Fingerprint. No WordPress, no database, never the real Vault key:
// options and transients are in-memory stand-ins, the Master Key and every KEK are random.
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_opts']       = array();
$GLOBALS['t_transients'] = array();

function get_option( $n, $d = false ) { return array_key_exists( $n, $GLOBALS['t_opts'] ) ? $GLOBALS['t_opts'][ $n ] : $d; }
function update_option( $n, $v, $a = null ) { $GLOBALS['t_opts'][ $n ] = $v; return true; }
function delete_option( $n ) { unset( $GLOBALS['t_opts'][ $n ] ); return true; }
function get_transient( $n ) { return array_key_exists( $n, $GLOBALS['t_transients'] ) ? $GLOBALS['t_transients'][ $n ] : false; }
function set_transient( $n, $v, $e = 0 ) { $GLOBALS['t_transients'][ $n ] = $v; return true; }
function delete_transient( $n ) { unset( $GLOBALS['t_transients'][ $n ] ); return true; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }

require __DIR__ . '/../../includes/class-crypto.php';
require __DIR__ . '/../../includes/class-vault-fingerprint.php';

$F   = 'Fotonic_Vault_Fingerprint';
$mk  = random_bytes( 32 );
$uid = 7;
$b64 = function ( $raw ) { return Fotonic_Vault_Fingerprint::b64u_encode( $raw ); };
$cred = $b64( random_bytes( 32 ) );

// Stand-in for what the browser does: KEK = f( device secret, salt ). A hash is enough here.
$device_secret = random_bytes( 32 );
$kek_for       = function ( $salt_b64 ) use ( $device_secret ) { return hash_hmac( 'sha256', $salt_b64, $device_secret, true ); };

function enroll( $F, $uid, $mk, $cred, $label, $host, $kek_for, $b64 ) {
	$begin = $F::begin_enroll( $uid );
	return array( $begin, $F::finish_enroll( $uid, $mk, $begin['token'], $cred, $label, $host, $b64( $kek_for( $begin['salt'] ) ) ) );
}

echo "enrollment\n";
list( $begin, $res ) = enroll( $F, $uid, $mk, $cred, 'Test Mac', 'Example.TEST', $kek_for, $b64 );
t_true( $res['ok'], 'enrolls with a valid token and key' );
t_equals( 'example.test', $res['device']['host'], 'host stored in lower case' );
t_true( ! isset( $res['device']['wrap'] ) && ! isset( $res['device']['salt_cur'] ), 'the public view carries no wrap or salt' );
t_equals( 1, $F::count(), 'one device' );

$again = $F::finish_enroll( $uid, $mk, $begin['token'], $cred, 'x', 'example.test', $b64( $kek_for( $begin['salt'] ) ) );
t_equals( 'enroll_expired', $again['code'] ?? '', 'the token is single use' );

$begin = $F::begin_enroll( $uid );
$bad   = $F::finish_enroll( $uid, $mk, 'wrong-token', $cred, 'x', 'example.test', $b64( random_bytes( 32 ) ) );
t_equals( 'enroll_expired', $bad['code'] ?? '', 'a wrong token is refused' );
$begin = $F::begin_enroll( $uid );
$bad   = $F::finish_enroll( $uid, $mk, $begin['token'], $cred, 'x', 'example.test', $b64( random_bytes( 16 ) ) );
t_equals( 'bad_request', $bad['code'] ?? '', 'a key that is not 32 bytes is refused' );
t_equals( 1, $F::count(), 'refused enrolments store nothing' );

echo "challenge\n";
t_equals( array(), $F::challenge( 'other.test' ), 'no device for another host' );
$ch = $F::challenge( 'EXAMPLE.test' );
t_equals( 1, count( $ch ), 'one device for the host (case-insensitive)' );
t_equals( 1, $ch[0]['gen'], 'generation 1 after enrolment' );
t_true( $ch[0]['salt_cur'] !== $ch[0]['salt_next'], 'two different salts' );

echo "unlock and rotation\n";
$k_cur  = $b64( $kek_for( $ch[0]['salt_cur'] ) );
$k_next = $b64( $kek_for( $ch[0]['salt_next'] ) );
$r = $F::unlock( $cred, 1, $k_cur, $k_next );
t_true( $r['ok'] && hash_equals( $mk, $r['mk'] ), 'unlock returns the Master Key' );
$ch2 = $F::challenge( 'example.test' );
t_equals( 2, $ch2[0]['gen'], 'generation went up' );
t_equals( $ch[0]['salt_next'], $ch2[0]['salt_cur'], 'next salt became the current one' );
t_true( $ch2[0]['salt_next'] !== $ch[0]['salt_next'], 'a fresh next salt was drawn' );

$replay = $F::unlock( $cred, 2, $k_cur, $b64( random_bytes( 32 ) ) );
t_equals( 'invalid', $replay['code'] ?? '', 'the old key no longer opens the wrap (replay)' );
$stale = $F::unlock( $cred, 1, $k_cur, $k_next );
t_equals( 'stale', $stale['code'] ?? '', 'an old generation is "stale", not a failed guess' );

$k2_cur  = $b64( $kek_for( $ch2[0]['salt_cur'] ) );
$k2_next = $b64( $kek_for( $ch2[0]['salt_next'] ) );
t_equals( 'bad_request', $F::unlock( $cred, 2, $k2_cur, '' )['code'] ?? '', 'missing next key is refused' );
t_equals( 'bad_request', $F::unlock( $cred, 2, $k2_cur, $b64( random_bytes( 8 ) ) )['code'] ?? '', 'short next key is refused' );
t_equals( 'bad_request', $F::unlock( $cred, 2, $k2_cur, $k2_cur )['code'] ?? '', 'next key equal to the current one is refused' );
t_equals( 2, $F::challenge( 'example.test' )[0]['gen'], 'refused attempts rotate nothing' );
t_equals( 'unknown', $F::unlock( $b64( random_bytes( 32 ) ), 2, $k2_cur, $k2_next )['code'] ?? '', 'unknown device' );

$r2 = $F::unlock( $cred, 2, $k2_cur, $k2_next );
t_true( $r2['ok'] && hash_equals( $mk, $r2['mk'] ), 'second unlock works after a rotation' );
t_equals( 3, $F::challenge( 'example.test' )[0]['gen'], 'generation 3' );

echo "removal and limits\n";
t_true( $F::remove( $cred ), 'remove returns true' );
t_true( ! $F::remove( $cred ), 'removing again returns false' );
t_equals( 0, $F::count(), 'no device left' );
t_true( ! array_key_exists( Fotonic_Vault_Fingerprint::OPTION, $GLOBALS['t_opts'] ), 'the option is gone when empty' );

for ( $i = 0; $i < Fotonic_Vault_Fingerprint::MAX_DEVICES; $i++ ) {
	list( , $res ) = enroll( $F, $uid, $mk, $b64( random_bytes( 32 ) ), 'D' . $i, 'example.test', $kek_for, $b64 );
}
list( , $over ) = enroll( $F, $uid, $mk, $b64( random_bytes( 32 ) ), 'one too many', 'example.test', $kek_for, $b64 );
t_equals( 'too_many_devices', $over['code'] ?? '', 'the device limit holds' );

$F::revoke_all();
t_equals( 0, $F::count(), 'revoke_all clears every device' );

echo "label\n";
list( , $res ) = enroll( $F, $uid, $mk, $cred, '<b>' . str_repeat( 'x', 200 ), 'example.test', $kek_for, $b64 );
t_true( strlen( $res['device']['label'] ) <= Fotonic_Vault_Fingerprint::MAX_LABEL && false === strpos( $res['device']['label'], '<' ), 'label is cleaned and capped' );
$F::revoke_all();

echo "wiring (source checks)\n";
$root = __DIR__ . '/../../includes/';
require_once $root . 'class-backup-registry.php';
t_true( in_array( 'fotonic_vault_prf_devices', Fotonic_Backup_Registry::denied_options(), true ), 'the option is in the hard backup denylist' );
$vault = file_get_contents( $root . 'class-vault.php' );
t_true( 1 === preg_match( '/function reset_vault\(\).*?revoke_all\(\)/s', $vault ), 'reset_vault revokes every device' );
t_true( 1 === preg_match( '/function recover_with_secret\(.*?revoke_all\(\)/s', $vault ), 'recovery revokes every device' );
t_true( 1 === preg_match( '/function change_password\(.*?\n\t}\n/s', $vault, $m ) && false === strpos( $m[0], 'revoke_all' ), 'a password change keeps the devices' );
t_true( false === stripos( file_get_contents( $root . 'class-backup-archive.php' ), 'prf' ), 'the backup archive code does not mention the fingerprint wraps (key.json)' );
$att = file_get_contents( $root . 'class-vault-attempts.php' );
t_true( false !== strpos( $att, "'fingerprint'" ), 'a dedicated attempts bucket exists' );

t_done();
