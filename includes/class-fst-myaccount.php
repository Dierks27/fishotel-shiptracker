<?php
/**
 * WooCommerce My Account integration.
 *
 * Adds a "Shipment Tracking" tab to My Account and injects tracking info
 * into individual order views.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_MyAccount {

    /**
     * Constructor — register all hooks.
     */
    public function __construct() {
        // Add tracking details to the order-details page in My Account.
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'display_order_tracking' ), 10 );

        // Also show on the order-received (thank you) page.
        add_action( 'woocommerce_thankyou', array( $this, 'display_thankyou_tracking' ), 10 );

        // Enqueue frontend styles.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );
    }

    /**
     * Enqueue frontend CSS on relevant pages.
     */
    public function enqueue_frontend_assets() {
        if ( ! is_account_page() && ! is_order_received_page() ) {
            return;
        }

        wp_enqueue_style(
            'fst-frontend',
            FST_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            FST_VERSION
        );
    }

    /**
     * Display tracking info on My Account > View Order page.
     *
     * @param WC_Order $order
     */
    public function display_order_tracking( $order ) {
        if ( ! $order ) {
            return;
        }

        $order_id  = $order->get_id();
        $shipments = FST_Shipment::get_by_order( $order_id );

        if ( empty( $shipments ) ) {
            return;
        }

        echo '<div class="fst-tracking-section">';
        echo '<h2>' . esc_html__( 'Shipment Tracking', 'fishotel-shiptracker' ) . '</h2>';

        foreach ( $shipments as $index => $shipment ) {
            $this->render_shipment_card( $shipment, count( $shipments ) > 1 ? $index + 1 : 0 );
        }

        echo '</div>';
    }

    /**
     * Display tracking on Thank You page.
     *
     * @param int $order_id
     */
    public function display_thankyou_tracking( $order_id ) {
        if ( ! $order_id ) {
            return;
        }

        $shipments = FST_Shipment::get_by_order( $order_id );

        if ( empty( $shipments ) ) {
            return;
        }

        // Enqueue CSS here too (thankyou might not trigger is_account_page).
        wp_enqueue_style(
            'fst-frontend',
            FST_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            FST_VERSION
        );

        echo '<div class="fst-tracking-section">';
        echo '<h2>' . esc_html__( 'Shipment Tracking', 'fishotel-shiptracker' ) . '</h2>';

        foreach ( $shipments as $index => $shipment ) {
            $this->render_shipment_card( $shipment, count( $shipments ) > 1 ? $index + 1 : 0 );
        }

        echo '</div>';
    }

    /**
     * Render a single shipment tracking card.
     *
     * @param object $shipment  stdClass from FST_Shipment.
     * @param int    $number    Shipment number for multi-shipment orders (0 = single).
     */
    private function render_shipment_card( $shipment, $number = 0 ) {
        $status       = $shipment->status;
        $status_label = FST_Carrier::get_status_label( $status );
        $status_color = FST_Carrier::get_status_color( $status );
        $carrier      = strtoupper( $shipment->carrier );
        $tracking_num = $shipment->tracking_number;
        $events       = FST_Shipment::get_events( $shipment->id );

        // Build the carrier tracking URL.
        $carrier_url = '';
        if ( 'ups' === $shipment->carrier ) {
            $carrier_url = 'https://www.ups.com/track?tracknum=' . urlencode( $tracking_num );
        } elseif ( 'usps' === $shipment->carrier ) {
            $carrier_url = 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . urlencode( $tracking_num );
        }

        // Status progress steps.
        $progress_steps = array(
            'shipped'          => 'Shipped',
            'in_transit'       => 'In Transit',
            'out_for_delivery' => 'Out for Delivery',
            'delivered'        => 'Delivered',
        );

        $step_order = array_keys( $progress_steps );
        $current_step_index = $this->get_progress_index( $status, $step_order );

        ?>
        <div class="fst-shipment-card">
            <?php if ( $number > 0 ) : ?>
                <div class="fst-shipment-card__header-label">
                    <?php printf( esc_html__( 'Package %d', 'fishotel-shiptracker' ), $number ); ?>
                </div>
            <?php endif; ?>

            <!-- Status Header -->
            <div class="fst-shipment-card__status" style="--fst-status-color: <?php echo esc_attr( $status_color ); ?>">
                <div class="fst-shipment-card__status-badge">
                    <?php echo esc_html( $status_label ); ?>
                </div>
                <?php if ( ! empty( $shipment->status_detail ) ) : ?>
                    <div class="fst-shipment-card__status-detail">
                        <?php echo esc_html( $shipment->status_detail ); ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Tracking Info -->
            <div class="fst-shipment-card__info">
                <div class="fst-shipment-card__info-row">
                    <span class="fst-shipment-card__label"><?php esc_html_e( 'Carrier', 'fishotel-shiptracker' ); ?></span>
                    <span class="fst-shipment-card__value"><?php echo esc_html( $carrier ); ?></span>
                </div>
                <div class="fst-shipment-card__info-row">
                    <span class="fst-shipment-card__label"><?php esc_html_e( 'Tracking #', 'fishotel-shiptracker' ); ?></span>
                    <span class="fst-shipment-card__value">
                        <?php if ( $carrier_url ) : ?>
                            <a href="<?php echo esc_url( $carrier_url ); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo esc_html( $tracking_num ); ?>
                            </a>
                        <?php else : ?>
                            <?php echo esc_html( $tracking_num ); ?>
                        <?php endif; ?>
                    </span>
                </div>
                <?php if ( ! empty( $shipment->ship_date ) ) : ?>
                    <div class="fst-shipment-card__info-row">
                        <span class="fst-shipment-card__label"><?php esc_html_e( 'Shipped', 'fishotel-shiptracker' ); ?></span>
                        <span class="fst-shipment-card__value">
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $shipment->ship_date ) ) ); ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if ( ! empty( $shipment->est_delivery ) ) : ?>
                    <div class="fst-shipment-card__info-row">
                        <span class="fst-shipment-card__label"><?php esc_html_e( 'Est. Delivery', 'fishotel-shiptracker' ); ?></span>
                        <span class="fst-shipment-card__value">
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $shipment->est_delivery ) ) ); ?>
                        </span>
                    </div>
                <?php endif; ?>
                <?php if ( ! empty( $shipment->delivered_at ) ) : ?>
                    <div class="fst-shipment-card__info-row">
                        <span class="fst-shipment-card__label"><?php esc_html_e( 'Delivered', 'fishotel-shiptracker' ); ?></span>
                        <span class="fst-shipment-card__value fst-delivered-highlight">
                            <?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $shipment->delivered_at ) ) ); ?>
                        </span>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Progress Bar -->
            <div class="fst-progress-bar">
                <?php foreach ( $progress_steps as $step_key => $step_label ) :
                    $step_idx  = array_search( $step_key, $step_order, true );
                    $is_done   = $step_idx <= $current_step_index;
                    $is_active = $step_idx === $current_step_index;
                    $classes   = 'fst-progress-bar__step';
                    if ( $is_done )   $classes .= ' fst-progress-bar__step--done';
                    if ( $is_active ) $classes .= ' fst-progress-bar__step--active';
                ?>
                    <div class="<?php echo esc_attr( $classes ); ?>" style="--fst-status-color: <?php echo esc_attr( $status_color ); ?>">
                        <div class="fst-progress-bar__dot"></div>
                        <div class="fst-progress-bar__label"><?php echo esc_html( $step_label ); ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Tracking Events Timeline -->
            <?php if ( ! empty( $events ) ) : ?>
                <div class="fst-timeline">
                    <h3 class="fst-timeline__heading">
                        <?php esc_html_e( 'Tracking History', 'fishotel-shiptracker' ); ?>
                    </h3>
                    <div class="fst-timeline__list">
                        <?php foreach ( $events as $event ) : ?>
                            <div class="fst-timeline__event">
                                <div class="fst-timeline__dot"></div>
                                <div class="fst-timeline__content">
                                    <div class="fst-timeline__description">
                                        <?php echo esc_html( $event->description ); ?>
                                    </div>
                                    <div class="fst-timeline__meta">
                                        <?php if ( ! empty( $event->location ) ) : ?>
                                            <span class="fst-timeline__location"><?php echo esc_html( $event->location ); ?></span>
                                            <span class="fst-timeline__separator">&middot;</span>
                                        <?php endif; ?>
                                        <span class="fst-timeline__time">
                                            <?php echo esc_html( date_i18n(
                                                get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                                                strtotime( $event->event_time )
                                            ) ); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Track on Carrier Site Link -->
            <?php if ( $carrier_url ) : ?>
                <div class="fst-shipment-card__footer">
                    <a href="<?php echo esc_url( $carrier_url ); ?>" class="fst-btn fst-btn--outline" target="_blank" rel="noopener noreferrer">
                        <?php printf( esc_html__( 'Track on %s', 'fishotel-shiptracker' ), esc_html( $carrier ) ); ?> &rarr;
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Map current status to progress bar step index.
     *
     * @param string $status    Current shipment status.
     * @param array  $step_keys Ordered step keys.
     * @return int              Index in the step_keys array, -1 if before first step.
     */
    private function get_progress_index( $status, $step_keys ) {
        // Map statuses to the nearest progress step.
        $status_to_step = array(
            'unknown'              => -1,
            'label_created'        => 0,  // Shipped
            'shipped'              => 0,  // Shipped
            'pre_transit'          => 0,  // Shipped
            'in_transit'           => 1,  // In Transit
            'out_for_delivery'     => 2,  // Out for Delivery
            'available_for_pickup' => 2,  // Out for Delivery equivalent
            'delivered'            => 3,  // Delivered
            'exception'            => 1,  // show as In Transit level
            'return_to_sender'     => 1,
            'failure'              => 1,
        );

        return isset( $status_to_step[ $status ] ) ? $status_to_step[ $status ] : -1;
    }
}
