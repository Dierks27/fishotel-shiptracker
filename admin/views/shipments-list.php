<?php
/**
 * FisHotel ShipTracker - Shipments Dashboard
 *
 * Uses FST_Shipment static methods which return stdClass rows.
 *
 * @package FisHotel_ShipTracker
 * @subpackage Admin
 */

defined( 'ABSPATH' ) || exit;

// Get filter values from query string.
$status_filter  = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
$carrier_filter = isset( $_GET['carrier'] ) ? sanitize_text_field( $_GET['carrier'] ) : '';
$search_query   = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
$paged          = isset( $_GET['paged'] ) ? max( 1, intval( $_GET['paged'] ) ) : 1;
$fst_orderby    = isset( $_GET['orderby'] ) ? sanitize_text_field( $_GET['orderby'] ) : 'ship_date';
$fst_order      = isset( $_GET['order'] ) ? strtoupper( sanitize_text_field( $_GET['order'] ) ) : 'DESC';
$per_page       = 20;

// Build query arguments.
$query_args = array(
    'per_page' => $per_page,
    'page'     => $paged,
    'orderby'  => $fst_orderby,
    'order'    => $fst_order,
);

if ( $status_filter ) {
    $query_args['status'] = $status_filter;
}

if ( $carrier_filter ) {
    $query_args['carrier'] = $carrier_filter;
}

if ( $search_query ) {
    $query_args['search'] = $search_query;
}

/**
 * Build a sortable column header link.
 *
 * Variables are passed explicitly because this file is included from a method
 * scope, so they are NOT in the global scope.
 *
 * @param string $column         Column key (must match allowed_orderby in query()).
 * @param string $label          Display label.
 * @param string $cur_orderby    Current orderby column.
 * @param string $cur_order      Current order direction (ASC/DESC).
 * @param string $status_filter  Current status filter.
 * @param string $carrier_filter Current carrier filter.
 * @param string $search_query   Current search query.
 * @return string HTML link.
 */
function fst_sort_link( $column, $label, $cur_orderby, $cur_order, $status_filter, $carrier_filter, $search_query ) {
    $is_current  = ( $cur_orderby === $column );
    $new_order   = ( $is_current && 'ASC' === $cur_order ) ? 'DESC' : 'ASC';
    $arrow       = '';

    if ( $is_current ) {
        $arrow = ( 'ASC' === $cur_order ) ? ' &#9650;' : ' &#9660;';
    }

    $url = admin_url( 'admin.php?page=fst-dashboard&orderby=' . urlencode( $column ) . '&order=' . $new_order );
    if ( $status_filter )  $url .= '&status=' . urlencode( $status_filter );
    if ( $carrier_filter ) $url .= '&carrier=' . urlencode( $carrier_filter );
    if ( $search_query )   $url .= '&s=' . urlencode( $search_query );

    $style = $is_current ? 'font-weight:700;' : '';

    return '<a href="' . esc_url( $url ) . '" style="text-decoration:none;color:inherit;' . $style . '">' . esc_html( $label ) . $arrow . '</a>';
}

// FST_Shipment::query() returns array( 'items' => [...], 'total' => int, 'pages' => int ).
$result      = FST_Shipment::query( $query_args );
$shipments   = $result['items'];
$total_count = $result['total'];
$total_pages = $result['pages'];

// Get status counts for summary cards.
$status_counts = FST_Shipment::count_by_status();

/**
 * Format status for display.
 *
 * @param string $status The status code.
 * @return string
 */
function fst_format_status_label( $status ) {
    return FST_Carrier::get_status_label( $status );
}

/**
 * Check if a shipment is late (past est_delivery, not terminal).
 *
 * @param object $shipment stdClass row from DB.
 * @return bool
 */
function fst_is_shipment_late( $shipment ) {
    $terminal = array( 'delivered', 'failure', 'return_to_sender' );
    if ( in_array( $shipment->status, $terminal, true ) ) {
        return false;
    }
    if ( empty( $shipment->est_delivery ) ) {
        return false;
    }
    return strtotime( $shipment->est_delivery ) < strtotime( current_time( 'Y-m-d' ) );
}

?>

<div class="wrap">
    <h1><?php esc_html_e( 'Shipments', 'fishotel-shiptracker' ); ?></h1>

    <!-- Active Shipments Overview -->
    <?php
    $active_statuses = array(
        'in_transit'       => array( 'label' => 'In Transit',       'icon' => '&#128666;' ),
        'out_for_delivery' => array( 'label' => 'Out for Delivery', 'icon' => '&#128230;' ),
        'exception'        => array( 'label' => 'Exception',        'icon' => '&#9888;' ),
        'return_to_sender' => array( 'label' => 'Returned',         'icon' => '&#8617;' ),
    );
    $in_transit_count = isset( $status_counts['in_transit'] ) ? $status_counts['in_transit'] : 0;
    $ofd_count        = isset( $status_counts['out_for_delivery'] ) ? $status_counts['out_for_delivery'] : 0;
    $exception_count  = isset( $status_counts['exception'] ) ? $status_counts['exception'] : 0;
    $active_total     = $in_transit_count + $ofd_count + $exception_count;
    $delivered_count  = isset( $status_counts['delivered'] ) ? $status_counts['delivered'] : 0;
    $base_url         = admin_url( 'admin.php?page=fst-dashboard' );
    ?>
    <div style="display: flex; gap: 12px; margin-bottom: 20px; flex-wrap: wrap;">
        <!-- Active shipments highlight -->
        <a href="<?php echo esc_url( $base_url ); ?>" style="text-decoration: none; flex: 0 0 auto;">
            <div style="background: #fff; padding: 16px 24px; border: 1px solid #ccd0d4; border-top: 3px solid #0073aa; border-radius: 4px; text-align: center; min-width: 120px; <?php echo ! $status_filter ? 'box-shadow: 0 0 0 2px #0073aa;' : ''; ?>">
                <div style="font-size: 11px; text-transform: uppercase; color: #666; font-weight: 600; letter-spacing: 0.5px;"><?php esc_html_e( 'Active', 'fishotel-shiptracker' ); ?></div>
                <div style="font-size: 32px; font-weight: 700; color: #0073aa; line-height: 1.2;"><?php echo esc_html( $active_total ); ?></div>
            </div>
        </a>

        <?php foreach ( $active_statuses as $skey => $sinfo ) :
            $scount  = isset( $status_counts[ $skey ] ) ? $status_counts[ $skey ] : 0;
            $color   = FST_Carrier::get_status_color( $skey );
            $is_active = ( $status_filter === $skey );
        ?>
        <a href="<?php echo esc_url( $base_url . '&status=' . $skey ); ?>" style="text-decoration: none; flex: 0 0 auto;">
            <div style="background: #fff; padding: 16px 24px; border: 1px solid #ccd0d4; border-top: 3px solid <?php echo esc_attr( $color ); ?>; border-radius: 4px; text-align: center; min-width: 120px; <?php echo $is_active ? 'box-shadow: 0 0 0 2px ' . esc_attr( $color ) . ';' : ''; ?> <?php echo ( $scount > 0 && in_array( $skey, array( 'exception', 'return_to_sender' ), true ) ) ? 'background: #fff8f8;' : ''; ?>">
                <div style="font-size: 11px; text-transform: uppercase; color: #666; font-weight: 600; letter-spacing: 0.5px;"><?php echo esc_html( $sinfo['label'] ); ?></div>
                <div style="font-size: 32px; font-weight: 700; color: <?php echo esc_attr( $color ); ?>; line-height: 1.2;"><?php echo esc_html( $scount ); ?></div>
            </div>
        </a>
        <?php endforeach; ?>

        <!-- Delivered -->
        <a href="<?php echo esc_url( $base_url . '&status=delivered' ); ?>" style="text-decoration: none; flex: 0 0 auto;">
            <div style="background: #fff; padding: 16px 24px; border: 1px solid #ccd0d4; border-top: 3px solid #1e7e34; border-radius: 4px; text-align: center; min-width: 120px; <?php echo 'delivered' === $status_filter ? 'box-shadow: 0 0 0 2px #1e7e34;' : ''; ?>">
                <div style="font-size: 11px; text-transform: uppercase; color: #666; font-weight: 600; letter-spacing: 0.5px;"><?php esc_html_e( 'Delivered', 'fishotel-shiptracker' ); ?></div>
                <div style="font-size: 32px; font-weight: 700; color: #1e7e34; line-height: 1.2;"><?php echo esc_html( $delivered_count ); ?></div>
            </div>
        </a>
    </div>

    <!-- Filter Bar -->
    <div class="fst-filter-bar" style="background: #fff; padding: 15px; border: 1px solid #ccd0d4; border-radius: 4px; margin-bottom: 20px;">
        <form method="get" id="fst-filter-form" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
            <input type="hidden" name="page" value="fst-dashboard">

            <div class="fst-filter-group">
                <label for="fst-status-filter" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Status', 'fishotel-shiptracker' ); ?></label>
                <select id="fst-status-filter" name="status">
                    <option value=""><?php esc_html_e( 'All Statuses', 'fishotel-shiptracker' ); ?></option>
                    <option value="unknown" <?php selected( $status_filter, 'unknown' ); ?>>Unknown</option>
                    <option value="label_created" <?php selected( $status_filter, 'label_created' ); ?>>Label Created</option>
                    <option value="pre_transit" <?php selected( $status_filter, 'pre_transit' ); ?>>Pre-Transit</option>
                    <option value="in_transit" <?php selected( $status_filter, 'in_transit' ); ?>>In Transit</option>
                    <option value="out_for_delivery" <?php selected( $status_filter, 'out_for_delivery' ); ?>>Out for Delivery</option>
                    <option value="delivered" <?php selected( $status_filter, 'delivered' ); ?>>Delivered</option>
                    <option value="exception" <?php selected( $status_filter, 'exception' ); ?>>Exception</option>
                    <option value="return_to_sender" <?php selected( $status_filter, 'return_to_sender' ); ?>>Return to Sender</option>
                    <option value="failure" <?php selected( $status_filter, 'failure' ); ?>>Failure</option>
                </select>
            </div>

            <div class="fst-filter-group">
                <label for="fst-carrier-filter" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Carrier', 'fishotel-shiptracker' ); ?></label>
                <select id="fst-carrier-filter" name="carrier">
                    <option value=""><?php esc_html_e( 'All Carriers', 'fishotel-shiptracker' ); ?></option>
                    <option value="ups" <?php selected( $carrier_filter, 'ups' ); ?>>UPS</option>
                    <option value="usps" <?php selected( $carrier_filter, 'usps' ); ?>>USPS</option>
                </select>
            </div>

            <div class="fst-filter-group">
                <label for="fst-search" style="display: block; font-weight: 600; margin-bottom: 4px;"><?php esc_html_e( 'Search', 'fishotel-shiptracker' ); ?></label>
                <input type="text" id="fst-search" name="s" placeholder="<?php esc_attr_e( 'Tracking number or order...', 'fishotel-shiptracker' ); ?>" value="<?php echo esc_attr( $search_query ); ?>">
            </div>

            <?php submit_button( esc_html__( 'Filter', 'fishotel-shiptracker' ), 'primary', 'submit', false ); ?>
        </form>
    </div>

    <!-- Shipments Table -->
    <?php if ( ! empty( $shipments ) ) { ?>
        <table class="widefat striped fst-shipments-table">
            <thead>
                <tr>
                    <th><?php echo fst_sort_link( 'order_id', __( 'Order #', 'fishotel-shiptracker' ), $fst_orderby, $fst_order, $status_filter, $carrier_filter, $search_query ); ?></th>
                    <th><?php esc_html_e( 'Tracking #', 'fishotel-shiptracker' ); ?></th>
                    <th><?php echo fst_sort_link( 'carrier', __( 'Carrier', 'fishotel-shiptracker' ), $fst_orderby, $fst_order, $status_filter, $carrier_filter, $search_query ); ?></th>
                    <th><?php echo fst_sort_link( 'status', __( 'Status', 'fishotel-shiptracker' ), $fst_orderby, $fst_order, $status_filter, $carrier_filter, $search_query ); ?></th>
                    <th><?php echo fst_sort_link( 'ship_date', __( 'Ship Date', 'fishotel-shiptracker' ), $fst_orderby, $fst_order, $status_filter, $carrier_filter, $search_query ); ?></th>
                    <th><?php echo fst_sort_link( 'est_delivery', __( 'Est. Delivery', 'fishotel-shiptracker' ), $fst_orderby, $fst_order, $status_filter, $carrier_filter, $search_query ); ?></th>
                    <th><?php echo fst_sort_link( 'updated_at', __( 'Last Checked', 'fishotel-shiptracker' ), $fst_orderby, $fst_order, $status_filter, $carrier_filter, $search_query ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'fishotel-shiptracker' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $shipments as $shipment ) : ?>
                    <?php
                        $order   = wc_get_order( $shipment->order_id );
                        $is_late = fst_is_shipment_late( $shipment );
                    ?>
                    <tr <?php echo $is_late ? 'style="background-color: #fff3cd;"' : ''; ?>>
                        <td>
                            <?php if ( $order ) : ?>
                                <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
                                    #<?php echo esc_html( $order->get_order_number() ); ?>
                                </a>
                            <?php else : ?>
                                #<?php echo esc_html( $shipment->order_id ); ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <code><?php echo esc_html( $shipment->tracking_number ); ?></code>
                        </td>
                        <td>
                            <?php echo esc_html( strtoupper( $shipment->carrier ) ); ?>
                        </td>
                        <td>
                            <span class="fst-status-badge" data-id="<?php echo esc_attr( $shipment->id ); ?>" style="background-color: <?php echo esc_attr( FST_Carrier::get_status_color( $shipment->status ) ); ?>; color: #fff; padding: 2px 8px; border-radius: 3px; font-size: 11px; display: inline-block;">
                                <?php echo esc_html( fst_format_status_label( $shipment->status ) ); ?>
                            </span>
                            <?php if ( $is_late ) : ?>
                                <span style="background-color: #dc3545; color: #fff; padding: 2px 6px; border-radius: 3px; font-size: 10px; display: inline-block; margin-left: 4px;"><?php esc_html_e( 'LATE', 'fishotel-shiptracker' ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo $shipment->ship_date ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $shipment->ship_date ) ) ) : '&mdash;'; ?>
                        </td>
                        <td>
                            <?php echo $shipment->est_delivery ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $shipment->est_delivery ) ) ) : '&mdash;'; ?>
                        </td>
                        <td>
                            <?php echo ! empty( $shipment->last_checked ) ? esc_html( human_time_diff( strtotime( $shipment->last_checked ), current_time( 'timestamp' ) ) ) . ' ago' : esc_html__( 'Never', 'fishotel-shiptracker' ); ?>
                        </td>
                        <td>
                            <button class="button button-small fst-recheck-btn" data-id="<?php echo esc_attr( $shipment->id ); ?>">
                                <?php esc_html_e( 'Recheck', 'fishotel-shiptracker' ); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <!-- Pagination -->
        <?php if ( $total_pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <span class="displaying-num">
                        <?php printf( esc_html__( '%s items', 'fishotel-shiptracker' ), number_format_i18n( $total_count ) ); ?>
                    </span>
                    <?php
                        $base_url = admin_url( 'admin.php?page=fst-dashboard' );
                        if ( $status_filter ) {
                            $base_url .= '&status=' . urlencode( $status_filter );
                        }
                        if ( $carrier_filter ) {
                            $base_url .= '&carrier=' . urlencode( $carrier_filter );
                        }
                        if ( $search_query ) {
                            $base_url .= '&s=' . urlencode( $search_query );
                        }
                        if ( $fst_orderby && 'ship_date' !== $fst_orderby ) {
                            $base_url .= '&orderby=' . urlencode( $fst_orderby );
                        }
                        if ( $fst_order && 'DESC' !== $fst_order ) {
                            $base_url .= '&order=' . urlencode( $fst_order );
                        }

                        echo paginate_links( array(
                            'base'    => $base_url . '%_%',
                            'format'  => '&paged=%#%',
                            'current' => $paged,
                            'total'   => $total_pages,
                            'type'    => 'plain',
                        ) );
                    ?>
                </div>
            </div>
        <?php endif; ?>
    <?php } else { ?>
        <div class="notice notice-info">
            <p><?php esc_html_e( 'No shipments found.', 'fishotel-shiptracker' ); ?></p>
        </div>
    <?php } ?>
</div>
