<?php
/**
 * Shipment data model — CRUD operations for the wp_fst_shipments table.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Shipment {

    /**
     * Get the shipments table name.
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'fst_shipments';
    }

    /**
     * Get the events table name.
     */
    public static function events_table() {
        global $wpdb;
        return $wpdb->prefix . 'fst_tracking_events';
    }

    /**
     * Get a shipment by ID.
     *
     * @param int $id
     * @return object|null
     */
    public static function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d", $id
        ) );
    }

    /**
     * Get shipments for an order.
     *
     * @param int $order_id
     * @return array
     */
    public static function get_by_order( $order_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE order_id = %d ORDER BY created_at DESC", $order_id
        ) );
    }

    /**
     * Get a shipment by tracking number.
     *
     * @param string $tracking_number
     * @return object|null
     */
    public static function get_by_tracking( $tracking_number ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE tracking_number = %s", $tracking_number
        ) );
    }

    /**
     * Create a new shipment.
     *
     * @param array $data {
     *     @type int    $order_id
     *     @type string $tracking_number
     *     @type string $carrier          'ups' or 'usps'
     *     @type string $status           Default 'unknown'
     *     @type string $ship_date        Optional. Y-m-d format.
     * }
     * @return int|false Shipment ID or false on failure.
     */
    public static function create( $data ) {
        global $wpdb;

        $defaults = array(
            'order_id'        => 0,
            'tracking_number' => '',
            'carrier'         => 'ups',
            'status'          => 'unknown',
            'status_detail'   => '',
            'ship_date'       => current_time( 'mysql' ),
            'check_count'     => 0,
            'created_at'      => current_time( 'mysql' ),
            'updated_at'      => current_time( 'mysql' ),
        );

        $data = wp_parse_args( $data, $defaults );

        $result = $wpdb->insert( self::table(), $data, array(
            '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s',
        ) );

        if ( false === $result ) {
            return false;
        }

        return $wpdb->insert_id;
    }

    /**
     * Update a shipment.
     *
     * @param int   $id
     * @param array $data Column => value pairs.
     * @return bool
     */
    public static function update( $id, $data ) {
        global $wpdb;

        $data['updated_at'] = current_time( 'mysql' );

        return false !== $wpdb->update( self::table(), $data, array( 'id' => $id ) );
    }

    /**
     * Delete a shipment and its events.
     *
     * @param int $id
     * @return bool
     */
    public static function delete( $id ) {
        global $wpdb;

        // Delete events first.
        $wpdb->delete( self::events_table(), array( 'shipment_id' => $id ), array( '%d' ) );

        return false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
    }

    /**
     * Add a tracking event.
     *
     * @param array $data {
     *     @type int    $shipment_id
     *     @type string $status
     *     @type string $description
     *     @type string $location
     *     @type string $event_time
     *     @type string $raw_data      JSON string.
     * }
     * @return int|false Event ID or false.
     */
    public static function add_event( $data ) {
        global $wpdb;

        $defaults = array(
            'shipment_id' => 0,
            'status'      => '',
            'description' => '',
            'location'    => '',
            'event_time'  => current_time( 'mysql' ),
            'raw_data'    => '',
            'created_at'  => current_time( 'mysql' ),
        );

        $data = wp_parse_args( $data, $defaults );

        if ( is_array( $data['raw_data'] ) ) {
            $data['raw_data'] = wp_json_encode( $data['raw_data'] );
        }

        $result = $wpdb->insert( self::events_table(), $data );
        return false === $result ? false : $wpdb->insert_id;
    }

    /**
     * Get events for a shipment.
     *
     * @param int $shipment_id
     * @return array
     */
    public static function get_events( $shipment_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::events_table() . " WHERE shipment_id = %d ORDER BY event_time DESC",
            $shipment_id
        ) );
    }

    /**
     * Get shipments that need polling (active, non-terminal, due for check).
     *
     * @param int $limit Max shipments to return.
     * @return array
     */
    public static function get_due_for_poll( $limit = 100 ) {
        global $wpdb;

        $terminal = array( 'delivered', 'failure', 'return_to_sender' );
        $placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );

        $polling_interval    = (int) get_option( 'fst_polling_interval', 60 );
        $ofd_interval        = (int) get_option( 'fst_ofd_polling_interval', 30 );
        $stale_days          = 30;

        $now = current_time( 'mysql' );

        // Get shipments that are:
        // 1. Not in a terminal status.
        // 2. Either never checked OR last checked longer ago than their interval.
        // Build params array (cannot use spread + positional args on PHP < 8.1).
        $params = array_merge(
            $terminal,
            array(
                $now, $ofd_interval,
                $now, $stale_days, $now,
                $now, $stale_days, $now, $polling_interval,
                $limit,
            )
        );

        $sql = $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE status NOT IN ({$placeholders})
             AND (
                 last_checked IS NULL
                 OR (status = 'out_for_delivery' AND last_checked < DATE_SUB(%s, INTERVAL %d MINUTE))
                 OR (status != 'out_for_delivery' AND DATEDIFF(%s, COALESCE(ship_date, created_at)) > %d AND last_checked < DATE_SUB(%s, INTERVAL 1 DAY))
                 OR (status != 'out_for_delivery' AND DATEDIFF(%s, COALESCE(ship_date, created_at)) <= %d AND last_checked < DATE_SUB(%s, INTERVAL %d MINUTE))
             )
             ORDER BY
                 CASE WHEN status = 'out_for_delivery' THEN 0 ELSE 1 END,
                 last_checked ASC
             LIMIT %d",
            ...$params
        );

        return $wpdb->get_results( $sql );
    }

    /**
     * Get late shipments (past estimated delivery, not yet delivered).
     *
     * @return array
     */
    public static function get_late_shipments() {
        global $wpdb;

        $terminal = array( 'delivered', 'failure', 'return_to_sender' );
        $placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );

        $params = array_merge( $terminal, array( current_time( 'Y-m-d' ) ) );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE status NOT IN ({$placeholders})
             AND est_delivery IS NOT NULL
             AND est_delivery < %s
             ORDER BY est_delivery ASC",
            ...$params
        ) );
    }

    /**
     * Count shipments by status.
     *
     * @return array status => count
     */
    public static function count_by_status() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM " . self::table() . " GROUP BY status"
        );

        $counts = array();
        foreach ( $results as $row ) {
            $counts[ $row->status ] = (int) $row->count;
        }
        return $counts;
    }

    /**
     * Get all shipments with pagination and filters.
     *
     * @param array $args {
     *     @type string $status   Filter by status.
     *     @type string $carrier  Filter by carrier.
     *     @type int    $per_page Items per page.
     *     @type int    $page     Current page.
     *     @type string $orderby  Column to sort by.
     *     @type string $order    ASC or DESC.
     * }
     * @return array { items, total, pages }
     */
    public static function query( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'   => '',
            'carrier'  => '',
            'search'   => '',
            'per_page' => 20,
            'page'     => 1,
            'orderby'  => 'created_at',
            'order'    => 'DESC',
        );

        $args = wp_parse_args( $args, $defaults );

        $where = array( '1=1' );
        $values = array();

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['carrier'] ) ) {
            $where[]  = 'carrier = %s';
            $values[] = $args['carrier'];
        }

        if ( ! empty( $args['search'] ) ) {
            $where[]  = '(tracking_number LIKE %s OR order_id LIKE %s)';
            $like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $values[] = $like;
            $values[] = $like;
        }

        $where_sql = implode( ' AND ', $where );

        // Sanitize orderby.
        $allowed_orderby = array( 'created_at', 'updated_at', 'status', 'carrier', 'order_id', 'ship_date', 'est_delivery' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';

        $offset = ( max( 1, (int) $args['page'] ) - 1 ) * (int) $args['per_page'];

        // Get total count.
        $count_sql = "SELECT COUNT(*) FROM " . self::table() . " WHERE {$where_sql}";
        $total = (int) $wpdb->get_var( empty( $values ) ? $count_sql : $wpdb->prepare( $count_sql, ...$values ) );

        // Get items.
        $items_sql = "SELECT * FROM " . self::table() . " WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $values[] = (int) $args['per_page'];
        $values[] = $offset;

        $items = $wpdb->get_results( $wpdb->prepare( $items_sql, ...$values ) );

        return array(
            'items' => $items,
            'total' => $total,
            'pages' => ceil( $total / max( 1, (int) $args['per_page'] ) ),
        );
    }

    /**
     * Count shipments by carrier.
     *
     * @return array carrier => count
     */
    public static function count_by_carrier() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT carrier, COUNT(*) as count FROM " . self::table() . " GROUP BY carrier"
        );

        $counts = array();
        foreach ( $results as $row ) {
            $counts[ $row->carrier ] = (int) $row->count;
        }
        return $counts;
    }

    /**
     * Get shipment counts per day for the last N days.
     *
     * @param int $days Number of days to look back.
     * @return array [ { date, count } ]
     */
    public static function count_per_day( $days = 30 ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(created_at) as date, COUNT(*) as count
             FROM " . self::table() . "
             WHERE created_at >= DATE_SUB(%s, INTERVAL %d DAY)
             GROUP BY DATE(created_at)
             ORDER BY date ASC",
            current_time( 'mysql' ),
            $days
        ) );
    }

    /**
     * Get average delivery time in days (ship_date to delivered_date).
     *
     * @return float|null Average days, or null if no data.
     */
    public static function avg_delivery_days() {
        global $wpdb;

        $avg = $wpdb->get_var(
            "SELECT AVG(DATEDIFF(delivered_date, ship_date))
             FROM " . self::table() . "
             WHERE status = 'delivered'
             AND delivered_date IS NOT NULL
             AND ship_date IS NOT NULL
             AND delivered_date > ship_date"
        );

        return null !== $avg ? round( (float) $avg, 1 ) : null;
    }

    /**
     * Get average delivery time by carrier.
     *
     * @return array carrier => avg_days
     */
    public static function avg_delivery_days_by_carrier() {
        global $wpdb;

        $results = $wpdb->get_results(
            "SELECT carrier, AVG(DATEDIFF(delivered_date, ship_date)) as avg_days, COUNT(*) as count
             FROM " . self::table() . "
             WHERE status = 'delivered'
             AND delivered_date IS NOT NULL
             AND ship_date IS NOT NULL
             AND delivered_date > ship_date
             GROUP BY carrier"
        );

        $data = array();
        foreach ( $results as $row ) {
            $data[ $row->carrier ] = array(
                'avg_days' => round( (float) $row->avg_days, 1 ),
                'count'    => (int) $row->count,
            );
        }
        return $data;
    }

    /**
     * Get delivery counts per day for the last N days.
     *
     * @param int $days Number of days to look back.
     * @return array [ { date, count } ]
     */
    public static function deliveries_per_day( $days = 30 ) {
        global $wpdb;

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(delivered_date) as date, COUNT(*) as count
             FROM " . self::table() . "
             WHERE status = 'delivered'
             AND delivered_date IS NOT NULL
             AND delivered_date >= DATE_SUB(%s, INTERVAL %d DAY)
             GROUP BY DATE(delivered_date)
             ORDER BY date ASC",
            current_time( 'mysql' ),
            $days
        ) );
    }
}
