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

// Box costs: what the boxes in this range cost — label + materials — against
// what customers paid, plus the supplies figures of the range.
$cost_report  = FST_Label_Cost::get_report( $date_from, $date_to );
$cost_summary = $cost_report['summary'];
$cost_parts   = array(
    'label' => __( 'Label', 'fishotel-shiptracker' ),
    'boxes' => __( 'Boxes', 'fishotel-shiptracker' ),
    'foam'  => __( 'Foam', 'fishotel-shiptracker' ),
    'paper' => __( 'Paper', 'fishotel-shiptracker' ),
    'bags'  => __( 'Bags', 'fishotel-shiptracker' ),
    'packs' => __( 'Packs', 'fishotel-shiptracker' ),
    'other' => __( 'Other', 'fishotel-shiptracker' ),
);

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
            <a href="<?php echo esc_url( $page_url . '&fst_range=' . $pkey ); ?>"
               style="padding: 6px 14px; border-radius: 4px; text-decoration: none; font-size: 13px; font-weight: 500;
                      <?php echo $active
                          ? 'background: #0073aa; color: #fff;'
                          : 'background: #f0f0f0; color: #333;'; ?>">
                <?php echo esc_html( $plabel ); ?>
            </a>
        <?php endforeach; ?>

        <span style="color: #ccc; margin: 0 4px;">|</span>

        <form method="get" action="<?php echo esc_url( $page_url ); ?>" style="display: flex; align-items: center; gap: 6px; margin: 0;">
            <input type="hidden" name="page" value="fst-analytics" />
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
            <?php if ( array_sum( $chart_shipped ) > 0 || array_sum( $chart_delivered ) > 0 ) :
                // Calculate nice Y-axis ticks that fit the actual data.
                // Pick from clean step sizes so the axis always has round numbers.
                $y_max       = $max_daily;
                $nice_steps  = array( 1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500, 1000 );
                $y_step      = 1;
                foreach ( $nice_steps as $ns ) {
                    if ( ceil( $y_max / $ns ) <= 6 ) {
                        $y_step = $ns;
                        break;
                    }
                }
                $y_top = (int) ceil( $y_max / $y_step ) * $y_step;
                if ( $y_top < 1 ) $y_top = 1;
                $y_ticks = array();
                for ( $t = $y_top; $t >= 0; $t -= $y_step ) {
                    $y_ticks[] = $t;
                }
            ?>
                <div style="display: flex; gap: 16px; font-size: 12px; margin-bottom: 10px;">
                    <span><span style="display: inline-block; width: 12px; height: 12px; background: #0073aa; border-radius: 2px; margin-right: 4px;"></span><?php esc_html_e( 'Shipped', 'fishotel-shiptracker' ); ?></span>
                    <span><span style="display: inline-block; width: 12px; height: 12px; background: #1e7e34; border-radius: 2px; margin-right: 4px;"></span><?php esc_html_e( 'Delivered', 'fishotel-shiptracker' ); ?></span>
                </div>
                <div style="display: flex; height: 220px;">
                    <!-- Y-axis labels -->
                    <div style="display: flex; flex-direction: column; justify-content: space-between; padding-right: 8px; padding-bottom: 20px;">
                        <?php foreach ( $y_ticks as $tick ) : ?>
                            <span style="font-size: 11px; color: #999; text-align: right; min-width: 24px; line-height: 1;"><?php echo esc_html( $tick ); ?></span>
                        <?php endforeach; ?>
                    </div>
                    <!-- Chart area -->
                    <div style="flex: 1; display: flex; flex-direction: column;">
                        <div style="flex: 1; position: relative; border-left: 1px solid #ddd; border-bottom: 1px solid #ddd;">
                            <!-- Gridlines -->
                            <?php foreach ( $y_ticks as $idx => $tick ) :
                                $line_pct = $y_top > 0 ? ( $tick / $y_top ) * 100 : 0;
                            ?>
                                <div style="position: absolute; left: 0; right: 0; bottom: <?php echo esc_attr( $line_pct ); ?>%; border-top: 1px solid #f0f0f0; z-index: 0;"></div>
                            <?php endforeach; ?>
                            <!-- Bars -->
                            <div style="position: absolute; left: 0; right: 0; bottom: 0; top: 0; display: flex; align-items: flex-end; gap: 3px; padding: 0 4px; z-index: 1;">
                                <?php for ( $i = 0; $i < count( $chart_shipped ); $i++ ) :
                                    $s_pct = $y_top > 0 ? ( $chart_shipped[ $i ] / $y_top ) * 100 : 0;
                                    $d_pct = $y_top > 0 ? ( $chart_delivered[ $i ] / $y_top ) * 100 : 0;
                                ?>
                                    <div style="flex: 1; display: flex; gap: 1px; align-items: flex-end; height: 100%;"
                                         title="<?php echo esc_attr( $chart_dates[ $i ] . ': ' . $chart_shipped[ $i ] . ' shipped, ' . $chart_delivered[ $i ] . ' delivered' ); ?>">
                                        <!-- Created bar -->
                                        <div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%;">
                                            <?php if ( $chart_shipped[ $i ] > 0 ) : ?>
                                                <span style="font-size: 9px; color: #0073aa; font-weight: 600; margin-bottom: 1px;"><?php echo esc_html( $chart_shipped[ $i ] ); ?></span>
                                            <?php endif; ?>
                                            <div style="width: 100%; background: #0073aa; border-radius: 2px 2px 0 0; min-height: <?php echo $chart_shipped[ $i ] > 0 ? '4' : '0'; ?>px; height: <?php echo esc_attr( $s_pct ); ?>%;"></div>
                                        </div>
                                        <!-- Delivered bar -->
                                        <div style="flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end; height: 100%;">
                                            <?php if ( $chart_delivered[ $i ] > 0 ) : ?>
                                                <span style="font-size: 9px; color: #1e7e34; font-weight: 600; margin-bottom: 1px;"><?php echo esc_html( $chart_delivered[ $i ] ); ?></span>
                                            <?php endif; ?>
                                            <div style="width: 100%; background: #1e7e34; border-radius: 2px 2px 0 0; min-height: <?php echo $chart_delivered[ $i ] > 0 ? '4' : '0'; ?>px; height: <?php echo esc_attr( $d_pct ); ?>%;"></div>
                                        </div>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                        <!-- X-axis dates -->
                        <div style="display: flex; justify-content: space-between; font-size: 10px; color: #999; margin-top: 4px; padding-left: 4px; padding-right: 4px;">
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
                    </div>
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

    <!-- Box Costs: label + materials -->
    <?php
    // Money cell: wc_price() markup, red when negative.
    $fst_money = function ( $amount, $signed = false ) {
        if ( null === $amount ) {
            return '&mdash;';
        }
        $html = wp_kses_post( wc_price( (float) $amount ) );
        if ( $signed ) {
            $html = '<span style="font-weight: 600; color: ' . ( (float) $amount < 0 ? '#dc3545' : '#1e7e34' ) . ';">' . $html . '</span>';
        }
        return $html;
    };
    ?>
    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px; margin-bottom: 20px;">
        <h3 style="margin: 0 0 6px;"><?php esc_html_e( 'Box Costs', 'fishotel-shiptracker' ); ?></h3>
        <p style="color: #666; font-size: 12px; margin: 0 0 4px; max-width: 900px;">
            <?php esc_html_e( 'What the boxes cost you — the label plus the materials that went into them — against what customers paid for shipping, for boxes shipped in this date range (the last day included). "Shipping paid" is the order\'s shipping total before tax, less any shipping refunded. A box shared by several orders counts the shipping of all of them, an order that went in another order\'s box included; an order that went out in several boxes has its shipping split evenly between them, so it is counted once.', 'fishotel-shiptracker' ); ?>
        </p>
        <p style="font-size: 13px; margin: 0 0 16px; max-width: 900px;">
            <strong>
                <?php
                printf(
                    /* translators: %d: number of boxes */
                    esc_html__( 'Boxes without a recorded label cost are left out of every total, average and difference below — %d in this range.', 'fishotel-shiptracker' ),
                    (int) $cost_summary['boxes_without_cost']
                );
                ?>
            </strong>
            <?php if ( $cost_summary['boxes_without_cost'] > 0 ) : ?>
                <?php
                echo wp_kses_post( sprintf(
                    /* translators: %s: amount customers paid for shipping */
                    __( 'Customers paid %s shipping for those boxes. Add the costs on the orders, or import them under Settings &rarr; Boxes &amp; label costs.', 'fishotel-shiptracker' ),
                    wc_price( (float) $cost_summary['paid_without_cost'] )
                ) );
                ?>
                <?php if ( (float) $cost_report['materials']['without_label_cost'] > 0 ) : ?>
                    <?php
                    echo wp_kses_post( sprintf(
                        /* translators: %s: materials recorded on boxes without a label cost */
                        __( 'Their materials (%s) are left out here too, but are counted under Supplies below.', 'fishotel-shiptracker' ),
                        wc_price( (float) $cost_report['materials']['without_label_cost'] )
                    ) );
                    ?>
                <?php endif; ?>
            <?php endif; ?>
        </p>

        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px; margin-bottom: 20px;">
            <div style="padding: 14px; border: 1px solid #eee; border-radius: 6px; text-align: center; background: #f9f9f9;">
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Boxes Shipped', 'fishotel-shiptracker' ); ?></div>
                <div style="font-size: 24px; font-weight: 700; color: #0073aa;"><?php echo esc_html( $cost_summary['boxes'] ); ?></div>
            </div>
            <div style="padding: 14px; border: 1px solid #eee; border-radius: 6px; text-align: center; background: #f9f9f9;">
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'With a Recorded Cost', 'fishotel-shiptracker' ); ?></div>
                <div style="font-size: 24px; font-weight: 700; color: #1e7e34;"><?php echo esc_html( $cost_summary['boxes_with_cost'] ); ?></div>
            </div>
            <div style="padding: 14px; border: 1px solid #eee; border-radius: 6px; text-align: center; background: #f9f9f9;">
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Without a Cost', 'fishotel-shiptracker' ); ?></div>
                <div style="font-size: 24px; font-weight: 700; color: <?php echo $cost_summary['boxes_without_cost'] > 0 ? '#b26b00' : '#1e7e34'; ?>;"><?php echo esc_html( $cost_summary['boxes_without_cost'] ); ?></div>
            </div>
            <div style="padding: 14px; border: 1px solid #eee; border-radius: 6px; text-align: center; background: #f9f9f9;">
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Total Cost (Label + Materials)', 'fishotel-shiptracker' ); ?></div>
                <div style="font-size: 24px; font-weight: 700; color: #333;"><?php echo $fst_money( $cost_summary['total_cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></div>
            </div>
            <div style="padding: 14px; border: 1px solid #eee; border-radius: 6px; text-align: center; background: #f9f9f9;">
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Shipping Customers Paid', 'fishotel-shiptracker' ); ?></div>
                <div style="font-size: 24px; font-weight: 700; color: #333;"><?php echo $fst_money( $cost_summary['total_paid'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></div>
            </div>
            <div style="padding: 14px; border: 1px solid #eee; border-radius: 6px; text-align: center; background: #f9f9f9;">
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Difference (Paid − Cost)', 'fishotel-shiptracker' ); ?></div>
                <div style="font-size: 24px;"><?php echo $fst_money( $cost_summary['difference'], true ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></div>
            </div>
            <div style="padding: 14px; border: 1px solid #eee; border-radius: 6px; text-align: center; background: #f9f9f9;">
                <div style="font-size: 12px; color: #666; margin-bottom: 4px;"><?php esc_html_e( 'Avg. Cost per Box', 'fishotel-shiptracker' ); ?></div>
                <div style="font-size: 24px; font-weight: 700; color: #333;"><?php echo $fst_money( $cost_summary['avg_cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></div>
            </div>
        </div>

        <?php if ( $cost_summary['boxes_with_cost'] > 0 ) : ?>
            <!-- What the cost is made of: in total, by box size and by label source -->
            <h4 style="margin: 0 0 4px;"><?php esc_html_e( 'What the Cost Is Made Of', 'fishotel-shiptracker' ); ?></h4>
            <p style="color: #666; font-size: 12px; margin: 0 0 8px;">
                <?php
                printf(
                    /* translators: 1: boxes with materials recorded, 2: boxes shipped */
                    esc_html__( 'Label plus materials, as recorded on each box (%1$d of the %2$d boxes shipped have materials recorded; a box without any adds only its label). Packs are heat packs and ice packs.', 'fishotel-shiptracker' ),
                    (int) $cost_report['materials']['boxes_with_materials'],
                    (int) $cost_summary['boxes']
                );
                ?>
            </p>
            <table class="widefat striped" style="margin-bottom: 20px;">
                <thead>
                    <tr>
                        <th></th>
                        <th><?php esc_html_e( 'Boxes', 'fishotel-shiptracker' ); ?></th>
                        <?php foreach ( $cost_parts as $part_label ) : ?>
                            <th><?php echo esc_html( $part_label ); ?></th>
                        <?php endforeach; ?>
                        <th><?php esc_html_e( 'Total', 'fishotel-shiptracker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $split_rows = array(
                        array( 'label' => __( 'All boxes', 'fishotel-shiptracker' ), 'boxes' => $cost_summary['boxes_with_cost'], 'split' => $cost_report['split'], 'strong' => true ),
                    );
                    foreach ( $cost_report['by_size'] as $size_row ) {
                        $split_rows[] = array( 'label' => $size_row['label'], 'boxes' => $size_row['boxes'], 'split' => $cost_report['split_by_size'][ $size_row['dims'] ], 'strong' => false );
                    }
                    foreach ( $cost_report['by_source'] as $source_row ) {
                        $split_rows[] = array(
                            /* translators: %s: label source */
                            'label'  => sprintf( __( 'Bought through: %s', 'fishotel-shiptracker' ), $source_row['label'] ),
                            'boxes'  => $source_row['boxes'],
                            'split'  => $cost_report['split_by_source'][ $source_row['source'] ],
                            'strong' => false,
                        );
                    }
                    foreach ( $split_rows as $split_row ) :
                        ?>
                        <tr<?php echo $split_row['strong'] ? ' style="font-weight: 600;"' : ''; ?>>
                            <td><?php echo esc_html( $split_row['label'] ); ?></td>
                            <td><?php echo esc_html( $split_row['boxes'] ); ?></td>
                            <?php foreach ( array_keys( $cost_parts ) as $part ) : ?>
                                <td><?php echo $fst_money( $split_row['split'][ $part ] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                            <?php endforeach; ?>
                            <td><?php echo $fst_money( $split_row['split']['total'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
                <!-- By box size -->
                <div>
                    <h4 style="margin: 0 0 8px;"><?php esc_html_e( 'By Box Size', 'fishotel-shiptracker' ); ?></h4>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Box', 'fishotel-shiptracker' ); ?></th>
                                <th><?php esc_html_e( 'Boxes', 'fishotel-shiptracker' ); ?></th>
                                <th><?php esc_html_e( 'Avg. Cost', 'fishotel-shiptracker' ); ?></th>
                                <th><?php esc_html_e( 'Avg. Shipping Paid', 'fishotel-shiptracker' ); ?></th>
                                <th><?php esc_html_e( 'Avg. Difference', 'fishotel-shiptracker' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $cost_report['by_size'] as $size_row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $size_row['label'] ); ?></td>
                                    <td><?php echo esc_html( $size_row['boxes'] ); ?></td>
                                    <td><?php echo $fst_money( $size_row['avg_cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                                    <td><?php echo $fst_money( $size_row['avg_paid'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                                    <td><?php echo $fst_money( $size_row['avg_diff'], true ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- By label source -->
                <div>
                    <h4 style="margin: 0 0 8px;"><?php esc_html_e( 'By Label Source', 'fishotel-shiptracker' ); ?></h4>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Bought Through', 'fishotel-shiptracker' ); ?></th>
                                <th><?php esc_html_e( 'Boxes', 'fishotel-shiptracker' ); ?></th>
                                <th><?php esc_html_e( 'Total Cost', 'fishotel-shiptracker' ); ?></th>
                                <th><?php esc_html_e( 'Avg. Cost', 'fishotel-shiptracker' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $cost_report['by_source'] as $source_row ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $source_row['label'] ); ?></td>
                                    <td><?php echo esc_html( $source_row['boxes'] ); ?></td>
                                    <td><?php echo $fst_money( $source_row['total_cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                                    <td><?php echo $fst_money( $source_row['avg_cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Average cost per box size, per source, side by side -->
            <h4 style="margin: 0 0 4px;"><?php esc_html_e( 'Average Cost per Box Size, by Label Source', 'fishotel-shiptracker' ); ?></h4>
            <p style="color: #666; font-size: 12px; margin: 0 0 8px;">
                <?php esc_html_e( 'The cheaper source for each box size is highlighted. The number in brackets is how many boxes the average is made of — weights and destinations differ, so a couple of boxes is only a hint.', 'fishotel-shiptracker' ); ?>
            </p>
            <table class="widefat striped" style="margin-bottom: 20px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Box', 'fishotel-shiptracker' ); ?></th>
                        <?php foreach ( $cost_report['size_by_source']['sources'] as $source_col ) : ?>
                            <th><?php echo esc_html( $source_col['label'] ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $cost_report['size_by_source']['rows'] as $matrix_row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $matrix_row['label'] ); ?></td>
                            <?php foreach ( $cost_report['size_by_source']['sources'] as $source_col ) :
                                $cell        = isset( $matrix_row['cells'][ $source_col['source'] ] ) ? $matrix_row['cells'][ $source_col['source'] ] : null;
                                $is_cheapest = null !== $matrix_row['cheapest'] && (string) $matrix_row['cheapest'] === $source_col['source'];
                            ?>
                                <td<?php echo $is_cheapest ? ' style="background: #e6f4ea; font-weight: 600;"' : ''; ?>>
                                    <?php if ( $cell ) : ?>
                                        <?php echo $fst_money( $cell['avg_cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?>
                                        <span style="color: #999; font-weight: 400;">(<?php echo esc_html( $cell['boxes'] ); ?>)</span>
                                        <?php if ( $is_cheapest ) : ?>
                                            <span style="color: #1e7e34; font-size: 11px;"><?php esc_html_e( 'cheaper', 'fishotel-shiptracker' ); ?></span>
                                        <?php endif; ?>
                                    <?php else : ?>
                                        <span style="color: #999;">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <!-- The boxes that lost the most -->
            <h4 style="margin: 0 0 8px;"><?php esc_html_e( 'The 20 Boxes That Lost the Most', 'fishotel-shiptracker' ); ?></h4>
            <?php if ( ! empty( $cost_report['losers'] ) ) : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Order', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Tracking #', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Box', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Bought Through', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Label Cost', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Materials', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Shipping Paid', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Difference', 'fishotel-shiptracker' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $cost_report['losers'] as $loser ) : ?>
                            <tr>
                                <td>
                                    <?php
                                    $order_links = array();
                                    foreach ( $loser['orders'] as $loser_order ) {
                                        if ( $loser_order['missing'] || '' === $loser_order['url'] ) {
                                            $order_links[] = '#' . esc_html( $loser_order['number'] ) . ' <span style="color: #999;">(' . esc_html__( 'order deleted', 'fishotel-shiptracker' ) . ')</span>';
                                        } else {
                                            $order_links[] = '<a href="' . esc_url( $loser_order['url'] ) . '">#' . esc_html( $loser_order['number'] ) . '</a>'
                                                . ( $loser_order['rider'] ? ' <span style="color: #999;">(' . esc_html__( 'added to this box', 'fishotel-shiptracker' ) . ')</span>' : '' );
                                        }
                                    }
                                    echo implode( ', ', $order_links ); // phpcs:ignore WordPress.Security.EscapeOutput -- each part escaped above.
                                    ?>
                                </td>
                                <td><code><?php echo esc_html( $loser['tracking_number'] ); ?></code></td>
                                <td><?php echo '' !== $loser['size_label'] ? esc_html( $loser['size_label'] ) : '&mdash;'; ?></td>
                                <td><?php echo null !== $loser['label_source'] ? esc_html( $loser['label_source'] ) : '&mdash;'; ?></td>
                                <td><?php echo $fst_money( $loser['label_cost'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                                <td><?php echo $fst_money( $loser['materials_total'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                                <td><?php echo $fst_money( $loser['paid'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                                <td><?php echo $fst_money( $loser['difference'], true ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else : ?>
                <p style="color: #1e7e34;"><?php esc_html_e( 'No box in this range cost more than its customers paid for shipping.', 'fishotel-shiptracker' ); ?></p>
            <?php endif; ?>
        <?php else : ?>
            <p style="color: #999; text-align: center; padding: 20px 0;"><?php esc_html_e( 'No label costs recorded for boxes in this date range yet.', 'fishotel-shiptracker' ); ?></p>
        <?php endif; ?>

        <!-- Free shipping, local pickups, orders in another order's box -->
        <h4 style="margin: 20px 0 8px;"><?php esc_html_e( 'Free Shipping, Local Pickups and Shared Boxes', 'fishotel-shiptracker' ); ?></h4>
        <table class="widefat striped" style="max-width: 900px;">
            <tbody>
                <tr>
                    <td style="width: 220px;"><strong><?php esc_html_e( 'Free shipping given', 'fishotel-shiptracker' ); ?></strong></td>
                    <td>
                        <?php
                        echo wp_kses_post( sprintf(
                            /* translators: 1: number of orders, 2: what their boxes cost */
                            _n(
                                '%1$d order shipped with a label and paid nothing for shipping. It cost you %2$s (label + materials).',
                                '%1$d orders shipped with a label and paid nothing for shipping. They cost you %2$s (label + materials).',
                                (int) $cost_report['free_shipping']['orders'],
                                'fishotel-shiptracker'
                            ),
                            (int) $cost_report['free_shipping']['orders'],
                            wc_price( (float) $cost_report['free_shipping']['cost'] )
                        ) );
                        if ( $cost_report['free_shipping']['orders_without_cost'] > 0 ) {
                            echo ' ';
                            printf(
                                /* translators: %d: number of orders */
                                esc_html__( '%d of them are in a box without a recorded label cost, which is not in that amount.', 'fishotel-shiptracker' ),
                                (int) $cost_report['free_shipping']['orders_without_cost']
                            );
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Local pickups', 'fishotel-shiptracker' ); ?></strong></td>
                    <td>
                        <?php
                        echo wp_kses_post( sprintf(
                            /* translators: 1: number of orders, 2: materials used */
                            _n(
                                '%1$d order was picked up — no label. Materials used: %2$s.',
                                '%1$d orders were picked up — no label. Materials used: %2$s.',
                                (int) $cost_report['local_pickups']['orders'],
                                'fishotel-shiptracker'
                            ),
                            (int) $cost_report['local_pickups']['orders'],
                            wc_price( (float) $cost_report['local_pickups']['materials'] )
                        ) );
                        ?>
                    </td>
                </tr>
                <tr>
                    <td><strong><?php esc_html_e( 'Went in another order\'s box', 'fishotel-shiptracker' ); ?></strong></td>
                    <td>
                        <?php
                        printf(
                            /* translators: %d: number of orders */
                            esc_html( _n(
                                '%d order had no label of its own: it went in a box shipped in this range, and its shipping paid counts towards that box.',
                                '%d orders had no label of their own: they went in boxes shipped in this range, and their shipping paid counts towards those boxes.',
                                (int) $cost_report['in_other_box']['orders'],
                                'fishotel-shiptracker'
                            ) ),
                            (int) $cost_report['in_other_box']['orders']
                        );
                        ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Supplies -->
    <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 6px; margin-bottom: 20px;">
        <h3 style="margin: 0 0 6px;"><?php esc_html_e( 'Supplies', 'fishotel-shiptracker' ); ?></h3>
        <p style="color: #666; font-size: 12px; margin: 0 0 12px; max-width: 900px;">
            <?php esc_html_e( 'Bought: purchases logged with a date in this range. Used: what went into the boxes shipped and the local pickups made in this range — every one of them, with or without a recorded label cost. On hand is now, not at the end of the range.', 'fishotel-shiptracker' ); ?>
        </p>
        <?php if ( ! empty( $cost_report['supplies']['rows'] ) ) : ?>
            <table class="widefat striped" style="margin-bottom: 16px;">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'Supply', 'fishotel-shiptracker' ); ?></th>
                        <th><?php esc_html_e( 'Bought', 'fishotel-shiptracker' ); ?></th>
                        <th><?php esc_html_e( 'Paid', 'fishotel-shiptracker' ); ?></th>
                        <th><?php esc_html_e( 'Used', 'fishotel-shiptracker' ); ?></th>
                        <th><?php esc_html_e( 'Cost of What Was Used', 'fishotel-shiptracker' ); ?></th>
                        <th><?php esc_html_e( 'On Hand Now', 'fishotel-shiptracker' ); ?></th>
                        <th><?php esc_html_e( 'Reorder At', 'fishotel-shiptracker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $cost_report['supplies']['rows'] as $supply_row ) : ?>
                        <tr>
                            <td>
                                <?php echo esc_html( $supply_row['name'] ); ?>
                                <?php if ( $supply_row['removed'] ) : ?>
                                    <span style="color: #999;"><?php esc_html_e( '(no longer on the list)', 'fishotel-shiptracker' ); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html( FST_Label_Cost::trim_decimal( $supply_row['bought_qty'] ) . ' ' . $supply_row['unit'] ); ?></td>
                            <td><?php echo $fst_money( $supply_row['bought_money'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                            <td><?php echo esc_html( FST_Label_Cost::trim_decimal( $supply_row['used_qty'] ) . ' ' . $supply_row['unit'] ); ?></td>
                            <td><?php echo $fst_money( $supply_row['used_money'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                            <td>
                                <?php if ( null === $supply_row['on_hand'] ) : ?>
                                    <span style="color: #999;"><?php esc_html_e( 'not counted', 'fishotel-shiptracker' ); ?></span>
                                <?php else : ?>
                                    <strong><?php echo esc_html( FST_Label_Cost::trim_decimal( $supply_row['on_hand'] ) ); ?></strong>
                                    <?php if ( $supply_row['recount_needed'] ) : ?>
                                        <span style="color: #b26b00;"><?php esc_html_e( 'recount needed', 'fishotel-shiptracker' ); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( null === $supply_row['reorder_level'] ) : ?>
                                    &mdash;
                                <?php else : ?>
                                    <?php echo esc_html( FST_Label_Cost::trim_decimal( $supply_row['reorder_level'] ) ); ?>
                                    <?php if ( $supply_row['low'] ) : ?>
                                        <span style="color: #dc3545; font-weight: 600;"><?php esc_html_e( 'reorder', 'fishotel-shiptracker' ); ?></span>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p style="color: #999;"><?php esc_html_e( 'No supplies yet. Add them under Settings → Boxes & label costs.', 'fishotel-shiptracker' ); ?></p>
        <?php endif; ?>

        <h4 style="margin: 0 0 4px;"><?php esc_html_e( 'Foam and Paper: Bought Against Your Ballparks', 'fishotel-shiptracker' ); ?></h4>
        <p style="color: #666; font-size: 12px; margin: 0 0 8px; max-width: 900px;">
            <?php esc_html_e( 'Foam and paper are a flat amount per box. Over a long enough range the amounts should add up to about what you paid for foam and paper — if they are far apart, correct the foam or paper cost of your standard boxes.', 'fishotel-shiptracker' ); ?>
        </p>
        <table class="widefat striped" style="max-width: 900px;">
            <thead>
                <tr>
                    <th></th>
                    <th><?php esc_html_e( 'Bought in This Range', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Per-Box Amounts Added Up', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Boxes With an Amount', 'fishotel-shiptracker' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( array( 'foam' => __( 'Foam', 'fishotel-shiptracker' ), 'paper' => __( 'Paper', 'fishotel-shiptracker' ) ) as $ballpark_key => $ballpark_label ) :
                    $ballpark = $cost_report['supplies']['ballparks'][ $ballpark_key ];
                    ?>
                    <tr>
                        <td><strong><?php echo esc_html( $ballpark_label ); ?></strong></td>
                        <td>
                            <?php if ( null === $ballpark['supply'] ) : ?>
                                <span style="color: #999;"><?php esc_html_e( 'no supply is marked for this yet', 'fishotel-shiptracker' ); ?></span>
                            <?php else : ?>
                                <?php echo $fst_money( $ballpark['bought_money'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $fst_money( $ballpark['ballpark'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- wc_price() markup. ?></td>
                        <td><?php echo esc_html( $ballpark['records'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
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
