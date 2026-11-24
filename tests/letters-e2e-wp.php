<?php
/**
 * End-to-end letter lifecycle on a real WordPress + WooCommerce install,
 * with every USPS HTTP call mocked and outgoing mail captured.
 *
 * THROWAWAY SITES ONLY — creates orders and changes ShipTracker settings.
 *   wp eval-file wp-content/plugins/fishotel-shiptracker/tests/letters-e2e-wp.php
 */

if ( ! defined( 'ABSPATH' ) || 'cli' !== PHP_SAPI || ! defined( 'WP_CLI' ) ) {
    exit( "Run with wp eval-file.\n" );
}

$GLOBALS['e2e_fail'] = 0;
function e2e( $cond, $label ) {
    echo ( $cond ? 'PASS ' : 'FAIL ' ) . $label . "\n";
    if ( ! $cond ) {
        $GLOBALS['e2e_fail']++;
    }
}

// ---------------------------------------------------------------------------
// Mocks: USPS HTTP + wp_mail.
// ---------------------------------------------------------------------------
$GLOBALS['e2e_scans']   = array(); // imb => scans[]
$GLOBALS['e2e_mail']    = array();
$GLOBALS['e2e_http']    = array();

add_filter( 'pre_http_request', function ( $pre, $args, $url ) {
    $GLOBALS['e2e_http'][] = $url;
    $json = function ( $code, $body ) {
        return array( 'headers' => array(), 'body' => wp_json_encode( $body ), 'response' => array( 'code' => $code, 'message' => '' ), 'cookies' => array() );
    };
    if ( false !== strpos( $url, 'apis.usps.com/oauth2/v3/token' ) ) {
        return $json( 200, array( 'access_token' => 'addr-token', 'expires_in' => 3600 ) );
    }
    if ( false !== strpos( $url, 'apis.usps.com/addresses/v3/address' ) ) {
        parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $q );
        if ( false !== stripos( $q['streetAddress'] ?? '', 'nowhere' ) ) {
            return $json( 400, array( 'error' => array( 'message' => 'Address Not Found.' ) ) );
        }
        return $json( 200, array(
            'firm'           => null,
            'address'        => array( 'streetAddress' => '11955 CHAMPLIN DR', 'secondaryAddress' => '', 'city' => 'CHAMPLIN', 'state' => 'MN', 'ZIPCode' => '55316', 'ZIPPlus4' => '2340' ),
            'additionalInfo' => array( 'deliveryPoint' => '55', 'carrierRoute' => 'C012', 'DPVConfirmation' => 'Y' ),
        ) );
    }
    if ( false !== strpos( $url, 'services.usps.com/oauth/' ) ) {
        $body = json_decode( $args['body'], true );
        if ( false !== strpos( $url, 'authenticate' ) && ( 'bcguser' !== $body['username'] || '687b8a36-db61-42f7-83f7-11c79bf7785e' !== $body['client_id'] ) ) {
            return $json( 401, array( 'error' => 'invalid' ) );
        }
        return $json( 200, array( 'token_type' => 'Bearer', 'access_token' => 'iv-token', 'expires_in' => 900, 'refresh_token' => 'r' ) );
    }
    if ( false !== strpos( $url, 'iv.usps.com/ivws_api/informedvisapi/api/mt/get/piece/imb/' ) ) {
        $imb = basename( $url );
        if ( empty( $GLOBALS['e2e_scans'][ $imb ] ) ) {
            return $json( 404, array( 'message' => 'Not found', 'data' => null ) );
        }
        return $json( 200, array( 'message' => null, 'data' => array( 'imb' => $imb, 'mail_shape' => 'Letter', 'scans' => $GLOBALS['e2e_scans'][ $imb ] ) ) );
    }
    return new WP_Error( 'e2e_blocked', 'Unexpected HTTP in test: ' . $url );
}, 10, 3 );

add_filter( 'pre_wp_mail', function ( $null, $atts ) {
    $GLOBALS['e2e_mail'][] = $atts;
    return true;
}, 10, 2 );

function e2e_mail_count( $to, $subject_part ) {
    $n = 0;
    foreach ( $GLOBALS['e2e_mail'] as $m ) {
        if ( $m['to'] === $to && false !== stripos( $m['subject'], $subject_part ) ) {
            $n++;
        }
    }
    return $n;
}

function e2e_scan( $when, $opcode, $phase ) {
    return array(
        'scan_date_time'      => gmdate( 'Y-m-d\TH:i:s', $when ),
        'scan_event_code'     => $opcode,
        'mail_phase'          => $phase,
        'scan_facility_city'  => 'MINNEAPOLIS',
        'scan_facility_state' => 'MN',
        'scan_facility_zip'   => '55401',
        'handling_event_type' => 'A',
    );
}

// ---------------------------------------------------------------------------
// Setup.
// ---------------------------------------------------------------------------
update_option( 'fst_usps_client_id', 'cid' );
update_option( 'fst_usps_client_secret', 'csecret' );
update_option( 'fst_letter_settings', array(
    'mid'            => '901234567',
    'crid'           => '12345678',
    'stid'           => '310',
    'barcode_id'     => '00',
    'return_address' => "FisHotel\n123 Reef Way\nChamplin MN 55316",
) );

function e2e_order( $street = '11955 Champlin Dr', $sticker = true ) {
    $o = wc_create_order();
    $p = new WC_Product_Simple();
    $p->set_name( 'EA Sticker Pack' );
    $p->set_regular_price( '5' );
    $p->save();
    $o->add_product( $p, 1 );
    $o->set_billing_first_name( 'Jeff' );
    $o->set_billing_last_name( 'Test' );
    $o->set_billing_email( 'customer@example.com' );
    $o->set_shipping_first_name( 'Jeff' );
    $o->set_shipping_last_name( 'Test' );
    $o->set_shipping_address_1( $street );
    $o->set_shipping_city( 'Champlin' );
    $o->set_shipping_state( 'MN' );
    $o->set_shipping_postcode( '55316' );
    $o->set_shipping_country( 'US' );
    if ( $sticker ) {
        $o->update_meta_data( FST_Letters::STICKER_META, '1' );
    }
    $o->set_status( 'processing' );
    $o->calculate_totals();
    $o->save();
    return wc_get_order( $o->get_id() );
}

// ---------------------------------------------------------------------------
// 1. Create letter: address validation → 11-digit routing → IMb → shipment.
// ---------------------------------------------------------------------------
$order  = e2e_order();
$letter = FST_Letters::get_or_create_for_order( $order );
e2e( ! is_wp_error( $letter ), 'letter created' . ( is_wp_error( $letter ) ? ': ' . $letter->get_error_message() : '' ) );
e2e( 'validated' === $letter->address_status, 'Champlin address validated (DPV Y)' );
e2e( '55316234055' === $letter->routing_code, 'routing code = 55316 + 2340 + 55' );
e2e( 1 === preg_match( '/^00310901234567\d{6}$/', $letter->tracking_code ), 'tracking code = 00 + 310 + MID + 6-digit serial' );
$serial_after_create = FST_Letters::current_serial();
e2e( 31 === strlen( $letter->imb ), 'IMb is 31 digits' );
e2e( 'sticker' === $letter->contents, 'contents defaults to "sticker" on sticker orders' );

$ship = FST_Shipment::get( $letter->shipment_id );
e2e( $ship && 'usps_letter' === $ship->carrier && 'label_created' === $ship->status && $ship->tracking_number === $letter->imb, 'usps_letter shipment, Label Created, tracking ID = IMb' );
e2e( 'processing' === wc_get_order( $order->get_id() )->get_status(), 'order status untouched by letter creation' );

$again = FST_Letters::get_or_create_for_order( $order );
e2e( (int) $again->id === (int) $letter->id && $again->imb === $letter->imb, 'reprint reuses the same IMb (no new serial)' );
e2e( $serial_after_create === FST_Letters::current_serial(), 'serial counter unchanged by reprint' );

// ---------------------------------------------------------------------------
// 2. Void & regenerate.
// ---------------------------------------------------------------------------
$new = FST_Letters::void_and_regenerate( $letter->id );
e2e( ! is_wp_error( $new ) && (int) $new->serial > (int) $letter->serial && FST_Letters::current_serial() > $serial_after_create, 'void & regenerate assigns a new, higher serial' );
e2e( (int) $new->shipment_id === (int) $letter->shipment_id, 'regenerated letter keeps the same shipment row' );
e2e( 'voided' === FST_Letters::get( $letter->id )->state, 'old letter marked voided' );
e2e( FST_Shipment::get( $letter->shipment_id )->tracking_number === $new->imb, 'shipment tracking ID updated to the new IMb' );
e2e( 1 === count( FST_Shipment::get_by_order( $order->get_id() ) ), 'order still has exactly one letter shipment' );
$letter = $new;

// ---------------------------------------------------------------------------
// 3. Mark mailed → In transit + "on its way" email.
// ---------------------------------------------------------------------------
$r = FST_Letters::mark_mailed( $letter->id );
e2e( true === $r, 'mark mailed' );
$ship = FST_Shipment::get( $letter->shipment_id );
e2e( 'in_transit' === $ship->status, 'status → In Transit after Mark mailed' );
e2e( 1 === e2e_mail_count( 'customer@example.com', 'Your sticker is on its way' ), '"Your sticker is on its way!" email sent once' );
e2e( 'processing' === wc_get_order( $order->get_id() )->get_status(), 'order status not changed by letter status' );

// ---------------------------------------------------------------------------
// 4. IV-MTR: not verified → no polling; verify → polling.
// ---------------------------------------------------------------------------
$GLOBALS['e2e_http'] = array();
FST_Letters::run_cron();
e2e( 0 === count( preg_grep( '/iv\.usps\.com/', $GLOBALS['e2e_http'] ) ), 'no IV-MTR calls while IV-MTR is off' );

$s                   = get_option( 'fst_letter_settings' );
$s['ivmtr_method']   = 'api';
$s['ivmtr_username'] = 'bcguser';
$s['ivmtr_password'] = FST_IVMTR::encrypt( 'bcgpass' );
update_option( 'fst_letter_settings', $s );
FST_Letters::run_cron();
e2e( 0 === count( preg_grep( '/iv\.usps\.com/', $GLOBALS['e2e_http'] ) ), 'no polling until "Test connection" has passed' );

$t = FST_IVMTR::test_connection();
e2e( ! is_wp_error( $t ), 'IV-MTR test connection passes against the documented API shape' );
e2e( FST_IVMTR::is_ready(), 'IV-MTR marked verified' );

// Scans: origin → destination → final.
$now = current_time( 'timestamp' );
$GLOBALS['e2e_scans'][ $letter->imb ] = array(
    e2e_scan( $now - 20 * HOUR_IN_SECONDS, '015', 'Phase 0 - Origin Cancellation' ),
    e2e_scan( $now - 18 * HOUR_IN_SECONDS, '891', 'Phase 1a - Origin Processing' ),
);
FST_Shipment::update( $letter->shipment_id, array( 'last_checked' => null ) );
FST_Letters::run_cron();
$ship = FST_Shipment::get( $letter->shipment_id );
e2e( 'in_transit' === $ship->status && 2 === count( FST_Shipment::get_events( $ship->id ) ), 'origin scans stored as events, still In Transit' );
e2e( 1 === e2e_mail_count( 'customer@example.com', 'on its way' ), 'no duplicate "on its way" email on first scans' );
e2e( 2 === (int) FST_Letters::get( $letter->id )->scan_count, 'scan count recorded on the letter' );

$GLOBALS['e2e_scans'][ $letter->imb ][] = e2e_scan( $now - 6 * HOUR_IN_SECONDS, '895', 'Phase 2 - Destination Processing' );
$GLOBALS['e2e_scans'][ $letter->imb ][] = e2e_scan( $now - 2 * HOUR_IN_SECONDS, '919', 'Phase 3c - Destination Sequenced Carrier Sortation' );
FST_Shipment::update( $letter->shipment_id, array( 'last_checked' => null ) );
FST_Letters::run_cron();
$ship = FST_Shipment::get( $letter->shipment_id );
e2e( 'out_for_delivery' === $ship->status, 'carrier-route sort → Out for Delivery ("At local post office")' );
e2e( 0 === e2e_mail_count( 'customer@example.com', 'local post office' ), 'no OFD email by default (setting off)' );

// 30 h of silence after the final sort.
foreach ( $GLOBALS['e2e_scans'][ $letter->imb ] as &$sc ) {
    $sc['scan_date_time'] = gmdate( 'Y-m-d\TH:i:s', strtotime( $sc['scan_date_time'] ) - 28 * HOUR_IN_SECONDS );
}
unset( $sc );
FST_Shipment::update( $letter->shipment_id, array( 'last_checked' => null ) );
FST_Letters::run_cron();
$ship = FST_Shipment::get( $letter->shipment_id );
e2e( 'likely_delivered' === $ship->status, 'no scans for 24h+ after final sort → Likely Delivered' );
e2e( 1 === e2e_mail_count( 'customer@example.com', 'should be in your mailbox' ), '"should be in your mailbox" email sent' );
e2e( empty( $ship->delivered_date ), 'never recorded as delivered' );
e2e( 'processing' === wc_get_order( $order->get_id() )->get_status(), 'order not auto-completed by a likely-delivered letter' );
$all_mail = wp_list_pluck( $GLOBALS['e2e_mail'], 'subject' );
e2e( 0 === count( preg_grep( '/delivered/i', $all_mail ) ), 'no email subject ever says "Delivered"' );

$GLOBALS['e2e_http'] = array();
FST_Shipment::update( $letter->shipment_id, array( 'last_checked' => null ) );
FST_Letters::run_cron();
e2e( 0 === count( preg_grep( '/iv\.usps\.com/', $GLOBALS['e2e_http'] ) ), 'Likely Delivered letters stop polling' );

// ---------------------------------------------------------------------------
// 5. Bad address still prints, with a warning.
// ---------------------------------------------------------------------------
$bad_order = e2e_order( '1 Nowhere Rd', false );
$bad       = FST_Letters::get_or_create_for_order( $bad_order );
e2e( ! is_wp_error( $bad ) && 'unvalidated' === $bad->address_status, 'bad address → letter still created, flagged unvalidated' );
e2e( '55316' === $bad->routing_code && 25 === strlen( $bad->imb ), 'bad address uses a 5-digit routing code' );
e2e( '' !== (string) wc_get_order( $bad_order->get_id() )->get_meta( '_fst_letter_address_warning' ), '"address not fully validated" saved on the order' );
e2e( 'letter' === $bad->contents, 'non-sticker order defaults to "letter"' );

// ---------------------------------------------------------------------------
// 6. No-scan path (fake IMb that IV-MTR never sees) + 14-day resend.
// ---------------------------------------------------------------------------
$ns_order  = e2e_order();
$ns        = FST_Letters::get_or_create_for_order( $ns_order );
FST_Letters::mark_mailed( $ns->id, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 6 * DAY_IN_SECONDS ) );
$mail_before = count( $GLOBALS['e2e_mail'] );
FST_Shipment::update( $ns->shipment_id, array( 'last_checked' => null ) );
FST_Letters::run_cron();
$ns = FST_Letters::get( $ns->id );
e2e( 'no_scan' === $ns->flag, 'no scans 5+ days after mailing → flagged no_scan' );
$new_mail = array_slice( $GLOBALS['e2e_mail'], $mail_before );
e2e( 1 === count( $new_mail ) && get_option( 'admin_email' ) === $new_mail[0]['to'], 'flag emails the admin only (customer not told)' );
e2e( 'in_transit' === FST_Shipment::get( $ns->shipment_id )->status, 'customer-facing status stays In Transit' );
e2e( 1 === count( FST_Letters::get_open_flags() ), 'admin notice lists the flagged letter' );

FST_Letters::update( $ns->id, array( 'mailed_at' => gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 15 * DAY_IN_SECONDS ) ) );
FST_Letters::run_cron();
$ns = FST_Letters::get( $ns->id );
e2e( 'stale' === $ns->flag, '14+ days without delivery-type status → flagged stale (resend offered)' );

$resent = FST_Letters::resend( $ns->id );
e2e( ! is_wp_error( $resent ) && (int) $resent->shipment_id !== (int) $ns->shipment_id, 'one-click resend creates a fresh letter shipment' );
e2e( 'resent' === FST_Letters::get( $ns->id )->state && 0 === count( FST_Letters::get_open_flags() ), 'old letter marked resent; flag cleared' );
e2e( 2 === count( FST_Shipment::get_by_order( $ns_order->get_id() ) ), 'order now has the original + resend shipments' );

// Scans arriving later clear a no_scan flag automatically.
$ns2_order = e2e_order();
$ns2       = FST_Letters::get_or_create_for_order( $ns2_order );
FST_Letters::mark_mailed( $ns2->id, gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 6 * DAY_IN_SECONDS ) );
FST_Letters::check_flags();
$GLOBALS['e2e_scans'][ $ns2->imb ] = array( e2e_scan( current_time( 'timestamp' ) - HOUR_IN_SECONDS, '891', 'Phase 1a - Origin Processing' ) );
FST_Shipment::update( $ns2->shipment_id, array( 'last_checked' => null ) );
FST_Letters::run_cron();
e2e( '' === FST_Letters::get( $ns2->id )->flag, 'late scans clear the no_scan flag' );

// Auto-mark mailed on first scan (no "Mark mailed" click).
$am_order = e2e_order();
$am       = FST_Letters::get_or_create_for_order( $am_order );
$GLOBALS['e2e_scans'][ $am->imb ] = array( e2e_scan( current_time( 'timestamp' ) - 3 * HOUR_IN_SECONDS, '015', 'Phase 0 - Origin Cancellation' ) );
FST_Letters::run_cron();
$am = FST_Letters::get( $am->id );
e2e( ! empty( $am->mailed_at ) && 'in_transit' === FST_Shipment::get( $am->shipment_id )->status, 'first scan auto-marks the letter mailed' );

// ---------------------------------------------------------------------------
// 7. Package tracking unaffected.
// ---------------------------------------------------------------------------
$pkg_order = e2e_order( '11955 Champlin Dr', false );
$pkg_id    = FST_Shipment::create( array( 'order_id' => $pkg_order->get_id(), 'tracking_number' => '1Z999AA10123456784', 'carrier' => 'ups', 'status' => 'in_transit' ) );
$due       = FST_Shipment::get_due_for_poll();
$due_carriers = array_unique( wp_list_pluck( $due, 'carrier' ) );
e2e( in_array( 'ups', $due_carriers, true ) && ! in_array( 'usps_letter', $due_carriers, true ), 'package poll queue includes UPS, excludes letters' );
$counts = FST_Shipment::count_by_carrier();
e2e( ! isset( $counts['usps_letter'] ), 'letters excluded from package analytics' );

// Package status change still goes through the package path (status actions).
update_option( 'fst_status_actions', array( 'out_for_delivery' => array( 'email_customer' => true, 'email_admin' => false, 'order_status' => '' ) ) );
$tracker = FST()->tracker;
$tracker->process_status_change_external( FST_Shipment::get( $pkg_id ), 'in_transit', 'out_for_delivery' );
e2e( 1 === e2e_mail_count( 'customer@example.com', 'is out for delivery' ), 'package OFD email still uses the package template' );

e2e( 'addr-token' === get_transient( 'fst_usps_addresses_token' ) && false === get_transient( 'fst_usps_access_token' ), 'address validation uses its own token cache; package USPS token untouched' );

// ---------------------------------------------------------------------------
// 8. Box costs / supplies / ship mode (1.9.17–1.9.18) don't treat letters as boxes.
// ---------------------------------------------------------------------------
if ( method_exists( 'FST_Shipment', 'get_cost_report_rows' ) ) {
    $report       = FST_Shipment::get_cost_report_rows();
    $report_carriers = array_unique( wp_list_pluck( $report['rows'], 'carrier' ) );
    $letter_imbs  = $GLOBALS['wpdb']->get_col( "SELECT imb FROM {$GLOBALS['wpdb']->prefix}fst_letters" );
    e2e( ! array_intersect( $letter_imbs, $report['tracking_numbers'] ) && ! in_array( 'usps_letter', $report_carriers, true ), 'letters are not boxes in the Box Costs report' );
    e2e( in_array( '1Z999AA10123456784', $report['tracking_numbers'], true ), 'package boxes still in the Box Costs report' );
}
if ( method_exists( FST()->order, 'set_ship_mode' ) ) {
    $sm_order = e2e_order();
    $sm_letter = FST_Letters::get_or_create_for_order( $sm_order );
    $sm = FST()->order->set_ship_mode( $sm_order->get_id(), array( 'mode' => 'local' ) );
    e2e( ! is_wp_error( $sm ), 'an order whose only shipment is a sticker letter can still be marked Local pickup' . ( is_wp_error( $sm ) ? ': ' . $sm->get_error_message() : '' ) );
    $box_order = e2e_order( '11955 Champlin Dr', false );
    FST_Shipment::create( array( 'order_id' => $box_order->get_id(), 'tracking_number' => '1ZBOXONLY000001', 'carrier' => 'ups', 'status' => 'in_transit' ) );
    $sm2 = FST()->order->set_ship_mode( $box_order->get_id(), array( 'mode' => 'local' ) );
    e2e( is_wp_error( $sm2 ) && 'fst_order_has_shipments' === $sm2->get_error_code(), 'an order with a real box label is still blocked from Local pickup' );
}

echo $GLOBALS['e2e_fail'] ? "\n{$GLOBALS['e2e_fail']} FAILED\n" : "\nALL PASSED\n";
