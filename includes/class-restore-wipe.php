<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Restore, "replace everything" (phase 10): removes the plugin's records so the backup can be restored in their place.
 *
 * Only the post types the restore can write are touched (`Fotonic_Restore_Writer::post_types()`): never another
 * plugin's posts, never users, and never the files of the media library or the terms. Every status goes, the bin
 * included. Nothing fires a delete hook (see Fotonic_Restore_Repo::wipe_batch()).
 *
 * A total restore is refused while the site holds records of a dataset this version cannot restore (Pro's, until
 * phase 12): wiping only the free half would leave quotes and expenses pointing at records that no longer exist.
 *
 * The person confirms by typing a fixed word. The server owns the word (the screen reads it from the server), so
 * the language of the request never changes what is expected.
 */
final class Fotonic_Restore_Wipe {

	const WORD = 'REPLACE';

	public static function confirm_word(): string {
		return self::WORD;
	}

	public static function confirmed( $typed ): bool {
		return is_string( $typed ) && hash_equals( self::WORD, strtoupper( trim( $typed ) ) );
	}

	/**
	 * Data of this plugin family on the site that this version of the restore cannot write back. Two sources: the datasets
	 * of the registry (named), and a raw count of `ftnc_*` records of any other type, which also sees Pro's data when
	 * Pro is switched off (its types are not registered then, so no dataset lists them).
	 *
	 * @return array<int,array{dataset:string,label:string,count:int}>
	 */
	public static function blocking(): array {
		$repo     = Fotonic_Restore_Writer::repo();
		$writable = Fotonic_Restore_Writer::writable_datasets();
		$out      = array();
		$named    = 0;
		foreach ( Fotonic_Backup_Registry::datasets() as $key => $dataset ) {
			if ( in_array( $key, $writable, true ) || empty( $dataset['post_type'] ) ) {
				continue;
			}
			$count = $repo->count_records( array( (string) $dataset['post_type'] ) );
			if ( $count > 0 ) {
				$out[]  = array( 'dataset' => (string) $key, 'label' => (string) ( $dataset['label'] ?? $key ), 'count' => $count );
				$named += $count;
			}
		}
		$all = $repo->count_unknown_records( Fotonic_Restore_Writer::post_types() );
		if ( $all > $named ) {
			$out[] = array( 'dataset' => 'other', 'label' => __( 'Other data of Eleva CRM', 'eleva-crm-for-photographers' ), 'count' => $all - $named );
		}
		return $out;
	}

	/**
	 * One batch of the wipe. Records that carry the label of `$keep_batch` (what the restore already wrote) stay, so a
	 * wipe that is resumed never removes freshly restored records.
	 *
	 * @return array{deleted:int,remaining:int,error:bool}
	 */
	public static function step( string $keep_batch, int $size = 200 ): array {
		return Fotonic_Restore_Writer::repo()->wipe_batch( Fotonic_Restore_Writer::post_types(), $keep_batch, $size );
	}

	/**
	 * Everything at once (tests and tools). The restore job uses step() in batches.
	 *
	 * @return int|\WP_Error Records removed.
	 */
	public static function run( string $confirm ) {
		if ( ! self::confirmed( $confirm ) ) {
			return new \WP_Error( 'ftnc_restore_confirm_needed', __( 'Type the confirmation word exactly to replace everything.', 'eleva-crm-for-photographers' ), array( 'status' => 422 ) );
		}
		$total = 0;
		for ( $i = 0; $i < 100000; $i++ ) {
			$r      = self::step( '', 500 );
			if ( ! empty( $r['error'] ) ) {
				return new \WP_Error( 'ftnc_restore_wipe_failed', __( 'Some records could not be removed.', 'eleva-crm-for-photographers' ), array( 'status' => 500 ) );
			}
			$total += $r['deleted'];
			if ( 0 === $r['remaining'] || 0 === $r['deleted'] ) {
				break;
			}
		}
		return $total;
	}
}
