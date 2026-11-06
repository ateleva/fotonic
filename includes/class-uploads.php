<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Fotonic_Uploads {
    const DEFAULT_MAX_BYTES = 52428800; // 50 MB: a contract with scans and pictures easily passes 10 MB. Documents of the works are also held back by the memory of the server (Fotonic_Work_Files::max_bytes()).

    /**
     * Single source of truth for the max upload size shown and enforced in the UI.
     * Filterable via `ftnc_max_upload_bytes`; clamped to what PHP will actually accept.
     */
    public static function max_file_bytes(): int {
        $bytes  = (int) apply_filters( 'ftnc_max_upload_bytes', self::DEFAULT_MAX_BYTES );
        $server = (int) wp_max_upload_size();
        if ( $server > 0 && $bytes > $server ) {
            $bytes = $server; // Never advertise more than PHP will actually accept.
        }
        return max( 1, $bytes );
    }
}
