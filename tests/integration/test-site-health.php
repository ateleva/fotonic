<?php
/**
 * Site Health test for the private upload folders. The HTTP request is faked with
 * pre_http_request, so the result does not depend on the web server of the machine. Probe files
 * are written to (and removed from) the real folders that exist.
 */
require __DIR__ . '/../lib/wp-bootstrap.php';

$uploads = wp_upload_dir();
$dir     = $uploads['basedir'] . '/fotonic/backups';
wp_mkdir_p( $dir );
$before  = glob( $uploads['basedir'] . '/fotonic/*/ftnc-probe-*.txt' ) ?: [];

$set = function ( $cb ) {
    remove_all_filters( 'pre_http_request' );
    add_filter( 'pre_http_request', $cb, 10, 3 );
};

// A server that returns the file: public.
$set( function ( $pre, $args, $url ) {
    $name = basename( $url );
    $body = 'ftnc-probe-' . substr( $name, 11, 16 );
    return [ 'response' => [ 'code' => 200, 'message' => 'OK' ], 'body' => $body, 'headers' => [], 'cookies' => [], 'http_response' => null ];
} );
t_equals( 'public', Fotonic_Site_Health::probe_folder( 'backups' ), 'the file is served: public' );
t_equals( 'recommended', Fotonic_Site_Health::run_test()['status'], 'a public folder gives a recommended status' );
t_true( false !== strpos( Fotonic_Site_Health::run_test()['actions'], 'location ^~ ' ), 'the answer carries the nginx rules' );

// 403 and 404: protected. A 200 page without the marker (catch-all): protected.
foreach ( [ [ 403, '' ], [ 404, '' ], [ 200, '<html>home</html>' ] ] as $case ) {
    $set( function () use ( $case ) {
        return [ 'response' => [ 'code' => $case[0], 'message' => '' ], 'body' => $case[1], 'headers' => [], 'cookies' => [], 'http_response' => null ];
    } );
    t_equals( 'protected', Fotonic_Site_Health::probe_folder( 'backups' ), "HTTP {$case[0]} without the marker: protected" );
}
t_equals( 'good', Fotonic_Site_Health::run_test()['status'], 'protected folders give good' );

// A 5xx or a failed request is "could not check", never good.
$set( function () {
    return [ 'response' => [ 'code' => 503, 'message' => '' ], 'body' => '', 'headers' => [], 'cookies' => [], 'http_response' => null ];
} );
t_equals( 'unknown', Fotonic_Site_Health::probe_folder( 'backups' ), 'HTTP 503: unknown' );
$set( function () {
    return new WP_Error( 'http_request_failed', 'x' );
} );
t_equals( 'unknown', Fotonic_Site_Health::probe_folder( 'backups' ), 'a failed request: unknown' );
$set( function () {
    return new WP_Error( 'http_request_failed', 'x' );
} );
$r = Fotonic_Site_Health::run_test();
t_true( 'good' !== $r['status'], 'when every folder is unknown the test is not good' );

remove_all_filters( 'pre_http_request' );
t_equals( [], array_values( array_diff( glob( $uploads['basedir'] . '/fotonic/*/ftnc-probe-*.txt' ) ?: [], $before ) ), 'no probe file is left behind' );

// Folder names from the filter are cleaned.
add_filter( 'ftnc_protected_folders', function ( $f ) {
    $f[] = '../evil';
    $f[] = 'a/b';
    return $f;
} );
t_true( ! in_array( '../evil', Fotonic_Site_Health::protected_folders(), true ) && ! in_array( 'a/b', Fotonic_Site_Health::protected_folders(), true ), 'unsafe folder names are dropped' );

t_equals( true, false !== strpos( Fotonic_Site_Health::nginx_rules( [ 'backups' ] ), 'location ^~ /wp-content/uploads/fotonic/backups/ { deny all; }' ) || false !== strpos( Fotonic_Site_Health::nginx_rules( [ 'backups' ] ), '/fotonic/backups/' ), 'the nginx rule names the folder' );
t_done();
