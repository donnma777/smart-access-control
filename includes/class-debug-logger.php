<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Simple debug logger used when WP_DEBUG is enabled.
 */
class GGC_Debug_Logger {
    /**
     * Log a message to the PHP error log when enabled.
     * @param string $message
     */
    public static function log( $message ) {
        if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
            return;
        }

        if ( defined( 'GGC_ENABLE_DEBUG_LOG' ) && ! GGC_ENABLE_DEBUG_LOG ) {
            return;
        }

        $enabled = apply_filters( 'ggc_enable_debug_log', false );
        if ( ! $enabled ) {
            return;
        }

        error_log( '[GGC] ' . $message );
    }
}

if ( ! function_exists( 'ggc_debug_log' ) ) {
    function ggc_debug_log( $message ) {
        GGC_Debug_Logger::log( $message );
    }
}
