<?php
/**
 * FisHotel ShipTracker - Settings Page
 *
 * Handles the settings page rendering and form submission.
 * Menu registration is handled by the main plugin file — this class only renders
 * the settings page when called.
 *
 * @package FisHotel_ShipTracker
 * @subpackage Admin
 */

defined( 'ABSPATH' ) || exit;

class FST_Settings {

    /**
     * Constructor — hooks form handling only.
     * Menu registration is done in the main plugin file.
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'handle_form_submission' ) );
    }

    /**
     * Handle form submission.
     */
    public function handle_form_submission() {
        if ( ! isset( $_POST['fst_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( $_POST['fst_nonce'], 'fst_settings_nonce' ) ) {
            wp_die( 'Security check failed' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized access' );
        }

        $tab = isset( $_POST['fst_tab'] ) ? sanitize_text_field( $_POST['fst_tab'] ) : 'general';

        switch ( $tab ) {
            case 'general':
                $this->save_general_settings();
                break;
            case 'carriers':
                $this->save_carrier_settings();
                break;
            case 'status_actions':
                $this->save_status_actions();
                break;
            case 'email':
                $this->save_email_templates();
                break;
            case 'tracking_page':
                $this->save_tracking_page_settings();
                break;
            case 'advanced':
                $this->save_advanced_settings();
                break;
        }

        wp_safe_redirect( admin_url( 'admin.php?page=fst-settings&tab=' . $tab . '&updated=1' ) );
        exit;
    }

    /**
     * Render settings page.
     */
    public function render_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized access' );
        }

        $tab     = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general';
        $updated = isset( $_GET['updated'] ) ? sanitize_text_field( $_GET['updated'] ) : '';

        ?>
        <div class="wrap fst-settings-wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <?php if ( $updated ) { ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php esc_html_e( 'Settings updated successfully.', 'fishotel-shiptracker' ); ?></p>
                </div>
            <?php } ?>

            <nav class="nav-tab-wrapper fst-tabs">
                <a href="#general" class="nav-tab <?php echo 'general' === $tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'General', 'fishotel-shiptracker' ); ?>
                </a>
                <a href="#carriers" class="nav-tab <?php echo 'carriers' === $tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Carriers', 'fishotel-shiptracker' ); ?>
                </a>
                <a href="#status_actions" class="nav-tab <?php echo 'status_actions' === $tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Status Actions', 'fishotel-shiptracker' ); ?>
                </a>
                <a href="#email" class="nav-tab <?php echo 'email' === $tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Email Designer', 'fishotel-shiptracker' ); ?>
                </a>
                <a href="#tracking_page" class="nav-tab <?php echo 'tracking_page' === $tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Tracking Page', 'fishotel-shiptracker' ); ?>
                </a>
                <a href="#advanced" class="nav-tab <?php echo 'advanced' === $tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Advanced', 'fishotel-shiptracker' ); ?>
                </a>
            </nav>

            <form method="post" class="fst-settings-form">
                <?php wp_nonce_field( 'fst_settings_nonce', 'fst_nonce' ); ?>
                <input type="hidden" name="fst_tab" value="<?php echo esc_attr( $tab ); ?>">

                <div id="general" class="fst-tab-content <?php echo 'general' === $tab ? 'active' : ''; ?>">
                    <?php $this->render_general_tab(); ?>
                </div>

                <div id="carriers" class="fst-tab-content <?php echo 'carriers' === $tab ? 'active' : ''; ?>">
                    <?php $this->render_carriers_tab(); ?>
                </div>

                <div id="status_actions" class="fst-tab-content <?php echo 'status_actions' === $tab ? 'active' : ''; ?>">
                    <?php $this->render_status_actions_tab(); ?>
                </div>

                <div id="email" class="fst-tab-content <?php echo 'email' === $tab ? 'active' : ''; ?>">
                    <?php $this->render_email_tab(); ?>
                </div>

                <div id="tracking_page" class="fst-tab-content <?php echo 'tracking_page' === $tab ? 'active' : ''; ?>">
                    <?php $this->render_tracking_page_tab(); ?>
                </div>

                <div id="advanced" class="fst-tab-content <?php echo 'advanced' === $tab ? 'active' : ''; ?>">
                    <?php $this->render_advanced_tab(); ?>
                </div>

                <?php submit_button(); ?>
            </form>
        </div>

        <script>
            jQuery(function($) {
                $('.nav-tab').on('click', function(e) {
                    e.preventDefault();
                    var tab = $(this).attr('href').substring(1);
                    $('.fst-tab-content').removeClass('active');
                    $('#' + tab).addClass('active');
                    $('input[name="fst_tab"]').val(tab);
                    $('.nav-tab').removeClass('nav-tab-active');
                    $(this).addClass('nav-tab-active');
                });
            });
        </script>
        <?php
    }

    /**
     * Render General tab.
     */
    private function render_general_tab() {
        $default_carrier = get_option( 'fst_default_carrier', 'ups' );
        $auto_detect     = get_option( 'fst_auto_detect_carrier', true );
        $auto_complete   = get_option( 'fst_auto_complete_orders', true );

        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="fst_default_carrier"><?php esc_html_e( 'Default Carrier', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <select id="fst_default_carrier" name="fst_default_carrier">
                        <option value="ups" <?php selected( $default_carrier, 'ups' ); ?>>UPS</option>
                        <option value="usps" <?php selected( $default_carrier, 'usps' ); ?>>USPS</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_auto_detect_carrier"><?php esc_html_e( 'Auto-Detect Carrier', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="fst_auto_detect_carrier" name="fst_auto_detect_carrier" value="1" <?php checked( $auto_detect ); ?>>
                    <p class="description"><?php esc_html_e( 'Automatically detect carrier from tracking number format', 'fishotel-shiptracker' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_auto_complete_orders"><?php esc_html_e( 'Auto-Complete Orders', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="fst_auto_complete_orders" name="fst_auto_complete_orders" value="1" <?php checked( $auto_complete ); ?>>
                    <p class="description"><?php esc_html_e( 'Automatically mark orders complete when shipment is delivered', 'fishotel-shiptracker' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Carriers tab.
     */
    private function render_carriers_tab() {
        $ups_client_id      = get_option( 'fst_ups_client_id', '' );
        $ups_client_secret  = get_option( 'fst_ups_client_secret', '' );
        $usps_client_id     = get_option( 'fst_usps_client_id', '' );
        $usps_client_secret = get_option( 'fst_usps_client_secret', '' );

        ?>
        <h3><?php esc_html_e( 'UPS Configuration', 'fishotel-shiptracker' ); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="fst_ups_client_id"><?php esc_html_e( 'UPS Client ID', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="password" id="fst_ups_client_id" name="fst_ups_client_id" value="<?php echo esc_attr( $ups_client_id ); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_ups_client_secret"><?php esc_html_e( 'UPS Client Secret', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="password" id="fst_ups_client_secret" name="fst_ups_client_secret" value="<?php echo esc_attr( $ups_client_secret ); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"></th>
                <td>
                    <button type="button" class="button" id="fst_test_ups"><?php esc_html_e( 'Test UPS Connection', 'fishotel-shiptracker' ); ?></button>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'USPS Configuration', 'fishotel-shiptracker' ); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="fst_usps_client_id"><?php esc_html_e( 'USPS Client ID', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="password" id="fst_usps_client_id" name="fst_usps_client_id" value="<?php echo esc_attr( $usps_client_id ); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_usps_client_secret"><?php esc_html_e( 'USPS Client Secret', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="password" id="fst_usps_client_secret" name="fst_usps_client_secret" value="<?php echo esc_attr( $usps_client_secret ); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"></th>
                <td>
                    <button type="button" class="button" id="fst_test_usps"><?php esc_html_e( 'Test USPS Connection', 'fishotel-shiptracker' ); ?></button>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Status Actions tab.
     */
    private function render_status_actions_tab() {
        $statuses = array(
            'label_created'        => 'Label Created',
            'pre_transit'          => 'Pre-Transit',
            'in_transit'           => 'In Transit',
            'out_for_delivery'     => 'Out for Delivery',
            'delivered'            => 'Delivered',
            'exception'            => 'Exception',
            'available_for_pickup' => 'Available for Pickup',
            'return_to_sender'     => 'Return to Sender',
            'failure'              => 'Delivery Failed',
        );

        $status_actions = get_option( 'fst_status_actions', array() );

        ?>
        <table class="form-table">
            <?php foreach ( $statuses as $status_key => $status_label ) { ?>
                <?php
                    $actions        = isset( $status_actions[ $status_key ] ) ? $status_actions[ $status_key ] : array();
                    $email_customer = isset( $actions['email_customer'] ) ? $actions['email_customer'] : 0;
                    $email_admin    = isset( $actions['email_admin'] ) ? $actions['email_admin'] : 0;
                    $order_status   = isset( $actions['order_status'] ) ? $actions['order_status'] : 'none';
                ?>
                <tr>
                    <th scope="row" colspan="2">
                        <h4><?php echo esc_html( $status_label ); ?></h4>
                    </th>
                </tr>
                <tr>
                    <td style="padding-left: 40px;">
                        <label>
                            <input type="checkbox" name="fst_status_actions[<?php echo esc_attr( $status_key ); ?>][email_customer]" value="1" <?php checked( $email_customer ); ?>>
                            <?php esc_html_e( 'Send Customer Email', 'fishotel-shiptracker' ); ?>
                        </label>
                    </td>
                    <td>
                        <label>
                            <input type="checkbox" name="fst_status_actions[<?php echo esc_attr( $status_key ); ?>][email_admin]" value="1" <?php checked( $email_admin ); ?>>
                            <?php esc_html_e( 'Send Admin Email', 'fishotel-shiptracker' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row" style="padding-left: 40px;">
                        <?php esc_html_e( 'Update Order Status To:', 'fishotel-shiptracker' ); ?>
                    </th>
                    <td>
                        <select name="fst_status_actions[<?php echo esc_attr( $status_key ); ?>][order_status]">
                            <option value="none" <?php selected( $order_status, 'none' ); ?>><?php esc_html_e( 'No Change', 'fishotel-shiptracker' ); ?></option>
                            <option value="completed" <?php selected( $order_status, 'completed' ); ?>>Completed</option>
                            <option value="processing" <?php selected( $order_status, 'processing' ); ?>>Processing</option>
                            <option value="on-hold" <?php selected( $order_status, 'on-hold' ); ?>>On Hold</option>
                        </select>
                    </td>
                </tr>
            <?php } ?>
        </table>
        <?php
    }

    /**
     * Render Email Designer tab.
     */
    private function render_email_tab() {
        $statuses = array(
            'label_created'        => 'Label Created',
            'pre_transit'          => 'Pre-Transit',
            'in_transit'           => 'In Transit',
            'out_for_delivery'     => 'Out for Delivery',
            'delivered'            => 'Delivered',
            'exception'            => 'Exception',
            'available_for_pickup' => 'Available for Pickup',
            'return_to_sender'     => 'Return to Sender',
            'failure'              => 'Delivery Failed',
        );

        $email_templates = get_option( 'fst_email_templates', array() );
        $defaults        = FST_Email::get_defaults();

        ?>
        <div style="background: #fff; border: 1px solid #ccd0d4; border-left: 4px solid #007cba; padding: 14px 18px; margin-bottom: 20px;">
            <p style="margin: 0 0 10px; font-weight: 600;"><?php esc_html_e( 'Available Shortcodes', 'fishotel-shiptracker' ); ?></p>
            <div style="display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 13px;">
                <code>{customer_name}</code>
                <code>{order_number}</code>
                <code>{tracking_number}</code>
                <code>{carrier}</code>
                <code>{status}</code>
                <code>{status_detail}</code>
                <code>{est_delivery}</code>
                <code>{ship_date}</code>
                <code>{carrier_tracking_url}</code>
            </div>
            <p style="margin: 10px 0 0; font-weight: 600;"><?php esc_html_e( 'Widget Shortcodes (auto-render HTML)', 'fishotel-shiptracker' ); ?></p>
            <div style="display: flex; flex-wrap: wrap; gap: 6px 16px; font-size: 13px;">
                <span><code>{tracking_progress}</code> — <?php esc_html_e( 'Visual progress bar', 'fishotel-shiptracker' ); ?></span>
                <span><code>{tracking_events}</code> — <?php esc_html_e( 'Recent tracking updates', 'fishotel-shiptracker' ); ?></span>
                <span><code>{order_summary}</code> — <?php esc_html_e( 'Order items &amp; total', 'fishotel-shiptracker' ); ?></span>
                <span><code>{track_button}</code> — <?php esc_html_e( 'Track on carrier site button', 'fishotel-shiptracker' ); ?></span>
            </div>
        </div>

        <p style="margin-bottom: 8px;">
            <button type="button" class="button" id="fst-send-test-email">
                <?php esc_html_e( 'Send Test Email', 'fishotel-shiptracker' ); ?>
            </button>
            <span style="color: #666; font-size: 13px; margin-left: 8px;">
                <?php printf( esc_html__( 'Sends a sample "In Transit" email to %s', 'fishotel-shiptracker' ), esc_html( get_option( 'admin_email' ) ) ); ?>
            </span>
            <span id="fst-test-email-status" style="margin-left: 8px;"></span>
        </p>

        <?php foreach ( $statuses as $status_key => $status_label ) {
            $template = isset( $email_templates[ $status_key ] ) ? $email_templates[ $status_key ] : array();
            $default  = isset( $defaults[ $status_key ] ) ? $defaults[ $status_key ] : array();
            $subject  = isset( $template['subject'] ) && '' !== $template['subject'] ? $template['subject'] : '';
            $body     = isset( $template['body'] ) && '' !== $template['body'] ? $template['body'] : '';
            $def_subj = isset( $default['subject'] ) ? $default['subject'] : '';
            $def_body = isset( $default['body'] ) ? $default['body'] : '';
            $color    = FST_Carrier::get_status_color( $status_key );
        ?>
            <div style="border-left: 3px solid <?php echo esc_attr( $color ); ?>; padding-left: 16px; margin: 24px 0;">
                <h3 style="margin: 0 0 8px; color: <?php echo esc_attr( $color ); ?>;"><?php echo esc_html( $status_label ); ?></h3>
                <table class="form-table" style="margin-top: 0;">
                    <tr>
                        <th scope="row" style="width: 80px;">
                            <label for="fst_email_subject_<?php echo esc_attr( $status_key ); ?>"><?php esc_html_e( 'Subject', 'fishotel-shiptracker' ); ?></label>
                        </th>
                        <td>
                            <input type="text" id="fst_email_subject_<?php echo esc_attr( $status_key ); ?>"
                                   name="fst_email_templates[<?php echo esc_attr( $status_key ); ?>][subject]"
                                   value="<?php echo esc_attr( $subject ); ?>"
                                   placeholder="<?php echo esc_attr( $def_subj ); ?>"
                                   class="large-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fst_email_body_<?php echo esc_attr( $status_key ); ?>"><?php esc_html_e( 'Body', 'fishotel-shiptracker' ); ?></label>
                        </th>
                        <td>
                            <textarea id="fst_email_body_<?php echo esc_attr( $status_key ); ?>"
                                      name="fst_email_templates[<?php echo esc_attr( $status_key ); ?>][body]"
                                      rows="6" class="large-text"
                                      placeholder="<?php echo esc_attr( $def_body ); ?>"><?php echo esc_textarea( $body ); ?></textarea>
                            <p class="description"><?php esc_html_e( 'Leave blank to use the built-in default template.', 'fishotel-shiptracker' ); ?></p>
                        </td>
                    </tr>
                </table>
            </div>
        <?php } ?>

        <script>
        jQuery(function($) {
            $('#fst-send-test-email').on('click', function() {
                var $btn    = $(this);
                var $status = $('#fst-test-email-status');
                $btn.prop('disabled', true);
                $status.html('<span style="color:#666;">Sending...</span>');

                $.post(fst_admin.ajax_url, {
                    action: 'fst_send_test_email',
                    nonce: fst_admin.nonce
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $status.html('<span style="color:#2e7d32;">&#10003; Test email sent!</span>');
                    } else {
                        $status.html('<span style="color:#d63638;">&#10007; ' + (response.data.message || 'Failed') + '</span>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $status.html('<span style="color:#d63638;">&#10007; Request failed</span>');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render Tracking Page tab.
     */
    private function render_tracking_page_tab() {
        $bg_color     = get_option( 'fst_tracking_bg_color', '#ffffff' );
        $accent_color = get_option( 'fst_tracking_accent_color', '#007cba' );
        $text_color   = get_option( 'fst_tracking_text_color', '#333333' );
        $custom_css   = get_option( 'fst_tracking_custom_css', '' );

        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="fst_tracking_bg_color"><?php esc_html_e( 'Background Color', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="color" id="fst_tracking_bg_color" name="fst_tracking_bg_color" value="<?php echo esc_attr( $bg_color ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_tracking_accent_color"><?php esc_html_e( 'Accent Color', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="color" id="fst_tracking_accent_color" name="fst_tracking_accent_color" value="<?php echo esc_attr( $accent_color ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_tracking_text_color"><?php esc_html_e( 'Text Color', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="color" id="fst_tracking_text_color" name="fst_tracking_text_color" value="<?php echo esc_attr( $text_color ); ?>">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_tracking_custom_css"><?php esc_html_e( 'Custom CSS', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <textarea id="fst_tracking_custom_css" name="fst_tracking_custom_css" rows="10" class="large-text"><?php echo esc_textarea( $custom_css ); ?></textarea>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render Advanced tab.
     */
    private function render_advanced_tab() {
        $polling_interval = get_option( 'fst_polling_interval', 3600 );
        $debug_enabled    = get_option( 'fst_debug_logging', 'no' );
        $data_retention   = get_option( 'fst_data_retention_days', 1095 );

        ?>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="fst_polling_interval"><?php esc_html_e( 'Polling Interval (seconds)', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="number" id="fst_polling_interval" name="fst_polling_interval" value="<?php echo esc_attr( $polling_interval ); ?>" min="300">
                    <p class="description"><?php esc_html_e( 'Minimum 300 seconds (5 minutes)', 'fishotel-shiptracker' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_debug_logging"><?php esc_html_e( 'Enable Debug Logging', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="fst_debug_logging" name="fst_debug_logging" value="yes" <?php checked( $debug_enabled, 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Writes detailed logs to wp-content/fst-debug.log', 'fishotel-shiptracker' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_data_retention_days"><?php esc_html_e( 'Data Retention (days)', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="number" id="fst_data_retention_days" name="fst_data_retention_days" value="<?php echo esc_attr( $data_retention ); ?>" min="0">
                    <p class="description"><?php esc_html_e( 'How long to keep shipment history. 0 = keep forever. Default 1095 (3 years).', 'fishotel-shiptracker' ); ?></p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Save general settings.
     */
    private function save_general_settings() {
        update_option( 'fst_default_carrier', sanitize_text_field( $_POST['fst_default_carrier'] ) );
        update_option( 'fst_auto_detect_carrier', isset( $_POST['fst_auto_detect_carrier'] ) ? 1 : 0 );
        update_option( 'fst_auto_complete_orders', isset( $_POST['fst_auto_complete_orders'] ) ? 1 : 0 );
    }

    /**
     * Save carrier settings.
     */
    private function save_carrier_settings() {
        update_option( 'fst_ups_client_id', sanitize_text_field( $_POST['fst_ups_client_id'] ) );
        update_option( 'fst_ups_client_secret', sanitize_text_field( $_POST['fst_ups_client_secret'] ) );
        update_option( 'fst_usps_client_id', sanitize_text_field( $_POST['fst_usps_client_id'] ) );
        update_option( 'fst_usps_client_secret', sanitize_text_field( $_POST['fst_usps_client_secret'] ) );
    }

    /**
     * Save status actions.
     */
    private function save_status_actions() {
        $status_actions = isset( $_POST['fst_status_actions'] ) ? wp_kses_post_deep( $_POST['fst_status_actions'] ) : array();
        update_option( 'fst_status_actions', $status_actions );
    }

    /**
     * Save email templates.
     */
    private function save_email_templates() {
        $email_templates = isset( $_POST['fst_email_templates'] ) ? wp_kses_post_deep( $_POST['fst_email_templates'] ) : array();
        update_option( 'fst_email_templates', $email_templates );
    }

    /**
     * Save tracking page settings.
     */
    private function save_tracking_page_settings() {
        update_option( 'fst_tracking_bg_color', sanitize_text_field( $_POST['fst_tracking_bg_color'] ) );
        update_option( 'fst_tracking_accent_color', sanitize_text_field( $_POST['fst_tracking_accent_color'] ) );
        update_option( 'fst_tracking_text_color', sanitize_text_field( $_POST['fst_tracking_text_color'] ) );
        update_option( 'fst_tracking_custom_css', wp_kses_post( $_POST['fst_tracking_custom_css'] ) );
    }

    /**
     * Save advanced settings.
     */
    private function save_advanced_settings() {
        update_option( 'fst_polling_interval', absint( $_POST['fst_polling_interval'] ) );
        update_option( 'fst_debug_logging', isset( $_POST['fst_debug_logging'] ) ? 'yes' : 'no' );
        update_option( 'fst_data_retention_days', absint( $_POST['fst_data_retention_days'] ) );
    }
}
