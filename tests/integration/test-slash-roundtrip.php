<?php
require __DIR__ . '/../lib/wp-bootstrap.php';

// wp_insert_post(), wp_update_post() and update_post_meta() unslash their input. A writer that
// skips wp_slash() therefore loses every backslash in the text and turns the \u00e8 escapes that
// wp_json_encode() writes for accents into the literal text "u00e8". This test drives the free
// plugin's save paths in-process (REST handlers and the classic meta boxes) with text that
// carries quotes, ampersands, backslashes, accents and non-Latin letters, and demands the same
// bytes back, both from storage and from the handler response.

// Every post this run creates carries this tag in its title: the shutdown sweep removes them even
// if the script dies before t_done().
$GLOBALS['ftnc_run'] = 'sl' . wp_generate_password( 6, false, false );
$run                 = $GLOBALS['ftnc_run'];
// "Vault never set up" is simulated in memory (no DB write) by short-circuiting this option read.
$GLOBALS['ftnc_no_vault_hook'] = 'pre_option_' . Fotonic_Vault::OPTION_SETUP;
// Every post of these types inserted by this CLI process is this test's own.
$GLOBALS['ftnc_inserted'] = [];
foreach ( [ 'ftnc_work', 'ftnc_customer', 'ftnc_service', 'ftnc_memory_card' ] as $ftnc_type ) {
    add_action( 'save_post_' . $ftnc_type, function ( $post_id, $post, $update ) {
        if ( ! $update ) {
            $GLOBALS['ftnc_inserted'][] = (int) $post_id;
        }
    }, PHP_INT_MIN, 3 );
}
register_shutdown_function( function () {
    remove_filter( $GLOBALS['ftnc_no_vault_hook'], '__return_zero' );
    unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );
    $tagged = get_posts( [
        'post_type'        => [ 'ftnc_customer', 'ftnc_work', 'ftnc_service', 'ftnc_memory_card' ],
        'post_status'      => [ 'publish', 'trash', 'draft' ],
        's'                => $GLOBALS['ftnc_run'],
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'suppress_filters' => true,
    ] );
    foreach ( array_unique( array_merge( array_reverse( $GLOBALS['ftnc_cleanup'] ), $tagged, $GLOBALS['ftnc_inserted'] ) ) as $post_id ) {
        wp_delete_post( $post_id, true );
        // A work with an event date may schedule a deferred Google Calendar job for itself.
        wp_clear_scheduled_hook( 'fotonic_gcal_sync_work', [ (int) $post_id ] );
        wp_clear_scheduled_hook( 'fotonic_gcal_update_reminders', [ (int) $post_id ] );
    }
} );

// Plain text and HTML fixtures. Each one is stable under the sanitizer that guards its field, so a
// difference after a round trip can only come from slashing.
$T = [
    1 => "Dell'Acqua & Figli \\ è Łukasz {$run}",
    2 => "Tab\\nNew \\u00e8 \"quoted\" C:\\dir\\ ž ö {$run}",
];
$H = [
    1 => "<p>Dell'Acqua &amp; Figli \\ è Łukasz {$run}</p>",
    2 => "<p>Tab\\nNew \\u00e8 &quot;quoted&quot; C:\\dir\\ ž ö {$run}</p>",
];
foreach ( [ 1, 2 ] as $n ) {
    t_equals( $T[ $n ], sanitize_text_field( $T[ $n ] ), "fixture T{$n} survives sanitize_text_field" );
    t_equals( $H[ $n ], wp_kses_post( $H[ $n ] ), "fixture H{$n} survives wp_kses_post" );
}

$rest = function ( string $method, array $params = [], $body = null ) {
    $req = new WP_REST_Request( $method );
    foreach ( $params as $k => $v ) {
        $req->set_param( $k, $v );
    }
    if ( null !== $body ) {
        $req->set_header( 'content-type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
    }
    return $req;
};
$meta_json = function ( int $post_id, string $key ): array {
    return (array) json_decode( (string) get_post_meta( $post_id, $key, true ), true );
};

$person_input  = function ( int $n ) use ( $T, $run ): array {
    return [
        'first_name'         => $T[ $n ],
        'last_name'          => "D'Acqua \\ Ö {$run}",
        'email'              => "luca.dell{$n}@example.com",
        'phone'              => '+39 333 1234567',
        'nationality'        => 'Łódź',
        'instagram_username' => '@luca.dell',
        'address'            => "Via D'Azeglio \\ 5, è {$run}",
        'tin'                => 'RSSLCU80A01H501U',
    ];
};
// The eight person fields of a row, in the order $person_input lists them.
$pick = function ( array $row ) use ( $person_input ): array {
    $out = [];
    foreach ( array_keys( $person_input( 1 ) ) as $field ) {
        $out[ $field ] = $row[ $field ] ?? null;
    }
    return $out;
};

$svc = t_cleanup( wp_insert_post( wp_slash( [ 'post_type' => 'ftnc_service', 'post_status' => 'publish', 'post_title' => "Reportage {$run}" ] ) ) );

// ---------------------------------------------------------------------------
// One full pass of every save path, with the Vault in the state $key describes.
// ---------------------------------------------------------------------------
$scenario = function ( string $label, ?string $key ) use ( $rest, $meta_json, $T, $H, $svc, $person_input, $pick ) {
    echo "-- {$label}\n";
    $encrypted = null !== $key;

    // ----- Customers -----
    $body = [ 'title' => $T[1], 'people' => [ array_merge( $person_input( 1 ), [ 'is_main' => true ] ), [ 'first_name' => 'Anna' ] ] ];
    $res  = Fotonic_REST_API::create_customer( $rest( 'POST', [], $body ) );
    $cid  = t_cleanup( (int) ( $res->get_data()['id'] ?? 0 ) );
    t_equals( 201, $res->get_status(), "{$label}: create_customer is 201" );

    $check_customer = function ( int $id, array $data, int $n, string $step ) use ( $label, $T, $meta_json, $pick, $person_input, $encrypted, $key ) {
        t_equals( $T[ $n ], get_post_field( 'post_title', $id, 'raw' ), "{$label}: {$step} customer title stored" );
        t_equals( $T[ $n ], $data['title'] ?? null, "{$label}: {$step} customer title in the response" );
        $stored = Fotonic_REST_API::decrypt_people( $meta_json( $id, '_ftnc_people' ), $key );
        t_equals( $person_input( $n ), $pick( $stored[0] ?? [] ), "{$label}: {$step} person fields stored" );
        t_equals( $person_input( $n ), $pick( $data['people'][0] ?? [] ), "{$label}: {$step} person fields in the response" );
        if ( $encrypted ) {
            $raw = $meta_json( $id, '_ftnc_people' );
            t_true( $person_input( $n )['first_name'] !== $raw[0]['first_name'], "{$label}: {$step} first_name is stored encrypted" );
            t_equals( Fotonic_Crypto::deterministic_encrypt( $person_input( $n )['email'], $key ), $raw[0]['email'] ?? null, "{$label}: {$step} deterministic email ciphertext untouched" );
        }
    };
    $check_customer( $cid, $res->get_data(), 1, 'create' );

    $body = [ 'title' => $T[2], 'people' => [ array_merge( $person_input( 2 ), [ 'is_main' => true ] ) ] ];
    $res  = Fotonic_REST_API::update_customer( $rest( 'PUT', [ 'id' => $cid ], $body ) );
    t_equals( 200, $res->get_status(), "{$label}: update_customer is 200" );
    $check_customer( $cid, $res->get_data(), 2, 'update' );

    // ----- Services -----
    $res = Fotonic_REST_API::create_service( $rest( 'POST', [], [ 'title' => $T[1], 'base_price' => 10, 'notes' => $H[1] ] ) );
    $sid = t_cleanup( (int) ( $res->get_data()['id'] ?? 0 ) );
    t_equals( $T[1], get_post_field( 'post_title', $sid, 'raw' ), "{$label}: create_service title stored" );
    t_equals( $H[1], get_post_meta( $sid, '_ftnc_notes', true ), "{$label}: create_service notes stored" );
    t_equals( [ $T[1], $H[1] ], [ $res->get_data()['title'] ?? null, $res->get_data()['notes'] ?? null ], "{$label}: create_service response" );
    $res = Fotonic_REST_API::update_service( $rest( 'PUT', [ 'id' => $sid ], [ 'title' => $T[2], 'notes' => $H[2] ] ) );
    t_equals( $T[2], get_post_field( 'post_title', $sid, 'raw' ), "{$label}: update_service title stored" );
    t_equals( $H[2], get_post_meta( $sid, '_ftnc_notes', true ), "{$label}: update_service notes stored" );
    t_equals( [ $T[2], $H[2] ], [ $res->get_data()['title'] ?? null, $res->get_data()['notes'] ?? null ], "{$label}: update_service response" );

    // ----- Memory cards -----
    $res  = Fotonic_Memory_Card_REST_API::create_card( $rest( 'POST', [], [ 'title' => $T[1] ] ) );
    $card = t_cleanup( (int) ( $res->get_data()['id'] ?? 0 ) );
    t_equals( $T[1], get_post_field( 'post_title', $card, 'raw' ), "{$label}: create_card title stored" );
    t_equals( $T[1], $res->get_data()['title'] ?? null, "{$label}: create_card response" );
    $res = Fotonic_Memory_Card_REST_API::update_card( $rest( 'PUT', [ 'id' => $card ], [ 'title' => $T[2] ] ) );
    t_equals( $T[2], get_post_field( 'post_title', $card, 'raw' ), "{$label}: update_card title stored" );
    t_equals( $T[2], $res->get_data()['title'] ?? null, "{$label}: update_card response" );

    // ----- Works -----
    $work_body = function ( int $n ) use ( $T, $H, $cid, $svc, $card ): array {
        return [
            'title'           => $T[ $n ],
            'notes'           => $H[ $n ],
            'event_date'      => '2099-05-17',
            'customer_id'     => $cid,
            'event_addresses' => [ [ 'label' => $T[ $n ], 'street' => 'Via ' . $T[ $n ] ] ],
            'services'        => [ [ 'service_id' => $svc, 'price_override' => 100, 'notes_override' => $T[ $n ] ] ],
            'installments'    => [ [ 'title' => $T[ $n ], 'amount' => 50, 'status' => 'unpaid', 'type' => 'default', 'date' => '2099-05-01' ] ],
            'files'           => [ [ 'type' => 'link', 'url' => 'https://example.com/a/b?x=1', 'label' => $T[ $n ] ] ],
            'quick_notes'     => $H[ $n ],
            'memory_cards'    => [ [ 'card_id' => $card, 'notes' => $T[ $n ] ] ],
        ];
    };
    $check_work = function ( int $id, array $data, int $n, string $step ) use ( $label, $T, $H, $meta_json, $encrypted, $key ) {
        $street = 'Via ' . $T[ $n ];
        t_equals( $T[ $n ], get_post_field( 'post_title', $id, 'raw' ), "{$label}: {$step} work title stored" );
        t_equals( $H[ $n ], get_post_field( 'post_content', $id, 'raw' ), "{$label}: {$step} work notes stored" );
        $addr = $meta_json( $id, '_ftnc_event_addresses' );
        t_equals( $T[ $n ], $addr[0]['label'] ?? null, "{$label}: {$step} address label stored" );
        t_equals( $street, Fotonic_REST_API::decrypt_value( (string) ( $addr[0]['street'] ?? '' ), $key ), "{$label}: {$step} address street stored" );
        if ( $encrypted ) {
            t_true( $street !== ( $addr[0]['street'] ?? '' ), "{$label}: {$step} street is stored encrypted" );
        }
        t_equals( $T[ $n ], $meta_json( $id, '_ftnc_work_services' )[0]['notes_override'] ?? null, "{$label}: {$step} service notes_override stored" );
        t_equals( $T[ $n ], $meta_json( $id, '_ftnc_installments' )[0]['title'] ?? null, "{$label}: {$step} installment title stored" );
        $file = $meta_json( $id, '_ftnc_work_files' )[0] ?? [];
        t_equals( [ $T[ $n ], 'https://example.com/a/b?x=1' ], [ $file['label'] ?? null, $file['url'] ?? null ], "{$label}: {$step} file label and link stored" );
        t_equals( $H[ $n ], get_post_meta( $id, '_ftnc_quick_notes', true ), "{$label}: {$step} quick notes stored" );
        t_equals( $T[ $n ], $meta_json( $id, '_ftnc_memory_cards' )[0]['notes'] ?? null, "{$label}: {$step} memory card notes stored" );

        t_equals( $T[ $n ], $data['title'] ?? null, "{$label}: {$step} work title in the response" );
        t_equals( $H[ $n ], $data['notes'] ?? null, "{$label}: {$step} work notes in the response" );
        t_equals( [ $T[ $n ], $street ], [ $data['event_addresses'][0]['label'] ?? null, $data['event_addresses'][0]['street'] ?? null ], "{$label}: {$step} address in the response" );
        t_equals( $T[ $n ], $data['services'][0]['notes_override'] ?? null, "{$label}: {$step} service notes_override in the response" );
        t_equals( $T[ $n ], $data['installments'][0]['title'] ?? null, "{$label}: {$step} installment title in the response" );
        t_equals( $T[ $n ], $data['files'][0]['label'] ?? null, "{$label}: {$step} file label in the response" );
        t_equals( $H[ $n ], $data['quick_notes'] ?? null, "{$label}: {$step} quick notes in the response" );
        t_equals( $T[ $n ], $data['memory_cards'][0]['notes'] ?? null, "{$label}: {$step} memory card notes in the response" );
    };

    $res = Fotonic_REST_API::create_work( $rest( 'POST', [], $work_body( 1 ) ) );
    $wid = t_cleanup( (int) ( $res->get_data()['id'] ?? 0 ) );
    t_equals( 201, $res->get_status(), "{$label}: create_work is 201" );
    $check_work( $wid, (array) $res->get_data(), 1, 'create' );

    $res = Fotonic_REST_API::update_work( $rest( 'PUT', [ 'id' => $wid ], $work_body( 2 ) ) );
    t_equals( 200, $res->get_status(), "{$label}: update_work is 200" );
    $check_work( $wid, (array) $res->get_data(), 2, 'update' );

    // A second save of the stored values (what the SPA does when nothing was edited) changes nothing.
    $res = Fotonic_REST_API::update_work( $rest( 'PUT', [ 'id' => $wid ], $work_body( 2 ) ) );
    $check_work( $wid, (array) $res->get_data(), 2, 'repeat' );

    // ----- Classic meta boxes: $_POST arrives slashed, exactly as WordPress hands it over -----
    $post_with = function ( array $fields ) {
        $_POST = wp_slash( array_merge( [ 'ftnc_meta_nonce' => wp_create_nonce( 'ftnc_save_meta' ) ], $fields ) );
    };
    try {
        $post_with( [ 'ftnc_people_json' => wp_json_encode( [ array_merge( $person_input( 1 ), [ 'is_main' => true ] ) ] ) ] );
        Fotonic_Meta_Boxes::save_customer( $cid, get_post( $cid ) );
        $stored = Fotonic_REST_API::decrypt_people( $meta_json( $cid, '_ftnc_people' ), $key );
        t_equals( $person_input( 1 ), $pick( $stored[0] ?? [] ), "{$label}: meta box save_customer person fields" );

        $post_with( [ 'ftnc_base_price' => '12', 'ftnc_notes' => $H[1] ] );
        Fotonic_Meta_Boxes::save_service( $sid, get_post( $sid ) );
        t_equals( $H[1], get_post_meta( $sid, '_ftnc_notes', true ), "{$label}: meta box save_service notes" );

        $post_with( [
            'ftnc_event_addresses_json' => wp_json_encode( [ [ 'label' => $T[1], 'street' => 'Via ' . $T[1] ] ] ),
            'ftnc_work_services_json'   => wp_json_encode( [ [ 'service_id' => $svc, 'price_override' => 100, 'notes_override' => $T[1] ] ] ),
            'ftnc_installments_json'    => wp_json_encode( [ [ 'title' => $T[1], 'amount' => 50, 'status' => 'unpaid', 'type' => 'default', 'date' => '2099-05-01' ] ] ),
            'ftnc_quick_notes'          => $H[1],
        ] );
        Fotonic_Meta_Boxes::save_work( $wid, get_post( $wid ) );
        $addr = $meta_json( $wid, '_ftnc_event_addresses' );
        t_equals( $T[1], $addr[0]['label'] ?? null, "{$label}: meta box save_work address label" );
        t_equals( 'Via ' . $T[1], Fotonic_REST_API::decrypt_value( (string) ( $addr[0]['street'] ?? '' ), $key ), "{$label}: meta box save_work address street" );
        t_equals( $T[1], $meta_json( $wid, '_ftnc_work_services' )[0]['notes_override'] ?? null, "{$label}: meta box save_work service notes_override" );
        t_equals( $T[1], $meta_json( $wid, '_ftnc_installments' )[0]['title'] ?? null, "{$label}: meta box save_work installment title" );
        t_equals( $H[1], get_post_meta( $wid, '_ftnc_quick_notes', true ), "{$label}: meta box save_work quick notes" );
        // The link entry added through the SPA survives a classic save that only manages attachments.
        $post_with( [ 'ftnc_work_files_json' => '[]' ] );
        Fotonic_Meta_Boxes::save_work( $wid, get_post( $wid ) );
        $file = $meta_json( $wid, '_ftnc_work_files' )[0] ?? [];
        t_equals( [ $T[2], 'https://example.com/a/b?x=1' ], [ $file['label'] ?? null, $file['url'] ?? null ], "{$label}: meta box save_work keeps the link entry intact" );
    } finally {
        $_POST = [];
    }
};

// ---------------------------------------------------------------------------
// 1. Vault configured but locked: personal details are refused (423) instead of being stored as
//    plaintext, so there is no save to round-trip here; see test-pii-protection.php.
// ---------------------------------------------------------------------------
unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );

// ---------------------------------------------------------------------------
// 2. Vault never set up. Simulated in memory only (pre_option filter, no DB write), always removed afterwards.
// ---------------------------------------------------------------------------
$was_setup = Fotonic_Vault::is_setup();
add_filter( $GLOBALS['ftnc_no_vault_hook'], '__return_zero' );
try {
    t_equals( false, Fotonic_Vault::is_setup(), 'no-Vault simulation active' );
    $scenario( 'no vault', null );
} finally {
    remove_filter( $GLOBALS['ftnc_no_vault_hook'], '__return_zero' );
}
t_equals( $was_setup, Fotonic_Vault::is_setup(), 'no-Vault simulation removed' );

// ---------------------------------------------------------------------------
// 3. Unlocked: a throwaway key stands in for the session key (cookie in memory only), so PII and
//    streets are stored as base64 ciphertext. The key is picked so a deterministic ciphertext
//    contains "/", the character wp_json_encode() escapes as "\/".
// ---------------------------------------------------------------------------
$probe = 'luca.dell1@example.com';
$key   = random_bytes( 32 );
for ( $i = 0; $i < 60 && false === strpos( Fotonic_Crypto::deterministic_encrypt( $probe, $key ), '/' ); $i++ ) {
    $key = random_bytes( 32 );
}
t_true( false !== strpos( Fotonic_Crypto::deterministic_encrypt( $probe, $key ), '/' ), 'test key yields a ciphertext containing a slash' );
ob_start(); // setcookie() has nothing to send in the CLI; keep any notice out of the output
Fotonic_Vault::update_session_key( $key );
ob_end_clean();
t_equals( $key, Fotonic_Vault::get_session_key(), 'throwaway session key active for this process' );
try {
    $scenario( 'unlocked', $key );
} finally {
    unset( $_COOKIE[ Fotonic_Vault::COOKIE_NAME ] );
}
t_equals( null, Fotonic_Vault::get_session_key(), 'throwaway session key removed' );

t_done();
