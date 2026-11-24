<?php
/**
 * Recipient address standardization for letters via the USPS Addresses API v3.
 *
 * Reuses the USPS OAuth2 client credentials already configured for package
 * tracking (Settings > Carriers > USPS) but requests its own token with the
 * "addresses" scope, cached separately, so the package tracking token and
 * calls are never touched.
 *
 * The result always includes a usable routing code:
 *   validated   → ZIP5 + ZIP4 + delivery point (11 digits)
 *   partial     → USPS answered but without a confirmed delivery point;
 *                 9 or 5 digit routing code
 *   unvalidated → API unavailable / no match; 5 (or 9) digits from the order
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Letter_Address {

    const API_BASE        = 'https://apis.usps.com';
    const TOKEN_TRANSIENT = 'fst_usps_addresses_token';

    /**
     * Pull the shipping address off an order (falls back to billing).
     *
     * @param WC_Order $order
     * @return array
     */
    public static function from_order( $order ) {
        $use_shipping = '' !== trim( (string) $order->get_shipping_address_1() );
        $get          = static function ( $field ) use ( $order, $use_shipping ) {
            $method = 'get_' . ( $use_shipping ? 'shipping' : 'billing' ) . '_' . $field;
            return is_callable( array( $order, $method ) ) ? trim( (string) $order->$method() ) : '';
        };

        $name = trim( $get( 'first_name' ) . ' ' . $get( 'last_name' ) );

        return array(
            'name'     => $name,
            'company'  => $get( 'company' ),
            'address1' => $get( 'address_1' ),
            'address2' => $get( 'address_2' ),
            'city'     => $get( 'city' ),
            'state'    => strtoupper( $get( 'state' ) ),
            'zip'      => $get( 'postcode' ),
            'country'  => strtoupper( $get( 'country' ) ),
        );
    }

    /**
     * Validate/standardize an address and derive the IMb routing code.
     *
     * @param array $address From from_order().
     * @return array {
     *     @type string $status       validated|partial|unvalidated
     *     @type string $routing_code 11, 9, 5 or 0 digits
     *     @type string $message      Human explanation (warnings for Jeff)
     *     @type array  $address      Address to print (standardized when available)
     * }
     */
    public static function validate( array $address ) {
        $fallback = self::fallback( $address );

        if ( ! empty( $address['country'] ) && 'US' !== $address['country'] ) {
            $fallback['message'] = 'Non-US address — IMb routing code left empty. IV-MTR tracking only covers domestic mail.';
            $fallback['routing_code'] = '';
            return $fallback;
        }

        if ( empty( $address['address1'] ) || empty( $address['state'] ) || ( empty( $address['city'] ) && empty( $address['zip'] ) ) ) {
            $fallback['message'] = 'Address is incomplete, so it was not sent to USPS for validation.';
            return $fallback;
        }

        $token = self::get_token();
        if ( is_wp_error( $token ) ) {
            $fallback['message'] = 'USPS address validation unavailable: ' . $token->get_error_message();
            return $fallback;
        }

        $zip5  = substr( preg_replace( '/\D/', '', $address['zip'] ), 0, 5 );
        $query = array_filter( array(
            'firm'             => $address['company'],
            'streetAddress'    => $address['address1'],
            'secondaryAddress' => $address['address2'],
            'city'             => $address['city'],
            'state'            => $address['state'],
            'ZIPCode'          => $zip5,
        ), 'strlen' );

        $response = wp_remote_get( add_query_arg( array_map( 'rawurlencode', $query ), self::API_BASE . '/addresses/v3/address' ), array(
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            $fallback['message'] = 'USPS address validation request failed: ' . $response->get_error_message();
            return $fallback;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        $body = json_decode( $raw, true );

        if ( 401 === $code ) {
            delete_transient( self::TOKEN_TRANSIENT );
        }

        if ( 200 !== $code || ! is_array( $body ) || empty( $body['address'] ) ) {
            $detail = is_array( $body ) && ! empty( $body['error']['message'] ) ? $body['error']['message'] : 'HTTP ' . $code;
            $fallback['message'] = 'USPS could not validate this address (' . $detail . ').';
            FST_Letters::log( 'Address API non-200: HTTP ' . $code . ' ' . substr( (string) $raw, 0, 500 ), 'warning' );
            return $fallback;
        }

        return self::parse_response( $body, $address );
    }

    /**
     * Turn an Addresses API v3 response into our result shape.
     * Public for unit tests.
     *
     * @param array $body     Decoded JSON.
     * @param array $original Original address (for name/company).
     * @return array
     */
    public static function parse_response( array $body, array $original ) {
        $a    = isset( $body['address'] ) && is_array( $body['address'] ) ? $body['address'] : array();
        $info = isset( $body['additionalInfo'] ) && is_array( $body['additionalInfo'] ) ? $body['additionalInfo'] : array();

        $zip5 = preg_replace( '/\D/', '', (string) ( $a['ZIPCode'] ?? '' ) );
        $zip4 = preg_replace( '/\D/', '', (string) ( $a['ZIPPlus4'] ?? '' ) );
        $dp   = preg_replace( '/\D/', '', (string) ( $info['deliveryPoint'] ?? '' ) );
        $dpv  = strtoupper( (string) ( $info['DPVConfirmation'] ?? '' ) );

        $printed = array(
            'name'     => $original['name'] ?? '',
            'company'  => ! empty( $body['firm'] ) ? $body['firm'] : ( $original['company'] ?? '' ),
            'address1' => $a['streetAddress'] ?? ( $original['address1'] ?? '' ),
            'address2' => $a['secondaryAddress'] ?? '',
            'city'     => $a['city'] ?? ( $original['city'] ?? '' ),
            'state'    => $a['state'] ?? ( $original['state'] ?? '' ),
            'zip'      => $zip5 . ( 4 === strlen( $zip4 ) ? '-' . $zip4 : '' ),
            'country'  => 'US',
        );

        $result = array(
            'status'       => 'partial',
            'routing_code' => '',
            'message'      => '',
            'address'      => $printed,
            'dpv'          => $dpv,
        );

        if ( 5 !== strlen( $zip5 ) ) {
            $fb              = self::fallback( $original );
            $fb['message']   = 'USPS response had no ZIP Code.';
            return $fb;
        }

        if ( 4 === strlen( $zip4 ) && 2 === strlen( $dp ) ) {
            $result['routing_code'] = $zip5 . $zip4 . $dp;
            if ( 'Y' === $dpv ) {
                $result['status']  = 'validated';
                $result['message'] = 'Address validated by USPS (DPV confirmed).';
            } else {
                $result['message'] = self::dpv_message( $dpv );
            }
        } elseif ( 4 === strlen( $zip4 ) ) {
            $result['routing_code'] = $zip5 . $zip4;
            $result['message']      = 'USPS returned ZIP+4 but no delivery point. ' . self::dpv_message( $dpv );
        } else {
            $result['routing_code'] = $zip5;
            $result['message']      = 'USPS returned no ZIP+4. ' . self::dpv_message( $dpv );
        }

        return $result;
    }

    private static function dpv_message( $dpv ) {
        switch ( $dpv ) {
            case 'Y':
                return 'Delivery point confirmed.';
            case 'D':
                return 'Building confirmed but the apartment/suite number is missing.';
            case 'S':
                return 'Building confirmed but the apartment/suite number could not be confirmed.';
            case 'N':
                return 'USPS could not confirm this is a deliverable address.';
            default:
                return 'Delivery point not confirmed.';
        }
    }

    /**
     * Unvalidated result built from whatever ZIP the order has.
     */
    private static function fallback( array $address ) {
        $digits  = preg_replace( '/\D/', '', (string) ( $address['zip'] ?? '' ) );
        $routing = '';
        if ( strlen( $digits ) >= 9 ) {
            $routing = substr( $digits, 0, 9 );
        } elseif ( strlen( $digits ) >= 5 ) {
            $routing = substr( $digits, 0, 5 );
        }

        return array(
            'status'       => 'unvalidated',
            'routing_code' => $routing,
            'message'      => '',
            'address'      => $address,
            'dpv'          => '',
        );
    }

    /**
     * OAuth2 token with the "addresses" scope (separate cache from tracking).
     *
     * @return string|WP_Error
     */
    public static function get_token() {
        $cached = get_transient( self::TOKEN_TRANSIENT );
        if ( $cached ) {
            return $cached;
        }

        $client_id     = get_option( 'fst_usps_client_id', '' );
        $client_secret = get_option( 'fst_usps_client_secret', '' );
        if ( empty( $client_id ) || empty( $client_secret ) ) {
            return new WP_Error( 'fst_usps_no_credentials', 'USPS API credentials are not configured (Settings > Carriers > USPS).' );
        }

        $response = wp_remote_post( self::API_BASE . '/oauth2/v3/token', array(
            'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
            'body'    => array(
                'grant_type'    => 'client_credentials',
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'scope'         => 'addresses',
            ),
            'timeout' => 20,
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 200 !== $code || empty( $body['access_token'] ) ) {
            FST_Letters::log( 'Addresses OAuth failed: HTTP ' . $code . ' ' . substr( wp_remote_retrieve_body( $response ), 0, 300 ), 'error' );
            return new WP_Error( 'fst_usps_addresses_auth', 'USPS authentication for the Addresses API failed (HTTP ' . $code . '). Make sure the app in the USPS developer portal has the Addresses API enabled.' );
        }

        $ttl = isset( $body['expires_in'] ) ? max( 60, (int) $body['expires_in'] - 60 ) : 3540;
        set_transient( self::TOKEN_TRANSIENT, $body['access_token'], $ttl );

        return $body['access_token'];
    }
}
