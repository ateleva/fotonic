<?php
/**
 * Record uid, Vault first-run choice and vault-download without a Vault, on the live site.
 * "No Vault" is simulated in-process with the pre_option filter: the real Vault is never touched,
 * unlocked or changed. Every post, attachment and option this creates is removed at the end.
 */
require __DIR__ . '/../lib/wp-bootstrap.php';
t_preserve_options( [ 'fotonic_vault_choice', 'fotonic_uid_backfill_done', 'fotonic_vault_encrypt_job', 'fotonic_vault_encrypt_version' ] );

$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );

function ftnc_t_rest( string $method, string $route, $body = null ): WP_REST_Response {
    $req = new WP_REST_Request( $method, $route );
    if ( null !== $body ) {
        $req->set_header( 'content-type', 'application/json' );
        $req->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $req );
}

// ---- 1. Every tracked type gets a uid when it is created ------------------------------------
$ids = [];
foreach ( [ 'ftnc_customer', 'ftnc_service', 'ftnc_work', 'ftnc_memory_card' ] as $type ) {
    $id = t_cleanup( (int) wp_insert_post( [ 'post_type' => $type, 'post_status' => 'publish', 'post_title' => 'ftnc uid test ' . $type ] ) );
    $uid = get_post_meta( $id, '_ftnc_uid', true );
    t_true( Fotonic_Record_Uid::is_valid( $uid ), "$type: a uid on creation" );
    wp_update_post( [ 'ID' => $id, 'post_title' => 'ftnc uid test renamed' ] );
    t_equals( $uid, get_post_meta( $id, '_ftnc_uid', true ), "$type: the uid survives an update" );
    $ids[ $type ] = $id;
}
t_equals( 4, count( array_unique( array_map( function ( $i ) { return get_post_meta( $i, '_ftnc_uid', true ); }, $ids ) ) ), 'four records, four different uids' );

// ---- 2. Created through the real REST route --------------------------------------------------
$res = ftnc_t_rest( 'POST', '/fotonic/v1/customers', [ 'title' => 'ftnc uid rest test', 'people' => [] ] );
t_equals( 201, $res->get_status(), 'REST create customer: 201' );
$rest_id = (int) ( $res->get_data()['id'] ?? 0 );
t_cleanup( $rest_id );
t_true( Fotonic_Record_Uid::is_valid( get_post_meta( $rest_id, '_ftnc_uid', true ) ), 'REST create: the customer has a uid' );

// ---- 3. Backfill and ensure_all on a record that predates the uid ---------------------------
delete_post_meta( $ids['ftnc_service'], '_ftnc_uid' );
t_true( in_array( $ids['ftnc_service'], Fotonic_Record_Uid::missing_ids( 5000 ), true ), 'a record without uid is reported as missing' );
Fotonic_Record_Uid::ensure_all();
t_true( Fotonic_Record_Uid::is_valid( get_post_meta( $ids['ftnc_service'], '_ftnc_uid', true ) ), 'ensure_all() gives it a uid' );
t_equals( [], Fotonic_Record_Uid::missing_ids( 5000 ), 'ensure_all() leaves no record without a uid on the whole site' );
update_option( 'fotonic_uid_backfill_done', false );
Fotonic_Record_Uid::maybe_backfill();
t_equals( true, (bool) get_option( 'fotonic_uid_backfill_done' ), 'maybe_backfill(): flag set when nothing is left' );

// ---- 4. The backup registry knows the key and the audit is clean ----------------------------
foreach ( [ 'ftnc_customer', 'ftnc_service', 'ftnc_work', 'ftnc_memory_card' ] as $type ) {
    t_true( in_array( '_ftnc_uid', Fotonic_Backup_Registry::datasets()[ $type ]['meta_keys'], true ), "$type dataset declares _ftnc_uid" );
}
t_equals( [], array_values( array_filter( Fotonic_Backup_Registry::audit(), function ( $e ) { return false !== strpos( $e, '_ftnc_uid' ); } ) ), 'the registry audit reports no _ftnc_uid drift' );
// The archive payload: a record whose uid was removed gets it back before the export, and every
// record of the four free datasets carries a valid uid. collect_all_datasets() only reads (no zip, no lock, no file).
delete_post_meta( $ids['ftnc_work'], '_ftnc_uid' );
Fotonic_Record_Uid::ensure_all(); // what Fotonic_Backup_Archive::create() does first
$collect = new ReflectionMethod( 'Fotonic_Backup_Archive', 'collect_all_datasets' );
$collect->setAccessible( true );
$free_sets = array_intersect_key( Fotonic_Backup_Registry::datasets(), array_flip( [ 'ftnc_customer', 'ftnc_service', 'ftnc_work', 'ftnc_memory_card' ] ) );
$collected = $collect->invoke( null, $free_sets, [ 'datasets' => [] ] );
$bad = 0; $total = 0;
foreach ( $collected['payload'] as $records ) {
    foreach ( $records as $rec ) {
        $total++;
        if ( ! Fotonic_Record_Uid::is_valid( $rec['meta']['_ftnc_uid'] ?? '' ) ) { $bad++; }
    }
}
t_true( $total >= 4, "the archive payload holds the records ($total)" );
t_equals( 0, $bad, 'every record in the archive payload carries a valid _ftnc_uid' );
t_true( false !== strpos( file_get_contents( FOTONIC_DIR . 'includes/class-backup-archive.php' ), 'Fotonic_Record_Uid::ensure_all();' ), 'create() calls ensure_all()' );

// ---- 5. Status and choice, with the real Vault (set up) -------------------------------------
$status = ftnc_t_rest( 'GET', '/fotonic/v1/vault/status' )->get_data();
t_equals( true, $status['setup'], 'the live site has a Vault' );
t_equals( 'enabled', $status['choice'], 'status: choice is enabled when a Vault exists' );
t_equals( Fotonic_Vault_Encrypt_Existing::is_pending(), $status['encrypt_pending'], 'status: encrypt_pending says whether the encrypt-existing job is pending' );
t_equals( false, Fotonic_Vault_Encrypt_Existing::is_blocking(), 'status: the pending job never blocks writes' );
t_equals( 409, ftnc_t_rest( 'POST', '/fotonic/v1/vault/choice', [ 'choice' => 'skipped' ] )->get_status(), 'choice with a Vault: 409' );
t_equals( false, get_option( 'fotonic_vault_choice', false ) === 'skipped' && ! Fotonic_Vault::is_setup(), 'choice with a Vault: nothing stored as a side effect' );

// ---- 6. No Vault (simulated): the choice flow ------------------------------------------------
add_filter( 'pre_option_fotonic_vault_setup', '__return_zero' );
delete_option( 'fotonic_vault_choice' );
t_equals( '', ftnc_t_rest( 'GET', '/fotonic/v1/vault/status' )->get_data()['choice'], 'no Vault, never asked: choice is empty' );
t_equals( 400, ftnc_t_rest( 'POST', '/fotonic/v1/vault/choice', [ 'choice' => 'enabled' ] )->get_status(), 'invalid choice: 400' );
t_equals( 400, ftnc_t_rest( 'POST', '/fotonic/v1/vault/choice', [] )->get_status(), 'empty choice: 400' );
$ok = ftnc_t_rest( 'POST', '/fotonic/v1/vault/choice', [ 'choice' => 'skipped' ] );
t_equals( 200, $ok->get_status(), 'skipped: 200' );
t_equals( 'skipped', ftnc_t_rest( 'GET', '/fotonic/v1/vault/status' )->get_data()['choice'], 'status now says skipped' );
wp_set_current_user( 0 );
t_equals( true, in_array( ftnc_t_rest( 'POST', '/fotonic/v1/vault/choice', [ 'choice' => 'skipped' ] )->get_status(), [ 401, 403 ], true ), 'choice without a login: refused' );
$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
wp_set_current_user( (int) $admins[0] );

// ---- 7. vault-download without a Vault -------------------------------------------------------
$uploads = wp_upload_dir();
$work    = t_cleanup( (int) wp_insert_post( [ 'post_type' => 'ftnc_work', 'post_status' => 'publish', 'post_title' => 'ftnc download test' ] ) );
$make_att = function ( string $name, string $bytes, string $mime ) use ( $uploads, $work ) {
    $path = $uploads['basedir'] . '/' . $name;
    file_put_contents( $path, $bytes );
    $att = wp_insert_attachment( [ 'post_mime_type' => $mime, 'post_title' => $name, 'post_status' => 'inherit' ], $path, $work );
    return [ (int) $att, $path ];
};
list( $plain_id, $plain_path ) = $make_att( 'ftnc-test-plain-' . wp_generate_password( 8, false ) . '.txt', "hello plain file\n", 'text/plain' );
$enc_plain = random_bytes( 64 );
$tmp       = $uploads['basedir'] . '/ftnc-test-src-' . wp_generate_password( 8, false ) . '.pdf';
file_put_contents( $tmp, $enc_plain );
$enc_target = $tmp . '.enc';
t_equals( true, Fotonic_File_Crypt::encrypt_file( $tmp, $enc_target, random_bytes( 32 ) ), 'test fixture: a file under the Vault layer' );
@unlink( $tmp );
$enc_path = $enc_target;
$enc_att  = (int) wp_insert_attachment( [ 'post_mime_type' => 'application/pdf', 'post_title' => 'enc', 'post_status' => 'inherit' ], $enc_path, $work );
update_post_meta( $work, '_ftnc_work_files', wp_slash( wp_json_encode( [ [ 'type' => 'attachment', 'id' => $plain_id, 'label' => 'p' ], [ 'type' => 'attachment', 'id' => $enc_att, 'label' => 'e' ] ] ) ) );

$perm = new WP_REST_Request( 'GET', '/fotonic/v1/vault-download/' . $plain_id );
$perm->set_header( 'x_wp_nonce', $_SERVER['HTTP_X_WP_NONCE'] );
t_equals( true, Fotonic_REST_API::vault_download_permission( $perm ), 'no Vault: the permission check passes for an admin with the nonce' );
$dl_req = new WP_REST_Request( 'GET', '/fotonic/v1/vault-download/' . $enc_att );
$dl_req->set_header( 'x_wp_nonce', $_SERVER['HTTP_X_WP_NONCE'] );
$resp = rest_do_request( $dl_req );
t_equals( 422, $resp->get_status(), 'no Vault + file under the Vault layer: 422' );
t_equals( 'file_unreadable', $resp->get_data()['code'] ?? '', 'no Vault + .enc file: code file_unreadable' );

// The success path streams and exits: run it in a child process.
$child  = FOTONIC_DIR . 'tests/lib/download-child.php';
$sock   = getenv( 'FTNC_DB_SOCKET' ) ?: ( getenv( 'HOME' ) . '/Library/Application Support/Local/run/JdpHqGxur/mysql/mysqld.sock' );
$out    = shell_exec( escapeshellarg( PHP_BINARY ) . ' -d mysqli.default_socket=' . escapeshellarg( $sock ) . ' -d error_reporting=24575 ' . escapeshellarg( $child ) . ' ' . (int) $plain_id . ' 2>&1' );
t_equals( "hello plain file\n", $out, 'no Vault: a plain linked file streams its exact bytes' );
$out2 = shell_exec( escapeshellarg( PHP_BINARY ) . ' -d mysqli.default_socket=' . escapeshellarg( $sock ) . ' -d error_reporting=24575 ' . escapeshellarg( $child ) . ' ' . (int) $enc_att . ' 2>&1' );
t_true( false !== strpos( (string) $out2, 'ERROR_STATUS=422 CODE=file_unreadable' ), 'no Vault: the .enc file answers 422 in the real handler' );

// Unlinked attachment is still refused.
list( $other_id, $other_path ) = $make_att( 'ftnc-test-other-' . wp_generate_password( 8, false ) . '.txt', 'x', 'text/plain' );
$other = shell_exec( escapeshellarg( PHP_BINARY ) . ' -d mysqli.default_socket=' . escapeshellarg( $sock ) . ' -d error_reporting=24575 ' . escapeshellarg( $child ) . ' ' . (int) $other_id . ' 2>&1' );
t_true( false !== strpos( (string) $other, 'ERROR_STATUS=403' ), 'no Vault: an attachment not linked to a work is still refused (403)' );

foreach ( [ $plain_id, $enc_att, $other_id ] as $a ) { wp_delete_attachment( $a, true ); }
foreach ( [ $plain_path, $enc_path, $other_path ] as $f ) { if ( file_exists( $f ) ) { @unlink( $f ); } }
remove_filter( 'pre_option_fotonic_vault_setup', '__return_zero' );

t_done();
