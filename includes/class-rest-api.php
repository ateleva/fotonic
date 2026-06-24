<?php
/**
 * Fotonic REST API
 *
 * Full CRUD for customers, services, and works.
 * Namespace: fotonic/v1
 *
 * @package Fotonic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fotonic_REST_API {

	const NAMESPACE = 'fotonic/v1';

	// ---------------------------------------------------------------------------
	// Route registration
	// ---------------------------------------------------------------------------

	public static function register_routes(): void {

		// ------------------------------------------------------------------
		// Vault (Phase C).
		// ------------------------------------------------------------------

		register_rest_route( self::NAMESPACE, '/vault/setup', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'vault_setup' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/vault/unlock', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'vault_unlock' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/vault/lock', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'vault_lock' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/vault/status', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'vault_status' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/vault/change-password', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'vault_change_password' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/vault/reset-totp', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'vault_reset_totp' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/vault/recovery/reset-password', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'vault_recovery_reset_password' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/vault/recovery/reset-totp', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'vault_recovery_reset_totp' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/vault/recovery/regenerate', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'vault_recovery_regenerate' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/vault/recovery/enroll-phrase', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'vault_recovery_enroll_phrase' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/vault/recovery/reset-password-phrase', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'vault_recovery_reset_password_phrase' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/vault/reset', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'vault_reset' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/vault-download/(?P<id>\d+)', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'vault_download' ],
			'permission_callback' => [ __CLASS__, 'vault_download_permission' ],
		] );

		// ------------------------------------------------------------------
		// Customers
		// ------------------------------------------------------------------
		register_rest_route( self::NAMESPACE, '/customers', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_customers' ],
				'permission_callback' => [ __CLASS__, 'data_permission' ],
				'args'                => [
					'search'   => [ 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
					'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
					'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
				],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_customer' ],
				'permission_callback' => [ __CLASS__, 'data_permission' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/customers/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_customer' ],
				'permission_callback' => [ __CLASS__, 'data_permission' ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ __CLASS__, 'update_customer' ],
				'permission_callback' => [ __CLASS__, 'data_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'delete_customer' ],
				'permission_callback' => [ __CLASS__, 'data_permission' ],
			],
		] );

		// ------------------------------------------------------------------
		// Services
		// ------------------------------------------------------------------
		register_rest_route( self::NAMESPACE, '/services', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_services' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_service' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/services/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_service' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ __CLASS__, 'update_service' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'delete_service' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
		] );

		// ------------------------------------------------------------------
		// Works
		// ------------------------------------------------------------------
		register_rest_route( self::NAMESPACE, '/works', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_works' ],
				'permission_callback' => [ __CLASS__, 'data_permission' ],
				'args'                => [
					'search'         => [ 'type' => 'string',  'default' => '', 'sanitize_callback' => 'sanitize_text_field' ],
					'page'           => [ 'type' => 'integer', 'default' => 1,  'minimum' => 1 ],
					'per_page'       => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
					'payment_status' => [
						'type'    => 'string',
						'default' => '',
						'enum'    => [ '', 'paid', 'partial', 'unpaid' ],
					],
					'customer_id'    => [ 'type' => 'integer', 'default' => 0, 'minimum' => 0 ],
				],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_work' ],
				'permission_callback' => [ __CLASS__, 'data_permission' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/works/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_work' ],
				'permission_callback' => [ __CLASS__, 'data_permission' ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ __CLASS__, 'update_work' ],
				'permission_callback' => [ __CLASS__, 'data_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'delete_work' ],
				'permission_callback' => [ __CLASS__, 'data_permission' ],
			],
		] );

		// ------------------------------------------------------------------
		// Can-delete checks
		// ------------------------------------------------------------------
		register_rest_route( self::NAMESPACE, '/customers/(?P<id>\d+)/can-delete', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'can_delete_customer' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/services/(?P<id>\d+)/can-delete', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'can_delete_service' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/works/(?P<id>\d+)/can-delete', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'can_delete_work' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		// ------------------------------------------------------------------
		// Calendar — GET /calendar?from=YYYY-MM-DD&to=YYYY-MM-DD
		// ------------------------------------------------------------------
		register_rest_route( self::NAMESPACE, '/calendar', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_calendar_works' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
			'args'                => [
				'from' => [ 'type' => 'string', 'required' => true ],
				'to'   => [ 'type' => 'string', 'required' => true ],
			],
		] );

		// ------------------------------------------------------------------
		// Payment Types
		// ------------------------------------------------------------------
		register_rest_route( self::NAMESPACE, '/payment-types', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_payment_types' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_payment_type' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
				'args'                => [
					'label' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
		] );

		register_rest_route( self::NAMESPACE, '/dashboard-stats', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'get_dashboard_stats' ],
			'permission_callback' => [ __CLASS__, 'admin_permission' ],
		] );

		register_rest_route( self::NAMESPACE, '/payment-types/(?P<id>\d+)', [
			[
				'methods'             => 'PUT',
				'callback'            => [ __CLASS__, 'update_payment_type' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
				'args'                => [
					'label' => [
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'delete_payment_type' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
		] );
	}

	// ---------------------------------------------------------------------------
	// Permission callbacks
	// ---------------------------------------------------------------------------

	public static function admin_permission(): bool {
		return current_user_can( 'manage_options' ) &&
			(bool) wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ?? '' ) ),
				'wp_rest'
			);
	}

	public static function vault_download_permission( \WP_REST_Request $request ): bool {
		// Verify REST nonce from X-WP-Nonce header or _wpnonce query param.
		$nonce = $request->get_header( 'x_wp_nonce' );
		if ( empty( $nonce ) ) {
			$nonce = $request->get_param( '_wpnonce' );
		}
		if ( empty( $nonce ) || ! wp_verify_nonce( sanitize_text_field( (string) $nonce ), 'wp_rest' ) ) {
			return false;
		}
		return current_user_can( 'manage_options' ) && Fotonic_Vault::is_unlocked();
	}

	/**
	 * Permission callback for customer/work data endpoints.
	 * Delegates to the standard admin nonce check.
	 *
	 * @return bool
	 */
	public static function data_permission(): bool {
		return self::admin_permission();
	}

	// ---------------------------------------------------------------------------
	// Vault endpoints (Phase C)
	// ---------------------------------------------------------------------------

	/**
	 * POST /vault/setup
	 * Body: { password, totp_secret? }
	 * Returns: { setup, qr_uri, recovery_code, recovery_phrase }
	 */
	public static function vault_setup( \WP_REST_Request $req ): \WP_REST_Response {
		if ( Fotonic_Vault::is_setup() ) {
			return new \WP_REST_Response(
				[ 'code' => 'already_setup', 'message' => __( 'Vault is already configured. Use change-password to update credentials.', 'eleva-crm-for-photographers' ) ],
				409
			);
		}

		$body        = $req->get_json_params();
		$password    = isset( $body['password'] )    ? (string) $body['password']    : '';
		$totp_secret = isset( $body['totp_secret'] ) ? (string) $body['totp_secret'] : '';

		if ( empty( $password ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_password', 'message' => __( 'Password is required.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		if ( strlen( $password ) < 12 ) {
			return new \WP_REST_Response(
				[ 'code' => 'password_too_short', 'message' => __( 'Vault password must be at least 12 characters.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		// If no totp_secret supplied, generate a fresh one.
		if ( empty( $totp_secret ) ) {
			$totp_secret = Fotonic_TOTP::generate_secret();
		}

		$result = Fotonic_Vault::setup( $password, $totp_secret );
		if ( ! $result['ok'] ) {
			return new \WP_REST_Response(
				[ 'code' => 'setup_failed', 'message' => __( 'Vault setup failed.', 'eleva-crm-for-photographers' ) ],
				500
			);
		}

		$site_name = get_bloginfo( 'name' ) ?: 'Eleva CRM';
		$label     = $site_name . ':' . wp_get_current_user()->user_email;
		$qr_uri    = Fotonic_TOTP::get_uri( $totp_secret, $label, $site_name );

		self::audit_log( 'vault_setup' );

		return new \WP_REST_Response( [
			'setup'           => true,
			'qr_uri'          => $qr_uri,
			'recovery_code'   => $result['recovery_code'],
			'recovery_phrase' => $result['recovery_phrase'],
		], 200 );
	}

	/**
	 * POST /vault/unlock
	 * Body: { password, otp }
	 * Returns: { unlocked: true }
	 */
	public static function vault_unlock( \WP_REST_Request $req ): \WP_REST_Response {
		$fail_key = 'fotonic_vault_fails_' . get_current_user_id();
		$attempts = (int) get_transient( $fail_key );
		if ( $attempts >= 5 ) {
			return new \WP_REST_Response(
				array( 'code' => 'rate_limited', 'message' => __( 'Too many failed attempts. Try again in 15 minutes.', 'eleva-crm-for-photographers' ) ),
				429
			);
		}

		$body     = $req->get_json_params();
		$password = isset( $body['password'] ) ? (string) $body['password'] : '';
		$otp      = isset( $body['otp'] )      ? (string) $body['otp']      : '';

		if ( empty( $password ) || empty( $otp ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_fields', 'message' => __( 'Password and OTP are required.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		$ok = Fotonic_Vault::unlock( $password, $otp );
		if ( ! $ok ) {
			set_transient( $fail_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
			self::audit_log( 'vault_unlock_fail' );
			return new \WP_REST_Response(
				[ 'code' => 'invalid_credentials', 'message' => __( 'Invalid password or OTP code.', 'eleva-crm-for-photographers' ) ],
				401
			);
		}

		delete_transient( $fail_key );
		self::audit_log( 'vault_unlock_ok' );

		return new \WP_REST_Response( [ 'unlocked' => true ], 200 );
	}

	/**
	 * POST /vault/lock
	 * Returns: { locked: true }
	 */
	public static function vault_lock( \WP_REST_Request $_req ): \WP_REST_Response {
		Fotonic_Vault::lock();
		self::audit_log( 'vault_lock' );
		return new \WP_REST_Response( [ 'locked' => true ], 200 );
	}

	/**
	 * GET /vault/status
	 * Returns: { setup, unlocked, has_recovery_code, has_recovery_phrase, scheme }
	 */
	public static function vault_status( \WP_REST_Request $_req ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'setup'               => Fotonic_Vault::is_setup(),
			'unlocked'            => Fotonic_Vault::is_unlocked(),
			'has_recovery_code'   => Fotonic_Vault::has_recovery(),
			'has_recovery_phrase' => Fotonic_Vault::has_recovery_phrase(),
			'scheme'              => Fotonic_Vault::get_scheme(),
		], 200 );
	}

	/**
	 * GET /vault-download/{id}
	 * Streams WP attachment file. Permission callback already checks vault + capability.
	 *
	 * Returns WP_REST_Response for error cases. For the success path, outputs headers
	 * and calls readfile() + exit() — this is required for binary file streaming and
	 * is a recognised exception to the "return WP_REST_Response" convention.
	 *
	 * @return \WP_REST_Response On error only; success path terminates via exit().
	 */
	public static function vault_download( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || ! is_a( $post, 'WP_Post' ) || strpos( $post->post_mime_type, '/' ) === false ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'File not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'file_missing', 'message' => __( 'File not found on disk.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		// Verify the resolved path is inside the uploads directory (path traversal guard).
		$real_file    = realpath( $file );
		$real_basedir = realpath( wp_upload_dir()['basedir'] );
		if ( ! $real_file || ! $real_basedir || strpos( $real_file, rtrim( $real_basedir, '/' ) . '/' ) !== 0 ) {
			return new \WP_REST_Response(
				[ 'code' => 'forbidden', 'message' => __( 'Access denied.', 'eleva-crm-for-photographers' ) ],
				403
			);
		}

		global $wpdb;
		// Narrow candidates with LIKE (catches all positions: [10], [10,...], [...,10], [...,10,...]),
		// then decode JSON and confirm exact integer membership server-side. Avoids substring false-positives
		// (ID 1 vs [10,11]) and works regardless of array position/whitespace.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Ownership check must be real-time; caching stale results would allow IDOR. Prepared query, no user-controlled table name.
		$candidates = $wpdb->get_col( $wpdb->prepare(
			"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_ftnc_work_files' AND meta_value LIKE %s",
			'%' . $wpdb->esc_like( (string) $id ) . '%'
		) );
		$linked = false;
		foreach ( $candidates as $raw ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $maybe_id ) {
					if ( (int) $maybe_id === (int) $id ) {
						$linked = true;
						break 2;
					}
				}
			}
		}
		if ( ! $linked ) {
			return new \WP_REST_Response( array( 'code' => 'forbidden', 'message' => __( 'Access denied.', 'eleva-crm-for-photographers' ) ), 403 );
		}

		// Sanitize headers before output.
		$mime     = sanitize_mime_type( $post->post_mime_type );
		$filename = sanitize_file_name( basename( $real_file ) );
		// Strip any CRLF that could cause header injection.
		$filename = str_replace( [ "\r", "\n" ], '', $filename );

		// Disable output buffering to stream efficiently.
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- MIME and filename sanitized above.
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . esc_attr( $filename ) . '"' );
		header( 'Content-Length: ' . (int) filesize( $real_file ) );
		header( 'X-Content-Type-Options: nosniff' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $real_file );
		exit; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Binary stream requires exit to prevent WP HTML appended after file content.
	}

	// ---------------------------------------------------------------------------
	// Vault management (Phase G)
	// ---------------------------------------------------------------------------

	/**
	 * POST /vault/change-password
	 * Body: { current_password, otp, new_password }
	 * Envelope scheme v2: re-wraps MK under new KEK — no PII re-encryption needed.
	 */
	public static function vault_change_password( \WP_REST_Request $req ): \WP_REST_Response {
		$fail_key = 'fotonic_vault_fails_' . get_current_user_id();
		$attempts = (int) get_transient( $fail_key );
		if ( $attempts >= 5 ) {
			return new \WP_REST_Response(
				array( 'code' => 'rate_limited', 'message' => __( 'Too many failed attempts. Try again in 15 minutes.', 'eleva-crm-for-photographers' ) ),
				429
			);
		}

		$body             = $req->get_json_params();
		$current_password = isset( $body['current_password'] ) ? (string) $body['current_password'] : '';
		$otp              = isset( $body['otp'] )              ? (string) $body['otp']              : '';
		$new_password     = isset( $body['new_password'] )     ? (string) $body['new_password']     : '';

		if ( empty( $current_password ) || empty( $otp ) || empty( $new_password ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_fields', 'message' => __( 'All fields are required.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		if ( strlen( $new_password ) < 12 ) {
			return new \WP_REST_Response(
				[ 'code' => 'password_too_short', 'message' => __( 'New vault password must be at least 12 characters.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		if ( ! Fotonic_Vault::is_setup() ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_setup', 'message' => __( 'Vault is not set up.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		$ok = Fotonic_Vault::change_password( $current_password, $otp, $new_password );

		if ( ! $ok ) {
			set_transient( $fail_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
			self::audit_log( 'vault_password_change_fail' );
			return new \WP_REST_Response(
				[ 'code' => 'invalid_credentials', 'message' => __( 'Invalid password or OTP code.', 'eleva-crm-for-photographers' ) ],
				401
			);
		}

		delete_transient( $fail_key );
		self::audit_log( 'vault_password_changed' );

		return new \WP_REST_Response( [ 'changed' => true ], 200 );
	}

	/**
	 * POST /vault/reset-totp
	 * Body: { password, otp }
	 * Generates a new TOTP secret while keeping the same password.
	 * Works for both legacy scheme v1 and envelope scheme v2.
	 * Returns: { reset: true, qr_uri: string }
	 */
	public static function vault_reset_totp( \WP_REST_Request $req ): \WP_REST_Response {
		$fail_key = 'fotonic_vault_fails_' . get_current_user_id();
		$attempts = (int) get_transient( $fail_key );
		if ( $attempts >= 5 ) {
			return new \WP_REST_Response(
				array( 'code' => 'rate_limited', 'message' => __( 'Too many failed attempts. Try again in 15 minutes.', 'eleva-crm-for-photographers' ) ),
				429
			);
		}

		$body     = $req->get_json_params();
		$password = isset( $body['password'] ) ? (string) $body['password'] : '';
		$otp      = isset( $body['otp'] )      ? (string) $body['otp']      : '';

		if ( empty( $password ) || empty( $otp ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_fields', 'message' => __( 'Password and OTP are required.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		if ( ! Fotonic_Vault::is_setup() ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_setup', 'message' => __( 'Vault is not set up.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		$salt = (string) get_option( Fotonic_Vault::OPTION_SALT, '' );
		if ( empty( $salt ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'vault_error', 'message' => __( 'Vault configuration error.', 'eleva-crm-for-photographers' ) ],
				500
			);
		}

		$new_totp_secret = '';
		$qr_uri          = '';

		if ( Fotonic_Vault::get_scheme() >= 2 ) {
			// Scheme v2: verify via unlock (checks wrap_pw + wrap_totp), then
			// generate new TOTP and re-wrap under MK.
			$wrap_pw   = (string) get_option( Fotonic_Vault::OPTION_WRAP_PW, '' );
			$wrap_totp = (string) get_option( Fotonic_Vault::OPTION_WRAP_TOTP, '' );
			if ( empty( $wrap_pw ) || empty( $wrap_totp ) ) {
				return new \WP_REST_Response(
					[ 'code' => 'vault_error', 'message' => __( 'Vault configuration error.', 'eleva-crm-for-photographers' ) ],
					500
				);
			}
			$kek_pw = Fotonic_Crypto::derive_key( $password, $salt );
			$mk     = Fotonic_Crypto::unwrap( $wrap_pw, $kek_pw );
			if ( false === $mk ) {
				set_transient( $fail_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
				return new \WP_REST_Response(
					[ 'code' => 'invalid_credentials', 'message' => __( 'Invalid password or OTP code.', 'eleva-crm-for-photographers' ) ],
					401
				);
			}
			$current_totp = Fotonic_Crypto::unwrap( $wrap_totp, $mk );
			if ( false === $current_totp || ! Fotonic_TOTP::verify( $otp, $current_totp ) ) {
				set_transient( $fail_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
				return new \WP_REST_Response(
					[ 'code' => 'invalid_credentials', 'message' => __( 'Invalid password or OTP code.', 'eleva-crm-for-photographers' ) ],
					401
				);
			}
			$new_totp_secret = Fotonic_TOTP::generate_secret();
			$new_wrap_totp   = Fotonic_Crypto::wrap( strtoupper( $new_totp_secret ), $mk );
			if ( empty( $new_wrap_totp ) ) {
				return new \WP_REST_Response(
					[ 'code' => 'encrypt_error', 'message' => __( 'Encryption error.', 'eleva-crm-for-photographers' ) ],
					500
				);
			}
			update_option( Fotonic_Vault::OPTION_WRAP_TOTP, $new_wrap_totp, false );
		} else {
			// Legacy scheme v1 path.
			$encrypted_totp = (string) get_option( Fotonic_Vault::OPTION_TOTP, '' );
			if ( empty( $encrypted_totp ) ) {
				return new \WP_REST_Response(
					[ 'code' => 'vault_error', 'message' => __( 'Vault configuration error.', 'eleva-crm-for-photographers' ) ],
					500
				);
			}
			$key         = Fotonic_Crypto::derive_key( $password, $salt );
			$totp_secret = Fotonic_Crypto::decrypt( $encrypted_totp, $key );
			if ( empty( $totp_secret ) || ! Fotonic_TOTP::verify( $otp, $totp_secret ) ) {
				set_transient( $fail_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
				return new \WP_REST_Response(
					[ 'code' => 'invalid_credentials', 'message' => __( 'Invalid password or OTP code.', 'eleva-crm-for-photographers' ) ],
					401
				);
			}
			$new_totp_secret    = Fotonic_TOTP::generate_secret();
			$new_encrypted_totp = Fotonic_Crypto::encrypt( strtoupper( $new_totp_secret ), $key );
			if ( empty( $new_encrypted_totp ) ) {
				return new \WP_REST_Response(
					[ 'code' => 'encrypt_error', 'message' => __( 'Encryption error.', 'eleva-crm-for-photographers' ) ],
					500
				);
			}
			update_option( Fotonic_Vault::OPTION_TOTP, $new_encrypted_totp, false );
		}

		delete_transient( $fail_key );

		$site_name = get_bloginfo( 'name' ) ?: 'Eleva CRM';
		$label     = $site_name . ':' . wp_get_current_user()->user_email;
		$qr_uri    = Fotonic_TOTP::get_uri( $new_totp_secret, $label, $site_name );

		return new \WP_REST_Response( [
			'reset'  => true,
			'qr_uri' => $qr_uri,
		], 200 );
	}

	// ---------------------------------------------------------------------------
	// Vault recovery endpoints
	// ---------------------------------------------------------------------------

	/**
	 * POST /vault/recovery/reset-password
	 * Body: { recovery_code, new_password }
	 * Reset the vault password using a recovery code (lost OTP path).
	 * Rate-limited to 5 attempts / 15 min.
	 */
	public static function vault_recovery_reset_password( \WP_REST_Request $req ): \WP_REST_Response {
		$fail_key = 'fotonic_vault_fails_' . get_current_user_id();
		$attempts = (int) get_transient( $fail_key );
		if ( $attempts >= 5 ) {
			return new \WP_REST_Response(
				array( 'code' => 'rate_limited', 'message' => __( 'Too many failed attempts. Try again in 15 minutes.', 'eleva-crm-for-photographers' ) ),
				429
			);
		}

		$body          = $req->get_json_params();
		$recovery_code = isset( $body['recovery_code'] ) ? (string) $body['recovery_code'] : '';
		$new_password  = isset( $body['new_password'] )  ? (string) $body['new_password']  : '';

		if ( empty( $recovery_code ) || empty( $new_password ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_fields', 'message' => __( 'Recovery code and new password are required.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		if ( strlen( $new_password ) < 12 ) {
			return new \WP_REST_Response(
				[ 'code' => 'password_too_short', 'message' => __( 'New vault password must be at least 12 characters.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		if ( ! Fotonic_Vault::is_setup() ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_setup', 'message' => __( 'Vault is not set up.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		$ok = Fotonic_Vault::recover_reset_password( $recovery_code, $new_password );
		if ( ! $ok ) {
			set_transient( $fail_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
			self::audit_log( 'vault_recovery_password_reset_fail' );
			return new \WP_REST_Response(
				[ 'code' => 'invalid_recovery_code', 'message' => __( 'Invalid recovery code.', 'eleva-crm-for-photographers' ) ],
				401
			);
		}

		delete_transient( $fail_key );
		self::audit_log( 'vault_recovery_password_reset' );

		return new \WP_REST_Response( [ 'reset' => true ], 200 );
	}

	/**
	 * POST /vault/recovery/reset-totp
	 * Body: { password, recovery_code }
	 * Reset the TOTP secret when the OTP device is lost.
	 * Both password AND recovery code are required (dual-factor).
	 * Rate-limited to 5 attempts / 15 min.
	 */
	public static function vault_recovery_reset_totp( \WP_REST_Request $req ): \WP_REST_Response {
		$fail_key = 'fotonic_vault_fails_' . get_current_user_id();
		$attempts = (int) get_transient( $fail_key );
		if ( $attempts >= 5 ) {
			return new \WP_REST_Response(
				array( 'code' => 'rate_limited', 'message' => __( 'Too many failed attempts. Try again in 15 minutes.', 'eleva-crm-for-photographers' ) ),
				429
			);
		}

		$body          = $req->get_json_params();
		$password      = isset( $body['password'] )      ? (string) $body['password']      : '';
		$recovery_code = isset( $body['recovery_code'] ) ? (string) $body['recovery_code'] : '';

		if ( empty( $password ) || empty( $recovery_code ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_fields', 'message' => __( 'Password and recovery code are required.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		if ( ! Fotonic_Vault::is_setup() ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_setup', 'message' => __( 'Vault is not set up.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		$result = Fotonic_Vault::recover_reset_totp( $password, $recovery_code );
		if ( false === $result ) {
			set_transient( $fail_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
			self::audit_log( 'vault_recovery_totp_reset_fail' );
			return new \WP_REST_Response(
				[ 'code' => 'invalid_credentials', 'message' => __( 'Invalid password or recovery code.', 'eleva-crm-for-photographers' ) ],
				401
			);
		}

		delete_transient( $fail_key );
		self::audit_log( 'vault_recovery_totp_reset' );

		return new \WP_REST_Response( [
			'reset'  => true,
			'qr_uri' => $result['qr_uri'],
		], 200 );
	}

	/**
	 * POST /vault/recovery/regenerate
	 * Generate a new recovery code (replaces the old one).
	 * Requires vault to be unlocked (active session cookie).
	 * Rate-limited to 5 attempts / 15 min.
	 */
	public static function vault_recovery_regenerate( \WP_REST_Request $_req ): \WP_REST_Response {
		$fail_key = 'fotonic_vault_fails_' . get_current_user_id();
		$attempts = (int) get_transient( $fail_key );
		if ( $attempts >= 5 ) {
			return new \WP_REST_Response(
				array( 'code' => 'rate_limited', 'message' => __( 'Too many failed attempts. Try again in 15 minutes.', 'eleva-crm-for-photographers' ) ),
				429
			);
		}

		if ( ! Fotonic_Vault::is_unlocked() ) {
			return new \WP_REST_Response(
				[ 'code' => 'vault_locked', 'message' => __( 'Vault must be unlocked to regenerate recovery code.', 'eleva-crm-for-photographers' ) ],
				403
			);
		}

		$result = Fotonic_Vault::regenerate_recovery_code();

		if ( false === $result ) {
			set_transient( $fail_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
			self::audit_log( 'vault_recovery_regenerate_fail' );
			return new \WP_REST_Response(
				[ 'code' => 'regenerate_failed', 'message' => __( 'Failed to regenerate recovery code.', 'eleva-crm-for-photographers' ) ],
				500
			);
		}

		delete_transient( $fail_key );
		self::audit_log( 'vault_recovery_regenerated' );

		return new \WP_REST_Response( [
			'regenerated'   => true,
			'recovery_code' => $result['recovery_code'],
		], 200 );
	}

	/**
	 * POST /vault/recovery/enroll-phrase
	 * Enroll (or re-enroll) a recovery phrase.
	 * Requires vault to be unlocked (active session cookie).
	 * Returns the recovery phrase ONCE — it is never stored in plaintext.
	 */
	public static function vault_recovery_enroll_phrase( \WP_REST_Request $_req ): \WP_REST_Response {
		if ( ! Fotonic_Vault::is_unlocked() ) {
			return new \WP_REST_Response(
				[ 'code' => 'vault_locked', 'message' => __( 'Vault must be unlocked to enroll a recovery phrase.', 'eleva-crm-for-photographers' ) ],
				403
			);
		}

		$result = Fotonic_Vault::enroll_recovery_phrase();
		if ( false === $result ) {
			self::audit_log( 'vault_recovery_enroll_phrase_fail' );
			return new \WP_REST_Response(
				[ 'code' => 'enroll_failed', 'message' => __( 'Failed to enroll recovery phrase.', 'eleva-crm-for-photographers' ) ],
				500
			);
		}

		self::audit_log( 'vault_recovery_phrase_enrolled' );
		return new \WP_REST_Response( [
			'enrolled'        => true,
			'recovery_phrase' => $result['recovery_phrase'],
		], 200 );
	}

	/**
	 * POST /vault/recovery/reset-password-phrase
	 * Body: { recovery_phrase, new_password }
	 * Reset the vault password using a recovery phrase.
	 * Rate-limited to 5 attempts / 15 min.
	 */
	public static function vault_recovery_reset_password_phrase( \WP_REST_Request $req ): \WP_REST_Response {
		$fail_key = 'fotonic_vault_fails_' . get_current_user_id();
		$attempts = (int) get_transient( $fail_key );
		if ( $attempts >= 5 ) {
			return new \WP_REST_Response(
				array( 'code' => 'rate_limited', 'message' => __( 'Too many failed attempts. Try again in 15 minutes.', 'eleva-crm-for-photographers' ) ),
				429
			);
		}

		$body            = $req->get_json_params();
		$recovery_phrase = isset( $body['recovery_phrase'] ) ? (string) $body['recovery_phrase'] : '';
		$new_password    = isset( $body['new_password'] )    ? (string) $body['new_password']    : '';

		if ( empty( $recovery_phrase ) || empty( $new_password ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_fields', 'message' => __( 'Recovery phrase and new password are required.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		if ( strlen( $new_password ) < 12 ) {
			return new \WP_REST_Response(
				[ 'code' => 'password_too_short', 'message' => __( 'New vault password must be at least 12 characters.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		if ( ! Fotonic_Vault::is_setup() ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_setup', 'message' => __( 'Vault is not set up.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		$ok = Fotonic_Vault::recover_reset_password_via_phrase( $recovery_phrase, $new_password );
		if ( ! $ok ) {
			set_transient( $fail_key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
			self::audit_log( 'vault_recovery_phrase_reset_fail' );
			return new \WP_REST_Response(
				[ 'code' => 'invalid_recovery_phrase', 'message' => __( 'Invalid recovery phrase.', 'eleva-crm-for-photographers' ) ],
				401
			);
		}

		delete_transient( $fail_key );
		self::audit_log( 'vault_recovery_phrase_reset' );

		return new \WP_REST_Response( [ 'reset' => true ], 200 );
	}

	/**
	 * POST /vault/reset
	 * Completely delete the vault and all its options.
	 * WARNING: all PII encrypted under the Master Key becomes unrecoverable.
	 */
	public static function vault_reset( \WP_REST_Request $_req ): \WP_REST_Response {
		$ok = Fotonic_Vault::reset_vault();
		self::audit_log( 'vault_reset' );

		if ( ! $ok ) {
			return new \WP_REST_Response(
				[ 'code' => 'reset_failed', 'message' => __( 'Vault reset failed.', 'eleva-crm-for-photographers' ) ],
				500
			);
		}

		return new \WP_REST_Response( [ 'reset' => true ], 200 );
	}

	/**
	 * Re-encrypt all customer PII postmeta with a new key.
	 * Public so Fotonic_Vault::migrate_legacy() can call it.
	 *
	 * @param string $old_key 32-byte old derived key.
	 * @param string $new_key 32-byte new derived key.
	 */
	public static function reencrypt_customers( string $old_key, string $new_key ): void {
		$posts = get_posts( [
			'post_type'      => 'ftnc_customer',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
		] );

		foreach ( $posts as $post_id ) {
			$raw = get_post_meta( $post_id, '_ftnc_people', true );
			if ( empty( $raw ) ) {
				continue;
			}
			$people = json_decode( $raw, true );
			if ( ! is_array( $people ) ) {
				continue;
			}

			$changed = false;
			foreach ( $people as &$person ) {
				foreach ( [ 'first_name', 'last_name', 'nationality', 'instagram_username', 'address', 'tin' ] as $field ) {
					if ( ! empty( $person[ $field ] ) && self::looks_encrypted( $person[ $field ] ) ) {
						$plain = Fotonic_Crypto::decrypt( $person[ $field ], $old_key );
						if ( '' !== $plain ) {
							$person[ $field ] = Fotonic_Crypto::encrypt( $plain, $new_key );
							$changed          = true;
						}
					}
				}
				foreach ( [ 'email', 'phone' ] as $field ) {
					if ( ! empty( $person[ $field ] ) && self::looks_encrypted( $person[ $field ] ) ) {
						$plain = Fotonic_Crypto::deterministic_decrypt( $person[ $field ], $old_key );
						if ( '' !== $plain ) {
							$person[ $field ] = Fotonic_Crypto::deterministic_encrypt( $plain, $new_key );
							$changed          = true;
						}
					}
				}
			}
			unset( $person );

			if ( $changed ) {
				update_post_meta( $post_id, '_ftnc_people', wp_json_encode( $people ) );
			}
		}
	}

	/**
	 * Re-encrypt all work event address postmeta with a new key.
	 * Public so Fotonic_Vault::migrate_legacy() can call it.
	 *
	 * @param string $old_key 32-byte old derived key.
	 * @param string $new_key 32-byte new derived key.
	 */
	public static function reencrypt_works( string $old_key, string $new_key ): void {
		$posts = get_posts( [
			'post_type'      => 'ftnc_work',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [ [ 'key' => '_ftnc_event_addresses', 'compare' => 'EXISTS' ] ], // phpcs:ignore WordPress.DB.SlowDBQuery
		] );

		foreach ( $posts as $post_id ) {
			$raw = get_post_meta( $post_id, '_ftnc_event_addresses', true );
			if ( empty( $raw ) ) {
				continue;
			}
			$addresses = json_decode( $raw, true );
			if ( ! is_array( $addresses ) ) {
				continue;
			}

			$changed = false;
			foreach ( $addresses as &$addr ) {
				$street = $addr['street'] ?? '';
				if ( self::looks_encrypted( $street ) ) {
					$plain = Fotonic_Crypto::decrypt( $street, $old_key );
					if ( '' !== $plain ) {
						$addr['street'] = Fotonic_Crypto::encrypt( $plain, $new_key );
						$changed        = true;
					}
				}
			}
			unset( $addr );

			if ( $changed ) {
				update_post_meta( $post_id, '_ftnc_event_addresses', wp_json_encode( $addresses ) );
			}
		}
	}

	// ---------------------------------------------------------------------------
	// Customers — READ
	// ---------------------------------------------------------------------------

	public static function get_customers( \WP_REST_Request $req ): \WP_REST_Response {
		$search   = (string) $req->get_param( 'search' );
		$page     = (int) $req->get_param( 'page' );
		$per_page = (int) $req->get_param( 'per_page' );

		$args = [
			'post_type'      => 'ftnc_customer',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query = new \WP_Query( $args );
		$data  = [];

		foreach ( $query->posts as $post ) {
			$data[] = self::format_customer( $post );
		}

		return new \WP_REST_Response( [
			'data'  => $data,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		], 200 );
	}

	public static function get_customer( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_customer' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Customer not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		return new \WP_REST_Response( self::format_customer( $post ), 200 );
	}

	// ---------------------------------------------------------------------------
	// Customers — WRITE
	// ---------------------------------------------------------------------------

	public static function create_customer( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $req->get_json_params();

		$title = isset( $body['title'] ) ? sanitize_text_field( $body['title'] ) : '';
		if ( empty( $title ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_title', 'message' => __( 'Title is required.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		$post_id = wp_insert_post( [
			'post_type'   => 'ftnc_customer',
			'post_status' => 'publish',
			'post_title'  => $title,
		], true );

		if ( is_wp_error( $post_id ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'insert_failed', 'message' => $post_id->get_error_message() ],
				500
			);
		}

		self::save_customer_meta( $post_id, $body );

		return new \WP_REST_Response( self::format_customer( get_post( $post_id ) ), 201 );
	}

	public static function update_customer( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_customer' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Customer not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		$body = $req->get_json_params();

		if ( isset( $body['title'] ) ) {
			$title = sanitize_text_field( $body['title'] );
			if ( ! empty( $title ) ) {
				wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
			}
		}

		self::save_customer_meta( $id, $body );

		return new \WP_REST_Response( self::format_customer( get_post( $id ) ), 200 );
	}

	public static function delete_customer( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_customer' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Customer not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		wp_trash_post( $id );

		return new \WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	// ---------------------------------------------------------------------------
	// Services — READ
	// ---------------------------------------------------------------------------

	public static function get_services( \WP_REST_Request $req ): \WP_REST_Response {
		$query = new \WP_Query( [
			'post_type'      => 'ftnc_service',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		$data = [];
		foreach ( $query->posts as $post ) {
			$data[] = self::format_service( $post );
		}

		return new \WP_REST_Response( [
			'data'  => $data,
			'total' => count( $data ),
			'pages' => 1,
		], 200 );
	}

	public static function get_service( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_service' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Service not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		return new \WP_REST_Response( self::format_service( $post ), 200 );
	}

	// ---------------------------------------------------------------------------
	// Services — WRITE
	// ---------------------------------------------------------------------------

	public static function create_service( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $req->get_json_params();

		$title = isset( $body['title'] ) ? sanitize_text_field( $body['title'] ) : '';
		if ( empty( $title ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_title', 'message' => __( 'Title is required.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		$post_id = wp_insert_post( [
			'post_type'   => 'ftnc_service',
			'post_status' => 'publish',
			'post_title'  => $title,
		], true );

		if ( is_wp_error( $post_id ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'insert_failed', 'message' => $post_id->get_error_message() ],
				500
			);
		}

		self::save_service_meta( $post_id, $body );

		return new \WP_REST_Response( self::format_service( get_post( $post_id ) ), 201 );
	}

	public static function update_service( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_service' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Service not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		$body = $req->get_json_params();

		if ( isset( $body['title'] ) ) {
			$title = sanitize_text_field( $body['title'] );
			if ( ! empty( $title ) ) {
				wp_update_post( [ 'ID' => $id, 'post_title' => $title ] );
			}
		}

		self::save_service_meta( $id, $body );

		return new \WP_REST_Response( self::format_service( get_post( $id ) ), 200 );
	}

	public static function delete_service( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_service' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Service not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		wp_trash_post( $id );

		return new \WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	// ---------------------------------------------------------------------------
	// Works — READ
	// ---------------------------------------------------------------------------

	public static function get_works( \WP_REST_Request $req ): \WP_REST_Response {
		$search         = (string) $req->get_param( 'search' );
		$page           = (int) $req->get_param( 'page' );
		$per_page       = (int) $req->get_param( 'per_page' );
		$payment_status = (string) $req->get_param( 'payment_status' );
		$customer_id    = (int) $req->get_param( 'customer_id' );

		$args = [
			'post_type'      => 'ftnc_work',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'meta_value',
			'meta_key'       => '_ftnc_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta key indexed; necessary for postmeta-based filtering.
			'order'          => 'ASC',
		];

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		if ( $customer_id > 0 ) {
			$args['meta_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'   => '_ftnc_customer_id',
					'value' => $customer_id,
					'type'  => 'NUMERIC',
				],
			];
		}

		if ( ! empty( $payment_status ) ) {
			$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				[
					'taxonomy' => 'ftnc_work_payment_status',
					'field'    => 'slug',
					'terms'    => $payment_status,
				],
			];
		}

		$query = new \WP_Query( $args );
		$data  = [];

		foreach ( $query->posts as $post ) {
			$data[] = self::format_work( $post );
		}

		return new \WP_REST_Response( [
			'data'  => $data,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		], 200 );
	}

	public static function get_work( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_work' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Work not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		return new \WP_REST_Response( self::format_work( $post ), 200 );
	}

	// ---------------------------------------------------------------------------
	// Works — WRITE
	// ---------------------------------------------------------------------------

	public static function create_work( \WP_REST_Request $req ): \WP_REST_Response {
		$body = $req->get_json_params();

		$title = isset( $body['title'] ) ? sanitize_text_field( $body['title'] ) : '';
		if ( empty( $title ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_title', 'message' => __( 'Title is required.', 'eleva-crm-for-photographers' ) ],
				400
			);
		}

		$post_id = wp_insert_post( [
			'post_type'    => 'ftnc_work',
			'post_status'  => 'publish',
			'post_title'   => $title,
			'post_content' => isset( $body['notes'] ) ? wp_kses_post( $body['notes'] ) : '',
		], true );

		if ( is_wp_error( $post_id ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'insert_failed', 'message' => $post_id->get_error_message() ],
				500
			);
		}

		self::save_work_meta( $post_id, $body );
		Fotonic_Meta_Boxes::auto_assign_payment_status( $post_id );

		/**
		 * Fires after a work is created and its core meta saved.
		 * Pro plugin uses this to handle owner (collaborator) and collaborators list.
		 *
		 * @param int   $post_id Work post ID.
		 * @param array $body    Decoded request body array.
		 */
		do_action( 'ftnc_after_save_work', $post_id, $body );

		return new \WP_REST_Response( self::format_work( get_post( $post_id ) ), 201 );
	}

	public static function update_work( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_work' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Work not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		$body = $req->get_json_params();

		$update = [ 'ID' => $id ];
		if ( isset( $body['title'] ) ) {
			$title = sanitize_text_field( $body['title'] );
			if ( ! empty( $title ) ) {
				$update['post_title'] = $title;
			}
		}
		if ( isset( $body['notes'] ) ) {
			$update['post_content'] = wp_kses_post( $body['notes'] );
		}
		if ( count( $update ) > 1 ) {
			wp_update_post( $update );
		}

		// Capture previous card list before save_work_meta overwrites it (for removed-card detection in sync).
		$prev_memory_cards = get_post_meta( $id, '_ftnc_memory_cards', true );

		self::save_work_meta( $id, $body );
		Fotonic_Meta_Boxes::auto_assign_payment_status( $id );

		/**
		 * Fires after a work is updated and its core meta saved.
		 * Pro plugin uses this to handle owner (collaborator) and collaborators list.
		 *
		 * @param int   $id   Work post ID.
		 * @param array $body Decoded request body array.
		 */
		do_action( 'ftnc_after_save_work', $id, array_merge( $body, [ '_prev_memory_cards' => $prev_memory_cards ] ) );

		return new \WP_REST_Response( self::format_work( get_post( $id ) ), 200 );
	}

	public static function delete_work( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_work' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Work not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		// Free any memory cards still linked to this work.
		if ( class_exists( 'Fotonic_Memory_Card_CPT' ) ) {
			$raw = get_post_meta( $id, '_ftnc_memory_cards', true );
			if ( ! empty( $raw ) ) {
				$mc_list = json_decode( $raw, true );
				if ( is_array( $mc_list ) ) {
					foreach ( $mc_list as $mc ) {
						$card_id = (int) ( $mc['card_id'] ?? 0 );
						if ( $card_id > 0 ) {
							Fotonic_Memory_Card_CPT::set_card_status( $card_id, 'free' );
						}
					}
				}
			}
		}

		wp_trash_post( $id );

		return new \WP_REST_Response( [ 'deleted' => true, 'id' => $id ], 200 );
	}

	// ---------------------------------------------------------------------------
	// Can-delete checks
	// ---------------------------------------------------------------------------

	public static function can_delete_customer( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_customer' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Customer not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta key indexed; necessary for postmeta-based filtering.
		$linked = get_posts( [
			'post_type'      => 'ftnc_work',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_key'       => '_ftnc_customer_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'meta_value'     => $id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			'fields'         => 'ids',
		] );

		if ( ! empty( $linked ) ) {
			$titles = array_map( 'get_the_title', $linked );
			$count  = count( $linked );
			return new \WP_REST_Response( [
				'can_delete' => false,
				'reason'     => sprintf(
					/* translators: 1: number of linked works, 2: comma-separated work titles */
					_n( 'Linked to %1$d work: %2$s', 'Linked to %1$d works: %2$s', $count, 'eleva-crm-for-photographers' ),
					$count,
					implode( ', ', $titles )
				),
			], 200 );
		}

		return new \WP_REST_Response( [ 'can_delete' => true ], 200 );
	}

	public static function can_delete_service( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_service' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Service not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta key indexed; necessary for postmeta-based filtering.
		$candidate_works = get_posts( [
			'post_type'      => 'ftnc_work',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [ [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'key'     => '_ftnc_work_services',
				'compare' => 'EXISTS',
			] ],
		] );

		$linked_titles = [];
		foreach ( $candidate_works as $work_id ) {
			$raw      = get_post_meta( $work_id, '_ftnc_work_services', true );
			$services = json_decode( $raw, true );
			if ( ! is_array( $services ) ) {
				continue;
			}
			foreach ( $services as $svc ) {
				if ( isset( $svc['service_id'] ) && (int) $svc['service_id'] === $id ) {
					$linked_titles[] = get_the_title( $work_id );
					break;
				}
			}
		}

		if ( ! empty( $linked_titles ) ) {
			$count = count( $linked_titles );
			return new \WP_REST_Response( [
				'can_delete' => false,
				'reason'     => sprintf(
					/* translators: 1: number of linked works, 2: comma-separated work titles */
					_n( 'Linked to %1$d work: %2$s', 'Linked to %1$d works: %2$s', $count, 'eleva-crm-for-photographers' ),
					$count,
					implode( ', ', $linked_titles )
				),
			], 200 );
		}

		return new \WP_REST_Response( [ 'can_delete' => true ], 200 );
	}

	public static function can_delete_work( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_work' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Work not found.', 'eleva-crm-for-photographers' ) ],
				404
			);
		}

		return new \WP_REST_Response( [ 'can_delete' => true ], 200 );
	}

	/**
	 * GET /calendar?from=YYYY-MM-DD&to=YYYY-MM-DD
	 * Returns ftnc_work posts with event_date in range for the calendar view.
	 */
	public static function get_calendar_works( \WP_REST_Request $req ): \WP_REST_Response {
		$from = sanitize_text_field( $req->get_param( 'from' ) );
		$to   = sanitize_text_field( $req->get_param( 'to' ) );

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta key indexed; necessary for postmeta-based filtering.
		$query = new \WP_Query( [
			'post_type'      => 'ftnc_work',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => '_ftnc_event_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta key indexed; necessary for postmeta-based filtering.
			'order'          => 'ASC',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta key indexed; necessary for postmeta-based filtering.
				[
					'key'     => '_ftnc_event_date',
					'value'   => [ $from, $to ],
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				],
			],
		] );

		$events = [];
		foreach ( $query->posts as $post ) {
			$customer_id    = (int) get_post_meta( $post->ID, '_ftnc_customer_id', true );
			$customer_title = $customer_id > 0 ? get_the_title( $customer_id ) : null;
			$ps_terms       = wp_get_object_terms( $post->ID, 'ftnc_work_payment_status', [ 'fields' => 'slugs' ] );
			$payment_status = ( ! is_wp_error( $ps_terms ) && ! empty( $ps_terms ) ) ? $ps_terms[0] : 'unpaid';
			$events[]       = [
				'type'            => 'work',
				'id'              => $post->ID,
				'title'           => $post->post_title,
				'event_date'      => (string) get_post_meta( $post->ID, '_ftnc_event_date', true ),
				'event_time_from' => (string) get_post_meta( $post->ID, '_ftnc_event_time_from', true ),
				'event_time_to'   => (string) get_post_meta( $post->ID, '_ftnc_event_time_to', true ),
				'customer_title'  => $customer_title,
				'payment_status'  => $payment_status,
				'kanban_status'   => (string) get_post_meta( $post->ID, '_ftnc_kanban_status', true ),
				'color'           => (string) get_post_meta( $post->ID, '_ftnc_color', true ),
				'gcal_event_id'   => (string) get_post_meta( $post->ID, '_ftnc_gcal_event_id', true ),
				'gcal_entry_type' => null,
			];
		}

		return new \WP_REST_Response( $events, 200 );
	}

	// ---------------------------------------------------------------------------
	// Meta helpers
	// ---------------------------------------------------------------------------

	/**
	 * Save customer people meta from API body.
	 * Encrypts PII fields when the vault is unlocked.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $body    Request body.
	 */
	private static function save_customer_meta( int $post_id, array $body ): void {
		if ( ! isset( $body['people'] ) || ! is_array( $body['people'] ) ) {
			return;
		}

		$vault_key = Fotonic_Vault::get_session_key();
		$encrypt   = $vault_key !== null;

		$sanitized = [];
		$has_main  = false;

		foreach ( $body['people'] as $person ) {
			if ( ! is_array( $person ) ) {
				continue;
			}

			$is_main  = ! empty( $person['is_main'] ) && ! $has_main;
			$has_main = $has_main || $is_main;

			$first_name         = sanitize_text_field( $person['first_name'] ?? '' );
			$last_name          = sanitize_text_field( $person['last_name'] ?? '' );
			$email              = sanitize_email( $person['email'] ?? '' );
			$phone              = sanitize_text_field( $person['phone'] ?? '' );
			$nationality        = sanitize_text_field( $person['nationality'] ?? '' );
			$instagram_username = sanitize_text_field( $person['instagram_username'] ?? '' );
			$address            = sanitize_text_field( $person['address'] ?? '' );
			$tin                = sanitize_text_field( $person['tin'] ?? '' );

			if ( $encrypt ) {
				// Random-IV encryption for free-text PII. Skip empty strings — encrypting
				// empty produces a short ciphertext that bypasses looks_encrypted() checks.
				if ( '' !== $first_name )         { $first_name         = Fotonic_Crypto::encrypt( $first_name,         $vault_key ); }
				if ( '' !== $last_name )          { $last_name          = Fotonic_Crypto::encrypt( $last_name,          $vault_key ); }
				if ( '' !== $nationality )        { $nationality        = Fotonic_Crypto::encrypt( $nationality,        $vault_key ); }
				if ( '' !== $instagram_username ) { $instagram_username = Fotonic_Crypto::encrypt( $instagram_username, $vault_key ); }
				if ( '' !== $address )            { $address            = Fotonic_Crypto::encrypt( $address,            $vault_key ); }
				if ( '' !== $tin )                { $tin                = Fotonic_Crypto::encrypt( $tin,                $vault_key ); }
				// Deterministic encryption for searchable fields.
				if ( '' !== $email ) { $email = Fotonic_Crypto::deterministic_encrypt( $email, $vault_key ); }
				if ( '' !== $phone ) { $phone = Fotonic_Crypto::deterministic_encrypt( $phone, $vault_key ); }
			}

			$sanitized[] = [
				'first_name'         => $first_name,
				'last_name'          => $last_name,
				'email'              => $email,
				'phone'              => $phone,
				'nationality'        => $nationality,
				'instagram_username' => $instagram_username,
				'address'            => $address,
				'tin'                => $tin,
				'is_main'            => (bool) $is_main,
			];
		}

		if ( ! empty( $sanitized ) && ! $has_main ) {
			$sanitized[0]['is_main'] = true;
		}

		update_post_meta( $post_id, '_ftnc_people', wp_json_encode( $sanitized ) );
	}

	/**
	 * Save service meta from API body.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $body    Request body.
	 */
	private static function save_service_meta( int $post_id, array $body ): void {
		if ( isset( $body['base_price'] ) ) {
			$price = (float) $body['base_price'];
			update_post_meta( $post_id, '_ftnc_base_price', $price >= 0 ? $price : 0 );
		}
		if ( isset( $body['notes'] ) ) {
			update_post_meta( $post_id, '_ftnc_notes', wp_kses_post( $body['notes'] ) );
		}
	}

	/**
	 * Save work meta from API body.
	 * Encrypts PII fields (event_address) when the vault is unlocked.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $body    Request body.
	 */
	private static function save_work_meta( int $post_id, array $body ): void {
		$vault_key = Fotonic_Vault::get_session_key();
		$encrypt   = $vault_key !== null;

		// Event details.
		if ( isset( $body['event_date'] ) ) {
			$date = sanitize_text_field( $body['event_date'] );
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				update_post_meta( $post_id, '_ftnc_event_date', $date );
			} else {
				delete_post_meta( $post_id, '_ftnc_event_date' );
			}
		}

		if ( isset( $body['event_time_from'] ) ) {
			$time = sanitize_text_field( $body['event_time_from'] );
			if ( preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
				update_post_meta( $post_id, '_ftnc_event_time_from', $time );
			} else {
				delete_post_meta( $post_id, '_ftnc_event_time_from' );
			}
		}

		if ( isset( $body['event_time_to'] ) ) {
			$time = sanitize_text_field( $body['event_time_to'] );
			if ( preg_match( '/^\d{2}:\d{2}$/', $time ) ) {
				update_post_meta( $post_id, '_ftnc_event_time_to', $time );
			} else {
				delete_post_meta( $post_id, '_ftnc_event_time_to' );
			}
		}

		if ( isset( $body['event_addresses'] ) && is_array( $body['event_addresses'] ) ) {
			$clean_addresses = [];
			foreach ( $body['event_addresses'] as $addr ) {
				if ( ! is_array( $addr ) ) {
					continue;
				}
				$label  = sanitize_text_field( $addr['label'] ?? '' );
				$street = sanitize_text_field( $addr['street'] ?? '' );
				if ( $encrypt && $street !== '' ) {
					$street = Fotonic_Crypto::encrypt( $street, $vault_key );
				}
				$clean_addresses[] = [
					'label'  => $label,
					'street' => $street,
				];
			}
			update_post_meta( $post_id, '_ftnc_event_addresses', wp_json_encode( $clean_addresses ) );
		}

		// Customer.
		if ( isset( $body['customer_id'] ) ) {
			$cid = (int) $body['customer_id'];
			if ( $cid > 0 && get_post_type( $cid ) === 'ftnc_customer' ) {
				update_post_meta( $post_id, '_ftnc_customer_id', $cid );
			} else {
				delete_post_meta( $post_id, '_ftnc_customer_id' );
			}
		}

		// Owner — free plugin only sets the default admin owner when no owner is stored yet.
		// Pro plugin handles collaborator owner and collaborators list via ftnc_after_save_work hook.
		if ( ! get_post_meta( $post_id, '_ftnc_owner_type', true ) ) {
			update_post_meta( $post_id, '_ftnc_owner_type', 'admin' );
			update_post_meta( $post_id, '_ftnc_owner_id', get_current_user_id() );
		}

		// Services.
		if ( isset( $body['services'] ) && is_array( $body['services'] ) ) {
			$clean = [];
			foreach ( $body['services'] as $svc ) {
				if ( ! is_array( $svc ) ) {
					continue;
				}
				$clean[] = [
					'service_id'     => (int) ( $svc['service_id'] ?? 0 ),
					'price_override' => max( 0.0, (float) ( $svc['price_override'] ?? 0 ) ),
					'notes_override' => sanitize_text_field( $svc['notes_override'] ?? '' ),
				];
			}
			update_post_meta( $post_id, '_ftnc_work_services', wp_json_encode( $clean ) );
		}

		// Files.
		if ( isset( $body['files'] ) && is_array( $body['files'] ) ) {
			// Body may send full objects or plain IDs.
			$ids = [];
			foreach ( $body['files'] as $f ) {
				if ( is_numeric( $f ) ) {
					$ids[] = (int) $f;
				} elseif ( is_array( $f ) && isset( $f['id'] ) ) {
					$ids[] = (int) $f['id'];
				}
			}
			update_post_meta( $post_id, '_ftnc_work_files', wp_json_encode( array_values( array_filter( $ids ) ) ) );
		}

		// Payments.
		if ( isset( $body['total_price'] ) ) {
			$price = (float) $body['total_price'];
			update_post_meta( $post_id, '_ftnc_total_price', $price >= 0 ? $price : 0 );
		}

		if ( isset( $body['installments'] )&& is_array( $body['installments'] ) ) {
			$clean = [];
			foreach ( $body['installments'] as $inst ) {
				if ( ! is_array( $inst ) ) {
					continue;
				}
				$status      = ( isset( $inst['status'] ) && $inst['status'] === 'paid' ) ? 'paid' : 'unpaid';
				$valid_slugs = self::get_valid_payment_type_slugs();
				$fallback    = ! empty( $valid_slugs ) ? $valid_slugs[0] : 'default';
				$type        = ( isset( $inst['type'] ) && in_array( $inst['type'], $valid_slugs, true ) ) ? $inst['type'] : $fallback;
				$raw_date    = sanitize_text_field( $inst['date'] ?? '' );
				$clean[]     = [
					'title'  => sanitize_text_field( $inst['title'] ?? '' ),
					'amount' => max( 0.0, (float) ( $inst['amount'] ?? 0 ) ),
					'status' => $status,
					'type'   => $type,
					'date'   => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw_date ) ? $raw_date : '',
				];
			}
			update_post_meta( $post_id, '_ftnc_installments', wp_json_encode( $clean ) );
		}

		// Calendar color.
		if ( array_key_exists( 'color', $body ) ) {
			$color = sanitize_hex_color( (string) ( $body['color'] ?? '' ) );
			if ( $color ) {
				update_post_meta( $post_id, '_ftnc_color', $color );
			} else {
				delete_post_meta( $post_id, '_ftnc_color' );
			}
		}

		// Quick notes.
		if ( isset( $body['quick_notes'] ) ) {
			update_post_meta( $post_id, '_ftnc_quick_notes', wp_kses_post( $body['quick_notes'] ) );
		}

		// Memory cards.
		if ( isset( $body['memory_cards'] ) && is_array( $body['memory_cards'] ) ) {
			$clean_cards = [];
			foreach ( $body['memory_cards'] as $mc ) {
				if ( ! is_array( $mc ) ) {
					continue;
				}
				$card_id = (int) ( $mc['card_id'] ?? 0 );
				if ( $card_id <= 0 || get_post_type( $card_id ) !== 'ftnc_memory_card' ) {
					continue;
				}
				$clean_cards[] = [
					'card_id' => $card_id,
					'notes'   => sanitize_text_field( $mc['notes'] ?? '' ),
				];
			}
			update_post_meta( $post_id, '_ftnc_memory_cards', wp_json_encode( $clean_cards ) );
		}

		// Backup / formatting / ready-for-next flags.
		if ( array_key_exists( 'backup_done', $body ) ) {
			update_post_meta( $post_id, '_ftnc_backup_done', (bool) $body['backup_done'] ? '1' : '0' );
		}
		if ( array_key_exists( 'formatting_done', $body ) ) {
			update_post_meta( $post_id, '_ftnc_formatting_done', (bool) $body['formatting_done'] ? '1' : '0' );
		}
	}

	// ---------------------------------------------------------------------------
	// Formatters
	// ---------------------------------------------------------------------------

	/**
	 * Format a customer post as the API response shape.
	 * Decrypts PII fields when the vault is unlocked; returns null for
	 * encrypted fields when locked (graceful degradation).
	 *
	 * @param WP_Post $post Customer post.
	 * @return array
	 */
	private static function format_customer( \WP_Post $post ): array {
		$raw    = get_post_meta( $post->ID, '_ftnc_people', true );
		$people = [];
		if ( ! empty( $raw ) ) {
			$dec = json_decode( $raw, true );
			if ( is_array( $dec ) ) {
				$people = $dec;
			}
		}

		$vault_key = Fotonic_Vault::get_session_key();

		if ( $vault_key !== null ) {
			// Vault unlocked: decrypt each person's PII fields.
			$people = array_map( function ( $person ) use ( $vault_key ) {
				$person['first_name']         = self::maybe_decrypt( $person['first_name']         ?? '', $vault_key );
				$person['last_name']          = self::maybe_decrypt( $person['last_name']          ?? '', $vault_key );
				$person['nationality']        = self::maybe_decrypt( $person['nationality']        ?? '', $vault_key );
				$person['instagram_username'] = self::maybe_decrypt( $person['instagram_username'] ?? '', $vault_key );
				$person['address']            = self::maybe_decrypt( $person['address']            ?? '', $vault_key );
				$person['tin']                = self::maybe_decrypt( $person['tin']                ?? '', $vault_key );
				// Deterministic-encrypted fields use the same decrypt function.
				$person['email'] = self::maybe_decrypt( $person['email'] ?? '', $vault_key );
				$person['phone'] = self::maybe_decrypt( $person['phone'] ?? '', $vault_key );
				return $person;
			}, $people );
		} else {
			// Vault locked: return null for encrypted fields.
			$people = array_map( function ( $person ) {
				if ( self::looks_encrypted( $person['first_name'] ?? '' ) ) {
					$person['first_name'] = null;
				}
				if ( self::looks_encrypted( $person['last_name'] ?? '' ) ) {
					$person['last_name'] = null;
				}
				if ( self::looks_encrypted( $person['nationality'] ?? '' ) ) {
					$person['nationality'] = null;
				}
				if ( self::looks_encrypted( $person['instagram_username'] ?? '' ) ) {
					$person['instagram_username'] = null;
				}
				if ( self::looks_encrypted( $person['address'] ?? '' ) ) {
					$person['address'] = null;
				}
				if ( self::looks_encrypted( $person['tin'] ?? '' ) ) {
					$person['tin'] = null;
				}
				if ( self::looks_encrypted( $person['email'] ?? '' ) ) {
					$person['email'] = null;
				}
				if ( self::looks_encrypted( $person['phone'] ?? '' ) ) {
					$person['phone'] = null;
				}
				return $person;
			}, $people );
		}

		return [
			'id'     => $post->ID,
			'title'  => $post->post_title,
			'people' => $people,
		];
	}

	/**
	 * Format a service post as the API response shape.
	 *
	 * @param WP_Post $post Service post.
	 * @return array
	 */
	private static function format_service( \WP_Post $post ): array {
		return [
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'base_price' => (float) get_post_meta( $post->ID, '_ftnc_base_price', true ),
			'notes'      => (string) get_post_meta( $post->ID, '_ftnc_notes', true ),
		];
	}

	/**
	 * Format a work post as the full API response shape.
	 *
	 * @param WP_Post $post Work post.
	 * @return array
	 */
	private static function format_work( \WP_Post $post ): array {
		$customer_id    = (int) get_post_meta( $post->ID, '_ftnc_customer_id', true );
		$customer_title = '';
		if ( $customer_id ) {
			$cust = get_post( $customer_id );
			if ( $cust ) {
				$customer_title = $cust->post_title;
			}
		}

		// Services.
		$raw_services = get_post_meta( $post->ID, '_ftnc_work_services', true );
		$services     = [];
		if ( ! empty( $raw_services ) ) {
			$dec = json_decode( $raw_services, true );
			if ( is_array( $dec ) ) {
				foreach ( $dec as $svc ) {
					$svc_post = get_post( $svc['service_id'] ?? 0 );
					$services[] = [
						'service_id'    => (int) ( $svc['service_id'] ?? 0 ),
						'service_title' => $svc_post ? $svc_post->post_title : '',
						'price_override' => max( 0.0, (float) ( $svc['price_override'] ?? 0 ) ),
						'notes_override' => (string) ( $svc['notes_override'] ?? '' ),
					];
				}
			}
		}

		// Files.
		$raw_files = get_post_meta( $post->ID, '_ftnc_work_files', true );
		$files     = [];
		if ( ! empty( $raw_files ) ) {
			$dec = json_decode( $raw_files, true );
			if ( is_array( $dec ) ) {
				foreach ( $dec as $attach_id ) {
					$attach_id  = (int) $attach_id;
					$url        = wp_get_attachment_url( $attach_id );
					$metadata   = wp_get_attachment_metadata( $attach_id );
					$post_attach = get_post( $attach_id );
					if ( ! $url || ! $post_attach ) {
						continue;
					}
					$files[] = [
						'id'       => $attach_id,
						'url'      => $url,
						'filename' => basename( get_attached_file( $attach_id ) ),
						'mime'     => $post_attach->post_mime_type,
					];
				}
			}
		}

		// Installments.
		$raw_inst     = get_post_meta( $post->ID, '_ftnc_installments', true );
		$installments = [];
		if ( ! empty( $raw_inst ) ) {
			$dec = json_decode( $raw_inst, true );
			if ( is_array( $dec ) ) {
				foreach ( $dec as $inst ) {
					if ( ! is_array( $inst ) ) {
						continue;
					}
					$installments[] = [
						'title'  => (string) ( $inst['title'] ?? '' ),
						'amount' => max( 0.0, (float) ( $inst['amount'] ?? 0 ) ),
						'status' => ( isset( $inst['status'] ) && $inst['status'] === 'paid' ) ? 'paid' : 'unpaid',
						'type'   => (string) ( $inst['type'] ?? 'default' ),
						'date'   => (string) ( $inst['date'] ?? '' ),
					];
				}
			}
		}

		// Payment status term.
		$terms          = wp_get_object_terms( $post->ID, 'ftnc_work_payment_status', [ 'fields' => 'slugs' ] );
		$payment_status = ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : 'unpaid';

		// Decrypt vault-protected fields.
		$vault_key           = Fotonic_Vault::get_session_key();
		$notes               = $post->post_content;
		$raw_event_addresses = get_post_meta( $post->ID, '_ftnc_event_addresses', true );
		$event_addresses     = [];
		if ( ! empty( $raw_event_addresses ) ) {
			$dec_addrs = json_decode( $raw_event_addresses, true );
			if ( is_array( $dec_addrs ) ) {
				foreach ( $dec_addrs as $addr ) {
					if ( ! is_array( $addr ) ) {
						continue;
					}
					$street = (string) ( $addr['street'] ?? '' );
					if ( $vault_key !== null ) {
						$street = self::maybe_decrypt( $street, $vault_key );
					} else {
						if ( self::looks_encrypted( $street ) ) {
							$street = null;
						}
					}
					$event_addresses[] = [
						'label'  => (string) ( $addr['label'] ?? '' ),
						'street' => $street,
					];
				}
			}
		}

		// Owner.
		$owner_type  = (string) get_post_meta( $post->ID, '_ftnc_owner_type', true ) ?: 'admin';
		$owner_id    = (int) get_post_meta( $post->ID, '_ftnc_owner_id', true );
		$owner_label = '';
		if ( 'collaborator' === $owner_type && $owner_id ) {
			$owner_post  = get_post( $owner_id );
			$owner_label = $owner_post ? $owner_post->post_title : '';
		} else {
			$owner_user  = $owner_id ? get_user_by( 'id', $owner_id ) : null;
			if ( $owner_user ) {
				$owner_label = $owner_user->display_name ?: $owner_user->user_login;
			}
		}

		// Collaborators.
		$raw_collabs      = get_post_meta( $post->ID, '_ftnc_collaborators', true );
		$collaborators    = [];
		if ( ! empty( $raw_collabs ) ) {
			$dec = json_decode( $raw_collabs, true );
			if ( is_array( $dec ) ) {
				foreach ( $dec as $c ) {
					if ( ! is_array( $c ) ) {
						continue;
					}
					$c_name = '';
					if ( 'collaborator' === ( $c['type'] ?? '' ) ) {
						$c_post = get_post( (int) ( $c['id'] ?? 0 ) );
						$c_name = $c_post ? $c_post->post_title : '';
					} else {
						$c_user = get_user_by( 'id', (int) ( $c['id'] ?? 0 ) );
						$c_name = $c_user ? ( $c_user->display_name ?: $c_user->user_login ) : '';
					}
					$c_service_ids = isset( $c['services'] ) ? (array) $c['services'] : [];
						$c_services    = [];
						foreach ( $c_service_ids as $term_id ) {
							$term = get_term( (int) $term_id, 'ftnc_collaborator_service' );
							if ( $term && ! is_wp_error( $term ) ) {
								$c_services[] = [ 'id' => $term->term_id, 'name' => $term->name ];
							}
						}
						$collaborators[] = [
							'type'     => (string) ( $c['type'] ?? 'collaborator' ),
							'id'       => (int) ( $c['id'] ?? 0 ),
							'name'     => $c_name,
							'services' => $c_services,
							'price'    => (float) ( $c['price'] ?? 0 ),
							'status'   => in_array( $c['status'] ?? '', [ 'paid', 'to_pay' ], true ) ? $c['status'] : 'to_pay',
						];
				}
			}
		}

		// Memory cards.
		$raw_mc       = get_post_meta( $post->ID, '_ftnc_memory_cards', true );
		$memory_cards = [];
		if ( ! empty( $raw_mc ) ) {
			$dec_mc = json_decode( $raw_mc, true );
			if ( is_array( $dec_mc ) ) {
				foreach ( $dec_mc as $mc ) {
					if ( ! is_array( $mc ) ) {
						continue;
					}
					$card_id   = (int) ( $mc['card_id'] ?? 0 );
					$card_post = $card_id ? get_post( $card_id ) : null;
					if ( ! $card_post || $card_post->post_status === 'trash' ) {
						continue;
					}
					$card_status = class_exists( 'Fotonic_Memory_Card_CPT' )
						? Fotonic_Memory_Card_CPT::get_card_status( $card_id )
						: '';
					$memory_cards[] = [
						'card_id'    => $card_id,
						'card_title' => $card_post->post_title,
						'status'     => $card_status,
						'notes'      => (string) ( $mc['notes'] ?? '' ),
					];
				}
			}
		}

		return [
			'id'              => $post->ID,
			'title'           => $post->post_title,
			'event_date'      => (string) get_post_meta( $post->ID, '_ftnc_event_date', true ),
			'event_time_from' => (string) get_post_meta( $post->ID, '_ftnc_event_time_from', true ),
			'event_time_to'   => (string) get_post_meta( $post->ID, '_ftnc_event_time_to', true ),
			'event_addresses' => $event_addresses,
			'customer_id'     => $customer_id,
			'customer_title'  => $customer_title,
			'owner_type'      => $owner_type,
			'owner_id'        => $owner_id,
			'owner_label'     => $owner_label,
			'collaborators'   => $collaborators,
			'services'        => $services,
			'files'           => $files,
			'total_price'         => (float) get_post_meta( $post->ID, '_ftnc_total_price', true ),
			'total_price_taxable' => self::format_taxable_price( $post->ID ),
			'installments'        => $installments,
			'payment_status'  => $payment_status,
			'notes'           => $notes,
			'quick_notes'     => (string) get_post_meta( $post->ID, '_ftnc_quick_notes', true ),
			'color'           => (string) get_post_meta( $post->ID, '_ftnc_color', true ),
			'memory_cards'    => $memory_cards,
			'backup_done'     => (bool) get_post_meta( $post->ID, '_ftnc_backup_done', true ),
			'formatting_done' => (bool) get_post_meta( $post->ID, '_ftnc_formatting_done', true ),
		];
	}

	/**
	 * Return the taxable price for a work as float, or null when unset.
	 */
	private static function format_taxable_price( int $post_id ) {
		$raw = get_post_meta( $post_id, '_ftnc_total_price_taxable', true );
		if ( '' === $raw || null === $raw ) {
			return null;
		}
		return (float) $raw;
	}

	// ---------------------------------------------------------------------------
	// Crypto helpers
	// ---------------------------------------------------------------------------

	/**
	 * Attempt to decrypt a value. If decryption fails (e.g. plain-text stored
	 * before vault was set up), return the original value as-is.
	 *
	 * @param string $value     Possibly-encrypted value.
	 * @param string $vault_key 32-byte AES key.
	 * @return string Decrypted value, or original if not encrypted.
	 */
	private static function maybe_decrypt( string $value, string $vault_key ): string {
		if ( ! self::looks_encrypted( $value ) ) {
			return $value;
		}
		// v1d: prefix = deterministic CBC (email/phone searchable fields).
		if ( strncmp( $value, 'v1d:', 4 ) === 0 ) {
			return Fotonic_Crypto::deterministic_decrypt( $value, $vault_key );
		}
		$decrypted = Fotonic_Crypto::decrypt( $value, $vault_key );
		if ( '' === $decrypted && '' !== $value ) {
			return '';
		}
		return $decrypted;
	}

	/**
	 * Heuristic: does this string look like a base64-encoded AES ciphertext blob?
	 * Used to avoid treating plaintext legacy data as encrypted.
	 *
	 * @param string $value Value to test.
	 * @return bool
	 */
	// ---------------------------------------------------------------------------
	// Payment Types
	// ---------------------------------------------------------------------------

	/**
	 * @return array<int, array{id: int, slug: string, label: string}>
	 */
	private static function get_payment_types_option(): array {
		$raw = get_option( 'fotonic_payment_types' );
		if ( ! is_string( $raw ) || '' === $raw ) {
			$defaults = [
				[ 'id' => 1, 'slug' => 'default', 'label' => __( 'Payment', 'eleva-crm-for-photographers' ) ],
				[ 'id' => 2, 'slug' => 'coupon',  'label' => __( 'Discount', 'eleva-crm-for-photographers' ) ],
			];
			if ( false === $raw ) {
				update_option( 'fotonic_payment_types', wp_json_encode( $defaults ) );
			}
			return $defaults;
		}
		$types = json_decode( $raw, true );
		return is_array( $types ) ? $types : [];
	}

	private static function save_payment_types_option( array $types ): void {
		update_option( 'fotonic_payment_types', wp_json_encode( array_values( $types ) ) );
	}

	public static function get_valid_payment_type_slugs(): array {
		$types = self::get_payment_types_option();
		return array_column( $types, 'slug' );
	}

	// =========================================================================
	// Dashboard Stats
	// =========================================================================

	public static function get_dashboard_stats( \WP_REST_Request $request ): \WP_REST_Response {
		$now          = current_time( 'Y' );
		$this_year    = (int) $now;
		$last_year    = $this_year - 1;
		$next_year    = $this_year + 1;

		$years = [
			'last_year' => $last_year,
			'this_year' => $this_year,
			'next_year' => $next_year,
		];

		$works_count   = [ 'last_year' => 0, 'this_year' => 0, 'next_year' => 0 ];
		$revenue       = [ 'last_year' => 0.0, 'this_year' => 0.0, 'next_year' => 0.0 ];
		$payments_recv = [ 'last_year' => 0.0, 'this_year' => 0.0 ];

		foreach ( $years as $key => $year ) {
			$from = sprintf( '%04d-01-01', $year );
			$to   = sprintf( '%04d-12-31', $year );

			// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta key indexed; necessary for postmeta-based filtering.
			$query = new \WP_Query( [
				'post_type'      => 'ftnc_work',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta key indexed; necessary for postmeta-based filtering.
					[
						'key'     => '_ftnc_event_date',
						'value'   => [ $from, $to ],
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					],
				],
			] );

			$works_count[ $key ] = $query->post_count;

			foreach ( $query->posts as $post_id ) {
				$raw          = get_post_meta( (int) $post_id, '_ftnc_installments', true );
				$installments = is_string( $raw ) ? json_decode( $raw, true ) : [];
				if ( ! is_array( $installments ) ) {
					$installments = [];
				}
				foreach ( $installments as $inst ) {
					if ( ! is_array( $inst ) ) continue;
					$amount = (float) ( $inst['amount'] ?? 0 );
					$revenue[ $key ] += $amount;
					if ( isset( $key ) && ( 'last_year' === $key || 'this_year' === $key ) ) {
						$status = (string) ( $inst['status'] ?? 'unpaid' );
						if ( 'unpaid' === $status ) {
							$payments_recv[ $key ] += $amount;
						}
					}
				}
			}
		}

		// Payment types breakdown — this year only
		$pt_map     = [];
		$pt_total   = 0.0;
		$from_ty    = sprintf( '%04d-01-01', $this_year );
		$to_ty      = sprintf( '%04d-12-31', $this_year );
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta key indexed; necessary for postmeta-based filtering.
		$ty_query   = new \WP_Query( [
			'post_type'      => 'ftnc_work',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value,WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Meta key indexed; necessary for postmeta-based filtering.
				[
					'key'     => '_ftnc_event_date',
					'value'   => [ $from_ty, $to_ty ],
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				],
			],
		] );
		foreach ( $ty_query->posts as $post_id ) {
			$raw          = get_post_meta( (int) $post_id, '_ftnc_installments', true );
			$installments = is_string( $raw ) ? json_decode( $raw, true ) : [];
			if ( ! is_array( $installments ) ) continue;
			foreach ( $installments as $inst ) {
				if ( ! is_array( $inst ) ) continue;
				$amount   = (float) ( $inst['amount'] ?? 0 );
				$type_key = sanitize_key( (string) ( $inst['type'] ?? 'default' ) );
				if ( ! isset( $pt_map[ $type_key ] ) ) {
					$pt_map[ $type_key ] = 0.0;
				}
				$pt_map[ $type_key ] += $amount;
				$pt_total             += $amount;
			}
		}

		// Build payment_types array with labels + percentages
		$all_types     = self::get_payment_types_option();
		$type_labels   = array_column( $all_types, 'label', 'slug' );
		$payment_types = [];
		foreach ( $pt_map as $slug => $subtotal ) {
			$payment_types[] = [
				'slug'     => $slug,
				'label'    => $type_labels[ $slug ] ?? $slug,
				'subtotal' => round( $subtotal, 2 ),
				'pct'      => $pt_total > 0 ? round( ( $subtotal / $pt_total ) * 100, 1 ) : 0.0,
			];
		}
		usort( $payment_types, static function( $a, $b ) {
			return $b['subtotal'] <=> $a['subtotal'];
		} );

		return new \WP_REST_Response( [
			'works'    => [
				'this_year' => $works_count['this_year'],
				'next_year' => $works_count['next_year'],
				'last_year' => $works_count['last_year'],
			],
			'revenue'  => [
				'this_year' => round( $revenue['this_year'], 2 ),
				'next_year' => round( $revenue['next_year'], 2 ),
				'last_year' => round( $revenue['last_year'], 2 ),
			],
			'payments_to_receive' => [
				'this_year'     => round( $payments_recv['this_year'], 2 ),
				'last_year'     => round( $payments_recv['last_year'], 2 ),
				'show_last_year' => $payments_recv['last_year'] > 0,
			],
			'payment_types' => $payment_types,
		], 200 );
	}

	public static function get_payment_types( \WP_REST_Request $request ): \WP_REST_Response {
		return new \WP_REST_Response( self::get_payment_types_option(), 200 );
	}

	public static function create_payment_type( \WP_REST_Request $request ): \WP_REST_Response {
		$label = sanitize_text_field( (string) $request->get_param( 'label' ) );
		if ( empty( $label ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Label is required.', 'eleva-crm-for-photographers' ) ], 400 );
		}
		$slug  = sanitize_title( $label );
		$types = self::get_payment_types_option();
		foreach ( $types as $t ) {
			if ( $t['slug'] === $slug ) {
				return new \WP_REST_Response( [ 'message' => __( 'A payment type with this slug already exists.', 'eleva-crm-for-photographers' ) ], 400 );
			}
		}
		$max_id = 0;
		foreach ( $types as $t ) {
			if ( (int) $t['id'] > $max_id ) {
				$max_id = (int) $t['id'];
			}
		}
		$new     = [ 'id' => $max_id + 1, 'slug' => $slug, 'label' => $label ];
		$types[] = $new;
		self::save_payment_types_option( $types );
		return new \WP_REST_Response( $new, 201 );
	}

	public static function update_payment_type( \WP_REST_Request $request ): \WP_REST_Response {
		$id    = (int) $request->get_param( 'id' );
		$label = sanitize_text_field( (string) $request->get_param( 'label' ) );
		if ( empty( $label ) ) {
			return new \WP_REST_Response( [ 'message' => __( 'Label is required.', 'eleva-crm-for-photographers' ) ], 400 );
		}
		$types   = self::get_payment_types_option();
		$found   = false;
		$updated = null;
		foreach ( $types as &$t ) {
			if ( (int) $t['id'] === $id ) {
				$t['label'] = $label;
				$updated    = $t;
				$found      = true;
				break;
			}
		}
		unset( $t );
		if ( ! $found ) {
			return new \WP_REST_Response( [ 'message' => __( 'Payment type not found.', 'eleva-crm-for-photographers' ) ], 404 );
		}
		self::save_payment_types_option( $types );
		return new \WP_REST_Response( $updated, 200 );
	}

	public static function delete_payment_type( \WP_REST_Request $request ): \WP_REST_Response {
		$id       = (int) $request->get_param( 'id' );
		$types    = self::get_payment_types_option();
		if ( count( $types ) <= 1 ) {
			return new \WP_REST_Response( [ 'message' => __( 'At least one payment type must remain.', 'eleva-crm-for-photographers' ) ], 400 );
		}
		$found    = false;
		$filtered = [];
		foreach ( $types as $t ) {
			if ( (int) $t['id'] === $id ) {
				$found = true;
			} else {
				$filtered[] = $t;
			}
		}
		if ( ! $found ) {
			return new \WP_REST_Response( [ 'message' => __( 'Payment type not found.', 'eleva-crm-for-photographers' ) ], 404 );
		}
		self::save_payment_types_option( $filtered );
		return new \WP_REST_Response( [ 'deleted' => true ], 200 );
	}

	/**
	 * Write a vault audit log entry to the WordPress error log.
	 * Uses error_log so no extra DB tables are needed; visible in WP_DEBUG_LOG.
	 *
	 * @param string $event  Short event name: vault_unlock_ok, vault_unlock_fail, vault_lock, vault_password_changed.
	 * @param array  $extra  Optional extra context key→value pairs.
	 */
	private static function audit_log( string $event, array $extra = [] ): void {
		$user_id = get_current_user_id();
		$ip      = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? 'unknown' ) );
		$context = array_merge( [ 'user_id' => $user_id, 'ip' => $ip ], $extra );
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( '[Fotonic Vault] ' . $event . ' ' . wp_json_encode( $context ) );
	}

	private static function looks_encrypted( string $value ): bool {
		// Prefixed formats are unambiguously encrypted.
		if ( strncmp( $value, 'v1d:', 4 ) === 0 || strncmp( $value, 'v2:', 3 ) === 0 ) {
			return true;
		}
		if ( strlen( $value ) < 24 ) {
			return false;
		}
		// Must be valid base64.
		if ( base64_encode( base64_decode( $value, true ) ) !== $value ) {
			return false;
		}
		$decoded = base64_decode( $value, true );
		// Decoded length: 16+ bytes covers legacy v1 fields (16-byte IV + ciphertext).
		return ( false !== $decoded && strlen( $decoded ) >= 16 );
	}
}
