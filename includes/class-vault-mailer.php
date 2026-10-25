<?php
/**
 * Vault mailer
 *
 * Plain-text emails for the vault: the recovery kit and the destructive-reset
 * code. Both go to the site's administration address (`admin_email`).
 *
 * @package Fotonic
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Fotonic_Vault_Mailer {

	/**
	 * Address that receives vault emails.
	 *
	 * @return string
	 */
	public static function recipient(): string {
		return (string) get_option( 'admin_email' );
	}

	/**
	 * Hide most of an email address for display: first character + "***@" + domain.
	 *
	 * @param string $email Full address.
	 * @return string Masked address, or '' when it is not an address.
	 */
	public static function mask_email( string $email ): string {
		$at = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return '';
		}
		return substr( $email, 0, 1 ) . '***@' . substr( $email, $at + 1 );
	}

	/**
	 * Email the recovery kit (phrase + code).
	 *
	 * @param string $code   Recovery code.
	 * @param string $phrase Recovery phrase.
	 * @return bool wp_mail() result.
	 */
	public static function send_recovery_kit( string $code, string $phrase ): bool {
		$site    = self::site_name();
		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Vault recovery kit', 'eleva-crm-for-photographers' ),
			$site
		);
		$body = sprintf(
			/* translators: 1: site name, 2: site URL, 3: date and time, 4: recovery phrase, 5: recovery code. */
			__(
				'This is the recovery kit for the Eleva CRM vault on %1$s (%2$s), created on %3$s.

Keep this email. If you forget the vault password or lose your authenticator app, open Eleva CRM, click "Lost your password or authenticator?" on the vault screen and paste ONE of these:

Recovery phrase: %4$s
Recovery code: %5$s

Either one opens the vault and lets you set a new password and a new authenticator, without losing any data.

Anyone who reads this email can open the vault. Do not forward it. If you think someone else has seen it, open Eleva CRM > Settings > Vault and generate a new recovery kit: this one stops working at once.

If you did not set up or recover the vault yourself, someone with administrator access to the site did. Check your site\'s administrators.',
				'eleva-crm-for-photographers'
			),
			$site,
			home_url(),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
			$phrase,
			$code
		);
		return (bool) wp_mail( self::recipient(), $subject, $body );
	}

	/**
	 * Email the one-time code that gates the destructive vault reset.
	 *
	 * @param string $code 8-digit reset code.
	 * @return bool wp_mail() result.
	 */
	public static function send_reset_code( string $code ): bool {
		$site    = self::site_name();
		$subject = sprintf(
			/* translators: %s: site name. */
			__( '[%s] Vault reset code', 'eleva-crm-for-photographers' ),
			$site
		);
		$body = sprintf(
			/* translators: 1: site name, 2: site URL, 3: reset code. */
			__(
				'Someone asked to reset the Eleva CRM vault on %1$s (%2$s).

Reset code: %3$s

The code expires in 15 minutes. Resetting the vault makes every piece of data already encrypted unreadable forever.

If you did not ask for this, ignore this email and check your site\'s administrators.',
				'eleva-crm-for-photographers'
			),
			$site,
			home_url(),
			$code
		);
		return (bool) wp_mail( self::recipient(), $subject, $body );
	}

	/**
	 * Site name as plain text (no HTML entities).
	 *
	 * @return string
	 */
	private static function site_name(): string {
		return wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	}
}
