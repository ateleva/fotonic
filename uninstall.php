<?php
/**
 * Fotonic Uninstall
 *
 * Fired when the plugin is deleted via the WordPress admin.
 * Removes all CPT posts, postmeta, taxonomy terms, options, and vault files.
 *
 * @package Fotonic
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ---------------------------------------------------------------------------
// 1. Delete all custom post type posts (force delete — bypass trash).
// ---------------------------------------------------------------------------

$ftnc_post_types = array( 'ftnc_customer', 'ftnc_service', 'ftnc_work', 'ftnc_collaborator' );

foreach ( $ftnc_post_types as $post_type ) {
	$ftnc_post_ids = get_posts( array(
		'post_type'      => $post_type,
		'post_status'    => 'any',
		'numberposts'    => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
	) );

	foreach ( $ftnc_post_ids as $post_id ) {
		wp_delete_post( (int) $post_id, true );
	}
}

// ---------------------------------------------------------------------------
// 2. Remove orphaned postmeta (defensive — wp_delete_post should handle this,
//    but belt-and-suspenders for any postmeta that survived).
// ---------------------------------------------------------------------------

$ftnc_meta_prefixes = array(
	'_ftnc_people',
	'_ftnc_base_price',
	'_ftnc_notes',
	'_ftnc_event_date',
	'_ftnc_event_time_from',
	'_ftnc_event_time_to',
	'_ftnc_event_addresses',
	'_ftnc_customer_id',
	'_ftnc_owner_type',
	'_ftnc_owner_id',
	'_ftnc_work_services',
	'_ftnc_work_files',
	'_ftnc_total_price',
	'_ftnc_installments',
	'_ftnc_quick_notes',
	'_ftnc_color',
	'_ftnc_kanban_status',
	'_ftnc_collaborators',
);

foreach ( $ftnc_meta_prefixes as $ftnc_key ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Orphan postmeta cleanup in uninstall context; no caching needed.
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => $ftnc_key ), array( '%s' ) );
}

// ---------------------------------------------------------------------------
// 3. Delete taxonomy terms for ftnc_work_payment_status.
// ---------------------------------------------------------------------------

$ftnc_terms = get_terms( array(
	'taxonomy'   => 'ftnc_work_payment_status',
	'hide_empty' => false,
	'fields'     => 'ids',
) );

if ( is_array( $ftnc_terms ) ) {
	foreach ( $ftnc_terms as $ftnc_term_id ) {
		wp_delete_term( (int) $ftnc_term_id, 'ftnc_work_payment_status' );
	}
}

// ---------------------------------------------------------------------------
// 4. Delete plugin options.
// ---------------------------------------------------------------------------

$ftnc_options = array(
	'fotonic_vault_enabled',
	'fotonic_vault_salt',
	'fotonic_vault_totp_secret',
	'fotonic_vault_setup',
	'fotonic_server_secret_fallback',
	'fotonic_payment_types',
	'fotonic_pro_license_key',
);

foreach ( $ftnc_options as $ftnc_option ) {
	delete_option( $ftnc_option );
}

// ---------------------------------------------------------------------------
// 5. Delete all fotonic transients (rate-limit counters, reencrypt lock).
//    Transient keys: fotonic_vault_fails_{user_id}, fotonic_vault_reencrypt_lock.
// ---------------------------------------------------------------------------

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_fotonic_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_fotonic_' ) . '%'
	)
);

// ---------------------------------------------------------------------------
// 6. Remove vault upload directory and its files.
//    Direct filesystem used intentionally: WP_Filesystem form-auth is not
//    available in uninstall context. Path is built from wp_upload_dir() with
//    no user input.
// ---------------------------------------------------------------------------

$ftnc_upload_dir = wp_upload_dir();
$ftnc_vault_dir  = $ftnc_upload_dir['basedir'] . '/fotonic/vault';
$ftnc_fotonic_dir = $ftnc_upload_dir['basedir'] . '/fotonic';

if ( is_dir( $ftnc_vault_dir ) ) {
	// Recursive deletion handles any subdirectories that may exist.
	$ftnc_items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $ftnc_vault_dir, RecursiveDirectoryIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $ftnc_items as $ftnc_item ) {
		if ( $ftnc_item->isDir() ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $ftnc_item->getPathname() );
		} else {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			unlink( $ftnc_item->getPathname() );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	rmdir( $ftnc_vault_dir );
}

// Remove the fotonic uploads parent directory only if now empty.
if ( is_dir( $ftnc_fotonic_dir ) && ! ( new FilesystemIterator( $ftnc_fotonic_dir ) )->valid() ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	rmdir( $ftnc_fotonic_dir );
}
