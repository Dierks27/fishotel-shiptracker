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

        if ( ! $order_id || empty( $tracking_number ) ) {
            wp_send_json_error( 'Order ID and tracking number are required.' );
        }

        // Auto-detect carrier if needed.
        if ( 'auto' === $carrier ) {
            $carrier = FST_Carrier::detect_carrier( $tracking_number );
        }

        // Check for duplicate.
        $existing = FST_Shipment::get_by_tracking( $tracking_number );
        if ( $existing ) {
            wp_send_json_error( 'This tracking number has already been added.' );
        }

        // Create the shipment.
        $shipment_id = FST_Shipment::create( array(
            'order_id'        => $order_id,
            'tracking_number' => $tracking_number,
            'carrier'         => $carrier,
            'status'          => 'unknown',
            'ship_date'       => current_time( 'mysql' ),
        ) );

        if ( ! $shipment_id ) {
            wp_send_json_error( 'Failed to save tracking data.' );
        }

        // Immediately poll for status.
        $carrier_instance = $this->get_carrier_instance( $carrier );
        if ( $carrier_instance && $carrier_instance->has_credentials() ) {
            $tracker = new FST_Tracker();
            $tracker->poll_shipment( FST_Shipment::get( $shipment_id ) );
        }

        // Add order note.
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->add_order_note(
                sprintf( 'Shipment tracking added: %s via %s', $tracking_number, strtoupper( $carrier ) ),
                false // Not customer-facing note.
            );
        }

        wp_send_json_success( array(
            'message'     => 'Tracking added successfully!',
            'shipment_id' => $shipment_id,
            'carrier'     => $carrier,
        ) );
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

        // Avoid duplicates.
        if ( FST_Shipment::get_by_tracking( $tracking_number ) ) {
            return;
        }

        FST_Shipment::create( array(
            'order_id'        => $order_id,
            'tracking_number' => $tracking_number,
            'carrier'         => $carrier,
        ) );
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
                $new_columns['fst_ship_date'] = __( 'Ship Date', 'fishotel-shiptracker' );
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
     * Output ship date for orders list column.
     *
     * Shows the shipment ship_date if tracking exists, otherwise falls back
     * to the requested shipping date stored as order meta by checkout plugins.
     */
    private function output_ship_date_column( $order_id ) {
        $shipments = FST_Shipment::get_by_order( $order_id );

        // If shipments exist, show the shipment ship dates.
        if ( ! empty( $shipments ) ) {
            foreach ( $shipments as $shipment ) {
                if ( ! empty( $shipment->ship_date ) ) {
                    echo '<span style="display:block;font-size:12px;white-space:nowrap;">';
                    echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $shipment->ship_date ) ) );
                    echo '</span>';
                } else {
                    echo '<span style="color:#999;">&mdash;</span>';
                }
            }
            return;
        }

        // No shipments — check for a requested shipping date in order meta.
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
