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
        $order_id  = $post_or_order instanceof WC_Order ? $post_or_order->get_id() : $post_or_order->ID;
        $shipments = $this->get_effective_shipments( $order_id );

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
                <?php
                $order      = wc_get_order( $order_id );
                $line_items = $order ? $order->get_items() : array();
                // Fulfillment items don't 1:1 with source order items, and
                // ajax_add_tracking handles the fan-out via meta lookup. Skip
                // the per-item picker on fulfillments so it doesn't mis-scope
                // shipments. Detect via meta (theme sets _fishotel_is_fulfillment
                // = '1' on creation and it stays put) rather than status, which
                // can be flipped past Fulfillment manually or by other
                // automation and would silently lose the fan-out.
                $is_fulfillment = $order && '1' === (string) $order->get_meta( '_fishotel_is_fulfillment' );

                if ( ! $is_fulfillment && ! empty( $line_items ) ) :
                    // Items already covered by an existing shipment shouldn't
                    // be selectable again — they're crossed out and disabled.
                    $covered_item_ids = array();
                    foreach ( $shipments as $sh ) {
                        if ( empty( $sh->line_item_ids ) ) {
                            continue;
                        }
                        $ids = json_decode( $sh->line_item_ids, true );
                        if ( is_array( $ids ) ) {
                            $covered_item_ids = array_merge( $covered_item_ids, array_map( 'absint', $ids ) );
                        }
                    }
                    $covered_item_ids = array_unique( $covered_item_ids );
                    ?>
                    <div class="fst-line-items-scope" style="margin-bottom: 8px; padding: 8px; background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px;">
                        <p style="margin: 0 0 6px; font-weight: 600; font-size: 12px;">Items in this shipment:</p>
                        <label style="display:block; margin-bottom:4px; font-size:12px;">
                            <input type="checkbox" id="fst-select-all-items" checked>
                            <em>Select all unshipped items</em>
                        </label>
                        <?php foreach ( $line_items as $item_id => $item ) :
                            $is_covered = in_array( (int) $item_id, $covered_item_ids, true );
                            $row_style  = 'display:block; margin:2px 0 2px 16px; font-size:12px;';
                            if ( $is_covered ) {
                                $row_style .= 'color:#999; text-decoration:line-through;';
                            }
                            ?>
                            <label style="<?php echo esc_attr( $row_style ); ?>">
                                <?php if ( $is_covered ) : ?>
                                    <input type="checkbox" disabled>
                                <?php else : ?>
                                    <input type="checkbox" name="line_item_ids[]"
                                           value="<?php echo esc_attr( $item_id ); ?>"
                                           class="fst-item-checkbox" checked>
                                <?php endif; ?>
                                <?php echo esc_html( $item->get_name() ); ?>
                                <?php if ( $item->get_quantity() > 1 ) : ?>
                                    <span style="color:#999;"> &times; <?php echo esc_html( $item->get_quantity() ); ?></span>
                                <?php endif; ?>
                                <?php if ( $is_covered ) : ?>
                                    <span style="font-size:10px; color:#1e7e34; text-decoration:none; margin-left:4px;">[shipped]</span>
                                <?php endif; ?>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
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
     *
     * Thin wrapper around add_tracking(). The POST field names (order_id,
     * tracking_number, carrier, line_item_ids[]) and the payload shape
     * (string error message / success data with `message`) are the contract
     * the plugin's own meta box AND the theme's "Shipments" box rely on —
     * keep them stable. Sanitisation happens in normalize_add_tracking_args().
     */
    public function ajax_add_tracking() {
        check_ajax_referer( 'fst_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( 'Permission denied.' );
        }

        $args = array();
        foreach ( array( 'order_id', 'tracking_number', 'carrier', 'line_item_ids' ) as $field ) {
            if ( isset( $_POST[ $field ] ) ) {
                $args[ $field ] = $_POST[ $field ]; // phpcs:ignore WordPress.Security -- sanitised by normalize_add_tracking_args().
            }
        }

        $result = $this->add_tracking( $args );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( $result );
    }

    /**
     * Normalise and validate the arguments for add_tracking().
     *
     * Pure: no database or WooCommerce access, so it is unit-tested without
     * WordPress (tests/add-tracking-args-test.php). Applies exactly the
     * sanitisation the AJAX handler has always applied, plus the optional
     * `ship_date` the REST endpoint accepts.
     *
     * @param array $args {
     *     @type int|string   $order_id        Order ID (the order number shown in admin).
     *     @type string       $tracking_number Carrier tracking number.
     *     @type string       $carrier         'auto' (default / empty), 'ups' or 'usps'.
     *     @type int[]|string $line_item_ids   WC_Order_Item IDs in this box — array, or a
     *                                         comma-separated string for form-encoded callers.
     *     @type string       $ship_date       Optional Y-m-d. Null means "now" at create time.
     * }
     * @return array|WP_Error Normalised args, or WP_Error with code
     *                        `fst_missing_args` or `fst_invalid_ship_date`.
     */
    public static function normalize_add_tracking_args( array $args ) {
        $order_id        = isset( $args['order_id'] ) && is_scalar( $args['order_id'] ) ? absint( $args['order_id'] ) : 0;
        $tracking_number = isset( $args['tracking_number'] ) && is_scalar( $args['tracking_number'] )
            ? sanitize_text_field( (string) $args['tracking_number'] )
            : '';
        $carrier         = isset( $args['carrier'] ) && is_scalar( $args['carrier'] )
            ? sanitize_text_field( (string) $args['carrier'] )
            : 'auto';

        $raw_items = isset( $args['line_item_ids'] ) ? $args['line_item_ids'] : array();
        if ( is_string( $raw_items ) ) {
            // "12,15" convenience for form-encoded / CLI callers. The admin
            // meta boxes always send a real line_item_ids[] array.
            $raw_items = '' === trim( $raw_items ) ? array() : explode( ',', $raw_items );
        }
        $line_item_ids = array();
        foreach ( (array) $raw_items as $raw_id ) {
            $line_item_ids[] = is_scalar( $raw_id ) ? absint( $raw_id ) : 0;
        }
        $line_item_ids = array_values( array_unique( array_filter( $line_item_ids ) ) );

        // empty() on purpose (not '' ===): the pre-1.9.16 AJAX guard used it,
        // so a literal "0" has always been rejected here. Keep it that way.
        if ( ! $order_id || empty( $tracking_number ) ) {
            return new WP_Error( 'fst_missing_args', 'Order ID and tracking number are required.' );
        }

        // Auto-detect carrier if needed (an empty carrier counts as auto).
        if ( 'auto' === $carrier || '' === $carrier ) {
            $carrier = FST_Carrier::detect_carrier( $tracking_number );
        }

        $ship_date = null;
        if ( isset( $args['ship_date'] ) && is_scalar( $args['ship_date'] ) && '' !== trim( (string) $args['ship_date'] ) ) {
            $raw_date = trim( (string) $args['ship_date'] );
            if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $raw_date, $m )
                 || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
                return new WP_Error( 'fst_invalid_ship_date', 'ship_date must be a valid date in Y-m-d format.' );
            }
            $ship_date = $raw_date . ' 00:00:00';
        }

        return array(
            'order_id'        => $order_id,
            'tracking_number' => $tracking_number,
            'carrier'         => $carrier,
            'line_item_ids'   => $line_item_ids,
            'ship_date'       => $ship_date,
        );
    }

    /**
     * Add a tracking number to an order — the service behind the admin meta
     * box (ajax_add_tracking) and POST /fst/v1/shipment.
     *
     * Does everything the admin path does: fulfillment fan-out to source
     * orders, per-source line_item_ids coverage, same-order duplicate check,
     * carrier auto-detect, EasyPost routing / first carrier poll, order notes
     * and the Processing → Shipped transition.
     *
     * @param array $args See normalize_add_tracking_args().
     * @return array|WP_Error {
     *     @type string $message           Human-readable summary (what the meta box shows).
     *     @type int    $shipment_id       First created shipment id.
     *     @type int[]  $shipment_ids      Every created shipment id (one per target order).
     *     @type int[]  $skipped_order_ids Source orders skipped because none of the selected items belong to them.
     *     @type int[]  $failed_order_ids  Target orders whose row insert failed.
     *     @type string $carrier           Resolved carrier slug.
     *     @type array  $shipments         Per-shipment summary after the first poll / EasyPost registration.
     *     @type array  $order_statuses    order_id => WooCommerce status after the transition.
     * }
     * Error codes: fst_missing_args, fst_invalid_ship_date, fst_no_items,
     * fst_nothing_added (bad input), fst_order_not_found, fst_duplicate_tracking,
     * fst_create_failed. Messages are the ones the admin meta box shows.
     */
    public function add_tracking( array $args ) {
        $normalized = self::normalize_add_tracking_args( $args );
        if ( is_wp_error( $normalized ) ) {
            return $normalized;
        }

        $order_id        = $normalized['order_id'];
        $tracking_number = $normalized['tracking_number'];
        $carrier         = $normalized['carrier'];
        $line_item_ids   = $normalized['line_item_ids'];
        $ship_date       = $normalized['ship_date'] ? $normalized['ship_date'] : current_time( 'mysql' );

        // Regular (non-fulfillment) orders must POST an explicit line_item_ids[]
        // subset from the meta-box checkboxes. Without that guard, mixed orders
        // (e.g. fish + EA pellets) would silently auto-flip to Shipped when
        // tracking is added for one box, and the customer would get a
        // "your order has shipped" email while the other items are still here.
        // Fulfillment orders still fall through to the per-source default
        // below (their items don't 1:1 with source items, so the meta-box
        // doesn't render the picker).
        $submitting_order = wc_get_order( $order_id );
        // Detect fulfillment via meta, not status — the order may have been
        // flipped past Fulfillment (Shipped, Processing, Completed) and we
        // still need to fan tracking out to source orders.
        $is_fulfillment   = $submitting_order && '1' === (string) $submitting_order->get_meta( '_fishotel_is_fulfillment' );
        if ( ! $is_fulfillment && empty( $line_item_ids ) ) {
            return new WP_Error( 'fst_no_items', 'Please select at least one item in this shipment.' );
        }

        // Fan out to source orders when this is a fulfillment order.
        // resolve_target_order_ids() is empty only when the order doesn't exist.
        $target_order_ids = $this->resolve_target_order_ids( $order_id );
        if ( empty( $target_order_ids ) ) {
            return new WP_Error( 'fst_order_not_found', 'Could not resolve target order(s).' );
        }

        $shipment_ids = array();
        $failures     = array();
        $skipped      = array();

        foreach ( $target_order_ids as $target_id ) {
            // Skip if this exact tracking# is already on this order (allows duplicates
            // across DIFFERENT source orders for combined-box shipments).
            if ( FST_Shipment::get_by_order_and_tracking( $target_id, $tracking_number ) ) {
                continue;
            }

            // Resolve line_item_ids for this source order:
            //   - If the request explicitly POSTed a subset, intersect with this
            //     source's items so each row only stores its own line items.
            //   - Otherwise (only reachable on the fulfillment fan-out path now —
            //     regular orders are rejected up front by the guard above),
            //     default to every item on the source order so the strict
            //     coverage check in is_order_fully_shipped() still passes.
            $items_for_this_order = array();
            $source_order         = wc_get_order( $target_id );
            if ( $source_order ) {
                $source_item_ids = array_keys( $source_order->get_items() );
                if ( ! empty( $line_item_ids ) ) {
                    $items_for_this_order = array_values( array_intersect( $line_item_ids, $source_item_ids ) );
                } else {
                    $items_for_this_order = $source_item_ids;
                }
            }

            // WHY (shiptracker-1): when an explicit item subset was posted and
            // NONE of it belongs to this source order, nothing of this order is
            // in the box. Creating a row anyway stored line_item_ids = NULL,
            // which every reader (is_order_fully_delivered, the theme meta box
            // and consolidated email) treats as "covers ALL items" — so the
            // untouched sibling was emailed "shipped", auto-completed on
            // delivery and blocked from adding its own tracking later. Skip it.
            // With no subset posted at all (legacy / whole-box fan-out) the
            // covers-all default above still applies.
            //
            // WHY (kill-switch exemption): with FST_FULFILLMENT_FANOUT_OFF /
            // fst_fulfillment_fanout_enabled='0' the only target is the
            // submitting fulfillment order itself, while the theme meta box
            // still posts its SOURCE orders' item ids (order item ids are
            // globally unique, so they never match F's own items). The guard
            // above would then skip F too, leaving no row and an AJAX error.
            // The documented fallback is "tracking lands on the fulfillment
            // order_id directly" — keep that legacy covers-all (NULL) row.
            $is_self_target = $is_fulfillment && (int) $target_id === (int) $order_id;
            if ( ! empty( $line_item_ids ) && empty( $items_for_this_order ) && ! $is_self_target ) {
                $skipped[] = $target_id;
                if ( $is_fulfillment && $submitting_order ) {
                    $submitting_order->add_order_note(
                        sprintf(
                            'ShipTracker: tracking %s not added to source order #%d — none of the selected items belong to it.',
                            $tracking_number,
                            $target_id
                        ),
                        false
                    );
                }
                continue;
            }

            $shipment_id = FST_Shipment::create( array(
                'order_id'        => $target_id,
                'tracking_number' => $tracking_number,
                'carrier'         => $carrier,
                'status'          => 'unknown',
                'ship_date'       => $ship_date,
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
            if ( ! empty( $failures ) ) {
                return new WP_Error( 'fst_create_failed', 'Failed to save tracking data.' );
            }
            if ( ! empty( $skipped ) ) {
                return new WP_Error( 'fst_nothing_added', 'None of the selected items belong to the target order(s). No tracking was added.' );
            }
            return new WP_Error( 'fst_duplicate_tracking', 'This tracking number is already on this order.' );
        }

        // Register / poll each newly created shipment.
        foreach ( $shipment_ids as $sid ) {
            $this->poll_or_register_shipment( $sid, $carrier, $tracking_number );
        }

        // Post-poll snapshot so API callers can confirm the save without a
        // second request: each row's status and each target order's status.
        $shipments      = array();
        $order_statuses = array();
        foreach ( $shipment_ids as $sid ) {
            $row = FST_Shipment::get( $sid );
            if ( ! $row ) {
                continue;
            }
            $shipments[] = $this->summarize_shipment( $row );

            $target_order = wc_get_order( $row->order_id );
            if ( $target_order ) {
                $order_statuses[ (int) $row->order_id ] = $target_order->get_status();
            }
        }

        return array(
            'message'           => count( $shipment_ids ) > 1
                ? sprintf( 'Tracking added to %d source orders.', count( $shipment_ids ) )
                : 'Tracking added successfully!',
            'shipment_id'       => $shipment_ids[0],
            'shipment_ids'      => $shipment_ids,
            'skipped_order_ids' => $skipped,
            'failed_order_ids'  => $failures,
            'carrier'           => $carrier,
            'shipments'         => $shipments,
            'order_statuses'    => $order_statuses,
        );
    }

    /**
     * API-friendly view of one wp_fst_shipments row: the row's own columns
     * minus the raw tracking_data blob, with `line_item_ids` decoded to an
     * int array (NULL stays NULL = covers every item of order_id), plus the
     * status label and carrier tracking URL. The DB row shape is untouched.
     *
     * @param object $row Shipment row (stdClass from FST_Shipment).
     * @return array
     */
    public function summarize_shipment( $row ) {
        $line_item_ids = null;
        if ( ! empty( $row->line_item_ids ) ) {
            $decoded = json_decode( $row->line_item_ids, true );
            if ( is_array( $decoded ) ) {
                $line_item_ids = array_values( array_map( 'absint', $decoded ) );
            }
        }

        return array(
            'id'               => (int) $row->id,
            'order_id'         => (int) $row->order_id,
            'tracking_number'  => $row->tracking_number,
            'carrier'          => $row->carrier,
            'tracking_url'     => $this->get_carrier_url( $row ),
            'status'           => $row->status,
            'status_label'     => FST_Carrier::get_status_label( $row->status ),
            'status_detail'    => isset( $row->status_detail ) ? $row->status_detail : '',
            'tracker_provider' => isset( $row->tracker_provider ) ? $row->tracker_provider : '',
            'line_item_ids'    => $line_item_ids,
            'ship_date'        => isset( $row->ship_date ) ? $row->ship_date : null,
            'est_delivery'     => isset( $row->est_delivery ) ? $row->est_delivery : null,
            'delivered_date'   => isset( $row->delivered_date ) ? $row->delivered_date : null,
            'last_checked'     => isset( $row->last_checked ) ? $row->last_checked : null,
            'created_at'       => isset( $row->created_at ) ? $row->created_at : null,
            'updated_at'       => isset( $row->updated_at ) ? $row->updated_at : null,
        );
    }

    /**
     * Describe an order's effective shipments and line-item coverage — what
     * GET /fst/v1/order/{id}/shipments returns so a caller (the label skill)
     * can pick the line_item_ids to POST and then confirm the save.
     *
     * Reuses get_effective_shipments(): a fulfillment order with no direct
     * rows fans IN from its source orders, deduped by tracking number.
     *
     * For a fulfillment order the payload also carries `source_orders[]`:
     * each source's own line items and coverage. Order item ids are globally
     * unique, so the fulfillment's own `line_items[]` can never be POSTed
     * (add_tracking() intersects the posted ids with each SOURCE's items and
     * skips sources with no match). Without the source items a REST caller
     * could only omit line_item_ids, which stores a covers-all row on every
     * source and flips a source to Shipped while part of it is still in QT —
     * the theme meta box avoids that by posting the source item ids, and
     * this is what lets an API caller do the same.
     *
     * @param int $order_id
     * @return array|WP_Error {
     *     @type array      $order         id, number, status, is_fulfillment, source_order_ids
     *                                     (where POST /shipment fans out to; empty for regular orders).
     *     @type array      $line_items    [ { id, name, quantity, covered } ] — `covered` = some shipment
     *                                     lists the item explicitly. Fulfillment items never match
     *                                     (coverage lives on the source orders).
     *     @type bool|null  $fully_shipped Every line item covered (regular orders); null for fulfillments.
     *     @type array      $shipments     summarize_shipment() rows.
     *     @type array      $source_orders Fulfillments only, one per source_order_ids entry:
     *                                     { id, number, status, line_items[], fully_shipped, shipments[] }
     *                                     computed from that source's OWN rows. Empty for regular orders.
     * }
     */
    public function describe_order_shipments( $order_id ) {
        $order_id = absint( $order_id );
        $order    = $order_id ? wc_get_order( $order_id ) : false;
        if ( ! $order instanceof WC_Order ) {
            return new WP_Error( 'fst_order_not_found', 'Order not found.' );
        }

        $is_fulfillment = '1' === (string) $order->get_meta( '_fishotel_is_fulfillment' );
        $shipments      = $this->get_effective_shipments( $order_id );

        $source_order_ids = array();
        $source_orders    = array();
        if ( $is_fulfillment ) {
            $source_order_ids = array_values( array_diff( $this->resolve_target_order_ids( $order_id ), array( $order_id ) ) );

            foreach ( $source_order_ids as $source_id ) {
                $source = wc_get_order( $source_id );
                if ( ! $source instanceof WC_Order ) {
                    continue;
                }
                // Direct rows only: the write side (add_tracking,
                // is_order_fully_shipped) looks at rows owned by the source.
                $source_rows     = (array) FST_Shipment::get_by_order( $source_id );
                $source_orders[] = array(
                    'id'            => $source->get_id(),
                    'number'        => (string) $source->get_order_number(),
                    'status'        => $source->get_status(),
                    'line_items'    => $this->describe_line_items( $source, $source_rows ),
                    'fully_shipped' => $this->is_order_fully_shipped( $source ),
                    'shipments'     => array_map( array( $this, 'summarize_shipment' ), $source_rows ),
                );
            }
        }

        return array(
            'order'         => array(
                'id'               => $order->get_id(),
                'number'           => (string) $order->get_order_number(),
                'status'           => $order->get_status(),
                'is_fulfillment'   => $is_fulfillment,
                'source_order_ids' => $source_order_ids,
            ),
            'line_items'    => $this->describe_line_items( $order, $shipments ),
            'fully_shipped' => $is_fulfillment ? null : $this->is_order_fully_shipped( $order ),
            'shipments'     => array_map( array( $this, 'summarize_shipment' ), (array) $shipments ),
            'source_orders' => $source_orders,
        );
    }

    /**
     * Line items of $order with `covered` = some row in $shipments lists the
     * item explicitly in its line_item_ids (a NULL list never covers here,
     * same rule as is_order_fully_shipped() and the meta-box picker).
     *
     * @param WC_Order   $order
     * @param array|null $shipments Shipment rows (stdClass) to compute coverage from.
     * @return array [ { id, name, quantity, covered } ]
     */
    private function describe_line_items( $order, $shipments ) {
        $covered_item_ids = array();
        foreach ( (array) $shipments as $row ) {
            if ( empty( $row->line_item_ids ) ) {
                continue;
            }
            $ids = json_decode( $row->line_item_ids, true );
            if ( is_array( $ids ) ) {
                $covered_item_ids = array_merge( $covered_item_ids, array_map( 'absint', $ids ) );
            }
        }
        $covered_item_ids = array_unique( $covered_item_ids );

        $line_items = array();
        foreach ( $order->get_items() as $item_id => $item ) {
            $line_items[] = array(
                'id'       => (int) $item_id,
                'name'     => $item->get_name(),
                'quantity' => (int) $item->get_quantity(),
                'covered'  => in_array( (int) $item_id, $covered_item_ids, true ),
            );
        }

        return $line_items;
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

        // Meta-based detection: the order may have moved past Fulfillment
        // status (manually reverted, auto-advanced, etc.) but it's still a
        // combined-box meta order and tracking must still fan out.
        if ( '1' === (string) $order->get_meta( '_fishotel_is_fulfillment' ) ) {
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
     * Whether every line item on $order is explicitly covered by at least one
     * shipment's `line_item_ids` list.
     *
     * The pre-1.9.6 implementation treated a shipment with empty/NULL
     * line_item_ids as "covers everything", which misfired on partial
     * fulfillments: adding tracking for one box (e.g. EA pellets) would flip
     * the entire mixed order Processing → Shipped and trigger a misleading
     * "your order has shipped" email even though the fish were still in QT.
     * Now: only shipments with an explicit line_item_ids list contribute to
     * coverage, and every order item id must appear in at least one such
     * list. Empty order or no shipments → false.
     *
     * @param WC_Order $order
     * @return bool
     */
    private function is_order_fully_shipped( $order ) {
        $order_item_ids = array_keys( $order->get_items() );
        if ( empty( $order_item_ids ) ) {
            return false;
        }

        $shipments = FST_Shipment::get_by_order( $order->get_id() );
        if ( empty( $shipments ) ) {
            return false;
        }

        $covered_ids = array();
        foreach ( $shipments as $s ) {
            if ( empty( $s->line_item_ids ) ) {
                continue;
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
     * Get the shipments to display for an order.
     *
     * For a fulfillment order with no direct shipments, fans IN from the
     * source orders linked to this fulfillment and dedupes by tracking
     * number — the v1.9 fan-out creates one shipment row per source sharing
     * the same number, but the fulfillment row itself stays empty, so a
     * plain `get_by_order()` returns nothing. This is the inverse view of
     * the write-side fan-out: shipments belong to source orders, but the
     * fulfillment's UI surfaces (orders-list column, meta box) need to see
     * them.
     *
     * Sources are discovered via an inverse `wc_get_orders` query against
     * `_fishotel_fulfilled_by_order` (set on each SOURCE — per the theme,
     * the single source of truth for the link). The `_fishotel_fulfillment_sources`
     * cache on the fulfillment itself can be empty or stale on real data.
     *
     * Intentionally NOT gated on order status: theme v1.18.2 auto-advances
     * fulfillments to wc-shipped / wc-completed once their sources ship, and
     * the column has to keep showing the badges through that whole lifecycle.
     * Non-fulfillment orders pass through harmlessly — nothing points up at
     * them, so the inverse query returns empty.
     *
     * Display-only — write paths still use `FST_Shipment::get_by_order()`
     * directly so they only act on rows actually owned by that order.
     *
     * Kill switch: `define( 'FST_FULFILLMENT_DISPLAY_AGGREGATION_OFF', true )`
     * disables the fan-in and falls back to direct lookup.
     *
     * Public so the REST layer (describe_order_shipments) can reuse it.
     *
     * @param int $order_id
     * @return array Shipment rows (stdClass) to display.
     */
    public function get_effective_shipments( $order_id ) {
        $shipments = FST_Shipment::get_by_order( $order_id );

        // Direct shipments win — short-circuit. Also short-circuits the kill
        // switch case implicitly: with the constant set, this stays at "direct
        // lookup only" since we never reach the fan-in below.
        if ( ! empty( $shipments ) ) {
            return $shipments;
        }

        if ( defined( 'FST_FULFILLMENT_DISPLAY_AGGREGATION_OFF' ) && FST_FULFILLMENT_DISPLAY_AGGREGATION_OFF ) {
            return array();
        }

        if ( ! wc_get_order( $order_id ) ) {
            return array();
        }

        // Find every source order that points back at this fulfillment.
        // _fishotel_fulfilled_by_order lives on the SOURCE — the theme's
        // documented single source of truth for the link. status=any covers
        // the whole fulfillment lifecycle (wc-fulfillment → wc-shipped →
        // wc-completed) as well as anything custom.
        $source_ids = wc_get_orders( array(
            'meta_key'   => '_fishotel_fulfilled_by_order',
            'meta_value' => $order_id,
            'limit'      => -1,
            'return'     => 'ids',
            'status'     => 'any',
        ) );

        if ( empty( $source_ids ) ) {
            return array();
        }

        $seen   = array();
        $unique = array();
        foreach ( $source_ids as $sid ) {
            foreach ( FST_Shipment::get_by_order( $sid ) as $sh ) {
                // Dedupe by tracking number — fan-out creates one row per source
                // sharing the same number; show one badge per physical shipment.
                if ( in_array( $sh->tracking_number, $seen, true ) ) {
                    continue;
                }
                $seen[]   = $sh->tracking_number;
                $unique[] = $sh;
            }
        }

        return $unique;
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

            // Default the shipment to covering every item on the target order
            // (same reasoning as ajax_add_tracking: v1.9.6's strict coverage
            // check would otherwise leave Processing orders stuck forever
            // because the non-AJAX form has no line_item_ids[] field).
            $source_order  = wc_get_order( $target_id );
            $all_item_ids  = $source_order ? array_keys( $source_order->get_items() ) : array();

            FST_Shipment::create( array(
                'order_id'        => $target_id,
                'tracking_number' => $tracking_number,
                'carrier'         => $carrier,
                'line_item_ids'   => ! empty( $all_item_ids ) ? wp_json_encode( $all_item_ids ) : null,
            ) );

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
        $shipments = $this->get_effective_shipments( $order_id );

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
