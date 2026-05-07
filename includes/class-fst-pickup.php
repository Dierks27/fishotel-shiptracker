<?php
/**
 * Local pickup notification — meta box + AJAX handler for sending
 * the "Available for Pickup" email on local pickup orders without
 * touching the carrier-tracking flow.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Pickup {

    const STATUS_KEY = 'available_for_pickup';
    const META_SENT  = '_fst_pickup_email_sent_at';

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'maybe_add_meta_box' ), 10, 2 );
        add_action( 'wp_ajax_fst_send_pickup_email', array( $this, 'ajax_send_pickup_email' ) );
    }

    /**
     * Register the meta box only on local pickup orders.
     *
     * @param string             $screen_id
     * @param WP_Post|WC_Order   $post_or_order
     */
    public function maybe_add_meta_box( $screen_id, $post_or_order ) {
        $order = $this->resolve_order( $post_or_order );
        if ( ! $order || ! self::is_local_pickup( $order ) ) {
            return;
        }

        $valid_screens = array( 'shop_order' );
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $valid_screens[] = wc_get_page_screen_id( 'shop-order' );
        }
        if ( ! in_array( $screen_id, $valid_screens, true ) ) {
            return;
        }

        add_meta_box(
            'fst-pickup',
            __( 'FisHotel Pickup', 'fishotel-shiptracker' ),
            array( $this, 'render_meta_box' ),
            $screen_id,
            'side',
            'high'
        );
    }

    /**
     * @param WP_Post|WC_Order $post_or_order
     * @return WC_Order|null
     */
    private function resolve_order( $post_or_order ) {
        if ( $post_or_order instanceof WC_Order ) {
            return $post_or_order;
        }
        if ( $post_or_order instanceof WP_Post ) {
            return wc_get_order( $post_or_order->ID );
        }
        return null;
    }

    /**
     * Detect a local pickup shipping method on an order.
     *
     * @param WC_Order $order
     * @return bool
     */
    public static function is_local_pickup( $order ) {
        foreach ( $order->get_shipping_methods() as $method ) {
            if ( 'local_pickup' === $method->get_method_id() ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render the meta box body.
     *
     * @param WP_Post|WC_Order $post_or_order
     */
    public function render_meta_box( $post_or_order ) {
        $order = $this->resolve_order( $post_or_order );
        if ( ! $order ) {
            return;
        }

        $order_id = $order->get_id();
        $sent_at  = $order->get_meta( self::META_SENT );
        $is_sent  = ! empty( $sent_at );

        $sent_display = '';
        if ( $is_sent ) {
            $ts = strtotime( $sent_at );
            if ( $ts ) {
                $sent_display = date_i18n(
                    get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                    $ts
                );
            }
        }
        ?>
        <div id="fst-pickup-metabox" data-order-id="<?php echo esc_attr( $order_id ); ?>">
            <p style="margin: 0 0 8px;">
                <strong><?php esc_html_e( 'Shipping method:', 'fishotel-shiptracker' ); ?></strong>
                <?php esc_html_e( 'Local pickup', 'fishotel-shiptracker' ); ?>
            </p>
            <p class="fst-pickup-status" style="margin: 0 0 12px;">
                <strong><?php esc_html_e( 'Pickup ready email:', 'fishotel-shiptracker' ); ?></strong><br>
                <?php if ( $is_sent ) : ?>
                    <span style="color: #46b450;">
                        &#10003; <?php esc_html_e( 'Sent', 'fishotel-shiptracker' ); ?>
                    </span>
                    <?php if ( $sent_display ) : ?>
                        <span style="color: #666; font-size: 12px;"><br><?php echo esc_html( $sent_display ); ?></span>
                    <?php endif; ?>
                <?php else : ?>
                    <span style="color: #666;"><?php esc_html_e( 'Not sent', 'fishotel-shiptracker' ); ?></span>
                <?php endif; ?>
            </p>
            <button type="button" class="button button-primary widefat" id="fst-send-pickup-email">
                <?php echo $is_sent
                    ? esc_html__( 'Resend Email', 'fishotel-shiptracker' )
                    : esc_html__( 'Send Pickup Ready Email', 'fishotel-shiptracker' ); ?>
            </button>
            <div id="fst-pickup-message" style="margin-top: 8px; display: none;"></div>
        </div>
        <script>
        (function ($) {
            $(function () {
                var $box = $('#fst-pickup-metabox');
                if ( ! $box.length ) return;
                $('#fst-send-pickup-email').on('click', function () {
                    var $btn = $(this);
                    var $msg = $('#fst-pickup-message');
                    var orderId = $box.data('order-id');
                    var original = $btn.text();

                    $btn.prop('disabled', true).text('Sending...');
                    $msg.hide().removeClass('notice-success notice-error').css('color', '');

                    $.ajax({
                        url: fst_admin.ajax_url,
                        type: 'POST',
                        data: {
                            action: 'fst_send_pickup_email',
                            nonce: fst_admin.nonce,
                            order_id: orderId
                        }
                    }).done(function (response) {
                        if (response && response.success) {
                            $msg.text(response.data.message).css('color', '#46b450').show();
                            if (response.data.sent_display) {
                                $('.fst-pickup-status').html(
                                    '<strong><?php echo esc_js( __( 'Pickup ready email:', 'fishotel-shiptracker' ) ); ?></strong><br>' +
                                    '<span style="color:#46b450;">&#10003; <?php echo esc_js( __( 'Sent', 'fishotel-shiptracker' ) ); ?></span>' +
                                    '<span style="color:#666;font-size:12px;"><br>' + response.data.sent_display + '</span>'
                                );
                            }
                            $btn.text('<?php echo esc_js( __( 'Resend Email', 'fishotel-shiptracker' ) ); ?>');
                        } else {
                            var err = response && response.data && response.data.message
                                ? response.data.message
                                : 'Failed to send email.';
                            $msg.text(err).css('color', '#dc3232').show();
                            $btn.text(original);
                        }
                    }).fail(function () {
                        $msg.text('AJAX error — could not reach server.').css('color', '#dc3232').show();
                        $btn.text(original);
                    }).always(function () {
                        $btn.prop('disabled', false);
                    });
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /**
     * AJAX: send the pickup-ready email to the customer.
     */
    public function ajax_send_pickup_email() {
        check_ajax_referer( 'fst_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        $order    = $order_id ? wc_get_order( $order_id ) : null;

        if ( ! $order ) {
            wp_send_json_error( array( 'message' => 'Order not found.' ) );
        }

        if ( ! self::is_local_pickup( $order ) ) {
            wp_send_json_error( array( 'message' => 'This order is not a local pickup order.' ) );
        }

        $customer_email = $order->get_billing_email();
        if ( empty( $customer_email ) ) {
            wp_send_json_error( array( 'message' => 'Order has no billing email.' ) );
        }

        $replacements = self::build_replacements( $order );
        $rendered     = FST_Tracker::render_status_email( self::STATUS_KEY, $replacements );

        if ( ! $rendered ) {
            wp_send_json_error( array( 'message' => 'No email template configured for "Available for Pickup".' ) );
        }

        $headers       = array( 'Content-Type: text/html; charset=UTF-8' );
        $customer_sent = wp_mail( $customer_email, $rendered['subject'], $rendered['html_body'], $headers );

        if ( ! $customer_sent ) {
            wp_send_json_error( array( 'message' => 'wp_mail() failed sending to customer. Check your email configuration.' ) );
        }

        // Optionally send to admin per Status Action config.
        $status_actions = get_option( 'fst_status_actions', array() );
        $actions        = isset( $status_actions[ self::STATUS_KEY ] ) ? $status_actions[ self::STATUS_KEY ] : array();
        if ( ! empty( $actions['email_admin'] ) ) {
            $admin_rendered = FST_Tracker::render_status_email( self::STATUS_KEY, $replacements, true );
            if ( $admin_rendered ) {
                wp_mail( get_option( 'admin_email' ), $admin_rendered['subject'], $admin_rendered['html_body'], $headers );
            }
        }

        $now = current_time( 'mysql' );
        $order->update_meta_data( self::META_SENT, $now );
        $order->save();

        $order->add_order_note( 'Pickup ready email sent to customer.', false );

        $sent_display = date_i18n(
            get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
            strtotime( $now )
        );

        wp_send_json_success( array(
            'message'      => 'Pickup ready email sent to ' . $customer_email,
            'sent_at'      => $now,
            'sent_display' => $sent_display,
        ) );
    }

    /**
     * Build the shortcode replacement map for a local pickup order
     * (no carrier, no shipment record).
     *
     * @param WC_Order $order
     * @return array
     */
    private static function build_replacements( $order ) {
        $progress = FST_Email::render_progress_bar( self::STATUS_KEY );

        return array(
            '{tracking_number}'      => '',
            '{carrier}'              => 'FisHotel',
            '{tracking_url}'         => '',
            '{carrier_tracking_url}' => '',
            '{status}'               => FST_Carrier::get_status_label( self::STATUS_KEY ),
            '{status_detail}'        => 'Ready for pickup at FisHotel',
            '{est_delivery}'         => '',
            '{ship_date}'            => '',
            '{order_number}'         => $order->get_order_number(),
            '{customer_name}'        => $order->get_billing_first_name(),
            '{tracking_progress}'    => $progress,
            '{tracking_timeline}'    => $progress,
            '{tracking_events}'      => '',
            '{order_summary}'        => FST_Email::render_order_summary( $order ),
            '{track_button}'         => '',
        );
    }
}
