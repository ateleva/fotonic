<?php
/**
 * REST API of the Bin (phase 16). Namespace fotonic/v1, every route needs manage_options + X-WP-Nonce.
 *
 *   GET  /trash/summary  -> { count, by_type, auto_days, word } (two cheap queries; every list shortcut and the Settings card read it)
 *   GET  /trash          -> { data, total, pages }           (?page, ?per_page, ?type = one record type, 400 when it is not one)
 *   POST /trash/restore  { ids[] }                           -> { results: [ { id, ok, warnings[] } ] }
 *   POST /trash/delete   { ids[] } | { all: true, type?, skip[] }, confirm   (`all` + `type` = only that type; an invalid type is 400, never "everything")
 *                        -> { deleted, blocked[], processed[], remaining, missing }  (one batch; call again while remaining > 0)
 *
 * Deleting more than one record (or "all") needs `confirm` = the word the summary returns; one record does not.
 *
 * @package Fotonic
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_Trash_REST_API {

	const NAMESPACE = 'fotonic/v1';

	public static function register_routes(): void {
		$perm = array( __CLASS__, 'admin_permission' );
		register_rest_route( self::NAMESPACE, '/trash/summary', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'summary' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( self::NAMESPACE, '/trash', array(
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'items' ),
				'permission_callback' => $perm,
				'args'                => array(
					'page'     => array( 'type' => 'integer', 'default' => 1, 'sanitize_callback' => 'absint' ),
					'per_page' => array( 'type' => 'integer', 'default' => 24, 'sanitize_callback' => 'absint' ),
					'type'     => array( 'type' => 'string', 'default' => '', 'sanitize_callback' => 'sanitize_key' ),
				),
			),
		) );
		register_rest_route( self::NAMESPACE, '/trash/restore', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'restore' ), 'permission_callback' => $perm ),
		) );
		register_rest_route( self::NAMESPACE, '/trash/delete', array(
			array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( __CLASS__, 'delete' ), 'permission_callback' => $perm ),
		) );
	}

	/** Capability AND nonce, like the other routes of the plugin. */
	public static function admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	private static function error( string $code, string $message, int $status ): \WP_REST_Response {
		return new \WP_REST_Response( array( 'code' => $code, 'message' => $message ), $status );
	}

	/** @return int[] */
	private static function ids( $raw ): array {
		return array_values( array_filter( array_map( 'absint', is_array( $raw ) ? $raw : array() ) ) );
	}

	public static function summary(): \WP_REST_Response {
		return new \WP_REST_Response( array(
			'count'     => Fotonic_Trash::count(),
			'by_type'   => (object) Fotonic_Trash::by_type(),
			'names'     => (object) Fotonic_Trash::type_names(),
			'auto_days' => Fotonic_Trash::auto_days(),
			'word'      => Fotonic_Trash::WORD,
		), 200 );
	}

	/**
	 * The `type` parameter: '' = every type; anything else must be a record type of the Bin.
	 *
	 * @return string|null|\WP_REST_Response Null for every type, the type, or a 400 response.
	 */
	private static function type_param( \WP_REST_Request $req ) {
		$type = $req->get_param( 'type' );
		if ( null === $type || '' === $type ) {
			return null;
		}
		if ( ! Fotonic_Trash::valid_type( $type ) ) {
			return self::error( 'invalid_type', __( 'This kind of item is not in the Bin.', 'eleva-crm-for-photographers' ), 400 );
		}
		return $type;
	}

	public static function items( \WP_REST_Request $req ): \WP_REST_Response {
		$type = self::type_param( $req );
		if ( $type instanceof \WP_REST_Response ) {
			return $type;
		}
		$out = Fotonic_Trash::items( (int) $req->get_param( 'page' ), (int) $req->get_param( 'per_page' ), $type );
		return new \WP_REST_Response( $out, 200 );
	}

	public static function restore( \WP_REST_Request $req ): \WP_REST_Response {
		$guard = Fotonic_Trash::guard();
		if ( is_wp_error( $guard ) ) {
			return self::error( $guard->get_error_code(), $guard->get_error_message(), 423 );
		}
		$ids = self::ids( $req->get_param( 'ids' ) );
		if ( ! $ids || count( $ids ) > 100 ) {
			return self::error( 'bad_request', __( 'Choose what to restore.', 'eleva-crm-for-photographers' ), 400 );
		}
		$results = array();
		foreach ( $ids as $id ) {
			$results[] = Fotonic_Trash::restore( $id );
		}
		return new \WP_REST_Response( array( 'results' => $results ), 200 );
	}

	public static function delete( \WP_REST_Request $req ): \WP_REST_Response {
		$guard = Fotonic_Trash::guard();
		if ( is_wp_error( $guard ) ) {
			return self::error( $guard->get_error_code(), $guard->get_error_message(), 423 );
		}
		$all = true === $req->get_param( 'all' );
		$ids = self::ids( $req->get_param( 'ids' ) );
		if ( ! $all && ! $ids ) {
			return self::error( 'bad_request', __( 'Choose what to delete.', 'eleva-crm-for-photographers' ), 400 );
		}
		// "All" never takes a list from the client: the server picks what is in the Bin.
		if ( $all ) {
			$ids = array();
		}
		if ( Fotonic_Trash::needs_word( $all, count( $ids ) ) && ! Fotonic_Trash::confirm_ok( $req->get_param( 'confirm' ) ) ) {
			return self::error( 'confirm_required', __( 'Type the word to confirm.', 'eleva-crm-for-photographers' ), 400 );
		}
		$type = $all ? self::type_param( $req ) : null;
		if ( $type instanceof \WP_REST_Response ) {
			return $type;
		}
		$out = Fotonic_Trash::delete_batch( $all ? null : $ids, $all, self::ids( $req->get_param( 'skip' ) ), $type );
		return new \WP_REST_Response( $out, 200 );
	}
}
