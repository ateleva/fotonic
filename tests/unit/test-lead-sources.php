<?php
// Unit test for Fotonic_Lead_Sources (the list of where a lead came from). No WordPress, no database.
ob_start();
require __DIR__ . '/../lib/assert.php';

define( 'ABSPATH', __DIR__ . '/' );
function __( $t, $d = '' ) { return $t; }
function sanitize_text_field( $v ) { return trim( strip_tags( (string) $v ) ); }

require __DIR__ . '/../../includes/class-lead-sources.php';

t_equals( 'instagram', Fotonic_Lead_Sources::slug( 'instagram' ), 'slug: a listed slug is kept' );
foreach ( [ 'Instagram', ' instagram', 42, [ 'instagram' ], null, 'bogus', '' ] as $bad ) {
	t_equals( '', Fotonic_Lead_Sources::slug( $bad ), 'slug: invalid value reads as empty: ' . var_export( $bad, true ) );
}

t_true( Fotonic_Lead_Sources::takes_detail( 'other' ), 'takes_detail: other' );
t_true( Fotonic_Lead_Sources::takes_detail( 'word_of_mouth' ), 'takes_detail: word_of_mouth' );
t_true( Fotonic_Lead_Sources::takes_detail( 'supplier_referral' ), 'takes_detail: supplier_referral' );
t_equals( false, Fotonic_Lead_Sources::takes_detail( 'instagram' ), 'takes_detail: instagram does not' );
t_equals( false, Fotonic_Lead_Sources::takes_detail( '' ), 'takes_detail: empty does not' );

t_equals( 120, mb_strlen( Fotonic_Lead_Sources::detail( str_repeat( 'a', 200 ) ) ), 'detail: cut to 120 characters' );
t_equals( 'Mario', Fotonic_Lead_Sources::detail( '  Mario <b></b>' ), 'detail: trimmed and stripped' );
t_equals( '', Fotonic_Lead_Sources::detail( [ 'x' ] ), 'detail: a non-string is empty' );

t_equals( Fotonic_Lead_Sources::SOURCES, array_keys( Fotonic_Lead_Sources::labels() ), 'labels: same slugs in display order' );
t_equals( 12, count( Fotonic_Lead_Sources::SOURCES ), 'twelve sources' );
t_equals( 'Matrimonio.com', Fotonic_Lead_Sources::label( 'matrimonio_com' ), 'label: the brand name' );
t_equals( '', Fotonic_Lead_Sources::label( 'bogus' ), 'label: unknown slug is empty' );
$all = Fotonic_Lead_Sources::SOURCES;
t_equals( 'other', end( $all ), 'other is last' );

t_done();
