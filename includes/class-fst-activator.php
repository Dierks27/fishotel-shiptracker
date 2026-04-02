<?php
/**
 * Plugin activation and DB migration.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Activator {

    /**
     * Run on plugin activation.
     */
    public static function activate() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            deactivate_plugins( FST_PLUGIN_BASENAME );
            wp_die( 'FisHotel ShipTracker requires WooCommerce. Please install and activate WooCommerce first.' );
        }

        self::create_tables();
        self::set_default_options();
        self::schedule_events();

        update_option( 'fst_db_version', FST_DB_VERSION );
        update_option( 'fst_activated', true );

        // Flush rewrite rules for tracking page.
        flush_rewrite_rules();
    }

    /**
     * Check if DB needs updating (runs on every page load via init).
     */
    public static function maybe_update() {
        $current_version = get_option( 'fst_db_version', '0' );

        if ( version_compare( $current_version, FST_DB_VERSION, '<' ) ) {
            self::create_tables();
            update_option( 'fst_db_version', FST_DB_VERSION );
        }
    }

    /**
     * Create custom database tables.
     */
    private static function create_tables() {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();
        $shipments_table = $wpdb->prefix . 'fst_shipments';
        $events_table    = $wpdb->prefix . 'fst_tracking_events';

        $sql = "CREATE TABLE {$shipments_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            order_id bigint(20) unsigned NOT NULL,
            tracking_number varchar(100) NOT NULL DEFAULT '',
            carrier varchar(20) NOT NULL DEFAULT 'ups',
            status varchar(30) NOT NULL DEFAULT 'unknown',
            status_detail text,
            ship_date datetime DEFAULT NULL,
            est_delivery datetime DEFAULT NULL,
            delivered_date datetime DEFAULT NULL,
            last_checked datetime DEFAULT NULL,
            last_event_at datetime DEFAULT NULL,
            tracking_data longtext,
            check_count int(11) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY tracking_number (tracking_number),
            KEY status (status),
            KEY carrier (carrier),
            KEY last_checked (last_checked)
        ) {$charset_collate};

        CREATE TABLE {$events_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            shipment_id bigint(20) unsigned NOT NULL,
            status varchar(30) NOT NULL DEFAULT '',
            description text,
            location varchar(255) DEFAULT '',
            event_time datetime DEFAULT NULL,
            raw_data text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY shipment_id (shipment_id),
            KEY status (status),
            KEY event_time (event_time)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Set default plugin options.
     */
    private static function set_default_options() {
        $defaults = array(
            'fst_default_carrier'    => 'ups',
            'fst_auto_detect_carrier' => 'yes',
            'fst_auto_complete_order' => 'yes',
            'fst_data_retention_days' => 1095, // 3 years
            'fst_debug_logging'      => 'no',
            'fst_polling_interval'   => 60,    // minutes
            'fst_ofd_polling_interval' => 30,  // minutes for out-for-delivery

            // UPS credentials.
            'fst_ups_client_id'      => '',
            'fst_ups_client_secret'  => '',
            'fst_ups_sandbox'        => 'no',

            // USPS credentials.
            'fst_usps_client_id'     => '',
            'fst_usps_client_secret' => '',

            // Status actions — what happens on each status change.
            // Format: array of actions per status.
            'fst_status_actions' => array(
                'label_created'        => array( 'email_customer' => false, 'email_admin' => false, 'order_status' => '' ),
                'pre_transit'          => array( 'email_customer' => false, 'email_admin' => false, 'order_status' => '' ),
                'in_transit'           => array( 'email_customer' => true,  'email_admin' => false, 'order_status' => '' ),
                'out_for_delivery'     => array( 'email_customer' => true,  'email_admin' => false, 'order_status' => '' ),
                'delivered'            => array( 'email_customer' => true,  'email_admin' => true,  'order_status' => 'completed' ),
                'exception'            => array( 'email_customer' => true,  'email_admin' => true,  'order_status' => '' ),
                'available_for_pickup' => array( 'email_customer' => true,  'email_admin' => false, 'order_status' => '' ),
                'return_to_sender'     => array( 'email_customer' => true,  'email_admin' => true,  'order_status' => '' ),
                'failure'              => array( 'email_customer' => true,  'email_admin' => true,  'order_status' => '' ),
            ),

            // Email templates — subject and body per status (with shortcode placeholders).
            'fst_email_templates' => array(
                'in_transit' => array(
                    'subject' => 'Your order #{order_number} has shipped!',
                    'body'    => "Hi {customer_name},\n\nGreat news! Your order #{order_number} is on its way via {carrier}.\n\nTracking Number: {tracking_number}\n\n{tracking_timeline}\n\nTrack your package: {tracking_url}\n\nThank you for your order!",
                ),
                'out_for_delivery' => array(
                    'subject' => 'Your order #{order_number} is out for delivery!',
                    'body'    => "Hi {customer_name},\n\nYour order #{order_number} is out for delivery today!\n\nTracking Number: {tracking_number}\n\n{tracking_timeline}\n\nTrack your package: {tracking_url}",
                ),
                'delivered' => array(
                    'subject' => 'Your order #{order_number} has been delivered!',
                    'body'    => "Hi {customer_name},\n\nYour order #{order_number} has been delivered!\n\n{tracking_timeline}\n\nWe hope you enjoy your purchase. Thank you for shopping with FisHotel!",
                ),
                'exception' => array(
                    'subject' => 'Delivery update for order #{order_number}',
                    'body'    => "Hi {customer_name},\n\nThere's an update on your order #{order_number}.\n\nStatus: {status_detail}\n\nTracking Number: {tracking_number}\nTrack your package: {tracking_url}\n\nIf you have questions, please contact us.",
                ),
                'available_for_pickup' => array(
                    'subject' => 'Your order #{order_number} is ready for pickup!',
                    'body'    => "Hi {customer_name},\n\nYour order #{order_number} is available for pickup.\n\n{status_detail}\n\nTracking Number: {tracking_number}",
                ),
                'return_to_sender' => array(
                    'subject' => 'Update on your order #{order_number}',
                    'body'    => "Hi {customer_name},\n\nYour order #{order_number} is being returned to us.\n\nStatus: {status_detail}\n\nWe'll be in touch about next steps.",
                ),
                'failure' => array(
                    'subject' => 'Delivery issue with order #{order_number}',
                    'body'    => "Hi {customer_name},\n\nUnfortunately, delivery of your order #{order_number} could not be completed.\n\nStatus: {status_detail}\n\nPlease contact us so we can resolve this.",
                ),
            ),

            // Tracking page settings.
            'fst_tracking_page_id'   => 0,
            'fst_tracking_bg_color'  => '#ffffff',
            'fst_tracking_accent_color' => '#2e75b6',
            'fst_tracking_text_color'   => '#333333',
            'fst_tracking_custom_css'   => '',
        );

        foreach ( $defaults as $key => $value ) {
            if ( false === get_option( $key ) ) {
                update_option( $key, $value );
            }
        }
    }

    /**
     * Schedule Action Scheduler recurring events.
     */
    private static function schedule_events() {
        if ( function_exists( 'as_has_scheduled_action' ) ) {
            if ( ! as_has_scheduled_action( 'fst_poll_shipments' ) ) {
                as_schedule_recurring_action( time(), 30 * MINUTE_IN_SECONDS, 'fst_poll_shipments', array(), 'fishotel-shiptracker' );
            }
        }
    }
}
