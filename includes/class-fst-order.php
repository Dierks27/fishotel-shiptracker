<?php
/**
 * WooCommerce order integration — meta box, order hooks.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Order {

    public function __construct() {
        // Add meta box to order edit screen (right sidebar, high priority).
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );

        // Save tracking data when order is saved.
        add_action( 'woocommerce_process_shop_order_meta', array( $this, 'save_tracking_data' ), 50 );

        // AJAX handler for adding tracking.
        add_action( 'wp_ajax_fst_add_tracking', array( $this, 'ajax_add_tracking' ) );
        add_action( 'wp_ajax_fst_remove_tracking', array( $this, 'ajax_remove_tracking' ) );
        add_action( 'wp_ajax_fst_recheck_tracking', array( $this, 'ajax_recheck_tracking' ) );

        // Add shipments column to WooCommerce orders list.
        add_filter( 'manage_edit-shop_order_columns', array( $this, 'add_orders_column' ) );
        add_filter( 'manage_woocommerce_page_wc-orders_columns', array( $this, 'add_orders_column' ) );
        add_action( 'manage_shop_order_posts_custom_column', array( $this, 'render_orders_column' ), 10, 2 );
        add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( $this, 'render_orders_column_hpos' ), 10, 2 );
        add_action( 'woocommerce_shop_order_list_table_custom_column', array( $this, 'render_orders_column_hpos' ), 10, 2 );
    }

    /**
     * Add the Shipment Tracking meta box — right sidebar, high position.
     * Works with both legacy CPT orders and HPOS (Custom Order Tables).
     */
    public function add_meta_box() {
        // Register on legacy order screen.
        add_meta_box(
            'fst-shipment-tracking',
            __( 'Shipment Tracking', 'fishotel-shiptracker' ),
            array( $this, 'render_meta_box' ),
            'shop_order',
            'side',
            'high'
        );

        // Register on HPOS order screen (if HPOS is active).
        $hpos_screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : '';
        if ( $hpos_screen && 'shop_order' !== $hpos_screen ) {
            add_meta_box(
                'fst-shipment-tracking',
                __( 'Shipment Tracking', 'fishotel-shiptracker' ),
                array( $this, 'render_meta_box' ),
                $hpos_screen,
                'side',
                'high'
            );
        }
    }

    /**
     * Render the shipment tracking meta box.
     *
     * @param WP_Post|WC_Order $post_or_order
     */
    public function render_meta_box( $post_or_order ) {
        $order_id = $post_or_order instanceof WC_Order ? $post_or_order->get_id() : $post_or_order->ID;
        $shipments = FST_Shipment::get_by_order( $order_id );

        wp_nonce_field( 'fst_tracking_nonce', 'fst_tracking_nonce_field' );
        ?>
        <div id="fst-tracking-metabox" data-order-id="<?php echo esc_attr( $order_id ); ?>">

            <?php if ( ! empty( $shipments ) ) : ?>
                <div class="fst-shipments-list">
                    <?php foreach ( $shipments as $shipment ) : ?>
                        <div class="fst-shipment-item" data-id="<?php echo esc_attr( $shipment->id ); ?>">
                            <div class="fst-shipment-header">
                                <span class="fst-status-badge" style="background-color: <?php echo esc_attr( FST_Carrier::get_status_color( $shipment->status ) ); ?>">
                                    <?php echo esc_html( FST_Carrier::get_status_label( $shipment->status ) ); ?>
                                </span>
                                <span class="fst-carrier-label"><?php echo esc_html( strtoupper( $shipment->carrier ) ); ?></span>
                                <?php if ( isset( $shipment->tracker_provider ) && 'easypost' === $shipment->tracker_provider ) : ?>
                                    <span class="fst-ep-badge" title="Tracked via EasyPost" style="display:inline-block;margin-left:4px;padding:1px 6px;border-radius:3px;background:#eef2ff;color:#3730a3;font-size:10px;font-weight:600;letter-spacing:0.3px;">via EP</span>
                                <?php endif; ?>
                            </div>
                            <div class="fst-tracking-number">
                                <a href="<?php echo esc_url( $this->get_carrier_url( $shipment ) ); ?>" target="_blank">
                                    <?php echo esc_html( $shipment->tracking_number ); ?>
                                </a>
                            </div>
                            <?php if ( $shipment->status_detail ) : ?>
                                <div class="fst-status-detail"><?php echo esc_html( $shipment->status_detail ); ?></div>
                            <?php endif; ?>
                            <?php if ( $shipment->est_delivery ) : ?>
                                <div class="fst-est-delivery">
                                    <small>Est. delivery: <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $shipment->est_delivery ) ) ); ?></small>
                                </div>
                            <?php endif; ?>
                            <?php
                            // Late indicator.
                            if ( $shipment->est_delivery && ! in_array( $shipment->status, array( 'delivered', 'failure', 'return_to_sender' ), true ) ) {
                                if ( strtotime( $shipment->est_delivery ) < strtotime( current_time( 'Y-m-d' ) ) ) {
                                    echo '<div class="fst-late-badge">LATE</div>';
                                }
                            }
                            ?>
                            <div class="fst-shipment-actions">
                                <a href="#" class="fst-recheck-btn" data-id="<?php echo esc_attr( $shipment->id ); ?>" title="Re-check status">
                                    &#x21bb; Re-check
                                </a>
                                <a href="#" class="fst-remove-btn" data-id="<?php echo esc_attr( $shipment->id ); ?>" title="Remove tracking">
                                    &times; Remove
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <hr style="margin: 10px 0;">
            <?php endif; ?>

            <!-- Add new tracking form -->
            <div class="fst-add-tracking-form">
                <p>
                    <input type="text" id="fst-tracking-number" name="fst_tracking_number"
                           placeholder="Enter tracking number" class="widefat"
                           style="margin-bottom: 8px;">
                </p>
                <p>
                    <select id="fst-carrier" name="fst_carrier" class="widefat" style="margin-bottom: 8px;">
                        <option value="auto">Auto-detect carrier</option>
                        <option value="ups">UPS</option>
                        <option value="usps">USPS</option>
                    </select>
                </p>
                <button type="button" id="fst-add-tracking-btn" class="button button-primary widefat">
                    Add Tracking Info
                </button>
                <div id="fst-tracking-message" style="margin-top: 8px; display: none;"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Get the carrier tracking URL for a shipment.
     */
    private function get_carrier_url( $shipment ) {
        $carrier = $this->get_carrier_instance( $shipment->carrier );
        return $carrier ? $carrier->get_tracking_url( $shipment->tracking_number ) : '#';
    }

    /**
     * Get a carrier instance.
     *
     * @param string $slug
     * @return FST_Carrier|null
     */
    private function get_carrier_instance( $slug ) {
        switch ( $slug ) {
            case 'ups':
                return new FST_Carrier_UPS();
            case 'usps':
                return new FST_Carrier_USPS();
            default:
                return null;
        }
    }

    /**
     * Whether a shipment for the given carrier should be routed through EasyPost.
     *
     * @param string $carrier Carrier slug.
     * @return bool
     */
    private function should_route_via_easypost( $carrier ) {
        if ( 'usps' !== $carrier ) {
            return false;
        }
        if ( 'never' === get_option( 'fst_easypost_usps_routing', 'auto' ) ) {
            return false;
        }
        return FST_EasyPost::has_credentials();
    }

    /**
     * AJAX: Add tracking to an order.
     */
    public function ajax_add_tracking() {
        check_ajax_referer( 'fst_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $order_id        = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $tracking_number = isset( $_POST['tracking_number'] ) ? sanitize_text_field( $_POST['tracking_number'] ) : '';
        $carrier         = isset( $_POST['carrier'] ) ? sanitize_text_field( $_POST['carrier'] ) : 'auto';
        $line_item_ids   = isset( $_POST['line_item_ids'] )
            ? array_values( array_unique( array_filter( array_map( 'absint', (array) $_POST['line_item_ids'] ) ) ) )
            : array();

        if ( ! $order_id || empty( $tracking_number ) ) {
            wp_send_json_error( 'Order ID and tracking number are required.' );
        }

        // Auto-detect carrier if needed.
        if ( 'auto' === $carrier ) {
            $carrier = FST_Carrier::detect_carrier( $tracking_number );
        }

        // Fan out to source orders when this is a fulfillment order.
        $target_order_ids = $this->resolve_target_order_ids( $order_id );
        if ( empty( $target_order_ids ) ) {
            wp_send_json_error( 'Could not resolve target order(s).' );
        }

        $shipment_ids = array();
        $failures     = array();

        foreach ( $target_order_ids as $target_id ) {
            // Skip if this exact tracking# is already on this order (allows duplicates
            // across DIFFERENT source orders for combined-box shipments).
            if ( FST_Shipment::get_by_order_and_tracking( $target_id, $tracking_number ) ) {
                continue;
            }

            // Filter line_item_ids down to those that actually belong to this source order.
            $items_for_this_order = array();
            if ( ! empty( $line_item_ids ) ) {
                $source_order = wc_get_order( $target_id );
                if ( $source_order ) {
                    $source_item_ids      = array_keys( $source_order->get_items() );
                    $items_for_this_order = array_values( array_intersect( $line_item_ids, $source_item_ids ) );
                }
            }

            $shipment_id = FST_Shipment::create( array(
                'order_id'        => $target_id,
                'tracking_number' => $tracking_number,
                'carrier'         => $carrier,
                'status'          => 'unknown',
                'ship_date'       => current_time( 'mysql' ),
                'line_item_ids'   => ! empty( $items_for_this_order ) ? wp_json_encode( $items_for_this_order ) : null,
            ) );

            if ( ! $shipment_id ) {
                $failures[] = $target_id;
                continue;
            }

            $shipment_ids[] = $shipment_id;

            // Add order note + transition source order. Only flip to "Shipped" once
            // every line item on that order is covered by a shipment — partial
            // fulfillments stay in Processing and just get a note.
            $source_order = wc_get_order( $target_id );
            if ( $source_order ) {
                $source_order->add_order_note(
                    sprintf( 'Shipment tracking added: %s via %s', $tracking_number, strtoupper( $carrier ) ),
                    false
                );

                if ( 'processing' === $source_order->get_status() ) {
                    if ( $this->is_order_fully_shipped( $source_order ) ) {
                        $source_order->set_status( 'shipped', 'ShipTracker: all items now have tracking.' );
                        $source_order->save();
                    } else {
                        $source_order->add_order_note(
                            sprintf(
                                'Partial shipment added: %s (%s) — some items still pending',
                                $tracking_number,
                                strtoupper( $carrier )
                            ),
                            false
                        );
                    }
                }
            }
        }

        if ( empty( $shipment_ids ) ) {
            wp_send_json_error( empty( $failures )
                ? 'This tracking number is already on this order.'
                : 'Failed to save tracking data.' );
        }

        // Register / poll each newly created shipment.
        foreach ( $shipment_ids as $sid ) {
            $this->poll_or_register_shipment( $sid, $carrier, $tracking_number );
        }

        wp_send_json_success( array(
            'message'      => count( $shipment_ids ) > 1
                ? sprintf( 'Tracking added to %d source orders.', count( $shipment_ids ) )
                : 'Tracking added successfully!',
            'shipment_id'  => $shipment_ids[0],
            'shipment_ids' => $shipment_ids,
            'carrier'      => $carrier,
        ) );
    }

    /**
     * Resolve the target order IDs that should receive shipment rows when
     * tracking is added against $order_id.
     *
     * For a fulfillment order (a combined-box meta order owned by the Phase 2.5
     * fulfillment workflow), fan out to the source order IDs stored in
     * `_fishotel_fulfillment_sources`. For everything else, return the original
     * order id unchanged.
     *
     * @param int $order_id
     * @return int[] Ordered list of target order IDs (deduped, may be 1+ entries).
     */
    private function resolve_target_order_ids( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return array();
        }

        // Kill switches — fall back to legacy single-shipment behavior.
        $kill_constant = defined( 'FST_FULFILLMENT_FANOUT_OFF' ) && FST_FULFILLMENT_FANOUT_OFF;
        $kill_option   = '0' === (string) get_option( 'fst_fulfillment_fanout_enabled', '1' );
        if ( $kill_constant || $kill_option ) {
            return array( (int) $order_id );
        }

        if ( 'fulfillment' === $order->get_status() ) {
            $raw_sources = $order->get_meta( '_fishotel_fulfillment_sources' );

            // The FisHotel theme stores this meta as a comma-separated string
            // (e.g. "34168,34159"), not an array. Normalize string → array
            // first, then handle the array case uniformly. Defensive against
            // a future format change to a real array.
            if ( is_string( $raw_sources ) && '' !== trim( $raw_sources ) ) {
                $raw_sources = array_map( 'trim', explode( ',', $raw_sources ) );
            }

            if ( is_array( $raw_sources ) && ! empty( $raw_sources ) ) {
                return array_values( array_unique( array_filter( array_map( 'absint', $raw_sources ) ) ) );
            }
        }

        return array( (int) $order_id );
    }

    /**
     * Whether every line item on $order is covered by at least one shipment.
     *
     * A shipment with NULL line_item_ids is treated as covering ALL items of
     * its order (the pre-1.9 semantic — preserves backwards compatibility for
     * rows created before the column existed).
     *
     * @param WC_Order $order
     * @return bool
     */
    private function is_order_fully_shipped( $order ) {
        $order_item_ids = array_keys( $order->get_items() );
        if ( empty( $order_item_ids ) ) {
            return true;
        }

        $shipments   = FST_Shipment::get_by_order( $order->get_id() );
        $covered_ids = array();

        foreach ( $shipments as $s ) {
            if ( empty( $s->line_item_ids ) ) {
                // NULL/empty line_item_ids — shipment covers everything on this order.
                return true;
            }
            $ids = json_decode( $s->line_item_ids, true );
            if ( is_array( $ids ) ) {
                $covered_ids = array_merge( $covered_ids, array_map( 'absint', $ids ) );
            }
        }

        $covered_ids = array_unique( $covered_ids );
        $uncovered   = array_diff( $order_item_ids, $covered_ids );

        return empty( $uncovered );
    }

    /**
     * Register a freshly created shipment with EasyPost (when applicable),
     * otherwise poll the carrier API directly. Encapsulates the
     * EasyPost-or-direct branch so it can be reused per shipment in the
     * fan-out loop.
     *
     * @param int    $shipment_id
     * @param string $carrier
     * @param string $tracking_number
     */
    private function poll_or_register_shipment( $shipment_id, $carrier, $tracking_number ) {
        $routed_via_easypost = false;

        if ( $this->should_route_via_easypost( $carrier ) ) {
            $ep_tracker = FST_EasyPost::create_tracker( $tracking_number, $carrier );
            if ( ! is_wp_error( $ep_tracker ) ) {
                FST_Shipment::update( $shipment_id, array(
                    'easypost_tracker_id' => isset( $ep_tracker['id'] ) ? $ep_tracker['id'] : '',
                    'tracker_provider'    => 'easypost',
                ) );
                FST_EasyPost::apply_tracker_to_shipment( FST_Shipment::get( $shipment_id ), $ep_tracker );
                $routed_via_easypost = true;
            }
        }

        if ( ! $routed_via_easypost ) {
            $carrier_instance = $this->get_carrier_instance( $carrier );
            if ( $carrier_instance && $carrier_instance->has_credentials() ) {
                $tracker = new FST_Tracker();
                $tracker->poll_shipment( FST_Shipment::get( $shipment_id ) );
            }
        }
    }

    /**
     * AJAX: Remove tracking from an order.
     */
    public function ajax_remove_tracking() {
        check_ajax_referer( 'fst_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $shipment_id = isset( $_POST['shipment_id'] ) ? absint( $_POST['shipment_id'] ) : 0;
        if ( ! $shipment_id ) {
            wp_send_json_error( 'Shipment ID required.' );
        }

        FST_Shipment::delete( $shipment_id );
        wp_send_json_success( 'Tracking removed.' );
    }

    /**
     * AJAX: Re-check tracking status.
     */
    public function ajax_recheck_tracking() {
        check_ajax_referer( 'fst_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $shipment_id = isset( $_POST['shipment_id'] ) ? absint( $_POST['shipment_id'] ) : 0;
        $shipment    = FST_Shipment::get( $shipment_id );

        if ( ! $shipment ) {
            wp_send_json_error( 'Shipment not found.' );
        }

        $tracker = new FST_Tracker();
        $result  = $tracker->poll_shipment( $shipment );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Re-fetch updated shipment.
        $updated = FST_Shipment::get( $shipment_id );

        wp_send_json_success( array(
            'status'        => $updated->status,
            'status_label'  => FST_Carrier::get_status_label( $updated->status ),
            'status_detail' => $updated->status_detail,
            'status_color'  => FST_Carrier::get_status_color( $updated->status ),
        ) );
    }

    /**
     * Save tracking data when order is saved (fallback for non-AJAX).
     */
    public function save_tracking_data( $order_id ) {
        if ( ! isset( $_POST['fst_tracking_nonce_field'] ) || ! wp_verify_nonce( $_POST['fst_tracking_nonce_field'], 'fst_tracking_nonce' ) ) {
            return;
        }

        // This is handled via AJAX primarily. This is a fallback.
        $tracking_number = isset( $_POST['fst_tracking_number'] ) ? sanitize_text_field( $_POST['fst_tracking_number'] ) : '';

        if ( empty( $tracking_number ) ) {
            return;
        }

        $carrier = isset( $_POST['fst_carrier'] ) ? sanitize_text_field( $_POST['fst_carrier'] ) : 'auto';
        if ( 'auto' === $carrier ) {
            $carrier = FST_Carrier::detect_carrier( $tracking_number );
        }

        $target_order_ids = $this->resolve_target_order_ids( $order_id );

        foreach ( $target_order_ids as $target_id ) {
            // Avoid duplicates on the same order — duplicates ACROSS source
            // orders are allowed (combined-box scenario).
            if ( FST_Shipment::get_by_order_and_tracking( $target_id, $tracking_number ) ) {
                continue;
            }

            FST_Shipment::create( array(
                'order_id'        => $target_id,
                'tracking_number' => $tracking_number,
                'carrier'         => $carrier,
            ) );

            $source_order = wc_get_order( $target_id );
            if ( $source_order && 'processing' === $source_order->get_status()
                 && $this->is_order_fully_shipped( $source_order ) ) {
                $source_order->set_status( 'shipped', 'ShipTracker: all items now have tracking.' );
                $source_order->save();
            }
        }
    }

    /**
     * Add tracking column to WooCommerce orders list.
     */
    public function add_orders_column( $columns ) {
        $new_columns = array();
        foreach ( $columns as $key => $label ) {
            $new_columns[ $key ] = $label;
            // Add after order status column.
            if ( 'order_status' === $key ) {
                $new_columns['fst_tracking']  = __( 'Tracking', 'fishotel-shiptracker' );
                $new_columns['fst_ship_date'] = __( 'Delivery Date', 'fishotel-shiptracker' );
            }
        }
        return $new_columns;
    }

    /**
     * Render tracking column for legacy orders (CPT).
     */
    public function render_orders_column( $column, $post_id ) {
        if ( 'fst_tracking' === $column ) {
            $this->output_tracking_column( $post_id );
        } elseif ( 'fst_ship_date' === $column ) {
            $this->output_ship_date_column( $post_id );
        }
    }

    /**
     * Render tracking column for HPOS orders.
     */
    public function render_orders_column_hpos( $column, $order ) {
        $order_id = $order instanceof WC_Order ? $order->get_id() : $order;
        if ( 'fst_tracking' === $column ) {
            $this->output_tracking_column( $order_id );
        } elseif ( 'fst_ship_date' === $column ) {
            $this->output_ship_date_column( $order_id );
        }
    }

    /**
     * Output tracking status for orders list column.
     */
    private function output_tracking_column( $order_id ) {
        $shipments = FST_Shipment::get_by_order( $order_id );

        if ( empty( $shipments ) ) {
            echo '<span class="fst-no-tracking">&mdash;</span>';
            return;
        }

        foreach ( $shipments as $shipment ) {
            $color = FST_Carrier::get_status_color( $shipment->status );
            $label = FST_Carrier::get_status_label( $shipment->status );
            echo '<span class="fst-status-badge-small" style="background-color:' . esc_attr( $color ) . ';color:#fff;padding:2px 8px;border-radius:3px;font-size:11px;display:inline-block;margin:1px 0;">';
            echo esc_html( $label );
            echo '</span> ';
        }
    }

    /**
     * Output the customer-requested delivery date for the orders list column.
     *
     * Always shows the date the customer selected at checkout, regardless of
     * whether tracking has been added — the column's job is forward planning
     * ("what needs to go out tomorrow?"), not a history of when each box left.
     * The actual ship date(s) for an order are visible inside that order's
     * Shipment Tracking meta box on the edit page.
     */
    private function output_ship_date_column( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            echo '<span style="color:#999;">&mdash;</span>';
            return;
        }

        // Don't show a date for refunded orders.
        if ( 'refunded' === $order->get_status() ) {
            echo '<span style="color:#999;">&mdash;</span>';
            return;
        }

        $requested_date = $this->get_requested_ship_date( $order_id );

        if ( $requested_date ) {
            echo '<span style="display:block;font-size:12px;white-space:nowrap;color:#b26b00;">';
            echo esc_html( $requested_date );
            echo '</span>';
        } else {
            echo '<span style="color:#999;">&mdash;</span>';
        }
    }

    /**
     * Get the requested shipping date from order meta (set by checkout plugin).
     *
     * @param int $order_id
     * @return string|false Formatted date string or false.
     */
    private function get_requested_ship_date( $order_id ) {
        /**
         * Filter the meta keys checked for a requested shipping date.
         *
         * @param array $meta_keys Meta keys to check (first match wins).
         */
        $meta_keys = apply_filters( 'fst_requested_ship_date_meta_keys', array(
            '_fishotel_shipping_date',
            'fishotel_shipping_date',
        ) );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        foreach ( $meta_keys as $key ) {
            $value = $order->get_meta( $key );
            if ( ! empty( $value ) ) {
                // Try to parse as a date. The value may already be formatted
                // (e.g. "Monday, April 20, 2026") or a raw date string.
                $timestamp = strtotime( $value );
                if ( $timestamp ) {
                    return date_i18n( get_option( 'date_format' ), $timestamp );
                }
                // Return as-is if it can't be parsed.
                return $value;
            }
        }

        return false;
    }
}
