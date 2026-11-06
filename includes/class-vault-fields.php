<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The one list of every field the Vault protects, and the walker that encrypts the plain ones.
 *
 * A group is one record type and the fields it holds. A field is described, not coded:
 *   - kind 'post'  : a post column ('post_title', 'post_content');
 *   - kind 'meta'  : a scalar postmeta;
 *   - kind 'rows'  : postmeta holding a JSON list of rows, with named columns inside each row.
 * Every field has a format: 'f1' (Fotonic_Field_Crypt, authenticated, prefixed), 'v1' (AES-CBC,
 * random IV, the people and address fields) or 'v1d' (AES-CBC, deterministic, email and phone).
 *
 * Pro adds its own groups through the filter `ftnc_vault_fields`. The encrypt-existing job
 * (Fotonic_Vault_Encrypt_Existing) and, later, the backup restore (re-encryption with another
 * key) both walk this list, so a field added here is covered by both.
 *
 * Database access goes through a small repository object so the logic can be tested without
 * WordPress (see Fotonic_Vault_Fields_Repo).
 */
class Fotonic_Vault_Fields {

	/** @var object|null Repository override (tests). */
	private static $repo = null;

	/** Replace the repository (tests only); null restores the WordPress one. */
	public static function use_repo( $repo ): void {
		self::$repo = $repo;
	}

	public static function repo() {
		if ( null === self::$repo ) {
			self::$repo = new Fotonic_Vault_Fields_Repo();
		}
		return self::$repo;
	}

	/**
	 * The groups the free plugin protects by itself (without what Pro adds).
	 *
	 * @return array<string, array> group id => { label, post_type, fields[] }
	 */
	public static function base_groups(): array {
		$groups = array(
			'customers' => array(
				'label'     => __( 'Customers', 'eleva-crm-for-photographers' ),
				'post_type' => 'ftnc_customer',
				'fields'    => array(
					array( 'kind' => 'rows', 'meta' => '_ftnc_people', 'cols' => array(
						'first_name' => 'v1', 'last_name' => 'v1', 'nationality' => 'v1', 'instagram_username' => 'v1',
						'address' => 'v1', 'tin' => 'v1', 'email' => 'v1d', 'phone' => 'v1d',
					) ),
				),
			),
			'works'     => array(
				'label'     => __( 'Works', 'eleva-crm-for-photographers' ),
				'post_type' => 'ftnc_work',
				'fields'    => array(
					array( 'kind' => 'post', 'field' => 'post_content', 'format' => 'f1' ),
					array( 'kind' => 'rows', 'meta' => '_ftnc_event_addresses', 'cols' => array( 'street' => 'v1' ) ),
					array( 'kind' => 'rows', 'meta' => '_ftnc_memory_cards', 'cols' => array( 'notes' => 'f1' ) ),
					array( 'kind' => 'rows', 'meta' => '_ftnc_work_services', 'cols' => array( 'notes_override' => 'f1' ) ),
					// The label of each document or link of the work (a name typed by the owner).
					array( 'kind' => 'rows', 'meta' => '_ftnc_work_files', 'cols' => array( 'label' => 'f1' ) ),
					array( 'kind' => 'meta', 'meta' => '_ftnc_delivery_notes', 'format' => 'f1' ),
					array( 'kind' => 'meta', 'meta' => '_ftnc_delivery_password', 'format' => 'f1' ),
					// The documents themselves (contents and names): after the label column above, so the file step reads
					// the row as it was just written. Done by Fotonic_Work_Files, not by a field format.
					array( 'kind' => 'work_files' ),
				),
			),
			'backup_locations' => array(
				'label'     => __( 'Backup Locations', 'eleva-crm-for-photographers' ),
				'post_type' => 'ftnc_backup_loc',
				'fields'    => array(
					array( 'kind' => 'meta', 'meta' => '_ftnc_loc_notes', 'format' => 'f1' ),
				),
			),
			'services'  => array(
				'label'     => __( 'Services', 'eleva-crm-for-photographers' ),
				'post_type' => 'ftnc_service',
				'fields'    => array(
					array( 'kind' => 'post', 'field' => 'post_title', 'format' => 'f1', 'neutral_slug' => true ),
					array( 'kind' => 'meta', 'meta' => '_ftnc_base_price', 'format' => 'f1' ),
					array( 'kind' => 'meta', 'meta' => '_ftnc_notes', 'format' => 'f1' ),
				),
			),
		);
		// The title of a customer or a work (phase 15), only while titles may be encrypted (see Fotonic_Titles::enabled()). Added at the
		// END of the fields: Pro's quotes take the people field by its meta name, never by position.
		if ( self::titles_on() ) {
			$groups['customers']['fields'][] = self::title_field();
			$groups['works']['fields'][]     = self::title_field();
		}
		return $groups;
	}

	/** True while the titles of customers, works and quotes may be encrypted (the gate of Fotonic_Titles). */
	public static function titles_on(): bool {
		return class_exists( 'Fotonic_Titles' ) && Fotonic_Titles::enabled();
	}

	/** The field that describes the title of a record: ciphertext, and a slug that says nothing about it. */
	public static function title_field(): array {
		return array( 'kind' => 'post', 'field' => 'post_title', 'format' => 'f1', 'neutral_slug' => true );
	}

	/**
	 * @return array<string, array> group id => { label, post_type, fields[] }
	 */
	public static function groups(): array {
		$groups = self::base_groups();

		/**
		 * Lets Pro declare the fields it keeps under the Vault.
		 *
		 * @param array $groups Group id => { label, post_type, fields[] }.
		 */
		$groups = (array) apply_filters( 'ftnc_vault_fields', $groups );
		$clean  = array();
		foreach ( $groups as $id => $group ) {
			if ( is_string( $id ) && is_array( $group ) && ! empty( $group['post_type'] ) && ! empty( $group['fields'] ) ) {
				$clean[ $id ] = $group;
			}
		}
		return $clean;
	}

	/**
	 * One short signature per protected field, so the job can tell which fields a site has already
	 * been through (a field that appears later, because Pro was updated or switched on, is new).
	 *
	 * @param array|null $groups Groups to describe (null = groups()).
	 * @return string[]
	 */
	public static function signatures( ?array $groups = null ): array {
		$groups = null === $groups ? self::groups() : $groups;
		$out    = array();
		foreach ( $groups as $id => $group ) {
			foreach ( (array) $group['fields'] as $field ) {
				$out[] = substr( md5( wp_json_encode( array( (string) $id, (string) $group['post_type'], $field ) ) ), 0, 12 );
			}
		}
		return array_values( array_unique( $out ) );
	}

	// ---------------------------------------------------------------------------
	// One value
	// ---------------------------------------------------------------------------

	/**
	 * Whether a stored value is plain text that still has to be encrypted.
	 * An empty value has nothing to protect. For the CBC formats, which have no reliable marker,
	 * a value that looks like base64 is trusted as ciphertext only when this key decrypts it.
	 * (A long plain text without spaces is therefore still encrypted. A ciphertext left by a Vault that was
	 * reset fails to decrypt but has the CBC shape, and is left as it is.)
	 */
	public static function is_plain( string $value, string $format, string $key ): bool {
		if ( '' === $value ) {
			return false;
		}
		if ( 'f1' === $format ) {
			return ! Fotonic_Field_Crypt::is_encrypted( $value );
		}
		if ( 'v1d' === $format && 0 === strncmp( $value, 'v1d:', 4 ) ) {
			return false;
		}
		if ( 0 === strncmp( $value, 'v2:', 3 ) ) {
			return false; // The browser-side layer: not ours to touch.
		}
		if ( ! Fotonic_REST_API::looks_encrypted( $value ) ) {
			return true;
		}
		$probe = 'v1d' === $format ? Fotonic_Crypto::deterministic_decrypt( $value, $key ) : Fotonic_Crypto::decrypt( $value, $key );
		if ( '' !== $probe ) {
			return false;
		}
		// This key does not open it. A value shaped like CBC ciphertext (IV plus whole blocks) is a ciphertext of
		// another key (a Vault that was reset): leave it, so it keeps reading as "cannot be read" (null) instead of
		// being wrapped and then shown as base64 noise. A base64-looking plain text of that exact shape is far rarer.
		$raw = base64_decode( $value, true );
		return ! ( false !== $raw && strlen( $raw ) >= 32 && 0 === strlen( $raw ) % 16 );
	}

	/**
	 * Encrypt a plain value in the given format.
	 *
	 * @return string|false The stored form, or false on failure (the caller leaves the value alone).
	 */
	public static function encrypt_value( string $plain, string $format, string $key ) {
		if ( '' === $plain ) {
			return '';
		}
		if ( 'f1' === $format ) {
			// A plain text escaped for a site without a Vault ("f0:...") is unescaped first.
			if ( 0 === strncmp( $plain, Fotonic_Field_Crypt::ESCAPE, 3 ) ) {
				$plain = substr( $plain, 3 );
			}
			return Fotonic_Field_Crypt::encrypt( $plain, $key );
		}
		$out = 'v1d' === $format ? Fotonic_Crypto::deterministic_encrypt( $plain, $key ) : Fotonic_Crypto::encrypt( $plain, $key );
		return '' === $out ? false : $out;
	}

	// ---------------------------------------------------------------------------
	// One record
	// ---------------------------------------------------------------------------

	/**
	 * Walk the fields of one record. With $write false it only counts.
	 *
	 * @param int    $post_id Record ID.
	 * @param array  $group   Group definition.
	 * @param string $key     Master Key.
	 * @param bool   $write   Write the encrypted values.
	 * @return int Number of plain values found (and, with $write, encrypted).
	 */
	public static function process_record( int $post_id, array $group, string $key, bool $write ): int {
		$repo    = self::repo();
		$changed = 0;
		$post_up = array();

		if ( $write ) {
			/**
			 * About to encrypt a record: Pro keeps the readable copy that Google Calendar needs (it can only be made while the title is
			 * still readable or the key is at hand, and it must exist before the title turns into ciphertext).
			 *
			 * @param int    $post_id   Record ID.
			 * @param string $post_type Post type.
			 * @param string $key       Master Key.
			 */
			do_action( 'ftnc_vault_before_encrypt_record', $post_id, (string) $group['post_type'], $key );
		}

		foreach ( $group['fields'] as $field ) {
			$kind = $field['kind'] ?? '';
			if ( 'post' === $kind ) {
				$name  = (string) $field['field'];
				$value = $repo->get_post_field( $post_id, $name );
				if ( self::is_plain( $value, $field['format'], $key ) ) {
					$changed++;
					if ( $write ) {
						$enc = self::encrypt_value( $value, $field['format'], $key );
						if ( false === $enc ) {
							$changed--;
							continue;
						}
						$post_up[ $name ] = $enc;
						if ( ! empty( $field['neutral_slug'] ) ) {
							$post_up['post_name'] = self::neutral_slug( $group['post_type'], $post_id );
						}
					}
				}
			} elseif ( 'meta' === $kind ) {
				$name  = (string) $field['meta'];
				$value = $repo->get_meta( $post_id, $name );
				if ( self::is_plain( $value, $field['format'], $key ) ) {
					$changed++;
					if ( $write ) {
						$enc = self::encrypt_value( $value, $field['format'], $key );
						if ( false === $enc ) {
							$changed--;
							continue;
						}
						$repo->set_meta( $post_id, $name, $enc );
					}
				}
			} elseif ( 'rows' === $kind ) {
				$name = (string) $field['meta'];
				$raw  = $repo->get_meta( $post_id, $name );
				$rows = '' === $raw ? null : json_decode( $raw, true );
				if ( ! is_array( $rows ) ) {
					continue;
				}
				$row_changes = 0;
				foreach ( $rows as $i => $row ) {
					if ( ! is_array( $row ) ) {
						continue;
					}
					foreach ( $field['cols'] as $col => $format ) {
						$value = isset( $row[ $col ] ) && is_string( $row[ $col ] ) ? $row[ $col ] : '';
						if ( ! self::is_plain( $value, $format, $key ) ) {
							continue;
						}
						$row_changes++;
						if ( $write ) {
							$enc = self::encrypt_value( $value, $format, $key );
							if ( false === $enc ) {
								$row_changes--;
								continue;
							}
							$rows[ $i ][ $col ] = $enc;
						}
					}
				}
				$changed += $row_changes;
				if ( $write && $row_changes > 0 ) {
					$repo->set_meta( $post_id, $name, wp_json_encode( $rows ) );
				}
			}
		}

		// The documents of a work: moved into the private folder and encrypted (the in-memory repo of the tests has no files).
		foreach ( $group['fields'] as $field ) {
			if ( 'work_files' === ( $field['kind'] ?? '' ) && method_exists( $repo, 'work_files' ) ) {
				if ( $write && ! empty( $post_up ) ) { // Titles first, so a stop between the two leaves nothing half done.
					$repo->set_post_fields( $post_id, $post_up );
					$post_up = array();
				}
				$changed += $repo->work_files( $post_id, $key, $write );
			}
		}

		if ( $write && ! empty( $post_up ) ) {
			$repo->set_post_fields( $post_id, $post_up );
		}
		return $changed;
	}

	/** A slug that says nothing about the record (WordPress derives the slug from the title). */
	public static function neutral_slug( string $post_type, int $post_id ): string {
		return substr( sanitize_key( str_replace( 'ftnc_', '', $post_type ) ), 0, 20 ) . '-' . $post_id;
	}
}
