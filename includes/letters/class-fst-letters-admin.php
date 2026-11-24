<?php
/**
 * USPS Letter admin UI: order meta box, AJAX actions, printable label,
 * flag notices, and the Settings > Letters tab.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Letters_Admin {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ), 10, 2 );

        add_action( 'wp_ajax_fst_letter_create', array( $this, 'ajax_create' ) );
        add_action( 'wp_ajax_fst_letter_mark_mailed', array( $this, 'ajax_mark_mailed' ) );
        add_action( 'wp_ajax_fst_letter_void', array( $this, 'ajax_void' ) );
        add_action( 'wp_ajax_fst_letter_resend', array( $this, 'ajax_resend' ) );
        add_action( 'wp_ajax_fst_letter_dismiss_flag', array( $this, 'ajax_dismiss_flag' ) );
        add_action( 'wp_ajax_fst_test_ivmtr', array( $this, 'ajax_test_ivmtr' ) );

        add_action( 'admin_post_fst_letter_label', array( $this, 'render_label_page' ) );

        add_action( 'admin_notices', array( $this, 'flag_notices' ) );
    }

    /* ------------------------------------------------------------------
     * Meta box
     * ---------------------------------------------------------------- */

    public function add_meta_box( $screen_id, $post_or_order ) {
        $valid = array( 'shop_order' );
        if ( function_exists( 'wc_get_page_screen_id' ) ) {
            $valid[] = wc_get_page_screen_id( 'shop-order' );
        }
        if ( ! in_array( $screen_id, $valid, true ) ) {
            return;
        }

        $order = $this->resolve_order( $post_or_order );
        // Sticker orders get the box high in the sidebar; everyone else lower down.
        $priority = ( $order && $order->get_meta( FST_Letters::STICKER_META ) ) ? 'high' : 'low';

        add_meta_box(
            'fst-letter',
            __( 'USPS Letter Tracking', 'fishotel-shiptracker' ),
            array( $this, 'render_meta_box' ),
            $screen_id,
            'side',
            $priority
        );
    }

    private function resolve_order( $post_or_order ) {
        if ( $post_or_order instanceof WC_Order ) {
            return $post_or_order;
        }
        if ( $post_or_order instanceof WP_Post ) {
            return wc_get_order( $post_or_order->ID );
        }
        return null;
    }

    public function render_meta_box( $post_or_order ) {
        $order = $this->resolve_order( $post_or_order );
        if ( ! $order ) {
            return;
        }

        $order_id   = $order->get_id();
        $is_sticker = (bool) $order->get_meta( FST_Letters::STICKER_META );
        $letters    = FST_Letters::get_by_order( $order_id );
        $settings_ok = FST_Letters::validate_barcode_settings();
        $has_active = false;
        ?>
        <div id="fst-letter-metabox" data-order-id="<?php echo esc_attr( $order_id ); ?>">
            <?php if ( $is_sticker ) : ?>
                <p style="margin:0 0 8px;padding:6px 8px;background:#fff8e5;border-left:3px solid #dba617;">
                    <strong><?php esc_html_e( 'Sticker goes by letter', 'fishotel-shiptracker' ); ?></strong>
                </p>
            <?php endif; ?>

            <?php if ( is_wp_error( $settings_ok ) ) : ?>
                <p style="color:#b32d2e;margin:0 0 8px;">
                    <?php echo esc_html( $settings_ok->get_error_message() ); ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=fst-settings&tab=letters' ) ); ?>"><?php esc_html_e( 'Letter settings', 'fishotel-shiptracker' ); ?></a>
                </p>
            <?php endif; ?>

            <?php foreach ( $letters as $letter ) :
                if ( 'voided' === $letter->state ) {
                    continue;
                }
                $shipment = FST_Shipment::get( $letter->shipment_id );
                if ( ! $shipment ) {
                    continue;
                }
                $has_active = $has_active || 'active' === $letter->state;
                $label_url  = self::label_url( $letter->id );
                ?>
                <div class="fst-letter-item" style="border:1px solid #e0e0e0;border-radius:4px;padding:8px;margin-bottom:8px;">
                    <div>
                        <span class="fst-status-badge" style="background-color:<?php echo esc_attr( FST_Carrier::get_status_color( $shipment->status ) ); ?>">
                            <?php echo esc_html( FST_Carrier::get_status_label( $shipment->status ) ); ?>
                        </span>
                        <span style="font-size:11px;color:#666;margin-left:4px;"><?php echo esc_html( ucfirst( $letter->contents ) ); ?></span>
                        <?php if ( 'resent' === $letter->state ) : ?>
                            <span style="font-size:11px;color:#999;">(<?php esc_html_e( 'resent', 'fishotel-shiptracker' ); ?>)</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-family:monospace;font-size:11px;word-break:break-all;margin:4px 0;" title="<?php esc_attr_e( 'IMb (tracking code + routing code)', 'fishotel-shiptracker' ); ?>">
                        <?php echo esc_html( FST_IMb::format_human( $letter->tracking_code, $letter->routing_code, '9' === substr( $letter->tracking_code, 5, 1 ) ? 9 : 6 ) ); ?>
                    </div>
                    <?php if ( $shipment->status_detail ) : ?>
                        <div style="font-size:12px;color:#555;"><?php echo esc_html( $shipment->status_detail ); ?></div>
                    <?php endif; ?>
                    <?php if ( 'validated' !== $letter->address_status ) : ?>
                        <div style="font-size:12px;color:#b32d2e;margin-top:4px;">
                            &#9888; <?php esc_html_e( 'Address not fully validated:', 'fishotel-shiptracker' ); ?>
                            <?php echo esc_html( $letter->address_message ); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ( $letter->mailed_at ) : ?>
                        <div style="font-size:11px;color:#666;margin-top:4px;">
                            <?php printf( esc_html__( 'Mailed %s', 'fishotel-shiptracker' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $letter->mailed_at ) ) ) ); ?>
                            &middot; <?php printf( esc_html( _n( '%d scan', '%d scans', (int) $letter->scan_count, 'fishotel-shiptracker' ) ), (int) $letter->scan_count ); ?>
                        </div>
                    <?php endif; ?>
                    <?php if ( $letter->flag && ! $letter->flag_dismissed ) : ?>
                        <div style="font-size:12px;color:#b32d2e;margin-top:4px;font-weight:600;">
                            <?php echo 'stale' === $letter->flag ? esc_html__( 'No delivery scan after 14+ days', 'fishotel-shiptracker' ) : esc_html__( 'No USPS scans yet', 'fishotel-shiptracker' ); ?>
                        </div>
                    <?php endif; ?>

                    <?php if ( 'active' === $letter->state ) : ?>
                        <div style="margin-top:8px;display:flex;flex-wrap:wrap;gap:4px;">
                            <a href="<?php echo esc_url( $label_url ); ?>" target="_blank" class="button button-small"><?php echo $letter->print_count ? esc_html__( 'Reprint label', 'fishotel-shiptracker' ) : esc_html__( 'Print label', 'fishotel-shiptracker' ); ?></a>
                            <?php if ( ! $letter->mailed_at ) : ?>
                                <button type="button" class="button button-small button-primary fst-letter-action" data-action="fst_letter_mark_mailed" data-letter="<?php echo esc_attr( $letter->id ); ?>"><?php esc_html_e( 'Mark mailed', 'fishotel-shiptracker' ); ?></button>
                                <button type="button" class="button button-small fst-letter-action" data-action="fst_letter_void" data-letter="<?php echo esc_attr( $letter->id ); ?>" data-confirm="<?php esc_attr_e( 'Void this barcode and assign a NEW serial? Throw away any labels already printed with the old barcode.', 'fishotel-shiptracker' ); ?>"><?php esc_html_e( 'Void & regenerate', 'fishotel-shiptracker' ); ?></button>
                            <?php else : ?>
                                <button type="button" class="button button-small fst-letter-action" data-action="fst_letter_resend" data-letter="<?php echo esc_attr( $letter->id ); ?>" data-confirm="<?php esc_attr_e( 'Create a fresh letter (new barcode) to resend?', 'fishotel-shiptracker' ); ?>"><?php esc_html_e( 'Resend', 'fishotel-shiptracker' ); ?></button>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>

            <?php if ( ! $has_active || ! FST_Letters::get_active_unmailed_letter( $order_id ) ) : ?>
                <p style="margin:0 0 6px;">
                    <label for="fst-letter-contents" style="font-size:12px;"><?php esc_html_e( 'Contents (used in emails):', 'fishotel-shiptracker' ); ?></label>
                    <input type="text" id="fst-letter-contents" class="widefat" value="<?php echo esc_attr( FST_Letters::default_contents( $order ) ); ?>">
                </p>
                <button type="button" class="button <?php echo $is_sticker && ! $has_active ? 'button-primary' : ''; ?> widefat" id="fst-letter-create" <?php disabled( is_wp_error( $settings_ok ) ); ?>>
                    <?php echo $has_active ? esc_html__( 'Create another letter', 'fishotel-shiptracker' ) : esc_html__( 'Print letter label', 'fishotel-shiptracker' ); ?>
                </button>
            <?php endif; ?>
            <div id="fst-letter-message" style="margin-top:8px;display:none;"></div>
        </div>
        <script>
        (function ($) {
            $(function () {
                var $box = $('#fst-letter-metabox');
                if (!$box.length) return;
                var orderId = $box.data('order-id');
                var $msg = $('#fst-letter-message');

                function fail(m) { $msg.text(m || 'Request failed.').css('color', '#b32d2e').show(); }

                $('#fst-letter-create').on('click', function () {
                    var $btn = $(this);
                    // Open the print window now (inside the click) so popup blockers allow it.
                    var win = window.open('', '_blank');
                    $btn.prop('disabled', true);
                    $msg.hide();
                    $.post(fst_admin.ajax_url, {
                        action: 'fst_letter_create',
                        nonce: fst_admin.nonce,
                        order_id: orderId,
                        contents: $('#fst-letter-contents').val()
                    }).done(function (r) {
                        if (r && r.success) {
                            if (win) { win.location = r.data.label_url; } else { window.open(r.data.label_url, '_blank'); }
                            window.location.reload();
                        } else {
                            if (win) win.close();
                            fail(r && r.data && r.data.message);
                            $btn.prop('disabled', false);
                        }
                    }).fail(function () {
                        if (win) win.close();
                        fail();
                        $btn.prop('disabled', false);
                    });
                });

                $box.on('click', '.fst-letter-action', function () {
                    var $btn = $(this);
                    var confirmMsg = $btn.data('confirm');
                    if (confirmMsg && !window.confirm(confirmMsg)) return;
                    $btn.prop('disabled', true);
                    $.post(fst_admin.ajax_url, {
                        action: $btn.data('action'),
                        nonce: fst_admin.nonce,
                        letter_id: $btn.data('letter')
                    }).done(function (r) {
                        if (r && r.success) {
                            if (r.data && r.data.label_url) { window.open(r.data.label_url, '_blank'); }
                            window.location.reload();
                        } else {
                            fail(r && r.data && r.data.message);
                            $btn.prop('disabled', false);
                        }
                    }).fail(function () { fail(); $btn.prop('disabled', false); });
                });
            });
        })(jQuery);
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * AJAX
     * ---------------------------------------------------------------- */

    private function guard() {
        check_ajax_referer( 'fst_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( array( 'message' => 'Permission denied.' ), 403 );
        }
    }

    private function letter_from_request() {
        $letter = FST_Letters::get( isset( $_POST['letter_id'] ) ? absint( $_POST['letter_id'] ) : 0 );
        if ( ! $letter ) {
            wp_send_json_error( array( 'message' => 'Letter not found.' ) );
        }
        return $letter;
    }

    public function ajax_create() {
        $this->guard();

        $order = wc_get_order( isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0 );
        if ( ! $order ) {
            wp_send_json_error( array( 'message' => 'Order not found.' ) );
        }

        $contents = isset( $_POST['contents'] ) ? sanitize_text_field( wp_unslash( $_POST['contents'] ) ) : '';
        $letter   = FST_Letters::get_or_create_for_order( $order, array( 'contents' => $contents ) );

        if ( is_wp_error( $letter ) ) {
            FST_Letters::log( 'Create letter failed for order ' . $order->get_id() . ': ' . $letter->get_error_message(), 'error' );
            wp_send_json_error( array( 'message' => $letter->get_error_message() ) );
        }

        wp_send_json_success( array(
            'letter_id' => (int) $letter->id,
            'imb'       => $letter->imb,
            'label_url' => self::label_url( $letter->id ),
        ) );
    }

    public function ajax_mark_mailed() {
        $this->guard();
        $letter = $this->letter_from_request();
        $result = FST_Letters::mark_mailed( $letter->id );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success();
    }

    public function ajax_void() {
        $this->guard();
        $letter = $this->letter_from_request();
        $new    = FST_Letters::void_and_regenerate( $letter->id );
        if ( is_wp_error( $new ) ) {
            wp_send_json_error( array( 'message' => $new->get_error_message() ) );
        }
        wp_send_json_success( array( 'label_url' => self::label_url( $new->id ) ) );
    }

    public function ajax_resend() {
        $this->guard();
        $letter = $this->letter_from_request();
        $new    = FST_Letters::resend( $letter->id );
        if ( is_wp_error( $new ) ) {
            wp_send_json_error( array( 'message' => $new->get_error_message() ) );
        }
        wp_send_json_success( array(
            'label_url' => self::label_url( $new->id ),
            'order_url' => ( $o = wc_get_order( $new->order_id ) ) ? $o->get_edit_order_url() : '',
        ) );
    }

    public function ajax_dismiss_flag() {
        $this->guard();
        $letter = $this->letter_from_request();
        FST_Letters::update( $letter->id, array( 'flag_dismissed' => 1 ) );
        FST_Letters::log( sprintf( 'Flag %s on letter %d dismissed by user %d', $letter->flag, $letter->id, get_current_user_id() ) );
        wp_send_json_success();
    }

    public function ajax_test_ivmtr() {
        $this->guard();
        $result = FST_IVMTR::test_connection();
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        wp_send_json_success( array( 'message' => $result ) );
    }

    /* ------------------------------------------------------------------
     * Flag notices
     * ---------------------------------------------------------------- */

    public function flag_notices() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $ok_screens = array( 'dashboard', 'toplevel_page_fst-dashboard', 'shiptracker_page_fst-settings', 'edit-shop_order', 'woocommerce_page_wc-orders', 'shop_order' );
        if ( ! $screen || ! in_array( $screen->id, $ok_screens, true ) ) {
            return;
        }

        $flags = FST_Letters::get_open_flags();
        if ( empty( $flags ) ) {
            return;
        }
        ?>
        <div class="notice notice-warning" id="fst-letter-flags">
            <p><strong><?php esc_html_e( 'ShipTracker: letters that need a look', 'fishotel-shiptracker' ); ?></strong></p>
            <ul style="margin-left:1em;">
                <?php foreach ( $flags as $letter ) :
                    $order = wc_get_order( $letter->order_id );
                    if ( ! $order ) {
                        continue;
                    }
                    ?>
                    <li data-letter="<?php echo esc_attr( $letter->id ); ?>">
                        <a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">#<?php echo esc_html( $order->get_order_number() ); ?></a>
                        <?php echo esc_html( $order->get_formatted_billing_full_name() ); ?> &mdash;
                        <?php if ( 'stale' === $letter->flag ) : ?>
                            <?php printf( esc_html__( '%1$s mailed %2$s, no delivery scan after %3$d days.', 'fishotel-shiptracker' ), esc_html( ucfirst( $letter->contents ) ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $letter->mailed_at ) ) ), (int) FST_Letters::setting( 'stale_days' ) ); ?>
                            <button type="button" class="button button-small button-primary fst-flag-action" data-action="fst_letter_resend"><?php printf( esc_html__( 'Resend %s', 'fishotel-shiptracker' ), esc_html( $letter->contents ) ); ?></button>
                        <?php else : ?>
                            <?php printf( esc_html__( 'mailed %1$s, no USPS scans after %2$d days (may have been hand-sorted).', 'fishotel-shiptracker' ), esc_html( date_i18n( get_option( 'date_format' ), strtotime( $letter->mailed_at ) ) ), (int) FST_Letters::setting( 'no_scan_days' ) ); ?>
                        <?php endif; ?>
                        <button type="button" class="button button-small fst-flag-action" data-action="fst_letter_dismiss_flag"><?php esc_html_e( 'Dismiss', 'fishotel-shiptracker' ); ?></button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <script>
        jQuery(function ($) {
            $('#fst-letter-flags').on('click', '.fst-flag-action', function () {
                var $btn = $(this), $li = $btn.closest('li');
                if ($btn.data('action') === 'fst_letter_resend' && !window.confirm('<?php echo esc_js( __( 'Create a fresh letter with a new barcode and open its label?', 'fishotel-shiptracker' ) ); ?>')) return;
                var win = $btn.data('action') === 'fst_letter_resend' ? window.open('', '_blank') : null;
                $btn.prop('disabled', true);
                $.post(ajaxurl, {
                    action: $btn.data('action'),
                    nonce: '<?php echo esc_js( wp_create_nonce( 'fst_nonce' ) ); ?>',
                    letter_id: $li.data('letter')
                }).done(function (r) {
                    if (r && r.success) {
                        if (win && r.data && r.data.label_url) { win.location = r.data.label_url; }
                        $li.fadeOut();
                    } else {
                        if (win) win.close();
                        alert((r && r.data && r.data.message) || 'Request failed.');
                        $btn.prop('disabled', false);
                    }
                }).fail(function () { if (win) win.close(); $btn.prop('disabled', false); });
            });
        });
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * Printable label
     * ---------------------------------------------------------------- */

    public static function label_url( $letter_id ) {
        // Raw URL (not wp_nonce_url, which HTML-encodes "&"): it's also handed
        // to window.location from AJAX. Escape with esc_url() when echoing.
        return add_query_arg(
            array(
                'action'   => 'fst_letter_label',
                'letter'   => (int) $letter_id,
                '_wpnonce' => wp_create_nonce( 'fst_letter_label_' . (int) $letter_id ),
            ),
            admin_url( 'admin-post.php' )
        );
    }

    /**
     * Label geometry per size setting. All values in inches.
     *
     * @return array { page_w, page_h, box_w, box_h, rotate }
     */
    public static function label_geometry( $size, $s ) {
        switch ( $size ) {
            case '4x6_portrait':
                return array( 'page_w' => 4, 'page_h' => 6, 'box_w' => 4, 'box_h' => 6, 'rotate' => false );
            case 'env10':
                return array( 'page_w' => 9.5, 'page_h' => 4.125, 'box_w' => 9.5, 'box_h' => 4.125, 'rotate' => false );
            case 'custom':
                $w = max( 3, min( 12, (float) $s['label_custom_width'] ) );
                $h = max( 2, min( 12, (float) $s['label_custom_height'] ) );
                return array( 'page_w' => $w, 'page_h' => $h, 'box_w' => $w, 'box_h' => $h, 'rotate' => false );
            case '4x6':
            default:
                // 4x6 thermal stock, laid out landscape (6 wide × 4 tall) so the
                // label sits across a #10 envelope with the stamp corner free.
                return array( 'page_w' => 4, 'page_h' => 6, 'box_w' => 6, 'box_h' => 4, 'rotate' => true );
        }
    }

    public function render_label_page() {
        $letter_id = isset( $_GET['letter'] ) ? absint( $_GET['letter'] ) : 0;

        if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '', 'fst_letter_label_' . $letter_id ) ) {
            wp_die( 'Not allowed.' );
        }

        $letter = FST_Letters::get( $letter_id );
        if ( ! $letter ) {
            wp_die( 'Letter not found.' );
        }
        if ( 'voided' === $letter->state ) {
            wp_die( 'This letter barcode was voided. Print the replacement from the order screen.' );
        }

        $order = wc_get_order( $letter->order_id );
        $s     = FST_Letters::get_settings();

        if ( isset( $_GET['size'] ) ) {
            $s['label_size'] = sanitize_key( wp_unslash( $_GET['size'] ) );
        }
        $geo = self::label_geometry( $s['label_size'], $s );

        FST_Letters::record_print( $letter );
        FST_Letters::log( sprintf( 'Label printed for letter %d (IMb %s), size %s', $letter->id, $letter->imb, $s['label_size'] ) );

        $bars = FST_IMb::encode( $letter->tracking_code, $letter->routing_code );
        $svg  = FST_IMb::render_svg( $bars, array( 'dpi' => (int) $s['printer_dpi'] ) );

        $to = json_decode( (string) $letter->recipient, true );
        if ( ! is_array( $to ) ) {
            $to = array();
        }

        $to_lines = array_filter( array(
            $to['name'] ?? '',
            $to['company'] ?? '',
            trim( ( $to['address1'] ?? '' ) . ' ' . ( $to['address2'] ?? '' ) ),
            trim( ( $to['city'] ?? '' ) . ' ' . ( $to['state'] ?? '' ) . '  ' . ( $to['zip'] ?? '' ) ),
        ) );
        $to_lines = array_map( 'strtoupper', $to_lines );

        $return_lines = array_filter( array_map( 'trim', explode( "\n", (string) $s['return_address'] ) ) );
        if ( empty( $return_lines ) ) {
            $return_lines = array( get_bloginfo( 'name' ) );
        }

        // Layout positions (inches) inside the label box.
        $env      = 'env10' === $s['label_size'];
        $portrait = $geo['box_h'] > $geo['box_w'];
        $to_left  = $env ? 3.5 : ( $portrait ? 0.3 : max( 0.3, $geo['box_w'] * 0.3 ) );
        // Keep the address block clear of the bottom 5/8" (USPS barcode clear zone)
        // and inside the OCR read area.
        $to_bottom = $portrait ? $geo['box_h'] * 0.35 : 0.75;
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?php echo esc_html( sprintf( 'Letter label — order #%s', $order ? $order->get_order_number() : $letter->order_id ) ); ?></title>
<style>
    @page { size: <?php echo esc_html( $geo['page_w'] ); ?>in <?php echo esc_html( $geo['page_h'] ); ?>in; margin: 0; }
    html, body { margin: 0; padding: 0; background: #fff; }
    body { font-family: Arial, Helvetica, sans-serif; color: #000; }
    .page { width: <?php echo esc_html( $geo['page_w'] ); ?>in; height: <?php echo esc_html( $geo['page_h'] ); ?>in; position: relative; overflow: hidden; }
    .box {
        width: <?php echo esc_html( $geo['box_w'] ); ?>in; height: <?php echo esc_html( $geo['box_h'] ); ?>in;
        position: absolute; top: 0; left: 0;
        <?php if ( $geo['rotate'] ) : ?>
        transform-origin: top left; transform: translateX(<?php echo esc_html( $geo['page_w'] ); ?>in) rotate(90deg);
        <?php endif; ?>
    }
    .return { position: absolute; top: 0.25in; left: 0.25in; font-size: 9pt; line-height: 1.25; }
    .ref { position: absolute; top: 0.25in; right: 0.25in; font-size: 7pt; color: #444; }
    .to { position: absolute; left: <?php echo esc_html( $to_left ); ?>in; bottom: <?php echo esc_html( $to_bottom ); ?>in; }
    .to svg { display: block; margin-left: -0.125in; } /* left quiet zone lines up with the address text */
    .to .lines { font-size: 11pt; line-height: 1.25; font-weight: bold; letter-spacing: 0.02em; }
    .toolbar { font: 13px -apple-system, Segoe UI, sans-serif; padding: 12px; background: #f6f7f7; border-bottom: 1px solid #ddd; }
    .toolbar .warn { color: #b32d2e; font-weight: 600; margin: 6px 0; }
    @media print { .toolbar { display: none; } }
    @media screen { .page { margin: 16px; box-shadow: 0 0 0 1px #ccc; } }
</style>
</head>
<body>
<div class="toolbar">
    <button onclick="window.print()">Print</button>
    &nbsp;Print at <strong>100% / Actual size</strong> (no "fit to page"), margins none.
    Size: <?php echo esc_html( $s['label_size'] ); ?> &middot; <?php echo (int) $s['printer_dpi']; ?> dpi &middot;
    IMb <?php echo esc_html( FST_IMb::format_human( $letter->tracking_code, $letter->routing_code, FST_Letters::mid_length( $s['mid'] ) ) ); ?>
    <?php if ( 'validated' !== $letter->address_status ) : ?>
        <div class="warn">&#9888; Address not fully validated: <?php echo esc_html( $letter->address_message ); ?> The barcode uses a <?php echo strlen( $letter->routing_code ); ?>-digit routing code.</div>
    <?php endif; ?>
    <div>Other sizes:
        <?php foreach ( array( '4x6' => '4×6 (landscape on envelope)', '4x6_portrait' => '4×6 portrait', 'env10' => '#10 envelope' ) as $k => $lbl ) : ?>
            <a href="<?php echo esc_url( add_query_arg( 'size', $k ) ); ?>"><?php echo esc_html( $lbl ); ?></a> &nbsp;
        <?php endforeach; ?>
    </div>
</div>
<div class="page">
    <div class="box">
        <div class="return"><?php echo implode( '<br>', array_map( 'esc_html', $return_lines ) ); ?></div>
        <?php if ( ! $env ) : ?>
            <div class="ref"><?php echo esc_html( $order ? '#' . $order->get_order_number() : '' ); ?></div>
        <?php endif; ?>
        <div class="to">
            <?php echo $svg; // Generated by FST_IMb::render_svg — numeric attributes only. ?>
            <div class="lines"><?php echo implode( '<br>', array_map( 'esc_html', $to_lines ) ); ?></div>
        </div>
    </div>
</div>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
        <?php
        exit;
    }

    /* ------------------------------------------------------------------
     * Settings tab (rendered inside FST_Settings' form)
     * ---------------------------------------------------------------- */

    public static function render_settings_tab() {
        $s         = FST_Letters::get_settings();
        $templates = get_option( 'fst_letter_email_templates', array() );
        $defaults  = FST_Letters::default_templates();
        $self_test = self::encoder_self_test();
        ?>
        <div class="notice notice-info inline" style="margin:12px 0;padding:10px 14px;">
            <p style="margin-top:0;"><strong><?php esc_html_e( 'Before the first letter (one-time USPS setup)', 'fishotel-shiptracker' ); ?></strong></p>
            <ol style="margin-bottom:0;">
                <li><?php esc_html_e( 'Business Customer Gateway (gateway.usps.com): confirm your Mailer ID (MID) and CRID and enter them below. You probably have these already from the April 2026 USPS access-control setup.', 'fishotel-shiptracker' ); ?></li>
                <li><?php esc_html_e( 'Look up the Service Type ID (STID) for single-piece First-Class Mail with IV-MTR tracking and no ACS in the current USPS STID table on PostalPro, and enter it below.', 'fishotel-shiptracker' ); ?></li>
                <li><?php esc_html_e( 'In the BCG, add the Informed Visibility (IV-MTR) service for that MID and set up data access as described in the IV-MTR section below.', 'fishotel-shiptracker' ); ?></li>
                <li><?php esc_html_e( 'Postage is a normal Forever stamp. The barcode only tracks the letter. It does not pay postage.', 'fishotel-shiptracker' ); ?></li>
            </ol>
        </div>

        <h3><?php esc_html_e( 'Barcode (IMb)', 'fishotel-shiptracker' ); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="fst_letter_mid"><?php esc_html_e( 'Mailer ID (MID)', 'fishotel-shiptracker' ); ?></label></th>
                <td><input type="text" id="fst_letter_mid" name="fst_letter[mid]" value="<?php echo esc_attr( $s['mid'] ); ?>" class="regular-text" inputmode="numeric">
                    <p class="description"><?php esc_html_e( '9 digits (starts with 9) or 6 digits. A 9-digit MID leaves 6 digits for the serial; a 6-digit MID leaves 9.', 'fishotel-shiptracker' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="fst_letter_crid"><?php esc_html_e( 'CRID', 'fishotel-shiptracker' ); ?></label></th>
                <td><input type="text" id="fst_letter_crid" name="fst_letter[crid]" value="<?php echo esc_attr( $s['crid'] ); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e( 'For reference / IV-MTR setup. Not encoded in the barcode.', 'fishotel-shiptracker' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="fst_letter_stid"><?php esc_html_e( 'Service Type ID (STID)', 'fishotel-shiptracker' ); ?></label></th>
                <td><input type="text" id="fst_letter_stid" name="fst_letter[stid]" value="<?php echo esc_attr( $s['stid'] ); ?>" class="small-text" maxlength="3">
                    <p class="description"><?php esc_html_e( 'Required, with deliberately no default. Use the current USPS STID table on PostalPro. In the table effective Jan 21, 2024 it is 310: First-Class Mail, "No Address Corrections – No Printed Endorsements", Basic/Nonautomation, with IV-MTR. Check the table again if USPS publishes a new one.', 'fishotel-shiptracker' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><label for="fst_letter_barcode_id"><?php esc_html_e( 'Barcode ID', 'fishotel-shiptracker' ); ?></label></th>
                <td><input type="text" id="fst_letter_barcode_id" name="fst_letter[barcode_id]" value="<?php echo esc_attr( $s['barcode_id'] ); ?>" class="small-text" maxlength="2">
                    <p class="description"><?php esc_html_e( '"00" for a normal letter with no OEL / presort sort code.', 'fishotel-shiptracker' ); ?></p></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Serial counter', 'fishotel-shiptracker' ); ?></th>
                <td>
                    <code><?php echo esc_html( FST_Letters::current_serial() ); ?></code>
                    <span class="description"><?php printf( esc_html__( 'Last serial issued (%d-digit field). Increments atomically; serials never repeat within %d days.', 'fishotel-shiptracker' ), (int) FST_Letters::serial_length(), (int) FST_Letters::UNIQUENESS_DAYS ); ?></span>
                    <p style="margin-top:8px;">
                        <label><input type="checkbox" name="fst_letter_reset_confirm" value="1"> <?php esc_html_e( 'Reset counter to', 'fishotel-shiptracker' ); ?></label>
                        <input type="number" name="fst_letter_reset_value" min="0" class="small-text" style="width:120px;">
                    </p>
                    <p class="description"><?php esc_html_e( 'Only needed if you change MIDs. Resets that would reuse a serial from the last 45 days are refused.', 'fishotel-shiptracker' ); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Encoder self-test', 'fishotel-shiptracker' ); ?></th>
                <td>
                    <?php if ( $self_test ) : ?>
                        <span style="color:#1e7e34;font-weight:600;">&#10003; <?php esc_html_e( 'Matches all 4 USPS-B-3200 test vectors', 'fishotel-shiptracker' ); ?></span>
                    <?php else : ?>
                        <span style="color:#b32d2e;font-weight:600;">&#10007; <?php esc_html_e( 'Encoder does NOT match the USPS test vectors — do not mail letters.', 'fishotel-shiptracker' ); ?></span>
                    <?php endif; ?>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Label', 'fishotel-shiptracker' ); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="fst_letter_return_address"><?php esc_html_e( 'Return address', 'fishotel-shiptracker' ); ?></label></th>
                <td><textarea id="fst_letter_return_address" name="fst_letter[return_address]" rows="4" class="regular-text"><?php echo esc_textarea( $s['return_address'] ); ?></textarea></td>
            </tr>
            <tr>
                <th scope="row"><label for="fst_letter_label_size"><?php esc_html_e( 'Label size', 'fishotel-shiptracker' ); ?></label></th>
                <td>
                    <select id="fst_letter_label_size" name="fst_letter[label_size]">
                        <?php foreach ( array(
                            '4x6'          => '4×6 thermal (RX106HD), landscape on a #10 envelope',
                            '4x6_portrait' => '4×6 thermal, portrait',
                            'env10'        => '#10 envelope (print directly on the envelope)',
                            'custom'       => 'Custom size (landscape layout)',
                        ) as $k => $lbl ) : ?>
                            <option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['label_size'], $k ); ?>><?php echo esc_html( $lbl ); ?></option>
                        <?php endforeach; ?>
                    </select>
                    &nbsp;<?php esc_html_e( 'Custom:', 'fishotel-shiptracker' ); ?>
                    <input type="number" step="0.125" min="3" max="12" name="fst_letter[label_custom_width]" value="<?php echo esc_attr( $s['label_custom_width'] ); ?>" class="small-text"> ×
                    <input type="number" step="0.125" min="2" max="12" name="fst_letter[label_custom_height]" value="<?php echo esc_attr( $s['label_custom_height'] ); ?>" class="small-text"> in
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="fst_letter_printer_dpi"><?php esc_html_e( 'Printer resolution', 'fishotel-shiptracker' ); ?></label></th>
                <td>
                    <select id="fst_letter_printer_dpi" name="fst_letter[printer_dpi]">
                        <?php foreach ( array( 203, 300, 600 ) as $dpi ) : ?>
                            <option value="<?php echo esc_attr( $dpi ); ?>" <?php selected( (int) $s['printer_dpi'], $dpi ); ?>><?php echo esc_html( $dpi ); ?> dpi</option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e( 'Bars are snapped to whole printer dots so widths stay inside the USPS spec. Use the printer\'s native resolution.', 'fishotel-shiptracker' ); ?></p>
                </td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Tracking timing', 'fishotel-shiptracker' ); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><label for="fst_letter_ld"><?php esc_html_e( '"Likely delivered" after', 'fishotel-shiptracker' ); ?></label></th>
                <td><input type="number" id="fst_letter_ld" min="1" name="fst_letter[likely_delivered_hours]" value="<?php echo esc_attr( $s['likely_delivered_hours'] ); ?>" class="small-text"> <?php esc_html_e( 'hours with no new scans after the final carrier-route sort', 'fishotel-shiptracker' ); ?></td>
            </tr>
            <tr>
                <th scope="row"><label for="fst_letter_ns"><?php esc_html_e( 'Flag "no scans" after', 'fishotel-shiptracker' ); ?></label></th>
                <td><input type="number" id="fst_letter_ns" min="1" name="fst_letter[no_scan_days]" value="<?php echo esc_attr( $s['no_scan_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days after mailing (admin only)', 'fishotel-shiptracker' ); ?></td>
            </tr>
            <tr>
                <th scope="row"><label for="fst_letter_stale"><?php esc_html_e( 'Offer "Resend" after', 'fishotel-shiptracker' ); ?></label></th>
                <td><input type="number" id="fst_letter_stale" min="1" name="fst_letter[stale_days]" value="<?php echo esc_attr( $s['stale_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days after mailing with no "local post office" or "likely delivered" status', 'fishotel-shiptracker' ); ?></td>
            </tr>
        </table>

        <h3><?php esc_html_e( 'Letter emails', 'fishotel-shiptracker' ); ?></h3>
        <p class="description"><?php esc_html_e( 'Letters use their own email copy and never change the WooCommerce order status. Shortcodes: {customer_name}, {order_number}, {letter_contents}, {tracking_progress}, {tracking_events}, {ship_date}. Leave blank for the default.', 'fishotel-shiptracker' ); ?></p>
        <table class="form-table">
            <?php
            $email_rows = array(
                'in_transit'       => __( 'Mailed ("on its way")', 'fishotel-shiptracker' ),
                'out_for_delivery' => __( 'At local post office', 'fishotel-shiptracker' ),
                'likely_delivered' => __( 'Likely delivered ("should be in your mailbox")', 'fishotel-shiptracker' ),
            );
            foreach ( $email_rows as $key => $label ) :
                $tpl = isset( $templates[ $key ] ) ? $templates[ $key ] : array();
                ?>
                <tr>
                    <th scope="row"><?php echo esc_html( $label ); ?></th>
                    <td>
                        <label><input type="checkbox" name="fst_letter[email_<?php echo esc_attr( $key ); ?>]" value="yes" <?php checked( $s[ 'email_' . $key ], 'yes' ); ?>> <?php esc_html_e( 'Email the customer', 'fishotel-shiptracker' ); ?></label>
                        <p><input type="text" class="large-text" name="fst_letter_email_templates[<?php echo esc_attr( $key ); ?>][subject]" value="<?php echo esc_attr( $tpl['subject'] ?? '' ); ?>" placeholder="<?php echo esc_attr( $defaults[ $key ]['subject'] ); ?>"></p>
                        <textarea class="large-text" rows="5" name="fst_letter_email_templates[<?php echo esc_attr( $key ); ?>][body]" placeholder="<?php echo esc_attr( $defaults[ $key ]['body'] ); ?>"><?php echo esc_textarea( $tpl['body'] ?? '' ); ?></textarea>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <th scope="row"><?php esc_html_e( 'Admin flag emails', 'fishotel-shiptracker' ); ?></th>
                <td><label><input type="checkbox" name="fst_letter[email_admin_flags]" value="yes" <?php checked( $s['email_admin_flags'], 'yes' ); ?>> <?php esc_html_e( 'Email the site admin when a letter is flagged (no scans / needs resend)', 'fishotel-shiptracker' ); ?></label></td>
            </tr>
        </table>

        <?php FST_IVMTR::render_settings_section( $s ); ?>
        <?php
    }

    public static function save_settings() {
        $in  = isset( $_POST['fst_letter'] ) && is_array( $_POST['fst_letter'] ) ? wp_unslash( $_POST['fst_letter'] ) : array();
        $old = FST_Letters::get_settings();

        $digits = static function ( $v, $max ) {
            return substr( preg_replace( '/\D/', '', (string) $v ), 0, $max );
        };

        $size = sanitize_key( $in['label_size'] ?? '4x6' );
        $dpi  = (int) ( $in['printer_dpi'] ?? 203 );

        $new = array(
            'mid'                    => $digits( $in['mid'] ?? '', 9 ),
            'crid'                   => $digits( $in['crid'] ?? '', 15 ),
            'stid'                   => $digits( $in['stid'] ?? '', 3 ),
            'barcode_id'             => $digits( $in['barcode_id'] ?? '00', 2 ),
            'return_address'         => sanitize_textarea_field( $in['return_address'] ?? '' ),
            'label_size'             => in_array( $size, array( '4x6', '4x6_portrait', 'env10', 'custom' ), true ) ? $size : '4x6',
            'label_custom_width'     => (string) max( 3, min( 12, (float) ( $in['label_custom_width'] ?? 6 ) ) ),
            'label_custom_height'    => (string) max( 2, min( 12, (float) ( $in['label_custom_height'] ?? 4 ) ) ),
            'printer_dpi'            => in_array( $dpi, array( 203, 300, 600 ), true ) ? $dpi : 203,
            'likely_delivered_hours' => max( 1, (int) ( $in['likely_delivered_hours'] ?? 24 ) ),
            'no_scan_days'           => max( 1, (int) ( $in['no_scan_days'] ?? 5 ) ),
            'stale_days'             => max( 1, (int) ( $in['stale_days'] ?? 14 ) ),
            'email_in_transit'       => ! empty( $in['email_in_transit'] ) ? 'yes' : 'no',
            'email_out_for_delivery' => ! empty( $in['email_out_for_delivery'] ) ? 'yes' : 'no',
            'email_likely_delivered' => ! empty( $in['email_likely_delivered'] ) ? 'yes' : 'no',
            'email_admin_flags'      => ! empty( $in['email_admin_flags'] ) ? 'yes' : 'no',
            'opcode_map'             => sanitize_textarea_field( $in['opcode_map'] ?? '' ),
        );
        if ( '' === $new['barcode_id'] ) {
            $new['barcode_id'] = '00';
        }

        $new = array_merge( $new, FST_IVMTR::sanitize_settings( $in, $old ) );

        update_option( 'fst_letter_settings', array_merge( $old, $new ) );

        $tpl_in = isset( $_POST['fst_letter_email_templates'] ) && is_array( $_POST['fst_letter_email_templates'] ) ? wp_unslash( $_POST['fst_letter_email_templates'] ) : array();
        $tpls   = array();
        foreach ( array( 'in_transit', 'out_for_delivery', 'likely_delivered' ) as $k ) {
            $tpls[ $k ] = array(
                'subject' => sanitize_text_field( $tpl_in[ $k ]['subject'] ?? '' ),
                'body'    => wp_kses_post( $tpl_in[ $k ]['body'] ?? '' ),
            );
        }
        update_option( 'fst_letter_email_templates', $tpls );

        if ( ! empty( $_POST['fst_letter_reset_confirm'] ) && isset( $_POST['fst_letter_reset_value'] ) && '' !== $_POST['fst_letter_reset_value'] ) {
            $r = FST_Letters::reset_serial( absint( $_POST['fst_letter_reset_value'] ) );
            if ( is_wp_error( $r ) ) {
                set_transient( 'fst_letter_settings_error', $r->get_error_message(), 60 );
            }
        }

        if ( $old['mid'] !== $new['mid'] && '' !== $old['mid'] ) {
            FST_Letters::log( sprintf( 'MID changed from %s to %s', $old['mid'], $new['mid'] ), 'notice' );
        }
    }

    /**
     * True when the encoder reproduces all USPS-B-3200 test vectors.
     */
    public static function encoder_self_test() {
        $vectors = array(
            array( '01234567094987654321', '',            'ATTFATTDTTADTAATTDTDTATTDAFDDFADFDFTFFFFFTATFAAAATDFFTDAADFTFDTDT' ),
            array( '01234567094987654321', '01234',       'DTTAFADDTTFTDTFTFDTDDADADAFADFATDDFTAAAFDTTADFAAATDFDTDFADDDTDFFT' ),
            array( '01234567094987654321', '012345678',   'ADFTTAFDTTTTFATTADTAAATFTFTATDAAAFDDADATATDTDTTDFDTDATADADTDFFTFA' ),
            array( '01234567094987654321', '01234567891', 'AADTFFDFTDADTAADAATFDTDDAAADDTDTTDAFADADDDTFFFDDTTTADFAAADFTDAADA' ),
        );
        foreach ( $vectors as $v ) {
            if ( FST_IMb::encode( $v[0], $v[1] ) !== $v[2] ) {
                return false;
            }
        }
        return true;
    }
}
