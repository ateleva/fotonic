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
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
				'args'                => [
					'search'   => [ 'type' => 'string',  'default' => '' ],
					'page'     => [ 'type' => 'integer', 'default' => 1, 'minimum' => 1 ],
					'per_page' => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
				],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_customer' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/customers/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_customer' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ __CLASS__, 'update_customer' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'delete_customer' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
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
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
				'args'                => [
					'search'         => [ 'type' => 'string',  'default' => '' ],
					'page'           => [ 'type' => 'integer', 'default' => 1,  'minimum' => 1 ],
					'per_page'       => [ 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100 ],
					'payment_status' => [
						'type'    => 'string',
						'default' => '',
						'enum'    => [ '', 'paid', 'partial', 'unpaid' ],
					],
				],
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'create_work' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
		] );

		register_rest_route( self::NAMESPACE, '/works/(?P<id>\d+)', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'get_work' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ __CLASS__, 'update_work' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ __CLASS__, 'delete_work' ],
				'permission_callback' => [ __CLASS__, 'admin_permission' ],
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
	}

	// ---------------------------------------------------------------------------
	// Permission callbacks
	// ---------------------------------------------------------------------------

	public static function admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	public static function vault_download_permission(): bool {
		return current_user_can( 'manage_options' ) && Fotonic_Vault::is_unlocked();
	}

	// ---------------------------------------------------------------------------
	// Vault endpoints (Phase C)
	// ---------------------------------------------------------------------------

	/**
	 * POST /vault/setup
	 * Body: { password, totp_secret }
	 * Returns: { setup: true, qr_uri: string }
	 */
	public static function vault_setup( \WP_REST_Request $req ): \WP_REST_Response {
		$body        = $req->get_json_params();
		$password    = isset( $body['password'] )    ? (string) $body['password']    : '';
		$totp_secret = isset( $body['totp_secret'] ) ? (string) $body['totp_secret'] : '';

		if ( empty( $password ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_password', 'message' => __( 'Password is required.', 'fotonic' ) ],
				400
			);
		}

		// If no totp_secret supplied, generate a fresh one.
		if ( empty( $totp_secret ) ) {
			$totp_secret = Fotonic_TOTP::generate_secret();
		}

		$ok = Fotonic_Vault::setup( $password, $totp_secret );
		if ( ! $ok ) {
			return new \WP_REST_Response(
				[ 'code' => 'setup_failed', 'message' => __( 'Vault setup failed.', 'fotonic' ) ],
				500
			);
		}

		$site_name = get_bloginfo( 'name' ) ?: 'Fotonic';
		$label     = $site_name . ':' . wp_get_current_user()->user_email;
		$qr_uri    = Fotonic_TOTP::get_uri( $totp_secret, $label );

		return new \WP_REST_Response( [
			'setup'  => true,
			'qr_uri' => $qr_uri,
		], 200 );
	}

	/**
	 * POST /vault/unlock
	 * Body: { password, otp }
	 * Returns: { unlocked: true }
	 */
	public static function vault_unlock( \WP_REST_Request $req ): \WP_REST_Response {
		$body     = $req->get_json_params();
		$password = isset( $body['password'] ) ? (string) $body['password'] : '';
		$otp      = isset( $body['otp'] )      ? (string) $body['otp']      : '';

		if ( empty( $password ) || empty( $otp ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_fields', 'message' => __( 'Password and OTP are required.', 'fotonic' ) ],
				400
			);
		}

		$ok = Fotonic_Vault::unlock( $password, $otp );
		if ( ! $ok ) {
			return new \WP_REST_Response(
				[ 'code' => 'invalid_credentials', 'message' => __( 'Invalid password or OTP code.', 'fotonic' ) ],
				401
			);
		}

		return new \WP_REST_Response( [ 'unlocked' => true ], 200 );
	}

	/**
	 * POST /vault/lock
	 * Returns: { locked: true }
	 */
	public static function vault_lock( \WP_REST_Request $_req ): \WP_REST_Response {
		Fotonic_Vault::lock();
		return new \WP_REST_Response( [ 'locked' => true ], 200 );
	}

	/**
	 * GET /vault/status
	 * Returns: { setup: bool, unlocked: bool }
	 */
	public static function vault_status( \WP_REST_Request $_req ): \WP_REST_Response {
		return new \WP_REST_Response( [
			'setup'    => Fotonic_Vault::is_setup(),
			'unlocked' => Fotonic_Vault::is_unlocked(),
			'salt'     => (string) get_option( Fotonic_Vault::OPTION_SALT, '' ),
		], 200 );
	}

	/**
	 * GET /vault-download/{id}
	 * Streams WP attachment file. Permission callback already checks vault + capability.
	 */
	public static function vault_download( \WP_REST_Request $req ): void {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || strpos( $post->post_mime_type, '/' ) === false ) {
			status_header( 404 );
			wp_send_json( [ 'code' => 'not_found', 'message' => __( 'File not found.', 'fotonic' ) ], 404 );
			exit;
		}

		$file = get_attached_file( $id );
		if ( ! $file || ! file_exists( $file ) ) {
			status_header( 404 );
			wp_send_json( [ 'code' => 'file_missing', 'message' => __( 'File not found on disk.', 'fotonic' ) ], 404 );
			exit;
		}

		$mime     = $post->post_mime_type;
		$filename = basename( $file );

		// Disable output buffering to stream efficiently.
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		header( 'X-Content-Type-Options: nosniff' );

		readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	// ---------------------------------------------------------------------------
	// Vault management (Phase G)
	// ---------------------------------------------------------------------------

	/**
	 * POST /vault/change-password
	 * Body: { current_password, otp, new_password }
	 * Re-derives the vault key from a new password and re-encrypts all PII postmeta.
	 */
	public static function vault_change_password( \WP_REST_Request $req ): \WP_REST_Response {
		$body             = $req->get_json_params();
		$current_password = isset( $body['current_password'] ) ? (string) $body['current_password'] : '';
		$otp              = isset( $body['otp'] )              ? (string) $body['otp']              : '';
		$new_password     = isset( $body['new_password'] )     ? (string) $body['new_password']     : '';

		if ( empty( $current_password ) || empty( $otp ) || empty( $new_password ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_fields', 'message' => __( 'All fields are required.', 'fotonic' ) ],
				400
			);
		}

		if ( ! Fotonic_Vault::is_setup() ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_setup', 'message' => __( 'Vault is not set up.', 'fotonic' ) ],
				400
			);
		}

		$old_salt       = (string) get_option( Fotonic_Vault::OPTION_SALT, '' );
		$encrypted_totp = (string) get_option( Fotonic_Vault::OPTION_TOTP, '' );

		if ( empty( $old_salt ) || empty( $encrypted_totp ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'vault_error', 'message' => __( 'Vault configuration error.', 'fotonic' ) ],
				500
			);
		}

		$old_key     = Fotonic_Crypto::derive_key( $current_password, $old_salt );
		$totp_secret = Fotonic_Crypto::decrypt( $encrypted_totp, $old_key );

		if ( empty( $totp_secret ) || ! Fotonic_TOTP::verify( $otp, $totp_secret ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'invalid_credentials', 'message' => __( 'Invalid password or OTP code.', 'fotonic' ) ],
				401
			);
		}

		$new_salt           = base64_encode( random_bytes( 32 ) );
		$new_key            = Fotonic_Crypto::derive_key( $new_password, $new_salt );
		$new_encrypted_totp = Fotonic_Crypto::encrypt( $totp_secret, $new_key );

		if ( empty( $new_encrypted_totp ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'encrypt_error', 'message' => __( 'Encryption error.', 'fotonic' ) ],
				500
			);
		}

		self::reencrypt_customers( $old_key, $new_key );
		self::reencrypt_works( $old_key, $new_key );

		update_option( Fotonic_Vault::OPTION_SALT, $new_salt,           false );
		update_option( Fotonic_Vault::OPTION_TOTP, $new_encrypted_totp, false );

		Fotonic_Vault::update_session_key( $new_key );

		return new \WP_REST_Response( [ 'changed' => true ], 200 );
	}

	/**
	 * POST /vault/reset-totp
	 * Body: { password, otp }
	 * Generates a new TOTP secret (keeping the same vault password/key).
	 * Returns: { reset: true, qr_uri: string }
	 */
	public static function vault_reset_totp( \WP_REST_Request $req ): \WP_REST_Response {
		$body     = $req->get_json_params();
		$password = isset( $body['password'] ) ? (string) $body['password'] : '';
		$otp      = isset( $body['otp'] )      ? (string) $body['otp']      : '';

		if ( empty( $password ) || empty( $otp ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'missing_fields', 'message' => __( 'Password and OTP are required.', 'fotonic' ) ],
				400
			);
		}

		if ( ! Fotonic_Vault::is_setup() ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_setup', 'message' => __( 'Vault is not set up.', 'fotonic' ) ],
				400
			);
		}

		$salt           = (string) get_option( Fotonic_Vault::OPTION_SALT, '' );
		$encrypted_totp = (string) get_option( Fotonic_Vault::OPTION_TOTP, '' );

		if ( empty( $salt ) || empty( $encrypted_totp ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'vault_error', 'message' => __( 'Vault configuration error.', 'fotonic' ) ],
				500
			);
		}

		$key         = Fotonic_Crypto::derive_key( $password, $salt );
		$totp_secret = Fotonic_Crypto::decrypt( $encrypted_totp, $key );

		if ( empty( $totp_secret ) || ! Fotonic_TOTP::verify( $otp, $totp_secret ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'invalid_credentials', 'message' => __( 'Invalid password or OTP code.', 'fotonic' ) ],
				401
			);
		}

		$new_totp_secret    = Fotonic_TOTP::generate_secret();
		$new_encrypted_totp = Fotonic_Crypto::encrypt( strtoupper( $new_totp_secret ), $key );

		if ( empty( $new_encrypted_totp ) ) {
			return new \WP_REST_Response(
				[ 'code' => 'encrypt_error', 'message' => __( 'Encryption error.', 'fotonic' ) ],
				500
			);
		}

		update_option( Fotonic_Vault::OPTION_TOTP, $new_encrypted_totp, false );

		$site_name = get_bloginfo( 'name' ) ?: 'Fotonic';
		$label     = $site_name . ':' . wp_get_current_user()->user_email;
		$qr_uri    = Fotonic_TOTP::get_uri( $new_totp_secret, $label );

		return new \WP_REST_Response( [
			'reset'  => true,
			'qr_uri' => $qr_uri,
		], 200 );
	}

	/**
	 * Re-encrypt all customer PII postmeta with a new key.
	 *
	 * @param string $old_key 32-byte old derived key.
	 * @param string $new_key 32-byte new derived key.
	 */
	private static function reencrypt_customers( string $old_key, string $new_key ): void {
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
						$plain = Fotonic_Crypto::decrypt( $person[ $field ], $old_key );
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
	 *
	 * @param string $old_key 32-byte old derived key.
	 * @param string $new_key 32-byte new derived key.
	 */
	private static function reencrypt_works( string $old_key, string $new_key ): void {
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
				[ 'code' => 'not_found', 'message' => __( 'Customer not found.', 'fotonic' ) ],
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
				[ 'code' => 'missing_title', 'message' => __( 'Title is required.', 'fotonic' ) ],
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
				[ 'code' => 'not_found', 'message' => __( 'Customer not found.', 'fotonic' ) ],
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
				[ 'code' => 'not_found', 'message' => __( 'Customer not found.', 'fotonic' ) ],
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
				[ 'code' => 'not_found', 'message' => __( 'Service not found.', 'fotonic' ) ],
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
				[ 'code' => 'missing_title', 'message' => __( 'Title is required.', 'fotonic' ) ],
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
				[ 'code' => 'not_found', 'message' => __( 'Service not found.', 'fotonic' ) ],
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
				[ 'code' => 'not_found', 'message' => __( 'Service not found.', 'fotonic' ) ],
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

		$args = [
			'post_type'      => 'ftnc_work',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'meta_value',
			'meta_key'       => '_ftnc_event_date',
			'order'          => 'ASC',
		];

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
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
				[ 'code' => 'not_found', 'message' => __( 'Work not found.', 'fotonic' ) ],
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
				[ 'code' => 'missing_title', 'message' => __( 'Title is required.', 'fotonic' ) ],
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

		return new \WP_REST_Response( self::format_work( get_post( $post_id ) ), 201 );
	}

	public static function update_work( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_work' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Work not found.', 'fotonic' ) ],
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

		self::save_work_meta( $id, $body );
		Fotonic_Meta_Boxes::auto_assign_payment_status( $id );

		return new \WP_REST_Response( self::format_work( get_post( $id ) ), 200 );
	}

	public static function delete_work( \WP_REST_Request $req ): \WP_REST_Response {
		$id   = (int) $req->get_param( 'id' );
		$post = get_post( $id );

		if ( ! $post || $post->post_type !== 'ftnc_work' || $post->post_status === 'trash' ) {
			return new \WP_REST_Response(
				[ 'code' => 'not_found', 'message' => __( 'Work not found.', 'fotonic' ) ],
				404
			);
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
				[ 'code' => 'not_found', 'message' => __( 'Customer not found.', 'fotonic' ) ],
				404
			);
		}

		$linked = get_posts( [
			'post_type'      => 'ftnc_work',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_key'       => '_ftnc_customer_id',
			'meta_value'     => $id,
			'fields'         => 'ids',
		] );

		if ( ! empty( $linked ) ) {
			$titles = array_map( 'get_the_title', $linked );
			$count  = count( $linked );
			return new \WP_REST_Response( [
				'can_delete' => false,
				'reason'     => sprintf(
					_n( 'Linked to %d work: %s', 'Linked to %d works: %s', $count, 'fotonic' ),
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
				[ 'code' => 'not_found', 'message' => __( 'Service not found.', 'fotonic' ) ],
				404
			);
		}

		$candidate_works = get_posts( [
			'post_type'      => 'ftnc_work',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_query'     => [ [
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
					_n( 'Linked to %d work: %s', 'Linked to %d works: %s', $count, 'fotonic' ),
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
				[ 'code' => 'not_found', 'message' => __( 'Work not found.', 'fotonic' ) ],
				404
			);
		}

		return new \WP_REST_Response( [ 'can_delete' => true ], 200 );
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
				// Random-IV encryption for free-text PII.
				$first_name         = Fotonic_Crypto::encrypt( $first_name,         $vault_key );
				$last_name          = Fotonic_Crypto::encrypt( $last_name,          $vault_key );
				$nationality        = Fotonic_Crypto::encrypt( $nationality,        $vault_key );
				$instagram_username = Fotonic_Crypto::encrypt( $instagram_username, $vault_key );
				$address            = Fotonic_Crypto::encrypt( $address,            $vault_key );
				$tin                = Fotonic_Crypto::encrypt( $tin,                $vault_key );
				// Deterministic encryption for searchable fields.
				$email = Fotonic_Crypto::deterministic_encrypt( $email, $vault_key );
				$phone = Fotonic_Crypto::deterministic_encrypt( $phone, $vault_key );
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

		// Owner (free = admin).
		update_post_meta( $post_id, '_ftnc_owner_type', 'admin' );
		if ( isset( $body['owner_id'] ) ) {
			update_post_meta( $post_id, '_ftnc_owner_id', (int) $body['owner_id'] );
		} else {
			$uid = get_current_user_id();
			if ( $uid ) {
				update_post_meta( $post_id, '_ftnc_owner_id', $uid );
			}
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
					'price_override' => (float) ( $svc['price_override'] ?? 0 ),
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

		if ( isset( $body['installments'] ) && is_array( $body['installments'] ) ) {
			$clean = [];
			foreach ( $body['installments'] as $inst ) {
				if ( ! is_array( $inst ) ) {
					continue;
				}
				$status  = ( isset( $inst['status'] ) && $inst['status'] === 'paid' ) ? 'paid' : 'unpaid';
				$type    = ( isset( $inst['type'] ) && $inst['type'] === 'coupon' ) ? 'coupon' : 'default';
				$clean[] = [
					'title'  => sanitize_text_field( $inst['title'] ?? '' ),
					'amount' => (float) ( $inst['amount'] ?? 0 ),
					'status' => $status,
					'type'   => $type,
				];
			}
			update_post_meta( $post_id, '_ftnc_installments', wp_json_encode( $clean ) );
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
						'price_override' => (float) ( $svc['price_override'] ?? 0 ),
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
						'amount' => (float) ( $inst['amount'] ?? 0 ),
						'status' => ( isset( $inst['status'] ) && $inst['status'] === 'paid' ) ? 'paid' : 'unpaid',
						'type'   => ( isset( $inst['type'] ) && $inst['type'] === 'coupon' ) ? 'coupon' : 'default',
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

		return [
			'id'             => $post->ID,
			'title'          => $post->post_title,
			'event_date'     => (string) get_post_meta( $post->ID, '_ftnc_event_date', true ),
			'event_time_from' => (string) get_post_meta( $post->ID, '_ftnc_event_time_from', true ),
			'event_time_to'   => (string) get_post_meta( $post->ID, '_ftnc_event_time_to', true ),
			'event_addresses' => $event_addresses,
			'customer_id'    => $customer_id,
			'customer_title' => $customer_title,
			'services'       => $services,
			'files'          => $files,
			'total_price'    => (float) get_post_meta( $post->ID, '_ftnc_total_price', true ),
			'installments'   => $installments,
			'payment_status' => $payment_status,
			'notes'          => $notes,
		];
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
		$decrypted = Fotonic_Crypto::decrypt( $value, $vault_key );
		// If decrypt returns empty string for a non-empty input, the data may be
		// encrypted with a different key or be deterministic-encrypted; fall back.
		if ( '' === $decrypted && '' !== $value ) {
			// Try deterministic decrypt path (same function — decrypt handles both).
			// Actually Fotonic_Crypto::decrypt works for both random-IV and det-enc
			// because det-enc stores only ciphertext (no IV prefix), so it will
			// return '' on failure. Return the raw value as a safe fallback.
			return $value;
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
	private static function looks_encrypted( string $value ): bool {
		if ( strlen( $value ) < 24 ) {
			return false;
		}
		// Must be valid base64.
		if ( base64_encode( base64_decode( $value, true ) ) !== $value ) {
			return false;
		}
		// Decoded length must be at least 16 bytes (IV) + 16 bytes (one AES block).
		$decoded = base64_decode( $value, true );
		return ( false !== $decoded && strlen( $decoded ) >= 32 );
	}
}
