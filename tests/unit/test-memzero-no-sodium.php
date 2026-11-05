<?php
// Unit test: the private memzero() helpers must not break on a server with no libsodium extension.
// There, WordPress's sodium_compat defines sodium_memzero() but it throws, which used to turn creating a
// Vault and making a backup into a critical error. No WordPress, no database.
//
// This PHP has the real libsodium, so the file runs itself again in a child process with every native sodium_*
// function disabled. If WordPress's bundled sodium_compat is found (Local site tree, or FTNC_SODIUM_COMPAT=/path/to/
// sodium_compat), the child loads it in pure-PHP mode, which is exactly what such a server runs. If not, a throwing
// stand-in for sodium_memzero() is used instead.
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );

if ( ! getenv( 'FTNC_MEMZERO_CHILD' ) ) {
	putenv( 'FTNC_MEMZERO_CHILD=1' );
	$funcs = function_exists( 'sodium_memzero' ) ? get_extension_funcs( 'sodium' ) : array();
	$funcs = array_filter( (array) $funcs, function ( $f ) { return 0 === strpos( $f, 'sodium_' ); } );
	if ( ! $funcs ) {
		$funcs = array( 'sodium_memzero' );
	}
	passthru( escapeshellarg( PHP_BINARY ) . ' -d error_reporting=24575 -d disable_functions=' . escapeshellarg( implode( ',', $funcs ) ) . ' ' . escapeshellarg( __FILE__ ), $rc );
	exit( $rc );
}

$candidates = array_filter( array(
	getenv( 'FTNC_SODIUM_COMPAT' ),
	__DIR__ . '/../../../../../wp-includes/sodium_compat',
) );
$compat = '';
foreach ( $candidates as $dir ) {
	if ( is_file( $dir . '/autoload.php' ) ) {
		$compat = $dir . '/autoload.php';
		break;
	}
}

if ( $compat ) {
	require $compat;
	ParagonIE_Sodium_Compat::$disableFallbackForUnitTests = true; // pure PHP, even though the extension is loaded here
	t_true( function_exists( 'sodium_memzero' ), 'sodium_compat provides sodium_memzero()' );
	$probe = random_bytes( 32 );
	$threw = false;
	try {
		sodium_memzero( $probe );
	} catch ( Throwable $e ) {
		$threw = true;
	}
	t_true( $threw, 'sodium_compat sodium_memzero() throws (the real cause of the crash)' );
} else {
	echo "NOTE: sodium_compat not found, using a throwing stand-in for sodium_memzero().\n";
	if ( true ) { // run-time declaration: a top-level one would be hoisted and clash with the native function in the parent
		function sodium_memzero( &$secret ) {
			throw new RuntimeException( 'sodium_memzero() cannot be implemented in pure PHP' );
		}
	}
}

require __DIR__ . '/../../includes/class-backup-keys.php';
require __DIR__ . '/../../includes/class-backup-archive.php';

foreach ( array( 'Fotonic_Backup_Keys', 'Fotonic_Backup_Archive' ) as $class ) {
	$m = new ReflectionMethod( $class, 'memzero' );
	$m->setAccessible( true );
	$secret = random_bytes( 32 );
	$error  = '';
	try {
		$args = array( &$secret );
		$m->invokeArgs( null, $args );
	} catch ( Throwable $e ) {
		$error = $e->getMessage();
	}
	t_equals( '', $error, "$class::memzero() does not throw when sodium_memzero() throws" );
	t_equals( str_repeat( "\0", 32 ), $secret, "$class::memzero() still zeroes the secret by hand" );
}

if ( $compat ) {
	// The rest of the backup-key flow, on sodium_compat alone: keys, sealed box, and back.
	t_true( Fotonic_Backup_Keys::is_supported(), 'Fotonic_Backup_Keys::is_supported() is true on sodium_compat' );
	$keypair = sodium_crypto_box_keypair();
	$pub     = sodium_crypto_box_publickey( $keypair );
	$sealed  = sodium_crypto_box_seal( 'archive key bytes', $pub );
	t_equals( 'archive key bytes', sodium_crypto_box_seal_open( $sealed, $keypair ), 'sealed box round trip on sodium_compat' );
}

exit( $GLOBALS['ftnc_failures'] ? 1 : 0 );
