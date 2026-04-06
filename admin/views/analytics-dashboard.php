<?php
/**
 * FisHotel ShipTracker - Analytics Dashboard
 *
 * @package FisHotel_ShipTracker
 * @subpackage Admin
 */

defined( 'ABSPATH' ) || exit;

// Handle date range selection.
$range_preset = isset( $_GET['fst_range'] ) ? sanitize_text_field( $_GET['fst_range'] ) : '30d';
$date_from    = isset( $_GET['fst_from'] ) ? sanitize_text_field( $_GET['fst_from'] ) : '';
$date_to      = isset( $_GET['fst_to'] ) ? sanitize_text_field( $_GET['fst_to'] ) : '';

// Calculate dates from preset if not custom.
$today = current_time( 'Y-m-d' );
if ( 'custom' !== $range_preset ) {
    $date_to = $today;
    switch ( $range_preset ) {
        case '7d':
            $date_from = date( 'Y-m-d', strtotime( '-7 days', current_time( 'timestamp' ) ) );
            break;
        case '30d':
            $date_from = date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) );
            break;
        case '90d':
            $date_from = date( 'Y-m-d', strtotime( '-90 days', current_time( 'timestamp' ) ) );
            break;
        case '6m':
            $date_from = date( 'Y-m-d', strtotime( '-6 months', current_time( 'timestamp' ) ) );
            break;
        case '1y':
            $date_from = date( 'Y-m-d', strtotime( '-1 year', current_time( 'timestamp' ) ) );
            break;
        case 'all':
            $date_from = '';
            $date_to   = '';
            break;
        default:
            $date_from = date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) );
            break;
    }
}

// Validate custom dates.
if ( 'custom' === $range_preset ) {
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
        $date_from = date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) );
    }
    if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
        $date_to = $today;
    }
}

// Gather analytics data with date range and unknown excluded.
$status_counts    = FST_Shipment::count_by_status( $date_from, $date_to );
$carrier_counts   = FST_Shipment::count_by_carrier( $date_from, $date_to );
$late_shipments   = FST_Shipment::get_late_shipments();
$late_count       = count( $late_shipments );
$total            = array_sum( $status_counts );
$delivered        = isset( $status_counts['delivered'] ) ? $status_counts['delivered'] : 0;
$success_rate     = $total > 0 ? round( ( $delivered / $total ) * 100 ) : 0;
$avg_days         = FST_Shipment::avg_delivery_days( $date_from, $date_to );
$avg_by_carrier   = FST_Shipment::avg_delivery_days_by_carrier( $date_from, $date_to );
$shipments_range  = FST_Shipment::count_per_day( $date_from, $date_to );
$deliveries_range = FST_Shipment::deliveries_per_day( $date_from, $date_to );

// Build daily data for the chart.
$chart_from = $date_from ? $date_from : null;
$chart_to   = $date_to ? $date_to : $today;

// If no start date (all time), find the earliest shipment date from results.
if ( ! $chart_from && ! empty( $shipments_range ) ) {
    $chart_from = $shipments_range[0]->date;
}
if ( ! $chart_from ) {
    $chart_from = date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) );
}

$chart_dates     = array();
$chart_shipped   = array();
$chart_delivered = array();
$max_daily       = 1;

$shipped_by_date   = array();
$delivered_by_date = array();
foreach ( $shipments_range as $row ) {
    $shipped_by_date[ $row->date ] = (int) $row->count;
}
foreach ( $deliveries_range as $row ) {
    $delivered_by_date[ $row->date ] = (int) $row->count;
}

// Calculate number of days in range.
$range_days = max( 1, (int) ceil( ( strtotime( $chart_to ) - strtotime( $chart_from ) ) / DAY_IN_SECONDS ) + 1 );

// For very long ranges, aggregate into buckets to keep the chart readable.
$max_bars    = 60;
$bucket_size = max( 1, (int) ceil( $range_days / $max_bars ) );
$bar_count   = (int) ceil( $range_days / $bucket_size );

for ( $b = 0; $b < $bar_count; $b++ ) {
    $bucket_offset = $b * $bucket_size;
    $bucket_start  = date( 'Y-m-d', strtotime( "+{$bucket_offset} days", strtotime( $chart_from ) ) );
    $s_total = 0;
    $d_total = 0;

    for ( $d = 0; $d < $bucket_size; $d++ ) {
        $day_offset = $bucket_offset + $d;
        $date = date( 'Y-m-d', strtotime( "+{$day_offset} days", strtotime( $chart_from ) ) );
        if ( $date > $chart_to ) break;
        $s_total += isset( $shipped_by_date[ $date ] ) ? $shipped_by_date[ $date ] : 0;
        $d_total += isset( $delivered_by_date[ $date ] ) ? $delivered_by_date[ $date ] : 0;
    }

    if ( $bucket_size > 1 ) {
        $bucket_end = date( 'Y-m-d', strtotime( '+' . ( $bucket_offset + $bucket_size - 1 ) . ' days', strtotime( $chart_from ) ) );
        if ( $bucket_end > $chart_to ) $bucket_end = $chart_to;
        $chart_dates[] = date( 'M j', strtotime( $bucket_start ) ) . '-' . date( 'j', strtotime( $bucket_end ) );
    } else {
        $chart_dates[] = date( 'M j', strtotime( $bucket_start ) );
    }

    $chart_shipped[]   = $s_total;
    $chart_delivered[] = $d_total;
    $max_daily = max( $max_daily, $s_total, $d_total );
}

// Build range label for display.
if ( $date_from && $date_to ) {
    $range_label = date_i18n( get_option( 'date_format' ), strtotime( $date_from ) ) . ' &ndash; ' . date_i18n( get_option( 'date_format' ), strtotime( $date_to ) );
} else {
    $range_label = __( 'All Time', 'fishotel-shiptracker' );
}

// Current page URL for form action.
$page_url = admin_url( 'admin.php?page=fst-analytics' );

?>

<div class="wrap">
    <h1><?php esc_html_e( 'Analytics', 'fishotel-shiptracker' ); ?></h1>

    <!-- Date Range Selector -->
    <div style="background: #fff; padding: 14px 20px; border: 1px solid #ccd0d4; border-radius: 6px; margin: 16px 0; display: flex; align-items: center; flex-wrap: wrap; gap: 10px;">
        <span style="font-weight: 600; font-size: 13px; color: #333; margin-right: 4px;"><?php esc_html_e( 'Date Range:', 'fishotel-shiptracker' ); ?></span>

        <?php
        $presets = array(
            '7d'  => __( '7 Days', 'fishotel-shiptracker' ),
            '30d' => __( '30 Days', 'fishotel-shiptracker' ),
            '90d' => __( '90 Days', 'fishotel-shiptracker' ),
            '6m'  => __( '6 Months', 'fishotel-shiptracker' ),
            '1y'  => __( '1 Year', 'fishotel-shiptracker' ),
            'all' => __( 'All Time', 'fishotel-shiptracker' ),
        );
        foreach ( $presets as $pkey => $plabel ) :
            $active = ( $range_preset === $pkey );
        ?>
            <a href="<?php echo esc_url( add_query_arg( array( 'fst_range' => $pkey ), $page_url ) ); ?>"
               style="padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 13px; font-weight: 500;
                      <?php echo $active
                          ? 'background: #0073aa; color: #fff;'
                          : 'background: #f0f0f0; color: #333;'; ?>">
                <?php echo esc_html( $plabel ); ?>
            </a>
        <?php endforeach; ?>

        <span style="color: #ccc; margin: 0 4px;">|</span>

        <form method="get" action="<?php echo esc_url( $page_url ); ?>" style="display: flex; align-items: center; gap: 6px; margin: 0;">
            <input type="hidden" name="page" value="fishotel-shiptracker-analytics" />
            <input type="hidden" name="fst_range" value="custom" />
            <input type="date" name="fst_from" value="<?php echo esc_attr( $date_from ); ?>"
                   style="padding: 4px 8px; border: 1px solid #ccd0d4; border-radius: 4px; font-size: 13px;" />
            <span style="color: #666;">&ndash;</span>
            <input type="date" name="fst_to" value="<?php echo esc_attr( $date_to ); ?>"
                   style="padding: 4px 8px; border: 1px solid #ccd0d4; border-radius: 4px; font-size: 13px;" />
            <button type="submit" class="button button-small" style="padding: 4px 12px;"><?php esc_html_e( 'Apply', 'fishotel-shiptracker' ); ?></button>
        </form>
    </div>

    <p style="color: #666; font-size: 12px; margin: -8px 0 16px;">
        <?php echo wp_kses( $range_label, array( 'span' => array() ) ); ?>
        &mdash; <?php esc_html_e( 'shipments with "Unknown" or "Label Created" status are excluded from all stats.', 'fishotel-shiptracker' ); ?>
    </p>

    <!-- Top KPI Cards -->
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px; margin: 20px 0;">
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px; text-align: center;">
            <div style="font-size: 13px; color: #666; margin-bottom: 6px;"><?php esc_html_e( 'Total Shipments', 'fishotel-shiptracker' ); ?></div>
            <div style="font-size: 36px; font-weight: 700; color: #0073aa;"><?php echo esc_html( $total ); ?></div>
        </div>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px; text-align: center;">
            <div style="font-size: 13px; color: #666; margin-bottom: 6px;"><?php esc_html_e( 'Delivered', 'fishotel-shiptracker' ); ?></div>
            <div style="font-size: 36px; font-weight: 700; color: #1e7e34;"><?php echo esc_html( $delivered ); ?></div>
        </div>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px; text-align: center;">
            <div style="font-size: 13px; color: #666; margin-bottom: 6px;"><?php esc_html_e( 'Success Rate', 'fishotel-shiptracker' ); ?></div>
            <div style="font-size: 36px; font-weight: 700; color: #1e7e34;"><?php echo esc_html( $success_rate ); ?>%</div>
        </div>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px; text-align: center;">
            <div style="font-size: 13px; color: #666; margin-bottom: 6px;"><?php esc_html_e( 'Avg. Delivery Time', 'fishotel-shiptracker' ); ?></div>
            <div style="font-size: 36px; font-weight: 700; color: #0073aa;">
                <?php echo null !== $avg_days ? esc_html( $avg_days . 'd' ) : '&mdash;'; ?>
            </div>
        </div>
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px; text-align: center;">
            <div style="font-size: 13px; color: #666; margin-bottom: 6px;"><?php esc_html_e( 'Late Shipments', 'fishotel-shiptracker' ); ?></div>
            <div style="font-size: 36px; font-weight: 700; color: <?php echo $late_count > 0 ? '#dc3545' : '#1e7e34'; ?>;">
                <?php echo esc_html( $late_count ); ?>
            </div>
        </div>
    </div>

    <!-- Charts Row -->
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 20px;">

        <!-- Shipment Volume Chart -->
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px;">
            <h3 style="margin: 0 0 16px;"><?php esc_html_e( 'Shipment Volume', 'fishotel-shiptracker' ); ?></h3>
            <?php if ( array_sum( $chart_shipped ) > 0 || array_sum( $chart_delivered ) > 0 ) : ?>
                <div style="display: flex; gap: 16px; font-size: 12px; margin-bottom: 10px;">
                    <span><span style="display: inline-block; width: 12px; height: 12px; background: #0073aa; border-radius: 2px; margin-right: 4px;"></span><?php esc_html_e( 'Created', 'fishotel-shiptracker' ); ?></span>
                    <span><span style="display: inline-block; width: 12px; height: 12px; background: #1e7e34; border-radius: 2px; margin-right: 4px;"></span><?php esc_html_e( 'Delivered', 'fishotel-shiptracker' ); ?></span>
                </div>
                <div style="display: flex; align-items: flex-end; gap: 2px; height: 160px; border-bottom: 1px solid #eee; padding-bottom: 4px;">
                    <?php for ( $i = 0; $i < count( $chart_shipped ); $i++ ) :
                        $s_pct = $max_daily > 0 ? ( $chart_shipped[ $i ] / $max_daily ) * 100 : 0;
                        $d_pct = $max_daily > 0 ? ( $chart_delivered[ $i ] / $max_daily ) * 100 : 0;
                    ?>
                        <div style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 1px;" title="<?php echo esc_attr( $chart_dates[ $i ] . ': ' . $chart_shipped[ $i ] . ' created, ' . $chart_delivered[ $i ] . ' delivered' ); ?>">
                            <div style="width: 100%; display: flex; align-items: flex-end; gap: 1px; height: 100%;">
                                <div style="flex: 1; background: #0073aa; border-radius: 1px 1px 0 0; min-height: <?php echo $chart_shipped[ $i ] > 0 ? '2' : '0'; ?>px; height: <?php echo esc_attr( $s_pct ); ?>%;"></div>
                                <div style="flex: 1; background: #1e7e34; border-radius: 1px 1px 0 0; min-height: <?php echo $chart_delivered[ $i ] > 0 ? '2' : '0'; ?>px; height: <?php echo esc_attr( $d_pct ); ?>%;"></div>
                            </div>
                        </div>
                    <?php endfor; ?>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 10px; color: #999; margin-top: 4px;">
                    <?php
                    $label_count = count( $chart_dates );
                    if ( $label_count > 0 ) {
                        echo '<span>' . esc_html( $chart_dates[0] ) . '</span>';
                        if ( $label_count > 2 ) {
                            $mid = (int) floor( $label_count / 2 );
                            echo '<span>' . esc_html( $chart_dates[ $mid ] ) . '</span>';
                        }
                        if ( $label_count > 1 ) {
                            echo '<span>' . esc_html( $chart_dates[ $label_count - 1 ] ) . '</span>';
                        }
                    }
                    ?>
                </div>
            <?php else : ?>
                <p style="color: #999; text-align: center; padding: 40px 0;"><?php esc_html_e( 'No shipment data in this date range.', 'fishotel-shiptracker' ); ?></p>
            <?php endif; ?>
        </div>

        <!-- Status Breakdown -->
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px;">
            <h3 style="margin: 0 0 16px;"><?php esc_html_e( 'Status Breakdown', 'fishotel-shiptracker' ); ?></h3>
            <?php if ( $total > 0 ) : ?>
                <?php
                $statuses = array(
                    'delivered'        => 'Delivered',
                    'in_transit'       => 'In Transit',
                    'out_for_delivery' => 'Out for Delivery',
                    'pre_transit'      => 'Pre-Transit',
                    'exception'        => 'Exception',
                    'return_to_sender' => 'Return to Sender',
                    'failure'          => 'Failure',
                );
                foreach ( $statuses as $skey => $slabel ) :
                    $scount = isset( $status_counts[ $skey ] ) ? $status_counts[ $skey ] : 0;
                    if ( 0 === $scount ) continue;
                    $pct   = round( ( $scount / $total ) * 100 );
                    $color = FST_Carrier::get_status_color( $skey );
                ?>
                    <div style="margin-bottom: 10px;">
                        <div style="display: flex; justify-content: space-between; font-size: 13px; margin-bottom: 3px;">
                            <span><?php echo esc_html( $slabel ); ?></span>
                            <span style="font-weight: 600;"><?php echo esc_html( $scount ); ?> (<?php echo esc_html( $pct ); ?>%)</span>
                        </div>
                        <div style="background: #f0f0f0; border-radius: 3px; height: 8px; overflow: hidden;">
                            <div style="background: <?php echo esc_attr( $color ); ?>; height: 100%; width: <?php echo esc_attr( $pct ); ?>%; border-radius: 3px;"></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <p style="color: #999; text-align: center; padding: 40px 0;"><?php esc_html_e( 'No shipments yet.', 'fishotel-shiptracker' ); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Carrier Performance -->
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">

        <!-- Carrier Volume -->
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px;">
            <h3 style="margin: 0 0 16px;"><?php esc_html_e( 'Carrier Volume', 'fishotel-shiptracker' ); ?></h3>
            <?php if ( ! empty( $carrier_counts ) ) : ?>
                <?php foreach ( $carrier_counts as $cname => $ccount ) :
                    $cpct = $total > 0 ? round( ( $ccount / $total ) * 100 ) : 0;
                ?>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                        <div style="font-weight: 700; font-size: 14px; min-width: 50px;"><?php echo esc_html( strtoupper( $cname ) ); ?></div>
                        <div style="flex: 1; background: #f0f0f0; border-radius: 3px; height: 24px; overflow: hidden;">
                            <div style="background: #0073aa; height: 100%; width: <?php echo esc_attr( $cpct ); ?>%; border-radius: 3px; display: flex; align-items: center; padding-left: 8px;">
                                <?php if ( $cpct > 15 ) : ?>
                                    <span style="color: #fff; font-size: 12px; font-weight: 600;"><?php echo esc_html( $ccount ); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ( $cpct <= 15 ) : ?>
                            <span style="font-size: 13px; font-weight: 600;"><?php echo esc_html( $ccount ); ?></span>
                        <?php endif; ?>
                        <span style="font-size: 12px; color: #666;">(<?php echo esc_html( $cpct ); ?>%)</span>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <p style="color: #999;"><?php esc_html_e( 'No data yet.', 'fishotel-shiptracker' ); ?></p>
            <?php endif; ?>
        </div>

        <!-- Avg Delivery Time by Carrier -->
        <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px;">
            <h3 style="margin: 0 0 16px;"><?php esc_html_e( 'Avg. Delivery Time by Carrier', 'fishotel-shiptracker' ); ?></h3>
            <?php if ( ! empty( $avg_by_carrier ) ) : ?>
                <?php
                $max_avg = max( array_column( $avg_by_carrier, 'avg_days' ) );
                foreach ( $avg_by_carrier as $cname => $cdata ) :
                    $bar_pct = $max_avg > 0 ? round( ( $cdata['avg_days'] / $max_avg ) * 100 ) : 0;
                ?>
                    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 12px;">
                        <div style="font-weight: 700; font-size: 14px; min-width: 50px;"><?php echo esc_html( strtoupper( $cname ) ); ?></div>
                        <div style="flex: 1; background: #f0f0f0; border-radius: 3px; height: 24px; overflow: hidden;">
                            <div style="background: #ff9800; height: 100%; width: <?php echo esc_attr( $bar_pct ); ?>%; border-radius: 3px; display: flex; align-items: center; padding-left: 8px;">
                                <?php if ( $bar_pct > 20 ) : ?>
                                    <span style="color: #fff; font-size: 12px; font-weight: 600;"><?php echo esc_html( $cdata['avg_days'] ); ?>d</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ( $bar_pct <= 20 ) : ?>
                            <span style="font-size: 13px; font-weight: 600;"><?php echo esc_html( $cdata['avg_days'] ); ?>d</span>
                        <?php endif; ?>
                        <span style="font-size: 12px; color: #666;">(<?php echo esc_html( $cdata['count'] ); ?> shipments)</span>
                    </div>
                <?php endforeach; ?>
            <?php else : ?>
                <p style="color: #999;"><?php esc_html_e( 'No delivered shipments with date data yet.', 'fishotel-shiptracker' ); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <?php if ( $late_count > 0 ) : ?>
    <!-- Late Shipments -->
    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-left: 4px solid #dc3545; border-radius: 6px; margin-bottom: 20px;">
        <h3 style="color: #dc3545; margin: 0 0 12px;">
            <?php esc_html_e( 'Late Shipments', 'fishotel-shiptracker' ); ?>
            <span style="background: #dc3545; color: #fff; font-size: 12px; padding: 2px 8px; border-radius: 10px; margin-left: 8px;"><?php echo esc_html( $late_count ); ?></span>
        </h3>
        <table class="widefat striped" style="margin-top: 10px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Order', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Tracking #', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Carrier', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Status', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Est. Delivery', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Days Late', 'fishotel-shiptracker' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $late_shipments as $ls ) :
                    $days_late = floor( ( current_time( 'timestamp' ) - strtotime( $ls->est_delivery ) ) / DAY_IN_SECONDS );
                ?>
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
                    <td>
                        <span style="background-color: <?php echo esc_attr( FST_Carrier::get_status_color( $ls->status ) ); ?>; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px;">
                            <?php echo esc_html( FST_Carrier::get_status_label( $ls->status ) ); ?>
                        </span>
                    </td>
                    <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $ls->est_delivery ) ) ); ?></td>
                    <td style="color: #dc3545; font-weight: 600;"><?php echo esc_html( $days_late ); ?>d</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
