<?php
// Unit test for Fotonic_Titles (phase 15): the gate, folding, search, ordering, reading with a cache and what to store.
// No WordPress, no database: the data source is replaced by an in-memory one.
require __DIR__ . '/../lib/assert.php';
define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['t_filters'] = array();
function apply_filters( $tag, $value ) {
	foreach ( $GLOBALS['t_filters'][ $tag ] ?? array() as $cb ) {
		$value = $cb( $value );
	}
	return $value;
}
function sanitize_key( $v ) { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ); }
function wp_generate_password( $len = 12, $special = true ) { return substr( bin2hex( random_bytes( 16 ) ), 0, $len ); }
// A tiny remove_accents(): enough for the cases below.
function remove_accents( $s ) { return strtr( $s, array( "\u{f2}" => 'o', "\u{e8}" => 'e', "\u{e9}" => 'e', "\u{e0}" => 'a', "\u{ec}" => 'i', "\u{f9}" => 'u', "\u{d2}" => 'O', "\u{c9}" => 'E' ) ); }

class Fotonic_Vault { public static $setup = false; public static function is_setup(): bool { return self::$setup; } }

require __DIR__ . '/../../includes/class-field-crypt.php';
require __DIR__ . '/../../includes/class-vault-fields.php';
require __DIR__ . '/../../includes/class-titles.php';

class T_Source {
	public $titles = array();
	public $types  = array();
	public $people = array();
	public function title( int $id ): string { return (string) ( $this->titles[ $id ] ?? '' ); }
	public function rows( string $type, array $statuses ): array {
		$out = array();
		foreach ( $this->titles as $id => $t ) {
			if ( ( $this->types[ $id ] ?? '' ) === $type ) {
				$out[ $id ] = $t;
			}
		}
		return $out;
	}
	public function people_text( string $type, array $ids, ?string $key ): array {
		$out = array();
		foreach ( $ids as $id ) {
			if ( isset( $this->people[ $id ] ) ) {
				$out[ $id ] = Fotonic_Titles::fold( $this->people[ $id ] );
			}
		}
		return $out;
	}
}

$k  = random_bytes( 32 );
$k2 = random_bytes( 32 );

// --- Gate ---
t_equals( true, Fotonic_Titles::enabled(), 'gate: open without Pro' );
define( 'FOTO_PRO_VERSION', '1.6.7' );
t_equals( false, Fotonic_Titles::enabled(), 'gate: closed with an old Pro (no answer from it)' );
$GLOBALS['t_filters']['ftnc_titles_encryptable'][] = '__return_true_t';
function __return_true_t() { return true; }
t_equals( true, Fotonic_Titles::enabled(), 'gate: open when Pro says it can cope' );
// (the gate stays open for the rest of the test)

// --- fold / matches / compare ---
t_equals( Fotonic_Titles::fold( "Nicol\u{f2}" ), Fotonic_Titles::fold( 'NICOLO' ), 'fold: accents and case' );
t_equals( 'a b', Fotonic_Titles::fold( "  A \n  B " ), 'fold: spaces collapsed' );
t_true( Fotonic_Titles::matches( 'Matrimonio Rossi Bianchi', 'bianchi rossi' ), 'matches: all words, any order' );
t_true( ! Fotonic_Titles::matches( 'Matrimonio Rossi', 'rossi bianchi' ), 'matches: a missing word fails' );
t_true( ! Fotonic_Titles::matches( null, 'x' ), 'matches: unreadable never matches' );
t_true( Fotonic_Titles::matches( null, '' ), 'matches: empty query matches all' );
t_true( Fotonic_Titles::matches( "L'Anna \\ \"Rossi\"", "l'anna \"rossi\"" ), 'matches: quotes and backslashes are plain text' );
t_true( Fotonic_Titles::compare( 'Anna 2', 'anna 10' ) < 0, 'compare: natural order' );
t_true( Fotonic_Titles::compare( null, 'a' ) > 0, 'compare: unreadable last' );
t_equals( 0, Fotonic_Titles::compare( null, null ), 'compare: two unreadable are equal' );

// --- read / cache / search / sorted ---
$src = new T_Source();
foreach ( array( 1 => "Nicol\u{f2} Verdi", 2 => 'f1:looks like ciphertext', 3 => "Anna 10", 4 => 'Anna 2', 5 => 'Rossi Bianchi' ) as $id => $plain ) {
	$src->titles[ $id ] = 2 === $id ? Fotonic_Field_Crypt::store( $plain, null ) : Fotonic_Field_Crypt::store( $plain, $k );
	$src->types[ $id ]  = 'ftnc_customer';
}
$src->titles[6] = 'plain title never encrypted'; $src->types[6] = 'ftnc_customer';
$src->titles[7] = Fotonic_Field_Crypt::store( 'Other type', $k ); $src->types[7] = 'ftnc_work';
$src->people[3] = 'giulia@example.com +39 333 1234567';
Fotonic_Titles::use_source( $src );

t_equals( "Nicol\u{f2} Verdi", Fotonic_Titles::read( 1, $k ), 'read: decrypts' );
t_equals( null, Fotonic_Titles::read( 1, null ), 'read: no key is null, never empty' );
t_equals( null, Fotonic_Titles::read( 1, $k2 ), 'read: wrong key is null (and not served from the cache of the right key)' );
t_equals( "Nicol\u{f2} Verdi", Fotonic_Titles::read( 1, $k ), 'read: right key again' );
t_equals( 'f1:looks like ciphertext', Fotonic_Titles::read( 2, null ), 'read: a plain title that starts with f1: round-trips' );
t_equals( 'plain title never encrypted', Fotonic_Titles::read( 6, null ), 'read: plain title without a key' );
$src->titles[1] = Fotonic_Field_Crypt::store( 'Changed', $k );
t_equals( 'Changed', Fotonic_Titles::read( 1, $k ), 'read: the cache follows a changed stored value' );
$src->titles[1] = Fotonic_Field_Crypt::store( "Nicol\u{f2} Verdi", $k );

t_equals( array( 1 ), Fotonic_Titles::search_ids( 'ftnc_customer', 'nicolo', $k ), 'search: Nicolo finds Nicolò' );
t_equals( array( 5 ), Fotonic_Titles::search_ids( 'ftnc_customer', 'bianchi rossi', $k ), 'search: all words' );
t_equals( array( 2 ), Fotonic_Titles::search_ids( 'ftnc_customer', 'f1:looks', $k ), 'search: a title that starts with f1:' );
t_equals( array( 3 ), Fotonic_Titles::search_ids( 'ftnc_customer', 'giulia', $k ), 'search: customers are also found by email' );
t_equals( array( 3 ), Fotonic_Titles::search_ids( 'ftnc_customer', 'anna 1234567', $k ), 'search: title word plus a phone fragment' );
t_equals( array(), Fotonic_Titles::search_ids( 'ftnc_customer', 'nicolo', null ), 'search: without the key encrypted titles do not match' );
t_equals( array( 7 ), Fotonic_Titles::search_ids( 'ftnc_work', 'other', $k ), 'search: works have no people text' );
t_equals( array( 1, 2, 3, 4, 5, 6 ), Fotonic_Titles::search_ids( 'ftnc_customer', '  ', $k ), 'search: empty query = all, ID order' );

t_equals( array( 4, 3, 2, 1, 6, 5 ), Fotonic_Titles::sorted_ids( 'ftnc_customer', $k ), 'sorted: natural order, f1: title between a and n' );
$src->titles[8] = Fotonic_Field_Crypt::store( 'Anna 2', $k ); $src->types[8] = 'ftnc_customer';
t_equals( array( 4, 8 ), array_slice( Fotonic_Titles::sorted_ids( 'ftnc_customer', $k ), 0, 2 ), 'sorted: equal titles by ID' );
t_equals( array( 4, 8, 3 ), Fotonic_Titles::sorted_ids( 'ftnc_customer', $k, 'anna' ), 'sorted: filtered by the query' );
$s_null = Fotonic_Titles::sorted_ids( 'ftnc_customer', null );
t_equals( array( 2, 6 ), array_slice( $s_null, 0, 2 ), 'sorted: with no key the readable titles come first' );
t_true( in_array( 1, array_slice( $s_null, -5 ), true ), 'sorted: unreadable ones go last' );

// --- save_args ---
Fotonic_Titles::use_source( null );
$a = Fotonic_Titles::save_args( 'Rossi', '', $k, 'ftnc_customer' );
t_true( Fotonic_Field_Crypt::is_encrypted( $a['post_title'] ), 'save: encrypted with a key' );
t_equals( 'Rossi', Fotonic_Field_Crypt::read( $a['post_title'], $k ), 'save: decrypts to the typed text' );
t_true( 0 === strpos( $a['post_name'], 'customer-' ) && 'customer-12' !== $a['post_name'], 'save: random slug on insert' );
t_true( false === strpos( $a['post_name'], 'rossi' ), 'save: the slug says nothing about the title' );
$b = Fotonic_Titles::save_args( 'Rossi', $a['post_title'], $k, 'ftnc_customer', 12 );
t_equals( $a['post_title'], $b['post_title'], 'save: same text keeps the stored ciphertext' );
t_equals( 'customer-12', $b['post_name'], 'save: neutral slug with the ID' );
$c = Fotonic_Titles::save_args( 'Bianchi', $a['post_title'], $k, 'ftnc_work', 7 );
t_true( $c['post_title'] !== $a['post_title'] && 'Bianchi' === Fotonic_Field_Crypt::read( $c['post_title'], $k ), 'save: a changed text is a new ciphertext' );
t_equals( 'work-7', $c['post_name'], 'save: work slug' );
$q = Fotonic_Titles::save_args( 'x', '', $k, 'ftnc_quote', 3 );
t_equals( 'quote-3', $q['post_name'], 'save: quote slug' );

$p = Fotonic_Titles::save_args( 'Rossi', '', null, 'ftnc_customer' );
t_equals( array( 'post_title' => 'Rossi' ), $p, 'save: no key and no Vault = plain text, no slug' );
t_equals( 'f0:f1:x', Fotonic_Titles::save_args( 'f1:x', '', null, 'ftnc_customer' )['post_title'], 'save: a plain f1: text is escaped' );
Fotonic_Vault::$setup = true;
t_equals( array( 'post_title' => $a['post_title'] ), Fotonic_Titles::save_args( 'Other', $a['post_title'], null, 'ftnc_customer', 5 ), 'save: Vault locked = the stored value, untouched' );
Fotonic_Vault::$setup = false;

// Gate closed: plain even with the key, no slug change (old Pro active, no answer).
$GLOBALS['t_filters']['ftnc_titles_encryptable'][] = function () { return false; };
$g = Fotonic_Titles::save_args( 'Rossi', '', $k, 'ftnc_customer', 4 );
t_equals( array( 'post_title' => 'Rossi' ), $g, 'save: gate closed + key = plain text and no post_name' );
Fotonic_Vault::$setup = true;
t_equals( array( 'post_title' => 'Rossi' ), Fotonic_Titles::save_args( 'Rossi', 'x', null, 'ftnc_customer', 4 ), 'save: gate closed + locked Vault = plain (the field is an ordinary one)' );
$GLOBALS['t_filters']['ftnc_titles_encryptable'] = array();

exit( t_done() );
