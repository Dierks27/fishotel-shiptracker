<?php
/**
 * Abstract carrier class — interface for UPS and USPS.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

abstract class FST_Carrier {

    /**
     * Carrier slug (e.g., 'ups', 'usps').
     * @var string
     */
    protected $slug = '';

    /**
     * Carrier display name.
     * @var string
     */
    protected $name = '';

    /**
     * Map of internal statuses.
     */
    const STATUSES = array(
        'unknown',
        'label_created',
        'shipped',
        'pre_transit',
        'in_transit',
        'out_for_delivery',
        'delivered',
        'exception',
        'available_for_pickup',
        'return_to_sender',
        'failure',
    );

    /**
     * Terminal statuses — polling stops when a shipment reaches one of these.
     */
    const TERMINAL_STATUSES = array( 'delivered', 'failure', 'return_to_sender' );

    /**
     * Get the carrier slug.
     */
    public function get_slug() {
        return $this->slug;
    }

    /**
     * Get the carrier display name.
     */
    public function get_name() {
        return $this->name;
    }

    /**
     * Check if API credentials are configured.
     *
     * @return bool
     */
    abstract public function has_credentials();

    /**
     * Get an OAuth access token (cached in transient).
     *
     * @return string|WP_Error
     */
    abstract public function get_access_token();

    /**
     * Track a shipment by tracking number.
     *
     * @param string $tracking_number
     * @return array|WP_Error {
     *     @type string $status          Internal status slug.
     *     @type string $status_detail   Human-readable status description.
     *     @type string $est_delivery    Estimated delivery date (Y-m-d) or empty.
     *     @type string $delivered_date  Actual delivery date (Y-m-d H:i:s) or empty.
     *     @type array  $events          Array of tracking events.
     *     @type array  $raw             Raw API response data.
     * }
     */
    abstract public function track( $tracking_number );

    /**
     * Get the public tracking URL for a tracking number.
     *
     * @param string $tracking_number
     * @return string
     */
    abstract public function get_tracking_url( $tracking_number );

    /**
     * Auto-detect carrier from tracking number format.
     *
     * @param string $tracking_number
     * @return string 'ups', 'usps', or default carrier.
     */
    public static function detect_carrier( $tracking_number ) {
        $tracking_number = strtoupper( trim( $tracking_number ) );

        // UPS: starts with 1Z, 18 characters.
        if ( preg_match( '/^1Z[A-Z0-9]{16}$/i', $tracking_number ) ) {
            return 'ups';
        }

        // UPS: Mail Innovations (starts with MI).
        if ( preg_match( '/^MI\d{6}\d{16,22}$/', $tracking_number ) ) {
            return 'ups';
        }

        // USPS: 20-22 digit numeric.
        if ( preg_match( '/^\d{20,22}$/', $tracking_number ) ) {
            return 'usps';
        }

        // USPS: specific prefixes.
        if ( preg_match( '/^(9400|9205|9208|9202|9303|9270|9274|9346|9114|9261|9407)/', $tracking_number ) ) {
            return 'usps';
        }

        // USPS: 13-char international format (starts with 2 letters, ends with US).
        if ( preg_match( '/^[A-Z]{2}\d{9}US$/i', $tracking_number ) ) {
            return 'usps';
        }

        // Default to configured default carrier.
        return get_option( 'fst_default_carrier', 'ups' );
    }

    /**
     * Get the human-readable label for a status.
     *
     * @param string $status
     * @return string
     */
    public static function get_status_label( $status ) {
        $labels = array(
            'unknown'              => __( 'Unknown', 'fishotel-shiptracker' ),
            'label_created'        => __( 'Label Created', 'fishotel-shiptracker' ),
            'shipped'              => __( 'Shipped', 'fishotel-shiptracker' ),
            'pre_transit'          => __( 'Pre-Transit', 'fishotel-shiptracker' ),
            'in_transit'           => __( 'In Transit', 'fishotel-shiptracker' ),
            'out_for_delivery'     => __( 'Out for Delivery', 'fishotel-shiptracker' ),
            'delivered'            => __( 'Delivered', 'fishotel-shiptracker' ),
            'exception'            => __( 'Exception', 'fishotel-shiptracker' ),
            'available_for_pickup' => __( 'Available for Pickup', 'fishotel-shiptracker' ),
            'return_to_sender'     => __( 'Return to Sender', 'fishotel-shiptracker' ),
            'failure'              => __( 'Delivery Failed', 'fishotel-shiptracker' ),
        );

        return isset( $labels[ $status ] ) ? $labels[ $status ] : ucwords( str_replace( '_', ' ', $status ) );
    }

    /**
     * Get the CSS color class for a status.
     *
     * @param string $status
     * @return string
     */
    public static function get_status_color( $status ) {
        $colors = array(
            'unknown'              => '#999999',
            'label_created'        => '#607d8b',
            'shipped'              => '#1976d2',
            'pre_transit'          => '#ff9800',
            'in_transit'           => '#2196f3',
            'out_for_delivery'     => '#4caf50',
            'delivered'            => '#2e7d32',
            'exception'            => '#f44336',
            'available_for_pickup' => '#9c27b0',
            'return_to_sender'     => '#e65100',
            'failure'              => '#c62828',
        );

        return isset( $colors[ $status ] ) ? $colors[ $status ] : '#999999';
    }

    /**
     * Log a debug message.
     *
     * @param string $message
     */
    protected function log( $message ) {
        if ( 'yes' === get_option( 'fst_debug_logging', 'no' ) ) {
            $log_file = WP_CONTENT_DIR . '/fst-debug.log';
            $timestamp = current_time( 'Y-m-d H:i:s' );
            file_put_contents( $log_file, "[{$timestamp}] [{$this->slug}] {$message}\n", FILE_APPEND );
        }
    }
}
