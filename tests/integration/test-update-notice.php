<?php
// "Update available": reads WordPress's own update answer. The answer is faked with the pre_site_transient filter,
// so nothing is written to the database and the real update state of the site is never touched.
require __DIR__ . '/../lib/wp-bootstrap.php';

$base = plugin_basename( FOTONIC_DIR . 'fotonic.php' );
$fake = function ( $version ) use ( $base ) {
	return function () use ( $base, $version ) {
		return (object) array( 'response' => array( $base => (object) array( 'new_version' => $version ) ) );
	};
};
$admin = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admin[0] );

$f = $fake( '99.0.0' );
add_filter( 'pre_site_transient_update_plugins', $f );
$got = Fotonic_Update_Notice::get();
t_equals( 1, count( $got['items'] ), 'newer version: one item' );
t_equals( 'free', $got['items'][0]['id'], 'newer version: the free plugin' );
t_equals( '99.0.0', $got['items'][0]['latest'], 'newer version: latest read from the update answer' );
t_equals( FOTONIC_VERSION, $got['items'][0]['current'], 'newer version: current is the installed one' );
t_true( false !== strpos( $got['url'], 'plugins.php' ), 'url is the plugins page' );

add_filter( 'ftnc_pending_updates', function ( $items ) {
	$items[] = array( 'id' => 'x', 'name' => 'Add-on', 'current' => '1', 'latest' => '2', 'note' => 'first free' );
	$items[] = 'junk';
	return $items;
} );
$got = Fotonic_Update_Notice::get();
t_equals( array( 'free', 'x' ), array_column( $got['items'], 'id' ), 'add-on line follows the plugin, junk dropped' );
t_equals( 'first free', $got['items'][1]['note'], 'add-on note kept' );
remove_all_filters( 'ftnc_pending_updates' );

wp_set_current_user( 0 );
t_equals( array(), Fotonic_Update_Notice::get()['items'], 'a person who cannot update plugins sees nothing' );
wp_set_current_user( (int) $admin[0] );

remove_filter( 'pre_site_transient_update_plugins', $f );
$same = $fake( FOTONIC_VERSION );
add_filter( 'pre_site_transient_update_plugins', $same );
t_equals( array(), Fotonic_Update_Notice::get()['items'], 'same version: nothing' );
remove_filter( 'pre_site_transient_update_plugins', $same );
add_filter( 'pre_site_transient_update_plugins', function () { return false; } );
t_equals( array(), Fotonic_Update_Notice::get()['items'], 'no update answer yet: nothing' );
