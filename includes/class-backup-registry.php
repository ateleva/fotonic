<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Declarative inventory of every piece of plugin-owned data.
 *
 * Free declares its own datasets/options here; Pro extends both lists via the
 * `ftnc_backup_datasets` / `ftnc_backup_options` filters instead of duplicating
 * free's knowledge of its own meta keys. `denied_options()` is the hard safety
 * net and is deliberately NOT filterable — it must win even if a Pro filter
 * callback allowlists something dangerous by mistake.
 *
 * This class only describes the data. It does not collect, encrypt, or restore
 * anything — see the master plan's Phase 4 (archive builder).
 */
class Fotonic_Backup_Registry {

    /**
     * One entry per plugin-owned CPT.
     *
     * Shape: post_type, label, supports (post fields to capture), meta_keys
     * (exact keys owned by this dataset), taxonomies (taxonomy => options —
     * `[ 'derived' => true ]` means recorded but never restored), ref_fields
     * (meta key => reference kind, for v2's ID-remapping importer).
     *
     * @return array<string, array>
     */
    public static function datasets(): array {
        $datasets = [
            'ftnc_customer' => [
                'post_type'  => 'ftnc_customer',
                'label'      => __( 'Customers', 'eleva-crm-for-photographers' ),
                'supports'   => [ 'title' ],
                'meta_keys'  => [ '_ftnc_people' ],
                'taxonomies' => [],
                'ref_fields' => [],
            ],
            'ftnc_service' => [
                'post_type'  => 'ftnc_service',
                'label'      => __( 'Services', 'eleva-crm-for-photographers' ),
                'supports'   => [ 'title' ],
                'meta_keys'  => [ '_ftnc_base_price', '_ftnc_notes' ],
                'taxonomies' => [],
                'ref_fields' => [],
            ],
            'ftnc_work' => [
                'post_type'  => 'ftnc_work',
                'label'      => __( 'Works', 'eleva-crm-for-photographers' ),
                // 'content': post_content is the notes field on ftnc_work — do not omit.
                'supports'   => [ 'title', 'content' ],
                'meta_keys'  => [
                    '_ftnc_event_date',
                    '_ftnc_event_time_from',
                    '_ftnc_event_time_to',
                    '_ftnc_event_addresses',
                    '_ftnc_customer_id',
                    '_ftnc_owner_type',
                    '_ftnc_owner_id',
                    '_ftnc_work_services',
                    '_ftnc_work_files',
                    '_ftnc_total_price',
                    '_ftnc_installments',
                    '_ftnc_color',
                    '_ftnc_quick_notes',
                    '_ftnc_memory_cards',
                    '_ftnc_backup_done',
                    '_ftnc_formatting_done',
                    '_ftnc_gcal_sync',
                    // Free never writes this (Pro's Google Calendar sync does via
                    // ftnc_after_save_work / its own sync path) but it is work-scoped
                    // state exposed by free's own REST formatter, same as _ftnc_gcal_sync
                    // above — not Pro business data, so free declares it. See PROGRESS.md.
                    '_ftnc_gcal_event_id',
                    // Same rationale: free never writes this (Pro's reminders reconcile
                    // does, via ftnc_after_save_work) but it is work-scoped state exposed
                    // by free's own REST formatter (format_work()'s reminders_base_date),
                    // not Pro business data, so free declares it for backup purposes.
                    '_ftnc_reminders_base_date',
                ],
                'taxonomies' => [
                    // Auto-derived by Fotonic_Meta_Boxes::auto_assign_payment_status() —
                    // never restore it directly, let it recompute.
                    'ftnc_work_payment_status' => [ 'derived' => true ],
                ],
                'ref_fields' => [
                    '_ftnc_customer_id'   => 'post',
                    '_ftnc_owner_id'      => 'user_or_post',
                    '_ftnc_work_services' => 'json:[].service_id',
                    '_ftnc_work_files'    => 'json:[].id|attachment',
                    '_ftnc_memory_cards'  => 'json:[].card_id|post',
                ],
            ],
            'ftnc_memory_card' => [
                'post_type'  => 'ftnc_memory_card',
                'label'      => __( 'Memory Cards', 'eleva-crm-for-photographers' ),
                'supports'   => [ 'title' ],
                // No postmeta at all — ftnc_card_status is this CPT's only state.
                'meta_keys'  => [],
                'taxonomies' => [
                    // NOT derived: this is the card's only state, it must round-trip.
                    'ftnc_card_status' => [ 'derived' => false ],
                ],
                'ref_fields' => [],
            ],
        ];

        return apply_filters( 'ftnc_backup_datasets', $datasets );
    }

    /**
     * Allowlisted wp_options names, after the hard denylist has been applied.
     *
     * @return string[]
     */
    public static function options(): array {
        $options = [
            'fotonic_vault_setup',
            'fotonic_vault_scheme',
            'fotonic_vault_salt',
            'fotonic_vault_wrap_pw',
            'fotonic_vault_salt_rec',
            'fotonic_vault_wrap_rec',
            'fotonic_vault_salt_phrase',
            'fotonic_vault_wrap_phrase',
            'fotonic_vault_wrap_totp',
            // Legacy v1 TOTP secret. Empty/absent on any vault that has been unlocked
            // since the v2 envelope migration shipped, but a not-yet-migrated v1 vault
            // keeps its actual credential here — omitting it would make that vault's
            // archive permanently unrestorable. Cheap to always include.
            'fotonic_vault_totp_secret',
            'fotonic_payment_types',
        ];

        $options = (array) apply_filters( 'ftnc_backup_options', $options );

        return self::strip_denied( $options );
    }

    /**
     * Hard denylist — machine-bound or ephemeral options that must never round-trip
     * unmodified. NOT filterable: this must win even over a Pro bug that allowlists
     * one of these by mistake.
     *
     * @return string[]
     */
    public static function denied_options(): array {
        return [
            'fotonic_server_secret_fallback',
            'fotonic_gcal_refresh_token',
            'fotonic_pro_slm_secret',
            'fotonic_pro_license_last_valid',
            'fotonic_pro_license_expiry',
        ];
    }

    /**
     * Remove denylisted / transient-shaped names from a candidate option list.
     *
     * @param string[] $options Candidate option names.
     * @return string[]
     */
    private static function strip_denied( array $options ): array {
        $denied = self::denied_options();
        $kept   = [];

        foreach ( $options as $name ) {
            if ( ! is_string( $name ) || '' === $name ) {
                continue;
            }
            if ( in_array( $name, $denied, true ) ) {
                continue;
            }
            // Defensive: a transient's real row name carries this prefix. No legitimate
            // backup option should ever be transient-shaped, denylist or not.
            if ( 0 === strpos( $name, '_transient_fotonic_' ) || 0 === strpos( $name, '_transient_timeout_fotonic_' ) ) {
                continue;
            }
            $kept[] = $name;
        }

        return array_values( array_unique( $kept ) );
    }

    /**
     * Scan the live DB for `_ftnc_%` postmeta keys present on registered post types
     * but absent from the registry — the guard against the registry going stale.
     *
     * @return string[] Entries shaped "{post_type}::{meta_key}".
     */
    public static function audit(): array {
        global $wpdb;

        $stale = [];

        foreach ( self::datasets() as $dataset ) {
            $post_type = $dataset['post_type'];
            $known     = $dataset['meta_keys'];

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off drift audit, not a hot path.
            $found = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT pm.meta_key FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE p.post_type = %s AND pm.meta_key LIKE %s",
                $post_type,
                $wpdb->esc_like( '_ftnc_' ) . '%'
            ) );

            foreach ( $found as $meta_key ) {
                if ( ! in_array( $meta_key, $known, true ) ) {
                    $stale[] = $post_type . '::' . $meta_key;
                }
            }
        }

        return $stale;
    }

    /**
     * Cheap, WP-CLI-less audit entry point: visiting the plugin admin page with
     * `?ftnc_backup_audit=1` wp_die()s with the drift report. Gated to manage_options.
     * Phase 6 can surface this properly in the UI.
     *
     * @return void
     */
    public static function maybe_run_audit(): void {
        if ( ! is_admin() ) {
            return;
        }

        $page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only diagnostic view, no state change.
        if ( 'fotonic' !== $page || ! isset( $_GET['ftnc_backup_audit'] ) ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to run this.', 'eleva-crm-for-photographers' ) );
        }

        $stale = self::audit();

        if ( empty( $stale ) ) {
            wp_die( esc_html__( 'Backup registry audit: no stale meta keys found. Registry is in sync.', 'eleva-crm-for-photographers' ) );
        }

        $rows = '';
        foreach ( $stale as $entry ) {
            $rows .= '<li>' . esc_html( $entry ) . '</li>';
        }

        wp_die(
            '<h1>' . esc_html__( 'Backup registry audit', 'eleva-crm-for-photographers' ) . '</h1>'
            . '<p>' . esc_html__( 'Meta keys found in the database that are not declared in the backup registry:', 'eleva-crm-for-photographers' ) . '</p>'
            . '<ul>' . $rows . '</ul>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $rows built entirely from esc_html() above.
        );
    }
}
