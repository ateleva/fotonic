<?php
/**
 * Minimal CLI assertion helpers for Eleva CRM dev tests. No PHPUnit on purpose:
 * zero dev dependencies. Dev-only, never shipped (excluded from every release).
 */
$GLOBALS['ftnc_failures'] = 0;
$GLOBALS['ftnc_cleanup']  = [];
$GLOBALS['ftnc_options']  = [];

function t_equals( $expected, $actual, string $label ): void {
    if ( $expected === $actual ) {
        echo "  ok   {$label}\n";
        return;
    }
    $GLOBALS['ftnc_failures']++;
    echo "  FAIL {$label}\n       expected: " . var_export( $expected, true ) . "\n       actual:   " . var_export( $actual, true ) . "\n";
}

function t_money( float $expected, $actual, string $label ): void {
    $ok = is_numeric( $actual ) && abs( $expected - (float) $actual ) < 0.001;
    t_equals( true, $ok, $label . ' (got ' . var_export( $actual, true ) . ')' );
}

function t_true( $condition, string $label ): void {
    t_equals( true, (bool) $condition, $label );
}

/** Force-delete this post when the script ends, pass or fail. */
function t_cleanup( int $post_id ): int {
    $GLOBALS['ftnc_cleanup'][] = $post_id;
    return $post_id;
}

/** Snapshot options now; t_done() restores them. */
function t_preserve_options( array $names ): void {
    foreach ( $names as $name ) {
        $GLOBALS['ftnc_options'][ $name ] = get_option( $name, null );
    }
}

function t_done(): void {
    if ( function_exists( 'wp_delete_post' ) ) {
        foreach ( array_reverse( $GLOBALS['ftnc_cleanup'] ) as $id ) {
            wp_delete_post( $id, true );
        }
        foreach ( $GLOBALS['ftnc_options'] as $name => $value ) {
            if ( null === $value ) {
                delete_option( $name );
            } else {
                update_option( $name, $value );
            }
        }
    }
    $failures = $GLOBALS['ftnc_failures'];
    echo $failures ? "\n{$failures} failure(s)\n" : "\nall passed\n";
    exit( $failures ? 1 : 0 );
}
