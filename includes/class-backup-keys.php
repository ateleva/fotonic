<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Sealed-box key material for encrypted backups.
 *
 * The point of this class: a backup job running under cron, with the vault
 * LOCKED and no Master Key anywhere in memory, must still be able to encrypt
 * an archive — while the running site remains unable to decrypt it.
 *
 * That is achieved with a libsodium sealed box (X25519 + XSalsa20-Poly1305):
 *
 *   SETUP (once, vault UNLOCKED, MK available)
 *     [pub, priv] = sodium_crypto_box_keypair()
 *     fotonic_backup_pubkey    = base64(pub)                    // plaintext, harmless
 *     fotonic_backup_wrap_priv = Fotonic_Crypto::wrap(priv, MK) // 'w1:' AES-256-GCM
 *
 *   BACKUP (cron, vault LOCKED, no secret needed)
 *     AK        = random_bytes(32)
 *     sealed_AK = sodium_crypto_box_seal(AK, pub)
 *
 *   RESTORE (offline tool, any machine — see Phase 5)
 *     priv = GCM_unwrap(fotonic_backup_wrap_priv, MK)
 *     AK   = sodium_crypto_box_seal_open(sealed_AK, keypair(priv, pub))
 *
 * Note that sodium_crypto_box_seal_open() needs a full KEYPAIR, not a bare
 * secret key — rebuild it with
 * sodium_crypto_box_keypair_from_secretkey_and_publickey($priv, $pub).
 *
 * Security invariants, all load-bearing:
 *
 * 1. This class exposes NO method that returns the raw private key. The only
 *    accessor, wrapped_private_key(), returns it still wrapped under MK.
 *    rewrap() does unwrap internally, but demands the caller already possess
 *    the old MK and never hands the plaintext key back out.
 * 2. Nothing here ever calls Fotonic_Vault::get_session_key(). Sealing must
 *    work with the vault locked; needing the session key would defeat the
 *    entire design.
 * 3. setup() is idempotent and NEVER rotates an existing keypair. Rotating it
 *    would orphan every archive ever produced — they would become permanently
 *    undecryptable. Every early return in setup() protects that property.
 *
 * @see Fotonic_Backup_Cipher for the bulk-data (AK) encryption format.
 */
class Fotonic_Backup_Keys {

    const OPTION_PUBKEY    = 'fotonic_backup_pubkey';
    const OPTION_WRAP_PRIV = 'fotonic_backup_wrap_priv';

    /**
     * Whether this server can do sealed-box crypto at all.
     *
     * sodium_* is PHP core since 7.2 (inside the plugin's 7.4-8.3 window), but
     * a host can still compile it out, so every entry point checks.
     *
     * @return bool
     */
    public static function is_supported(): bool {
        return function_exists( 'sodium_crypto_box_keypair' )
            && function_exists( 'sodium_crypto_box_publickey' )
            && function_exists( 'sodium_crypto_box_secretkey' )
            && function_exists( 'sodium_crypto_box_seal' );
    }

    /**
     * Human-readable reason the backup key material is unusable, for the UI to
     * surface. Empty string when everything is fine.
     *
     * @return string
     */
    public static function unavailable_reason(): string {
        if ( ! self::is_supported() ) {
            return __( 'Unavailable on this server: the Sodium extension is missing.', 'eleva-crm-for-photographers' );
        }
        if ( ! self::is_ready() ) {
            return __( 'Backup keys are not set up yet. Unlock the vault once to generate them.', 'eleva-crm-for-photographers' );
        }
        return '';
    }

    /**
     * Whether both halves of the key material are present.
     *
     * @return bool
     */
    public static function is_ready(): bool {
        return '' !== (string) get_option( self::OPTION_PUBKEY, '' )
            && '' !== (string) get_option( self::OPTION_WRAP_PRIV, '' );
    }

    /**
     * Generate and store the backup keypair. Requires the Master Key, so it can
     * only run while the vault is unlocked.
     *
     * IDEMPOTENT AND NON-DESTRUCTIVE: if key material already exists this
     * returns true without touching it. Never rotate — see invariant 3 above.
     *
     * @param string $master_key Raw 32-byte MK.
     * @return bool True if key material exists after the call.
     */
    public static function setup( string $master_key ): bool {
        if ( ! self::is_supported() ) {
            return false;
        }
        if ( self::is_ready() ) {
            return true; // Already provisioned — never regenerate.
        }
        if ( 32 !== strlen( $master_key ) ) {
            return false;
        }

        $keypair = sodium_crypto_box_keypair();
        $pub     = sodium_crypto_box_publickey( $keypair );
        $priv    = sodium_crypto_box_secretkey( $keypair );

        $wrapped = Fotonic_Crypto::wrap( $priv, $master_key );

        self::memzero( $priv );
        self::memzero( $keypair );

        if ( '' === $wrapped ) {
            return false;
        }

        // Write order matters. The wrapped private key goes FIRST so that a crash
        // between the two writes leaves is_ready() false (and seal() refusing to
        // run, since it also gates on is_ready()). The next setup() then safely
        // regenerates both. Writing the pubkey first would allow a window where
        // data could be sealed to a public key whose private half was never
        // stored — silently unrecoverable.
        update_option( self::OPTION_WRAP_PRIV, $wrapped, false );
        update_option( self::OPTION_PUBKEY, base64_encode( $pub ), false );

        return true;
    }

    /**
     * Re-wrap the stored private key when the Master Key itself changes.
     *
     * Only one vault path actually changes MK: Fotonic_Vault::migrate_legacy(),
     * which mints a fresh MK during the scheme v1 -> v2 upgrade. change_password()
     * and all three recovery paths re-wrap the SAME MK, so they never need this.
     *
     * @param string $old_mk Raw 32-byte MK the private key is currently wrapped under.
     * @param string $new_mk Raw 32-byte MK to wrap it under instead.
     * @return bool True on success, or when there was nothing to re-wrap.
     */
    public static function rewrap( string $old_mk, string $new_mk ): bool {
        if ( ! self::is_supported() ) {
            return false;
        }
        if ( ! self::is_ready() ) {
            return true; // Nothing provisioned yet — not an error.
        }
        if ( 32 !== strlen( $old_mk ) || 32 !== strlen( $new_mk ) ) {
            return false;
        }

        $priv = Fotonic_Crypto::unwrap( (string) get_option( self::OPTION_WRAP_PRIV, '' ), $old_mk );
        if ( false === $priv ) {
            return false;
        }

        $rewrapped = Fotonic_Crypto::wrap( $priv, $new_mk );
        self::memzero( $priv );

        if ( '' === $rewrapped ) {
            return false;
        }

        update_option( self::OPTION_WRAP_PRIV, $rewrapped, false );
        return true;
    }

    /**
     * The raw 32-byte public key, or null when unavailable.
     *
     * @return string|null
     */
    public static function public_key(): ?string {
        $encoded = (string) get_option( self::OPTION_PUBKEY, '' );
        if ( '' === $encoded ) {
            return null;
        }
        $raw = base64_decode( $encoded, true );
        if ( false === $raw || 32 !== strlen( $raw ) ) {
            return null;
        }
        return $raw;
    }

    /**
     * The private key, still wrapped under MK ('w1:...'), for embedding in an
     * archive's key.json. Safe to hand out: useless without the vault password.
     *
     * @return string Empty string when unavailable.
     */
    public static function wrapped_private_key(): string {
        return (string) get_option( self::OPTION_WRAP_PRIV, '' );
    }

    /**
     * Seal a short secret (in practice the 32-byte archive key AK) to the stored
     * public key. Works with the vault locked — that is the whole point.
     *
     * @param string $plaintext Bytes to seal.
     * @return string|false Sealed bytes, or false on any failure.
     */
    public static function seal( string $plaintext ) {
        if ( ! self::is_supported() || ! self::is_ready() ) {
            return false;
        }

        $pub = self::public_key();
        if ( null === $pub ) {
            return false;
        }

        $sealed = sodium_crypto_box_seal( $plaintext, $pub );
        if ( ! is_string( $sealed ) || '' === $sealed ) {
            return false;
        }
        return $sealed;
    }

    /**
     * Delete all backup key material.
     *
     * Called from Fotonic_Vault::reset_vault(), which destroys the MK: without
     * this the site would keep a private key it could never unwrap again.
     *
     * @return void
     */
    public static function delete_all(): void {
        delete_option( self::OPTION_PUBKEY );
        delete_option( self::OPTION_WRAP_PRIV );
    }

    /**
     * Best-effort scrub of a secret from memory.
     *
     * @param string $secret Passed by reference; emptied in place.
     * @return void
     */
    private static function memzero( string &$secret ): void {
        if ( function_exists( 'sodium_memzero' ) ) {
            sodium_memzero( $secret );
            return;
        }
        $secret = str_repeat( "\0", strlen( $secret ) );
    }
}
