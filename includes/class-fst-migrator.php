<?php
/**
 * TrackShip / AST data migration tool.
 *
 * Imports tracking data from Advanced Shipment Tracking (AST) plugin
 * and TrackShip status data into FisHotel ShipTracker tables.
 *
 * AST stores tracking in order meta key: _wc_shipment_tracking_items
 * Each entry is a serialized array of tracking items with:
 *   - tracking_provider  (e.g., 'ups')
 *   - tracking_number    (e.g., '1Z50W72W0104836301')
 *   - date_shipped       (unix timestamp)
 *   - status_shipped     (1 = shipped)
 *   - tracking_id        (unique hash)
 *
 * TrackShip adds additional meta:
 *   - shipment_status       (e.g., 'delivered', 'in_transit')
 *   - ts_shipment_status    (similar status info)
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Migrator {

    /**
     * Provider name mapping from AST to our carrier slugs.
     */
    private static $carrier_map = array(
        'ups'           => 'ups',
        'usps'          => 'usps',
        'united-states-postal-service' => 'usps',
        'us-postal-service' => 'usps',
        'united-parcel-service' => 'ups',
    );

    /**
     * TrackShip status mapping to our internal statuses.
     */
    private static $status_map = array(
        'delivered'           => 'delivered',
        'in_transit'          => 'in_transit',
        'intransit'           => 'in_transit',
        'out_for_delivery'    => 'out_for_delivery',
        'outfordelivery'      => 'out_for_delivery',
        'pre_transit'         => 'pre_transit',
        'pretransit'          => 'pre_transit',
        'label_created'       => 'pre_transit',
        'exception'           => 'exception',
        'alert'               => 'exception',
        'expired'             => 'exception',
        'return_to_sender'    => 'return_to_sender',
        'returntosender'      => 'return_to_sender',
        'failure'             => 'failure',
        'available_for_pickup' => 'out_for_delivery',
        'shipped'             => 'in_transit',
        'unknown'             => 'unknown',
        ''                    => 'unknown',
    );

    /**
     * Scan for available AST/TrackShip data (dry run).
     *
     * @return array {
     *     @type int   $total_orders     Orders with tracking data.
     *     @type int   $total_shipments  Total tracking entries across all orders.
     *     @type int   $already_imported Tracking numbers already in ShipTracker.
     *     @type int   $to_import        New tracking numbers to import.
     *     @type array $carrier_counts   Count by carrier.
     *     @type array $status_counts    Count by status.
     *     @type array $sample           Sample of first 10 items to import.
     * }
     */
    public static function scan() {
        global $wpdb;

        $results = array(
            'total_orders'     => 0,
            'total_shipments'  => 0,
            'already_imported' => 0,
            'to_import'        => 0,
            'carrier_counts'   => array(),
            'status_counts'    => array(),
            'sample'           => array(),
            'date_range'       => array( 'earliest' => '', 'latest' => '' ),
        );

        // Get all orders with AST tracking data.
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_wc_shipment_tracking_items'
             ORDER BY post_id DESC"
        );

        // Also check HPOS table if it exists.
        $hpos_table = $wpdb->prefix . 'wc_orders_meta';
        $hpos_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $hpos_table ) );
        if ( $hpos_exists ) {
            $hpos_rows = $wpdb->get_results(
                "SELECT order_id as post_id, meta_value FROM {$hpos_table}
                 WHERE meta_key = '_wc_shipment_tracking_items'
                 ORDER BY order_id DESC"
            );
            // Merge, avoiding duplicates by order ID.
            $existing_ids = array_map( function( $r ) { return $r->post_id; }, $rows );
            foreach ( $hpos_rows as $hr ) {
                if ( ! in_array( $hr->post_id, $existing_ids, true ) ) {
                    $rows[] = $hr;
                }
            }
        }

        $order_ids = array();
        $earliest  = PHP_INT_MAX;
        $latest    = 0;

        foreach ( $rows as $row ) {
            $items = maybe_unserialize( $row->meta_value );
            if ( ! is_array( $items ) ) {
                continue;
            }

            $order_ids[] = $row->post_id;

            // Get TrackShip status for this order.
            $ts_status = get_post_meta( $row->post_id, 'shipment_status', true );
            if ( empty( $ts_status ) ) {
                $ts_status = get_post_meta( $row->post_id, 'ts_shipment_status', true );
            }

            foreach ( $items as $item ) {
                $results['total_shipments']++;

                $tracking_number = isset( $item['tracking_number'] ) ? trim( $item['tracking_number'] ) : '';
                if ( empty( $tracking_number ) ) {
                    continue;
                }

                // Check if already imported.
                $existing = FST_Shipment::get_by_tracking( $tracking_number );
                if ( $existing ) {
                    $results['already_imported']++;
                    continue;
                }

                $results['to_import']++;

                // Map carrier.
                $provider = isset( $item['tracking_provider'] ) ? strtolower( trim( $item['tracking_provider'] ) ) : '';
                $carrier  = isset( self::$carrier_map[ $provider ] ) ? self::$carrier_map[ $provider ] : FST_Carrier::detect_carrier( $tracking_number );

                if ( ! isset( $results['carrier_counts'][ $carrier ] ) ) {
                    $results['carrier_counts'][ $carrier ] = 0;
                }
                $results['carrier_counts'][ $carrier ]++;

                // Map status.
                $status_raw = is_array( $ts_status ) ? '' : strtolower( trim( (string) $ts_status ) );
                $status     = isset( self::$status_map[ $status_raw ] ) ? self::$status_map[ $status_raw ] : 'unknown';

                if ( ! isset( $results['status_counts'][ $status ] ) ) {
                    $results['status_counts'][ $status ] = 0;
                }
                $results['status_counts'][ $status ]++;

                // Date tracking.
                $date_shipped = isset( $item['date_shipped'] ) ? (int) $item['date_shipped'] : 0;
                if ( $date_shipped > 0 ) {
                    if ( $date_shipped < $earliest ) $earliest = $date_shipped;
                    if ( $date_shipped > $latest ) $latest = $date_shipped;
                }

                // Add to sample (first 10).
                if ( count( $results['sample'] ) < 10 ) {
                    $results['sample'][] = array(
                        'order_id'        => $row->post_id,
                        'tracking_number' => $tracking_number,
                        'carrier'         => $carrier,
                        'status'          => $status,
                        'date_shipped'    => $date_shipped > 0 ? date( 'Y-m-d', $date_shipped ) : '',
                    );
                }
            }
        }

        $results['total_orders'] = count( array_unique( $order_ids ) );

        if ( $earliest < PHP_INT_MAX ) {
            $results['date_range']['earliest'] = date( 'Y-m-d', $earliest );
        }
        if ( $latest > 0 ) {
            $results['date_range']['latest'] = date( 'Y-m-d', $latest );
        }

        return $results;
    }

    /**
     * Run the actual import.
     *
     * @param bool $skip_existing  Skip tracking numbers already in ShipTracker.
     * @return array {
     *     @type int   $imported  Number of shipments imported.
     *     @type int   $skipped   Number skipped (already exist).
     *     @type int   $errors    Number of errors.
     *     @type array $error_details  Details of any errors.
     * }
     */
    public static function import( $skip_existing = true ) {
        global $wpdb;

        $results = array(
            'imported'      => 0,
            'skipped'       => 0,
            'errors'        => 0,
            'error_details' => array(),
        );

        // Get all AST tracking data.
        $rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_wc_shipment_tracking_items'
             ORDER BY post_id ASC"
        );

        // Also check HPOS.
        $hpos_table = $wpdb->prefix . 'wc_orders_meta';
        $hpos_exists = $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $hpos_table ) );
        if ( $hpos_exists ) {
            $hpos_rows = $wpdb->get_results(
                "SELECT order_id as post_id, meta_value FROM {$hpos_table}
                 WHERE meta_key = '_wc_shipment_tracking_items'
                 ORDER BY order_id ASC"
            );
            $existing_ids = array_map( function( $r ) { return $r->post_id; }, $rows );
            foreach ( $hpos_rows as $hr ) {
                if ( ! in_array( $hr->post_id, $existing_ids, true ) ) {
                    $rows[] = $hr;
                }
            }
        }

        foreach ( $rows as $row ) {
            $items = maybe_unserialize( $row->meta_value );
            if ( ! is_array( $items ) ) {
                continue;
            }

            // Get TrackShip status for this order.
            $ts_status = get_post_meta( $row->post_id, 'shipment_status', true );
            if ( empty( $ts_status ) ) {
                $ts_status = get_post_meta( $row->post_id, 'ts_shipment_status', true );
            }

            foreach ( $items as $item ) {
                $tracking_number = isset( $item['tracking_number'] ) ? trim( $item['tracking_number'] ) : '';
                if ( empty( $tracking_number ) ) {
                    continue;
                }

                // Skip if already imported.
                if ( $skip_existing ) {
                    $existing = FST_Shipment::get_by_tracking( $tracking_number );
                    if ( $existing ) {
                        $results['skipped']++;
                        continue;
                    }
                }

                // Map carrier.
                $provider = isset( $item['tracking_provider'] ) ? strtolower( trim( $item['tracking_provider'] ) ) : '';
                $carrier  = isset( self::$carrier_map[ $provider ] ) ? self::$carrier_map[ $provider ] : FST_Carrier::detect_carrier( $tracking_number );

                // Map status.
                $status_raw = is_array( $ts_status ) ? '' : strtolower( trim( (string) $ts_status ) );
                $status     = isset( self::$status_map[ $status_raw ] ) ? self::$status_map[ $status_raw ] : 'unknown';

                // Parse ship date.
                $date_shipped = isset( $item['date_shipped'] ) ? (int) $item['date_shipped'] : 0;
                $ship_date    = $date_shipped > 0 ? date( 'Y-m-d H:i:s', $date_shipped ) : current_time( 'mysql' );

                // Create the shipment.
                $shipment_id = FST_Shipment::create( array(
                    'order_id'        => (int) $row->post_id,
                    'tracking_number' => $tracking_number,
                    'carrier'         => $carrier,
                    'status'          => $status,
                    'ship_date'       => $ship_date,
                ) );

                if ( $shipment_id ) {
                    $results['imported']++;
                } else {
                    $results['errors']++;
                    $results['error_details'][] = sprintf(
                        'Failed to import tracking %s for order #%d',
                        $tracking_number,
                        $row->post_id
                    );
                }
            }
        }

        // Store migration timestamp.
        update_option( 'fst_last_migration', array(
            'timestamp' => current_time( 'mysql' ),
            'imported'  => $results['imported'],
            'skipped'   => $results['skipped'],
            'errors'    => $results['errors'],
        ) );

        return $results;
    }

    /**
     * Get count of AST tracking entries available.
     *
     * @return int
     */
    public static function get_ast_count() {
        global $wpdb;
        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
             WHERE meta_key = '_wc_shipment_tracking_items'"
        );
    }
}
