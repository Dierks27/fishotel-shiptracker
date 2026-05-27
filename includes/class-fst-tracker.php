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

        // One-shot EasyPost backfill — runs after the main poll on the same hook.
        add_action( 'fst_poll_shipments', array( $this, 'maybe_run_easypost_backfill' ), 20 );

        // Ensure the polling cron is scheduled — recovers if the activation
        // hook failed (e.g. Action Scheduler wasn't loaded yet) or if the
        // scheduled action was lost.
        add_action( 'init', array( $this, 'ensure_cron_scheduled' ) );
    }

    /**
     * Make sure the recurring poll action exists.
     * Tries Action Scheduler first (ships with WooCommerce), falls back to WP-Cron.
     */
    public function ensure_cron_scheduled() {
        // Action Scheduler (preferred — bundled with WooCommerce).
        if ( function_exists( 'as_has_scheduled_action' ) ) {
            if ( ! as_has_scheduled_action( 'fst_poll_shipments' ) ) {
                as_schedule_recurring_action( time(), 30 * MINUTE_IN_SECONDS, 'fst_poll_shipments', array(), 'fishotel-shiptracker' );
            }
            return;
        }

        // Fallback to WP-Cron.
        if ( ! wp_next_scheduled( 'fst_poll_shipments' ) ) {
            wp_schedule_event( time(), 'hourly', 'fst_poll_shipments' );
        }
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
        // EasyPost-tracked shipments are pulled from EasyPost, not the carrier direct.
        if ( isset( $shipment->tracker_provider ) && 'easypost' === $shipment->tracker_provider ) {
            return $this->poll_via_easypost( $shipment );
        }

        $this->log( sprintf( 'Polling %s (%s)', $shipment->tracking_number, $shipment->carrier ) );

        try {
            $carrier = $this->get_carrier_instance( $shipment->carrier );

            if ( ! $carrier || ! $carrier->has_credentials() ) {
                $this->log( sprintf( 'No credentials for carrier: %s', $shipment->carrier ) );

                // Update last_checked so credential-less shipments don't
                // block the queue — without this they stay at the top of
                // get_due_for_poll() forever and starve other carriers.
                FST_Shipment::update( $shipment->id, array(
                    'last_checked' => current_time( 'mysql' ),
                    'check_count'  => $shipment->check_count + 1,
                ) );

                return new WP_Error( 'fst_no_credentials', 'Carrier credentials not configured.' );
            }

            $result = $carrier->track( $shipment->tracking_number );

            if ( is_wp_error( $result ) ) {
                $this->log( sprintf( 'Track error: %s', $result->get_error_message() ) );

                // USPS 403 (Access Control) — auto-promote to EasyPost if configured.
                if ( 'fst_usps_access_denied' === $result->get_error_code() ) {
                    $promoted = $this->maybe_promote_to_easypost( $shipment );
                    if ( ! is_wp_error( $promoted ) ) {
                        return $promoted;
                    }
                    $this->log( sprintf( 'EasyPost promotion failed: %s', $promoted->get_error_message() ) );
                }

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
     * Fire status-change side effects for an externally-driven change.
     *
     * Lets EasyPost webhook/poll handlers run the same emails, order
     * transitions, and hooks that the cron poll fires on a status change.
     *
     * @param object $shipment   Shipment row (stdClass).
     * @param string $old_status
     * @param string $new_status
     */
    public function process_status_change_external( $shipment, $old_status, $new_status ) {
        $this->process_status_change( $shipment, $old_status, $new_status );
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
            // Filter point so themes can suppress duplicates within a
            // fulfillment group (one combined shipped-email per customer
            // when multiple source orders ship in the same box).
            $send_customer = apply_filters( 'fst_send_customer_email', true, $shipment, $order, $new_status );
            if ( $send_customer ) {
                $this->send_email( $shipment, $order, $new_status, $order->get_billing_email() );
            }
        }

        // Send admin email.
        if ( ! empty( $actions['email_admin'] ) ) {
            $send_admin = apply_filters( 'fst_send_admin_email', true, $shipment, $order, $new_status );
            if ( $send_admin ) {
                $this->send_email( $shipment, $order, $new_status, get_option( 'admin_email' ), true );
            }
        }

        // Change WooCommerce order status.
        if ( ! empty( $actions['order_status'] ) && 'none' !== $actions['order_status'] ) {
            $target_status = $actions['order_status'];
            $current       = $order->get_status();

            // Coverage guards: don't auto-advance the order to a "this is done"
            // status when only one of N shipments has reached the trigger
            // status. The completed guard was added in v1.9.6 (auto-complete);
            // the shipped guard is v1.9.11 — without it, an OFD event on one
            // box would flip a multi-shipment order to Shipped even when other
            // items still don't have tracking.
            $blocked_partial_complete = 'completed' === $target_status
                && 'delivered' === $new_status
                && ! $this->is_order_fully_delivered( $order );

            $blocked_partial_shipped = 'shipped' === $target_status
                && ! $this->is_order_fully_shipped( $order );

            if ( $blocked_partial_complete ) {
                $order->add_order_note(
                    sprintf(
                        'Partial delivery: tracking %s delivered, other shipments still pending. Order kept in %s.',
                        $shipment->tracking_number,
                        $current
                    ),
                    false
                );
                $this->log( sprintf( 'Order %d: skipped auto-complete (partial delivery)', $shipment->order_id ) );
            } elseif ( $blocked_partial_shipped ) {
                $order->add_order_note(
                    sprintf(
                        'ShipTracker: shipment %s reached %s, but order has unshipped items — status held at %s.',
                        $shipment->tracking_number,
                        FST_Carrier::get_status_label( $new_status ),
                        $current
                    ),
                    false
                );
                $this->log( sprintf( 'Order %d: skipped auto-shipped (partial shipment)', $shipment->order_id ) );
            } elseif ( $target_status !== $current ) {
                $order->set_status( $target_status, sprintf( 'ShipTracker: shipment %s', FST_Carrier::get_status_label( $new_status ) ) );
                $order->save();
                $this->log( sprintf( 'Order %d status changed to %s', $shipment->order_id, $target_status ) );
            }
        }

        // Send SMS notification via email-to-SMS gateway.
        $this->send_sms( $shipment, $order, $new_status );

        // Fire action hook for extensibility.
        do_action( 'fst_status_changed', $shipment, $old_status, $new_status, $order );
    }

    /**
     * Poll a shipment via EasyPost (pull the current tracker state).
     *
     * @param object $shipment Shipment row (stdClass).
     * @return true|WP_Error
     */
    private function poll_via_easypost( $shipment ) {
        $this->log( sprintf( 'Polling %s via EasyPost (tracker %s)', $shipment->tracking_number, $shipment->easypost_tracker_id ) );

        // No tracker id yet — try to create one now.
        if ( empty( $shipment->easypost_tracker_id ) ) {
            return $this->maybe_promote_to_easypost( $shipment );
        }

        $tracker = FST_EasyPost::get_tracker( $shipment->easypost_tracker_id );

        if ( is_wp_error( $tracker ) ) {
            $this->log( sprintf( 'EasyPost get_tracker error: %s', $tracker->get_error_message() ) );

            // Update last_checked so a failing tracker doesn't starve the queue.
            FST_Shipment::update( $shipment->id, array(
                'last_checked' => current_time( 'mysql' ),
                'check_count'  => $shipment->check_count + 1,
            ) );

            return $tracker;
        }

        return FST_EasyPost::apply_tracker_to_shipment( $shipment, $tracker );
    }

    /**
     * Promote a shipment to EasyPost: create a tracker, store the id, apply the
     * initial payload. Used both for USPS 403 auto-promotion and to repair an
     * EasyPost shipment that has no tracker id yet.
     *
     * @param object $shipment Shipment row (stdClass).
     * @return true|WP_Error
     */
    private function maybe_promote_to_easypost( $shipment ) {
        if ( ! FST_EasyPost::has_credentials() ) {
            return new WP_Error( 'fst_easypost_not_configured', 'EasyPost is not configured.' );
        }

        // Respect the USPS routing setting.
        if ( 'usps' === $shipment->carrier && 'never' === get_option( 'fst_easypost_usps_routing', 'auto' ) ) {
            return new WP_Error( 'fst_easypost_routing_never', 'EasyPost routing is disabled for USPS.' );
        }

        $this->log( sprintf( 'Promoting %s to EasyPost', $shipment->tracking_number ) );

        $tracker = FST_EasyPost::create_tracker( $shipment->tracking_number, $shipment->carrier );
        if ( is_wp_error( $tracker ) ) {
            $this->log( sprintf( 'EasyPost create_tracker failed: %s', $tracker->get_error_message() ) );
            return $tracker;
        }

        $tracker_id = isset( $tracker['id'] ) ? $tracker['id'] : '';

        FST_Shipment::update( $shipment->id, array(
            'easypost_tracker_id' => $tracker_id,
            'tracker_provider'    => 'easypost',
        ) );
        $shipment->easypost_tracker_id = $tracker_id;
        $shipment->tracker_provider    = 'easypost';

        return FST_EasyPost::apply_tracker_to_shipment( $shipment, $tracker );
    }

    /**
     * Run the one-shot EasyPost backfill if it's pending and EasyPost is configured.
     *
     * Hooked to fst_poll_shipments at priority 20 so it runs after the main poll.
     */
    public function maybe_run_easypost_backfill() {
        if ( 'yes' !== get_option( 'fst_easypost_backfill_pending', 'no' ) ) {
            return;
        }

        // Leave the flag set until EasyPost is configured, so configuring it
        // later still triggers the backfill on a subsequent cron run.
        if ( ! FST_EasyPost::has_credentials() ) {
            $this->log( 'EasyPost backfill pending, but no credentials configured yet.' );
            return;
        }

        $this->log( 'Running one-shot EasyPost backfill' );
        $result = FST_EasyPost::backfill_active_shipments();
        $this->log( sprintf(
            'EasyPost backfill: %d registered, %d skipped, %d errors',
            $result['registered'],
            $result['skipped'],
            $result['errors']
        ) );

        delete_option( 'fst_easypost_backfill_pending' );
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
        $replacements = $this->build_replacements( $shipment, $order );
        $is_partial   = ! empty( $shipment->line_item_ids );
        $rendered     = self::render_status_email( $status, $replacements, $is_admin, $is_partial );

        if ( ! $rendered ) {
            $this->log( sprintf( 'No email template for status: %s', $status ) );
            return;
        }

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $sent    = wp_mail( $to, $rendered['subject'], $rendered['html_body'], $headers );
        $this->log( sprintf( 'Email to %s for %s status: %s', $to, $status, $sent ? 'sent' : 'FAILED' ) );
    }

    /**
     * Whether every line item on $order is covered by at least one shipment.
     *
     * Mirrors FST_Order::is_order_fully_shipped(). A shipment row with
     * NULL/empty line_item_ids contributes no coverage (post-v1.9.6 strict
     * semantics) — only rows that explicitly list item ids count.
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
     * Whether every line item on $order is in a shipment with status 'delivered'.
     *
     * Mirrors FST_Order::is_order_fully_shipped(). A shipment with NULL/empty
     * line_item_ids is treated as covering ALL items on its order.
     *
     * @param WC_Order $order
     * @return bool
     */
    private function is_order_fully_delivered( $order ) {
        $order_item_ids = array_keys( $order->get_items() );
        if ( empty( $order_item_ids ) ) {
            return true;
        }

        $shipments     = FST_Shipment::get_by_order( $order->get_id() );
        $delivered_ids = array();

        foreach ( $shipments as $s ) {
            if ( 'delivered' !== $s->status ) {
                continue;
            }
            if ( empty( $s->line_item_ids ) ) {
                // Delivered shipment with no item scope = covers everything.
                return true;
            }
            $ids = json_decode( $s->line_item_ids, true );
            if ( is_array( $ids ) ) {
                $delivered_ids = array_merge( $delivered_ids, array_map( 'absint', $ids ) );
            }
        }

        $delivered_ids = array_unique( $delivered_ids );
        $undelivered   = array_diff( $order_item_ids, $delivered_ids );

        return empty( $undelivered );
    }

    /**
     * Render a status email using the saved (or default) template.
     *
     * Shared by both the real status-change email path and the
     * "Send Test Email" handler so previews always reflect the
     * admin's saved templates.
     *
     * @param string $status        Shipment status slug.
     * @param array  $replacements  Map of shortcode => value.
     * @param bool   $is_admin      Whether this is an admin-recipient email.
     * @param bool   $is_partial    Whether this shipment covers only a subset
     *                              of the order's items (partial fulfillment).
     *                              Rewrites "Your order" → "Items from your order"
     *                              in the subject so customers know to expect
     *                              additional boxes.
     * @return array|null { subject, html_body } or null if no template exists.
     */
    public static function render_status_email( $status, array $replacements, $is_admin = false, $is_partial = false ) {
        $templates = get_option( 'fst_email_templates', array() );
        $template  = isset( $templates[ $status ] ) ? $templates[ $status ] : null;

        // Fall back to defaults if no custom template is set.
        if ( ! $template || empty( $template['subject'] ) || empty( $template['body'] ) ) {
            $defaults = FST_Email::get_defaults();
            $template = isset( $defaults[ $status ] ) ? $defaults[ $status ] : null;
        }

        if ( ! $template || empty( $template['subject'] ) || empty( $template['body'] ) ) {
            return null;
        }

        $subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $template['subject'] );
        $body    = str_replace( array_keys( $replacements ), array_values( $replacements ), $template['body'] );

        if ( $is_partial ) {
            $subject = self::partialize_subject( $subject );
        }

        if ( $is_admin ) {
            $subject = '[Admin] ' . $subject;
        }

        return array(
            'subject'   => $subject,
            'html_body' => FST_Email::wrap( nl2br( $body ), $status ),
        );
    }

    /**
     * Rewrite a subject line to use partial-shipment language.
     *
     * Heuristic: "Your order #X has shipped!" → "Items from your order #X have shipped".
     * If the template doesn't contain the phrase "your order", we prefix a tag
     * instead so the partial-vs-full distinction is never lost.
     *
     * @param string $subject
     * @return string
     */
    private static function partialize_subject( $subject ) {
        if ( preg_match( '/\byour order\b/i', $subject ) ) {
            $rewritten = preg_replace_callback(
                '/\b(your order)\b/i',
                static function ( $m ) {
                    // Match the case of the captured "Your" / "your".
                    return ctype_upper( $m[1][0] ) ? 'Items from your order' : 'items from your order';
                },
                $subject,
                1
            );
            // Singular verbs → plural ("has" → "have", "is" → "are").
            $rewritten = preg_replace( '/\bhas\b/', 'have', $rewritten );
            $rewritten = preg_replace( '/\bis\b/', 'are', $rewritten );
            return $rewritten;
        }
        return '[Partial shipment] ' . $subject;
    }

    /**
     * Send an SMS notification via email-to-SMS carrier gateway.
     *
     * @param object   $shipment   Shipment row (stdClass).
     * @param WC_Order $order
     * @param string   $new_status
     */
    private function send_sms( $shipment, $order, $new_status ) {
        if ( 'yes' !== $order->get_meta( '_fst_sms_enabled' ) ) {
            return;
        }

        $sms_statuses = $order->get_meta( '_fst_sms_statuses' );
        if ( ! is_array( $sms_statuses ) || ! in_array( $new_status, $sms_statuses, true ) ) {
            return;
        }

        $phone = preg_replace( '/[^0-9]/', '', $order->get_billing_phone() );

        // Strip US country code prefix.
        if ( strlen( $phone ) === 11 && '1' === $phone[0] ) {
            $phone = substr( $phone, 1 );
        }

        if ( strlen( $phone ) !== 10 ) {
            $this->log( sprintf( 'SMS skipped for order %d: invalid phone (%s)', $order->get_id(), $phone ) );
            return;
        }

        $gateways = array(
            'att'     => '@txt.att.net',
            'verizon' => '@vtext.com',
            'tmobile' => '@tmomail.net',
            'sprint'  => '@messaging.sprintpcs.com',
        );

        $carrier_key = $order->get_meta( '_fst_sms_carrier' );
        if ( ! isset( $gateways[ $carrier_key ] ) ) {
            $this->log( sprintf( 'SMS skipped for order %d: unknown carrier (%s)', $order->get_id(), $carrier_key ) );
            return;
        }

        $sms_to  = $phone . $gateways[ $carrier_key ];
        $message = $this->build_sms_message( $shipment, $order, $new_status );
        $headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        $sent = wp_mail( $sms_to, '', $message, $headers );
        $this->log( sprintf( 'SMS to %s for order %d status %s: %s', $sms_to, $order->get_id(), $new_status, $sent ? 'sent' : 'FAILED' ) );
    }

    /**
     * Build a short SMS message (max 160 characters).
     *
     * @param object   $shipment
     * @param WC_Order $order
     * @param string   $status
     * @return string
     */
    private function build_sms_message( $shipment, $order, $status ) {
        $message = sprintf(
            '%s: Order #%s is %s. Track: %s',
            get_bloginfo( 'name' ),
            $order->get_order_number(),
            FST_Carrier::get_status_label( $status ),
            $shipment->tracking_number
        );

        if ( mb_strlen( $message ) > 160 ) {
            $message = mb_substr( $message, 0, 157 ) . '...';
        }

        return $message;
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
        $replacements = $this->build_replacements( $shipment, $order );
        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }

    /**
     * Build the shortcode => value map for a real shipment + order.
     *
     * @param object   $shipment  Shipment row (stdClass).
     * @param WC_Order $order
     * @return array
     */
    public function build_replacements( $shipment, $order ) {
        $carrier     = $this->get_carrier_instance( $shipment->carrier );
        $carrier_url = $carrier ? $carrier->get_tracking_url( $shipment->tracking_number ) : '';
        $accent      = FST_Carrier::get_status_color( $shipment->status );

        // When a shipment is scoped to a subset of the order (partial fulfillment),
        // the {order_summary} block should only list the items that are actually
        // in this box.
        $items_to_show = null;
        if ( ! empty( $shipment->line_item_ids ) ) {
            $included_ids = json_decode( $shipment->line_item_ids, true );
            if ( is_array( $included_ids ) && ! empty( $included_ids ) ) {
                $included_ids  = array_map( 'absint', $included_ids );
                $items_to_show = array_filter(
                    $order->get_items(),
                    static function ( $item ) use ( $included_ids ) {
                        return in_array( $item->get_id(), $included_ids, true );
                    }
                );
            }
        }

        return array(
            '{tracking_number}'      => $shipment->tracking_number,
            '{carrier}'              => strtoupper( $shipment->carrier ),
            '{tracking_url}'         => home_url( '/shipment-tracking/?tracking=' . urlencode( $shipment->tracking_number ) ),
            '{carrier_tracking_url}' => $carrier_url,
            '{status}'               => FST_Carrier::get_status_label( $shipment->status ),
            '{status_detail}'        => $shipment->status_detail ?? '',
            '{est_delivery}'         => $shipment->est_delivery ? date_i18n( get_option( 'date_format' ), strtotime( $shipment->est_delivery ) ) : 'TBD',
            '{ship_date}'            => $shipment->ship_date ? date_i18n( get_option( 'date_format' ), strtotime( $shipment->ship_date ) ) : '',
            '{order_number}'         => $order->get_order_number(),
            '{customer_name}'        => $order->get_billing_first_name(),
            '{tracking_progress}'    => FST_Email::render_progress_bar( $shipment->status ),
            '{tracking_timeline}'    => FST_Email::render_progress_bar( $shipment->status ),
            '{tracking_events}'      => FST_Email::render_events( $shipment->id ),
            '{order_summary}'        => FST_Email::render_order_summary( $order, $items_to_show ),
            '{track_button}'         => FST_Email::render_track_button( $carrier_url ?: '', 'Track Your Package', $accent ),
        );
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
