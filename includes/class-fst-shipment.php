<?php
/**
 * Shipment data model — CRUD operations for the wp_fst_shipments table.
 *
 * Label cost columns (DB version 1.3.0): label_cost, box_weight, box_dims and
 * label_source describe the BOX, not the row — read and write them through
 * get_box_cost() / set_box_cost(), never through the row itself. A box's
 * materials (DB version 1.4.0) are not on this table at all: they are keyed
 * by tracking number in wp_fst_supply_usage — see FST_Supplies.
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
     * Get the shipment row for a specific order + tracking number combo.
     *
     * Used to detect a duplicate within the same order — the fan-out flow
     * intentionally allows the same tracking number across DIFFERENT source
     * orders (combined-box scenario).
     *
     * @param int    $order_id
     * @param string $tracking_number
     * @return object|null
     */
    public static function get_by_order_and_tracking( $order_id, $tracking_number ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE order_id = %d AND tracking_number = %s LIMIT 1",
            $order_id,
            $tracking_number
        ) );
    }

    /**
     * Get a shipment by its EasyPost tracker ID.
     *
     * @param string $tracker_id
     * @return object|null
     */
    public static function get_by_easypost_tracker( $tracker_id ) {
        global $wpdb;
        if ( empty( $tracker_id ) ) {
            return null;
        }
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE easypost_tracker_id = %s LIMIT 1", $tracker_id
        ) );
    }

    /**
     * Get EVERY shipment row bound to an EasyPost tracker ID.
     *
     * WHY (shiptracker-2): fulfillment fan-out stores one row per source order,
     * all sharing one tracking number and (EasyPost de-dupes trackers per
     * tracking_code) one tracker id. The webhook has to update all of them.
     *
     * @param string $tracker_id
     * @return array
     */
    public static function get_all_by_easypost_tracker( $tracker_id ) {
        global $wpdb;
        if ( empty( $tracker_id ) ) {
            return array();
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE easypost_tracker_id = %s ORDER BY id ASC", $tracker_id
        ) );
    }

    /**
     * Get EVERY shipment row for a tracking number, optionally scoped to a carrier.
     *
     * @param string $tracking_number
     * @param string $carrier Optional internal carrier slug; '' matches any carrier.
     * @return array
     */
    public static function get_all_by_tracking( $tracking_number, $carrier = '' ) {
        global $wpdb;
        if ( '' === (string) $tracking_number ) {
            return array();
        }
        if ( '' !== (string) $carrier ) {
            return $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM " . self::table() . " WHERE tracking_number = %s AND carrier = %s ORDER BY id ASC",
                $tracking_number,
                $carrier
            ) );
        }
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE tracking_number = %s ORDER BY id ASC",
            $tracking_number
        ) );
    }

    /**
     * Get non-terminal USPS shipments not yet registered with EasyPost.
     *
     * Used by the one-shot backfill that runs after upgrading to EasyPost support.
     *
     * @return array
     */
    public static function get_active_for_easypost_backfill() {
        global $wpdb;

        $terminal     = array( 'delivered', 'failure', 'return_to_sender' );
        $placeholders = implode( ',', array_fill( 0, count( $terminal ), '%s' ) );

        $params = array_merge( array( 'usps' ), $terminal );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::table() . "
             WHERE carrier = %s
             AND status NOT IN ({$placeholders})
             AND ( easypost_tracker_id IS NULL OR easypost_tracker_id = '' )
             ORDER BY id ASC",
            ...$params
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
            'order_id'            => 0,
            'tracking_number'     => '',
            'carrier'             => 'ups',
            'status'              => 'unknown',
            'status_detail'       => '',
            'ship_date'           => current_time( 'mysql' ),
            'easypost_tracker_id' => '',
            'tracker_provider'    => '',
            'line_item_ids'       => null,
            'check_count'         => 0,
            'created_at'          => current_time( 'mysql' ),
            'updated_at'          => current_time( 'mysql' ),
        );

        $data = wp_parse_args( $data, $defaults );

        $result = $wpdb->insert( self::table(), $data, array(
            '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s',
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

        // One box, one cost: keep the row in hand so its cost data can move
        // to a surviving row of the same tracking number once it is gone.
        $removed = self::get( $id );

        // Delete events first.
        $wpdb->delete( self::events_table(), array( 'shipment_id' => $id ), array( '%d' ) );

        $deleted = false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );

        if ( $deleted && $removed ) {
            self::rehome_box_cost( $removed );

            // The last row of a tracking number: the box itself is gone,
            // so its materials go back on the shelf. While another order's
            // row remains the box still exists and keeps them.
            if ( ! self::get_all_by_tracking( $removed->tracking_number ) ) {
                FST_Supplies::clear_box_materials( $removed->tracking_number );
            }
        }

        return $deleted;
    }

    /**
     * The cost data of a box: label cost, weight, dimensions and where the
     * label was bought, for one tracking number.
     *
     * A tracking number is one physical box. Fan-out writes one row per
     * source order for it, but the four cost columns are filled on ONE of
     * those rows only (the others stay NULL), so the box is read here by
     * tracking number and no sum can count it twice.
     *
     * @param string $tracking_number
     * @return array|null FST_Label_Cost::box_from_rows() shape — tracking_number,
     *                    label_cost, box_weight, box_dims, label_source (decimal
     *                    strings; NULL = not recorded), shared_box, order_ids,
     *                    shipment_ids, cost_shipment_id — or null when no
     *                    shipment has that tracking number.
     */
    public static function get_box_cost( $tracking_number ) {
        $rows = self::get_all_by_tracking( $tracking_number );
        if ( empty( $rows ) ) {
            return null;
        }
        return FST_Label_Cost::box_from_rows( $rows );
    }

    /**
     * Set cost fields on the box of a tracking number.
     *
     * Only the keys present in $fields change; a NULL value clears that
     * field. The box's four fields end up on exactly one row: the row that
     * already holds them, else $preferred_id (the first row a fan-out call
     * created), else the oldest row. Every other row of the tracking number
     * is NULLed.
     *
     * `updated_at` is deliberately left as it was — a label cost is
     * bookkeeping, not a tracking update, and "Recently Delivered" sorts on
     * that column.
     *
     * @param string $tracking_number
     * @param array  $fields       Any of label_cost, box_weight, box_dims, label_source
     *                             => normalised value (FST_Label_Cost::normalize_fields()) or null.
     * @param int    $preferred_id Row to hold the fields when no row holds any yet.
     * @return bool False when the tracking number has no shipment or no field was given.
     */
    public static function set_box_cost( $tracking_number, array $fields, $preferred_id = 0 ) {
        $fields = array_intersect_key( $fields, array_flip( FST_Label_Cost::FIELDS ) );
        $rows   = self::get_all_by_tracking( $tracking_number );
        if ( empty( $rows ) || empty( $fields ) ) {
            return false;
        }

        $box    = FST_Label_Cost::box_from_rows( $rows );
        $values = array();
        foreach ( FST_Label_Cost::FIELDS as $field ) {
            $values[ $field ] = array_key_exists( $field, $fields ) ? $fields[ $field ] : $box[ $field ];
        }

        $holder_id = $box['cost_shipment_id'];
        if ( ! $holder_id ) {
            $holder_id = in_array( (int) $preferred_id, $box['shipment_ids'], true ) ? (int) $preferred_id : $box['shipment_ids'][0];
        }

        $empty = array_fill_keys( FST_Label_Cost::FIELDS, null );
        $ok    = true;
        foreach ( $rows as $row ) {
            $ok = self::write_cost_columns( $row, (int) $row->id === $holder_id ? $values : $empty ) && $ok;
        }

        return $ok;
    }

    /**
     * Remove every cost field from the box of a tracking number.
     *
     * @param string $tracking_number
     * @return bool
     */
    public static function clear_box_cost( $tracking_number ) {
        return self::set_box_cost( $tracking_number, array_fill_keys( FST_Label_Cost::FIELDS, null ) );
    }

    /**
     * The boxes of an order (or of several orders — pass a fulfillment's
     * source order ids to get the boxes they went out in), oldest first.
     *
     * One entry per distinct tracking number, with the box's cost data and
     * whether the box is shared with other orders.
     *
     * @param int|int[] $order_ids
     * @return array[] Each: the get_box_cost() keys plus `shipment_id` (the
     *                 row of the given order(s) for that box — pass it to
     *                 fst_set_label_cost), `carrier`, `status`, `ship_date`,
     *                 and since 1.9.18 `materials` (FST_Supplies record, null
     *                 when none are recorded), `materials_total` ("4.21" or
     *                 null) and `total_cost` (label + materials; null while
     *                 the label cost is not recorded).
     */
    public static function get_order_boxes( $order_ids ) {
        $order_ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $order_ids ) ) ) );

        $boxes = array();
        foreach ( $order_ids as $order_id ) {
            foreach ( self::get_by_order( $order_id ) as $row ) {
                $key = FST_Label_Cost::box_key( $row->tracking_number );
                if ( isset( $boxes[ $key ] ) && $boxes[ $key ]['shipment_id'] < (int) $row->id ) {
                    continue;
                }

                $box = self::get_box_cost( $row->tracking_number );
                if ( null === $box ) {
                    continue;
                }
                $box['shipment_id'] = (int) $row->id;
                $box['carrier']     = $row->carrier;
                $box['status']      = $row->status;
                $box['ship_date']   = isset( $row->ship_date ) ? $row->ship_date : null;

                $boxes[ $key ] = self::with_materials( $box );
            }
        }

        $boxes = array_values( $boxes );
        usort( $boxes, array( __CLASS__, 'compare_box_shipment_ids' ) );

        return $boxes;
    }

    /**
     * Add a box's materials to its cost data: `materials`, `materials_total`
     * and `total_cost` (label + materials).
     *
     * @param array $box get_box_cost() result.
     * @return array
     */
    public static function with_materials( array $box ) {
        $materials = FST_Supplies::get_box_materials( $box['tracking_number'] );

        $box['materials']       = $materials;
        $box['materials_total'] = $materials ? $materials['total'] : null;
        $box['total_cost']      = null === $box['label_cost']
            ? null
            : FST_Label_Cost::from_cents( FST_Label_Cost::to_cents( $box['label_cost'] ) + ( $materials ? FST_Label_Cost::to_cents( $materials['total'] ) : 0 ) );

        return $box;
    }

    /**
     * usort callback: boxes by the id of their shipment row, oldest first.
     */
    private static function compare_box_shipment_ids( $a, $b ) {
        return $a['shipment_id'] - $b['shipment_id'];
    }

    /**
     * After a shipment row is deleted: if it held its box's cost data and
     * other rows share the tracking number, move the data to the oldest
     * surviving row so the box keeps its cost.
     *
     * @param object $removed The deleted row, as it was.
     */
    private static function rehome_box_cost( $removed ) {
        if ( ! FST_Label_Cost::row_has_cost_data( $removed ) ) {
            return;
        }

        $survivors = self::get_all_by_tracking( $removed->tracking_number );
        if ( empty( $survivors ) ) {
            return;
        }

        // A surviving row that already holds cost data stays the holder.
        $box = FST_Label_Cost::box_from_rows( $survivors );
        if ( $box['cost_shipment_id'] ) {
            return;
        }

        $values = array();
        foreach ( FST_Label_Cost::FIELDS as $field ) {
            $values[ $field ] = isset( $removed->$field ) ? $removed->$field : null;
        }

        self::write_cost_columns( $survivors[0], $values );
    }

    /**
     * Write the four cost columns of one row, and nothing else. Skips the
     * query when the row already holds exactly these values.
     *
     * @param object $row    Shipment row as currently stored.
     * @param array  $values All four fields => value or null.
     * @return bool
     */
    private static function write_cost_columns( $row, array $values ) {
        global $wpdb;

        $changed = false;
        foreach ( FST_Label_Cost::FIELDS as $field ) {
            $current = isset( $row->$field ) ? $row->$field : null;
            $wanted  = $values[ $field ];

            if ( null === $current || null === $wanted ) {
                $same = $current === $wanted;
            } elseif ( 'label_cost' === $field || 'box_weight' === $field ) {
                $same = number_format( (float) $current, 2, '.', '' ) === number_format( (float) $wanted, 2, '.', '' );
            } else {
                $same = (string) $current === (string) $wanted;
            }

            if ( ! $same ) {
                $changed = true;
                break;
            }
        }
        if ( ! $changed ) {
            return true;
        }

        // Naming updated_at keeps MySQL's ON UPDATE CURRENT_TIMESTAMP from
        // touching it.
        if ( isset( $row->updated_at ) ) {
            $values['updated_at'] = $row->updated_at;
        }

        return false !== $wpdb->update( self::table(), $values, array( 'id' => $row->id ) );
    }

    /**
     * Shipment rows for the label cost report of a ship-date range.
     *
     * Same exclusions as the rest of the Analytics page: a box counts when
     * one of its rows has a status other than unknown / label_created and a
     * ship date in the range (the end date included, see range_end()).
     *
     * @param string $date_from Optional. Y-m-d start date.
     * @param string $date_to   Optional. Y-m-d end date.
     * @return array {
     *     @type string[] $tracking_numbers Boxes shipped in the range.
     *     @type array    $rows             Every row of every order that has one of those
     *                                      boxes (so a multi-box order's other boxes and a
     *                                      shared box's other orders are all present).
     * }
     */
    public static function get_cost_report_rows( $date_from = '', $date_to = '' ) {
        global $wpdb;

        $where  = "WHERE status NOT IN ('unknown','label_created')";
        $values = array();

        if ( $date_from ) {
            $where   .= ' AND ship_date >= %s';
            $values[] = $date_from;
        }
        if ( $date_to ) {
            $where   .= ' AND ship_date <= %s';
            $values[] = self::range_end( $date_to );
        }

        $sql = "SELECT DISTINCT tracking_number FROM " . self::table() . " {$where}";

        $tracking_numbers = empty( $values )
            ? $wpdb->get_col( $sql )
            : $wpdb->get_col( $wpdb->prepare( $sql, ...$values ) );

        if ( empty( $tracking_numbers ) ) {
            return array(
                'tracking_numbers' => array(),
                'rows'             => array(),
            );
        }

        // The orders in those boxes, then every row of those orders.
        $order_ids = array();
        foreach ( self::get_report_rows_in( 'tracking_number', $tracking_numbers, '%s' ) as $row ) {
            $order_ids[ (int) $row->order_id ] = true;
        }

        return array(
            'tracking_numbers' => $tracking_numbers,
            'rows'             => self::get_report_rows_in( 'order_id', array_keys( $order_ids ), '%d' ),
        );
    }

    /**
     * Report columns of the rows whose $column is one of $values.
     *
     * @param string $column      'tracking_number' or 'order_id'.
     * @param array  $values
     * @param string $placeholder '%s' or '%d'.
     * @return array
     */
    private static function get_report_rows_in( $column, array $values, $placeholder ) {
        global $wpdb;

        $column = 'order_id' === $column ? 'order_id' : 'tracking_number';
        $rows   = array();

        foreach ( array_chunk( $values, 500 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), $placeholder ) );

            $found = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, order_id, tracking_number, carrier, status, ship_date, label_cost, box_weight, box_dims, label_source
                 FROM " . self::table() . "
                 WHERE {$column} IN ({$placeholders})
                 ORDER BY id ASC",
                ...$chunk
            ) );

            $rows = array_merge( $rows, (array) $found );
        }

        return $rows;
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

        $result = $wpdb->insert( self::events_table(), $data, array( '%d', '%s', '%s', '%s', '%s', '%s', '%s' ) );
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

        $polling_seconds     = (int) get_option( 'fst_polling_interval', 3600 );
        $polling_interval    = max( 5, intval( $polling_seconds / 60 ) ); // Convert seconds to minutes for SQL INTERVAL.
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
             AND ( tracker_provider IS NULL OR tracker_provider != 'easypost' )
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
     * Upper bound of a ship-date range. ship_date is a DATETIME, so a bare
     * Y-m-d end date has to mean the END of that day — compared as-is it is
     * midnight, and everything shipped on the last day (today, for the
     * preset ranges) fell out of the stats.
     *
     * @param string $date_to Y-m-d, or a full datetime (used unchanged).
     * @return string
     */
    private static function range_end( $date_to ) {
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $date_to ) ? $date_to . ' 23:59:59' : $date_to;
    }

    /**
     * Count shipments by status.
     *
     * @param string $date_from Optional. Y-m-d start date.
     * @param string $date_to   Optional. Y-m-d end date.
     * @return array status => count
     */
    public static function count_by_status( $date_from = '', $date_to = '' ) {
        global $wpdb;

        $where  = "WHERE status NOT IN ('unknown','label_created')";
        $values = array();

        if ( $date_from ) {
            $where   .= ' AND ship_date >= %s';
            $values[] = $date_from;
        }
        if ( $date_to ) {
            $where   .= ' AND ship_date <= %s';
            $values[] = self::range_end( $date_to );
        }

        // Dedupe across fan-out rows: combined-box shipments produce one row
        // per source order with the same tracking_number, but a stat card
        // should count one physical box, not N rows.
        $sql = "SELECT status, COUNT(DISTINCT tracking_number) as count FROM " . self::table() . " {$where} GROUP BY status";

        $results = empty( $values )
            ? $wpdb->get_results( $sql )
            : $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );

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
     * @param string $date_from Optional. Y-m-d start date.
     * @param string $date_to   Optional. Y-m-d end date.
     * @return array carrier => count
     */
    public static function count_by_carrier( $date_from = '', $date_to = '' ) {
        global $wpdb;

        $where  = "WHERE status NOT IN ('unknown','label_created')";
        $values = array();

        if ( $date_from ) {
            $where   .= ' AND ship_date >= %s';
            $values[] = $date_from;
        }
        if ( $date_to ) {
            $where   .= ' AND ship_date <= %s';
            $values[] = self::range_end( $date_to );
        }

        $sql = "SELECT carrier, COUNT(DISTINCT tracking_number) as count FROM " . self::table() . " {$where} GROUP BY carrier";

        $results = empty( $values )
            ? $wpdb->get_results( $sql )
            : $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );

        $counts = array();
        foreach ( $results as $row ) {
            $counts[ $row->carrier ] = (int) $row->count;
        }
        return $counts;
    }

    /**
     * Get shipment counts per day within a date range.
     *
     * @param string $date_from Y-m-d start date.
     * @param string $date_to   Y-m-d end date.
     * @return array [ { date, count } ]
     */
    public static function count_per_day( $date_from = '', $date_to = '' ) {
        global $wpdb;

        if ( ! $date_from ) {
            $date_from = date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) );
        }
        if ( ! $date_to ) {
            $date_to = current_time( 'Y-m-d' );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(ship_date) as date, COUNT(DISTINCT tracking_number) as count
             FROM " . self::table() . "
             WHERE status NOT IN ('unknown','label_created')
             AND ship_date IS NOT NULL
             AND ship_date >= %s
             AND ship_date <= %s
             GROUP BY DATE(ship_date)
             ORDER BY date ASC",
            $date_from,
            self::range_end( $date_to )
        ) );
    }

    /**
     * Get average delivery time in days (ship_date to delivered_date).
     *
     * @param string $date_from Optional. Y-m-d start date.
     * @param string $date_to   Optional. Y-m-d end date.
     * @return float|null Average days, or null if no data.
     */
    public static function avg_delivery_days( $date_from = '', $date_to = '' ) {
        global $wpdb;

        $where  = "WHERE status = 'delivered' AND delivered_date IS NOT NULL AND ship_date IS NOT NULL AND delivered_date > ship_date";
        $values = array();

        if ( $date_from ) {
            $where   .= ' AND ship_date >= %s';
            $values[] = $date_from;
        }
        if ( $date_to ) {
            $where   .= ' AND ship_date <= %s';
            $values[] = self::range_end( $date_to );
        }

        // Dedupe before averaging so a combined-box shipment (one tracking#,
        // N fan-out rows) contributes a single data point, not N.
        $sql = "SELECT AVG(DATEDIFF(dlv, shp)) FROM (
                    SELECT MIN(ship_date) AS shp, MIN(delivered_date) AS dlv
                    FROM " . self::table() . " {$where}
                    GROUP BY tracking_number
                ) AS dedup";

        $avg = empty( $values )
            ? $wpdb->get_var( $sql )
            : $wpdb->get_var( $wpdb->prepare( $sql, ...$values ) );

        return null !== $avg ? round( (float) $avg, 1 ) : null;
    }

    /**
     * Get average delivery time by carrier.
     *
     * @param string $date_from Optional. Y-m-d start date.
     * @param string $date_to   Optional. Y-m-d end date.
     * @return array carrier => avg_days
     */
    public static function avg_delivery_days_by_carrier( $date_from = '', $date_to = '' ) {
        global $wpdb;

        $where  = "WHERE status = 'delivered' AND delivered_date IS NOT NULL AND ship_date IS NOT NULL AND delivered_date > ship_date";
        $values = array();

        if ( $date_from ) {
            $where   .= ' AND ship_date >= %s';
            $values[] = $date_from;
        }
        if ( $date_to ) {
            $where   .= ' AND ship_date <= %s';
            $values[] = self::range_end( $date_to );
        }

        // Same dedupe as avg_delivery_days(): one row per tracking_number
        // before averaging, so combined-box shipments aren't double-weighted.
        $sql = "SELECT carrier, AVG(DATEDIFF(dlv, shp)) as avg_days, COUNT(*) as count
                FROM (
                    SELECT tracking_number, carrier,
                           MIN(ship_date) AS shp,
                           MIN(delivered_date) AS dlv
                    FROM " . self::table() . " {$where}
                    GROUP BY tracking_number
                ) AS dedup
                GROUP BY carrier";

        $results = empty( $values )
            ? $wpdb->get_results( $sql )
            : $wpdb->get_results( $wpdb->prepare( $sql, ...$values ) );

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
     * Get delivery counts per day within a date range.
     *
     * @param string $date_from Y-m-d start date.
     * @param string $date_to   Y-m-d end date.
     * @return array [ { date, count } ]
     */
    public static function deliveries_per_day( $date_from = '', $date_to = '' ) {
        global $wpdb;

        if ( ! $date_from ) {
            $date_from = date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) );
        }
        if ( ! $date_to ) {
            $date_to = current_time( 'Y-m-d' );
        }

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(delivered_date) as date, COUNT(DISTINCT tracking_number) as count
             FROM " . self::table() . "
             WHERE status = 'delivered'
             AND delivered_date IS NOT NULL
             AND delivered_date >= %s
             AND delivered_date <= %s
             GROUP BY DATE(delivered_date)
             ORDER BY date ASC",
            $date_from . ' 00:00:00',
            $date_to . ' 23:59:59'
        ) );
    }
}
