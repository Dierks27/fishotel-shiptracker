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

        // Add shipment (admin).
        register_rest_route( $this->namespace, '/shipment', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'add_shipment' ),
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
    }

    /**
     * Admin permission check.
     */
    public function admin_check() {
        return current_user_can( 'manage_woocommerce' );
    }

    /**
     * GET /shipment/{id}
     */
    public function get_shipment( $request ) {
        $shipment = FST_Shipment::get( absint( $request['id'] ) );

        if ( ! $shipment ) {
            return new WP_REST_Response( array( 'error' => 'Shipment not found' ), 404 );
        }

        $events = FST_Shipment::get_events( $shipment->id );

        return new WP_REST_Response( array(
            'shipment' => $shipment,
            'events'   => $events,
        ), 200 );
    }

    /**
     * POST /shipment
     */
    public function add_shipment( $request ) {
        $order_id        = absint( $request->get_param( 'order_id' ) );
        $tracking_number = sanitize_text_field( $request->get_param( 'tracking_number' ) );
        $carrier         = sanitize_text_field( $request->get_param( 'carrier' ) );

        if ( ! $order_id || empty( $tracking_number ) ) {
            return new WP_REST_Response( array( 'error' => 'order_id and tracking_number required' ), 400 );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return new WP_REST_Response( array( 'error' => 'Order not found' ), 404 );
        }

        if ( 'auto' === $carrier || empty( $carrier ) ) {
            $carrier = FST_Carrier::detect_carrier( $tracking_number );
        }

        // Check duplicate.
        if ( FST_Shipment::get_by_tracking( $tracking_number ) ) {
            return new WP_REST_Response( array( 'error' => 'Tracking number already exists' ), 409 );
        }

        $shipment_id = FST_Shipment::create( array(
            'order_id'        => $order_id,
            'tracking_number' => $tracking_number,
            'carrier'         => $carrier,
        ) );

        if ( ! $shipment_id ) {
            return new WP_REST_Response( array( 'error' => 'Failed to create shipment' ), 500 );
        }

        return new WP_REST_Response( array(
            'id'      => $shipment_id,
            'carrier' => $carrier,
            'message' => 'Shipment created',
        ), 201 );
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
        if ( ! $order ) {
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

        if ( ! $order ) {
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
