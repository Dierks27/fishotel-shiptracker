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
        add_action( 'admin_init', array( $this, 'handle_label_cost_import' ) );
    }

    /**
     * The standard boxes saved under Settings → Boxes & label costs.
     *
     * Public contract — the theme's Shipments box reads this to offer the
     * same sizes, and GET /fst/v1/shipping-options returns it. Nothing is
     * seeded: the list is empty until the owner fills it in.
     *
     * @return array[] [ { name, length, width, height, dims } ] — sizes in
     *                 inches as number strings ("10", "12.5"); `dims` is the
     *                 normalised "LxWxH" stored on a shipment (box_dims).
     */
    public static function get_standard_boxes() {
        $boxes = array();
        foreach ( self::sanitize_standard_boxes( get_option( 'fst_standard_boxes', array() ) ) as $box ) {
            $box['dims'] = $box['length'] . 'x' . $box['width'] . 'x' . $box['height'];
            $boxes[]     = $box;
        }
        return $boxes;
    }

    /**
     * The label sources saved under Settings → Boxes & label costs — the
     * partners labels are bought through.
     *
     * Public contract — read by the theme and returned by
     * GET /fst/v1/shipping-options. Nothing is seeded. A shipment's
     * label_source may still hold free text that is not on this list.
     *
     * @return string[]
     */
    public static function get_label_sources() {
        return self::sanitize_label_sources( get_option( 'fst_label_sources', array() ) );
    }

    /**
     * Clean a submitted (or stored) list of standard boxes: a row needs a
     * name and three sizes above 0; anything else is dropped, as is a second
     * box with the same name.
     *
     * @param mixed $raw [ { name, length, width, height } ]
     * @return array[] [ { name, length, width, height } ]
     */
    public static function sanitize_standard_boxes( $raw ) {
        $boxes = array();
        $seen  = array();

        foreach ( (array) $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $name = isset( $row['name'] ) && is_scalar( $row['name'] )
                ? FST_Label_Cost::limit_length( sanitize_text_field( (string) $row['name'] ), 40 )
                : '';
            $dims = FST_Label_Cost::parse_dims(
                array(
                    isset( $row['length'] ) ? $row['length'] : '',
                    isset( $row['width'] ) ? $row['width'] : '',
                    isset( $row['height'] ) ? $row['height'] : '',
                ),
                array()
            );

            $key = FST_Label_Cost::lower( $name );
            if ( '' === $name || ! is_string( $dims ) || isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;

            list( $length, $width, $height ) = explode( 'x', $dims );
            $boxes[] = array(
                'name'   => $name,
                'length' => $length,
                'width'  => $width,
                'height' => $height,
            );
        }

        return $boxes;
    }

    /**
     * Clean a submitted (or stored) list of label sources: trimmed to 40
     * characters, blanks and repeats (any letter case) dropped.
     *
     * @param mixed $raw
     * @return string[]
     */
    public static function sanitize_label_sources( $raw ) {
        $sources = array();
        $seen    = array();

        foreach ( (array) $raw as $source ) {
            if ( ! is_scalar( $source ) || is_bool( $source ) ) {
                continue;
            }
            $source = FST_Label_Cost::limit_length( sanitize_text_field( (string) $source ), 40 );
            $key    = FST_Label_Cost::lower( $source );
            if ( '' === $source || isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $sources[]    = $source;
        }

        return $sources;
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
            case 'boxes':
                $this->save_boxes_settings();
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

        $tab      = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'general';
        $updated  = isset( $_GET['updated'] ) ? sanitize_text_field( $_GET['updated'] ) : '';
        $imported = isset( $_GET['imported'] ) ? sanitize_text_field( $_GET['imported'] ) : '';

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
                <a href="#boxes" class="nav-tab <?php echo 'boxes' === $tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Boxes & label costs', 'fishotel-shiptracker' ); ?>
                </a>
                <a href="#migration" class="nav-tab <?php echo 'migration' === $tab ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Migration', 'fishotel-shiptracker' ); ?>
                </a>
            </nav>

            <form method="post" class="fst-settings-form" autocomplete="off">
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

                <div id="boxes" class="fst-tab-content <?php echo 'boxes' === $tab ? 'active' : ''; ?>">
                    <?php $this->render_boxes_tab(); ?>
                </div>

                <?php if ( 'migration' !== $tab ) : ?>
                    <?php submit_button(); ?>
                <?php endif; ?>
            </form>

            <?php // The import posts a file, so it is its own form below the settings form (same tab). ?>
            <div class="fst-tab-content <?php echo 'boxes' === $tab ? 'active' : ''; ?>" data-fst-tab="boxes">
                <?php $this->render_label_cost_import( $imported ); ?>
            </div>

            <div id="migration" class="fst-tab-content <?php echo 'migration' === $tab ? 'active' : ''; ?>">
                <?php include FST_PLUGIN_DIR . 'admin/views/migration.php'; ?>
            </div>
        </div>

        <?php
    }

    /**
     * Render General tab.
     */
    private function render_general_tab() {
        $default_carrier = get_option( 'fst_default_carrier', 'ups' );
        $auto_detect     = get_option( 'fst_auto_detect_carrier', true );
        $auto_complete   = get_option( 'fst_auto_complete_orders', true );
        $sms_enabled     = get_option( 'fst_sms_notifications', false );

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
            <tr>
                <th scope="row">
                    <label for="fst_sms_notifications"><?php esc_html_e( 'SMS Notifications', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="fst_sms_notifications" name="fst_sms_notifications" value="1" <?php checked( $sms_enabled ); ?>>
                    <p class="description"><?php esc_html_e( 'Allow customers to opt-in to SMS text notifications at checkout (via email-to-SMS gateway)', 'fishotel-shiptracker' ); ?></p>
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

        $ep_production_key  = get_option( 'fst_easypost_production_key', '' );
        $ep_test_key        = get_option( 'fst_easypost_test_key', '' );
        $ep_webhook_secret  = get_option( 'fst_easypost_webhook_secret', '' );
        $ep_test_mode       = get_option( 'fst_easypost_test_mode', 'no' );
        $ep_usps_routing    = get_option( 'fst_easypost_usps_routing', 'auto' );
        $ep_webhook_url     = rest_url( 'fst/v1/easypost-webhook' );

        ?>
        <h3><?php esc_html_e( 'UPS Configuration', 'fishotel-shiptracker' ); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="fst_ups_client_id"><?php esc_html_e( 'UPS Client ID', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="text" id="fst_ups_client_id" name="fst_ups_client_id" value="<?php echo esc_attr( $ups_client_id ); ?>" class="regular-text" autocomplete="off">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_ups_client_secret"><?php esc_html_e( 'UPS Client Secret', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="text" id="fst_ups_client_secret" name="fst_ups_client_secret" value="<?php echo esc_attr( $ups_client_secret ); ?>" class="regular-text" autocomplete="off">
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
                    <input type="text" id="fst_usps_client_id" name="fst_usps_client_id" value="<?php echo esc_attr( $usps_client_id ); ?>" class="regular-text" autocomplete="off">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_usps_client_secret"><?php esc_html_e( 'USPS Client Secret', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="text" id="fst_usps_client_secret" name="fst_usps_client_secret" value="<?php echo esc_attr( $usps_client_secret ); ?>" class="regular-text" autocomplete="off">
                </td>
            </tr>
            <tr>
                <th scope="row"></th>
                <td>
                    <button type="button" class="button" id="fst_test_usps"><?php esc_html_e( 'Test USPS Connection', 'fishotel-shiptracker' ); ?></button>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'EasyPost (USPS Aggregator)', 'fishotel-shiptracker' ); ?></h3>
        <p class="description" style="max-width: 640px;">
            <?php esc_html_e( 'Since the April 2026 USPS Access Control change, USPS only returns tracking for packages printed under your own Mailer ID. Routing USPS through EasyPost tracks any USPS package regardless of who printed the label. EasyPost pushes updates to the webhook URL below.', 'fishotel-shiptracker' ); ?>
        </p>
        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="fst_easypost_production_key"><?php esc_html_e( 'Production API Key', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="text" id="fst_easypost_production_key" name="fst_easypost_production_key" value="<?php echo esc_attr( $ep_production_key ); ?>" class="regular-text" autocomplete="off">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_easypost_test_key"><?php esc_html_e( 'Test API Key', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="text" id="fst_easypost_test_key" name="fst_easypost_test_key" value="<?php echo esc_attr( $ep_test_key ); ?>" class="regular-text" autocomplete="off">
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_easypost_webhook_secret"><?php esc_html_e( 'Webhook Secret', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="text" id="fst_easypost_webhook_secret" name="fst_easypost_webhook_secret" value="<?php echo esc_attr( $ep_webhook_secret ); ?>" class="regular-text" autocomplete="off">
                    <button type="button" class="button" id="fst_generate_webhook_secret"><?php esc_html_e( 'Generate', 'fishotel-shiptracker' ); ?></button>
                    <p class="description"><?php esc_html_e( 'Use the same secret here and in the EasyPost dashboard. Click Generate, then Save.', 'fishotel-shiptracker' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_easypost_test_mode"><?php esc_html_e( 'Test Mode', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="checkbox" id="fst_easypost_test_mode" name="fst_easypost_test_mode" value="yes" <?php checked( $ep_test_mode, 'yes' ); ?>>
                    <p class="description"><?php esc_html_e( 'Use the Test API Key instead of Production.', 'fishotel-shiptracker' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_easypost_usps_routing"><?php esc_html_e( 'USPS Routing', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <select id="fst_easypost_usps_routing" name="fst_easypost_usps_routing">
                        <option value="auto" <?php selected( $ep_usps_routing, 'auto' ); ?>><?php esc_html_e( 'Auto — use EasyPost if configured (recommended)', 'fishotel-shiptracker' ); ?></option>
                        <option value="always" <?php selected( $ep_usps_routing, 'always' ); ?>><?php esc_html_e( 'Always — route every USPS shipment through EasyPost', 'fishotel-shiptracker' ); ?></option>
                        <option value="never" <?php selected( $ep_usps_routing, 'never' ); ?>><?php esc_html_e( 'Never — always poll USPS directly', 'fishotel-shiptracker' ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row">
                    <label for="fst_easypost_webhook_url"><?php esc_html_e( 'Webhook URL', 'fishotel-shiptracker' ); ?></label>
                </th>
                <td>
                    <input type="text" id="fst_easypost_webhook_url" value="<?php echo esc_attr( $ep_webhook_url ); ?>" class="regular-text" readonly onclick="this.select();">
                    <button type="button" class="button" id="fst_copy_webhook_url"><?php esc_html_e( 'Copy', 'fishotel-shiptracker' ); ?></button>
                    <p class="description"><?php esc_html_e( 'Paste this into the EasyPost dashboard → Webhooks → Add Webhook.', 'fishotel-shiptracker' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"></th>
                <td>
                    <button type="button" class="button" id="fst_test_easypost"><?php esc_html_e( 'Test EasyPost Connection', 'fishotel-shiptracker' ); ?></button>
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
                            <option value="shipped" <?php selected( $order_status, 'shipped' ); ?>>Shipped</option>
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
     * Render the Boxes & label costs tab: the standard boxes and the label
     * sources. Both lists start empty — nothing is seeded.
     */
    private function render_boxes_tab() {
        $boxes   = self::get_standard_boxes();
        $sources = self::get_label_sources();

        // Always end with one blank row to type into.
        $boxes[]   = array( 'name' => '', 'length' => '', 'width' => '', 'height' => '' );
        $sources[] = '';

        ?>
        <h3><?php esc_html_e( 'Standard Boxes', 'fishotel-shiptracker' ); ?></h3>
        <p class="description" style="max-width: 640px;">
            <?php esc_html_e( 'The boxes you usually ship in, in inches. They are offered as a dropdown wherever a label cost is entered; a custom size can always be typed instead. A row needs a name and all three sizes to be saved.', 'fishotel-shiptracker' ); ?>
        </p>
        <table class="widefat striped fst-list-table" id="fst-standard-boxes" style="max-width: 640px; margin-top: 12px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Length (in)', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Width (in)', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Height (in)', 'fishotel-shiptracker' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $boxes as $index => $box ) : ?>
                    <tr class="fst-list-row">
                        <td>
                            <input type="text" name="fst_standard_boxes[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $box['name'] ); ?>" maxlength="40" style="width: 100%;">
                        </td>
                        <?php foreach ( array( 'length', 'width', 'height' ) as $side ) : ?>
                            <td>
                                <input type="number" name="fst_standard_boxes[<?php echo esc_attr( $index ); ?>][<?php echo esc_attr( $side ); ?>]" value="<?php echo esc_attr( $box[ $side ] ); ?>" min="0" step="0.01" style="width: 90px;">
                            </td>
                        <?php endforeach; ?>
                        <td>
                            <a href="#" class="fst-list-remove" title="<?php esc_attr_e( 'Remove this box', 'fishotel-shiptracker' ); ?>">&times; <?php esc_html_e( 'Remove', 'fishotel-shiptracker' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <button type="button" class="button fst-list-add" data-target="#fst-standard-boxes"><?php esc_html_e( 'Add another box', 'fishotel-shiptracker' ); ?></button>
        </p>

        <h3 style="margin-top: 30px;"><?php esc_html_e( 'Label Sources', 'fishotel-shiptracker' ); ?></h3>
        <p class="description" style="max-width: 640px;">
            <?php esc_html_e( 'Where you buy labels — one name per partner. The Analytics page compares their average cost per box size, so you can see who is cheaper for each box.', 'fishotel-shiptracker' ); ?>
        </p>
        <table class="widefat striped fst-list-table" id="fst-label-sources" style="max-width: 420px; margin-top: 12px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'fishotel-shiptracker' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $sources as $source ) : ?>
                    <tr class="fst-list-row">
                        <td>
                            <input type="text" name="fst_label_sources[]" value="<?php echo esc_attr( $source ); ?>" maxlength="40" style="width: 100%;">
                        </td>
                        <td>
                            <a href="#" class="fst-list-remove" title="<?php esc_attr_e( 'Remove this source', 'fishotel-shiptracker' ); ?>">&times; <?php esc_html_e( 'Remove', 'fishotel-shiptracker' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <button type="button" class="button fst-list-add" data-target="#fst-label-sources"><?php esc_html_e( 'Add another source', 'fishotel-shiptracker' ); ?></button>
        </p>
        <?php
    }

    /**
     * Render the "Import label costs" tool (its own form, below the
     * settings form on the Boxes & label costs tab) and the report of the
     * import that just ran.
     *
     * @param string $imported '1' right after an import.
     */
    private function render_label_cost_import( $imported ) {
        $sources = self::get_label_sources();
        $result  = false;
        if ( $imported ) {
            // Shown once: the report is dropped as soon as it has been read.
            $result = get_transient( 'fst_cost_import_' . get_current_user_id() );
            delete_transient( 'fst_cost_import_' . get_current_user_id() );
        }

        ?>
        <div style="border-top: 2px solid #f0f0f0; margin-top: 30px; padding-top: 10px; max-width: 800px;">
            <h3><?php esc_html_e( 'Import label costs', 'fishotel-shiptracker' ); ?></h3>

            <?php if ( is_array( $result ) ) : ?>
                <?php $this->render_label_cost_import_result( $result ); ?>
            <?php endif; ?>

            <p class="description">
                <?php esc_html_e( 'Record what labels cost from a spreadsheet export. The file needs a header row with a tracking column (tracking, tracking number, tracking #) and a cost column (cost, amount, total, charge, price). Weight, dimensions / box and source columns are used when present. Only tracking numbers that already exist in ShipTracker are updated, and only these four fields are ever changed.', 'fishotel-shiptracker' ); ?>
            </p>

            <form method="post" enctype="multipart/form-data" autocomplete="off">
                <?php wp_nonce_field( 'fst_import_label_costs', 'fst_import_costs_nonce' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="fst_cost_csv_file"><?php esc_html_e( 'CSV file', 'fishotel-shiptracker' ); ?></label>
                        </th>
                        <td>
                            <input type="file" id="fst_cost_csv_file" name="fst_cost_csv_file" accept=".csv,.txt,text/csv,text/plain">
                            <p class="description"><?php esc_html_e( 'Up to 2 MB.', 'fishotel-shiptracker' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fst_cost_csv_text"><?php esc_html_e( 'Or paste the rows', 'fishotel-shiptracker' ); ?></label>
                        </th>
                        <td>
                            <textarea id="fst_cost_csv_text" name="fst_cost_csv_text" rows="6" class="large-text code" placeholder="<?php echo esc_attr( "Tracking #,Cost\n1Z999AA10123456784,18.40" ); ?>"></textarea>
                            <p class="description"><?php esc_html_e( 'Include the header row. Cells copied straight from a spreadsheet work too.', 'fishotel-shiptracker' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="fst_cost_csv_source"><?php esc_html_e( 'These labels were bought through', 'fishotel-shiptracker' ); ?></label>
                        </th>
                        <td>
                            <?php if ( ! empty( $sources ) ) : ?>
                                <select id="fst_cost_csv_source" name="fst_cost_csv_source">
                                    <option value=""><?php esc_html_e( '— Not recorded —', 'fishotel-shiptracker' ); ?></option>
                                    <?php foreach ( $sources as $source ) : ?>
                                        <option value="<?php echo esc_attr( $source ); ?>"><?php echo esc_html( $source ); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else : ?>
                                <input type="text" id="fst_cost_csv_source" name="fst_cost_csv_source" maxlength="40" class="regular-text">
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e( 'Used for the whole file when it has no source column.', 'fishotel-shiptracker' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Overwrite', 'fishotel-shiptracker' ); ?></th>
                        <td>
                            <label for="fst_cost_csv_overwrite">
                                <input type="checkbox" id="fst_cost_csv_overwrite" name="fst_cost_csv_overwrite" value="1">
                                <?php esc_html_e( 'Replace costs that are already recorded', 'fishotel-shiptracker' ); ?>
                            </label>
                            <p class="description"><?php esc_html_e( 'Left unticked, a box that already has a cost keeps it and only its empty fields are filled in.', 'fishotel-shiptracker' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button( __( 'Import label costs', 'fishotel-shiptracker' ), 'secondary', 'fst_import_costs_submit' ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render the outcome of a label cost import.
     *
     * @param array $result FST_Label_Cost::import() result.
     */
    private function render_label_cost_import_result( array $result ) {
        if ( ! empty( $result['error'] ) ) {
            ?>
            <div class="notice notice-error inline"><p><?php echo esc_html( $result['error'] ); ?></p></div>
            <?php
            return;
        }

        $labels  = array(
            'tracking' => __( 'tracking', 'fishotel-shiptracker' ),
            'cost'     => __( 'cost', 'fishotel-shiptracker' ),
            'weight'   => __( 'weight', 'fishotel-shiptracker' ),
            'dims'     => __( 'box size', 'fishotel-shiptracker' ),
            'source'   => __( 'source', 'fishotel-shiptracker' ),
        );
        $columns = array();
        foreach ( $labels as $field => $label ) {
            if ( ! empty( $result['columns'][ $field ] ) ) {
                $columns[] = $label . ' = "' . $result['columns'][ $field ] . '"';
            }
        }

        ?>
        <div class="notice notice-success inline">
            <p>
                <strong><?php esc_html_e( 'Import finished.', 'fishotel-shiptracker' ); ?></strong>
                <?php
                printf(
                    /* translators: 1: rows read, 2: matched, 3: already had a cost, 4: not found, 5: invalid */
                    esc_html__( '%1$d rows read: %2$d matched and saved, %3$d already had a cost, %4$d not found, %5$d invalid.', 'fishotel-shiptracker' ),
                    (int) $result['rows'],
                    (int) $result['matched'],
                    (int) $result['had_cost'],
                    (int) $result['not_found'],
                    (int) $result['invalid']
                );
                ?>
            </p>
            <p style="color: #666;">
                <?php
                printf(
                    /* translators: %s: list of columns used */
                    esc_html__( 'Columns used: %s.', 'fishotel-shiptracker' ),
                    esc_html( implode( ', ', $columns ) )
                );
                if ( ! empty( $result['details_filled'] ) ) {
                    echo ' ';
                    printf(
                        /* translators: %d: number of boxes */
                        esc_html__( '%d of the boxes that already had a cost had an empty weight, size or source filled in.', 'fishotel-shiptracker' ),
                        (int) $result['details_filled']
                    );
                }
                if ( ! empty( $result['repeated'] ) ) {
                    echo ' ';
                    printf(
                        /* translators: %d: number of rows */
                        esc_html__( '%d repeated rows (same tracking number and cost) were skipped.', 'fishotel-shiptracker' ),
                        (int) $result['repeated']
                    );
                }
                if ( ! empty( $result['ignored_optional'] ) ) {
                    echo ' ';
                    printf(
                        /* translators: %d: number of rows */
                        esc_html__( '%d rows had a weight or box size that could not be read; their cost was still used.', 'fishotel-shiptracker' ),
                        (int) $result['ignored_optional']
                    );
                }
                ?>
            </p>
        </div>

        <?php if ( ! empty( $result['invalid_list'] ) ) : ?>
            <h4><?php esc_html_e( 'Invalid rows (nothing was saved for these)', 'fishotel-shiptracker' ); ?></h4>
            <table class="widefat striped" style="max-width: 800px;">
                <thead>
                    <tr>
                        <th style="width: 60px;"><?php esc_html_e( 'Row', 'fishotel-shiptracker' ); ?></th>
                        <th><?php esc_html_e( 'Tracking #', 'fishotel-shiptracker' ); ?></th>
                        <th><?php esc_html_e( 'Why', 'fishotel-shiptracker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $result['invalid_list'] as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['row'] ); ?></td>
                            <td><code><?php echo esc_html( $row['tracking_number'] ); ?></code></td>
                            <td><?php echo esc_html( $row['reason'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php if ( ! empty( $result['not_found_list'] ) ) : ?>
            <h4><?php esc_html_e( 'Not found (no shipment has this tracking number)', 'fishotel-shiptracker' ); ?></h4>
            <table class="widefat striped" style="max-width: 800px;">
                <thead>
                    <tr>
                        <th style="width: 60px;"><?php esc_html_e( 'Row', 'fishotel-shiptracker' ); ?></th>
                        <th><?php esc_html_e( 'Tracking #', 'fishotel-shiptracker' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $result['not_found_list'] as $row ) : ?>
                        <tr>
                            <td><?php echo esc_html( $row['row'] ); ?></td>
                            <td><code><?php echo esc_html( $row['tracking_number'] ); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    /**
     * Handle the "Import label costs" form: nonce, capability, the 2 MB
     * limit (checked before the file is read), then FST_Label_Cost::import().
     * The outcome is kept for the redirect in a short-lived transient.
     */
    public function handle_label_cost_import() {
        if ( ! isset( $_POST['fst_import_costs_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fst_import_costs_nonce'] ) ), 'fst_import_label_costs' ) ) {
            wp_die( 'Security check failed' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized access' );
        }

        $limit    = FST_Label_Cost::MAX_IMPORT_BYTES;
        $too_big  = __( 'That is more than 2 MB. Split the file and import it in parts.', 'fishotel-shiptracker' );
        $text     = null;
        $error    = '';
        $file     = isset( $_FILES['fst_cost_csv_file'] ) && is_array( $_FILES['fst_cost_csv_file'] ) ? $_FILES['fst_cost_csv_file'] : null;
        $uploaded = $file && isset( $file['error'] ) && UPLOAD_ERR_NO_FILE !== (int) $file['error'];

        if ( $uploaded ) {
            $tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';

            if ( in_array( (int) $file['error'], array( UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE ), true ) ) {
                $error = $too_big;
            } elseif ( UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $tmp_name ) ) {
                $error = __( 'The file could not be uploaded. Please try again.', 'fishotel-shiptracker' );
            } elseif ( (int) $file['size'] > $limit || filesize( $tmp_name ) > $limit ) {
                // Size is checked on disk before a single byte is read.
                $error = $too_big;
            } else {
                $text = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- local upload, size-checked above.
                if ( false === $text ) {
                    $error = __( 'The file could not be read. Please try again.', 'fishotel-shiptracker' );
                }
            }
        } else {
            // Raw on purpose: every cell is validated / sanitised by the parser.
            $pasted = isset( $_POST['fst_cost_csv_text'] ) && is_string( $_POST['fst_cost_csv_text'] ) ? wp_unslash( $_POST['fst_cost_csv_text'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
            if ( strlen( $pasted ) > $limit ) {
                $error = $too_big;
            } elseif ( '' === trim( $pasted ) ) {
                $error = __( 'Choose a CSV file or paste the rows first.', 'fishotel-shiptracker' );
            } else {
                $text = $pasted;
            }
        }

        if ( '' !== $error ) {
            $result = array( 'error' => $error );
        } else {
            $source    = isset( $_POST['fst_cost_csv_source'] ) && is_string( $_POST['fst_cost_csv_source'] ) ? sanitize_text_field( wp_unslash( $_POST['fst_cost_csv_source'] ) ) : '';
            $overwrite = ! empty( $_POST['fst_cost_csv_overwrite'] );

            $result = FST_Label_Cost::import( $text, $source, $overwrite );
        }

        set_transient( 'fst_cost_import_' . get_current_user_id(), $result, 5 * MINUTE_IN_SECONDS );

        wp_safe_redirect( admin_url( 'admin.php?page=fst-settings&tab=boxes&imported=1' ) );
        exit;
    }

    /**
     * Save the standard boxes and label sources.
     */
    private function save_boxes_settings() {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput -- sanitised row by row in the sanitize_* helpers.
        $boxes   = isset( $_POST['fst_standard_boxes'] ) && is_array( $_POST['fst_standard_boxes'] ) ? wp_unslash( $_POST['fst_standard_boxes'] ) : array();
        $sources = isset( $_POST['fst_label_sources'] ) && is_array( $_POST['fst_label_sources'] ) ? wp_unslash( $_POST['fst_label_sources'] ) : array();
        // phpcs:enable

        update_option( 'fst_standard_boxes', self::sanitize_standard_boxes( $boxes ) );
        update_option( 'fst_label_sources', self::sanitize_label_sources( $sources ) );
    }

    /**
     * Save general settings.
     */
    private function save_general_settings() {
        update_option( 'fst_default_carrier', sanitize_text_field( $_POST['fst_default_carrier'] ?? 'ups' ) );
        update_option( 'fst_auto_detect_carrier', isset( $_POST['fst_auto_detect_carrier'] ) ? 1 : 0 );
        update_option( 'fst_auto_complete_orders', isset( $_POST['fst_auto_complete_orders'] ) ? 1 : 0 );
        update_option( 'fst_sms_notifications', isset( $_POST['fst_sms_notifications'] ) ? 1 : 0 );
    }

    /**
     * Save carrier settings.
     */
    private function save_carrier_settings() {
        update_option( 'fst_ups_client_id', sanitize_text_field( $_POST['fst_ups_client_id'] ?? '' ) );
        update_option( 'fst_ups_client_secret', sanitize_text_field( $_POST['fst_ups_client_secret'] ?? '' ) );
        update_option( 'fst_usps_client_id', sanitize_text_field( $_POST['fst_usps_client_id'] ?? '' ) );
        update_option( 'fst_usps_client_secret', sanitize_text_field( $_POST['fst_usps_client_secret'] ?? '' ) );

        // EasyPost settings.
        update_option( 'fst_easypost_production_key', sanitize_text_field( $_POST['fst_easypost_production_key'] ?? '' ) );
        update_option( 'fst_easypost_test_key', sanitize_text_field( $_POST['fst_easypost_test_key'] ?? '' ) );

        // Treat the webhook secret as opaque — no character stripping, just a length cap.
        $webhook_secret = isset( $_POST['fst_easypost_webhook_secret'] ) ? (string) wp_unslash( $_POST['fst_easypost_webhook_secret'] ) : '';
        update_option( 'fst_easypost_webhook_secret', substr( $webhook_secret, 0, 200 ) );

        update_option( 'fst_easypost_test_mode', isset( $_POST['fst_easypost_test_mode'] ) ? 'yes' : 'no' );

        $routing = sanitize_text_field( $_POST['fst_easypost_usps_routing'] ?? 'auto' );
        update_option( 'fst_easypost_usps_routing', in_array( $routing, array( 'auto', 'always', 'never' ), true ) ? $routing : 'auto' );
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
        update_option( 'fst_tracking_bg_color', sanitize_hex_color( $_POST['fst_tracking_bg_color'] ?? '#ffffff' ) );
        update_option( 'fst_tracking_accent_color', sanitize_hex_color( $_POST['fst_tracking_accent_color'] ?? '#007cba' ) );
        update_option( 'fst_tracking_text_color', sanitize_hex_color( $_POST['fst_tracking_text_color'] ?? '#333333' ) );
        update_option( 'fst_tracking_custom_css', wp_kses_post( $_POST['fst_tracking_custom_css'] ?? '' ) );
    }

    /**
     * Save advanced settings.
     */
    private function save_advanced_settings() {
        update_option( 'fst_polling_interval', absint( $_POST['fst_polling_interval'] ?? 3600 ) );
        update_option( 'fst_debug_logging', isset( $_POST['fst_debug_logging'] ) ? 'yes' : 'no' );
        update_option( 'fst_data_retention_days', absint( $_POST['fst_data_retention_days'] ?? 1095 ) );
    }
}
