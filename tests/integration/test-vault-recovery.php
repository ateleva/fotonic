<?php
require __DIR__ . '/../lib/wp-bootstrap.php';
ob_start();

// Tests touch the live local vault options: snapshot them, the harness restores on exit (also on fatal).
t_preserve_options( [
	'fotonic_vault_setup',
	'fotonic_vault_epoch',
	'fotonic_vault_salt',
	'fotonic_vault_totp_secret',
	'fotonic_vault_wrap_pw',
	'fotonic_vault_salt_rec',
	'fotonic_vault_wrap_rec',
	'fotonic_vault_wrap_totp',
	'fotonic_vault_scheme',
	'fotonic_vault_salt_phrase',
	'fotonic_vault_wrap_phrase',
	'fotonic_vault_pending',
	'fotonic_vault_pending_totp',
	'fotonic_vault_recovery_ack',
	'fotonic_backup_pubkey',
	'fotonic_backup_wrap_priv',
] );
$t_cookie = isset( $_COOKIE ) ? $_COOKIE : array();

function t_clear_vault_transients(): void {
	Fotonic_Vault_Attempts::clear_all();
	delete_transient( 'fotonic_vault_reset_code' );
	delete_transient( 'fotonic_vault_reset_requests' );
}

function t_otp( string $secret ): string {
	delete_transient( 'fotonic_totp_used_' . md5( $secret ) );
	$m = new ReflectionMethod( 'Fotonic_TOTP', 'hotp' );
	if ( PHP_VERSION_ID < 80100 ) {
		$m->setAccessible( true );
	}
	return (string) $m->invoke( null, $secret, (int) floor( time() / 30 ) );
}

$GLOBALS['t_mail'] = array();
add_filter( 'pre_wp_mail', function ( $r, $atts ) { $GLOBALS['t_mail'][] = $atts; return true; }, 10, 2 );

t_clear_vault_transients();
Fotonic_Vault::reset_vault();

echo "-- Task 1.1: two-phase setup\n";
$secret = Fotonic_TOTP::generate_secret();
t_true( Fotonic_Vault::stage_setup( 'password8', $secret ), 'stage ok' );
t_equals( false, Fotonic_Vault::is_setup(), 'staged vault is not live' );
t_equals( null, Fotonic_Vault::get_session_key(), 'staging sets no cookie' );
t_true( Fotonic_Vault::has_pending_setup(), 'pending recorded' );
t_equals( false, Fotonic_Vault::confirm_setup( 'wrongpass', t_otp( $secret ) ), 'wrong password rejected' );
t_equals( false, Fotonic_Vault::confirm_setup( 'password8', '000000' === t_otp( $secret ) ? '111111' : '000000' ), 'wrong otp rejected' );
t_equals( false, Fotonic_Vault::is_setup(), 'failed confirm promotes nothing' );
t_true( Fotonic_Vault::confirm_setup( 'password8', t_otp( $secret ) ), 'confirm ok' );
t_true( Fotonic_Vault::is_setup() && null !== Fotonic_Vault::get_session_key(), 'live and unlocked' );
t_equals( false, Fotonic_Vault::has_pending_setup(), 'pending cleared' );
Fotonic_Vault::lock();
t_true( Fotonic_Vault::unlock( 'password8', t_otp( $secret ) ), 'normal unlock works after confirm' );
t_equals( 8, Fotonic_Vault::MIN_PASSWORD_LENGTH, 'min length 8' );

echo "-- Task 1.2: recovery kit, mailer, ack\n";
t_true( Fotonic_Vault::unlock( 'password8', t_otp( $secret ) ), 'reopen the 1.1 vault' );
t_equals( false, Fotonic_Vault::is_recovery_kit_ok(), 'fresh vault still needs its kit' );
$kit = Fotonic_Vault::issue_recovery_kit();
t_true( is_array( $kit ) && 55 === strlen( $kit['recovery_phrase'] ) && 24 === strlen( $kit['recovery_code'] ), 'kit has phrase (8x6 + dashes) and code (5x4 + dashes)' );
t_equals( false, Fotonic_Vault::is_recovery_kit_ok(), 'issued but not acked' );
t_true( Fotonic_Vault::set_recovery_ack( true ), 'ack ok' );
t_true( Fotonic_Vault::is_recovery_kit_ok(), 'kit ok after ack' );
$kit = Fotonic_Vault::issue_recovery_kit(); // Task 1.3 uses this, the live kit.
t_equals( false, Fotonic_Vault::is_recovery_kit_ok(), 'reissue drops ack (tab closed before ack still blocks)' );
delete_option( Fotonic_Vault::OPTION_RECOVERY_ACK );
t_equals( false, Fotonic_Vault::is_recovery_kit_ok(), 'existing install without ack option needs kit' );
t_true( Fotonic_Vault_Mailer::send_recovery_kit( $kit['recovery_code'], $kit['recovery_phrase'] ), 'mail sent' );
$m = end( $GLOBALS['t_mail'] );
t_equals( get_option( 'admin_email' ), $m['to'], 'kit goes to admin_email' );
t_true( false !== strpos( $m['message'], $kit['recovery_phrase'] ) && false !== strpos( $m['message'], $kit['recovery_code'] ), 'mail carries both secrets' );
t_equals( 'b***@gmail.com', Fotonic_Vault_Mailer::mask_email( 'bonacina.ale@gmail.com' ), 'mask' );
Fotonic_Vault::lock();
t_equals( false, Fotonic_Vault::issue_recovery_kit(), 'locked vault cannot issue' );

echo "-- Task 1.3: unified recovery + pending TOTP\n";
Fotonic_Vault::lock();
t_equals( false, Fotonic_Vault::recover_with_secret( 'AAAA-BBBB', 'newpass88' ), 'bad secret rejected' );
t_true( Fotonic_Vault::recover_with_secret( strtolower( $kit['recovery_phrase'] ), 'newpass88' ), 'phrase recovers (case-insensitive)' );
t_true( Fotonic_Vault::has_pending_totp() && Fotonic_Vault::is_unlocked(), 'unlocked with pending totp' );
Fotonic_Vault::lock();
t_true( Fotonic_Vault::unlock( 'newpass88', t_otp( $secret ) ), 'old TOTP still live before confirm' );
Fotonic_Vault::lock();
t_true( Fotonic_Vault::recover_with_secret( str_replace( '-', ' ', $kit['recovery_code'] ), 'newpass99' ), 'interrupted recovery repeats with the code' );
$new = Fotonic_Vault::stage_pending_totp();
t_equals( strtoupper( $new ), strtoupper( (string) Fotonic_Vault::get_pending_totp_secret() ), 'pending secret is readable back unchanged (QR stays valid)' );
t_true( Fotonic_Vault::confirm_pending_totp( t_otp( $new ) ), 'pending totp confirmed' );
t_equals( false, Fotonic_Vault::has_pending_totp(), 'pending cleared' );
t_equals( false, Fotonic_Vault::is_recovery_kit_ok(), 'recovery forces a new kit' );
Fotonic_Vault::lock();
t_true( Fotonic_Vault::unlock( 'newpass99', t_otp( $new ) ), 'new password + new TOTP unlock' );
t_equals( false, method_exists( 'Fotonic_Vault', 'recover_reset_totp' ), 'old path removed' );

echo "-- Task 1.4: email-gated destructive reset\n";
delete_transient( Fotonic_Vault::TRANSIENT_RESET_REQUESTS );
$code = Fotonic_Vault::request_reset_code();
t_true( is_string( $code ) && 1 === preg_match( '/^\d{8}$/', $code ), '8-digit code' );
for ( $i = 0; $i < 5; $i++ ) { Fotonic_Vault::verify_reset_code( '00000000' === $code ? '11111111' : '00000000' ); }
t_equals( false, Fotonic_Vault::verify_reset_code( $code ), 'locked out after 5 wrong codes' );
$code = Fotonic_Vault::request_reset_code();
t_true( Fotonic_Vault::verify_reset_code( $code ), 'fresh code verifies' );
t_equals( false, Fotonic_Vault::verify_reset_code( $code ), 'code is single use' );
Fotonic_Vault::request_reset_code();
t_equals( false, Fotonic_Vault::request_reset_code(), '4th request in an hour refused' );
t_true( Fotonic_Vault::verify_credentials( 'newpass99', t_otp( $new ) ), 'settings reset: right password + otp' );
t_equals( false, Fotonic_Vault::verify_credentials( 'wrongpass', t_otp( $new ) ), 'settings reset: wrong password' );

// @@APPEND@@

t_clear_vault_transients();
$_COOKIE = $t_cookie;
t_done();
