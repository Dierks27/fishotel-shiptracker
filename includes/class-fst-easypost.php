<?php
/**
 * EasyPost aggregator integration.
 *
 * Routes USPS tracking through EasyPost, which holds the commercial
 * arrangement that lets it track any USPS package regardless of the
 * Mailer ID embedded in the barcode (USPS API Access Control, April 2026).
 *
 * Unlike FST_Carrier subclasses (which poll), EasyPost is register-and-wait:
 * we create a tracker, then EasyPost pushes updates to our webhook. This
 * class therefore does NOT extend FST_Carrier — the shapes differ too much.
 *
 * All methods are static; the class holds no per-instance state.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_EasyPost {

    /**
     * EasyPost API base URL.
     */
    const API_BASE = 'https://api.easypost.com/v2';

    /**
     * Map our carrier slugs to EasyPost carrier slugs.
     *
     * @var array
     */
    const CARRIER_MAP = array(
        'usps'  => 'USPS',
        'ups'   => 'UPS',
        'fedex' => 'FedEx',
        'dhl'   => 'DHLExpress',
    );

    /**
     * Map EasyPost statuses to internal statuses.
     *
     * @var array
     */
    const STATUS_MAP = array(
        'unknown'              => 'unknown',
        'pre_transit'          => 'pre_transit',
        'in_transit'           => 'in_transit',
        'out_for_delivery'     => 'out_for_delivery',
        'delivered'            => 'delivered',
        'available_for_pickup' => 'available_for_pickup',
        'return_to_sender'     => 'return_to_sender',
        'failure'              => 'failure',
        'cancelled'            => 'failure',
        'error'                => 'exception',
    );

    /**
     * Whether test mode is enabled.
     *
     * @return bool
     */
    public static function is_test_mode() {
        return 'yes' === get_option( 'fst_easypost_test_mode', 'no' );
    }

    /**
     * Get the API key for the active mode (test or production).
     *
     * @return string
     */
    public static function get_api_key() {
        if ( self::is_test_mode() ) {
            return (string) get_option( 'fst_easypost_test_key', '' );
        }
        return (string) get_option( 'fst_easypost_production_key', '' );
    }

    /**
     * Whether the active-mode API key is configured.
     *
     * @return bool
     */
    public static function has_credentials() {
        return '' !== trim( self::get_api_key() );
    }

    /**
     * Get the configured webhook secret.
     *
     * @return string
     */
    public static function get_webhook_secret() {
        return (string) get_option( 'fst_easypost_webhook_secret', '' );
    }

    /**
     * Test the API connection (no side effects, requires auth).
     *
     * @return true|WP_Error
     */
    public static function test_connection() {
        $result = self::request( 'GET', '/users/retrieve_me' );
        if ( is_wp_error( $result ) ) {
            return $result;
        }
        return true;
    }

    /**
     * Create a tracker.
     *
     * @param string $tracking_number
     * @param string $carrier_slug    Our carrier slug (e.g. 'usps').
     * @return array|WP_Error Tracker array or error.
     */
    public static function create_tracker( $tracking_number, $carrier_slug ) {
        $tracker = array(
            'tracking_code' => $tracking_number,
        );

        $ep_carrier = self::map_carrier( $carrier_slug );
        if ( '' !== $ep_carrier ) {
            $tracker['carrier'] = $ep_carrier;
        }

        return self::request( 'POST', '/trackers', array( 'tracker' => $tracker ) );
    }

    /**
     * Retrieve a tracker by EasyPost tracker ID.
     *
     * @param string $tracker_id
     * @return array|WP_Error
     */
    public static function get_tracker( $tracker_id ) {
        if ( empty( $tracker_id ) ) {
            return new WP_Error( 'fst_easypost_no_tracker_id', 'EasyPost tracker ID is required.' );
        }
        return self::request( 'GET', '/trackers/' . rawurlencode( $tracker_id ) );
    }

    /**
     * Verify the HMAC-SHA256 signature on an incoming webhook.
     *
     * EasyPost signs each webhook with HMAC-SHA256 over the raw request body,
     * keyed with the configured webhook secret. The signature arrives in the
     * X-Hmac-Signature header as a lowercase hex digest prefixed with
     * "hmac-sha256-hex=".
     *
     * @param string $raw_body         Raw request body.
     * @param string $signature_header Value of the X-Hmac-Signature header.
     * @return bool
     */
    public static function verify_signature( $raw_body, $signature_header ) {
        $secret = self::get_webhook_secret();

        // Never accept unsigned/unverifiable webhooks — that would be a
        // remote-control hole. No secret configured means reject.
        if ( '' === trim( $secret ) ) {
            self::log( 'Webhook rejected: no webhook secret configured.' );
            return false;
        }

        if ( empty( $signature_header ) ) {
            return false;
        }

        // Trim first so any leading/trailing header whitespace doesn't defeat
        // the prefix match, then strip the algorithm prefix if present.
        $sig    = trim( (string) $signature_header );
        $prefix = 'hmac-sha256-hex=';
        if ( 0 === strpos( $sig, $prefix ) ) {
            $sig = substr( $sig, strlen( $prefix ) );
        }

        $sig = strtolower( trim( $sig ) );

        // NFKC-normalize the raw body when the intl extension is available,
        // otherwise fall back to the raw body as-is.
        $body = $raw_body;
        if ( class_exists( 'Normalizer' ) ) {
            $normalized = Normalizer::normalize( $raw_body, Normalizer::FORM_KC );
            if ( is_string( $normalized ) && '' !== $normalized ) {
                $body = $normalized;
            }
        }

        $computed = hash_hmac( 'sha256', $body, $secret );

        return hash_equals( $computed, $sig );
    }

    /**
     * Handle a decoded webhook payload.
     *
     * Processes only tracker.created and tracker.updated events; any other
     * event description is acknowledged (returns true) without action.
     *
     * @param array $decoded Decoded webhook JSON.
     * @return true|WP_Error
     */
    public static function handle_webhook( $decoded ) {
        $description = isset( $decoded['description'] ) ? $decoded['description'] : '';

        if ( ! in_array( $description, array( 'tracker.created', 'tracker.updated' ), true ) ) {
            self::log( 'Ignoring webhook event: ' . $description );
            return true;
        }

        $tracker = ( isset( $decoded['result'] ) && is_array( $decoded['result'] ) ) ? $decoded['result'] : array();
        if ( empty( $tracker ) ) {
            return new WP_Error( 'fst_easypost_no_result', 'Webhook payload is missing the result object.' );
        }

        $tracker_id    = isset( $tracker['id'] ) ? $tracker['id'] : '';
        $tracking_code = isset( $tracker['tracking_code'] ) ? $tracker['tracking_code'] : '';

        // Find EVERY shipment row for this tracker, falling back to tracking
        // number (+ carrier when the payload's carrier maps to one of ours).
        // WHY (shiptracker-2): fulfillment fan-out creates one row per source
        // order, all sharing the tracking number / tracker id, and the cron
        // poll skips EasyPost rows — so a single-row lookup left every sibling
        // stale forever (no status, no emails, no auto-complete).
        $shipments = array(); // keyed by row id so the two lookups de-dupe
        if ( $tracker_id ) {
            foreach ( FST_Shipment::get_all_by_easypost_tracker( $tracker_id ) as $row ) {
                $shipments[ (int) $row->id ] = $row;
            }
        }
        if ( $tracking_code ) {
            // WHY (shiptracker-2 polish): ALWAYS union in the tracking-number
            // siblings that are not bound to any tracker yet — not only when the
            // tracker-id lookup came back empty. A fan-out sibling whose own
            // create_tracker() failed (timeout / 5xx, or a backfill error) keeps
            // an empty easypost_tracker_id; once any other sibling was bound,
            // the "fallback only on zero rows" rule left that row stale forever
            // (cron only self-heals it on a direct USPS 403, which never fires
            // on an EasyPost-only install with no USPS credentials). The loop
            // below stamps the tracker id on it so later webhooks resolve it
            // directly. With no tracker-id match at all, the original full
            // tracking-number fallback still applies.
            $carrier_slug = isset( $tracker['carrier'] ) ? self::unmap_carrier( $tracker['carrier'] ) : '';
            $bound_any    = ! empty( $shipments );
            foreach ( FST_Shipment::get_all_by_tracking( $tracking_code, $carrier_slug ) as $row ) {
                if ( ! $bound_any || '' === (string) $row->easypost_tracker_id ) {
                    $shipments[ (int) $row->id ] = $row;
                }
            }
        }

        if ( empty( $shipments ) ) {
            self::log( sprintf( 'Webhook: no shipment for tracker %s / code %s', $tracker_id, $tracking_code ) );
            return new WP_Error( 'fst_easypost_no_shipment', 'No matching shipment found for this tracker.' );
        }

        // One admin email per webhook, not one per sibling row: the admin
        // cares that tracking X reached status Y, not how many source orders
        // share the box. Customer emails stay per row (different customers,
        // and the theme's consolidation filter already de-dupes those).
        // Priority 999 so earlier filters' decisions are respected.
        $admin_sent  = false;
        $admin_guard = static function ( $send ) use ( &$admin_sent ) {
            if ( ! $send ) {
                return $send;
            }
            if ( $admin_sent ) {
                return false;
            }
            $admin_sent = true;
            return true;
        };
        add_filter( 'fst_send_admin_email', $admin_guard, 999 );

        $result = true;
        foreach ( $shipments as $shipment ) {
            // If we matched by tracking number, backfill the tracker id so future
            // webhooks resolve directly.
            if ( empty( $shipment->easypost_tracker_id ) && $tracker_id ) {
                FST_Shipment::update( $shipment->id, array(
                    'easypost_tracker_id' => $tracker_id,
                    'tracker_provider'    => 'easypost',
                ) );
                $shipment->easypost_tracker_id = $tracker_id;
                $shipment->tracker_provider    = 'easypost';
            }

            $applied = self::apply_tracker_to_shipment( $shipment, $tracker );
            if ( is_wp_error( $applied ) ) {
                $result = $applied;
            }
        }

        remove_filter( 'fst_send_admin_email', $admin_guard, 999 );

        return $result;
    }

    /**
     * Apply an EasyPost tracker payload to a shipment.
     *
     * Translates the payload into a shipment update, persists new events
     * (deduped by event_time), and fires status-change side effects (emails,
     * order status transitions, hooks) when the status actually changes.
     *
     * @param object $shipment Shipment row (stdClass).
     * @param array  $tracker  EasyPost tracker array.
     * @return true|WP_Error
     */
    public static function apply_tracker_to_shipment( $shipment, $tracker ) {
        if ( empty( $tracker ) || ! is_array( $tracker ) ) {
            return new WP_Error( 'fst_easypost_empty_tracker', 'Empty EasyPost tracker payload.' );
        }

        $old_status = $shipment->status;
        $new_status = self::map_status( isset( $tracker['status'] ) ? $tracker['status'] : '' );

        // Build events from tracking_details.
        $events            = self::parse_tracking_details( $tracker );
        $latest_event_time = '';
        foreach ( $events as $event ) {
            if ( ! empty( $event['event_time'] ) && $event['event_time'] > $latest_event_time ) {
                $latest_event_time = $event['event_time'];
            }
        }

        // Regression guards. When one trips we still bump last_checked /
        // check_count and persist any events we don't have yet, but leave
        // status, status_detail, tracking_data and last_event_at alone and
        // fire no side effects (no emails, no order transitions).
        $skip_reason = '';
        $payload_ts  = '' !== $latest_event_time ? strtotime( $latest_event_time ) : false;
        $stored_ts   = ! empty( $shipment->last_event_at ) ? strtotime( $shipment->last_event_at ) : false;

        if ( 'unknown' === $new_status && 'unknown' !== $old_status ) {
            // WHY (shiptracker-9): a freshly created tracker (promotion /
            // backfill / registration) reports 'unknown' until EasyPost has
            // fetched carrier data. Applying it flipped an in-flight row back
            // to Unknown, and the next real update then re-fired the status
            // change — and the "your order has shipped" email — a second time.
            $skip_reason = 'payload status is unknown but row already has a status (' . $old_status . ')';
        } elseif ( in_array( $old_status, FST_Carrier::TERMINAL_STATUSES, true )
            && ! in_array( $new_status, FST_Carrier::TERMINAL_STATUSES, true ) ) {
            // WHY (shiptracker-4): a terminal status never moves backwards. A
            // retried or replayed webhook carrying an older state must not undo
            // delivered / return_to_sender / failure or re-send those emails.
            $skip_reason = 'row is terminal (' . $old_status . ') and payload is not (' . $new_status . ')';
        } elseif ( $payload_ts && $stored_ts && $payload_ts < $stored_ts ) {
            // WHY (shiptracker-4): EasyPost retries failed deliveries and can
            // deliver webhooks out of order. A payload whose newest event is
            // older than what we already stored is stale: applying it would
            // regress the status and re-send the earlier status email.
            $skip_reason = 'payload newest event ' . $latest_event_time . ' is older than stored ' . $shipment->last_event_at;
        }

        if ( '' !== $skip_reason ) {
            self::log( sprintf( 'Ignoring EasyPost payload for %s (row %d): %s', $shipment->tracking_number, $shipment->id, $skip_reason ) );
            FST_Shipment::update( $shipment->id, array(
                'last_checked' => current_time( 'mysql' ),
                'check_count'  => (int) $shipment->check_count + 1,
            ) );
            self::persist_events( $shipment->id, $events );
            return true;
        }

        $update = array(
            'status'        => $new_status,
            'status_detail' => isset( $tracker['status_detail'] ) ? sanitize_text_field( (string) $tracker['status_detail'] ) : '',
            'last_checked'  => current_time( 'mysql' ),
            'check_count'   => (int) $shipment->check_count + 1,
            'tracking_data' => wp_json_encode( $tracker ),
        );

        // Estimated delivery date.
        if ( ! empty( $tracker['est_delivery_date'] ) ) {
            $dt = date_create( $tracker['est_delivery_date'] );
            if ( $dt ) {
                $update['est_delivery'] = $dt->format( 'Y-m-d' );
            }
        }

        if ( '' !== $latest_event_time ) {
            $update['last_event_at'] = $latest_event_time;
        }

        // When delivered, record the delivery date from the latest event.
        if ( 'delivered' === $new_status && '' !== $latest_event_time ) {
            $update['delivered_date'] = $latest_event_time;
        }

        FST_Shipment::update( $shipment->id, $update );

        self::persist_events( $shipment->id, $events );

        // Fire status-change side effects only when the status actually changed.
        if ( $old_status !== $new_status ) {
            self::log( sprintf( 'Status changed via EasyPost: %s -> %s (%s)', $old_status, $new_status, $shipment->tracking_number ) );

            // Reuse the plugin's tracker instance rather than constructing a new
            // one — apply_tracker_to_shipment can run inside the cron poll loop,
            // and a fresh FST_Tracker would re-register its action hooks.
            $engine = ( function_exists( 'FST' ) && FST()->tracker instanceof FST_Tracker ) ? FST()->tracker : new FST_Tracker();
            $engine->process_status_change_external( $shipment, $old_status, $new_status );
        }

        return true;
    }

    /**
     * Register every in-flight USPS shipment that isn't already on EasyPost.
     *
     * @return array { registered, skipped, errors, error_details }
     */
    public static function backfill_active_shipments() {
        $result = array(
            'registered'    => 0,
            'skipped'       => 0,
            'errors'        => 0,
            'error_details' => array(),
        );

        if ( ! self::has_credentials() ) {
            return $result;
        }

        $shipments = FST_Shipment::get_active_for_easypost_backfill();

        foreach ( $shipments as $shipment ) {
            // Defensive: skip anything that already has a tracker.
            if ( ! empty( $shipment->easypost_tracker_id ) ) {
                $result['skipped']++;
                continue;
            }

            $tracker = self::create_tracker( $shipment->tracking_number, $shipment->carrier );
            if ( is_wp_error( $tracker ) ) {
                $result['errors']++;
                $result['error_details'][] = $shipment->tracking_number . ': ' . $tracker->get_error_message();
                continue;
            }

            $tracker_id = isset( $tracker['id'] ) ? $tracker['id'] : '';

            FST_Shipment::update( $shipment->id, array(
                'easypost_tracker_id' => $tracker_id,
                'tracker_provider'    => 'easypost',
            ) );
            $shipment->easypost_tracker_id = $tracker_id;
            $shipment->tracker_provider    = 'easypost';

            self::apply_tracker_to_shipment( $shipment, $tracker );
            $result['registered']++;
        }

        self::log( sprintf(
            'Backfill complete: %d registered, %d skipped, %d errors',
            $result['registered'],
            $result['skipped'],
            $result['errors']
        ) );

        return $result;
    }

    /**
     * Persist the events from a payload that we don't already have (deduped by event_time).
     *
     * @param int   $shipment_id
     * @param array $events Parsed events from parse_tracking_details().
     */
    private static function persist_events( $shipment_id, $events ) {
        if ( empty( $events ) ) {
            return;
        }

        $existing_times = array();
        foreach ( FST_Shipment::get_events( $shipment_id ) as $ev ) {
            $existing_times[] = $ev->event_time;
        }

        foreach ( $events as $event ) {
            if ( ! empty( $event['event_time'] ) && ! in_array( $event['event_time'], $existing_times, true ) ) {
                FST_Shipment::add_event( array(
                    'shipment_id' => $shipment_id,
                    'status'      => $event['status'],
                    'description' => $event['description'],
                    'location'    => $event['location'],
                    'event_time'  => $event['event_time'],
                    'raw_data'    => wp_json_encode( $event['raw'] ),
                ) );
                $existing_times[] = $event['event_time'];
            }
        }
    }

    /**
     * Parse an EasyPost tracker's tracking_details into our event shape.
     *
     * @param array $tracker
     * @return array
     */
    private static function parse_tracking_details( $tracker ) {
        $details = ( isset( $tracker['tracking_details'] ) && is_array( $tracker['tracking_details'] ) )
            ? $tracker['tracking_details']
            : array();

        $events = array();

        foreach ( $details as $detail ) {
            if ( ! is_array( $detail ) ) {
                continue;
            }

            $event_time = '';
            if ( ! empty( $detail['datetime'] ) ) {
                $dt = date_create( $detail['datetime'] );
                if ( $dt ) {
                    $event_time = $dt->format( 'Y-m-d H:i:s' );
                }
            }

            $events[] = array(
                'status'      => self::map_status( isset( $detail['status'] ) ? $detail['status'] : '' ),
                'description' => isset( $detail['message'] ) ? sanitize_text_field( (string) $detail['message'] ) : '',
                'location'    => self::format_location( isset( $detail['tracking_location'] ) ? $detail['tracking_location'] : array() ),
                'event_time'  => $event_time,
                'raw'         => $detail,
            );
        }

        return $events;
    }

    /**
     * Format an EasyPost tracking_location into "City, State" (country dropped if US).
     *
     * @param array $location
     * @return string
     */
    private static function format_location( $location ) {
        if ( ! is_array( $location ) ) {
            return '';
        }

        $parts = array();
        if ( ! empty( $location['city'] ) ) {
            $parts[] = $location['city'];
        }
        if ( ! empty( $location['state'] ) ) {
            $parts[] = $location['state'];
        }
        if ( ! empty( $location['country'] ) && 'US' !== strtoupper( $location['country'] ) ) {
            $parts[] = $location['country'];
        }

        return sanitize_text_field( implode( ', ', $parts ) );
    }

    /**
     * Map an EasyPost status to an internal status.
     *
     * @param string $ep_status
     * @return string
     */
    private static function map_status( $ep_status ) {
        $ep_status = strtolower( trim( (string) $ep_status ) );
        return isset( self::STATUS_MAP[ $ep_status ] ) ? self::STATUS_MAP[ $ep_status ] : 'unknown';
    }

    /**
     * Map an internal carrier slug to an EasyPost carrier slug.
     *
     * @param string $slug
     * @return string EasyPost slug, or '' if unmapped.
     */
    private static function map_carrier( $slug ) {
        $slug = strtolower( trim( (string) $slug ) );
        return isset( self::CARRIER_MAP[ $slug ] ) ? self::CARRIER_MAP[ $slug ] : '';
    }

    /**
     * Map an EasyPost carrier slug back to our internal slug.
     *
     * @param string $ep_carrier EasyPost carrier (e.g. 'USPS').
     * @return string Internal slug, or '' if unmapped.
     */
    private static function unmap_carrier( $ep_carrier ) {
        $needle = strtolower( trim( (string) $ep_carrier ) );
        $slug   = array_search( $needle, array_map( 'strtolower', self::CARRIER_MAP ), true );
        return is_string( $slug ) ? $slug : '';
    }

    /**
     * Make an authenticated request to the EasyPost API.
     *
     * @param string     $method HTTP method.
     * @param string     $path   Path relative to API_BASE (leading slash).
     * @param array|null $body   Request body (encoded as JSON) or null.
     * @return array|WP_Error Decoded response array or error.
     */
    private static function request( $method, $path, $body = null ) {
        $api_key = self::get_api_key();
        if ( '' === trim( $api_key ) ) {
            return new WP_Error( 'fst_easypost_no_key', 'EasyPost API key not configured.' );
        }

        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode( $api_key . ':' ),
                'Content-Type'  => 'application/json',
                'User-Agent'    => 'FisHotel-ShipTracker/' . FST_VERSION,
            ),
        );

        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( self::API_BASE . $path, $args );

        if ( is_wp_error( $response ) ) {
            self::log( sprintf( '%s %s error: %s', $method, $path, $response->get_error_message() ) );
            return $response;
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $raw      = wp_remote_retrieve_body( $response );
        $decoded = json_decode( $raw, true );

        if ( $code < 200 || $code >= 300 ) {
            $message = 'EasyPost API error (HTTP ' . $code . ')';
            if ( is_array( $decoded ) && ! empty( $decoded['error'] ) ) {
                $err = $decoded['error'];
                if ( is_array( $err ) && ! empty( $err['message'] ) ) {
                    $err_msg  = is_array( $err['message'] ) ? implode( '; ', $err['message'] ) : $err['message'];
                    $message .= ': ' . $err_msg;
                } elseif ( is_string( $err ) ) {
                    $message .= ': ' . $err;
                }
            }
            self::log( sprintf( '%s %s failed: %s — %s', $method, $path, $message, $raw ) );
            return new WP_Error( 'fst_easypost_http_error', $message );
        }

        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Log a debug message (gated on the debug-logging option).
     *
     * @param string $message
     */
    private static function log( $message ) {
        if ( 'yes' === get_option( 'fst_debug_logging', 'no' ) ) {
            $log_file  = WP_CONTENT_DIR . '/fst-debug.log';
            $timestamp = current_time( 'Y-m-d H:i:s' );
            file_put_contents( $log_file, "[{$timestamp}] [easypost] {$message}\n", FILE_APPEND );
        }
    }
}
