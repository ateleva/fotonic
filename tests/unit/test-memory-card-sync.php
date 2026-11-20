<?php
// Unit test for the pure decisions of Fotonic_Memory_Card_Sync (the state model of the memory cards). No WordPress, no database.
ob_start();
require __DIR__ . '/../lib/assert.php';
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../includes/class-memory-card-sync.php';

$S = 'Fotonic_Memory_Card_Sync';
$none = array( 'backup' => false, 'format' => false );
$b    = array( 'backup' => true, 'format' => false );
$bf   = array( 'backup' => true, 'format' => true );

t_equals( 'in_use', $S::target_for_flags( false, false ), 'target: no flags' );
t_equals( 'in_use', $S::target_for_flags( false, true ), 'target: formatting alone counts as no flag' );
t_equals( 'backed_up', $S::target_for_flags( true, false ), 'target: backup' );
t_equals( 'free', $S::target_for_flags( true, true ), 'target: backup + formatting' );

// 1) a free card added to a work becomes in use and that work is its active work.
$r = $S::plan_work_save( array( 5 => array( 'status' => 'free', 'active' => 0 ) ), array(), array( 5 ), $none, $none, 100 );
t_equals( array( 5 => array( 'status' => 'in_use', 'active' => 100 ) ), $r['cards'], 'add: free card -> in use, active work set' );
t_equals( false, $r['flags_off'], 'add: nothing to switch off when the flags were off' );

// A card that is not free is not taken (the picker never offers it).
$r = $S::plan_work_save( array( 5 => array( 'status' => 'in_use', 'active' => 200 ) ), array(), array( 5 ), $none, $none, 100 );
t_equals( array(), $r['cards'], 'add: a card in use elsewhere is left alone' );

// Adding a fresh card to a work marked as finished reopens it.
$r = $S::plan_work_save( array( 5 => array( 'status' => 'free', 'active' => 0 ) ), array(), array( 5 ), $bf, $bf, 100 );
t_equals( true, $r['flags_off'], 'add: a new card in a finished work switches the flags off' );

// 2) the flags of the ACTIVE work change: its cards follow (forward and back).
$st = array( 1 => array( 'status' => 'in_use', 'active' => 100 ) );
$r  = $S::plan_work_save( $st, array( 1 ), array( 1 ), $b, $none, 100 );
t_equals( 'backed_up', $r['cards'][1]['status'], 'flags: backup ticked -> backed up' );
$r = $S::plan_work_save( array( 1 => array( 'status' => 'backed_up', 'active' => 100 ) ), array( 1 ), array( 1 ), $bf, $b, 100 );
t_equals( array( 'status' => 'free', 'active' => 100 ), $r['cards'][1], 'flags: formatting ticked -> ready, still linked' );
$r = $S::plan_work_save( array( 1 => array( 'status' => 'free', 'active' => 100 ) ), array( 1 ), array( 1 ), $b, $bf, 100 );
t_equals( 'backed_up', $r['cards'][1]['status'], 'flags: formatting unticked -> back to backed up' );
$r = $S::plan_work_save( array( 1 => array( 'status' => 'backed_up', 'active' => 100 ) ), array( 1 ), array( 1 ), $none, $b, 100 );
t_equals( 'in_use', $r['cards'][1]['status'], 'flags: backup unticked -> back to in use' );

// Flags unchanged: a save does not touch the cards (their statuses were managed one by one).
$r = $S::plan_work_save( array( 1 => array( 'status' => 'in_use', 'active' => 100 ) ), array( 1 ), array( 1 ), $b, $b, 100 );
t_equals( array(), $r['cards'], 'flags unchanged: no change to the cards' );

// 6) an old work (the card is active elsewhere): its flags change, the card does not.
$r = $S::plan_work_save( array( 1 => array( 'status' => 'in_use', 'active' => 200 ) ), array( 1 ), array( 1 ), $none, $bf, 100 );
t_equals( array(), $r['cards'], 'old work: the card keeps its status' );
$r = $S::plan_work_save( array( 1 => array( 'status' => 'free', 'active' => 200 ) ), array( 1 ), array( 1 ), $none, $bf, 100 );
t_equals( array(), $r['cards'], 'old work: a ready card active elsewhere is not touched' );

// 4) a damaged card is never moved by the flags.
$r = $S::plan_work_save( array( 1 => array( 'status' => 'damaged', 'active' => 100 ) ), array( 1 ), array( 1 ), $bf, $none, 100 );
t_equals( array(), $r['cards'], 'damaged: not moved by the flags' );

// 5) removed from the active work: ready (damaged stays damaged), link cleared; from an old work: nothing.
$r = $S::plan_work_save( array( 1 => array( 'status' => 'in_use', 'active' => 100 ) ), array( 1 ), array(), $none, $none, 100 );
t_equals( array( 'status' => 'free', 'active' => 0 ), $r['cards'][1], 'removed from its active work: ready, unlinked' );
$r = $S::plan_work_save( array( 1 => array( 'status' => 'damaged', 'active' => 100 ) ), array( 1 ), array(), $none, $none, 100 );
t_equals( array( 'status' => 'damaged', 'active' => 0 ), $r['cards'][1], 'removed damaged card: stays damaged, unlinked' );
$r = $S::plan_work_save( array( 1 => array( 'status' => 'in_use', 'active' => 200 ) ), array( 1 ), array(), $none, $none, 100 );
t_equals( array(), $r['cards'], 'removed from an old work: nothing changes' );

// Older data: a card in use with no link counts as active in the work being saved.
t_true( $S::is_active_here( array( 'status' => 'in_use', 'active' => 0 ), 100 ), 'legacy: in use with no link is active here' );
t_equals( false, $S::is_active_here( array( 'status' => 'free', 'active' => 0 ), 100 ), 'legacy: a ready card with no link is not' );
$r = $S::plan_work_save( array( 1 => array( 'status' => 'in_use', 'active' => 0 ) ), array( 1 ), array( 1 ), $b, $none, 100 );
t_equals( array( 'status' => 'backed_up', 'active' => 100 ), $r['cards'][1], 'legacy: adopted on the first save with a flag change' );

// 3) flags from the cards of the active work.
t_equals( null, $S::flags_from_cards( array() ), 'flags from cards: none -> nothing to do' );
t_equals( null, $S::flags_from_cards( array( 'damaged' ) ), 'flags from cards: only damaged -> nothing to do' );
t_equals( $none, $S::flags_from_cards( array( 'in_use', 'backed_up' ) ), 'flags from cards: one still in use -> off' );
t_equals( $b, $S::flags_from_cards( array( 'backed_up', 'backed_up' ) ), 'flags from cards: all backed up -> backup' );
t_equals( $b, $S::flags_from_cards( array( 'backed_up', 'free' ) ), 'flags from cards: mixed backed up and ready -> backup only' );
t_equals( $bf, $S::flags_from_cards( array( 'free', 'free' ) ), 'flags from cards: all ready -> both' );
t_equals( $b, $S::flags_from_cards( array( 'backed_up', 'damaged' ) ), 'flags from cards: a damaged card does not count' );
t_equals( $bf, $S::flags_from_cards( array( 'free', 'damaged' ) ), 'flags from cards: ready + damaged -> both' );

class Fotonic_Memory_Card_CPT { const META_STATUS_CHANGED = '_ftnc_status_changed'; }
t_equals( array( '_ftnc_active_work', '_ftnc_status_changed' ), $S::derived_meta( array() ), 'derived meta: the link and the change day are not backed up' );
t_done();
