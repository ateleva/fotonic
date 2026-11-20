<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

// The state model of the memory cards against the real database (1.8.x): the link to the active work, the two-way sync between a work
// and its cards, release on remove and delete. Cards and works are created here with a run tag and removed at the end; the real
// cards and works of the site never take part.

$run = 'mcs' . wp_generate_password( 6, false, false );
register_shutdown_function( function () use ( $run ) {
    foreach ( array( 'ftnc_work', 'ftnc_memory_card' ) as $type ) {
        foreach ( get_posts( array( 'post_type' => $type, 'post_status' => array( 'publish', 'trash', 'draft' ), 's' => $run, 'posts_per_page' => -1, 'fields' => 'ids', 'suppress_filters' => true ) ) as $id ) {
            wp_delete_post( $id, true );
        }
    }
} );

$card = function ( string $name, string $status = 'free' ) use ( $run ) {
    $id = wp_insert_post( wp_slash( array( 'post_type' => 'ftnc_memory_card', 'post_title' => "{$name} {$run}", 'post_status' => 'publish' ) ) );
    Fotonic_Memory_Card_CPT::set_card_status( $id, $status );
    return $id;
};
$work = function ( string $name, array $card_ids = array(), bool $backup = false, bool $format = false ) use ( $run ) {
    $id = wp_insert_post( wp_slash( array( 'post_type' => 'ftnc_work', 'post_status' => 'publish', 'post_title' => "{$name} {$run}" ) ) );
    Fotonic_Memory_Card_Sync_Test::set_cards( $id, $card_ids );
    update_post_meta( $id, '_ftnc_backup_done', $backup ? '1' : '0' );
    update_post_meta( $id, '_ftnc_formatting_done', $format ? '1' : '0' );
    return $id;
};
class Fotonic_Memory_Card_Sync_Test {
    public static function set_cards( int $work_id, array $card_ids ): void {
        update_post_meta( $work_id, '_ftnc_memory_cards', wp_slash( wp_json_encode( array_map( function ( $c ) {
            return array( 'card_id' => $c, 'notes' => '' );
        }, $card_ids ) ) ) );
    }
    /** Like the REST update: what was stored before, then the new list and flags, then the hook. */
    public static function save( int $work_id, array $card_ids, bool $backup, bool $format ): void {
        $prev       = get_post_meta( $work_id, '_ftnc_memory_cards', true );
        $prev_flags = Fotonic_Memory_Card_Sync::flags_of_work( $work_id );
        self::set_cards( $work_id, $card_ids );
        update_post_meta( $work_id, '_ftnc_backup_done', $backup ? '1' : '0' );
        update_post_meta( $work_id, '_ftnc_formatting_done', $format ? '1' : '0' );
        do_action( 'ftnc_after_save_work', $work_id, array( '_prev_memory_cards' => $prev, '_prev_flags' => $prev_flags ) );
    }
}
$st = function ( int $id ) { return Fotonic_Memory_Card_CPT::get_card_status( $id ); };
$flags = function ( int $w ) { $f = Fotonic_Memory_Card_Sync::flags_of_work( $w ); return ( $f['backup'] ? 'B' : '-' ) . ( $f['format'] ? 'F' : '-' ); };

// 1. adding two ready cards to a new work
$a = $card( 'A' ); $b = $card( 'B' ); $c = $card( 'C' );
$w1 = $work( 'W1' );
Fotonic_Memory_Card_Sync_Test::save( $w1, array( $a, $b ), false, false );
t_equals( 'in_use', $st( $a ), 'add: A in use' );
t_equals( (string) $w1, (string) get_post_meta( $a, '_ftnc_active_work', true ), 'add: W1 is the active work of A' );

// 2. flags in the active work: backup, then formatting; then unticking steps back
Fotonic_Memory_Card_Sync_Test::save( $w1, array( $a, $b ), true, false );
t_equals( 'backed_up', $st( $a ), 'backup ticked: A backed up' );
Fotonic_Memory_Card_Sync_Test::save( $w1, array( $a, $b ), true, true );
t_equals( 'free', $st( $a ), 'both ticked: A ready' );
t_equals( (string) $w1, (string) get_post_meta( $a, '_ftnc_active_work', true ), 'both ticked: A is still linked to W1' );
Fotonic_Memory_Card_Sync_Test::save( $w1, array( $a, $b ), true, false );
t_equals( 'backed_up', $st( $a ), 'formatting unticked: A back to backed up' );
Fotonic_Memory_Card_Sync_Test::save( $w1, array( $a, $b ), true, true );

// 3. an old work: A is ready, used again in W2; saving W1 must not change A
$w2 = $work( 'W2' );
Fotonic_Memory_Card_Sync_Test::save( $w2, array( $a ), false, false );
t_equals( 'in_use', $st( $a ), 'A taken by W2: in use' );
t_equals( (string) $w2, (string) get_post_meta( $a, '_ftnc_active_work', true ), 'A is now linked to W2' );
Fotonic_Memory_Card_Sync_Test::save( $w1, array( $a, $b ), false, false ); // untick everything in the OLD work
t_equals( 'in_use', $st( $a ), 'old work saved: A keeps its status' );
t_equals( 'in_use', $st( $b ), 'W1 is still the active work of B: unticking there steps B back' );

// 4. a status set from the card updates only its active work (all-cards rule)
$w3 = $work( 'W3' );
Fotonic_Memory_Card_Sync_Test::save( $w3, array( $c ), false, false );
$d = $card( 'D' );
Fotonic_Memory_Card_Sync_Test::save( $w3, array( $c, $d ), false, false );
Fotonic_Memory_Card_CPT::set_card_status( $c, 'backed_up' );
Fotonic_Memory_Card_Sync::on_card_status_set( $c );
t_equals( '--', $flags( $w3 ), 'one of two cards backed up: flags stay off' );
Fotonic_Memory_Card_CPT::set_card_status( $d, 'backed_up' );
Fotonic_Memory_Card_Sync::on_card_status_set( $d );
t_equals( 'B-', $flags( $w3 ), 'all cards backed up: backup ticked' );
Fotonic_Memory_Card_CPT::set_card_status( $c, 'free' );
Fotonic_Memory_Card_Sync::on_card_status_set( $c );
Fotonic_Memory_Card_CPT::set_card_status( $d, 'free' );
Fotonic_Memory_Card_Sync::on_card_status_set( $d );
t_equals( 'BF', $flags( $w3 ), 'all cards ready: both ticked' );
Fotonic_Memory_Card_CPT::set_card_status( $d, 'in_use' );
Fotonic_Memory_Card_Sync::on_card_status_set( $d );
t_equals( '--', $flags( $w3 ), 'one card goes back: flags off' );
Fotonic_Memory_Card_CPT::set_card_status( $d, 'damaged' );
Fotonic_Memory_Card_Sync::on_card_status_set( $d );
t_equals( 'BF', $flags( $w3 ), 'a damaged card does not count: the other card (ready) decides' );

// 5. remove a damaged card from its active work: stays damaged, unlinked; a normal one becomes ready
Fotonic_Memory_Card_Sync_Test::save( $w3, array(), true, true );
t_equals( 'damaged', $st( $d ), 'removed damaged card stays damaged' );
t_equals( '', (string) get_post_meta( $d, '_ftnc_active_work', true ), 'removed damaged card is unlinked' );
t_equals( 'free', $st( $c ), 'removed normal card is ready' );

// 6. deleting the active work releases its cards, a damaged one stays damaged
$e = $card( 'E' ); $f = $card( 'F' );
$w4 = $work( 'W4' );
Fotonic_Memory_Card_Sync_Test::save( $w4, array( $e, $f ), false, false );
Fotonic_Memory_Card_CPT::set_card_status( $f, 'damaged' );
Fotonic_Memory_Card_Sync::on_work_deleted( $w4 );
t_equals( 'free', $st( $e ), 'work deleted: E ready' );
t_equals( 'damaged', $st( $f ), 'work deleted: F stays damaged' );
t_equals( '', (string) get_post_meta( $e, '_ftnc_active_work', true ), 'work deleted: link cleared (a restore does not re-link)' );

// 7. the card list shows the work a card is active in
$map = new ReflectionMethod( 'Fotonic_Memory_Card_REST_API', 'build_card_to_work_map' );
$map->setAccessible( true );
$m = $map->invoke( null );
t_equals( $w2, $m[ $a ]['id'] ?? 0, 'list: A shows W2 (its active work), not W1' );

t_done();
