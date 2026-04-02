<?php
/**
 * Core tracking engine — polls carriers and processes status changes.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Tracker {

    public function __construct() {
        add_action( 'fst_poll_shipments', array( $this, 'poll_all_shipments' ) );
    }

    /**
     * Poll all shipments due for checking.
     */
    public function poll_all_shipments() {
        $this->log( 'Starting poll cycle' );

        $shipments = FST_Shipment::get_due_for_poll();

        if ( empty( $shipments ) ) {
            $this->log( 'No shipments due for polling' );
            return;
        }

        $this->log( sprintf( 'Found %d shipments to poll', count( $shipments ) ) );

        $usps_count = 0;

        foreach ( $shipments as $shipment ) {
            // USPS 50-call cap per run.
            if ( 'usps' === $shipment->carrier && $usps_count >= 50 ) {
                $this->log( 'USPS 50-call limit reached, stopping USPS polls' );
                continue; // Continue to process UPS shipments.
            }

            $this->poll_shipment( $shipment );

            if ( 'usps' === $shipment->carrier ) {
                $usps_count++;
            }
        }

        $this->log( sprintf( 'Poll cycle complete. USPS calls: %d', $usps_count ) );
    }

    /**
     * Poll a single shipment.
     *
     * @param object $shipment  Row from wp_fst_shipments (stdClass).
     * @return true|WP_Error
     */
    public function poll_shipment( $shipment ) {
        $this->log( sprintf( 'Polling %s (%s)', $shipment->tracking_number, $shipment->carrier ) );

        try {
            $carrier = $this->get_carrier_instance( $shipment->carrier );

            if ( ! $carrier || ! $carrier->has_credentials() ) {
                $this->log( sprintf( 'No credentials for carrier: %s', $shipment->carrier ) );
                return new WP_Error( 'fst_no_credentials', 'Carrier credentials not configured.' );
            }

            $result = $carrier->track( $shipment->tracking_number );

            if ( is_wp_error( $result ) ) {
                $this->log( sprintf( 'Track error: %s', $result->get_error_message() ) );

                // Still update last_checked so we don't hammer a failing request.
                FST_Shipment::update( $shipment->id, array(
                    'last_checked' => current_time( 'mysql' ),
                    'check_count'  => $shipment->check_count + 1,
                ) );

                return $result;
            }

            // Save old status for comparison.
            $old_status = $shipment->status;
            $new_status = ! empty( $result['status'] ) ? $result['status'] : $old_status;

            // Build update data.
            $update = array(
                'status'        => $new_status,
                'status_detail' => ! empty( $result['status_detail'] ) ? $result['status_detail'] : '',
                'last_checked'  => current_time( 'mysql' ),
                'check_count'   => $shipment->check_count + 1,
                'tracking_data' => wp_json_encode( $result['raw'] ?? array() ),
            );

            if ( ! empty( $result['est_delivery'] ) ) {
                $update['est_delivery'] = $result['est_delivery'];
            }

            if ( ! empty( $result['delivered_date'] ) ) {
                $update['delivered_date'] = $result['delivered_date'];
            }

            // Update the latest event time.
            if ( ! empty( $result['events'] ) && ! empty( $result['events'][0]['event_time'] ) ) {
                $update['last_event_at'] = $result['events'][0]['event_time'];
            }

            FST_Shipment::update( $shipment->id, $update );

            // Store new tracking events (avoid duplicates by event_time).
            if ( ! empty( $result['events'] ) ) {
                $existing_events = FST_Shipment::get_events( $shipment->id );
                $existing_times  = array();
                foreach ( $existing_events as $ev ) {
                    $existing_times[] = $ev->event_time;
                }

                foreach ( $result['events'] as $event ) {
                    if ( ! empty( $event['event_time'] ) && ! in_array( $event['event_time'], $existing_times, true ) ) {
                        FST_Shipment::add_event( array(
                            'shipment_id' => $shipment->id,
                            'status'      => $event['status'] ?? '',
                            'description' => $event['description'] ?? '',
                            'location'    => $event['location'] ?? '',
                            'event_time'  => $event['event_time'],
                            'raw_data'    => wp_json_encode( $event['raw'] ?? array() ),
                        ) );
                    }
                }
            }

            // Process status change if it actually changed.
            if ( $old_status !== $new_status ) {
                $this->log( sprintf( 'Status changed: %s -> %s', $old_status, $new_status ) );
                $this->process_status_change( $shipment, $old_status, $new_status );
            }

            return true;

        } catch ( Exception $e ) {
            $this->log( sprintf( 'Exception polling %s: %s', $shipment->tracking_number, $e->getMessage() ) );
            return new WP_Error( 'fst_poll_exception', $e->getMessage() );
        }
    }

    /**
     * Process a status change — emails, order status, hooks.
     *
     * @param object $shipment   Shipment row (stdClass).
     * @param string $old_status
     * @param string $new_status
     */
    private function process_status_change( $shipment, $old_status, $new_status ) {
        $order = wc_get_order( $shipment->order_id );
        if ( ! $order ) {
            $this->log( sprintf( 'Order not found: %d', $shipment->order_id ) );
            return;
        }

        // Add order note.
        $order->add_order_note( sprintf(
            'Shipment %s status changed: %s → %s',
            $shipment->tracking_number,
            FST_Carrier::get_status_label( $old_status ),
            FST_Carrier::get_status_label( $new_status )
        ), false );

        // Get status action config.
        $status_actions = get_option( 'fst_status_actions', array() );
        $actions        = isset( $status_actions[ $new_status ] ) ? $status_actions[ $new_status ] : array();

        // Send customer email.
        if ( ! empty( $actions['email_customer'] ) ) {
            $this->send_email( $shipment, $order, $new_status, $order->get_billing_email() );
        }

        // Send admin email.
        if ( ! empty( $actions['email_admin'] ) ) {
            $this->send_email( $shipment, $order, $new_status, get_option( 'admin_email' ), true );
        }

        // Change WooCommerce order status.
        if ( ! empty( $actions['order_status'] ) ) {
            $target_status = $actions['order_status'];
            $current       = $order->get_status();

            if ( $target_status !== $current ) {
                $order->set_status( $target_status, sprintf( 'ShipTracker: shipment %s', FST_Carrier::get_status_label( $new_status ) ) );
                $order->save();
                $this->log( sprintf( 'Order %d status changed to %s', $shipment->order_id, $target_status ) );
            }
        }

        // Fire action hook for extensibility.
        do_action( 'fst_status_changed', $shipment, $old_status, $new_status, $order );
    }

    /**
     * Send a notification email.
     *
     * @param object   $shipment
     * @param WC_Order $order
     * @param string   $status
     * @param string   $to
     * @param bool     $is_admin
     */
    private function send_email( $shipment, $order, $status, $to, $is_admin = false ) {
        $templates = get_option( 'fst_email_templates', array() );
        $template  = isset( $templates[ $status ] ) ? $templates[ $status ] : null;

        if ( ! $template || empty( $template['subject'] ) || empty( $template['body'] ) ) {
            $this->log( sprintf( 'No email template for status: %s', $status ) );
            return;
        }

        $subject = $this->replace_shortcodes( $template['subject'], $shipment, $order );
        $body    = $this->replace_shortcodes( $template['body'], $shipment, $order );

        if ( $is_admin ) {
            $subject = '[Admin] ' . $subject;
        }

        // Wrap in basic HTML.
        $html_body = '<html><body style="font-family: Arial, sans-serif; line-height: 1.6;">';
        $html_body .= nl2br( $body );
        $html_body .= '</body></html>';

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $sent = wp_mail( $to, $subject, $html_body, $headers );
        $this->log( sprintf( 'Email to %s for %s status: %s', $to, $status, $sent ? 'sent' : 'FAILED' ) );
    }

    /**
     * Replace shortcodes in email template strings.
     *
     * @param string   $template
     * @param object   $shipment  Shipment row (stdClass).
     * @param WC_Order $order
     * @return string
     */
    public function replace_shortcodes( $template, $shipment, $order ) {
        $carrier = $this->get_carrier_instance( $shipment->carrier );

        $replacements = array(
            '{tracking_number}'      => $shipment->tracking_number,
            '{carrier}'              => strtoupper( $shipment->carrier ),
            '{tracking_url}'         => home_url( '/shipment-tracking/?tracking=' . urlencode( $shipment->tracking_number ) ),
            '{carrier_tracking_url}' => $carrier ? $carrier->get_tracking_url( $shipment->tracking_number ) : '',
            '{status}'               => FST_Carrier::get_status_label( $shipment->status ),
            '{status_detail}'        => $shipment->status_detail ?? '',
            '{est_delivery}'         => $shipment->est_delivery ? date_i18n( get_option( 'date_format' ), strtotime( $shipment->est_delivery ) ) : 'TBD',
            '{ship_date}'            => $shipment->ship_date ? date_i18n( get_option( 'date_format' ), strtotime( $shipment->ship_date ) ) : '',
            '{order_number}'         => $order->get_order_number(),
            '{customer_name}'        => $order->get_billing_first_name(),
            '{tracking_timeline}'    => '', // TODO: Phase 2 — HTML progress bar widget.
            '{order_summary}'        => '', // TODO: Phase 2 — HTML order items widget.
            '{tracking_events}'      => '', // TODO: Phase 2 — HTML events list widget.
        );

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }

    /**
     * Get a carrier instance by slug.
     *
     * @param string $slug  'ups' or 'usps'.
     * @return FST_Carrier|null
     */
    private function get_carrier_instance( $slug ) {
        switch ( $slug ) {
            case 'ups':  return new FST_Carrier_UPS();
            case 'usps': return new FST_Carrier_USPS();
            default:     return null;
        }
    }

    /**
     * Log a debug message.
     */
    private function log( $message ) {
        if ( 'yes' === get_option( 'fst_debug_logging', 'no' ) ) {
            $log_file  = WP_CONTENT_DIR . '/fst-debug.log';
            $timestamp = current_time( 'Y-m-d H:i:s' );
            file_put_contents( $log_file, "[{$timestamp}] [tracker] {$message}\n", FILE_APPEND );
        }
    }
}
