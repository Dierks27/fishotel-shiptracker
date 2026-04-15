<?php
/**
 * Email template engine — builds branded HTML emails.
 *
 * Provides a WooCommerce-style HTML wrapper and renders shortcode
 * widgets like tracking timelines, progress bars, and order summaries.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Email {

    /**
     * Get the full HTML email body, wrapped in the branded template.
     *
     * @param string   $body_content  The user-defined template body (with shortcodes already replaced).
     * @param string   $status        The shipment status slug (for accent color).
     * @return string  Complete HTML email.
     */
    public static function wrap( $body_content, $status = '' ) {
        $site_name    = get_bloginfo( 'name' );
        $site_url     = home_url();
        $accent_color = FST_Carrier::get_status_color( $status );
        $year         = date( 'Y' );

        // Fall back to a nice blue if no status color.
        if ( empty( $accent_color ) || '#999999' === $accent_color ) {
            $accent_color = '#007cba';
        }

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo esc_html( $site_name ); ?></title>
</head>
<body style="margin: 0; padding: 0; background-color: #f7f7f7; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif; -webkit-font-smoothing: antialiased;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #f7f7f7;">
<tr>
<td align="center" style="padding: 30px 15px;">

<!-- Main Container -->
<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.06);">

<!-- Header -->
<tr>
<td style="background-color: <?php echo esc_attr( $accent_color ); ?>; padding: 24px 40px; text-align: center;">
    <a href="<?php echo esc_url( $site_url ); ?>" style="color: #ffffff; text-decoration: none; font-size: 22px; font-weight: 700; letter-spacing: 0.5px;">
        <?php echo esc_html( $site_name ); ?>
    </a>
</td>
</tr>

<!-- Body -->
<tr>
<td style="padding: 32px 40px; color: #333333; font-size: 15px; line-height: 1.7;">
<?php echo $body_content; ?>
</td>
</tr>

<!-- Footer -->
<tr>
<td style="background-color: #fafafa; padding: 20px 40px; text-align: center; border-top: 1px solid #eee;">
    <p style="margin: 0; font-size: 12px; color: #999;">
        &copy; <?php echo esc_html( $year ); ?> <a href="<?php echo esc_url( $site_url ); ?>" style="color: #999; text-decoration: none;"><?php echo esc_html( $site_name ); ?></a>
    </p>
    <p style="margin: 4px 0 0; font-size: 11px; color: #bbb;">
        Shipment tracking powered by ShipTracker
    </p>
</td>
</tr>

</table>
<!-- End Main Container -->

</td>
</tr>
</table>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    /**
     * Render the tracking progress bar widget for email.
     *
     * @param string $status Current status slug.
     * @return string HTML for the progress bar.
     */
    public static function render_progress_bar( $status ) {
        $steps = array(
            'shipped'          => 'Shipped',
            'in_transit'       => 'In Transit',
            'out_for_delivery' => 'Out for Delivery',
            'delivered'        => 'Delivered',
        );

        $status_map = array(
            'unknown'              => -1,
            'label_created'        => 0,
            'shipped'              => 0,
            'pre_transit'          => 0,
            'in_transit'           => 1,
            'out_for_delivery'     => 2,
            'available_for_pickup' => 2,
            'delivered'            => 3,
            'exception'            => 1,
            'return_to_sender'     => 1,
            'failure'              => 1,
        );

        $current = isset( $status_map[ $status ] ) ? $status_map[ $status ] : -1;
        $accent  = FST_Carrier::get_status_color( $status );

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 16px 0 24px;">';
        $html .= '<tr>';

        $i = 0;
        foreach ( $steps as $key => $label ) {
            $is_done   = $i <= $current;
            $dot_bg    = $is_done ? $accent : '#e0e0e0';
            $dot_border= $is_done ? $accent : '#e0e0e0';
            $label_clr = $is_done ? '#333' : '#bbb';
            $label_wt  = $is_done ? '600' : '400';

            $html .= '<td width="25%" align="center" style="padding: 0 4px;">';
            $html .= '<div style="width: 18px; height: 18px; border-radius: 50%; background: ' . $dot_bg . '; border: 2px solid ' . $dot_border . '; margin: 0 auto 6px;"></div>';
            $html .= '<div style="font-size: 11px; color: ' . $label_clr . '; font-weight: ' . $label_wt . ';">' . esc_html( $label ) . '</div>';
            $html .= '</td>';
            $i++;
        }

        $html .= '</tr></table>';

        return $html;
    }

    /**
     * Render the tracking events list widget for email.
     *
     * @param int $shipment_id
     * @param int $max_events  Max events to show (default 5).
     * @return string HTML for the events list.
     */
    public static function render_events( $shipment_id, $max_events = 5 ) {
        $events = FST_Shipment::get_events( $shipment_id );

        if ( empty( $events ) ) {
            return '';
        }

        $events = array_slice( $events, 0, $max_events );

        $html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 16px 0; border: 1px solid #eee; border-radius: 6px; overflow: hidden;">';
        $html .= '<tr><td style="background: #f9f9f9; padding: 10px 16px; font-size: 13px; font-weight: 600; color: #444; border-bottom: 1px solid #eee;">Recent Tracking Updates</td></tr>';

        foreach ( $events as $idx => $event ) {
            $border = $idx < count( $events ) - 1 ? 'border-bottom: 1px solid #f0f0f0;' : '';
            $html .= '<tr><td style="padding: 10px 16px; ' . $border . '">';
            $html .= '<div style="font-size: 13px; color: #333; font-weight: 500;">' . esc_html( $event->description ) . '</div>';
            $html .= '<div style="font-size: 11px; color: #999; margin-top: 2px;">';
            if ( ! empty( $event->location ) ) {
                $html .= esc_html( $event->location ) . ' &middot; ';
            }
            $html .= esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $event->event_time ) ) );
            $html .= '</div></td></tr>';
        }

        $html .= '</table>';

        return $html;
    }

    /**
     * Render the order summary widget for email.
     *
     * @param WC_Order $order
     * @return string HTML for the order summary.
     */
    public static function render_order_summary( $order ) {
        if ( ! $order ) {
            return '';
        }

        $items = $order->get_items();
        if ( empty( $items ) ) {
            return '';
        }

        $html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 16px 0; border: 1px solid #eee; border-radius: 6px; overflow: hidden;">';
        $html .= '<tr><td style="background: #f9f9f9; padding: 10px 16px; font-size: 13px; font-weight: 600; color: #444; border-bottom: 1px solid #eee;">Order #' . esc_html( $order->get_order_number() ) . '</td></tr>';

        foreach ( $items as $item ) {
            $product  = $item->get_product();
            $name     = $item->get_name();
            $qty      = $item->get_quantity();
            $total    = $order->get_formatted_line_subtotal( $item );

            $html .= '<tr><td style="padding: 10px 16px; border-bottom: 1px solid #f0f0f0;">';
            $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>';
            $html .= '<td style="font-size: 13px; color: #333;">' . esc_html( $name ) . ' <span style="color: #999;">&times; ' . esc_html( $qty ) . '</span></td>';
            $html .= '<td align="right" style="font-size: 13px; color: #333; font-weight: 500;">' . $total . '</td>';
            $html .= '</tr></table>';
            $html .= '</td></tr>';
        }

        $html .= '<tr><td style="padding: 10px 16px; background: #fafafa;">';
        $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr>';
        $html .= '<td style="font-size: 13px; font-weight: 600; color: #333;">Total</td>';
        $html .= '<td align="right" style="font-size: 14px; font-weight: 700; color: #333;">' . $order->get_formatted_order_total() . '</td>';
        $html .= '</tr></table>';
        $html .= '</td></tr>';

        $html .= '</table>';

        return $html;
    }

    /**
     * Render the "Track Your Package" call-to-action button for email.
     *
     * @param string $url    The tracking URL.
     * @param string $label  Button text.
     * @param string $color  Button background color.
     * @return string HTML button.
     */
    public static function render_track_button( $url, $label = 'Track Your Package', $color = '#007cba' ) {
        return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin: 20px auto;">' .
            '<tr><td align="center" style="background: ' . esc_attr( $color ) . '; border-radius: 6px;">' .
            '<a href="' . esc_url( $url ) . '" target="_blank" style="display: inline-block; padding: 12px 32px; color: #ffffff; text-decoration: none; font-size: 14px; font-weight: 600; letter-spacing: 0.3px;">' . esc_html( $label ) . '</a>' .
            '</td></tr></table>';
    }

    /**
     * Get default email templates for all statuses.
     *
     * @return array status_key => array( 'subject' => ..., 'body' => ... )
     */
    public static function get_defaults() {
        return array(
            'label_created' => array(
                'subject' => 'Shipping label created for Order #{order_number}',
                'body'    => "Hi {customer_name},\n\nGreat news! A shipping label has been created for your order.\n\n{tracking_progress}\n\nCarrier: {carrier}\nTracking #: {tracking_number}\n\n{track_button}\n\nWe'll send you another update once your package is on the move!",
            ),
            'shipped' => array(
                'subject' => 'Your order #{order_number} has been shipped!',
                'body'    => "Hi {customer_name},\n\nYour order has been shipped!\n\n{tracking_progress}\n\nCarrier: {carrier}\nTracking #: {tracking_number}\n\n{track_button}\n\nWe'll keep you updated as your package makes its way to you!",
            ),
            'pre_transit' => array(
                'subject' => 'Your order #{order_number} is ready to ship',
                'body'    => "Hi {customer_name},\n\nYour package is ready and waiting to be picked up by {carrier}.\n\n{tracking_progress}\n\nTracking #: {tracking_number}\n\n{track_button}\n\nHang tight — it should be moving soon!",
            ),
            'in_transit' => array(
                'subject' => 'Your order #{order_number} is on its way!',
                'body'    => "Hi {customer_name},\n\nYour package is on the move!\n\n{tracking_progress}\n\nCarrier: {carrier}\nTracking #: {tracking_number}\nEstimated Delivery: {est_delivery}\n\n{tracking_events}\n\n{track_button}",
            ),
            'out_for_delivery' => array(
                'subject' => 'Your order #{order_number} is out for delivery!',
                'body'    => "Hi {customer_name},\n\nExciting news — your package is out for delivery today!\n\n{tracking_progress}\n\nCarrier: {carrier}\nTracking #: {tracking_number}\n\n{tracking_events}\n\n{track_button}\n\nKeep an eye out!",
            ),
            'delivered' => array(
                'subject' => 'Your order #{order_number} has been delivered!',
                'body'    => "Hi {customer_name},\n\nYour package has been delivered!\n\n{tracking_progress}\n\nCarrier: {carrier}\nTracking #: {tracking_number}\n\n{order_summary}\n\nThank you for shopping with us! If you have any questions about your order, feel free to get in touch.",
            ),
            'exception' => array(
                'subject' => 'Shipping update for Order #{order_number}',
                'body'    => "Hi {customer_name},\n\nThere's been an update on your shipment that may require your attention.\n\n{tracking_progress}\n\nStatus: {status}\nDetail: {status_detail}\nCarrier: {carrier}\nTracking #: {tracking_number}\n\n{tracking_events}\n\n{track_button}\n\nIf you have any concerns, please contact us and we'll help sort things out.",
            ),
            'available_for_pickup' => array(
                'subject' => 'Your order #{order_number} is ready for pickup!',
                'body'    => "Hi {customer_name},\n\nYour package is available for pickup!\n\n{tracking_progress}\n\nCarrier: {carrier}\nTracking #: {tracking_number}\n\n{tracking_events}\n\n{track_button}",
            ),
            'return_to_sender' => array(
                'subject' => 'Shipping update for Order #{order_number}',
                'body'    => "Hi {customer_name},\n\nUnfortunately, your package is being returned to us.\n\nStatus: {status}\nDetail: {status_detail}\nCarrier: {carrier}\nTracking #: {tracking_number}\n\n{tracking_events}\n\nPlease contact us and we'll make it right.",
            ),
            'failure' => array(
                'subject' => 'Delivery issue with Order #{order_number}',
                'body'    => "Hi {customer_name},\n\nWe're sorry, but there was a delivery issue with your order.\n\nStatus: {status}\nDetail: {status_detail}\nCarrier: {carrier}\nTracking #: {tracking_number}\n\n{tracking_events}\n\nPlease reach out and we'll work to resolve this as quickly as possible.",
            ),
        );
    }
}
