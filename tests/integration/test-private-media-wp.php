<?php
/**
 * Work documents stay out of the media library and out of core's single-attachment doors
 * (Fotonic_Private_Media), and their folder is protected (Site Health, restore). Test attachments are
 * created and removed here; no Vault state is read for writing and no real record is touched.
 */
require __DIR__ . '/../lib/wp-bootstrap.php';
require_once ABSPATH . 'wp-admin/includes/user.php';

$key  = random_bytes( 32 );
$tmp  = sys_get_temp_dir() . '/ftnc-pm-' . bin2hex( random_bytes( 4 ) ) . '.pdf';
file_put_contents( $tmp, "%PDF-1.7\nprivate media test" );
$up = Fotonic_Work_Files::store_upload( array( 'name' => 'prova.pdf', 'tmp_name' => $tmp, 'size' => filesize( $tmp ), 'error' => 0 ), $key, get_current_user_id() );
t_true( is_array( $up ), 'a private test file was stored' );
$mine = (int) $up['id'];
t_cleanup( $mine );

// An ordinary library attachment next to it.
$uploads = wp_upload_dir();
$plainf  = $uploads['path'] . '/ftnc-pm-plain-' . bin2hex( random_bytes( 3 ) ) . '.pdf';
file_put_contents( $plainf, "%PDF-1.7\nplain" );
$normal = wp_insert_attachment( array( 'post_title' => 'ftnc pm normal', 'post_mime_type' => 'application/pdf', 'post_status' => 'inherit' ), $plainf );
t_cleanup( (int) $normal );

// Media modal / list / REST queries.
$args = Fotonic_Private_Media::exclude_from_media_ajax( array( 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids' ) );
$ids  = ( new WP_Query( $args ) )->posts;
t_true( ! in_array( $mine, $ids, true ), 'the media modal query does not list the private file' );
t_true( in_array( (int) $normal, $ids, true ), 'the media modal query still lists an ordinary file' );
$args = Fotonic_Private_Media::exclude_from_media_rest( array( 'post_type' => 'attachment', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => array( array( 'key' => '_wp_attached_file', 'compare' => 'EXISTS' ) ) ) );
$ids  = ( new WP_Query( $args ) )->posts;
t_true( ! in_array( $mine, $ids, true ) && in_array( (int) $normal, $ids, true ), 'an existing meta query is kept and the private file is still hidden' );

// Capabilities: an Editor never reads, edits or deletes it; an administrator only with the Vault open (or no Vault).
$editor = wp_insert_user( array( 'user_login' => 'ftnc_pm_' . bin2hex( random_bytes( 3 ) ), 'user_pass' => wp_generate_password(), 'role' => 'editor' ) );
t_true( is_int( $editor ), 'a test Editor exists' );
foreach ( array( 'read_post', 'edit_post', 'delete_post' ) as $cap ) {
	t_equals( false, user_can( $editor, $cap, $mine ), "an Editor cannot $cap the private file" );
}
$admin_ok = ! Fotonic_Vault::is_setup() || Fotonic_Vault::is_unlocked();
t_equals( $admin_ok, current_user_can( 'read_post', $mine ), 'the administrator reads it only with the Vault open (or no Vault)' );
t_equals( true, current_user_can( 'read_post', (int) $normal ), 'an ordinary file is not affected' );
wp_delete_user( $editor );

// REST: no address in the core media answer; admin-ajax get-attachment is refused for who cannot read.
$resp = Fotonic_Private_Media::hide_media_url( new WP_REST_Response( array( 'source_url' => 'x', 'guid' => 'y', 'id' => $mine ) ), get_post( $mine ) );
t_true( ! isset( $resp->get_data()['source_url'] ) && ! isset( $resp->get_data()['guid'] ), 'the core media answer carries no file address' );
$resp = Fotonic_Private_Media::hide_media_url( new WP_REST_Response( array( 'source_url' => 'x' ) ), get_post( (int) $normal ) );
t_equals( 'x', $resp->get_data()['source_url'], 'an ordinary file keeps its address' );

// The folder is protected and restores know it.
t_true( in_array( 'work-files', Fotonic_Site_Health::protected_folders(), true ), 'Site Health probes the folder' );
$m = new ReflectionMethod( 'Fotonic_Restore_Files', 'private_dir_of' );
$m->setAccessible( true );
t_equals( 'fotonic/work-files', $m->invoke( null, 'fotonic/work-files/abc.pdf.enc' ), 'a restore sees the folder as private' );
t_equals( null, $m->invoke( null, '2026/05/abc.pdf' ), 'a library file is not private' );
t_true( file_exists( Fotonic_Work_Files::dir() . '/.htaccess' ) && file_exists( Fotonic_Work_Files::dir() . '/index.php' ), 'the folder has its deny-all guards' );

@unlink( $tmp );
t_done();
