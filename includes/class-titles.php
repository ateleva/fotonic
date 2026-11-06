<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The titles of customers, works and quotes under the Vault (1.7.0, phase 15).
 *
 * A title is stored as `f1:` ciphertext (Fotonic_Field_Crypt) when the Vault has a key, so SQL can no
 * longer search or sort it. This class is the one place that reads a title (with a per-request cache),
 * builds what to store, and does the searching and ordering in PHP after decrypting.
 *
 * The gate: a title is only ever encrypted when enabled() says so. Eleva CRM Pro older than 1.7.0 reads
 * `post_title` directly, so while it is active and does not say it can cope, titles stay plain text.
 * read() decrypts any `f1:` it finds either way.
 *
 * An unreadable title (no key, wrong key, damaged) is null, never ''.
 */
class Fotonic_Titles {

	/** @var array<string,array{0:string,1:?string}> "id|key id" => { stored value, plain text } */
	private static $cache = array();

	/** @var object|null Data source override (tests). Methods: title( int ), rows( string, array ), people_text( string, array, ?string ). */
	private static $source = null;

	/** Replace the data source (tests only); null restores WordPress. */
	public static function use_source( $source ): void {
		self::$source = $source;
		self::$cache  = array();
	}

	/**
	 * May titles be stored encrypted on this site? Free alone: yes. With Pro: only when Pro says it can read them
	 * (filter `ftnc_titles_encryptable`, returned true by Pro 1.7.0 and later).
	 */
	public static function enabled(): bool {
		return (bool) apply_filters( 'ftnc_titles_encryptable', ! defined( 'FOTO_PRO_VERSION' ) );
	}

	// ---------------------------------------------------------------------------
	// Text rules shared by search and ordering
	// ---------------------------------------------------------------------------

	/** Lower case, no accents, spaces collapsed: what two titles are compared by (like the database collation did). */
	public static function fold( string $s ): string {
		if ( function_exists( 'remove_accents' ) ) {
			$s = remove_accents( $s );
		} elseif ( class_exists( 'Normalizer' ) ) {
			$n = Normalizer::normalize( $s, Normalizer::FORM_D );
			$s = false === $n ? $s : (string) preg_replace( '/\p{Mn}+/u', '', $n );
		}
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
		$s = preg_replace( '/\s+/u', ' ', $s );
		return trim( (string) $s );
	}

	/** Search terms of a query: folded, split on spaces. */
	private static function terms( string $query ): array {
		$q = self::fold( $query );
		return '' === $q ? array() : explode( ' ', $q );
	}

	/** Every term appears in the (already folded) haystack. */
	private static function all_terms( array $terms, string $haystack ): bool {
		foreach ( $terms as $t ) {
			if ( false === strpos( $haystack, $t ) ) {
				return false;
			}
		}
		return true;
	}

	/** Does the title contain every word of the query (accents and case ignored)? An unreadable title never matches; an empty query always does. */
	public static function matches( ?string $title, string $query ): bool {
		$terms = self::terms( $query );
		if ( empty( $terms ) ) {
			return true;
		}
		return null !== $title && self::all_terms( $terms, self::fold( $title ) );
	}

	/** Natural, case and accent insensitive comparison of two titles; an unreadable one (null) goes last. */
	public static function compare( ?string $a, ?string $b ): int {
		if ( null === $a || null === $b ) {
			return null === $a ? ( null === $b ? 0 : 1 ) : -1;
		}
		return strnatcasecmp( self::fold( $a ), self::fold( $b ) );
	}

	// ---------------------------------------------------------------------------
	// Reading
	// ---------------------------------------------------------------------------

	private static function stored_title( int $id ): string {
		if ( null !== self::$source ) {
			return (string) self::$source->title( $id );
		}
		return (string) get_post_field( 'post_title', $id, 'raw' );
	}

	/** Identifies the key in the cache without keeping it. */
	private static function key_id( ?string $key ): string {
		return null === $key ? '' : substr( hash( 'sha256', 'ftnc-title-cache' . $key ), 0, 12 );
	}

	/**
	 * The plain title of a record.
	 *
	 * @param \WP_Post|int $post Record or ID.
	 * @param string|null  $key  Master Key (Fotonic_Vault::get_session_key()) or null.
	 * @return string|null Null when it cannot be read.
	 */
	public static function read( $post, ?string $key ): ?string {
		$id = is_object( $post ) ? (int) $post->ID : (int) $post;
		if ( $id <= 0 ) {
			return '';
		}
		$stored = self::stored_title( $id );
		return self::plain( $id, $stored, $key );
	}

	/** The title for a sentence (a message that names a record): the plain text, or "#ID" when it cannot be read. Never ciphertext. */
	public static function label( $post, ?string $key ): string {
		$plain = self::read( $post, $key );
		return null === $plain ? '#' . ( is_object( $post ) ? (int) $post->ID : (int) $post ) : $plain;
	}

	/** Same as read() for a title already in hand (a row of rows()). */
	public static function plain( int $id, string $stored, ?string $key ): ?string {
		$ck = $id . '|' . self::key_id( $key );
		if ( isset( self::$cache[ $ck ] ) && self::$cache[ $ck ][0] === $stored ) {
			return self::$cache[ $ck ][1];
		}
		$plain             = Fotonic_Field_Crypt::read( $stored, $key );
		self::$cache[ $ck ] = array( $stored, $plain );
		return $plain;
	}

	public static function flush_cache(): void {
		self::$cache = array();
	}

	/**
	 * The stored titles of every record of a type, with one query and no meta.
	 *
	 * @param string   $post_type Post type.
	 * @param string[] $statuses  Statuses.
	 * @return array<int,string> ID => stored title.
	 */
	public static function rows( string $post_type, array $statuses = array( 'publish' ) ): array {
		if ( null !== self::$source ) {
			return (array) self::$source->rows( $post_type, $statuses );
		}
		global $wpdb;
		$in = "'" . implode( "','", array_map( 'esc_sql', $statuses ) ) . "'";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- one read of our own post types; the status list is escaped above.
		$res = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->posts} WHERE post_type = %s AND post_status IN ( $in ) ORDER BY ID ASC", $post_type ), ARRAY_N );
		$out = array();
		foreach ( (array) $res as $r ) {
			$out[ (int) $r[0] ] = (string) $r[1];
		}
		return $out;
	}

	/**
	 * Text of the people of customers that a search also looks into (first and last name, email, phone), decrypted with the key
	 * where they are encrypted. Customers only; other types have none.
	 *
	 * @param int[] $ids Customer IDs.
	 * @return array<int,string> ID => text (folded).
	 */
	private static function people_text( string $post_type, array $ids, ?string $key ): array {
		if ( 'ftnc_customer' !== $post_type || empty( $ids ) ) {
			return array();
		}
		if ( null !== self::$source ) {
			return (array) self::$source->people_text( $post_type, $ids, $key );
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one read of the people of all customers.
		$res = $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_ftnc_people'", ARRAY_N );
		$set = array_flip( $ids );
		$out = array();
		foreach ( (array) $res as $r ) {
			$id = (int) $r[0];
			if ( ! isset( $set[ $id ] ) ) {
				continue;
			}
			$people = json_decode( (string) $r[1], true );
			if ( ! is_array( $people ) ) {
				continue;
			}
			$parts = array();
			foreach ( $people as $person ) {
				if ( ! is_array( $person ) ) {
					continue;
				}
				// Only the four fields a search looks into are opened (not the address, the tax code and so on).
				foreach ( array( 'first_name', 'last_name', 'email', 'phone' ) as $f ) {
					if ( isset( $person[ $f ] ) && is_string( $person[ $f ] ) && '' !== $person[ $f ] ) {
						$plain = Fotonic_REST_API::decrypt_value( $person[ $f ], $key );
						if ( null !== $plain && '' !== $plain ) {
							$parts[] = $plain;
						}
					}
				}
			}
			if ( $parts ) {
				$out[ $id ] = self::fold( implode( ' ', $parts ) );
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------------------
	// Searching and ordering in PHP
	// ---------------------------------------------------------------------------

	/**
	 * IDs of the records whose title (and, for customers, the name, email or phone of their people) contains every word of the query.
	 *
	 * @return int[] In ID order.
	 */
	public static function search_ids( string $post_type, string $query, ?string $key, array $statuses = array( 'publish' ) ): array {
		$rows  = self::rows( $post_type, $statuses );
		$terms = self::terms( $query );
		if ( empty( $terms ) ) {
			return array_map( 'intval', array_keys( $rows ) );
		}
		$people = self::people_text( $post_type, array_keys( $rows ), $key );
		$out    = array();
		foreach ( $rows as $id => $stored ) {
			$title = self::plain( (int) $id, $stored, $key );
			$hay   = ( null === $title ? '' : self::fold( $title ) ) . ' ' . ( $people[ $id ] ?? '' );
			if ( self::all_terms( $terms, $hay ) ) {
				$out[] = (int) $id;
			}
		}
		return $out;
	}

	/**
	 * Published records filtered by the query (may be empty) and ordered by title, equal titles by ID.
	 *
	 * @return int[]
	 */
	public static function sorted_ids( string $post_type, ?string $key, string $query = '' ): array {
		$ids   = '' === trim( $query ) ? null : array_flip( self::search_ids( $post_type, $query, $key ) );
		$items = array();
		foreach ( self::rows( $post_type ) as $id => $stored ) {
			if ( null !== $ids && ! isset( $ids[ $id ] ) ) {
				continue;
			}
			$items[] = array( (int) $id, self::plain( (int) $id, $stored, $key ) );
		}
		usort(
			$items,
			function ( $a, $b ) {
				$c = self::compare( $a[1], $b[1] );
				return 0 !== $c ? $c : $a[0] <=> $b[0];
			}
		);
		return array_map(
			function ( $i ) {
				return $i[0];
			},
			$items
		);
	}

	// ---------------------------------------------------------------------------
	// Writing
	// ---------------------------------------------------------------------------

	/**
	 * What to store for a title. The result goes to wp_insert_post() / wp_update_post() through wp_slash().
	 *
	 * - Gate closed (see enabled()), or no Vault at all: the plain text, no slug change.
	 * - A key: ciphertext (the stored one is kept when the text did not change: no needless rewrite) and a slug that says
	 *   nothing about the record: random on insert, `customer-ID` and the like once the ID is known. WordPress derives the
	 *   slug from the title, so a plain title must never reach wp_insert_post() without a post_name.
	 * - Vault set up but locked: the stored value, unchanged.
	 *
	 * @param string      $plain     The title typed.
	 * @param string      $stored    What the database holds now ('' for a new record).
	 * @param string|null $key       Master Key or null.
	 * @param string      $post_type Post type.
	 * @param int         $post_id   Record ID, 0 on insert.
	 * @return array{post_title:string,post_name?:string}
	 * @throws \RuntimeException When encryption fails (nothing may be stored then).
	 */
	public static function save_args( string $plain, string $stored, ?string $key, string $post_type, int $post_id = 0 ): array {
		if ( ! self::enabled() ) {
			return array( 'post_title' => (string) Fotonic_Field_Crypt::store( $plain, null ) );
		}
		if ( null === $key ) {
			if ( class_exists( 'Fotonic_Vault' ) && Fotonic_Vault::is_setup() ) {
				return array( 'post_title' => $stored ); // Locked: plain text is never stored, and an encrypted title is not overwritten.
			}
			return array( 'post_title' => (string) Fotonic_Field_Crypt::store( $plain, null ) );
		}
		if ( Fotonic_Field_Crypt::is_encrypted( $stored ) && Fotonic_Field_Crypt::read( $stored, $key ) === $plain ) {
			$title = $stored;
		} else {
			$title = Fotonic_Field_Crypt::store( $plain, $key );
			if ( false === $title ) {
				throw new \RuntimeException( 'Eleva CRM: a title could not be encrypted, nothing was saved.' );
			}
		}
		$slug = $post_id > 0
			? Fotonic_Vault_Fields::neutral_slug( $post_type, $post_id )
			: substr( sanitize_key( str_replace( 'ftnc_', '', $post_type ) ), 0, 20 ) . '-' . strtolower( wp_generate_password( 10, false ) );
		return array(
			'post_title' => $title,
			'post_name'  => $slug,
		);
	}

	/**
	 * Insert a record whose title is $plain. Encrypts the title (when allowed and a key is at hand) and gives the post a slug that
	 * says nothing about it, so the plain title never reaches the database through the slug.
	 *
	 * @param array       $args  wp_insert_post() arguments without post_title.
	 * @param string      $plain The title.
	 * @param string|null $key   Master Key or null.
	 * @return int|\WP_Error
	 */
	public static function insert( array $args, string $plain, ?string $key ) {
		try {
			$title = self::save_args( $plain, '', $key, (string) ( $args['post_type'] ?? '' ) );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'ftnc_title_encrypt', $e->getMessage() );
		}
		if ( '' === $title['post_title'] && '' !== $plain ) { // Vault set up and locked: a plain title is never stored.
			return new \WP_Error( 'ftnc_vault_locked', 'The Vault is locked.', array( 'status' => 423 ) );
		}
		self::flush_cache();
		return wp_insert_post( wp_slash( array_merge( $args, $title ) ), true );
	}

	/**
	 * Change the title of a record. The stored ciphertext is kept when the text did not change. The post is saved with
	 * wp_update_post() as before (save hooks included) but WITHOUT touching the slug; a slug that still carries the old
	 * plain title is replaced afterwards by set_neutral_slug(), which does not leave the old slug in `_wp_old_slug`.
	 *
	 * @return true|\WP_Error
	 */
	public static function rename( int $post_id, string $plain, ?string $key ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return new \WP_Error( 'ftnc_title_missing', 'No such record.' );
		}
		try {
			$args = self::save_args( $plain, (string) get_post_field( 'post_title', $post_id, 'raw' ), $key, (string) $post->post_type, $post_id );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'ftnc_title_encrypt', $e->getMessage() );
		}
		if ( '' === $args['post_title'] && '' !== $plain ) {
			return new \WP_Error( 'ftnc_vault_locked', 'The Vault is locked.', array( 'status' => 423 ) );
		}
		if ( $args['post_title'] !== (string) get_post_field( 'post_title', $post_id, 'raw' ) ) {
			$res = wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_title' => $args['post_title'] ) ), true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		if ( isset( $args['post_name'] ) && $args['post_name'] !== (string) get_post_field( 'post_name', $post_id, 'raw' ) ) {
			self::set_neutral_slug( $post_id, (string) $post->post_type );
		}
		self::flush_cache();
		return true;
	}

	/**
	 * Give a published record its neutral slug without wp_update_post(): that call would remember the old slug in plain text
	 * (`_wp_old_slug`) and fire the save hooks.
	 */
	public static function set_neutral_slug( int $post_id, string $post_type ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- deliberately not wp_update_post(), see above.
		$wpdb->update( $wpdb->posts, array( 'post_name' => Fotonic_Vault_Fields::neutral_slug( $post_type, $post_id ) ), array( 'ID' => $post_id ) );
		clean_post_cache( $post_id );
		delete_post_meta( $post_id, '_wp_old_slug' );
	}
}
