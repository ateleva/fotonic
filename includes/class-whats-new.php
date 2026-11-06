<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * "What's new" after an update, and the changelog pages.
 *
 * Each release can ship a few short entries, one per change, tied to a section of the app (works, services,
 * kanban...). The app shows a small icon next to that section in the sidebar; opening it explains the change,
 * and closing it dismisses it for good, for that admin user only (user meta, so it follows them across
 * browsers). A site that installed the plugin fresh sees nothing: activation stores a baseline version and
 * only entries newer than it show. A site that updated from before this existed has no baseline, which counts
 * as the last version without entries (1.6.4).
 *
 * Both plugins add entries through the `ftnc_whats_new_entries` filter and a changelog source through
 * `ftnc_changelog_sources`; the interface lives here, in free.
 *
 * Writing rule for every text below and in Pro: it is read by a photographer, not a technician. No
 * calculations, no technical or WordPress words, a title and one or two short sentences, a small plain
 * example when it helps.
 */
class Fotonic_Whats_New {

	const BASELINE_OPTION = 'fotonic_whats_new_baseline';
	const USER_META       = 'fotonic_whats_new_dismissed';
	/** What a site without a baseline is taken to have last seen. */
	const DEFAULT_BASELINE = '1.6.4';
	const MAX_DISMISSED    = 500;

	// -------------------------------------------------------------------------
	// Baseline
	// -------------------------------------------------------------------------

	/** Called on a fresh activation of a plugin: nothing older than this version is "new" to that site. */
	public static function set_baseline( string $plugin, string $version ): void {
		$all = get_option( self::BASELINE_OPTION, [] );
		$all = is_array( $all ) ? $all : [];
		if ( ! isset( $all[ $plugin ] ) ) {
			$all[ $plugin ] = $version;
			update_option( self::BASELINE_OPTION, $all, false );
		}
	}

	public static function baseline( string $plugin ): string {
		$all = get_option( self::BASELINE_OPTION, [] );
		return is_array( $all ) && ! empty( $all[ $plugin ] ) ? (string) $all[ $plugin ] : self::DEFAULT_BASELINE;
	}

	// -------------------------------------------------------------------------
	// Entries
	// -------------------------------------------------------------------------

	/**
	 * Entries of this plugin. Section keys: dashboard, works, services, kanban, analytics, expenses,
	 * suppliers, quotes (the sidebar entry each one is shown next to).
	 *
	 * @return array<int, array{id:string,plugin:string,since:string,section:string,title:string,body:string,banner?:bool}>
	 */
	private static function free_entries(): array {
		return [
			[
				'id'      => '1.7.0:settings-bin',
				'plugin'  => 'free',
				'since'   => '1.7.0',
				'section' => 'settings',
				'title'   => __( 'The Bin is now visible and yours to manage', 'eleva-crm-for-photographers' ),
				'body'    => __( 'Everything you delete now waits in a Bin that you can see. Each list (customers, works, quotes and so on) shows a small Bin button as soon as something of that kind is waiting there, and Settings has a Bin card too. The Bin page tells you in how many days each item disappears by itself, and lets you bring items back or delete them for good, one by one or all together. Nothing is hidden from you.', 'eleva-crm-for-photographers' ),
				'banner'  => true,
			],
			[
				'id'      => '1.7.0:titles-protected',
				'plugin'  => 'free',
				'since'   => '1.7.0',
				'section' => 'works',
				'title'   => __( 'The names of clients, works and quotes are protected', 'eleva-crm-for-photographers' ),
				'body'    => __( 'The titles you give to clients, works and quotes are now locked by the Vault too, like your notes. You see them and search them as always, and the search of clients also finds the name, email or phone number of the people in them.', 'eleva-crm-for-photographers' ),
				'banner'  => true,
			],
			[
				'id'      => '1.7.0:works-documents',
				'plugin'  => 'free',
				'since'   => '1.7.0',
				'section' => 'works',
				'title'   => __( 'Contracts and documents are kept safe', 'eleva-crm-for-photographers' ),
				'body'    => __( 'The contracts and other documents you attach to a work are now stored in a private place and open only with the Vault unlocked, file names included. The ones you had already uploaded are moved there for you when the Vault protects your data after the update.', 'eleva-crm-for-photographers' ),
				'banner'  => true,
			],
			[
				'id'      => '1.7.0:settings-restore',
				'plugin'  => 'free',
				'since'   => '1.7.0',
				'section' => 'settings',
				'title'   => __( 'Restore a backup', 'eleva-crm-for-photographers' ),
				'body'    => __( 'You can now put a backup back into Eleva CRM, from your computer or, with Eleva CRM Pro, from Google Drive. You see what is already there and what is missing, you choose what to keep, and you can undo it. Find it in Settings, under Backups.', 'eleva-crm-for-photographers' ),
				'banner'  => true,
			],
			[
				'id'      => '1.6.6:settings-fingerprint',
				'plugin'  => 'free',
				'since'   => '1.6.6',
				'section' => 'settings',
				'title'   => __( 'Unlock the Vault with a touch', 'eleva-crm-for-photographers' ),
				'body'    => __( 'If your computer or phone has a fingerprint reader or face unlock, you can open the Vault with a touch instead of typing the password and the code. Turn it on in Settings, under Vault. Your password and code still work.', 'eleva-crm-for-photographers' ),
				'banner'  => true,
			],
			[
				'id'      => '1.6.6:storage-page',
				'plugin'  => 'free',
				'since'   => '1.6.6',
				'section' => 'storage',
				'title'   => __( 'Storage: cards, backups and shots in one place', 'eleva-crm-for-photographers' ),
				'body'    => __( 'Memory Cards now live in Storage, next to two new tabs: Backup Locations, where you list the drives and cloud spaces that hold your backups, and Shots History.', 'eleva-crm-for-photographers' ),
				'banner'  => true,
			],
			[
				'id'      => '1.6.6:works-backups',
				'plugin'  => 'free',
				'since'   => '1.6.6',
				'section' => 'works',
				'title'   => __( 'Where each work is backed up', 'eleva-crm-for-photographers' ),
				'body'    => __( 'In a work, choose the backup locations where its photos are saved. If you delete a location later, the works that used it show a red sign in the list, and a button above the list shows only those works.', 'eleva-crm-for-photographers' ),
			],
			[
				'id'      => '1.6.6:works-shots-delivery',
				'plugin'  => 'free',
				'since'   => '1.6.6',
				'section' => 'works',
				'title'   => __( 'Shots and delivery', 'eleva-crm-for-photographers' ),
				'body'    => __( 'Write how many photos you took and delivered, and how you handed them over: a gallery link, a transfer or a USB stick. A Copy message button prepares the text for your client, with the link, the password and the expiry date. The Shots History tab adds it all up by year.', 'eleva-crm-for-photographers' ),
			],
			[
				'id'      => '1.6.6:works-payments',
				'plugin'  => 'free',
				'since'   => '1.6.6',
				'section' => 'works',
				'title'   => __( 'Payments are easier to get right', 'eleva-crm-for-photographers' ),
				'body'    => __( 'On each installment you now write the part of the services price it covers. The installments must add up to the amount shown above them, and the total is worked out for you, so you can no longer get it wrong. Works you saved before show a notice: check their installments the next time you open them.', 'eleva-crm-for-photographers' ),
				'banner'  => true,
			],
			[
				'id'      => '1.6.6:works-search',
				'plugin'  => 'free',
				'since'   => '1.6.6',
				'section' => 'works',
				'title'   => __( 'Find a record by typing', 'eleva-crm-for-photographers' ),
				'body'    => __( 'The fields where you pick a customer, a service, a memory card or a backup location now have a search: type a few letters and choose. The customer list is no longer limited to 100.', 'eleva-crm-for-photographers' ),
			],
			[
				'id'      => '1.6.6:works-pages',
				'plugin'  => 'free',
				'since'   => '1.6.6',
				'section' => 'works',
				'title'   => __( 'Long lists have pages', 'eleva-crm-for-photographers' ),
				'body'    => __( 'Works show 48 at a time and the other lists 24, with Previous and Next buttons. Before, only the first 20 works and customers were shown.', 'eleva-crm-for-photographers' ),
			],
			[
				'id'      => '1.6.6:dashboard-tabs',
				'plugin'  => 'free',
				'since'   => '1.6.6',
				'section' => 'dashboard',
				'title'   => __( 'Every page names its browser tab', 'eleva-crm-for-photographers' ),
				'body'    => __( 'With several tabs open, each one says which page it shows, for example Works or Settings. When the Vault is locked, the tab says so with a lock.', 'eleva-crm-for-photographers' ),
			],
			[
				'id'      => '1.6.5:works-payment-types',
				'plugin'  => 'free',
				'since'   => '1.6.5',
				'section' => 'works',
				'title'   => __( 'Payment types are easier to find', 'eleva-crm-for-photographers' ),
				'body'    => __( 'Add, rename or remove your payment types from the new button at the top of the Works list. No more scrolling down to find them.', 'eleva-crm-for-photographers' ),
			],
			[
				'id'      => '1.6.5:works-tidier',
				'plugin'  => 'free',
				'since'   => '1.6.5',
				'section' => 'works',
				'title'   => __( 'Tidier forms', 'eleva-crm-for-photographers' ),
				'body'    => __( 'Fields and buttons now have the same height everywhere in the app, so forms line up and look cleaner.', 'eleva-crm-for-photographers' ),
			],
			[
				'id'      => '1.6.5:services-form',
				'plugin'  => 'free',
				'since'   => '1.6.5',
				'section' => 'services',
				'title'   => __( 'A more compact service form', 'eleva-crm-for-photographers' ),
				'body'    => __( 'The name and the base price of a service now sit on the same row, with the notes below. The arrows on a price change it by one whole euro.', 'eleva-crm-for-photographers' ),
			],
		];
	}

	/** @return array<int, array> Every entry of both plugins, whoever the user. */
	public static function all_entries(): array {
		$entries = apply_filters( 'ftnc_whats_new_entries', self::free_entries() );
		return is_array( $entries ) ? array_values( $entries ) : [];
	}

	/** @return string[] Entry ids this user has closed. */
	public static function dismissed( int $user_id ): array {
		$list = get_user_meta( $user_id, self::USER_META, true );
		return is_array( $list ) ? array_values( array_map( 'strval', $list ) ) : [];
	}

	/** @param string[] $ids */
	public static function dismiss( int $user_id, array $ids ): void {
		$clean = [];
		foreach ( $ids as $id ) {
			$id = (string) $id;
			if ( preg_match( '/^[A-Za-z0-9:._\-]{1,100}$/', $id ) ) {
				$clean[] = $id;
			}
		}
		$list = array_values( array_unique( array_merge( self::dismissed( $user_id ), $clean ) ) );
		if ( count( $list ) > self::MAX_DISMISSED ) {
			$list = array_slice( $list, -self::MAX_DISMISSED );
		}
		update_user_meta( $user_id, self::USER_META, $list );
	}

	/**
	 * What this user still has to see: entries newer than their plugin's baseline and not closed yet, and the
	 * overview shown once after an update (its id carries the newest version, so a later update shows it again).
	 *
	 * @return array{entries: array, overview: array{id:string,version:string,show:bool}}
	 */
	public static function visible_for( int $user_id ): array {
		$dismissed = self::dismissed( $user_id );
		$visible   = [];
		$newest    = '';
		foreach ( self::all_entries() as $e ) {
			if ( ! is_array( $e ) || empty( $e['id'] ) || empty( $e['since'] ) || empty( $e['section'] ) ) {
				continue;
			}
			$plugin = ( isset( $e['plugin'] ) && 'pro' === $e['plugin'] ) ? 'pro' : 'free';
			if ( version_compare( (string) $e['since'], self::baseline( $plugin ), '<=' ) ) {
				continue;
			}
			if ( in_array( (string) $e['id'], $dismissed, true ) ) {
				continue;
			}
			$visible[] = [
				'id'      => (string) $e['id'],
				'plugin'  => $plugin,
				'since'   => (string) $e['since'],
				'section' => (string) $e['section'],
				'title'   => (string) ( $e['title'] ?? '' ),
				'body'    => (string) ( $e['body'] ?? '' ),
				'banner'  => ! empty( $e['banner'] ),
			];
			if ( '' === $newest || version_compare( (string) $e['since'], $newest, '>' ) ) {
				$newest = (string) $e['since'];
			}
		}
		$overview_id = 'overview:' . $newest;
		return [
			'entries'  => $visible,
			'overview' => [
				'id'      => $overview_id,
				'version' => $newest,
				'show'    => $visible && ! in_array( $overview_id, $dismissed, true ),
			],
		];
	}

	// -------------------------------------------------------------------------
	// Changelog
	// -------------------------------------------------------------------------

	/**
	 * Releases of a readme.txt changelog ("= 1.6.4 =" blocks of "* item" lines), newest first as written.
	 *
	 * @return array<int, array{version:string,items:string[]}>
	 */
	public static function parse_changelog( string $text, int $max = 40 ): array {
		$text = str_replace( [ "\r\n", "\r" ], "\n", $text );
		if ( ! preg_match( '/^==\s*Changelog\s*==\s*$(.*?)(?=^==[^=]|\z)/ms', $text, $m ) ) {
			return [];
		}
		$releases = [];
		$current  = null;
		foreach ( explode( "\n", $m[1] ) as $line ) {
			$line = rtrim( $line );
			if ( preg_match( '/^=\s*([0-9][0-9A-Za-z.\-]*)\s*=\s*$/', $line, $v ) ) {
				if ( null !== $current ) {
					$releases[] = $current;
				}
				if ( count( $releases ) >= $max ) {
					$current = null;
					break;
				}
				$current = [ 'version' => $v[1], 'items' => [] ];
			} elseif ( null !== $current && preg_match( '/^\*\s+(.+)$/', $line, $i ) ) {
				$current['items'][] = $i[1];
			}
		}
		if ( null !== $current ) {
			$releases[] = $current;
		}
		return $releases;
	}

	/**
	 * Italian (or other) text of the changelog sentences: a plain file `changelog-{locale}.json` in the
	 * plugin's languages folder, an object of "English sentence": "translation". It is not gettext on purpose:
	 * the sentences are read from readme.txt at run time, and a WordPress.org language pack (which wins over
	 * the bundled .mo and only holds what was imported so far) would hide every sentence it does not have yet.
	 * A sentence with no entry stays in English.
	 *
	 * @return array<string,string>
	 */
	public static function changelog_translations( string $languages_dir ): array {
		static $cache = [];
		$file = rtrim( $languages_dir, '/' ) . '/changelog-' . determine_locale() . '.json';
		if ( ! isset( $cache[ $file ] ) ) {
			$cache[ $file ] = [];
			if ( is_readable( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file shipped inside the plugin.
				$data = json_decode( (string) file_get_contents( $file ), true );
				if ( is_array( $data ) ) {
					$cache[ $file ] = $data;
				}
			}
		}
		return $cache[ $file ];
	}

	/**
	 * @param array<int, array{version:string,items:string[]}> $releases
	 * @param array<string,string>                              $map
	 * @return array<int, array{version:string,items:string[]}>
	 */
	public static function translate_releases( array $releases, array $map ): array {
		if ( ! $map ) {
			return $releases;
		}
		foreach ( $releases as &$release ) {
			foreach ( $release['items'] as &$item ) {
				if ( isset( $map[ $item ] ) && is_string( $map[ $item ] ) && '' !== $map[ $item ] ) {
					$item = $map[ $item ];
				}
			}
			unset( $item );
		}
		unset( $release );
		return $releases;
	}

	/**
	 * Where a plugin stands against the newest version WordPress knows about (the update list WordPress keeps,
	 * which Pro's own update checker feeds too). Null when it is up to date.
	 *
	 * @return array{latest:string,update_url:string}|null
	 */
	public static function update_info( string $basename, string $installed ): ?array {
		$updates = get_site_transient( 'update_plugins' );
		$new     = isset( $updates->response[ $basename ]->new_version ) ? (string) $updates->response[ $basename ]->new_version : '';
		if ( '' === $new || '' === $installed || ! version_compare( $new, $installed, '>' ) ) {
			return null;
		}
		return [
			'latest'     => $new,
			'update_url' => admin_url( 'plugins.php?plugin_status=upgrade' ),
		];
	}

	/** @return array<string, array{label:string,version:string,releases:array,update:array|null}> */
	public static function changelog(): array {
		$sources = apply_filters( 'ftnc_changelog_sources', [
			'free' => [
				'label'    => __( 'Eleva CRM', 'eleva-crm-for-photographers' ),
				'version'  => FOTONIC_VERSION,
				'file'     => FOTONIC_DIR . 'readme.txt',
				'basename' => plugin_basename( FOTONIC_DIR . 'fotonic.php' ),
				'languages' => FOTONIC_DIR . 'languages',
			],
		] );
		$out = [];
		foreach ( (array) $sources as $key => $src ) {
			if ( ! is_array( $src ) || empty( $src['file'] ) || ! is_readable( $src['file'] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file shipped inside the plugin.
			$text     = (string) file_get_contents( $src['file'] );
			$releases = self::parse_changelog( $text );
			if ( ! empty( $src['languages'] ) ) {
				$releases = self::translate_releases( $releases, self::changelog_translations( (string) $src['languages'] ) );
			}
			$out[ (string) $key ] = [
				'label'    => (string) ( $src['label'] ?? $key ),
				'version'  => (string) ( $src['version'] ?? '' ),
				'releases' => $releases,
				'update'   => ! empty( $src['basename'] ) ? self::update_info( (string) $src['basename'], (string) ( $src['version'] ?? '' ) ) : null,
			];
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// REST
	// -------------------------------------------------------------------------

	public static function register_routes(): void {
		register_rest_route( Fotonic_REST_API::NAMESPACE, '/whats-new', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'rest_get' ],
			'permission_callback' => [ 'Fotonic_REST_API', 'admin_permission' ],
		] );
		register_rest_route( Fotonic_REST_API::NAMESPACE, '/whats-new/dismiss', [
			'methods'             => 'POST',
			'callback'            => [ __CLASS__, 'rest_dismiss' ],
			'permission_callback' => [ 'Fotonic_REST_API', 'admin_permission' ],
			'args'                => [
				'ids' => [ 'required' => true, 'type' => 'array', 'items' => [ 'type' => 'string' ] ],
			],
		] );
		register_rest_route( Fotonic_REST_API::NAMESPACE, '/changelog', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'rest_changelog' ],
			'permission_callback' => [ 'Fotonic_REST_API', 'admin_permission' ],
		] );
	}

	public static function rest_get(): \WP_REST_Response {
		return new \WP_REST_Response( self::visible_for( get_current_user_id() ), 200 );
	}

	public static function rest_dismiss( \WP_REST_Request $req ): \WP_REST_Response {
		self::dismiss( get_current_user_id(), (array) $req->get_param( 'ids' ) );
		return new \WP_REST_Response( [ 'dismissed' => true ], 200 );
	}

	public static function rest_changelog(): \WP_REST_Response {
		return new \WP_REST_Response( self::changelog(), 200 );
	}
}
