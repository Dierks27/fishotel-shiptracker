<?php
/**
 * Plugin Name: FisHotel ShipTracker
 * Plugin URI: https://fishotel.com
 * Description: Self-hosted shipment tracking for WooCommerce. Tracks UPS & USPS packages, sends automated email notifications, and provides a branded tracking page for customers.
 * Version: 1.4.8
 * Author: FisHotel
 * Author URI: https://fishotel.com
 * Text Domain: fishotel-shiptracker
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * WC tested up to: 9.6
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants
define( 'FST_VERSION', '1.4.8' );
define( 'FST_DB_VERSION', '1.0.0' );
define( 'FST_PLUGIN_FILE', __FILE__ );
define( 'FST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FST_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class — singleton.
 */
final class FisHotel_ShipTracker {

    /** @var FisHotel_ShipTracker|null */
    private static $instance = null;

    /** @var FST_Settings|null */
    public $settings = null;

    /** @var FST_Tracker|null */
    public $tracker = null;

    /** @var FST_Order|null */
    public $order = null;

    /** @var FST_MyAccount|null */
    public $myaccount = null;

    /**
     * Get the single instance.
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor — hooks everything up.
     */
    private function __construct() {
        // Check for WooCommerce before doing anything.
        add_action( 'plugins_loaded', array( $this, 'init' ), 20 );

        // Load activator/deactivator EARLY — they must be available before
        // plugins_loaded fires (which has already happened during activation).
        require_once FST_PLUGIN_DIR . 'includes/class-fst-activator.php';
        require_once FST_PLUGIN_DIR . 'includes/class-fst-deactivator.php';

        // Activation / deactivation hooks.
        register_activation_hook( __FILE__, array( 'FST_Activator', 'activate' ) );
        register_deactivation_hook( __FILE__, array( 'FST_Deactivator', 'deactivate' ) );
    }

    /**
     * Initialize the plugin — only if WooCommerce is active.
     */
    public function init() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'wc_missing_notice' ) );
            return;
        }

        $this->includes();
        $this->init_hooks();

        // Run DB migrations if needed.
        FST_Activator::maybe_update();
    }

    /**
     * Include required files.
     */
    private function includes() {
        // Core.
        require_once FST_PLUGIN_DIR . 'includes/class-fst-activator.php';
        require_once FST_PLUGIN_DIR . 'includes/class-fst-deactivator.php';
        require_once FST_PLUGIN_DIR . 'includes/class-fst-shipment.php';
        require_once FST_PLUGIN_DIR . 'includes/class-fst-tracker.php';
        require_once FST_PLUGIN_DIR . 'includes/class-fst-order.php';
        require_once FST_PLUGIN_DIR . 'includes/class-fst-settings.php';
        require_once FST_PLUGIN_DIR . 'includes/class-fst-rest-api.php';
        require_once FST_PLUGIN_DIR . 'includes/class-fst-migrator.php';
        require_once FST_PLUGIN_DIR . 'includes/class-fst-email.php';
        require_once FST_PLUGIN_DIR . 'includes/class-fst-myaccount.php';
        require_once FST_PLUGIN_DIR . 'includes/class-fst-updater.php';

        // Carriers.
        require_once FST_PLUGIN_DIR . 'includes/carriers/class-fst-carrier.php';
        require_once FST_PLUGIN_DIR . 'includes/carriers/class-fst-carrier-ups.php';
        require_once FST_PLUGIN_DIR . 'includes/carriers/class-fst-carrier-usps.php';
    }

    /**
     * Hook into WordPress / WooCommerce.
     */
    private function init_hooks() {
        // Initialize components.
        $this->settings = new FST_Settings();
        $this->tracker  = new FST_Tracker();
        $this->order     = new FST_Order();
        $this->myaccount = new FST_MyAccount();

        // REST API — FST_REST_API::register_routes() is called directly, no constructor hook.
        $rest_api = new FST_REST_API();
        add_action( 'rest_api_init', array( $rest_api, 'register_routes' ) );

        // Admin menu.
        add_action( 'admin_menu', array( $this, 'admin_menu' ) );

        // Enqueue admin assets.
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

        // Declare HPOS compatibility.
        add_action( 'before_woocommerce_init', function() {
            if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
            }
        });

        // Migration AJAX handlers.
        add_action( 'wp_ajax_fst_migration_scan', array( $this, 'ajax_migration_scan' ) );
        add_action( 'wp_ajax_fst_migration_import', array( $this, 'ajax_migration_import' ) );

        // Test email AJAX handler.
        add_action( 'wp_ajax_fst_send_test_email', array( $this, 'ajax_send_test_email' ) );

        // Test carrier connection AJAX handlers.
        add_action( 'wp_ajax_fst_test_carrier', array( $this, 'ajax_test_carrier' ) );

        // GitHub auto-updater.
        new FST_Updater( array(
            'slug'        => FST_PLUGIN_BASENAME,
            'repo'        => 'Dierks27/fishotel-shiptracker',
            'version'     => FST_VERSION,
            'plugin_file' => FST_PLUGIN_FILE,
        ) );

        // Settings link on plugins page.
        add_filter( 'plugin_action_links_' . FST_PLUGIN_BASENAME, array( $this, 'plugin_action_links' ) );
    }

    /**
     * Register admin menu pages.
     */
    public function admin_menu() {
        // Top-level menu.
        add_menu_page(
            __( 'ShipTracker', 'fishotel-shiptracker' ),
            __( 'ShipTracker', 'fishotel-shiptracker' ),
            'manage_woocommerce',
            'fst-dashboard',
            array( $this, 'render_dashboard_page' ),
            'dashicons-airplane',
            56 // After WooCommerce.
        );

        // Shipments sub-page (default).
        add_submenu_page(
            'fst-dashboard',
            __( 'Shipments', 'fishotel-shiptracker' ),
            __( 'Shipments', 'fishotel-shiptracker' ),
            'manage_woocommerce',
            'fst-dashboard',
            array( $this, 'render_dashboard_page' )
        );

        // Analytics.
        add_submenu_page(
            'fst-dashboard',
            __( 'Analytics', 'fishotel-shiptracker' ),
            __( 'Analytics', 'fishotel-shiptracker' ),
            'manage_woocommerce',
            'fst-analytics',
            array( $this, 'render_analytics_page' )
        );

        // Settings.
        add_submenu_page(
            'fst-dashboard',
            __( 'Settings', 'fishotel-shiptracker' ),
            __( 'Settings', 'fishotel-shiptracker' ),
            'manage_woocommerce',
            'fst-settings',
            array( $this->settings, 'render_page' )
        );
    }

    /**
     * Render the shipments dashboard.
     */
    public function render_dashboard_page() {
        include FST_PLUGIN_DIR . 'admin/views/shipments-list.php';
    }

    /**
     * Render the analytics page.
     */
    public function render_analytics_page() {
        include FST_PLUGIN_DIR . 'admin/views/analytics-dashboard.php';
    }

    /**
     * Enqueue admin CSS and JS.
     */
    public function admin_assets( $hook ) {
        $screen = get_current_screen();

        // Load on our admin pages and WooCommerce order edit screens.
        $our_pages = array( 'toplevel_page_fst-dashboard', 'shiptracker_page_fst-analytics', 'shiptracker_page_fst-settings', 'shiptracker_page_fst-migration' );
        $is_order_screen = $screen && in_array( $screen->id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true );

        if ( in_array( $hook, $our_pages, true ) || $is_order_screen ) {
            wp_enqueue_style( 'fst-admin', FST_PLUGIN_URL . 'admin/css/admin.css', array(), FST_VERSION );
            wp_enqueue_script( 'fst-admin', FST_PLUGIN_URL . 'admin/js/admin.js', array( 'jquery' ), FST_VERSION, true );
            wp_localize_script( 'fst-admin', 'fst_admin', array(
                'ajax_url' => admin_url( 'admin-ajax.php' ),
                'rest_url' => rest_url( 'fst/v1/' ),
                'nonce'    => wp_create_nonce( 'fst_nonce' ),
                'rest_nonce' => wp_create_nonce( 'wp_rest' ),
            ) );
        }
    }

    /**
     * Add Settings link on plugins page.
     */
    public function plugin_action_links( $links ) {
        $settings_link = '<a href="' . admin_url( 'admin.php?page=fst-settings' ) . '">' . __( 'Settings', 'fishotel-shiptracker' ) . '</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Admin notice if WooCommerce is not active.
     */
    public function wc_missing_notice() {
        echo '<div class="notice notice-error"><p><strong>FisHotel ShipTracker</strong> requires WooCommerce to be installed and active.</p></div>';
    }

    /**
     * Render the migration page.
     */
    public function render_migration_page() {
        include FST_PLUGIN_DIR . 'admin/views/migration.php';
    }

    /**
     * AJAX: Scan for AST/TrackShip data (dry run).
     */
    public function ajax_migration_scan() {
        check_ajax_referer( 'fst_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        $results = FST_Migrator::scan();
        wp_send_json_success( $results );
    }

    /**
     * AJAX: Send a test email to the admin.
     */
    public function ajax_send_test_email() {
        check_ajax_referer( 'fst_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        $to = get_option( 'admin_email' );

        // Build a sample body using the default "in_transit" template.
        $defaults = FST_Email::get_defaults();
        $template = $defaults['in_transit'];

        // Replace shortcodes with sample data.
        $sample_replacements = array(
            '{customer_name}'        => 'John',
            '{order_number}'         => '12345',
            '{tracking_number}'      => '1Z999AA10123456784',
            '{carrier}'              => 'UPS',
            '{status}'               => 'In Transit',
            '{status_detail}'        => 'Package is moving through the UPS network',
            '{est_delivery}'         => date_i18n( get_option( 'date_format' ), strtotime( '+2 days' ) ),
            '{ship_date}'            => date_i18n( get_option( 'date_format' ), strtotime( '-1 day' ) ),
            '{carrier_tracking_url}' => 'https://www.ups.com/track?tracknum=1Z999AA10123456784',
            '{tracking_progress}'    => FST_Email::render_progress_bar( 'in_transit' ),
            '{tracking_events}'      => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0;border:1px solid #eee;border-radius:6px;overflow:hidden;">'
                . '<tr><td style="background:#f9f9f9;padding:10px 16px;font-size:13px;font-weight:600;color:#444;border-bottom:1px solid #eee;">Recent Tracking Updates</td></tr>'
                . '<tr><td style="padding:10px 16px;border-bottom:1px solid #f0f0f0;"><div style="font-size:13px;color:#333;font-weight:500;">Departed facility</div><div style="font-size:11px;color:#999;margin-top:2px;">Louisville, KY &middot; ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) . '</div></td></tr>'
                . '<tr><td style="padding:10px 16px;"><div style="font-size:13px;color:#333;font-weight:500;">Arrived at facility</div><div style="font-size:11px;color:#999;margin-top:2px;">Memphis, TN &middot; ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( '-4 hours' ) ) . '</div></td></tr>'
                . '</table>',
            '{order_summary}'        => '',
            '{track_button}'         => FST_Email::render_track_button( 'https://www.ups.com/track?tracknum=1Z999AA10123456784', 'Track Your Package', '#2196f3' ),
        );

        $subject = str_replace( array_keys( $sample_replacements ), array_values( $sample_replacements ), $template['subject'] );
        $body    = str_replace( array_keys( $sample_replacements ), array_values( $sample_replacements ), $template['body'] );

        $subject = '[TEST] ' . $subject;
        $html    = FST_Email::wrap( nl2br( $body ), 'in_transit' );

        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $sent    = wp_mail( $to, $subject, $html, $headers );

        if ( $sent ) {
            wp_send_json_success( array( 'message' => 'Test email sent to ' . $to ) );
        } else {
            wp_send_json_error( array( 'message' => 'wp_mail() failed. Check your email configuration.' ) );
        }
    }

    /**
     * AJAX: Test a carrier API connection.
     */
    public function ajax_test_carrier() {
        check_ajax_referer( 'fst_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        $carrier = sanitize_text_field( $_POST['carrier'] ?? '' );

        if ( 'ups' === $carrier ) {
            delete_transient( 'fst_ups_access_token' );

            $carrier_obj = new FST_Carrier_UPS();
            if ( ! $carrier_obj->has_credentials() ) {
                wp_send_json_error( array( 'message' => 'UPS credentials not configured. Please save settings first.' ) );
            }

            $result = $carrier_obj->get_access_token();
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }
            wp_send_json_success( array( 'message' => 'UPS connection successful! OAuth token obtained.' ) );

        } elseif ( 'usps' === $carrier ) {
            delete_transient( 'fst_usps_access_token' );

            $carrier_obj = new FST_Carrier_USPS();
            if ( ! $carrier_obj->has_credentials() ) {
                wp_send_json_error( array( 'message' => 'USPS credentials not configured. Please save settings first.' ) );
            }

            $result = $carrier_obj->get_access_token();
            if ( is_wp_error( $result ) ) {
                wp_send_json_error( array( 'message' => $result->get_error_message() ) );
            }
            wp_send_json_success( array( 'message' => 'USPS connection successful! OAuth token obtained.' ) );

        } else {
            wp_send_json_error( array( 'message' => 'Unknown carrier: "' . $carrier . '"' ) );
        }
    }

    /**
     * AJAX: Run the actual import.
     */
    public function ajax_migration_import() {
        check_ajax_referer( 'fst_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
        }

        $results = FST_Migrator::import( true );
        wp_send_json_success( $results );
    }
}

/**
 * Returns the main plugin instance.
 *
 * @return FisHotel_ShipTracker
 */
function FST() {
    return FisHotel_ShipTracker::instance();
}

// Boot!
FST();
