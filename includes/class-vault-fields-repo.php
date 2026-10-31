<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * WordPress database access of Fotonic_Vault_Fields and the encrypt-existing job.
 *
 * Titles and content are written with $wpdb->update() and the post cache is cleared: wp_update_post()
 * would fire the save hooks (payment status, Google sync scheduling, VAT snapshot) and bump the
 * modification date, and an encryption pass must change none of that. Meta goes through
 * update_post_meta() (no modification date, no save hooks) with wp_slash().
 *
 * Every method takes an optional scope: a list of record IDs the pass is limited to. Production
 * passes null (all records); tests pass their own fixtures so a test can never touch real data.
 */
class Fotonic_Vault_Fields_Repo {

	const STATUSES = array( 'publish', 'draft', 'pending', 'private', 'future', 'trash' );

	private function status_sql(): string {
		return "'" . implode( "','", array_map( 'esc_sql', self::STATUSES ) ) . "'";
	}

	private function scope_sql( ?array $scope ): string {
		if ( null === $scope ) {
			return '';
		}
		$ids = array_values( array_filter( array_map( 'intval', $scope ) ) );
		return empty( $ids ) ? ' AND 1=0' : ' AND ID IN (' . implode( ',', $ids ) . ')';
	}

	/** @return int[] Up to $limit record IDs of the type, greater than $after, ascending. */
	public function ids_after( string $post_type, int $after, int $limit, ?array $scope = null ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- cursor walk over our own post types; the status list and IDs are built from constants and intval().
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d AND post_status IN ( " . $this->status_sql() . ' )' . $this->scope_sql( $scope ) . ' ORDER BY ID ASC LIMIT %d', $post_type, $after, $limit ) );
		return array_map( 'intval', (array) $ids );
	}

	public function count( string $post_type, ?array $scope = null ): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see ids_after().
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( " . $this->status_sql() . ' )' . $this->scope_sql( $scope ), $post_type ) );
	}

	public function get_post_field( int $post_id, string $field ): string {
		global $wpdb;
		if ( ! in_array( $field, array( 'post_title', 'post_content' ), true ) ) {
			return '';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- the column name is checked against a two-item list above; read straight from the table so no filter touches the value.
		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT {$field} FROM {$wpdb->posts} WHERE ID = %d", $post_id ) );
	}

	/** @param array<string,string> $fields post_title / post_content / post_name => value (already in stored form). */
	public function set_post_fields( int $post_id, array $fields ): bool {
		global $wpdb;
		$allowed = array_intersect_key( $fields, array_flip( array( 'post_title', 'post_content', 'post_name' ) ) );
		if ( empty( $allowed ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberately not wp_update_post(): no save hooks, no modification date change.
		$ok = $wpdb->update( $wpdb->posts, $allowed, array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		return false !== $ok;
	}

	public function get_meta( int $post_id, string $key ): string {
		$value = get_post_meta( $post_id, $key, true );
		return is_scalar( $value ) ? (string) $value : '';
	}

	public function set_meta( int $post_id, string $key, string $value ): bool {
		update_post_meta( $post_id, $key, wp_slash( $value ) );
		return true;
	}
}
