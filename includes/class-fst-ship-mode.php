<?php
/**
 * How an order went out when it has no label of its own.
 *
 * Two cases the owner needs to record:
 *  - "Local pickup": the customer collected the order. No label, so a real
 *    label cost of zero; it may still have used materials.
 *  - "Went in another order's box": the fish were added to someone else's
 *    box. That box (or those boxes) then counts this order's shipping paid
 *    against its cost, exactly like a box shared by a fulfillment fan-out.
 *
 * This is a fact about the ORDER, stored as order meta through WooCommerce's
 * order API (so it works with and without HPOS). It is never a row in
 * wp_fst_shipments: nothing is polled, no customer e-mail is sent and the
 * order status is left alone. Clearing it puts the order back to "no
 * shipment yet".
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Ship_Mode {

    /**
     * Order meta: the mode ('local' or 'with_order'; absent = 'label'), the
     * order whose box it went in, when it was marked and by whom.
     */
    const META_MODE = '_fst_ship_mode';
    const META_WITH = '_fst_ship_with_order';
    const META_DATE = '_fst_ship_mode_date';
    const META_BY   = '_fst_ship_mode_by';

    /**
     * `label` is the normal case — the order ships (or will ship) with a
     * label of its own — and is stored as "no meta at all".
     */
    const MODES = array( 'label', 'local', 'with_order' );

    /**
     * Normalise the arguments of a ship mode change — pure.
     *
     * @param array $args mode (label | local | with_order), with_order_id (for with_order),
     *                    date (optional Y-m-d: the day it went out; default now).
     * @return array|WP_Error mode, with_order_id (0 unless with_order), date (Y-m-d H:i:s or
     *                        null = now) — or `fst_invalid_ship_mode`.
     */
    public static function normalize_args( array $args ) {
        $mode = isset( $args['mode'] ) && is_scalar( $args['mode'] ) ? strtolower( trim( (string) $args['mode'] ) ) : '';
        if ( '' === $mode || 'none' === $mode ) {
            $mode = 'label';
        }
        if ( ! in_array( $mode, self::MODES, true ) ) {
            return new WP_Error( 'fst_invalid_ship_mode', 'mode must be label, local or with_order.' );
        }

        $with_order_id = 0;
        if ( 'with_order' === $mode ) {
            $with_order_id = isset( $args['with_order_id'] ) && is_scalar( $args['with_order_id'] ) ? absint( $args['with_order_id'] ) : 0;
            if ( ! $with_order_id ) {
                return new WP_Error( 'fst_invalid_ship_mode', 'Choose the order whose box this order went in.' );
            }
        }

        $date = null;
        if ( isset( $args['date'] ) && is_scalar( $args['date'] ) && '' !== trim( (string) $args['date'] ) ) {
            $raw_date = trim( (string) $args['date'] );
            if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw_date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
                return new WP_Error( 'fst_invalid_ship_mode', 'date must be a valid date in Y-m-d format.' );
            }
            $date = $raw_date . ' 00:00:00';
        }

        return array(
            'mode'          => $mode,
            'with_order_id' => $with_order_id,
            'date'          => $date,
        );
    }

    /**
     * May an order take this mode? — pure.
     *
     * Rules: only an order with no label of its own can be a local pickup
     * or go in another order's box; the other order must exist, must not be
     * this one, must have a box to go in (not a local pickup) and must not
     * itself have gone in someone else's box; and an order that carries
     * other orders cannot be carried.
     *
     * @param array $normalized normalize_args() result.
     * @param array $facts      order_id, has_shipments, host_exists, host_mode ('' when no
     *                          host), has_riders (other orders went in this order's box).
     * @return true|WP_Error `fst_order_has_shipments`, `fst_order_not_found` or
     *                       `fst_invalid_ship_mode`.
     */
    public static function check( array $normalized, array $facts ) {
        if ( 'label' === $normalized['mode'] ) {
            return true;
        }

        if ( ! empty( $facts['has_shipments'] ) ) {
            return new WP_Error( 'fst_order_has_shipments', 'This order has a label of its own. Remove its tracking first.' );
        }

        if ( 'with_order' !== $normalized['mode'] ) {
            return true;
        }

        $host_id = (int) $normalized['with_order_id'];
        if ( $host_id === (int) $facts['order_id'] ) {
            return new WP_Error( 'fst_invalid_ship_mode', 'An order cannot go in its own box. Pick the order whose box it went in.' );
        }
        if ( empty( $facts['host_exists'] ) ) {
            return new WP_Error( 'fst_order_not_found', sprintf( 'Order #%d not found.', $host_id ) );
        }
        if ( 'with_order' === $facts['host_mode'] ) {
            return new WP_Error( 'fst_invalid_ship_mode', sprintf( 'Order #%d itself went in another order\'s box. Pick the order that has the box.', $host_id ) );
        }
        if ( 'local' === $facts['host_mode'] ) {
            return new WP_Error( 'fst_invalid_ship_mode', sprintf( 'Order #%d is a local pickup, so it has no box.', $host_id ) );
        }
        if ( ! empty( $facts['has_riders'] ) ) {
            return new WP_Error( 'fst_invalid_ship_mode', 'Other orders went in this order\'s box, so it cannot go in another one.' );
        }

        return true;
    }

    /**
     * How an order went out.
     *
     * @param WC_Order|int $order
     * @return array mode (label | local | with_order), with_order_id (int or null),
     *               date ("2026-11-12 09:00:00" or null).
     */
    public static function get( $order ) {
        if ( ! is_object( $order ) ) {
            $order = wc_get_order( absint( $order ) );
        }

        $state = array(
            'mode'          => 'label',
            'with_order_id' => null,
            'date'          => null,
        );
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_meta' ) ) {
            return $state;
        }

        $mode = (string) $order->get_meta( self::META_MODE );
        $with = absint( $order->get_meta( self::META_WITH ) );
        if ( 'local' !== $mode && ( 'with_order' !== $mode || ! $with ) ) {
            return $state;
        }

        $date = (string) $order->get_meta( self::META_DATE );

        $state['mode']          = $mode;
        $state['with_order_id'] = 'with_order' === $mode ? $with : null;
        $state['date']          = '' !== $date ? $date : null;

        return $state;
    }

    /**
     * get() plus what a screen needs: the other order's number and, for a
     * local pickup, its materials.
     *
     * Public contract — the theme's Shipments box reads this, and it is the
     * `ship_mode` of GET /fst/v1/order/{id}/shipments.
     *
     * @param WC_Order|int $order
     * @return array mode, with_order_id, with_order_number (string or null), date,
     *               materials (FST_Supplies record or null), materials_total ("1.40" or null).
     */
    public static function describe( $order ) {
        if ( ! is_object( $order ) ) {
            $order = wc_get_order( absint( $order ) );
        }

        $state = self::get( $order );

        $state['with_order_number'] = null;
        if ( $state['with_order_id'] ) {
            $host                       = wc_get_order( $state['with_order_id'] );
            $state['with_order_number'] = is_object( $host ) && method_exists( $host, 'get_order_number' )
                ? (string) $host->get_order_number()
                : (string) $state['with_order_id'];
        }

        $materials = 'local' === $state['mode'] && is_object( $order ) ? FST_Supplies::get_order_materials( $order->get_id() ) : null;

        $state['materials']       = $materials;
        $state['materials_total'] = $materials ? $materials['total'] : null;

        return $state;
    }

    /**
     * Store a mode on an order (or, for `label`, remove it).
     *
     * @param WC_Order $order
     * @param array    $normalized normalize_args() result, already checked.
     */
    public static function write( $order, array $normalized ) {
        if ( 'label' === $normalized['mode'] ) {
            self::clear( $order );
            return;
        }

        $order->update_meta_data( self::META_MODE, $normalized['mode'] );
        if ( 'with_order' === $normalized['mode'] ) {
            $order->update_meta_data( self::META_WITH, (int) $normalized['with_order_id'] );
        } else {
            $order->delete_meta_data( self::META_WITH );
        }
        $order->update_meta_data( self::META_DATE, $normalized['date'] ? $normalized['date'] : current_time( 'mysql' ) );
        $order->update_meta_data( self::META_BY, function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0 );
        $order->save();
    }

    /**
     * Put an order back to "no shipment yet".
     *
     * @param WC_Order $order
     * @return bool Whether there was a mode to remove.
     */
    public static function clear( $order ) {
        $state = self::get( $order );
        $had   = 'label' !== $state['mode'] || '' !== (string) $order->get_meta( self::META_MODE );

        foreach ( array( self::META_MODE, self::META_WITH, self::META_DATE, self::META_BY ) as $key ) {
            $order->delete_meta_data( $key );
        }
        $order->save();

        return $had;
    }

    /**
     * The orders that went in the box of $order_id.
     *
     * @param int $order_id
     * @return int[]
     */
    public static function get_riders_of( $order_id ) {
        $rider_ids = wc_get_orders( array(
            'meta_key'   => self::META_WITH,
            'meta_value' => absint( $order_id ),
            'limit'      => -1,
            'return'     => 'ids',
            'status'     => 'any',
        ) );

        $riders = array();
        foreach ( (array) $rider_ids as $rider_id ) {
            $state = self::get( $rider_id );
            if ( 'with_order' === $state['mode'] && (int) $state['with_order_id'] === absint( $order_id ) ) {
                $riders[] = (int) $rider_id;
            }
        }
        return $riders;
    }

    /**
     * The orders whose rows hold the boxes of a host order: the order
     * itself, or — a fulfillment order's boxes live on its source orders —
     * its sources as well.
     *
     * @param WC_Order|mixed $host
     * @return int[]
     */
    public static function host_order_ids( $host ) {
        if ( ! is_object( $host ) || ! method_exists( $host, 'get_meta' ) ) {
            return array();
        }

        $ids = array( (int) $host->get_id() );

        if ( '1' === (string) $host->get_meta( '_fishotel_is_fulfillment' ) ) {
            $sources = $host->get_meta( '_fishotel_fulfillment_sources' );
            if ( is_string( $sources ) ) {
                $sources = explode( ',', $sources );
            }
            foreach ( (array) $sources as $source_id ) {
                $ids[] = is_scalar( $source_id ) ? absint( trim( (string) $source_id ) ) : 0;
            }
        }

        return array_values( array_unique( array_filter( $ids ) ) );
    }

    /**
     * Every order marked as a local pickup or as gone in another order's
     * box — a short list, read for the Analytics page.
     *
     * @return array[] [ { order_id, mode, with_order_id, host_order_ids, date } ]
     */
    public static function get_marked_orders() {
        $marked = array();

        foreach ( array( 'local', 'with_order' ) as $mode ) {
            $order_ids = wc_get_orders( array(
                'meta_key'   => self::META_MODE,
                'meta_value' => $mode,
                'limit'      => -1,
                'return'     => 'ids',
                'status'     => 'any',
            ) );

            foreach ( (array) $order_ids as $order_id ) {
                $state = self::get( $order_id );
                if ( $state['mode'] !== $mode ) {
                    continue;
                }

                $marked[] = array(
                    'order_id'       => (int) $order_id,
                    'mode'           => $mode,
                    'with_order_id'  => $state['with_order_id'],
                    'host_order_ids' => $state['with_order_id'] ? self::host_order_ids( wc_get_order( $state['with_order_id'] ) ) : array(),
                    'date'           => $state['date'],
                );
            }
        }

        return $marked;
    }
}
