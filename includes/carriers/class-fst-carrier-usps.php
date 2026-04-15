<?php
/**
 * USPS carrier implementation (new API v3, post-Jan 2026).
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Carrier_USPS extends FST_Carrier {

    protected $slug = 'usps';
    protected $name = 'USPS';

    const API_BASE = 'https://api.usps.com';

    /**
     * Check if credentials are configured.
     */
    public function has_credentials() {
        return ! empty( get_option( 'fst_usps_client_id' ) ) && ! empty( get_option( 'fst_usps_client_secret' ) );
    }

    /**
     * Get OAuth 2.0 access token (cached).
     *
     * @return string|WP_Error
     */
    public function get_access_token() {
        $cached = get_transient( 'fst_usps_access_token' );
        if ( $cached ) {
            return $cached;
        }

        $client_id     = get_option( 'fst_usps_client_id', '' );
        $client_secret = get_option( 'fst_usps_client_secret', '' );

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            return new WP_Error( 'fst_usps_no_credentials', 'USPS API credentials not configured.' );
        }

        $response = wp_remote_post( self::API_BASE . '/oauth2/v3/token', array(
            'headers' => array(
                'Content-Type' => 'application/x-www-form-urlencoded',
            ),
            'body' => array(
                'grant_type'    => 'client_credentials',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'scope'         => 'tracking',
            ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log( 'OAuth error: ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code || empty( $body['access_token'] ) ) {
            $this->log( 'OAuth failed: HTTP ' . $code . ' - ' . wp_remote_retrieve_body( $response ) );
            return new WP_Error( 'fst_usps_auth_failed', 'USPS authentication failed. Check your credentials.' );
        }

        $token  = $body['access_token'];
        $expiry = isset( $body['expires_in'] ) ? (int) $body['expires_in'] - 60 : 3540;

        set_transient( 'fst_usps_access_token', $token, $expiry );
        $this->log( 'OAuth token obtained, expires in ' . $expiry . 's' );

        return $token;
    }

    /**
     * Track a shipment.
     *
     * @param string $tracking_number
     * @return array|WP_Error
     */
    public function track( $tracking_number ) {
        $token = $this->get_access_token();
        if ( is_wp_error( $token ) ) {
            return $token;
        }

        $url = self::API_BASE . '/tracking/v3/tracking/' . urlencode( $tracking_number ) . '?expand=DETAIL';

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Track error for ' . $tracking_number . ': ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );

        // Handle rate limiting.
        if ( 429 === $code ) {
            $this->log( 'Rate limited for ' . $tracking_number );
            return new WP_Error( 'fst_usps_rate_limited', 'USPS rate limit reached. Will retry next cycle.' );
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code ) {
            $this->log( 'Track failed for ' . $tracking_number . ': HTTP ' . $code );
            return new WP_Error( 'fst_usps_track_failed', 'USPS tracking request failed (HTTP ' . $code . ').' );
        }

        return $this->parse_response( $body );
    }

    /**
     * Parse USPS tracking API response.
     *
     * @param array $body Raw API response.
     * @return array
     */
    private function parse_response( $body ) {
        $result = array(
            'status'         => 'unknown',
            'status_detail'  => '',
            'est_delivery'   => '',
            'delivered_date' => '',
            'events'         => array(),
            'raw'            => $body,
        );

        $tracking = $body['trackingNumber'] ?? $body['tracking'] ?? $body;

        // Current status.
        $status_category = $tracking['statusCategory'] ?? '';
        $status_summary  = $tracking['statusSummary'] ?? $tracking['status'] ?? '';

        $result['status']        = $this->map_status( $status_category, $status_summary );
        $result['status_detail'] = $status_summary;

        // Expected delivery.
        $expected = $tracking['expectedDeliveryDate'] ?? $tracking['expectedDelivery'] ?? '';
        if ( $expected ) {
            // Try parsing various formats.
            $dt = date_create( $expected );
            if ( $dt ) {
                $result['est_delivery'] = $dt->format( 'Y-m-d' );
            }
        }

        // Parse tracking events.
        $events = $tracking['trackingEvents'] ?? $tracking['trackSummary'] ?? array();
        if ( ! is_array( $events ) ) {
            $events = array();
        }

        foreach ( $events as $event_data ) {
            $event_status = $this->map_status(
                $event_data['eventType'] ?? '',
                $event_data['eventDescription'] ?? $event_data['event'] ?? ''
            );

            $location_parts = array();
            if ( ! empty( $event_data['eventCity'] ) )  $location_parts[] = $event_data['eventCity'];
            if ( ! empty( $event_data['eventState'] ) ) $location_parts[] = $event_data['eventState'];

            $event_time = '';
            if ( ! empty( $event_data['eventTimestamp'] ) ) {
                $dt = date_create( $event_data['eventTimestamp'] );
                if ( $dt ) $event_time = $dt->format( 'Y-m-d H:i:s' );
            } elseif ( ! empty( $event_data['eventDate'] ) ) {
                $dt = date_create( $event_data['eventDate'] );
                if ( $dt ) $event_time = $dt->format( 'Y-m-d H:i:s' );
            }

            $event = array(
                'status'      => $event_status,
                'description' => $event_data['eventDescription'] ?? $event_data['event'] ?? '',
                'location'    => implode( ', ', $location_parts ),
                'event_time'  => $event_time,
                'raw'         => $event_data,
            );

            if ( 'delivered' === $event_status && ! empty( $event_time ) ) {
                $result['delivered_date'] = $event_time;
            }

            $result['events'][] = $event;
        }

        return $result;
    }

    /**
     * Map USPS status to internal status.
     */
    private function map_status( $category, $description = '' ) {
        $cat_lower  = strtolower( $category );
        $desc_lower = strtolower( $description );

        // Category-based mapping.
        $cat_map = array(
            'delivered'            => 'delivered',
            'in transit'           => 'in_transit',
            'in_transit'           => 'in_transit',
            'out for delivery'     => 'out_for_delivery',
            'out_for_delivery'     => 'out_for_delivery',
            'pre-shipment'         => 'shipped',
            'pre_shipment'         => 'shipped',
            'accepted'             => 'pre_transit',
            'alert'                => 'exception',
            'exception'            => 'exception',
            'available for pickup' => 'available_for_pickup',
            'return to sender'     => 'return_to_sender',
            'returned'             => 'return_to_sender',
            'undeliverable'        => 'failure',
        );

        foreach ( $cat_map as $key => $status ) {
            if ( false !== strpos( $cat_lower, $key ) || false !== strpos( $desc_lower, $key ) ) {
                return $status;
            }
        }

        return 'unknown';
    }

    /**
     * Get public tracking URL.
     */
    public function get_tracking_url( $tracking_number ) {
        return 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . urlencode( $tracking_number );
    }
}
