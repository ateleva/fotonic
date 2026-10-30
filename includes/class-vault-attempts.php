<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Failed-attempt limiter for the Vault secrets (password, authenticator code, recovery secret).
 *
 * Two rules, both per Vault and not per WordPress user, because the secret being guessed is
 * the same whoever asks:
 *  - at most MAX_FAILURES failures per WINDOW in each bucket;
 *  - one attempt at a time. acquire() takes a short database lock that is held until the
 *    returned object goes out of scope, so a request that arrives while the slow key
 *    derivation of another one is still running is refused, instead of being checked
 *    against a counter that has not been updated yet.
 *
 * Usage in a REST handler:
 *   $attempts = Fotonic_Vault_Attempts::acquire( 'credentials' );
 *   if ( null === $attempts || $attempts->exhausted() ) { return rate-limited answer; }
 *   ... check the secret ...
 *   on failure: $attempts->failed();   on success: $attempts->clear();
 * The lock is released when $attempts is destroyed, which happens when the handler returns.
 */
final class Fotonic_Vault_Attempts {

	const MAX_FAILURES = 5;
	const WINDOW       = 900; // 15 minutes.
	const LOCK_NAME    = 'fotonic_vault_attempt_lock';
	const LOCK_TTL     = 30;  // Seconds before a lock left by a crashed request counts as stale.
	const BUCKETS      = [ 'credentials', 'recovery' ];

	/** @var string */
	private $bucket;

	/** @var string Unique value stored in the lock row, so only its owner can release it. */
	private $token;

	private function __construct( string $bucket, string $token ) {
		$this->bucket = $bucket;
		$this->token  = $token;
	}

	/**
	 * Take the vault attempt lock.
	 *
	 * @param string $bucket One of self::BUCKETS.
	 * @return self|null The held attempt, or null when another attempt is already in progress.
	 */
	public static function acquire( string $bucket ): ?self {
		$token = time() . '.' . wp_generate_password( 12, false );
		if ( ! self::take_lock( $token ) ) {
			return null;
		}
		return new self( $bucket, $token );
	}

	/** True once MAX_FAILURES failures are on record in this bucket. */
	public function exhausted(): bool {
		return (int) get_transient( $this->key() ) >= self::MAX_FAILURES;
	}

	/** Record one failure. Safe to read-then-write because the lock is held. */
	public function failed(): void {
		set_transient( $this->key(), (int) get_transient( $this->key() ) + 1, self::WINDOW );
	}

	/** Forget the failures after a success. */
	public function clear(): void {
		delete_transient( $this->key() );
	}

	/** Forget every bucket (a fresh vault starts from zero). */
	public static function clear_all(): void {
		foreach ( self::BUCKETS as $bucket ) {
			delete_transient( 'fotonic_vault_attempts_' . $bucket );
		}
	}

	public function __destruct() {
		self::release_lock( $this->token );
	}

	private function key(): string {
		return 'fotonic_vault_attempts_' . $this->bucket;
	}

	/**
	 * Atomic "create if absent" on the unique option_name: the same pattern WordPress core
	 * uses for its own upgrade lock. A lock older than LOCK_TTL is stale and is taken over.
	 */
	private static function take_lock( string $token ): bool {
		global $wpdb;

		for ( $try = 0; $try < 2; $try++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic lock row, see the method comment.
			$created = $wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO `{$wpdb->options}` ( `option_name`, `option_value`, `autoload` ) VALUES ( %s, %s, 'no' ) /* FOTONIC VAULT LOCK */",
					self::LOCK_NAME,
					$token
				)
			);
			if ( $created ) {
				return true;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads the lock row straight from the table, the options cache does not know about it.
			$held = (string) $wpdb->get_var(
				$wpdb->prepare( "SELECT option_value FROM `{$wpdb->options}` WHERE option_name = %s", self::LOCK_NAME )
			);
			$since = (int) $held;
			if ( '' !== $held && $since > time() - self::LOCK_TTL ) {
				return false; // A live attempt holds it.
			}

			// Stale (or vanished between the two queries): remove exactly that row and retry once.
			self::delete_lock_row( $held );
		}
		return false;
	}

	private static function release_lock( string $token ): void {
		self::delete_lock_row( $token );
	}

	/** Delete the lock row only if it still carries $value (compare-and-delete). */
	private static function delete_lock_row( string $value ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Compare-and-delete on the lock row.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$wpdb->options}` WHERE option_name = %s AND option_value = %s",
				self::LOCK_NAME,
				$value
			)
		);
	}
}
