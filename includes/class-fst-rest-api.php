<?php
/**
 * REST API endpoints.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_REST_API {

    protected $namespace = 'fst/v1';

    /**
     * Register REST routes. Called via add_action('rest_api_init') from main plugin file.
     */
    public function register_routes() {
        // Get shipment details (admin).
        register_rest_route( $this->namespace, '/shipment/(?P<id>\d+)', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_shipment' ),
            'permission_callback' => array( $this, 'admin_check' ),
        ) );

        // Add shipment (admin) — same service as the admin meta box, so it
        // fans out to fulfillment sources, stores line-item coverage, dedupes,
        // auto-detects the carrier, registers/polls and transitions the order.
        // Declaring `type` makes WP validate + sanitise each param before the
        // callback runs (rest_validate_request_arg / rest_parse_request_arg).
        register_rest_route( $this->namespace, '/shipment', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'add_shipment' ),
            'permission_callback' => array( $this, 'admin_check' ),
            'args'                => array(
                'order_id'        => array(
                    'description' => 'Order ID — the order number shown in admin.',
                    'type'        => 'integer',
                ),
                'tracking_number' => array(
                    'description' => 'Carrier tracking number.',
                    'type'        => 'string',
                ),
                'carrier'         => array(
                    'description' => 'auto (detect from the number), ups or usps.',
                    'type'        => 'string',
                    'enum'        => array( 'auto', 'ups', 'usps' ),
                    'default'     => 'auto',
                ),
                'line_item_ids'   => array(
                    'description' => 'WC_Order_Item IDs in this box. Regular orders: required. Fulfillment orders: the SOURCE orders\' item ids (source_orders[].line_items[] from GET /order/{id}/shipments); omit only when the box holds every item of every source.',
                    'type'        => 'array',
                    'items'       => array( 'type' => 'integer' ),
                ),
                'ship_date'       => array(
                    'description' => 'Optional ship date, Y-m-d. Defaults to now.',
                    'type'        => 'string',
                ),
                // What the box cost to ship. No `type` on purpose: the service
                // validates these (so "$18.40" or 18.4 both work, and AJAX and
                // REST answer with the same fst_invalid_* codes).
                'label_cost'      => array(
                    'description' => 'Optional. What the label cost, 0 or more (18.40). Stored once per tracking number.',
                ),
                'box_weight'      => array(
                    'description' => 'Optional. Box weight in pounds, above 0.',
                ),
                'box_dims'        => array(
                    'description' => 'Optional. Box size in inches as LxWxH (10x10x10) or the name of a standard box from GET /shipping-options.',
                ),
                'label_source'    => array(
                    'description' => 'Optional. Where the label was bought — a name from GET /shipping-options, or free text (40 characters).',
                ),
                // What went into the box besides the label. Left out, a standard
                // box records its usual materials; sent, it is taken as it is.
                'materials'       => array(
                    'description' => 'Optional. { lines: [ { supply_id or supply (name), qty } ], foam_cost, paper_cost }. Supplies are listed by GET /shipping-options.',
                ),
            ),
        ) );

        // Record or change what a box cost to ship (admin). Acts on the box
        // (tracking number) of the shipment.
        register_rest_route( $this->namespace, '/shipment/(?P<id>\d+)/cost', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'set_shipment_cost' ),
            'permission_callback' => array( $this, 'admin_check' ),
        ) );

        // How an order with no label of its own went out (admin): a local
        // pickup, or in another order's box. Order meta only.
        register_rest_route( $this->namespace, '/order/(?P<id>\d+)/ship-mode', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'set_order_ship_mode' ),
            'permission_callback' => array( $this, 'admin_check' ),
        ) );

        // Standard boxes, label sources and supplies a caller may pick from (admin).
        register_rest_route( $this->namespace, '/shipping-options', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_shipping_options' ),
            'permission_callback' => array( $this, 'admin_check' ),
        ) );

        // Effective shipments + line-item coverage for an order (admin).
        register_rest_route( $this->namespace, '/order/(?P<id>\d+)/shipments', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_order_shipments' ),
            'permission_callback' => array( $this, 'admin_check' ),
        ) );

        // Force recheck (admin).
        register_rest_route( $this->namespace, '/shipment/(?P<id>\d+)/recheck', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'recheck_shipment' ),
            'permission_callback' => array( $this, 'admin_check' ),
        ) );

        // Public tracking lookup.
        register_rest_route( $this->namespace, '/track', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'public_track' ),
            'permission_callback' => '__return_true',
        ) );

        // Analytics (admin).
        register_rest_route( $this->namespace, '/analytics', array(
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => array( $this, 'get_analytics' ),
            'permission_callback' => array( $this, 'admin_check' ),
        ) );

        // EasyPost webhook (public — authenticated by HMAC signature).
        register_rest_route( $this->namespace, '/easypost-webhook', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'easypost_webhook' ),
            'permission_callback' => '__return_true',
        ) );
    }

    /**
     * Admin permission check.
     *
     * Works with any WP authentication that resolves a user before the
     * callback: cookie + X-WP-Nonce (admin.js) or an Application Password
     * sent as HTTP Basic auth over HTTPS (the label skill / curl). The plugin
     * deliberately hooks neither `determine_current_user` nor
     * `rest_authentication_errors`, and WooCommerce's own key/secret auth is
     * scoped to its `wc/*` namespaces, so nothing intercepts `fst/v1`.
     */
    public function admin_check() {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * The FST_Order instance that owns the add-tracking service. Prefer the
     * one the plugin booted so hooks aren't registered twice.
     *
     * @return FST_Order
     */
    private function order_service() {
        $plugin = function_exists( 'FST' ) ? FST() : null;
        if ( $plugin && isset( $plugin->order ) && $plugin->order instanceof FST_Order ) {
            return $plugin->order;
        }
        return new FST_Order();
    }

    /**
     * Map a service WP_Error onto an HTTP response. Messages are the ones the
     * admin meta box shows; the status comes from the error code.
     *
     * @param WP_Error $error
     * @return WP_REST_Response
     */
    private function error_response( WP_Error $error ) {
        $status_by_code = array(
            'fst_order_not_found'    => 404,
            'fst_shipment_not_found' => 404,
            'fst_duplicate_tracking' => 409,
            'fst_order_has_shipments' => 409,
            'fst_create_failed'      => 500,
            'fst_cost_save_failed'   => 500,
        );

        $code   = (string) $error->get_error_code();
        $status = isset( $status_by_code[ $code ] ) ? $status_by_code[ $code ] : 400;

        return new WP_REST_Response( array(
            'error' => $error->get_error_message(),
            'code'  => $code,
        ), $status );
    }

    /**
     * GET /shipment/{id}
     *
     * The four cost fields on `shipment` are the BOX's values (read by
     * tracking number — this row may hold NULL when a sibling row carries
     * them), and `shared_box` says whether other orders are in the same box.
     * `materials`, `materials_total` and `total_cost` are the box's too.
     */
    public function get_shipment( $request ) {
        $shipment = FST_Shipment::get( absint( $request['id'] ) );

        if ( ! $shipment ) {
            return new WP_REST_Response( array( 'error' => 'Shipment not found' ), 404 );
        }

        $box = FST_Shipment::get_box_cost( $shipment->tracking_number );
        $box = $box ? FST_Shipment::with_materials( $box ) : null;
        foreach ( array_merge( FST_Label_Cost::FIELDS, array( 'shared_box', 'materials', 'materials_total', 'total_cost' ) ) as $field ) {
            $shipment->$field = $box ? $box[ $field ] : ( 'shared_box' === $field ? false : null );
        }

        $events = FST_Shipment::get_events( $shipment->id );

        return new WP_REST_Response( array(
            'shipment' => $shipment,
            'events'   => $events,
        ), 200 );
    }

    /**
     * POST /shipment
     *
     * Delegates to FST_Order::add_tracking() — the exact code path the admin
     * meta box uses — and returns its result with 201, or the WP_Error mapped
     * to 400 (bad input), 404 (order) or 409 (duplicate on this order).
     */
    public function add_shipment( $request ) {
        $order_id = absint( $request->get_param( 'order_id' ) );

        // Resolve "order not found" up front: for a regular order with no
        // line_item_ids the service would otherwise answer with the
        // item-selection error, which is misleading for a typo'd order id.
        // A refund id resolves to WC_Order_Refund and is "not found" too.
        if ( $order_id && ! ( wc_get_order( $order_id ) instanceof WC_Order ) ) {
            return $this->error_response( new WP_Error( 'fst_order_not_found', 'Order not found.' ) );
        }

        $args = array( 'order_id' => $order_id );
        foreach ( array( 'tracking_number', 'carrier', 'line_item_ids', 'ship_date', 'label_cost', 'box_weight', 'box_dims', 'label_source' ) as $field ) {
            $value = $request->get_param( $field );
            if ( null !== $value ) {
                $args[ $field ] = $value;
            }
        }
        // has_param(): materials sent as null means "record none", which is
        // not the same as leaving the key out (a standard box's defaults).
        if ( $request->has_param( 'materials' ) ) {
            $args['materials'] = $request->get_param( 'materials' );
        }

        $result = $this->order_service()->add_tracking( $args );

        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result );
        }

        return new WP_REST_Response( $result, 201 );
    }

    /**
     * POST /shipment/{id}/cost
     *
     * Body: any of label_cost, box_weight, box_dims, label_source, materials.
     * A key sent with an empty value (or JSON null) clears that field; a key
     * that is not sent is left as it is. Delegates to
     * FST_Order::set_label_cost(), which writes to the box of the shipment's
     * tracking number.
     */
    public function set_shipment_cost( $request ) {
        $args = array();
        foreach ( array_merge( FST_Label_Cost::FIELDS, array( 'materials' ) ) as $field ) {
            // has_param(), not get_param(): an explicit null must count as sent.
            if ( $request->has_param( $field ) ) {
                $args[ $field ] = $request->get_param( $field );
            }
        }

        $result = $this->order_service()->set_label_cost( absint( $request['id'] ), $args );

        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result );
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * POST /order/{id}/ship-mode
     *
     * Body: mode (label | local | with_order), with_order_id (with_order),
     * optional date (Y-m-d) and, for a local pickup, optional materials.
     * Delegates to FST_Order::set_ship_mode(). 409 when the order has a
     * label of its own.
     */
    public function set_order_ship_mode( $request ) {
        $args = array();
        foreach ( array( 'mode', 'with_order_id', 'date', 'materials' ) as $field ) {
            if ( $request->has_param( $field ) ) {
                $args[ $field ] = $request->get_param( $field );
            }
        }

        $result = $this->order_service()->set_ship_mode( absint( $request['id'] ), $args );

        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result );
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * GET /shipping-options
     *
     * What is saved under Settings → Boxes & label costs, so a script can
     * send values the reports will group: the standard boxes — each with
     * what it starts with (foam_cost, paper_cost, paper_feet, ice_packs and
     * supply_id, the id of the box's own supply or null) — the label
     * sources, the supplies (id, name, unit, role, price_each, on_hand …),
     * the foam waste percent and the ship modes.
     */
    public function get_shipping_options() {
        $supplies = FST_Supplies::get_supplies();

        $boxes = array();
        foreach ( FST_Settings::get_standard_boxes() as $box ) {
            $supply           = FST_Supplies::supply_for_role( 'box', $supplies, $box['name'] );
            $box['supply_id'] = $supply ? $supply['id'] : null;
            $boxes[]          = $box;
        }

        return new WP_REST_Response( array(
            'standard_boxes'     => $boxes,
            'label_sources'      => FST_Settings::get_label_sources(),
            'supplies'           => $supplies,
            'foam_waste_percent' => FST_Supplies::get_waste_percent(),
            'ship_modes'         => FST_Ship_Mode::MODES,
        ), 200 );
    }

    /**
     * GET /order/{id}/shipments
     *
     * Effective shipments for an order (fan-in for fulfillments, via
     * FST_Order::get_effective_shipments()) plus line-item coverage, so a
     * caller can choose the line_item_ids to POST and confirm the save. For
     * a fulfillment the payload also carries source_orders[] with each
     * source's own items and coverage — those are the ids to POST.
     */
    public function get_order_shipments( $request ) {
        $result = $this->order_service()->describe_order_shipments( absint( $request['id'] ) );

        if ( is_wp_error( $result ) ) {
            return $this->error_response( $result );
        }

        return new WP_REST_Response( $result, 200 );
    }

    /**
     * POST /shipment/{id}/recheck
     */
    public function recheck_shipment( $request ) {
        $shipment = FST_Shipment::get( absint( $request['id'] ) );

        if ( ! $shipment ) {
            return new WP_REST_Response( array( 'error' => 'Shipment not found' ), 404 );
        }

        $tracker = new FST_Tracker();
        $result  = $tracker->poll_shipment( $shipment );

        // Re-fetch updated data.
        $updated = FST_Shipment::get( $shipment->id );

        return new WP_REST_Response( array(
            'status'        => $updated->status,
            'status_label'  => FST_Carrier::get_status_label( $updated->status ),
            'status_detail' => $updated->status_detail,
        ), 200 );
    }

    /**
     * POST /track (public — customer lookup by order number + email)
     */
    public function public_track( $request ) {
        $order_number = sanitize_text_field( $request->get_param( 'order_number' ) );
        $email        = sanitize_email( $request->get_param( 'email' ) );

        if ( empty( $order_number ) || empty( $email ) ) {
            return new WP_REST_Response( array( 'error' => 'Order number and email required' ), 400 );
        }

        $not_found = new WP_REST_Response( array( 'error' => 'No matching order found' ), 404 );

        // Look up order by ID (order number is usually the ID in basic WooCommerce).
        $order = wc_get_order( absint( $order_number ) );

        // If that didn't work, search by meta.
        if ( ! $order instanceof WC_Order ) {
            $orders = wc_get_orders( array(
                'limit'  => 1,
                'return' => 'objects',
                'meta_query' => array(
                    array(
                        'key'   => '_order_number',
                        'value' => $order_number,
                    ),
                ),
            ) );
            $order = ! empty( $orders ) ? $orders[0] : null;
        }

        // WHY (shiptracker-6): wc_get_order() returns a WC_Order_Refund for refund
        // IDs (refunds share the order ID sequence) and that class has no
        // get_billing_email(), so an anonymous POST with a refund id fatals
        // (HTTP 500). Anything that is not a real order is simply "not found" —
        // the same generic error as a missing order, so nothing is enumerable.
        if ( ! $order instanceof WC_Order ) {
            return $not_found;
        }

        // Verify email — return same error as "not found" to prevent order enumeration.
        if ( strtolower( $order->get_billing_email() ) !== strtolower( $email ) ) {
            return $not_found;
        }

        $shipments = FST_Shipment::get_by_order( $order->get_id() );

        if ( empty( $shipments ) ) {
            return new WP_REST_Response( array( 'error' => 'No tracking info found' ), 404 );
        }

        // Build response with events.
        $data = array();
        foreach ( $shipments as $s ) {
            $events = FST_Shipment::get_events( $s->id );
            $data[] = array(
                'tracking_number' => $s->tracking_number,
                'carrier'         => $s->carrier,
                'status'          => $s->status,
                'status_label'    => FST_Carrier::get_status_label( $s->status ),
                'status_detail'   => $s->status_detail,
                'est_delivery'    => $s->est_delivery,
                'ship_date'       => $s->ship_date,
                'delivered_date'  => $s->delivered_date,
                'events'          => $events,
            );
        }

        return new WP_REST_Response( array( 'shipments' => $data ), 200 );
    }

    /**
     * POST /easypost-webhook
     *
     * Authenticated by HMAC-SHA256 signature, not by a WordPress capability.
     */
    public function easypost_webhook( $request ) {
        $raw_body  = $request->get_body();
        $signature = $request->get_header( 'X-Hmac-Signature' );

        // Verify the signature before doing anything else.
        if ( ! FST_EasyPost::verify_signature( $raw_body, $signature ) ) {
            return new WP_REST_Response( array( 'error' => 'Invalid signature' ), 401 );
        }

        $decoded = json_decode( $raw_body, true );
        if ( ! is_array( $decoded ) ) {
            return new WP_REST_Response( array( 'error' => 'Malformed JSON' ), 400 );
        }

        $result = FST_EasyPost::handle_webhook( $decoded );
        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array( 'error' => $result->get_error_message() ), 422 );
        }

        return new WP_REST_Response( array( 'ok' => true ), 200 );
    }

    /**
     * GET /analytics
     */
    public function get_analytics( $request ) {
        $counts = FST_Shipment::count_by_status();
        $late   = FST_Shipment::get_late_shipments();

        return new WP_REST_Response( array(
            'status_counts' => $counts,
            'late_count'    => count( $late ),
            'total'         => array_sum( $counts ),
        ), 200 );
    }
}
