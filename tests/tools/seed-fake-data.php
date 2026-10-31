<?php
if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit;
}
/**
 * DEV ONLY. Fills a LOCAL test site with fake, PLAIN-TEXT records so the encrypt-existing job takes long
 * enough to try Pause, Resume and a closed tab. Never shipped (the whole /tests folder is in .distignore)
 * and it refuses to run unless WP_ENVIRONMENT_TYPE is "local".
 *
 *   php -d mysqli.default_socket="<socket>" tests/tools/seed-fake-data.php seed [--customers=1500] [--works=1500] [--services=20]
 *   php -d mysqli.default_socket="<socket>" tests/tools/seed-fake-data.php arm      (a new pending encrypt job)
 *   php -d mysqli.default_socket="<socket>" tests/tools/seed-fake-data.php throttle --size=1   (a local mu-plugin: records per encrypt step; "throttle off" removes it)
 *   php -d mysqli.default_socket="<socket>" tests/tools/seed-fake-data.php count
 *   php -d mysqli.default_socket="<socket>" tests/tools/seed-fake-data.php remove   (deletes ONLY what seed created)
 *
 * Every fake record carries the postmeta key `zz_dev_seed` = 1 (no `_ftnc_` prefix, so the backup registry
 * audit does not see it) and a title starting with "ZZ TEST". `remove` deletes by that key and nothing else.
 */
$_SERVER['HTTP_HOST']   = 'fotonic.local';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_USE_THEMES', false );
require dirname( __DIR__, 5 ) . '/wp-load.php';

if ( 'local' !== wp_get_environment_type() ) {
	fwrite( STDERR, "Refusing to run: this is not a local site.\n" );
	exit( 1 );
}

const FTNC_SEED_KEY = 'zz_dev_seed';

function ftnc_seed_args( array $argv ): array {
	$out = array();
	foreach ( array_slice( $argv, 2 ) as $a ) {
		if ( preg_match( '/^--([a-z]+)=(\d+)$/', $a, $m ) ) {
			$out[ $m[1] ] = (int) $m[2];
		}
	}
	return $out;
}

function ftnc_seed_ids(): array {
	global $wpdb;
	return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s", FTNC_SEED_KEY ) ) );
}

$cmd  = $argv[1] ?? '';
$args = ftnc_seed_args( $argv );

if ( 'count' === $cmd ) {
	echo count( ftnc_seed_ids() ) . " fake records\n";
	$job = Fotonic_Vault_Encrypt_Existing::job();
	echo 'encrypt job: ' . ( $job ? $job['status'] : 'none' ) . "\n";
	exit( 0 );
}

if ( 'remove' === $cmd ) {
	$ids = ftnc_seed_ids();
	foreach ( $ids as $id ) {
		wp_delete_post( $id, true );
	}
	echo 'removed ' . count( $ids ) . " fake records\n";
	exit( 0 );
}

if ( 'throttle' === $cmd ) {
	$file = WPMU_PLUGIN_DIR . '/zz-ftnc-dev-batch-size.php';
	if ( 'off' === ( $argv[2] ?? '' ) ) {
		if ( file_exists( $file ) ) {
			unlink( $file );
		}
		echo "throttle removed (the default batch size is back)\n";
		exit( 0 );
	}
	$size = max( 1, (int) ( $args['size'] ?? 1 ) );
	wp_mkdir_p( WPMU_PLUGIN_DIR );
	file_put_contents( $file, "<?php\n// Local test helper written by seed-fake-data.php: remove with 'throttle off'.\nadd_filter( 'ftnc_vault_encrypt_batch_size', function () { return {$size}; } );\n" );
	echo "throttle on: {$size} record(s) per encrypt step (remove with: throttle off)\n";
	exit( 0 );
}

if ( 'arm' === $cmd ) {
	Fotonic_Vault_Encrypt_Existing::clear();
	delete_option( Fotonic_Vault_Encrypt_Existing::OPTION_VERSION );
	Fotonic_Vault_Encrypt_Existing::create();
	echo "a new pending encrypt job is ready (open the app and unlock the Vault)\n";
	exit( 0 );
}

if ( 'seed' !== $cmd ) {
	fwrite( STDERR, "Usage: seed [--customers=N] [--works=N] [--services=N] | arm | throttle --size=N|off | count | remove\n" );
	exit( 1 );
}

$n_customers = $args['customers'] ?? 1500;
$n_works     = $args['works'] ?? 1500;
$n_services  = $args['services'] ?? 20;
$first       = array( 'Anna', 'Luca', 'Giulia', 'Marco', 'Sara', 'Paolo', 'Elena', 'Davide', 'Chiara', 'Matteo' );
$last        = array( 'Rossi', 'Bianchi', 'Verdi', 'Neri', 'Russo', 'Ferrari', 'Esposito', 'Romano', 'Colombo', 'Greco' );

wp_defer_term_counting( true );
// One fake memory card of its own: the fake works never point at a real card (a real card would then show as used by a fake work).
$fake_card = wp_insert_post( array( 'post_type' => 'ftnc_memory_card', 'post_status' => 'publish', 'post_title' => 'ZZ TEST scheda' ) );
update_post_meta( $fake_card, FTNC_SEED_KEY, 1 );
$service_ids = array();
for ( $i = 1; $i <= $n_services; $i++ ) {
	$id = wp_insert_post( array( 'post_type' => 'ftnc_service', 'post_status' => 'publish', 'post_title' => "ZZ TEST servizio {$i}" ) );
	update_post_meta( $id, '_ftnc_base_price', (string) ( 50 + $i ) );
	update_post_meta( $id, '_ftnc_notes', wp_slash( "<p>Note del servizio di prova {$i}</p>" ) );
	update_post_meta( $id, FTNC_SEED_KEY, 1 );
	$service_ids[] = $id;
}
$customer_ids = array();
for ( $i = 1; $i <= $n_customers; $i++ ) {
	$f   = $first[ $i % 10 ];
	$l   = $last[ ( $i * 7 ) % 10 ];
	$id  = wp_insert_post( array( 'post_type' => 'ftnc_customer', 'post_status' => 'publish', 'post_title' => "ZZ TEST cliente {$i}" ) );
	$ppl = array( array( 'first_name' => $f, 'last_name' => $l, 'email' => strtolower( "{$f}.{$l}{$i}@example.com" ), 'phone' => '+39 333 ' . str_pad( (string) $i, 7, '0', STR_PAD_LEFT ), 'nationality' => 'Italiana', 'address' => "Via di prova {$i}, Milano", 'tin' => 'TSTFAKE' . $i, 'gender' => 0 === $i % 2 ? 'female' : 'male', 'is_main' => true ) );
	update_post_meta( $id, '_ftnc_people', wp_slash( wp_json_encode( $ppl ) ) );
	update_post_meta( $id, FTNC_SEED_KEY, 1 );
	$customer_ids[] = $id;
}
for ( $i = 1; $i <= $n_works; $i++ ) {
	$id = wp_insert_post( array( 'post_type' => 'ftnc_work', 'post_status' => 'publish', 'post_title' => "ZZ TEST lavoro {$i}", 'post_content' => "<p>Note riservate del lavoro di prova {$i}: parenti, accordi, luoghi.</p>" ) );
	update_post_meta( $id, '_ftnc_event_date', gmdate( 'Y-m-d', strtotime( '+' . ( 30 + $i ) . ' days' ) ) );
	update_post_meta( $id, '_ftnc_customer_id', $customer_ids ? $customer_ids[ $i % count( $customer_ids ) ] : 0 );
	update_post_meta( $id, '_ftnc_event_addresses', wp_slash( wp_json_encode( array( array( 'label' => 'Location', 'street' => "Via dell'evento {$i}, Roma" ) ) ) ) );
	if ( $service_ids ) {
		update_post_meta( $id, '_ftnc_work_services', wp_slash( wp_json_encode( array( array( 'service_id' => $service_ids[ $i % count( $service_ids ) ], 'price_override' => 100, 'notes_override' => "extra del lavoro {$i}" ) ) ) ) );
	}
	update_post_meta( $id, '_ftnc_memory_cards', wp_slash( wp_json_encode( array( array( 'card_id' => $fake_card, 'notes' => "scheda del lavoro {$i}" ) ) ) ) );
	update_post_meta( $id, FTNC_SEED_KEY, 1 );
}
wp_defer_term_counting( false );
echo 'seeded ' . $n_customers . ' customers, ' . $n_works . ' works, ' . $n_services . " services (all plain text)\n";
