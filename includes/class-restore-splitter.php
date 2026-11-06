<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Splits the decrypted payload of a backup (data.enc once opened) into one file per collection,
 * without ever holding the whole document in memory.
 *
 * The payload is one JSON object written by Fotonic_Backup_Archive::write_data_json_stream():
 *
 *   { "format": 1, "generated_at": "...",
 *     "datasets":    { "<dataset>": [ <record>, ... ], ... },
 *     "terms":       { "<taxonomy>": [ <term>, ... ], ... },
 *     "options":     { ... },
 *     "attachments": [ <attachment>, ... ] }
 *
 * PHP has no streaming JSON parser, and json_decode() of a large payload needs several times its
 * size in memory, so this class walks the text with a small state machine. It understands only the
 * structure above: it tracks strings, escapes and brackets to find where each record starts and
 * ends, then hands that one record's text to json_decode(). Anything it does not recognise at the
 * top levels is skipped. The payload is untrusted (it comes from an uploaded file), so every size
 * is capped and a malformed document ends in an error, never in a partial result.
 *
 * Output, per collection (a dataset, a taxonomy, or the attachments):
 *   c<N>.jsonl   one line per record: the line encoder's output for the record's JSON text
 *   c<N>.idx     one 8-byte big-endian offset per record, the byte where its line starts in c<N>.jsonl
 * The encoder is how the caller keeps the temporary files unreadable at rest: Fotonic_Restore_Reader
 * passes a function that seals each record with the key of the restore session. Collection names
 * come from the archive and are never used as file names: files are numbered.
 */
final class Fotonic_Restore_Splitter {

	const MAX_RECORD      = 33554432; // 32 MB for one record.
	const MAX_META        = 8388608;  // 8 MB for the options object and other top-level values.
	const MAX_KEY         = 65536;    // Longest dataset name accepted.
	const MAX_COLLECTIONS = 200;
	const MAX_RECORDS     = 5000000;

	/** @var int Bytes read per block. Public only so the unit test can use tiny blocks and cross every boundary. */
	public static $read_size = 262144;

	/** @var resource */
	private $fh;
	/** @var string */
	private $buf = '';
	/** @var int */
	private $pos = 0;
	/** @var int */
	private $len = 0;
	/** @var string */
	private $dir;
	/** @var callable */
	private $encode;
	/** @var array<string,array> Open collections keyed by id. */
	private $collections = array();
	/** @var int */
	private $records = 0;
	/** @var array */
	private $meta = array(
		'format'       => null,
		'generated_at' => '',
		'options'      => array(),
	);

	/**
	 * @param string   $json_path Decrypted payload on disk.
	 * @param string   $out_dir   Existing private directory that receives the collection files.
	 * @param callable $encode    function ( string $record_json ): string, one line without line breaks.
	 * @return array|\WP_Error array( 'format', 'generated_at', 'options', 'collections' => list of
	 *                         array( 'id', 'kind', 'key', 'file', 'count' ) ).
	 */
	public static function split( string $json_path, string $out_dir, callable $encode ) {
		$fh = is_readable( $json_path ) ? fopen( $json_path, 'rb' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- own temp file, streamed.
		if ( false === $fh ) {
			return new \WP_Error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ) );
		}
		$self         = new self();
		$self->fh     = $fh;
		$self->dir    = rtrim( $out_dir, '/' );
		$self->encode = $encode;
		try {
			$self->run();
			$result = $self->result();
		} catch ( \RuntimeException $e ) {
			$self->close_all();
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'ftnc_restore_damaged', __( 'The backup could not be read. It may be damaged.', 'eleva-crm-for-photographers' ), array( 'detail' => $e->getMessage() ) );
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $result;
	}

	private function __construct() {}

	// ---------------------------------------------------------------------------
	// Structure walk
	// ---------------------------------------------------------------------------

	private function run(): void {
		$expect = 'top';
		$stack  = array(); // Each entry: array( 't' => 'o'|'a', 'key' => last key read in that object ).

		while ( true ) {
			if ( $this->pos >= $this->len && ! $this->fill() ) {
				break;
			}
			$skip = strspn( $this->buf, " \t\r\n", $this->pos );
			if ( $skip > 0 ) {
				$this->pos += $skip;
				continue;
			}
			$c   = $this->buf[ $this->pos ];
			$top = count( $stack ) - 1;

			if ( 'done' === $expect ) {
				throw new \RuntimeException( 'Unexpected content after the end of the document.' );
			}

			switch ( $expect ) {
				case 'top':
					if ( '{' !== $c ) {
						throw new \RuntimeException( 'The document is not a JSON object.' );
					}
					$this->pos++;
					$stack[] = array( 't' => 'o', 'key' => '' );
					$expect  = 'key_or_end';
					break;

				case 'key_or_end':
					if ( '}' === $c ) {
						$this->pos++;
						$expect = $this->after_pop( $stack );
						break;
					}
					// A key follows: same handling as 'key'.
				case 'key':
					if ( '"' !== $c ) {
						throw new \RuntimeException( 'An object key was expected.' );
					}
					$this->pos++;
					$stack[ count( $stack ) - 1 ]['key'] = $this->read_key();
					$expect                              = 'colon';
					break;

				case 'colon':
					if ( ':' !== $c ) {
						throw new \RuntimeException( 'A colon was expected.' );
					}
					$this->pos++;
					$expect = 'value';
					break;

				case 'value_or_end':
					if ( ']' === $c ) {
						$this->pos++;
						$expect = $this->after_pop( $stack );
						break;
					}
					// A value follows: same handling as 'value'.
				case 'value':
					$action = $this->decide( $stack, $c );
					if ( 'descend_o' === $action ) {
						$this->pos++;
						$stack[] = array( 't' => 'o', 'key' => '' );
						$expect  = 'key_or_end';
					} elseif ( 'descend_a' === $action || 'descend_att' === $action ) {
						$this->pos++;
						if ( 'descend_att' === $action ) {
							$this->collection( 'attachments', 'attachments', '' );
						} else {
							$this->collection( $stack[0]['key'] . ':' . $stack[ count( $stack ) - 1 ]['key'], $stack[0]['key'], $stack[ count( $stack ) - 1 ]['key'] );
						}
						$stack[] = array( 't' => 'a', 'key' => '' );
						$expect  = 'value_or_end';
					} elseif ( 0 === strpos( $action, 'rec:' ) ) {
						$raw = $this->capture( true, self::MAX_RECORD );
						$id  = substr( $action, 4 );
						$this->emit( $id, $raw );
						$expect = 'comma';
					} elseif ( 'meta' === $action ) {
						$raw = $this->capture( true, self::MAX_META );
						$this->set_meta( $stack[ $top ]['key'], $raw );
						$expect = 'comma';
					} else {
						$this->capture( false, 0 );
						$expect = 'comma';
					}
					break;

				case 'comma':
					if ( ',' === $c ) {
						$this->pos++;
						$expect = ( 'o' === $stack[ $top ]['t'] ) ? 'key' : 'value';
					} elseif ( ( '}' === $c && 'o' === $stack[ $top ]['t'] ) || ( ']' === $c && 'a' === $stack[ $top ]['t'] ) ) {
						$this->pos++;
						$expect = $this->after_pop( $stack );
					} else {
						throw new \RuntimeException( 'A comma or a closing bracket was expected.' );
					}
					break;
			}
		}

		if ( 'done' !== $expect ) {
			throw new \RuntimeException( 'The document ends too early.' );
		}
		if ( ! is_int( $this->meta['format'] ) ) {
			throw new \RuntimeException( 'The document has no format number.' );
		}
	}

	/** Pops one container; returns the next expectation. */
	private function after_pop( array &$stack ): string {
		array_pop( $stack );
		return empty( $stack ) ? 'done' : 'comma';
	}

	/**
	 * What to do with the value that starts at the current byte.
	 *
	 * @return string 'descend_o', 'descend_a', 'descend_att', 'rec:<collection id>', 'meta' or 'skip'.
	 */
	private function decide( array $stack, string $c ): string {
		$depth = count( $stack );
		$top   = $stack[ $depth - 1 ];

		if ( 1 === $depth ) {
			switch ( $top['key'] ) {
				case 'format':
				case 'generated_at':
				case 'options':
					return 'meta';
				case 'datasets':
				case 'terms':
					return '{' === $c ? 'descend_o' : 'skip';
				case 'attachments':
					return '[' === $c ? 'descend_att' : 'skip';
			}
			return 'skip';
		}

		$section = $stack[0]['key'];
		if ( 2 === $depth && ( 'datasets' === $section || 'terms' === $section ) && 'o' === $top['t'] ) {
			return '[' === $c ? 'descend_a' : 'skip';
		}
		if ( 2 === $depth && 'attachments' === $section && 'a' === $top['t'] ) {
			return 'rec:attachments';
		}
		if ( 3 === $depth && ( 'datasets' === $section || 'terms' === $section ) && 'o' === $stack[1]['t'] && 'a' === $top['t'] ) {
			return 'rec:' . $section . ':' . $stack[1]['key'];
		}
		return 'skip';
	}

	// ---------------------------------------------------------------------------
	// Reading
	// ---------------------------------------------------------------------------

	/** Reads the next block of the file; false at the end. */
	private function fill(): bool {
		if ( feof( $this->fh ) ) {
			return false;
		}
		$chunk = fread( $this->fh, max( 1, self::$read_size ) );
		if ( false === $chunk || '' === $chunk ) {
			return false;
		}
		$this->buf = $chunk;
		$this->pos = 0;
		$this->len = strlen( $chunk );
		return true;
	}

	/** Reads an object key (the opening quote is already consumed). */
	private function read_key(): string {
		$raw = '';
		$esc = false;
		while ( true ) {
			if ( $this->pos >= $this->len && ! $this->fill() ) {
				throw new \RuntimeException( 'A key is not closed.' );
			}
			if ( $esc ) {
				$raw .= $this->buf[ $this->pos ];
				$this->pos++;
				$esc = false;
				continue;
			}
			$n    = strcspn( $this->buf, '"\\', $this->pos );
			$raw .= substr( $this->buf, $this->pos, $n );
			$this->pos += $n;
			if ( strlen( $raw ) > self::MAX_KEY ) {
				throw new \RuntimeException( 'A key is too long.' );
			}
			if ( $this->pos >= $this->len ) {
				continue;
			}
			$ch = $this->buf[ $this->pos ];
			$this->pos++;
			if ( '\\' === $ch ) {
				$raw .= $ch;
				$esc  = true;
				continue;
			}
			$key = json_decode( '"' . $raw . '"' );
			if ( ! is_string( $key ) ) {
				throw new \RuntimeException( 'A key is not valid text.' );
			}
			return $key;
		}
	}

	/**
	 * Reads one whole value starting at the current byte (string, object, array or scalar).
	 *
	 * @param bool $buffer Keep the text and return it; false only walks past it.
	 * @param int  $limit  Longest text kept.
	 * @return string The value's JSON text ('' when not buffered).
	 */
	private function capture( bool $buffer, int $limit ): string {
		$out    = '';
		$depth  = 0;
		$in_str = false;
		$esc    = false;
		$begun  = false;
		$scalar = false;

		while ( true ) {
			if ( $this->pos >= $this->len && ! $this->fill() ) {
				if ( $scalar ) {
					return $out;
				}
				throw new \RuntimeException( 'A value is not closed.' );
			}
			$start = $this->pos;

			if ( $in_str ) {
				if ( $esc ) {
					$this->pos++;
					$esc = false;
				} else {
					$this->pos += strcspn( $this->buf, '"\\', $this->pos );
					if ( $this->pos < $this->len ) {
						if ( '\\' === $this->buf[ $this->pos ] ) {
							$esc = true;
						} else {
							$in_str = false;
						}
						$this->pos++;
					}
				}
				$done = ( ! $in_str && 0 === $depth );
			} elseif ( ! $begun ) {
				$begun = true;
				$ch    = $this->buf[ $this->pos ];
				if ( '"' === $ch ) {
					$in_str = true;
					$this->pos++;
				} elseif ( '{' === $ch || '[' === $ch ) {
					$depth = 1;
					$this->pos++;
				} else {
					$scalar = true;
					$this->pos += strcspn( $this->buf, ",}] \t\r\n", $this->pos );
				}
				$done = $scalar && $this->pos < $this->len;
			} elseif ( $scalar ) {
				$this->pos += strcspn( $this->buf, ",}] \t\r\n", $this->pos );
				$done       = $this->pos < $this->len;
			} else {
				$this->pos += strcspn( $this->buf, '"{}[]', $this->pos );
				$done       = false;
				if ( $this->pos < $this->len ) {
					$ch = $this->buf[ $this->pos ];
					$this->pos++;
					if ( '"' === $ch ) {
						$in_str = true;
					} elseif ( '{' === $ch || '[' === $ch ) {
						$depth++;
					} else {
						$depth--;
						$done = ( 0 === $depth );
					}
				}
			}

			if ( $buffer ) {
				$out .= substr( $this->buf, $start, $this->pos - $start );
				if ( strlen( $out ) > $limit ) {
					throw new \RuntimeException( 'A value is too large.' );
				}
			}
			if ( $done ) {
				return $out;
			}
		}
	}

	// ---------------------------------------------------------------------------
	// Output
	// ---------------------------------------------------------------------------

	private function set_meta( string $key, string $raw ): void {
		$value = json_decode( $raw, true );
		if ( 'format' === $key ) {
			if ( ! is_int( $value ) ) {
				throw new \RuntimeException( 'The format number is not an integer.' );
			}
			$this->meta['format'] = $value;
		} elseif ( 'generated_at' === $key ) {
			$this->meta['generated_at'] = is_string( $value ) ? $value : '';
		} elseif ( 'options' === $key ) {
			if ( ! is_array( $value ) ) {
				throw new \RuntimeException( 'The options are not an object.' );
			}
			$this->meta['options'] = $value;
		}
	}

	/** Registers a collection (so an empty one still shows up) and returns its entry. */
	private function collection( string $id, string $kind, string $key ): array {
		if ( isset( $this->collections[ $id ] ) ) {
			return $this->collections[ $id ];
		}
		if ( count( $this->collections ) >= self::MAX_COLLECTIONS ) {
			throw new \RuntimeException( 'Too many collections.' );
		}
		$file = 'c' . count( $this->collections );
		$j    = fopen( $this->dir . '/' . $file . '.jsonl', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- own private folder.
		$i    = fopen( $this->dir . '/' . $file . '.idx', 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- own private folder.
		if ( false === $j || false === $i ) {
			throw new \RuntimeException( 'Could not create a working file.' );
		}
		$this->collections[ $id ] = array(
			'id'    => $id,
			'kind'  => $kind,
			'key'   => $key,
			'file'  => $file,
			'count' => 0,
			'bytes' => 0,
			'j'     => $j,
			'i'     => $i,
		);
		return $this->collections[ $id ];
	}

	/** Checks one record's text, encodes it and appends it to its collection. */
	private function emit( string $id, string $raw ): void {
		if ( ++$this->records > self::MAX_RECORDS ) {
			throw new \RuntimeException( 'Too many records.' );
		}
		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'A record is not a JSON object.' );
		}
		$line = call_user_func( $this->encode, $raw );
		if ( ! is_string( $line ) || '' === $line || false !== strpbrk( $line, "\r\n" ) ) {
			throw new \RuntimeException( 'The record encoder returned an unusable line.' );
		}
		$c = &$this->collections[ $id ];
		fwrite( $c['i'], pack( 'J', $c['bytes'] ) );
		fwrite( $c['j'], $line . "\n" );
		$c['bytes'] += strlen( $line ) + 1;
		$c['count']++;
	}

	private function close_all(): void {
		foreach ( $this->collections as $c ) {
			if ( is_resource( $c['j'] ) ) {
				fclose( $c['j'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
			if ( is_resource( $c['i'] ) ) {
				fclose( $c['i'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			}
		}
	}

	private function result(): array {
		$this->close_all();
		$list = array();
		foreach ( $this->collections as $c ) {
			$list[] = array(
				'id'    => $c['id'],
				'kind'  => $c['kind'],
				'key'   => $c['key'],
				'file'  => $c['file'],
				'count' => $c['count'],
			);
		}
		return array(
			'format'       => $this->meta['format'],
			'generated_at' => $this->meta['generated_at'],
			'options'      => $this->meta['options'],
			'collections'  => $list,
		);
	}
}
