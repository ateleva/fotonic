<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Which of our plugins has a newer version waiting, for the "Update available" button of the app.
 *
 * WordPress already knows it: its own update check stores the answer in the site transient `update_plugins`
 * (the free plugin comes from WordPress.org, an add-on can add itself to the same transient). This class only
 * reads that answer, so it makes no request of its own and never slows a page. Only a person who may update
 * plugins sees anything. An add-on adds its own line through the filter `ftnc_pending_updates`, with `entry()`.
 */
class Fotonic_Update_Notice {

	/**
	 * One pending update, or null when this plugin is up to date (or the transient does not know it).
	 *
	 * @param string $basename `folder/file.php`, as WordPress lists the plugin.
	 * @param string $id       Short key of the plugin ('free', 'pro').
	 * @param string $name     Name shown to the person.
	 * @param string $current  Version installed now.
	 * @param string $note     Optional sentence shown under the list (the add-on says what to do first).
	 * @return array<string,string>|null
	 */
	public static function entry( string $basename, string $id, string $name, string $current, string $note = '' ) {
		$transient = get_site_transient( 'update_plugins' );
		if ( ! is_object( $transient ) || empty( $transient->response[ $basename ] ) ) {
			return null;
		}
		$update = $transient->response[ $basename ];
		$latest = is_object( $update ) && isset( $update->new_version ) ? (string) $update->new_version : '';
		if ( '' === $latest || ! version_compare( $latest, $current, '>' ) ) {
			return null;
		}
		return array(
			'id'      => $id,
			'name'    => $name,
			'current' => $current,
			'latest'  => $latest,
			'note'    => $note,
		);
	}

	/**
	 * What the app shows: `items` (the plugin first, then what an add-on added) and `url` of the page that updates them.
	 * Empty `items` for a person who cannot update plugins.
	 *
	 * @return array{items:array<int,array<string,string>>,url:string}
	 */
	public static function get(): array {
		$out = array( 'items' => array(), 'url' => admin_url( 'plugins.php' ) );
		if ( ! current_user_can( 'update_plugins' ) ) {
			return $out;
		}
		$items = array();
		$own   = self::entry( plugin_basename( FOTONIC_DIR . 'fotonic.php' ), 'free', 'Eleva CRM', FOTONIC_VERSION );
		if ( $own ) {
			$items[] = $own;
		}
		$items = apply_filters( 'ftnc_pending_updates', $items );
		if ( ! is_array( $items ) ) {
			return $out;
		}
		foreach ( $items as $item ) {
			if ( is_array( $item ) && ! empty( $item['id'] ) && ! empty( $item['name'] ) && ! empty( $item['latest'] ) ) {
				$out['items'][] = array(
					'id'      => (string) $item['id'],
					'name'    => (string) $item['name'],
					'current' => isset( $item['current'] ) ? (string) $item['current'] : '',
					'latest'  => (string) $item['latest'],
					'note'    => isset( $item['note'] ) ? (string) $item['note'] : '',
				);
			}
		}
		return $out;
	}
}
