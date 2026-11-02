<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

// "What's new": baseline, per-user dismissal, the overview, entries from the filter, changelog parsing.
// Everything runs as a temporary administrator: the real admin's closed entries must never be touched.
require_once ABSPATH . 'wp-admin/includes/user.php';
$real_admin = get_current_user_id();
$admin      = (int) wp_insert_user( [ 'user_login' => 'ftnc_whatsnew_a_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'administrator' ] );
wp_set_current_user( $admin );
t_preserve_options( [ Fotonic_Whats_New::BASELINE_OPTION ] );
$second = 0;
register_shutdown_function( function () use ( $admin, &$second, $real_admin ) {
    wp_set_current_user( $real_admin );
    wp_delete_user( $admin );
    if ( $second ) {
        wp_delete_user( $second );
    }
    t_restore_options();
} );
delete_option( Fotonic_Whats_New::BASELINE_OPTION );

$ids = function ( array $visible ) {
    return array_column( $visible['entries'], 'id' );
};

// --- baseline -----------------------------------------------------------------------------------------
t_equals( '1.6.4', Fotonic_Whats_New::baseline( 'free' ), 'a site with no baseline counts as 1.6.4' );
$v = Fotonic_Whats_New::visible_for( $admin );
t_true( in_array( '1.6.5:works-payment-types', $ids( $v ), true ), 'such a site sees the 1.6.5 entries' );
t_true( $v['overview']['show'], 'and the overview' );
t_equals( 'overview:1.6.5', $v['overview']['id'], 'the overview id carries the newest version' );
t_equals( '1.6.5', $v['entries'][0]['since'], 'every entry says which version brought it' );

// Pro may be active here and brings its own entries: a fresh install has both baselines.
Fotonic_Whats_New::set_baseline( 'free', '1.6.5' );
Fotonic_Whats_New::set_baseline( 'pro', '1.6.5' );
t_equals( [], $ids( Fotonic_Whats_New::visible_for( $admin ) ), 'a fresh install of 1.6.5 sees nothing' );
t_equals( false, Fotonic_Whats_New::visible_for( $admin )['overview']['show'], 'and no overview' );
Fotonic_Whats_New::set_baseline( 'free', '1.0.0' );
t_equals( '1.6.5', Fotonic_Whats_New::baseline( 'free' ), 'a baseline is written once' );
t_equals( '1.6.5', Fotonic_Whats_New::baseline( 'pro' ), 'each plugin has its own baseline' );
delete_option( Fotonic_Whats_New::BASELINE_OPTION );

// --- dismissal is per user --------------------------------------------------------------------------------
Fotonic_Whats_New::dismiss( $admin, [ '1.6.5:works-payment-types', 'overview:1.6.5', 'bad id with spaces', '' ] );
$v = Fotonic_Whats_New::visible_for( $admin );
t_true( ! in_array( '1.6.5:works-payment-types', $ids( $v ), true ), 'a closed entry stays closed' );
t_true( in_array( '1.6.5:works-tidier', $ids( $v ), true ), 'the others stay' );
t_equals( false, $v['overview']['show'], 'a closed overview stays closed' );
t_equals( [ '1.6.5:works-payment-types', 'overview:1.6.5' ], Fotonic_Whats_New::dismissed( $admin ), 'only well-formed ids are stored' );

$second = wp_insert_user( [ 'user_login' => 'ftnc_whatsnew_b_' . wp_generate_password( 6, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'administrator' ] );
$v2     = Fotonic_Whats_New::visible_for( (int) $second );
t_true( in_array( '1.6.5:works-payment-types', $ids( $v2 ), true ), 'another admin still sees what the first one closed' );

// --- entries from the filter (Pro) ----------------------------------------------------------------------------
add_filter( 'ftnc_whats_new_entries', function ( $entries ) {
    $entries[] = [ 'id' => '1.6.5:pro-test', 'plugin' => 'pro', 'since' => '1.6.5', 'section' => 'kanban', 'title' => 'T', 'body' => 'B', 'banner' => true ];
    return $entries;
} );
$v = Fotonic_Whats_New::visible_for( $admin );
$pro = array_values( array_filter( $v['entries'], function ( $e ) { return '1.6.5:pro-test' === $e['id']; } ) );
t_equals( [ 'pro', 'kanban', true ], [ $pro[0]['plugin'] ?? '', $pro[0]['section'] ?? '', $pro[0]['banner'] ?? false ], 'a Pro entry comes through the filter with its section and banner flag' );
Fotonic_Whats_New::set_baseline( 'pro', '1.6.5' );
t_true( ! in_array( '1.6.5:pro-test', $ids( Fotonic_Whats_New::visible_for( $admin ) ), true ), 'a Pro entry is hidden when Pro was installed at that version' );

// --- changelog -------------------------------------------------------------------------------------------------
$readme_free = (string) file_get_contents( FOTONIC_DIR . 'readme.txt' );
$readme = "=== Plugin ===\nStable tag: 1.6.5\n\n== Description ==\n* not a release\n\n== Changelog ==\n\n= 1.6.5 =\n* First thing\n* Second thing\n\n= 1.6.4 =\n* Older thing\nstray text\n\n== Upgrade Notice ==\n\n= 1.6.5 =\nUpgrade text\n";
$rel    = Fotonic_Whats_New::parse_changelog( $readme );
t_equals( [ '1.6.5', '1.6.4' ], array_column( $rel, 'version' ), 'the changelog releases are read in order' );
t_equals( [ 'First thing', 'Second thing' ], $rel[0]['items'], 'with their items' );
t_equals( [ 'Older thing' ], $rel[1]['items'], 'and nothing from other sections' );
t_equals( [], Fotonic_Whats_New::parse_changelog( "== Description ==\nx" ), 'a readme without a changelog gives an empty list' );
$real = Fotonic_Whats_New::changelog();
t_true( isset( $real['free'] ) && count( $real['free']['releases'] ) > 3, 'the real free changelog is read from readme.txt' );

// Every sentence of the changelog has an Italian text in changelog-it_IT.json (a new release must add its own).
$it = json_decode( (string) file_get_contents( FOTONIC_DIR . 'languages/changelog-it_IT.json' ), true );
t_true( is_array( $it ) && count( $it ) > 100, 'the Italian changelog file is there' );
$missing = [];
foreach ( Fotonic_Whats_New::parse_changelog( $readme_free, 500 ) as $r ) {
    foreach ( $r['items'] as $item ) {
        if ( empty( $it[ $item ] ) ) {
            $missing[] = $r['version'] . ': ' . substr( $item, 0, 60 );
        }
    }
}
t_equals( [], $missing, 'no changelog sentence is missing from the Italian file' );
$tr = Fotonic_Whats_New::translate_releases( [ [ 'version' => '9', 'items' => [ 'Hello', 'Keep me' ] ] ], [ 'Hello' => 'Ciao' ] );
t_equals( [ 'Ciao', 'Keep me' ], $tr[0]['items'], 'a sentence with an entry is translated, one without stays as it is' );

// --- update available -------------------------------------------------------------------------------------------
$base = plugin_basename( FOTONIC_DIR . 'fotonic.php' );
add_filter( 'pre_site_transient_update_plugins', function () use ( $base ) {
    return (object) [ 'response' => [ $base => (object) [ 'new_version' => '99.0.0' ] ] ];
} );
$info = Fotonic_Whats_New::update_info( $base, FOTONIC_VERSION );
t_equals( '99.0.0', $info['latest'] ?? '', 'a newer version known to WordPress is reported' );
t_true( false !== strpos( (string) ( $info['update_url'] ?? '' ), 'plugins.php' ), 'with a link to the plugins list' );
t_equals( null, Fotonic_Whats_New::update_info( $base, '99.0.0' ), 'nothing to report when up to date' );
t_equals( null, Fotonic_Whats_New::update_info( 'other/other.php', FOTONIC_VERSION ), 'nor for a plugin WordPress does not list' );
t_equals( '99.0.0', Fotonic_Whats_New::changelog()['free']['update']['latest'] ?? '', 'the changelog data carries it' );
remove_all_filters( 'pre_site_transient_update_plugins' );

// --- REST ------------------------------------------------------------------------------------------------------
$res = Fotonic_Whats_New::rest_get()->get_data();
t_true( isset( $res['entries'], $res['overview']['id'] ), 'GET /whats-new has entries and the overview' );
$req = new WP_REST_Request( 'POST' );
$req->set_param( 'ids', [ '1.6.5:works-tidier' ] );
Fotonic_Whats_New::rest_dismiss( $req );
t_true( in_array( '1.6.5:works-tidier', Fotonic_Whats_New::dismissed( $admin ), true ), 'POST /whats-new/dismiss stores the ids' );

t_done();
