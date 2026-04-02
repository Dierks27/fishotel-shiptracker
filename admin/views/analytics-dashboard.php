<?php
/**
 * FisHotel ShipTracker - Analytics Dashboard
 *
 * @package FisHotel_ShipTracker
 * @subpackage Admin
 */

defined( 'ABSPATH' ) || exit;

// Get analytics data using existing methods only.
$status_counts  = FST_Shipment::count_by_status();
$late_shipments = FST_Shipment::get_late_shipments();
$late_count     = count( $late_shipments );
$total          = array_sum( $status_counts );
$delivered      = isset( $status_counts['delivered'] ) ? $status_counts['delivered'] : 0;

?>

<div class="wrap">
    <h1><?php esc_html_e( 'Analytics Dashboard', 'fishotel-shiptracker' ); ?></h1>

    <div class="notice notice-info">
        <p>
            <?php esc_html_e( 'Full charts and graphs coming in Phase 3. Basic stats are shown below.', 'fishotel-shiptracker' ); ?>
        </p>
    </div>

    <!-- Basic Stats -->
    <div class="fst-analytics-grid" style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 20px;">
        <div class="fst-analytics-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
            <h3><?php esc_html_e( 'Status Overview', 'fishotel-shiptracker' ); ?></h3>

            <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                <?php
                    $statuses = array(
                        'unknown'          => 'Unknown',
                        'pre_transit'      => 'Pre-Transit',
                        'in_transit'       => 'In Transit',
                        'out_for_delivery' => 'Out for Delivery',
                        'delivered'        => 'Delivered',
                        'exception'        => 'Exception',
                        'return_to_sender' => 'Return to Sender',
                        'failure'          => 'Failure',
                    );

                    foreach ( $statuses as $status_key => $status_label ) {
                        $count = isset( $status_counts[ $status_key ] ) ? $status_counts[ $status_key ] : 0;
                        if ( 0 === $count && ! in_array( $status_key, array( 'in_transit', 'out_for_delivery', 'delivered', 'exception' ), true ) ) {
                            continue; // Hide zero-count non-essential statuses.
                        }
                        ?>
                        <div style="padding: 10px; border-bottom: 1px solid #eee;">
                            <strong><?php echo esc_html( $status_label ); ?>:</strong>
                            <span style="display: block; font-size: 24px; color: #0073aa; font-weight: 700; margin-top: 5px;">
                                <?php echo esc_html( $count ); ?>
                            </span>
                        </div>
                        <?php
                    }
                ?>
            </div>
        </div>

        <div class="fst-analytics-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
            <h3><?php esc_html_e( 'Key Metrics', 'fishotel-shiptracker' ); ?></h3>

            <div style="padding: 15px;">
                <div style="margin-bottom: 15px;">
                    <strong><?php esc_html_e( 'Total Shipments', 'fishotel-shiptracker' ); ?>:</strong>
                    <span style="display: block; font-size: 24px; color: #0073aa; font-weight: 700; margin-top: 5px;">
                        <?php echo esc_html( $total ); ?>
                    </span>
                </div>

                <div style="margin-bottom: 15px;">
                    <strong><?php esc_html_e( 'Late Shipments', 'fishotel-shiptracker' ); ?>:</strong>
                    <span style="display: block; font-size: 24px; color: <?php echo $late_count > 0 ? '#dc3545' : '#0073aa'; ?>; font-weight: 700; margin-top: 5px;">
                        <?php echo esc_html( $late_count ); ?>
                    </span>
                </div>

                <div>
                    <strong><?php esc_html_e( 'Delivered', 'fishotel-shiptracker' ); ?>:</strong>
                    <span style="display: block; font-size: 24px; color: #0073aa; font-weight: 700; margin-top: 5px;">
                        <?php echo esc_html( $delivered ); ?>
                    </span>
                </div>
            </div>
        </div>

        <div class="fst-analytics-card" style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
            <h3><?php esc_html_e( 'Delivery Success Rate', 'fishotel-shiptracker' ); ?></h3>

            <div style="padding: 15px;">
                <?php
                    $success_rate = $total > 0 ? round( ( $delivered / $total ) * 100 ) : 0;
                ?>

                <div style="font-size: 48px; color: #1e7e34; font-weight: 700; text-align: center; margin-bottom: 10px;">
                    <?php echo esc_html( $success_rate ); ?>%
                </div>

                <div style="text-align: center; color: #666;">
                    <?php echo esc_html( $delivered ); ?> / <?php echo esc_html( $total ); ?> <?php esc_html_e( 'delivered', 'fishotel-shiptracker' ); ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ( $late_count > 0 ) : ?>
    <!-- Late Shipments -->
    <div style="margin-top: 30px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px;">
        <h3 style="color: #dc3545;"><?php esc_html_e( 'Late Shipments', 'fishotel-shiptracker' ); ?></h3>
        <table class="widefat striped" style="margin-top: 10px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Order', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Tracking #', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Carrier', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Est. Delivery', 'fishotel-shiptracker' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $late_shipments as $ls ) : ?>
                <tr>
                    <td>
                        <?php
                            $order = wc_get_order( $ls->order_id );
                            if ( $order ) {
                                echo '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>';
                            } else {
                                echo '#' . esc_html( $ls->order_id );
                            }
                        ?>
                    </td>
                    <td><code><?php echo esc_html( $ls->tracking_number ); ?></code></td>
                    <td><?php echo esc_html( strtoupper( $ls->carrier ) ); ?></td>
                    <td><?php echo esc_html( FST_Carrier::get_status_label( $ls->status ) ); ?></td>
                    <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $ls->est_delivery ) ) ); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- Upcoming Features -->
    <div style="margin-top: 30px; padding: 20px; background-color: #f9f9f9; border-radius: 3px;">
        <h3><?php esc_html_e( 'Coming Soon', 'fishotel-shiptracker' ); ?></h3>
        <ul style="margin-left: 20px;">
            <li><?php esc_html_e( 'Charts and graphs for trend analysis', 'fishotel-shiptracker' ); ?></li>
            <li><?php esc_html_e( 'Carrier performance comparison', 'fishotel-shiptracker' ); ?></li>
            <li><?php esc_html_e( 'Average delivery time metrics', 'fishotel-shiptracker' ); ?></li>
            <li><?php esc_html_e( 'Export reports to CSV/PDF', 'fishotel-shiptracker' ); ?></li>
            <li><?php esc_html_e( 'Custom date range filtering', 'fishotel-shiptracker' ); ?></li>
        </ul>
    </div>
</div>
