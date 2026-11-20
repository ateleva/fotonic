<?php
// Unit test for Fotonic_Help (the in-app Help cards). No WordPress, no database.
ob_start();
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );
require __DIR__ . '/../../includes/class-help.php';

t_equals( 'it_IT', Fotonic_Help::locale_key( 'it_IT' ), 'locale: it_IT' );
t_equals( 'it_IT', Fotonic_Help::locale_key( 'it_CH' ), 'locale: other it_* reads Italian' );
t_equals( 'en_US', Fotonic_Help::locale_key( 'de_DE' ), 'locale: anything else reads English' );
t_equals( 'en_US', Fotonic_Help::locale_key( '' ), 'locale: empty reads English' );

$tmp = sys_get_temp_dir() . '/ftnc-help-' . uniqid();
mkdir( $tmp );
t_equals( array( 'sections' => array(), 'cards' => array() ), Fotonic_Help::load_file( $tmp, 'it_IT' ), 'load: missing file is empty' );
file_put_contents( $tmp . '/help-it_IT.json', '{not json' );
t_equals( array( 'sections' => array(), 'cards' => array() ), Fotonic_Help::load_file( $tmp, 'it_IT' ), 'load: broken json is empty' );
file_put_contents( $tmp . '/help-it_IT.json', '{"sections":{"customers":"Clienti"},"cards":[{"id":"customers-a","question":"Q","answer":"A"}]}' );
$d = Fotonic_Help::load_file( $tmp, 'it_IT' );
t_equals( array( 'customers' => 'Clienti' ), $d['sections'], 'load: sections read' );
t_equals( 1, count( $d['cards'] ), 'load: cards read' );
unlink( $tmp . '/help-it_IT.json' );
rmdir( $tmp );

$cards = array(
	array( 'id' => 'a-one', 'question' => 'Q1', 'answer' => 'A1', 'keywords' => array( 'x', '', 5, 'y' ) ),
	array( 'id' => 'a-one', 'question' => 'dup', 'answer' => 'dup' ),
	'junk',
	array( 'id' => '', 'question' => 'Q', 'answer' => 'A' ),
	array( 'id' => 'a-two', 'question' => '', 'answer' => 'A' ),
	array( 'id' => 'a-three', 'question' => 'Q3', 'answer' => 'A3', 'requires' => 'calendar', 'first_steps' => true, 'order' => '20' ),
	array( 'id' => 'a-four', 'question' => 'Q4', 'answer' => 'A4', 'requires' => 'gone', 'unavailable' => 'Missing' ),
);
$n = Fotonic_Help::normalize( $cards, Fotonic_Help::BASE_FEATURES );
t_equals( array( 'a-one', 'a-three', 'a-four' ), array_column( $n, 'id' ), 'normalize: junk, empty and repeated ids dropped, first wins' );
t_equals( 'Q1', $n[0]['question'], 'normalize: first card of a repeated id is kept' );
t_equals( array( 'x', 'y' ), $n[0]['keywords'], 'normalize: only non-empty string keywords' );
t_true( $n[0]['available'], 'normalize: no requirement is available' );
t_true( $n[1]['available'], 'normalize: feature on is available' );
t_true( $n[1]['first_steps'], 'normalize: first_steps kept' );
t_equals( 20, $n[1]['order'], 'normalize: order is an int' );
t_equals( false, $n[2]['available'], 'normalize: feature off is not available' );
t_equals( 'Missing', $n[2]['unavailable'], 'normalize: unavailable text kept' );
t_equals( '', $n[0]['unavailable'], 'normalize: unavailable defaults to empty' );

t_done();
