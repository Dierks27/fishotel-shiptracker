<?php
/**
 * Plugin deactivation — does NOT delete data.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Deactivator {

    /**
     * Run on plugin deactivation.
     * We intentionally do NOT remove database tables or options here.
     * Data is only removed via the explicit "Delete All Data" button in settings.
     */
    public static function deactivate() {
        // Unschedule Action Scheduler events.
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( 'fst_poll_shipments', array(), 'fishotel-shiptracker' );
        }

        // Clear any transients.
        delete_transient( 'fst_ups_access_token' );
        delete_transient( 'fst_usps_access_token' );

        flush_rewrite_rules();
    }
}
