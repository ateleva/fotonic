<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Restore, step 3: tells whether a record of a backup is new, identical, different or a possible
 * duplicate of a record already on this site. Pure logic: no database, no HTTP. The WordPress side
 * (reading the site, the plan file, the route) is Fotonic_Restore_Preview.
 *
 * A record is first turned into a "view": its fields in plain text (personal fields decrypted in
 * memory with the Master Key of the side it comes from, never written anywhere), reduced to what is
 * content. The same function builds the view of a backup record (old Master Key, ids of other
 * records translated to this site) and of a site record (this site's key, or none without a Vault).
 *
 * Decision table (classify()):
 *  1. The backup uid is on the site: identical or different by content. Never new. (A uid found twice,
 *     or already claimed by another backup record: possible.)
 *  2. No uid hit: the strong key rules (email and phone of any person, title, work date + title +
 *     customer) look for candidates. One free candidate: identical or different by content. Several, or
 *     one already claimed by another backup record: possible. A candidate whose uid differs from the
 *     backup uid (both have one): possible, never identical.
 *  3. No strong hit: the weak keys (customer title + main person), skipping site records that have strong
 *     keys of their own when the backup record has some too (two people with the same name and different
 *     emails are two people). A hit is identical when the content is equal, possible otherwise.
 *  4. Nothing found: new.
 *  A record that points to a record decided as possible (a work whose customer is possible) is at most possible.
 *  A value that cannot be read on either side is never identical (the field is listed as unreadable).
 *
 * Rules come from the `identity` key of the registry dataset (Fotonic_Backup_Registry) and the filter
 * `ftnc_restore_identity_rules` (dataset key => rules), which Pro uses for its own datasets.
 */
class Fotonic_Restore_Matcher {

	const NEW_RECORD = 'new';
	const IDENTICAL  = 'identical';
	const DIFFERENT  = 'different';
	const POSSIBLE   = 'possible';

	/** What stands for a value that cannot be read inside a view. */
	const UNREADABLE = "\0unreadable";

	/** Longest value shown in a difference. */
	const DISPLAY_MAX = 300;

	/** A list (people, services, payments) is shown in words by the screen, which needs it whole to read it. */
	const DISPLAY_MAX_LIST = 6000;

	// ---------------------------------------------------------------------------
	// Rules
	// ---------------------------------------------------------------------------

	/**
	 * The identity rules of one dataset, with every part present.
	 *
	 * @param string $dataset_key Dataset key (the post type).
	 * @param array  $dataset     Registry definition of the dataset.
	 * @return array{strong:array,weak:array,ignore:string[],refs:array,row_ignore:array,handlers:array,after:string[]}
	 */
	public static function rules( string $dataset_key, array $dataset ): array {
		$base = array(
			'strong'     => array(),
			'weak'       => array(),
			'ignore'     => array(),
			'refs'       => array(),
			'row_ignore' => array(),
			// Pro (phase 12): meta key => array( 'view' => callable, 'write' => callable ) for a field the generic reference
			// engine cannot describe (rows with two kinds of ids, nested attachment lists). See view() and the writer.
			'handlers'   => array(),
			// Datasets that must be compared and written before this one even when no single field points to them.
			'after'      => array(),
		);
		$own = isset( $dataset['identity'] ) && is_array( $dataset['identity'] ) ? $dataset['identity'] : array();
		$all = apply_filters( 'ftnc_restore_identity_rules', array( $dataset_key => array_merge( $base, $own ) ) );
		$one   = is_array( $all ) && isset( $all[ $dataset_key ] ) && is_array( $all[ $dataset_key ] ) ? $all[ $dataset_key ] : array_merge( $base, $own );
		$rules = array_merge( $base, $one );
		// A dataset that declares no rules at all (a Pro dataset before its phase) still must not be added again by
		// every restore: its title is the weak key. Equal content is identical, anything else a possible duplicate.
		if ( empty( $rules['strong'] ) && empty( $rules['weak'] ) && in_array( 'title', (array) ( $dataset['supports'] ?? array() ), true ) ) {
			$rules['weak'] = array( 'title' );
		}
		return $rules;
	}

	/**
	 * The protected fields of one record type, from the Vault registry (the one list of every field that
	 * can be encrypted), so a field added there is understood here too.
	 *
	 * @return array{post:array<string,string>,meta:array<string,string>,rows:array<string,array<string,string>>}
	 */
	public static function field_specs( string $post_type ): array {
		$specs = array( 'post' => array(), 'meta' => array(), 'rows' => array() );
		foreach ( Fotonic_Vault_Fields::groups() as $group ) {
			if ( ( $group['post_type'] ?? '' ) !== $post_type ) {
				continue;
			}
			foreach ( (array) $group['fields'] as $field ) {
				$kind = $field['kind'] ?? '';
				if ( 'post' === $kind ) {
					$specs['post'][ (string) $field['field'] ] = (string) $field['format'];
				} elseif ( 'meta' === $kind ) {
					$specs['meta'][ (string) $field['meta'] ] = (string) $field['format'];
				} elseif ( 'rows' === $kind ) {
					$specs['rows'][ (string) $field['meta'] ] = isset( $specs['rows'][ (string) $field['meta'] ] )
						? array_merge( $specs['rows'][ (string) $field['meta'] ], (array) $field['cols'] )
						: (array) $field['cols'];
				}
			}
		}
		return $specs;
	}

	// ---------------------------------------------------------------------------
	// Reading one stored value
	// ---------------------------------------------------------------------------

	/** Same test as Fotonic_REST_API::looks_encrypted() (that class is too big to load in a unit test). */
	private static function looks_encrypted( string $value ): bool {
		if ( 0 === strncmp( $value, 'v1d:', 4 ) || 0 === strncmp( $value, 'v2:', 3 ) ) {
			return true;
		}
		if ( strlen( $value ) < 24 ) {
			return false;
		}
		$decoded = base64_decode( $value, true );
		if ( false === $decoded || base64_encode( $decoded ) !== $value ) {
			return false;
		}
		return strlen( $decoded ) >= 16;
	}

	/**
	 * The plain text of one stored value.
	 *
	 * @param string      $stored Stored value (plain, or ciphertext of any layer).
	 * @param string      $format 'f1', 'v1' or 'v1d' (how this field is protected).
	 * @param string|null $key    Master Key of the side the value comes from; null = a site without a Vault.
	 * @return string|null Plain text, or null when it cannot be read.
	 */
	public static function read_value( string $stored, string $format, ?string $key ): ?string {
		if ( '' === $stored ) {
			return '';
		}
		if ( 'f1' === $format ) {
			return Fotonic_Field_Crypt::read( $stored, $key );
		}
		if ( 0 === strncmp( $stored, 'v2:', 3 ) ) {
			return null; // The browser-side layer: never opened by the server.
		}
		if ( ! self::looks_encrypted( $stored ) ) {
			return $stored;
		}
		if ( null === $key ) {
			// No Vault on this site: a value is plain text, unless it carries the marker of ciphertext.
			return 0 === strncmp( $stored, 'v1d:', 4 ) ? null : $stored;
		}
		$plain = 0 === strncmp( $stored, 'v1d:', 4 ) ? Fotonic_Crypto::deterministic_decrypt( $stored, $key ) : Fotonic_Crypto::decrypt( $stored, $key );
		if ( '' !== $plain && mb_check_encoding( $plain, 'UTF-8' ) ) {
			return $plain;
		}
		// The key does not open it. Ciphertext has the CBC shape (IV plus whole blocks); a plain text that
		// merely looks like base64 does not.
		if ( 0 === strncmp( $stored, 'v1d:', 4 ) ) {
			return null;
		}
		$raw = base64_decode( $stored, true );
		return ( false !== $raw && strlen( $raw ) >= 32 && 0 === strlen( $raw ) % 16 ) ? null : $stored;
	}

	// ---------------------------------------------------------------------------
	// A record as a view
	// ---------------------------------------------------------------------------

	/**
	 * @param array       $record     Record in the shape of Fotonic_Backup_Archive (ID, post_status, post_title,
	 *                                post_content, meta, terms).
	 * @param array       $dataset    Registry definition of the dataset.
	 * @param array       $rules      From rules().
	 * @param array       $specs      From field_specs().
	 * @param string|null $key        Master Key that opens the personal fields of this record.
	 * @param array|null  $refmap     Backup side only: dataset key => ( backup id => text that stands for it on this
	 *                                site: the site id, 'new:<id>' or 'possible:<id>' ). Null = a site record.
	 * @param array|null  $exist      Site side only: dataset key => ( id => true ) of the records that exist on this
	 *                                site. A reference to a record that is gone counts as no reference, exactly as
	 *                                on the backup side, so the same site and backup never differ because of it.
	 * @return array{uid:string,fields:array,unreadable:string[],sensitive:string[],possible_dep:bool,title:?string}
	 */
	public static function view( array $record, array $dataset, array $rules, array $specs, ?string $key, ?array $refmap = null, ?array $exist = null ): array {
		$fields     = array();
		$unreadable = array();
		$sensitive  = array();
		$dep        = false;
		$meta       = isset( $record['meta'] ) && is_array( $record['meta'] ) ? $record['meta'] : array();
		$ignore     = array_merge( array( '_ftnc_uid' ), (array) $rules['ignore'] );

		$fields['post_status'] = (string) ( $record['post_status'] ?? '' );

		foreach ( array( 'title' => 'post_title', 'content' => 'post_content' ) as $support => $column ) {
			if ( ! in_array( $support, (array) ( $dataset['supports'] ?? array() ), true ) ) {
				continue;
			}
			$stored = isset( $record[ $column ] ) ? (string) $record[ $column ] : '';
			if ( isset( $specs['post'][ $column ] ) ) {
				$sensitive[] = $column;
				$plain       = self::read_value( $stored, $specs['post'][ $column ], $key );
				if ( null === $plain ) {
					$fields[ $column ] = self::UNREADABLE;
					$unreadable[]      = $column;
					continue;
				}
				$stored = $plain;
			}
			$fields[ $column ] = self::scalar( $stored );
		}

		foreach ( (array) ( $dataset['meta_keys'] ?? array() ) as $meta_key ) {
			if ( in_array( $meta_key, $ignore, true ) ) {
				continue;
			}
			$raw = $meta[ $meta_key ] ?? '';
			$raw = is_scalar( $raw ) ? (string) $raw : ( is_array( $raw ) ? wp_json_encode( $raw ) : '' );
			$ref = isset( $rules['refs'][ $meta_key ] ) ? $rules['refs'][ $meta_key ] : null;

			if ( isset( $rules['handlers'][ $meta_key ]['view'] ) && is_callable( $rules['handlers'][ $meta_key ]['view'] ) ) {
				$handled = call_user_func( $rules['handlers'][ $meta_key ]['view'], $raw, array( 'refmap' => $refmap, 'exist' => $exist, 'key' => $key ) );
				$fields[ $meta_key ] = is_array( $handled ) && array_key_exists( 'value', $handled ) ? $handled['value'] : '';
				if ( ! empty( $handled['dep'] ) ) {
					$dep = true;
				}
				if ( ! empty( $handled['unreadable'] ) ) {
					$unreadable[] = $meta_key;
				}
				continue;
			}

			if ( isset( $specs['rows'][ $meta_key ] ) || self::looks_like_json( $raw ) ) {
				$cols   = isset( $specs['rows'][ $meta_key ] ) ? $specs['rows'][ $meta_key ] : array();
				$drop   = isset( $rules['row_ignore'][ $meta_key ] ) ? (array) $rules['row_ignore'][ $meta_key ] : array();
				$result = self::rows_value( $raw, $cols, $key, $ref, $refmap, $exist, $drop, $dep );
				if ( isset( $specs['rows'][ $meta_key ] ) ) {
					$sensitive[] = $meta_key;
				}
				$fields[ $meta_key ] = $result['value'];
				if ( $result['unreadable'] ) {
					$unreadable[] = $meta_key;
				}
				continue;
			}

			if ( isset( $specs['meta'][ $meta_key ] ) ) {
				$sensitive[] = $meta_key;
				$plain       = self::read_value( $raw, $specs['meta'][ $meta_key ], $key );
				if ( null === $plain ) {
					$fields[ $meta_key ] = self::UNREADABLE;
					$unreadable[]        = $meta_key;
					continue;
				}
				$raw = $plain;
			}
			if ( null !== $ref && '' === $ref[1] ) {
				$raw = self::translate_ref( (int) $raw, (string) $ref[0], $refmap, $exist, $dep );
			}
			$fields[ $meta_key ] = self::scalar( $raw );
		}

		// Terms that are state (derived ones are recomputed by the plugin and never compared).
		foreach ( (array) ( $record['terms'] ?? array() ) as $taxonomy => $entries ) {
			$slugs = array();
			foreach ( (array) $entries as $entry ) {
				if ( is_array( $entry ) && isset( $entry['slug'] ) ) {
					$slugs[] = (string) $entry['slug'];
				}
			}
			sort( $slugs );
			$fields[ 'term:' . $taxonomy ] = $slugs;
		}

		$title = isset( $fields['post_title'] ) && self::UNREADABLE !== $fields['post_title'] ? (string) $fields['post_title'] : null;
		$uid   = isset( $meta['_ftnc_uid'] ) && is_string( $meta['_ftnc_uid'] ) ? strtolower( trim( $meta['_ftnc_uid'] ) ) : '';

		return array(
			'uid'          => $uid,
			'fields'       => $fields,
			'unreadable'   => array_values( array_unique( $unreadable ) ),
			'sensitive'    => array_values( array_unique( $sensitive ) ),
			'possible_dep' => $dep,
			'title'        => $title,
		);
	}

	private static function looks_like_json( string $raw ): bool {
		return '' !== $raw && ( '[' === $raw[0] || '{' === $raw[0] );
	}

	/** Text of a scalar, comparable: line ends unified, trimmed, numbers written one way ('1200' = '1200.00'). */
	public static function scalar( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? '1' : '0';
		}
		if ( null === $value ) {
			return '';
		}
		$text = trim( str_replace( "\r\n", "\n", (string) $value ) );
		// Only plain numbers: a value with a leading zero (a phone number, a postcode) is text and stays as typed.
		if ( '' !== $text && 1 === preg_match( '/^-?(0|[1-9][0-9]*)(\.[0-9]+)?$/', $text ) ) {
			$text = rtrim( rtrim( number_format( (float) $text, 6, '.', '' ), '0' ), '.' );
			if ( '' === $text || '-' === $text ) {
				$text = '0';
			}
		}
		return $text;
	}

	/**
	 * The text that stands, on this site, for a record id of the backup (or the site id itself for a site record).
	 * A reference to a record that does not exist (a backup record that is not in the backup, a site record that
	 * was emptied from the trash) is no reference at all: '' on both sides.
	 *
	 * @param array|null $refmap Null = site record.
	 * @param array|null $exist  Site records that exist, per dataset key; null = do not check.
	 * @param bool       $dep    Set to true when the target is only a possible match.
	 */
	public static function translate_ref( int $id, string $target, ?array $refmap, ?array $exist, bool &$dep ): string {
		if ( $id <= 0 ) {
			return '';
		}
		if ( null === $refmap ) {
			return ( null !== $exist && ! isset( $exist[ $target ][ $id ] ) ) ? '' : (string) $id;
		}
		if ( ! isset( $refmap[ $target ][ $id ] ) ) {
			return '';
		}
		$to = (string) $refmap[ $target ][ $id ];
		if ( 0 === strncmp( $to, 'possible:', 9 ) ) {
			$dep = true;
		}
		return $to;
	}

	/**
	 * A JSON list of rows (people, addresses, services of a work, installments...), decrypted and reduced to content.
	 *
	 * @return array{value:mixed,unreadable:bool}
	 */
	private static function rows_value( string $raw, array $cols, ?string $key, $ref, ?array $refmap, ?array $exist, array $drop, bool &$dep ): array {
		$unreadable = false;
		if ( '' === $raw ) {
			return array( 'value' => array(), 'unreadable' => false );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return array( 'value' => self::scalar( $raw ), 'unreadable' => false );
		}
		$out = array();
		foreach ( $decoded as $row ) {
			if ( is_numeric( $row ) ) {
				// Legacy list of bare attachment ids.
				$row = array( 'type' => 'attachment' );
			}
			if ( ! is_array( $row ) ) {
				$out[] = self::scalar( $row );
				continue;
			}
			unset( $row['ref'] ); // The position of the row when it was read, not content.
			foreach ( $drop as $column ) {
				unset( $row[ $column ] );
			}
			foreach ( $cols as $column => $format ) {
				if ( ! isset( $row[ $column ] ) || ! is_string( $row[ $column ] ) ) {
					continue;
				}
				$plain = self::read_value( $row[ $column ], (string) $format, $key );
				if ( null === $plain ) {
					$row[ $column ] = self::UNREADABLE;
					$unreadable     = true;
				} else {
					$row[ $column ] = $plain;
				}
			}
			if ( is_array( $ref ) && '' !== $ref[1] && isset( $row[ $ref[1] ] ) ) {
				$row[ $ref[1] ] = self::translate_ref( (int) $row[ $ref[1] ], (string) $ref[0], $refmap, $exist, $dep );
			}
			$out[] = self::row_leafs( $row );
		}
		return array( 'value' => $out, 'unreadable' => $unreadable );
	}

	/** Leaf values of a row as comparable text; emails lower case, phones as digits. */
	private static function row_leafs( array $row ): array {
		$clean = array();
		foreach ( $row as $name => $value ) {
			if ( is_array( $value ) ) {
				$clean[ $name ] = self::row_leafs( $value );
				continue;
			}
			if ( self::UNREADABLE === $value ) {
				$clean[ $name ] = self::UNREADABLE;
				continue;
			}
			$text = self::scalar( $value );
			if ( 'email' === $name ) {
				$text = mb_strtolower( $text );
			} elseif ( 'phone' === $name ) {
				$text = (string) preg_replace( '/[^0-9]/', '', $text );
			}
			$clean[ $name ] = $text;
		}
		return $clean;
	}

	// ---------------------------------------------------------------------------
	// Keys
	// ---------------------------------------------------------------------------

	private static function norm( string $text ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( $text ) ) );
	}

	/** @return array{strong:string[],weak:string[]} The match keys of a view. */
	public static function keys( array $rules, array $view ): array {
		return array(
			'strong' => self::run_rules( (array) $rules['strong'], $view ),
			'weak'   => self::run_rules( (array) $rules['weak'], $view ),
		);
	}

	private static function run_rules( array $list, array $view ): array {
		$keys = array();
		foreach ( $list as $rule ) {
			$found = array();
			if ( is_callable( $rule ) ) {
				$found = (array) call_user_func( $rule, $view );
			} elseif ( is_string( $rule ) ) {
				$found = self::builtin_rule( $rule, $view );
			}
			foreach ( $found as $k ) {
				if ( is_string( $k ) && '' !== $k ) {
					$keys[] = $k;
				}
			}
		}
		return array_values( array_unique( $keys ) );
	}

	private static function plain_field( array $view, string $name ): string {
		$v = $view['fields'][ $name ] ?? '';
		return is_string( $v ) && self::UNREADABLE !== $v ? $v : '';
	}

	/** Rows of a person field of a view, without the unreadable cells. */
	private static function people( array $view ): array {
		$rows = $view['fields']['_ftnc_people'] ?? array();
		return is_array( $rows ) ? array_values( array_filter( $rows, 'is_array' ) ) : array();
	}

	private static function builtin_rule( string $rule, array $view ): array {
		$out = array();
		switch ( $rule ) {
			case 'title':
				$t = self::norm( self::plain_field( $view, 'post_title' ) );
				if ( '' !== $t ) {
					$out[] = 'title:' . $t;
				}
				break;
			case 'emails':
				foreach ( self::people( $view ) as $p ) {
					$e = isset( $p['email'] ) && is_string( $p['email'] ) && self::UNREADABLE !== $p['email'] ? mb_strtolower( trim( $p['email'] ) ) : '';
					if ( '' !== $e ) {
						$out[] = 'email:' . $e;
					}
				}
				break;
			case 'phones':
				foreach ( self::people( $view ) as $p ) {
					$d = isset( $p['phone'] ) && is_string( $p['phone'] ) ? (string) preg_replace( '/[^0-9]/', '', $p['phone'] ) : '';
					if ( strlen( $d ) >= 6 ) {
						$out[] = 'phone:' . $d;
					}
				}
				break;
			case 'title_main_person':
				$people = self::people( $view );
				$main   = null;
				foreach ( $people as $p ) {
					if ( ! empty( $p['is_main'] ) && '0' !== $p['is_main'] ) {
						$main = $p;
						break;
					}
				}
				if ( null === $main && ! empty( $people ) ) {
					$main = $people[0];
				}
				$first = is_array( $main ) && isset( $main['first_name'] ) && is_string( $main['first_name'] ) && self::UNREADABLE !== $main['first_name'] ? $main['first_name'] : '';
				$last  = is_array( $main ) && isset( $main['last_name'] ) && is_string( $main['last_name'] ) && self::UNREADABLE !== $main['last_name'] ? $main['last_name'] : '';
				$title = self::plain_field( $view, 'post_title' );
				if ( '' !== $title || '' !== $first || '' !== $last ) {
					$out[] = 'tmp:' . self::norm( $title ) . '|' . self::norm( $first ) . '|' . self::norm( $last );
				}
				break;
			case 'date_title_customer':
				$date  = self::plain_field( $view, '_ftnc_event_date' );
				$title = self::norm( self::plain_field( $view, 'post_title' ) );
				$cust  = self::plain_field( $view, '_ftnc_customer_id' );
				if ( '' !== $date || '' !== $title ) {
					$out[] = 'dtc:' . $date . '|' . $title . '|' . $cust;
				}
				break;
		}
		return $out;
	}

	// ---------------------------------------------------------------------------
	// Site index
	// ---------------------------------------------------------------------------

	public static function new_index(): array {
		return array( 'uid' => array(), 'uid_of' => array(), 'strong' => array(), 'weak' => array(), 'has_strong' => array() );
	}

	/** Adds one site record (its view) to the index. Only keys are kept, not the view. */
	public static function index_add( array &$index, int $id, array $view, array $rules ): void {
		$index['uid_of'][ $id ] = $view['uid'];
		if ( '' !== $view['uid'] ) {
			$index['uid'][ $view['uid'] ][] = $id;
		}
		$keys = self::keys( $rules, $view );
		foreach ( $keys['strong'] as $k ) {
			$index['strong'][ $k ][] = $id;
		}
		foreach ( $keys['weak'] as $k ) {
			$index['weak'][ $k ][] = $id;
		}
		$index['has_strong'][ $id ] = ! empty( $keys['strong'] );
	}

	// ---------------------------------------------------------------------------
	// Decision
	// ---------------------------------------------------------------------------

	/**
	 * @param array    $rules   From rules().
	 * @param array    $bview   View of the backup record.
	 * @param array    $index   From new_index() / index_add().
	 * @param callable $load    function( int $site_id ): array  View of a site record.
	 * @param int[]    $claimed Site ids already taken by an earlier backup record.
	 * @return array{status:string,site_id:?int,candidates:int[],diff:array}
	 */
	public static function classify( array $rules, array $bview, array $index, callable $load, array $claimed = array() ): array {
		$result = self::decide( $rules, $bview, $index, $load, $claimed );
		if ( $bview['possible_dep'] && self::POSSIBLE !== $result['status'] ) {
			$result['status'] = self::POSSIBLE;
		}
		return $result;
	}

	private static function out( string $status, ?int $site_id = null, array $candidates = array(), array $diff = array() ): array {
		return array( 'status' => $status, 'site_id' => $site_id, 'candidates' => $candidates, 'diff' => $diff );
	}

	private static function decide( array $rules, array $bview, array $index, callable $load, array $claimed ): array {
		$uid = $bview['uid'];

		// 1. The same uid is on the site.
		if ( '' !== $uid && isset( $index['uid'][ $uid ] ) ) {
			$ids  = array_values( array_unique( $index['uid'][ $uid ] ) );
			$free = array_values( array_diff( $ids, $claimed ) );
			if ( 1 === count( $ids ) && 1 === count( $free ) ) {
				return self::compare( $bview, $load( $free[0] ), $free[0] );
			}
			return self::out( self::POSSIBLE, null, $ids );
		}

		// 2. Strong keys.
		$keys  = self::keys( $rules, $bview );
		$cands = array();
		foreach ( $keys['strong'] as $k ) {
			if ( isset( $index['strong'][ $k ] ) ) {
				$cands = array_merge( $cands, $index['strong'][ $k ] );
			}
		}
		$cands = array_values( array_unique( $cands ) );
		$weak  = false;

		// 3. Weak keys.
		if ( empty( $cands ) ) {
			foreach ( $keys['weak'] as $k ) {
				if ( isset( $index['weak'][ $k ] ) ) {
					$cands = array_merge( $cands, $index['weak'][ $k ] );
				}
			}
			$cands = array_values( array_unique( $cands ) );
			if ( ! empty( $keys['strong'] ) ) {
				// Both sides have contact details and none match: two different people with the same name.
				$cands = array_values( array_filter( $cands, function ( $id ) use ( $index ) {
					return empty( $index['has_strong'][ $id ] );
				} ) );
			}
			$weak = ! empty( $cands );
		}

		if ( empty( $cands ) ) {
			return self::out( self::NEW_RECORD );
		}

		$free = array_values( array_diff( $cands, $claimed ) );
		if ( count( $cands ) > 1 || 1 !== count( $free ) ) {
			return self::out( self::POSSIBLE, null, $cands );
		}
		$id       = $free[0];
		$site_uid = isset( $index['uid_of'][ $id ] ) ? (string) $index['uid_of'][ $id ] : '';
		if ( '' !== $uid && '' !== $site_uid && $uid !== $site_uid ) {
			return self::out( self::POSSIBLE, null, array( $id ) );
		}
		$cmp = self::compare( $bview, $load( $id ), $id );
		if ( $weak && self::IDENTICAL !== $cmp['status'] ) {
			$cmp['status']     = self::POSSIBLE;
			$cmp['candidates'] = array( $id );
			$cmp['site_id']    = null;
		}
		return $cmp;
	}

	/** Field by field comparison of two views. */
	public static function compare( array $bview, array $sview, int $site_id ): array {
		$names = array_values( array_unique( array_merge( array_keys( $bview['fields'] ), array_keys( $sview['fields'] ) ) ) );
		$diff  = array();
		foreach ( $names as $name ) {
			$b  = $bview['fields'][ $name ] ?? '';
			$s  = $sview['fields'][ $name ] ?? '';
			$ub = in_array( $name, $bview['unreadable'], true );
			$us = in_array( $name, $sview['unreadable'], true );
			if ( ! $ub && ! $us && self::canonical( $b ) === self::canonical( $s ) ) {
				continue;
			}
			$entry = array(
				'field'    => $name,
				'personal' => in_array( $name, $bview['sensitive'], true ) || in_array( $name, $sview['sensitive'], true ),
				'backup'   => $ub ? null : self::display( $b ),
				'site'     => $us ? null : self::display( $s ),
			);
			if ( $ub || $us ) {
				$entry['unreadable'] = ( $ub && $us ) ? 'both' : ( $ub ? 'backup' : 'site' );
			}
			$diff[] = $entry;
		}
		return self::out( empty( $diff ) ? self::IDENTICAL : self::DIFFERENT, $site_id, array(), $diff );
	}

	/** A value as one comparable string (lists keep their order, named keys do not). */
	private static function canonical( $value ): string {
		// Nothing is nothing: '', null, an empty list and an empty object are one value (an older backup stores
		// '' where a newer site stores '[]').
		if ( null === $value || '' === $value || array() === $value ) {
			return '';
		}
		return (string) wp_json_encode( self::sorted( $value ) );
	}

	private static function sorted( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		$out     = array();
		foreach ( $value as $k => $v ) {
			$out[ $k ] = self::sorted( $v );
		}
		if ( ! $is_list ) {
			ksort( $out );
		}
		return $out;
	}

	private static function display( $value ): string {
		$text = is_array( $value ) ? (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : (string) $value;
		$max  = ( '' !== $text && ( '[' === $text[0] || '{' === $text[0] ) ) ? self::DISPLAY_MAX_LIST : self::DISPLAY_MAX;
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) . '...' : $text;
	}
}
