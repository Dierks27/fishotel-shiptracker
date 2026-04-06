<?php
/**
 * UPS carrier implementation.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Carrier_UPS extends FST_Carrier {

    protected $slug = 'ups';
    protected $name = 'UPS';

    /**
     * API base URLs.
     */
    const PRODUCTION_URL = 'https://onlinetools.ups.com';
    const SANDBOX_URL    = 'https://wwwcie.ups.com';

    /**
     * Check if credentials are configured.
     */
    public function has_credentials() {
        return ! empty( get_option( 'fst_ups_client_id' ) ) && ! empty( get_option( 'fst_ups_client_secret' ) );
    }

    /**
     * Get the API base URL.
     */
    private function get_base_url() {
        return 'yes' === get_option( 'fst_ups_sandbox', 'no' ) ? self::SANDBOX_URL : self::PRODUCTION_URL;
    }

    /**
     * Get OAuth 2.0 access token (cached for 14400 seconds / 4 hours).
     *
     * @return string|WP_Error
     */
    public function get_access_token() {
        $cached = get_transient( 'fst_ups_access_token' );
        if ( $cached ) {
            return $cached;
        }

        $client_id     = get_option( 'fst_ups_client_id', '' );
        $client_secret = get_option( 'fst_ups_client_secret', '' );

        if ( empty( $client_id ) || empty( $client_secret ) ) {
            return new WP_Error( 'fst_ups_no_credentials', 'UPS API credentials not configured.' );
        }

        $response = wp_remote_post( $this->get_base_url() . '/security/v1/oauth/token', array(
            'headers' => array(
                'Content-Type'  => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
            ),
            'body' => array(
                'grant_type' => 'client_credentials',
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
            return new WP_Error( 'fst_ups_auth_failed', 'UPS authentication failed. Check your credentials.' );
        }

        $token  = $body['access_token'];
        $expiry = isset( $body['expires_in'] ) ? (int) $body['expires_in'] - 60 : 14340; // Expire 1 min early.

        set_transient( 'fst_ups_access_token', $token, $expiry );
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

        $url = $this->get_base_url() . '/api/track/v1/details/' . urlencode( $tracking_number );

        $response = wp_remote_get( $url, array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'transId'       => 'fst_' . uniqid(),
                'transactionSrc' => 'FisHotelShipTracker',
            ),
            'timeout' => 30,
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Track error for ' . $tracking_number . ': ' . $response->get_error_message() );
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code ) {
            $this->log( 'Track failed for ' . $tracking_number . ': HTTP ' . $code );
            return new WP_Error( 'fst_ups_track_failed', 'UPS tracking request failed (HTTP ' . $code . ').' );
        }

        return $this->parse_response( $body );
    }

    /**
     * Parse UPS tracking API response into our standard format.
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

        // Navigate to the shipment package data.
        $shipment = $body['trackResponse']['shipment'][0] ?? null;
        if ( ! $shipment ) {
            return $result;
        }

        $package = $shipment['package'][0] ?? null;
        if ( ! $package ) {
            // No package data — check warnings for details.
            $warnings = $shipment['warnings'] ?? array();
            if ( ! empty( $warnings ) ) {
                $warning_msgs = array();
                foreach ( $warnings as $w ) {
                    $warning_msgs[] = $w['message'] ?? ( $w['description'] ?? wp_json_encode( $w ) );
                }
                $result['status_detail'] = implode( '; ', $warning_msgs );
            }
            // If UPS accepted the tracking number but has no package data,
            // it likely means the label was created but not yet scanned.
            $result['status'] = 'label_created';
            return $result;
        }

        // Current status — UPS uses 'type' for the status letter code (D, I, P, M, X etc.)
        // and 'code' for a more specific code (e.g., "SR", "KB"). We map on 'type' first.
        $current_status = $package['currentStatus'] ?? array();
        $status_type    = $current_status['type'] ?? '';
        $status_code    = $current_status['code'] ?? '';
        $status_desc    = $current_status['description'] ?? '';

        $result['status']        = $this->map_status( $status_type, $status_code, $status_desc );
        $result['status_detail'] = $status_desc;

        // Estimated delivery.
        $delivery_date = $package['deliveryDate'][0]['date'] ?? '';
        if ( $delivery_date ) {
            // UPS format: YYYYMMDD
            $result['est_delivery'] = substr( $delivery_date, 0, 4 ) . '-' . substr( $delivery_date, 4, 2 ) . '-' . substr( $delivery_date, 6, 2 );
        }

        // Delivery time info.
        $delivery_time = $package['deliveryTime'] ?? array();
        if ( ! empty( $delivery_time['type'] ) && 'DEL' === $delivery_time['type'] ) {
            $result['delivered_date'] = $result['est_delivery'];
        }

        // Parse activity/events.
        $activities = $package['activity'] ?? array();
        foreach ( $activities as $activity ) {
            $event = array(
                'status'      => $this->map_status( $activity['status']['type'] ?? '', $activity['status']['code'] ?? '', $activity['status']['description'] ?? '' ),
                'description' => $activity['status']['description'] ?? '',
                'location'    => $this->format_location( $activity['location'] ?? array() ),
                'event_time'  => $this->parse_datetime( $activity['date'] ?? '', $activity['time'] ?? '' ),
                'raw'         => $activity,
            );

            // Check if this activity is the delivery event.
            if ( 'delivered' === $event['status'] && ! empty( $event['event_time'] ) ) {
                $result['delivered_date'] = $event['event_time'];
            }

            $result['events'][] = $event;
        }

        return $result;
    }

    /**
     * Map UPS status to internal status.
     *
     * UPS response has both 'type' (single letter like D, I, P, M, X) and
     * 'code' (more specific like SR, KB, OR). We check type first, then code.
     *
     * @param string $type        UPS status type (D, I, P, M, X, etc.).
     * @param string $code        UPS status code (SR, KB, OR, etc.).
     * @param string $description Status description for fallback matching.
     * @return string Internal status slug.
     */
    private function map_status( $type, $code = '', $description = '' ) {
        // Primary map: 'type' field — the main status category.
        $type_map = array(
            'D'  => 'delivered',
            'I'  => 'in_transit',
            'P'  => 'pre_transit',
            'M'  => 'label_created',
            'MV' => 'label_created',
            'X'  => 'exception',
            'RS' => 'return_to_sender',
            'DO' => 'out_for_delivery',
            'DD' => 'out_for_delivery',
            'O'  => 'out_for_delivery',
        );

        if ( ! empty( $type ) && isset( $type_map[ $type ] ) ) {
            return $type_map[ $type ];
        }

        // Secondary map: 'code' field — more specific status codes.
        $code_map = array(
            'SR' => 'in_transit',       // Shipment received by carrier.
            'OR' => 'label_created',    // Order processed / ready for UPS.
            'DP' => 'in_transit',       // Departure scan.
            'AR' => 'in_transit',       // Arrival scan.
            'KB' => 'label_created',    // Billing info received.
            'OT' => 'out_for_delivery', // Out for delivery.
            'DL' => 'delivered',        // Delivered.
            'DS' => 'delivered',        // Delivered (signed).
            'MP' => 'label_created',    // Manifest pickup.
        );

        if ( ! empty( $code ) && isset( $code_map[ $code ] ) ) {
            return $code_map[ $code ];
        }

        // Fallback: check description text.
        $desc_lower = strtolower( $description );
        if ( false !== strpos( $desc_lower, 'delivered' ) ) return 'delivered';
        if ( false !== strpos( $desc_lower, 'out for delivery' ) ) return 'out_for_delivery';
        if ( false !== strpos( $desc_lower, 'in transit' ) ) return 'in_transit';
        if ( false !== strpos( $desc_lower, 'picked up' ) ) return 'pre_transit';
        if ( false !== strpos( $desc_lower, 'pickup' ) ) return 'available_for_pickup';
        if ( false !== strpos( $desc_lower, 'exception' ) ) return 'exception';
        if ( false !== strpos( $desc_lower, 'return' ) ) return 'return_to_sender';
        if ( false !== strpos( $desc_lower, 'label' ) ) return 'label_created';
        if ( false !== strpos( $desc_lower, 'billing' ) ) return 'label_created';
        if ( false !== strpos( $desc_lower, 'origin scan' ) ) return 'in_transit';
        if ( false !== strpos( $desc_lower, 'departed' ) ) return 'in_transit';
        if ( false !== strpos( $desc_lower, 'arrived' ) ) return 'in_transit';

        return 'unknown';
    }

    /**
     * Format UPS location data.
     */
    private function format_location( $location ) {
        $address = $location['address'] ?? array();
        $parts = array();

        if ( ! empty( $address['city'] ) )              $parts[] = $address['city'];
        if ( ! empty( $address['stateProvince'] ) )     $parts[] = $address['stateProvince'];
        if ( ! empty( $address['countryCode'] ) && 'US' !== $address['countryCode'] ) $parts[] = $address['countryCode'];

        return implode( ', ', $parts );
    }

    /**
     * Parse UPS date and time into Y-m-d H:i:s format.
     */
    private function parse_datetime( $date, $time = '' ) {
        if ( empty( $date ) ) return '';

        // UPS date: YYYYMMDD, time: HHmmss
        $formatted = substr( $date, 0, 4 ) . '-' . substr( $date, 4, 2 ) . '-' . substr( $date, 6, 2 );

        if ( ! empty( $time ) && strlen( $time ) >= 4 ) {
            $formatted .= ' ' . substr( $time, 0, 2 ) . ':' . substr( $time, 2, 2 ) . ':' . substr( $time, 4, 2 );
        }

        return $formatted;
    }

    /**
     * Get public tracking URL.
     */
    public function get_tracking_url( $tracking_number ) {
        return 'https://www.ups.com/track?tracknum=' . urlencode( $tracking_number );
    }
}
