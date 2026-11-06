<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Restore: the map from the id a record had in the backup to the id it has on this site.
 *
 * Kinds are dataset keys (`ftnc_customer`, `ftnc_service`, ...) plus `attachment` (phase 10). A record the
 * writer created or matched is `set()`; a reference that points to a record the map does not know is
 * written as "no reference", never as the raw backup id (that id could belong to an unrelated record here).
 *
 * The map lives in a file of the private session folder (one JSON line per entry, appended as records are
 * written), so a job that stops can load it again and go on. Ids only, no personal data.
 */
final class Fotonic_Restore_Idmap {

	/** @var array<string,array<int,int>> */
	private $map = array();

	/** @var array<int,array{0:string,1:int,2:int}> Entries not yet appended to the file. */
	private $pending = array();

	public function set( string $kind, int $old, int $new ): void {
		if ( $old <= 0 || $new <= 0 ) {
			return;
		}
		if ( isset( $this->map[ $kind ][ $old ] ) && $this->map[ $kind ][ $old ] === $new ) {
			return;
		}
		$this->map[ $kind ][ $old ] = $new;
		$this->pending[]            = array( $kind, $old, $new );
	}

	public function get( string $kind, int $old ): ?int {
		return $this->map[ $kind ][ $old ] ?? null;
	}

	public function count( string $kind ): int {
		return isset( $this->map[ $kind ] ) ? count( $this->map[ $kind ] ) : 0;
	}

	/** Load the entries written so far (a missing file is an empty map). */
	public static function load( string $path ): self {
		$map = new self();
		if ( ! is_file( $path ) ) {
			return $map;
		}
		$fh = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $fh ) {
			return $map;
		}
		while ( false !== ( $line = fgets( $fh ) ) ) {
			$row = json_decode( trim( $line ), true );
			if ( is_array( $row ) && 3 === count( $row ) && is_string( $row[0] ) ) {
				$map->map[ $row[0] ][ (int) $row[1] ] = (int) $row[2];
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $map;
	}

	/** Append the entries added since the last flush. */
	public function flush( string $path ): bool {
		if ( empty( $this->pending ) ) {
			return true;
		}
		$text = '';
		foreach ( $this->pending as $row ) {
			$text .= wp_json_encode( $row ) . "\n";
		}
		$ok = false !== file_put_contents( $path, $text, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( $ok ) {
			$this->pending = array();
		}
		return $ok;
	}
}
