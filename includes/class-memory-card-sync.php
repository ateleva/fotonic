<?php
/**
 * The state model of the memory cards (confirmed by the owner, 2026-11-20).
 *
 * A card is linked to ONE work at a time: its ACTIVE work, the last work it was added to (postmeta `_ftnc_active_work` on the card).
 * Older works that still list the card are history: saving them never changes the card. Statuses: free (Pronta), in_use, backed_up,
 * damaged. The two flags of a work (backup done, formatting done) are one pair for all its cards.
 *
 *  1. A free card added to a work becomes in_use and that work becomes its active work.
 *  2. When the flags of the ACTIVE work change on save, its cards follow them: none = in_use, backup = backed_up, backup + formatting =
 *     free (still listed and still linked, so unticking steps them back). Cards that are damaged are never moved.
 *  3. A status set from the card updates ONLY its active work: the flags are on only when ALL its non-damaged cards reached the step.
 *  4. A damaged card stays damaged when removed from the work or when the work is deleted; it does not count for the flags.
 *  5. A card removed from its active work, or the active work deleted: free (unless damaged), link cleared. Removed from an old work: nothing.
 *  6. Saving an old work changes its flags but never the card.
 *  7. A deleted work restored from the Bin does not re-link its cards (the link was cleared at deletion).
 *
 * The decisions (`plan_work_save()`, `flags_from_cards()`, `target_for_flags()`) are pure; the rest reads and writes WordPress.
 *
 * @package Fotonic
 */

defined( 'ABSPATH' ) || exit;

final class Fotonic_Memory_Card_Sync {

	const META_ACTIVE = '_ftnc_active_work';

	// -------------------------------------------------------------------------
	// Pure decisions
	// -------------------------------------------------------------------------

	/** The status the cards of the active work follow from its two flags. */
	public static function target_for_flags( bool $backup, bool $format ): string {
		if ( $backup && $format ) {
			return 'free';
		}
		return $backup ? 'backed_up' : 'in_use';
	}

	/**
	 * Is this work the card's active work? A card in use with no link yet (older data) counts as active in the work being handled:
	 * the first save adopts it.
	 *
	 * @param array{status:string,active:int} $card
	 */
	public static function is_active_here( array $card, int $work_id ): bool {
		if ( (int) $card['active'] === $work_id ) {
			return true;
		}
		return 0 === (int) $card['active'] && in_array( $card['status'], array( 'in_use', 'backed_up' ), true );
	}

	/**
	 * What a work save does to its cards.
	 *
	 * @param array<int,array{status:string,active:int}> $states     State of every card involved (by card id).
	 * @param int[]                                      $prev_ids   Card ids the work listed before this save.
	 * @param int[]                                      $cur_ids    Card ids it lists now.
	 * @param array{backup:bool,format:bool}             $flags      Flags as saved now.
	 * @param array{backup:bool,format:bool}             $prev_flags Flags before this save.
	 * @return array{cards:array<int,array{status:string,active:int}>,flags_off:bool} New state of the cards that change; whether the flags must be switched off.
	 */
	public static function plan_work_save( array $states, array $prev_ids, array $cur_ids, array $flags, array $prev_flags, int $work_id ): array {
		$out           = array();
		$flags_changed = $flags['backup'] !== $prev_flags['backup'] || $flags['format'] !== $prev_flags['format'];
		$added_open    = false;

		foreach ( array_diff( $prev_ids, $cur_ids ) as $id ) {
			if ( ! isset( $states[ $id ] ) || ! self::is_active_here( $states[ $id ], $work_id ) ) {
				continue;
			}
			$status       = 'damaged' === $states[ $id ]['status'] ? 'damaged' : 'free';
			$out[ $id ]   = array( 'status' => $status, 'active' => 0 );
		}

		foreach ( $cur_ids as $id ) {
			if ( ! isset( $states[ $id ] ) ) {
				continue;
			}
			$card = $states[ $id ];
			if ( ! in_array( $id, $prev_ids, true ) ) {
				// Added now: only a free card can be taken (the picker offers no other).
				if ( 'free' === $card['status'] ) {
					$out[ $id ] = array( 'status' => 'in_use', 'active' => $work_id );
					$added_open = true;
				}
				continue;
			}
			if ( $flags_changed && 'damaged' !== $card['status'] && self::is_active_here( $card, $work_id ) ) {
				$out[ $id ] = array( 'status' => self::target_for_flags( $flags['backup'], $flags['format'] ), 'active' => $work_id );
			}
		}

		// A fresh card in a work marked as finished reopens it: the flags are on only when every card reached the step.
		return array(
			'cards'     => $out,
			'flags_off' => $added_open && ( $flags['backup'] || $flags['format'] ),
		);
	}

	/**
	 * The flags a work shows from the statuses of its non-damaged cards that are active in it, or null when there are none.
	 *
	 * @param string[] $statuses
	 * @return array{backup:bool,format:bool}|null
	 */
	public static function flags_from_cards( array $statuses ): ?array {
		$statuses = array_values( array_filter( $statuses, static function ( $s ) {
			return 'damaged' !== $s;
		} ) );
		if ( ! $statuses ) {
			return null;
		}
		$backup = true;
		$format = true;
		foreach ( $statuses as $s ) {
			if ( ! in_array( $s, array( 'backed_up', 'free' ), true ) ) {
				$backup = false;
			}
			if ( 'free' !== $s ) {
				$format = false;
			}
		}
		return array( 'backup' => $backup, 'format' => $backup && $format );
	}

	// -------------------------------------------------------------------------
	// WordPress side
	// -------------------------------------------------------------------------

	/** @return int[] Card ids a work lists. */
	public static function card_ids_of_work( int $work_id ): array {
		$dec = json_decode( (string) get_post_meta( $work_id, '_ftnc_memory_cards', true ), true );
		if ( ! is_array( $dec ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( static function ( $c ) {
			return (int) ( is_array( $c ) ? ( $c['card_id'] ?? 0 ) : 0 );
		}, $dec ) ) ) );
	}

	/** @return array{status:string,active:int} */
	private static function state_of( int $card_id ): array {
		return array(
			'status' => Fotonic_Memory_Card_CPT::get_card_status( $card_id ),
			'active' => (int) get_post_meta( $card_id, self::META_ACTIVE, true ),
		);
	}

	private static function apply( int $card_id, array $new ): void {
		Fotonic_Memory_Card_CPT::set_card_status( $card_id, $new['status'] );
		if ( (int) $new['active'] > 0 ) {
			update_post_meta( $card_id, self::META_ACTIVE, (int) $new['active'] );
		} else {
			delete_post_meta( $card_id, self::META_ACTIVE );
		}
	}

	/** Flags as stored on a work. @return array{backup:bool,format:bool} */
	public static function flags_of_work( int $work_id ): array {
		return array(
			'backup' => (bool) get_post_meta( $work_id, '_ftnc_backup_done', true ),
			'format' => (bool) get_post_meta( $work_id, '_ftnc_formatting_done', true ),
		);
	}

	/**
	 * After a work is saved (hook `ftnc_after_save_work`).
	 *
	 * @param array<string,mixed> $body Request body plus `_prev_memory_cards` (raw JSON before the save) and `_prev_flags`.
	 */
	public static function on_work_saved( int $work_id, array $body = array() ): void {
		if ( ! class_exists( 'Fotonic_Memory_Card_CPT' ) ) {
			return;
		}
		$prev_ids   = self::ids_from_raw( $body['_prev_memory_cards'] ?? '' );
		$cur_ids    = self::card_ids_of_work( $work_id );
		$prev_flags = isset( $body['_prev_flags'] ) && is_array( $body['_prev_flags'] )
			? array( 'backup' => ! empty( $body['_prev_flags']['backup'] ), 'format' => ! empty( $body['_prev_flags']['format'] ) )
			: array( 'backup' => false, 'format' => false );
		$flags      = self::flags_of_work( $work_id );

		$states = array();
		foreach ( array_unique( array_merge( $prev_ids, $cur_ids ) ) as $id ) {
			$states[ $id ] = self::state_of( $id );
		}
		$plan = self::plan_work_save( $states, $prev_ids, $cur_ids, $flags, $prev_flags, $work_id );
		foreach ( $plan['cards'] as $id => $new ) {
			self::apply( $id, $new );
		}
		if ( $plan['flags_off'] ) {
			update_post_meta( $work_id, '_ftnc_backup_done', '0' );
			update_post_meta( $work_id, '_ftnc_formatting_done', '0' );
		}
	}

	/** The work is going to the Bin: its cards are released (a damaged card stays damaged), the link is cleared. */
	public static function on_work_deleted( int $work_id ): void {
		if ( ! class_exists( 'Fotonic_Memory_Card_CPT' ) ) {
			return;
		}
		foreach ( self::card_ids_of_work( $work_id ) as $id ) {
			$state = self::state_of( $id );
			if ( ! self::is_active_here( $state, $work_id ) ) {
				continue;
			}
			self::apply( $id, array( 'status' => 'damaged' === $state['status'] ? 'damaged' : 'free', 'active' => 0 ) );
		}
	}

	/**
	 * The status of a card was set by hand (card form): it updates ONLY its active work, whose flags are on only when every
	 * non-damaged card active in it reached the step. A card with no link yet (older data) is looked up in the works that list it.
	 */
	public static function on_card_status_set( int $card_id ): void {
		$work_id = self::active_work_of( $card_id );
		if ( $work_id <= 0 ) {
			return;
		}
		$statuses = array();
		foreach ( self::card_ids_of_work( $work_id ) as $id ) {
			$state = self::state_of( $id );
			if ( (int) $state['active'] === $work_id ) {
				$statuses[] = $state['status'];
			}
		}
		$flags = self::flags_from_cards( $statuses );
		if ( null === $flags ) {
			return;
		}
		$now = self::flags_of_work( $work_id );
		if ( $now['backup'] !== $flags['backup'] ) {
			update_post_meta( $work_id, '_ftnc_backup_done', $flags['backup'] ? '1' : '0' );
		}
		if ( $now['format'] !== $flags['format'] ) {
			update_post_meta( $work_id, '_ftnc_formatting_done', $flags['format'] ? '1' : '0' );
		}
	}

	/** The card's active work id (0 = none). A card in use with no link: the newest published work that lists it, adopted. */
	public static function active_work_of( int $card_id ): int {
		$work_id = (int) get_post_meta( $card_id, self::META_ACTIVE, true );
		if ( $work_id > 0 ) {
			return 'publish' === get_post_status( $work_id ) && in_array( $card_id, self::card_ids_of_work( $work_id ), true ) ? $work_id : 0;
		}
		if ( ! in_array( Fotonic_Memory_Card_CPT::get_card_status( $card_id ), array( 'in_use', 'backed_up' ), true ) ) {
			return 0;
		}
		$works = get_posts( array(
			'post_type'      => 'ftnc_work',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );
		foreach ( $works as $wid ) {
			if ( in_array( $card_id, self::card_ids_of_work( (int) $wid ), true ) ) {
				update_post_meta( $card_id, self::META_ACTIVE, (int) $wid );
				return (int) $wid;
			}
		}
		return 0;
	}

	/** @return int[] */
	private static function ids_from_raw( $raw ): array {
		$dec = json_decode( (string) $raw, true );
		if ( ! is_array( $dec ) ) {
			return array();
		}
		return array_values( array_unique( array_filter( array_map( static function ( $c ) {
			return (int) ( is_array( $c ) ? ( $c['card_id'] ?? 0 ) : 0 );
		}, $dec ) ) ) );
	}

	/** Filter `ftnc_backup_derived_meta`: the link is rebuilt on demand, so it is not backed up. */
	public static function derived_meta( $keys ): array {
		$keys   = is_array( $keys ) ? $keys : array();
		$keys[] = self::META_ACTIVE;
		$keys[] = Fotonic_Memory_Card_CPT::META_STATUS_CHANGED;
		return $keys;
	}
}
