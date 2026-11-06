<?php
// Unit test for the pure parts of Fotonic_Trash (phase 16): expiry, deletion order, restore status, the word.
// No WordPress, no database. The records themselves are covered by tests/integration/test-trash-wp.php.
require __DIR__ . '/../lib/assert.php';
define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../includes/class-trash.php';

echo "expires_at\n";
t_equals( 1000 + 30 * 86400, Fotonic_Trash::expires_at( 1000, 30 ), '30 days later' );
t_equals( 1000 + 7 * 86400, Fotonic_Trash::expires_at( 1000, '7' ), 'days given as a string' );
t_equals( null, Fotonic_Trash::expires_at( 1000, 0 ), 'EMPTY_TRASH_DAYS 0: never' );
t_equals( null, Fotonic_Trash::expires_at( 1000, false ), 'EMPTY_TRASH_DAYS false: never' );
t_equals( null, Fotonic_Trash::expires_at( 0, 30 ), 'no deletion time known: no date' );

echo "delete order\n";
$map = array(
	5 => 'ftnc_customer', 6 => 'ftnc_work', 7 => 'ftnc_service', 8 => 'ftnc_quote', 9 => 'ftnc_work',
	3 => 'ftnc_task', 4 => 'ftnc_unknown', 2 => 'ftnc_memory_card',
);
t_equals( array( 3, 6, 9, 8, 2, 7, 5, 4 ), Fotonic_Trash::sort_for_delete( $map ), 'children first, customers late, unknown last, id breaks ties' );
t_true( Fotonic_Trash::rank( 'ftnc_work' ) < Fotonic_Trash::rank( 'ftnc_quote' ), 'works go before quotes (a converted quote is held back by a live work only)' );
t_true( Fotonic_Trash::rank( 'ftnc_work' ) < Fotonic_Trash::rank( 'ftnc_customer' ), 'works go before their customer' );
t_true( Fotonic_Trash::rank( 'ftnc_quote' ) < Fotonic_Trash::rank( 'ftnc_customer' ), 'quotes go before their customer' );
t_true( Fotonic_Trash::rank( 'ftnc_work' ) < Fotonic_Trash::rank( 'ftnc_service' ), 'works go before the services they use' );

echo "restore status\n";
t_equals( 'publish', Fotonic_Trash::restore_status( 'publish' ), 'publish stays publish' );
t_equals( 'draft', Fotonic_Trash::restore_status( 'draft' ), 'a draft stays a draft' );
t_equals( 'publish', Fotonic_Trash::restore_status( '' ), 'unknown previous status: publish' );
t_equals( 'publish', Fotonic_Trash::restore_status( 'trash' ), 'never back to trash' );
t_equals( 'publish', Fotonic_Trash::restore_status( 'auto-draft' ), 'never an auto-draft' );
t_equals( 'publish', Fotonic_Trash::restore_status( false ), 'not a string: publish' );

echo "the word\n";
t_true( Fotonic_Trash::confirm_ok( 'DELETE' ), 'the word' );
t_true( Fotonic_Trash::confirm_ok( ' DELETE ' ), 'spaces around are ignored' );
t_true( ! Fotonic_Trash::confirm_ok( 'delete' ), 'case counts' );
t_true( ! Fotonic_Trash::confirm_ok( '' ), 'empty' );
t_true( ! Fotonic_Trash::confirm_ok( null ), 'null' );
t_true( ! Fotonic_Trash::confirm_ok( true ), 'not a string' );
t_true( ! Fotonic_Trash::needs_word( false, 1 ), 'one record: no word' );
t_true( Fotonic_Trash::needs_word( false, 2 ), 'two records: the word' );
t_true( Fotonic_Trash::needs_word( true, 0 ), 'all: the word' );

exit( t_done() );
