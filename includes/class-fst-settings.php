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
        add_action( 'admin_init', array( $this, 'handle_supply_action' ) );
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
     *                 Since 1.9.18 also what the box starts with: foam_cost
     *                 and paper_cost (flat amounts, "0.00" by default),
     *                 paper_feet (feet of paper per box, '' when not given)
     *                 and ice_packs (how many when cooling is needed, "0").
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
     * box with the same name. The four materials fields are optional and
     * fall back to "nothing": a flat amount that does not read as money is
     * 0.00, not an error.
     *
     * @param mixed $raw [ { name, length, width, height, foam_cost, paper_cost, paper_feet, ice_packs } ]
     * @return array[] [ { name, length, width, height, foam_cost, paper_cost, paper_feet, ice_packs } ]
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
            $paper_feet = FST_Supplies::parse_qty( isset( $row['paper_feet'] ) ? $row['paper_feet'] : null );
            $ice_packs  = FST_Supplies::parse_qty( isset( $row['ice_packs'] ) ? $row['ice_packs'] : null );

            $boxes[] = array(
                'name'       => $name,
                'length'     => $length,
                'width'      => $width,
                'height'     => $height,
                'foam_cost'  => self::flat_amount( isset( $row['foam_cost'] ) ? $row['foam_cost'] : null ),
                'paper_cost' => self::flat_amount( isset( $row['paper_cost'] ) ? $row['paper_cost'] : null ),
                'paper_feet' => is_int( $paper_feet ) && $paper_feet > 0 ? FST_Label_Cost::trim_decimal( FST_Supplies::format_qty( $paper_feet ) ) : '',
                'ice_packs'  => is_int( $ice_packs ) ? FST_Label_Cost::trim_decimal( FST_Supplies::format_qty( $ice_packs ) ) : '0',
            );
        }

        return $boxes;
    }

    /**
     * Standard boxes that were renamed by a settings save — pure.
     *
     * The form posts every box under the row number it was shown with, so a
     * saved box whose posted name differs was renamed (not removed and
     * re-added). Its box supply follows the new name instead of silently
     * losing its box.
     *
     * @param array $saved_boxes  The boxes as saved before this save, in list order.
     * @param array $posted_boxes The submitted rows, by row number.
     * @return array Old name (lower case) => new name.
     */
    public static function renamed_boxes( array $saved_boxes, array $posted_boxes ) {
        $renamed = array();

        foreach ( array_values( $saved_boxes ) as $index => $saved_box ) {
            if ( ! isset( $posted_boxes[ $index ]['name'] ) || ! is_scalar( $posted_boxes[ $index ]['name'] ) ) {
                continue;
            }
            $posted = FST_Label_Cost::limit_length( sanitize_text_field( (string) $posted_boxes[ $index ]['name'] ), 40 );
            if ( '' !== $posted && FST_Label_Cost::lower( $posted ) !== FST_Label_Cost::lower( $saved_box['name'] ) ) {
                $renamed[ FST_Label_Cost::lower( $saved_box['name'] ) ] = $posted;
            }
        }

        return $renamed;
    }

    /**
     * A flat amount of a standard box (foam cost, paper cost): money of 0 or
     * more, "0.00" when empty or not an amount.
     *
     * @param mixed $raw
     * @return string
     */
    private static function flat_amount( $raw ) {
        $amount = FST_Label_Cost::parse_money( $raw );
        return is_string( $amount ) ? $amount : '0.00';
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

            <?php // Purchases, recounts and the import are forms of their own, below the settings form (same tab). ?>
            <div class="fst-tab-content <?php echo 'boxes' === $tab ? 'active' : ''; ?>" data-fst-tab="boxes">
                <?php $this->render_supplies_log(); ?>
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
     * Render the Boxes & label costs tab: the standard boxes (with what each
     * starts with), the label sources and the supplies. All three lists
     * start empty — nothing is seeded.
     */
    private function render_boxes_tab() {
        $boxes    = self::get_standard_boxes();
        $sources  = self::get_label_sources();
        $supplies = FST_Supplies::get_supplies();
        $waste    = FST_Supplies::get_waste_percent();

        // What the suggestions are worked out from: the latest foam and paper purchase.
        $latest      = FST_Supplies::latest_by_supply( FST_Supplies::get_purchases() );
        $foam        = FST_Supplies::supply_for_role( 'foam', $supplies );
        $paper       = FST_Supplies::supply_for_role( 'paper', $supplies );
        $foam_bought = $foam && isset( $latest[ $foam['id'] ] ) ? $latest[ $foam['id'] ] : null;
        $roll_bought = $paper && isset( $latest[ $paper['id'] ] ) ? $latest[ $paper['id'] ] : null;

        $box_names = array();
        foreach ( $boxes as $box ) {
            $box_names[] = $box['name'];
        }

        // Always end with one blank row to type into.
        $boxes[]    = array( 'name' => '', 'length' => '', 'width' => '', 'height' => '', 'foam_cost' => '', 'paper_cost' => '', 'paper_feet' => '', 'ice_packs' => '' );
        $sources[]  = '';
        $supplies[] = array( 'id' => '', 'name' => '', 'unit' => '', 'counted' => false, 'reorder_level' => null, 'role' => '', 'box_name' => '', 'price_each' => null, 'on_hand' => null, 'recount_needed' => false, 'low' => false );

        ?>
        <h3><?php esc_html_e( 'Standard Boxes', 'fishotel-shiptracker' ); ?></h3>
        <p class="description" style="max-width: 900px;">
            <?php esc_html_e( 'The boxes you usually ship in, in inches. They are offered as a dropdown wherever a label cost is entered; a custom size can always be typed instead. A row needs a name and all three sizes to be saved.', 'fishotel-shiptracker' ); ?>
            <?php esc_html_e( 'Foam cost and paper cost are your ballpark per box — two separate flat amounts that a box of this size starts with (a custom box starts at 0). Ice packs is how many go in when the weather calls for cooling. A suggestion is only shown: click it to fill the field in, then save.', 'fishotel-shiptracker' ); ?>
        </p>
        <table class="widefat striped fst-list-table" id="fst-standard-boxes" style="max-width: 1100px; margin-top: 12px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Length (in)', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Width (in)', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Height (in)', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Foam cost', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Paper (ft per box)', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Paper cost', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Ice packs when cooling', 'fishotel-shiptracker' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $boxes as $index => $box ) :
                    $is_saved      = '' !== $box['name'];
                    $foam_suggest  = $is_saved ? FST_Supplies::foam_suggestion( $box, $foam, $foam_bought, $waste ) : null;
                    $paper_suggest = $is_saved ? FST_Supplies::paper_suggestion( $box, $roll_bought ) : null;
                    ?>
                    <tr class="fst-list-row">
                        <td>
                            <input type="text" name="fst_standard_boxes[<?php echo esc_attr( $index ); ?>][name]" value="<?php echo esc_attr( $box['name'] ); ?>" maxlength="40" style="width: 100%;">
                        </td>
                        <?php foreach ( array( 'length', 'width', 'height' ) as $side ) : ?>
                            <td>
                                <input type="number" name="fst_standard_boxes[<?php echo esc_attr( $index ); ?>][<?php echo esc_attr( $side ); ?>]" value="<?php echo esc_attr( $box[ $side ] ); ?>" min="0" step="0.01" style="width: 80px;">
                            </td>
                        <?php endforeach; ?>
                        <td>
                            <input type="number" name="fst_standard_boxes[<?php echo esc_attr( $index ); ?>][foam_cost]" value="<?php echo esc_attr( $box['foam_cost'] ); ?>" min="0" step="0.01" placeholder="0.00" style="width: 90px;">
                            <div class="fst-row-info" style="font-size: 12px; margin-top: 2px;">
                                <?php if ( $foam_suggest ) : ?>
                                    <a href="#" class="fst-suggestion" data-value="<?php echo esc_attr( $foam_suggest['cost'] ); ?>" title="<?php esc_attr_e( 'Click to fill it in', 'fishotel-shiptracker' ); ?>"><?php
                                        /* translators: %s: suggested amount */
                                        echo wp_kses_post( sprintf( __( 'Suggested: %s', 'fishotel-shiptracker' ), wc_price( $foam_suggest['cost'] ) ) );
                                    ?></a><br>
                                    <span style="color: #666;"><?php
                                        /* translators: 1: the working, e.g. "4.2 sq ft of a 32 sq ft sheet", 2: waste percent */
                                        echo esc_html( sprintf( __( '%1$s, plus %2$s%% waste', 'fishotel-shiptracker' ), $foam_suggest['text'], FST_Label_Cost::trim_decimal( number_format( $foam_suggest['waste_percent'], 2, '.', '' ) ) ) );
                                    ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <input type="number" name="fst_standard_boxes[<?php echo esc_attr( $index ); ?>][paper_feet]" value="<?php echo esc_attr( $box['paper_feet'] ); ?>" min="0" step="0.01" style="width: 80px;">
                        </td>
                        <td>
                            <input type="number" name="fst_standard_boxes[<?php echo esc_attr( $index ); ?>][paper_cost]" value="<?php echo esc_attr( $box['paper_cost'] ); ?>" min="0" step="0.01" placeholder="0.00" style="width: 90px;">
                            <div class="fst-row-info" style="font-size: 12px; margin-top: 2px;">
                                <?php if ( $paper_suggest ) : ?>
                                    <a href="#" class="fst-suggestion" data-value="<?php echo esc_attr( $paper_suggest['cost'] ); ?>" title="<?php esc_attr_e( 'Click to fill it in', 'fishotel-shiptracker' ); ?>"><?php
                                        /* translators: %s: suggested amount */
                                        echo wp_kses_post( sprintf( __( 'Suggested: %s', 'fishotel-shiptracker' ), wc_price( $paper_suggest['cost'] ) ) );
                                    ?></a><br>
                                    <span style="color: #666;"><?php echo esc_html( $paper_suggest['text'] ); ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td>
                            <input type="number" name="fst_standard_boxes[<?php echo esc_attr( $index ); ?>][ice_packs]" value="<?php echo esc_attr( $box['ice_packs'] ); ?>" min="0" step="1" placeholder="0" style="width: 70px;">
                        </td>
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
        <p>
            <label for="fst_foam_waste_percent"><?php esc_html_e( 'Foam waste', 'fishotel-shiptracker' ); ?></label>
            <input type="number" id="fst_foam_waste_percent" name="fst_foam_waste_percent" value="<?php echo esc_attr( $waste ); ?>" min="0" max="100" step="0.1" style="width: 80px;"> %
            <span class="description">
                <?php esc_html_e( 'Added to a suggested foam cost for the offcuts. The suggestion is the box surface, all six sides, 2 × (L×W + L×H + W×H), at the price per square foot of your latest foam purchase.', 'fishotel-shiptracker' ); ?>
                <?php if ( ! $foam ) : ?>
                    <?php esc_html_e( 'No supply is marked as foam yet, so there is no foam suggestion.', 'fishotel-shiptracker' ); ?>
                <?php elseif ( ! $foam_bought ) : ?>
                    <?php esc_html_e( 'No foam purchase is logged yet, so there is no foam suggestion.', 'fishotel-shiptracker' ); ?>
                <?php endif; ?>
            </span>
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

        <h3 style="margin-top: 30px;" id="fst-supplies-list"><?php esc_html_e( 'Supplies', 'fishotel-shiptracker' ); ?></h3>
        <?php self::render_reorder_notice( FST_Supplies::get_low_supplies() ); ?>
        <p class="description" style="max-width: 900px;">
            <?php esc_html_e( 'Everything else a box costs: the boxes themselves, fish bags, heat packs, ice packs, foam, paper — and anything else you want on the books. "Count it down" keeps a count: what you bought, less what went into boxes. "Used as" lets a new box find the supply by itself: one heat pack when the weather check says heat pack, the box\'s ice packs when it says cooling, one fish bag per fish, and one of the box\'s own supply. Only one supply can have each use (a box supply: one per standard box). Save the list, then log what you bought below.', 'fishotel-shiptracker' ); ?>
        </p>
        <datalist id="fst-supply-units">
            <option value="each"></option>
            <option value="sheets"></option>
            <option value="feet"></option>
        </datalist>
        <table class="widefat striped fst-list-table" id="fst-supplies" style="max-width: 1100px; margin-top: 12px;">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Name', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Unit', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Count it down', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Reorder at', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Used as', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'Price each now', 'fishotel-shiptracker' ); ?></th>
                    <th><?php esc_html_e( 'On hand', 'fishotel-shiptracker' ); ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $supplies as $index => $supply ) :
                    $role_value = 'box' === $supply['role'] ? 'box:' . $supply['box_name'] : $supply['role'];
                    $field      = 'fst_supplies[' . $index . ']';
                    ?>
                    <tr class="fst-list-row">
                        <td>
                            <input type="hidden" name="<?php echo esc_attr( $field ); ?>[id]" value="<?php echo esc_attr( $supply['id'] ); ?>">
                            <input type="text" name="<?php echo esc_attr( $field ); ?>[name]" value="<?php echo esc_attr( $supply['name'] ); ?>" maxlength="60" style="width: 100%;">
                        </td>
                        <td>
                            <input type="text" name="<?php echo esc_attr( $field ); ?>[unit]" value="<?php echo esc_attr( $supply['unit'] ); ?>" list="fst-supply-units" maxlength="20" placeholder="each" style="width: 90px;">
                        </td>
                        <td>
                            <input type="checkbox" name="<?php echo esc_attr( $field ); ?>[counted]" value="1" <?php checked( $supply['counted'] ); ?>>
                        </td>
                        <td>
                            <input type="number" name="<?php echo esc_attr( $field ); ?>[reorder_level]" value="<?php echo esc_attr( null === $supply['reorder_level'] ? '' : FST_Label_Cost::trim_decimal( $supply['reorder_level'] ) ); ?>" min="0" step="0.01" style="width: 80px;">
                        </td>
                        <td>
                            <select name="<?php echo esc_attr( $field ); ?>[role]" class="fst-supply-role">
                                <option value=""><?php esc_html_e( '— Nothing automatic —', 'fishotel-shiptracker' ); ?></option>
                                <?php foreach ( FST_Supplies::role_labels() as $role => $role_label ) : ?>
                                    <?php if ( 'box' === $role ) { continue; } ?>
                                    <option value="<?php echo esc_attr( $role ); ?>" <?php selected( $role_value, $role ); ?>><?php echo esc_html( $role_label ); ?></option>
                                <?php endforeach; ?>
                                <?php foreach ( $box_names as $box_name ) : ?>
                                    <option value="<?php echo esc_attr( 'box:' . $box_name ); ?>" <?php selected( $role_value, 'box:' . $box_name ); ?>><?php
                                        /* translators: %s: standard box name */
                                        echo esc_html( sprintf( __( 'The box: %s', 'fishotel-shiptracker' ), $box_name ) );
                                    ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="fst-foam-sheet" style="margin-top: 4px; font-size: 12px;<?php echo 'foam' === $supply['role'] ? '' : ' display: none;'; ?>">
                                <?php esc_html_e( 'A sheet is', 'fishotel-shiptracker' ); ?>
                                <input type="number" name="<?php echo esc_attr( $field ); ?>[sheet_length]" value="<?php echo esc_attr( isset( $supply['sheet_length'] ) ? $supply['sheet_length'] : '' ); ?>" min="0" step="0.01" placeholder="8" style="width: 60px;"> ft &times;
                                <input type="number" name="<?php echo esc_attr( $field ); ?>[sheet_width]" value="<?php echo esc_attr( isset( $supply['sheet_width'] ) ? $supply['sheet_width'] : '' ); ?>" min="0" step="0.01" placeholder="4" style="width: 60px;"> ft,
                                <input type="number" name="<?php echo esc_attr( $field ); ?>[thickness]" value="<?php echo esc_attr( isset( $supply['thickness'] ) ? $supply['thickness'] : '' ); ?>" min="0" step="0.01" placeholder="0.5" style="width: 60px;"> <?php esc_html_e( 'in thick', 'fishotel-shiptracker' ); ?>
                            </div>
                        </td>
                        <td class="fst-row-info">
                            <?php echo null !== $supply['price_each'] ? wp_kses_post( wc_price( $supply['price_each'], array( 'decimals' => 4 ) ) ) : '&mdash;'; ?>
                        </td>
                        <td class="fst-row-info">
                            <?php if ( null === $supply['on_hand'] ) : ?>
                                &mdash;
                            <?php else : ?>
                                <?php echo esc_html( FST_Label_Cost::trim_decimal( $supply['on_hand'] ) ); ?>
                                <?php if ( $supply['recount_needed'] ) : ?>
                                    <span style="color: #b26b00;"><?php esc_html_e( '(recount needed)', 'fishotel-shiptracker' ); ?></span>
                                <?php elseif ( $supply['low'] ) : ?>
                                    <span style="color: #dc3545;"><?php esc_html_e( '(reorder)', 'fishotel-shiptracker' ); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="#" class="fst-list-remove" title="<?php esc_attr_e( 'Remove this supply', 'fishotel-shiptracker' ); ?>">&times; <?php esc_html_e( 'Remove', 'fishotel-shiptracker' ); ?></a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p>
            <button type="button" class="button fst-list-add" data-target="#fst-supplies"><?php esc_html_e( 'Add another supply', 'fishotel-shiptracker' ); ?></button>
            <span class="description"><?php esc_html_e( 'Removing a supply keeps what was already recorded on boxes; its purchases stop counting.', 'fishotel-shiptracker' ); ?></span>
        </p>
        <?php
    }

    /**
     * The reorder notice: supplies at or under their reorder level, with
     * how many are on hand. Shown at the top of the Supplies list and on
     * ShipTracker's dashboard widget.
     *
     * @param array $low FST_Supplies::get_low_supplies().
     */
    public static function render_reorder_notice( array $low ) {
        if ( empty( $low ) ) {
            return;
        }
        ?>
        <div class="fst-reorder-notice" style="background: #fff8e5; border: 1px solid #f0d58c; border-left: 4px solid #b26b00; padding: 10px 14px; margin: 10px 0 14px; border-radius: 3px; max-width: 900px;">
            <strong style="color: #b26b00;"><?php esc_html_e( 'Time to reorder', 'fishotel-shiptracker' ); ?></strong>
            <ul style="margin: 6px 0 0 18px; list-style: disc; font-size: 13px;">
                <?php foreach ( $low as $supply ) : ?>
                    <li>
                        <?php
                        printf(
                            /* translators: 1: supply name, 2: how many are on hand, 3: unit, 4: reorder level */
                            esc_html__( '%1$s: %2$s %3$s on hand (reorder at %4$s)', 'fishotel-shiptracker' ),
                            esc_html( $supply['name'] ),
                            esc_html( FST_Label_Cost::trim_decimal( $supply['on_hand'] ) ),
                            esc_html( $supply['unit'] ),
                            esc_html( FST_Label_Cost::trim_decimal( $supply['reorder_level'] ) )
                        );
                        if ( $supply['recount_needed'] ) {
                            echo ' &mdash; <em>' . esc_html__( 'recount needed', 'fishotel-shiptracker' ) . '</em>';
                        }
                        ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
    }

    /**
     * Render "Purchases & stock" (forms of their own, below the settings
     * form on the Boxes & label costs tab): log / correct / remove a
     * purchase, and recount a supply that is counted down.
     */
    private function render_supplies_log() {
        $supplies = FST_Supplies::get_supplies();

        $message = get_transient( 'fst_supply_msg_' . get_current_user_id() );
        if ( isset( $_GET['supplies'] ) && is_array( $message ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- display only.
            delete_transient( 'fst_supply_msg_' . get_current_user_id() );
        } else {
            $message = false;
        }

        $by_id = array();
        foreach ( $supplies as $supply ) {
            $by_id[ $supply['id'] ] = $supply;
        }

        $purchases = array_reverse( FST_Supplies::get_purchases() );
        $stock     = FST_Supplies::get_stock();
        $recounts  = FST_Supplies::get_last_recounts();

        // A purchase being corrected fills the form.
        $editing = isset( $_GET['edit_purchase'] ) ? FST_Supplies::get_purchase( absint( $_GET['edit_purchase'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification -- display only.

        ?>
        <div id="fst-supplies-log" style="border-top: 2px solid #f0f0f0; margin-top: 30px; padding-top: 10px; max-width: 1100px;">
            <h3><?php esc_html_e( 'Purchases & stock', 'fishotel-shiptracker' ); ?></h3>

            <?php if ( $message ) : ?>
                <div class="notice notice-<?php echo 'error' === $message['type'] ? 'error' : 'success'; ?> inline"><p><?php echo esc_html( $message['text'] ); ?></p></div>
            <?php endif; ?>

            <?php if ( empty( $supplies ) ) : ?>
                <p class="description"><?php esc_html_e( 'Add your supplies to the list above and save. Then come back here each time you buy a case of heat packs, a sheet of foam or a roll of paper.', 'fishotel-shiptracker' ); ?></p>
            <?php else : ?>
                <p class="description" style="max-width: 900px;">
                    <?php esc_html_e( 'Log it when you buy more: how many came in the case (sheets of foam, feet of paper on the roll) and what you paid in total. The price of one is worked out from the latest purchase, and new boxes use it — boxes already recorded keep the price they were recorded at.', 'fishotel-shiptracker' ); ?>
                </p>

                <form method="post" autocomplete="off" style="background: #f9f9f9; border: 1px solid #e0e0e0; border-radius: 4px; padding: 10px 14px; margin: 10px 0;">
                    <?php wp_nonce_field( 'fst_supply_action', 'fst_supply_nonce' ); ?>
                    <input type="hidden" name="fst_supply_action" value="save_purchase">
                    <input type="hidden" name="purchase_id" value="<?php echo esc_attr( $editing ? $editing->id : '' ); ?>">
                    <strong><?php echo $editing ? esc_html__( 'Correct a purchase', 'fishotel-shiptracker' ) : esc_html__( 'Log a purchase', 'fishotel-shiptracker' ); ?></strong><br>
                    <label style="display: inline-block; margin: 6px 10px 0 0;">
                        <?php esc_html_e( 'Supply', 'fishotel-shiptracker' ); ?><br>
                        <select name="supply_id">
                            <?php foreach ( $supplies as $supply ) : ?>
                                <option value="<?php echo esc_attr( $supply['id'] ); ?>" <?php selected( $editing ? (int) $editing->supply_id : 0, $supply['id'] ); ?>>
                                    <?php echo esc_html( $supply['name'] . ' (' . $supply['unit'] . ')' ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label style="display: inline-block; margin: 6px 10px 0 0;">
                        <?php esc_html_e( 'Date', 'fishotel-shiptracker' ); ?><br>
                        <input type="date" name="purchase_date" value="<?php echo esc_attr( $editing ? $editing->purchase_date : current_time( 'Y-m-d' ) ); ?>">
                    </label>
                    <label style="display: inline-block; margin: 6px 10px 0 0;">
                        <?php esc_html_e( 'How many', 'fishotel-shiptracker' ); ?><br>
                        <input type="number" name="qty" value="<?php echo esc_attr( $editing ? FST_Label_Cost::trim_decimal( $editing->qty ) : '' ); ?>" min="0" step="0.01" style="width: 100px;">
                    </label>
                    <label style="display: inline-block; margin: 6px 10px 0 0;">
                        <?php esc_html_e( 'Total paid', 'fishotel-shiptracker' ); ?><br>
                        <input type="text" name="total_paid" inputmode="decimal" value="<?php echo esc_attr( $editing ? $editing->total_paid : '' ); ?>" placeholder="24.00" style="width: 100px;">
                    </label>
                    <label style="display: inline-block; margin: 6px 10px 0 0;">
                        <?php esc_html_e( 'Note (where you bought it)', 'fishotel-shiptracker' ); ?><br>
                        <input type="text" name="note" value="<?php echo esc_attr( $editing ? $editing->note : '' ); ?>" maxlength="190" style="width: 240px;">
                    </label>
                    <button type="submit" class="button button-secondary" style="vertical-align: bottom;">
                        <?php echo $editing ? esc_html__( 'Save the correction', 'fishotel-shiptracker' ) : esc_html__( 'Log the purchase', 'fishotel-shiptracker' ); ?>
                    </button>
                    <?php if ( $editing ) : ?>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=fst-settings&tab=boxes#fst-supplies-log' ) ); ?>" style="margin-left: 8px;"><?php esc_html_e( 'Cancel', 'fishotel-shiptracker' ); ?></a>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <?php if ( ! empty( $purchases ) ) : ?>
                <table class="widefat striped" style="margin-top: 12px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Supply', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'How many', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Total paid', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Price each', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Note', 'fishotel-shiptracker' ); ?></th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( array_slice( $purchases, 0, 100 ) as $purchase ) :
                            $bought = isset( $by_id[ (int) $purchase->supply_id ] ) ? $by_id[ (int) $purchase->supply_id ] : null;
                            $each   = FST_Supplies::price_each( $purchase );
                            ?>
                            <tr>
                                <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $purchase->purchase_date ) ) ); ?></td>
                                <td>
                                    <?php if ( $bought ) : ?>
                                        <?php echo esc_html( $bought['name'] ); ?>
                                    <?php else : ?>
                                        <span style="color: #999;"><?php esc_html_e( '(removed supply)', 'fishotel-shiptracker' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html( FST_Label_Cost::trim_decimal( $purchase->qty ) . ( $bought ? ' ' . $bought['unit'] : '' ) ); ?></td>
                                <td><?php echo wp_kses_post( wc_price( $purchase->total_paid ) ); ?></td>
                                <td><?php echo null !== $each ? wp_kses_post( wc_price( $each, array( 'decimals' => 4 ) ) ) : '&mdash;'; ?></td>
                                <td><?php echo esc_html( $purchase->note ); ?></td>
                                <td style="white-space: nowrap;">
                                    <?php if ( $bought ) : ?>
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=fst-settings&tab=boxes&edit_purchase=' . (int) $purchase->id . '#fst-supplies-log' ) ); ?>"><?php esc_html_e( 'Edit', 'fishotel-shiptracker' ); ?></a>
                                    <?php endif; ?>
                                    <form method="post" style="display: inline; margin-left: 8px;" onsubmit="return confirm( '<?php echo esc_js( __( 'Remove this purchase?', 'fishotel-shiptracker' ) ); ?>' );">
                                        <?php wp_nonce_field( 'fst_supply_action', 'fst_supply_nonce', false ); ?>
                                        <input type="hidden" name="fst_supply_action" value="delete_purchase">
                                        <input type="hidden" name="purchase_id" value="<?php echo esc_attr( $purchase->id ); ?>">
                                        <button type="submit" class="button-link" style="color: #b32d2e;"><?php esc_html_e( 'Delete', 'fishotel-shiptracker' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if ( count( $purchases ) > 100 ) : ?>
                    <p class="description"><?php esc_html_e( 'The latest 100 purchases are listed.', 'fishotel-shiptracker' ); ?></p>
                <?php endif; ?>
            <?php endif; ?>

            <?php
            $counted = array();
            foreach ( $supplies as $supply ) {
                if ( $supply['counted'] ) {
                    $counted[] = $supply;
                }
            }
            ?>
            <?php if ( ! empty( $counted ) ) : ?>
                <h4 style="margin: 24px 0 4px;"><?php esc_html_e( 'Stock', 'fishotel-shiptracker' ); ?></h4>
                <p class="description" style="max-width: 900px;">
                    <?php esc_html_e( 'On hand = bought − used on boxes ± recounts. Removing a box from an order gives its supplies back. When the shelf and the books disagree, count what is really there and enter it: the difference is kept with the date and who counted.', 'fishotel-shiptracker' ); ?>
                </p>
                <table class="widefat striped" style="margin-top: 8px;">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Supply', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Bought', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Used', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Recounts', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'On hand', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Reorder at', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Last recount', 'fishotel-shiptracker' ); ?></th>
                            <th><?php esc_html_e( 'Recount', 'fishotel-shiptracker' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $counted as $supply ) :
                            $level   = isset( $stock[ $supply['id'] ] ) ? $stock[ $supply['id'] ] : FST_Supplies::stock_from_totals( 0, 0, 0 );
                            $recount = isset( $recounts[ $supply['id'] ] ) ? $recounts[ $supply['id'] ] : null;
                            $counter = $recount && $recount->user_id ? get_userdata( (int) $recount->user_id ) : false;
                            ?>
                            <tr>
                                <td><?php echo esc_html( $supply['name'] ); ?></td>
                                <td><?php echo esc_html( FST_Label_Cost::trim_decimal( $level['bought'] ) ); ?></td>
                                <td><?php echo esc_html( FST_Label_Cost::trim_decimal( $level['used'] ) ); ?></td>
                                <td><?php echo esc_html( ( (float) $level['adjusted'] > 0 ? '+' : '' ) . FST_Label_Cost::trim_decimal( $level['adjusted'] ) ); ?></td>
                                <td>
                                    <strong><?php echo esc_html( FST_Label_Cost::trim_decimal( $level['on_hand'] ) . ' ' . $supply['unit'] ); ?></strong>
                                    <?php if ( $level['recount_needed'] ) : ?>
                                        <span style="color: #b26b00;"><?php esc_html_e( 'recount needed', 'fishotel-shiptracker' ); ?></span>
                                    <?php elseif ( $supply['low'] ) : ?>
                                        <span style="color: #dc3545;"><?php esc_html_e( 'reorder', 'fishotel-shiptracker' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo null !== $supply['reorder_level'] ? esc_html( FST_Label_Cost::trim_decimal( $supply['reorder_level'] ) ) : '&mdash;'; ?></td>
                                <td>
                                    <?php if ( $recount ) : ?>
                                        <?php
                                        echo esc_html( sprintf(
                                            /* translators: 1: date, 2: number counted, 3: correction, 4: who counted */
                                            __( '%1$s: counted %2$s (%3$s) by %4$s', 'fishotel-shiptracker' ),
                                            date_i18n( get_option( 'date_format' ), strtotime( $recount->counted_at ) ),
                                            FST_Label_Cost::trim_decimal( $recount->counted ),
                                            ( (float) $recount->delta > 0 ? '+' : '' ) . FST_Label_Cost::trim_decimal( $recount->delta ),
                                            $counter ? $counter->display_name : __( 'someone', 'fishotel-shiptracker' )
                                        ) );
                                        ?>
                                    <?php else : ?>
                                        &mdash;
                                    <?php endif; ?>
                                </td>
                                <td style="white-space: nowrap;">
                                    <form method="post" autocomplete="off" style="margin: 0;">
                                        <?php wp_nonce_field( 'fst_supply_action', 'fst_supply_nonce', false ); ?>
                                        <input type="hidden" name="fst_supply_action" value="recount">
                                        <input type="hidden" name="supply_id" value="<?php echo esc_attr( $supply['id'] ); ?>">
                                        <input type="number" name="counted" min="0" step="0.01" style="width: 80px;" aria-label="<?php esc_attr_e( 'How many are really there', 'fishotel-shiptracker' ); ?>">
                                        <button type="submit" class="button button-small"><?php esc_html_e( 'Set', 'fishotel-shiptracker' ); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle the purchase and recount forms: nonce, capability, then the
     * change. The outcome is kept for the redirect in a short-lived transient.
     */
    public function handle_supply_action() {
        if ( ! isset( $_POST['fst_supply_nonce'] ) ) {
            return;
        }

        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fst_supply_nonce'] ) ), 'fst_supply_action' ) ) {
            wp_die( 'Security check failed' );
        }

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized access' );
        }

        $action = isset( $_POST['fst_supply_action'] ) ? sanitize_text_field( wp_unslash( $_POST['fst_supply_action'] ) ) : '';
        $text   = '';
        $result = true;

        switch ( $action ) {
            case 'save_purchase':
                $args = array();
                foreach ( array( 'supply_id', 'purchase_date', 'qty', 'total_paid', 'note' ) as $field ) {
                    $args[ $field ] = isset( $_POST[ $field ] ) && is_scalar( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
                }
                $purchase_id = isset( $_POST['purchase_id'] ) ? absint( $_POST['purchase_id'] ) : 0;

                $result = $purchase_id ? FST_Supplies::update_purchase( $purchase_id, $args ) : FST_Supplies::add_purchase( $args );
                $text   = $purchase_id ? __( 'Purchase corrected.', 'fishotel-shiptracker' ) : __( 'Purchase logged.', 'fishotel-shiptracker' );
                break;

            case 'delete_purchase':
                FST_Supplies::delete_purchase( isset( $_POST['purchase_id'] ) ? absint( $_POST['purchase_id'] ) : 0 );
                $text = __( 'Purchase removed.', 'fishotel-shiptracker' );
                break;

            case 'recount':
                $result = FST_Supplies::recount(
                    isset( $_POST['supply_id'] ) ? absint( $_POST['supply_id'] ) : 0,
                    isset( $_POST['counted'] ) && is_scalar( $_POST['counted'] ) ? sanitize_text_field( wp_unslash( $_POST['counted'] ) ) : ''
                );
                $text   = __( 'Recount saved.', 'fishotel-shiptracker' );
                break;

            default:
                $result = new WP_Error( 'fst_unknown_action', __( 'Nothing was changed.', 'fishotel-shiptracker' ) );
                break;
        }

        set_transient(
            'fst_supply_msg_' . get_current_user_id(),
            array(
                'type' => is_wp_error( $result ) ? 'error' : 'success',
                'text' => is_wp_error( $result ) ? $result->get_error_message() : $text,
            ),
            5 * MINUTE_IN_SECONDS
        );

        wp_safe_redirect( admin_url( 'admin.php?page=fst-settings&tab=boxes&supplies=1#fst-supplies-log' ) );
        exit;
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
                <?php esc_html_e( 'Record what labels cost from a spreadsheet export. The file needs a header row with a tracking column (tracking, tracking number, tracking #) and a cost column (cost, amount, total, charge, price). If more than one column could be the cost, nothing is imported and the columns are named: remove the ones that are not what the label cost. Weight, dimensions / box and source columns are used when present. Only tracking numbers that already exist in ShipTracker are updated, and only these four fields are ever changed.', 'fishotel-shiptracker' ); ?>
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

        $renamed = self::renamed_boxes( self::get_standard_boxes(), $boxes );

        update_option( 'fst_standard_boxes', self::sanitize_standard_boxes( $boxes ) );
        update_option( 'fst_label_sources', self::sanitize_label_sources( $sources ) );

        // Supplies — after the boxes, because a box supply is tied to one of them by name.
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised row by row in FST_Supplies::sanitize_supplies().
        $supplies = isset( $_POST['fst_supplies'] ) && is_array( $_POST['fst_supplies'] ) ? wp_unslash( $_POST['fst_supplies'] ) : array();

        // A box renamed in this same save takes its box supply along.
        foreach ( $supplies as $index => $row ) {
            if ( ! is_array( $row ) || ! isset( $row['role'] ) || ! is_string( $row['role'] ) || 0 !== strpos( $row['role'], 'box:' ) ) {
                continue;
            }
            $old_name = FST_Label_Cost::lower( substr( $row['role'], 4 ) );
            if ( isset( $renamed[ $old_name ] ) ) {
                $supplies[ $index ]['role'] = 'box:' . $renamed[ $old_name ];
            }
        }
        $clean    = FST_Supplies::sanitize_supplies( $supplies, self::get_standard_boxes(), (int) get_option( FST_Supplies::OPTION_LAST_ID, 0 ) );

        update_option( FST_Supplies::OPTION, $clean['supplies'] );
        update_option( FST_Supplies::OPTION_LAST_ID, $clean['last_id'] );

        $waste = isset( $_POST['fst_foam_waste_percent'] ) && is_scalar( $_POST['fst_foam_waste_percent'] ) ? sanitize_text_field( wp_unslash( $_POST['fst_foam_waste_percent'] ) ) : '';
        update_option( FST_Supplies::OPTION_WASTE, FST_Supplies::get_waste_percent( $waste ) );
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
