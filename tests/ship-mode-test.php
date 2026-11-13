<?php
/**
 * Plain-PHP test for "how it went out": the rules of a local pickup and of
 * an order that went in another order's box, the service behind
 * fst_set_ship_mode, and the shared-cost maths in the report.
 *
 *   php tests/ship-mode-test.php
 *
 * Exit code 0 = all assertions passed, 1 = at least one failed.
 *
 * @package FisHotel_ShipTracker
 */

require __DIR__ . '/bootstrap.php';

$wpdb = new FST_Test_WPDB();

$fst_test_row_id = 0;

/**
 * A wp_fst_shipments row as the report query returns it.
 */
function fst_test_row( $order_id, $tracking_number, $cost = null, $status = 'delivered' ) {
    global $fst_test_row_id;
    $fst_test_row_id++;

    return (object) array(
        'id'              => $fst_test_row_id,
        'order_id'        => $order_id,
        'tracking_number' => $tracking_number,
        'carrier'         => 'ups',
        'status'          => $status,
        'ship_date'       => '2026-11-03 10:00:00',
        'label_cost'      => $cost,
        'box_weight'      => null,
        'box_dims'        => null,
        'label_source'    => null,
    );
}

/**
 * The orders map build_report() takes: order id => shipping paid.
 */
function fst_test_orders_paid( array $shipping ) {
    $orders = array();
    foreach ( $shipping as $order_id => $paid ) {
        $orders[ $order_id ] = array( 'shipping' => $paid, 'number' => (string) $order_id, 'url' => '' );
    }
    return $orders;
}

/**
 * Index the report's boxes by tracking number.
 */
function fst_test_boxes_by_tracking( array $report ) {
    $boxes = array();
    foreach ( $report['boxes'] as $box ) {
        $boxes[ $box['tracking_number'] ] = $box;
    }
    return $boxes;
}

// ---------------------------------------------------------------------------
// Arguments.
// ---------------------------------------------------------------------------
echo "Arguments\n";

fst_assert_same( array( 'mode' => 'local', 'with_order_id' => 0, 'date' => null ), FST_Ship_Mode::normalize_args( array( 'mode' => ' Local ' ) ), 'local' );
fst_assert_same( array( 'mode' => 'with_order', 'with_order_id' => 34183, 'date' => '2026-11-04 00:00:00' ), FST_Ship_Mode::normalize_args( array( 'mode' => 'with_order', 'with_order_id' => '34183', 'date' => '2026-11-04' ) ), 'with_order, with the day it went out' );
fst_assert_same( 'label', FST_Ship_Mode::normalize_args( array( 'mode' => 'label', 'with_order_id' => 5 ) )['mode'], 'label' );
fst_assert_same( 'label', FST_Ship_Mode::normalize_args( array( 'mode' => 'none' ) )['mode'], '"none" clears, like label' );
fst_assert_same( 'label', FST_Ship_Mode::normalize_args( array() )['mode'], 'so does no mode at all' );
fst_assert_same( 0, FST_Ship_Mode::normalize_args( array( 'mode' => 'local', 'with_order_id' => 5 ) )['with_order_id'], 'a local pickup ignores an order number' );
fst_assert_error( FST_Ship_Mode::normalize_args( array( 'mode' => 'pigeon' ) ), 'fst_invalid_ship_mode', 'an unknown mode' );
fst_assert_error( FST_Ship_Mode::normalize_args( array( 'mode' => 'with_order' ) ), 'fst_invalid_ship_mode', 'with_order needs the other order' );
fst_assert_error( FST_Ship_Mode::normalize_args( array( 'mode' => 'with_order', 'with_order_id' => 'abc' ) ), 'fst_invalid_ship_mode', 'which must be a number' );
fst_assert_error( FST_Ship_Mode::normalize_args( array( 'mode' => 'local', 'date' => '11/04/2026' ) ), 'fst_invalid_ship_mode', 'a date in another format' );

// ---------------------------------------------------------------------------
// Rules.
// ---------------------------------------------------------------------------
echo "Rules\n";

$local = array( 'mode' => 'local', 'with_order_id' => 0, 'date' => null );
$with  = array( 'mode' => 'with_order', 'with_order_id' => 200, 'date' => null );
$facts = array( 'order_id' => 100, 'has_shipments' => false, 'host_exists' => true, 'host_mode' => 'label', 'has_riders' => false );

fst_assert_same( true, FST_Ship_Mode::check( $local, $facts ), 'an order without a label may be a local pickup' );
fst_assert_same( true, FST_Ship_Mode::check( $with, $facts ), 'or go in the box of an order that ships with a label' );
fst_assert_error( FST_Ship_Mode::check( $local, array( 'has_shipments' => true ) + $facts ), 'fst_order_has_shipments', 'an order with a label of its own cannot be a local pickup' );
fst_assert_error( FST_Ship_Mode::check( $with, array( 'has_shipments' => true ) + $facts ), 'fst_order_has_shipments', 'nor go in another order\'s box' );
fst_assert_same( true, FST_Ship_Mode::check( array( 'mode' => 'label', 'with_order_id' => 0, 'date' => null ), array( 'has_shipments' => true ) + $facts ), 'clearing is always allowed' );
fst_assert_error( FST_Ship_Mode::check( array( 'mode' => 'with_order', 'with_order_id' => 100, 'date' => null ), $facts ), 'fst_invalid_ship_mode', 'an order cannot go in its own box' );
fst_assert_error( FST_Ship_Mode::check( $with, array( 'host_exists' => false ) + $facts ), 'fst_order_not_found', 'the other order must exist' );
fst_assert_error( FST_Ship_Mode::check( $with, array( 'host_mode' => 'with_order' ) + $facts ), 'fst_invalid_ship_mode', 'no chains: the other order must not itself be in someone else\'s box' );
fst_assert_error( FST_Ship_Mode::check( $with, array( 'host_mode' => 'local' ) + $facts ), 'fst_invalid_ship_mode', 'a local pickup has no box to go in' );
fst_assert_error( FST_Ship_Mode::check( $with, array( 'has_riders' => true ) + $facts ), 'fst_invalid_ship_mode', 'an order that carries others cannot be carried' );
fst_assert_error( FST_Ship_Mode::check( $local, array( 'has_riders' => true ) + $facts ), 'fst_invalid_ship_mode', 'nor be a local pickup: it would have no box for them to be in' );

// ---------------------------------------------------------------------------
// The service: order meta only.
// ---------------------------------------------------------------------------
echo "Service\n";

$fst_test_options['fst_supplies']       = array(
    array( 'id' => 1, 'name' => 'Fish bag', 'unit' => 'each', 'counted' => true, 'role' => 'fish_bag' ),
    array( 'id' => 2, 'name' => 'Heat pack', 'unit' => 'each', 'counted' => true, 'role' => 'heat_pack' ),
);
$fst_test_options['fst_supply_last_id'] = 2;
FST_Supplies::add_purchase( array( 'supply_id' => 1, 'purchase_date' => '2026-10-01', 'qty' => '500', 'total_paid' => '55.00' ) );

$fst_test_orders[100] = new WC_Order( 100, array( 1, 2 ) );  // Will be a local pickup.
$fst_test_orders[101] = new WC_Order( 101, array( 3 ) );     // Will go in the box of 200.
$fst_test_orders[102] = new WC_Order( 102, array( 4 ) );
$fst_test_orders[200] = new WC_Order( 200, array( 5 ) );     // Ships with a label.
$fst_test_orders[201] = new WC_Order( 201, array( 6 ) );

$service = new FST_Order();
$service->add_tracking( array( 'order_id' => 200, 'tracking_number' => 'HOST-1', 'carrier' => 'ups', 'line_item_ids' => array( 5 ) ) );
$shipment_rows = count( $wpdb->tables['wp_fst_shipments'] );

fst_assert_same(
    array( 'mode' => 'label', 'with_order_id' => null, 'date' => null ),
    FST_Ship_Mode::get( $fst_test_orders[100] ),
    'an order starts as "label": nothing recorded'
);

$result = $service->set_ship_mode( 100, array( 'mode' => 'local', 'materials' => array( 'lines' => array( array( 'supply' => 'Fish bag', 'qty' => 2 ) ) ) ) );
fst_assert_same(
    array( 'Marked as a local pickup.', 100, 'local', null, null, '2026-11-02 09:00:00', '0.22' ),
    array( $result['message'], $result['order_id'], $result['mode'], $result['with_order_id'], $result['with_order_number'], $result['date'], $result['materials_total'] ),
    'a local pickup is recorded with its date and its materials'
);
fst_assert_same( array( 'local', '2026-11-02 09:00:00', 7 ), array( $fst_test_orders[100]->get_meta( '_fst_ship_mode' ), $fst_test_orders[100]->get_meta( '_fst_ship_mode_date' ), $fst_test_orders[100]->get_meta( '_fst_ship_mode_by' ) ), 'as order meta, through the order object (HPOS-safe), with who marked it' );
fst_assert_same( true, $fst_test_orders[100]->saves > 0, 'and the order is saved' );
fst_assert_same( $shipment_rows, count( $wpdb->tables['wp_fst_shipments'] ), 'no shipment row is made up for it' );
fst_assert_same( 'processing', $fst_test_orders[100]->get_status(), 'the order status is left alone' );
fst_assert_same( array( 'ShipTracker: Marked as a local pickup.' ), $fst_test_orders[100]->notes, 'a private note records it' );
fst_assert_same( '2.00', FST_Supplies::get_order_materials( 100 )['lines'][0]['qty'], 'its materials are stored with the order' );
fst_assert_same( '498.00', FST_Supplies::get_stock()[1]['on_hand'], 'and come off the shelf like a box\'s' );

$result = $service->set_ship_mode( 100, array( 'mode' => 'local' ) );
fst_assert_same( '0.22', $result['materials_total'], 'saving the mode again without materials leaves them' );
fst_assert_same( 1, count( $fst_test_orders[100]->notes ), 'and adds no second note' );

$result = $service->set_ship_mode( 100, array( 'mode' => 'local', 'materials' => null ) );
fst_assert_same( array( null, '500.00' ), array( $result['materials'], FST_Supplies::get_stock()[1]['on_hand'] ), 'materials sent as null removes them: back on the shelf' );
$service->set_ship_mode( 100, array( 'mode' => 'local', 'date' => '2026-11-01', 'materials' => '{"lines":[{"supply_id":1,"qty":2}]}' ) );
fst_assert_same( '2026-11-01 00:00:00', FST_Ship_Mode::get( 100 )['date'], 'the day it was picked up can be given' );

fst_assert_error( $service->set_ship_mode( 100, array( 'mode' => 'local', 'materials' => array( 'lines' => array( array( 'supply' => 'Confetti', 'qty' => 1 ) ) ) ) ), 'fst_unknown_supply', 'bad materials are refused' );
fst_assert_same( '2.00', FST_Supplies::get_order_materials( 100 )['lines'][0]['qty'], 'and change nothing' );

$result = $service->set_ship_mode( 101, array( 'mode' => 'with_order', 'with_order_id' => 200, 'materials' => array( 'lines' => array( array( 'supply_id' => 1, 'qty' => 9 ) ) ) ) );
fst_assert_same(
    array( 'Marked as gone in the box of order #200.', 'with_order', 200, '200', null ),
    array( $result['message'], $result['mode'], $result['with_order_id'], $result['with_order_number'], $result['materials'] ),
    'an order that went in another order\'s box points at that order; it carries no materials of its own'
);
fst_assert_same( array( 101 ), FST_Ship_Mode::get_riders_of( 200 ), 'the box\'s order knows who came along' );
fst_assert_same( $shipment_rows, count( $wpdb->tables['wp_fst_shipments'] ), 'still no shipment row' );

fst_assert_error( $service->set_ship_mode( 102, array( 'mode' => 'with_order', 'with_order_id' => 101 ) ), 'fst_invalid_ship_mode', 'no chains: 101 is itself in the box of 200' );
fst_assert_error( $service->set_ship_mode( 102, array( 'mode' => 'with_order', 'with_order_id' => 100 ) ), 'fst_invalid_ship_mode', 'a local pickup has no box' );
fst_assert_error( $service->set_ship_mode( 102, array( 'mode' => 'with_order', 'with_order_id' => 102 ) ), 'fst_invalid_ship_mode', 'not its own box' );
fst_assert_error( $service->set_ship_mode( 102, array( 'mode' => 'with_order', 'with_order_id' => 999 ) ), 'fst_order_not_found', 'the other order must exist' );
fst_assert_error( $service->set_ship_mode( 999, array( 'mode' => 'local' ) ), 'fst_order_not_found', 'and so must the order itself' );
fst_assert_error( $service->set_ship_mode( 200, array( 'mode' => 'local' ) ), 'fst_order_has_shipments', 'an order with tracking cannot become a local pickup' );
fst_assert_error( $service->set_ship_mode( 200, array( 'mode' => 'with_order', 'with_order_id' => 201 ) ), 'fst_order_has_shipments', 'nor go in another box' );
fst_assert_same( 'label', FST_Ship_Mode::get( 102 )['mode'], 'a refused change records nothing' );

// An order that carries another cannot be carried — even before it has a label.
$fst_test_orders[300] = new WC_Order( 300, array( 7 ) );
$fst_test_orders[301] = new WC_Order( 301, array( 8 ) );
$service->set_ship_mode( 301, array( 'mode' => 'with_order', 'with_order_id' => 300 ) );
fst_assert_error( $service->set_ship_mode( 300, array( 'mode' => 'with_order', 'with_order_id' => 201 ) ), 'fst_invalid_ship_mode', 'an order that carries another cannot be carried' );
fst_assert_error( $service->set_ship_mode( 300, array( 'mode' => 'local' ) ), 'fst_invalid_ship_mode', 'nor become a local pickup: 301 would be in a box that does not exist' );
fst_assert_same( array( 'label', array( 301 ) ), array( FST_Ship_Mode::get( 300 )['mode'], FST_Ship_Mode::get_riders_of( 300 ) ), 'it stays as it was, and so does the order it carries' );
fst_assert_same( 'local', $service->set_ship_mode( 201, array( 'mode' => 'local' ) )['mode'], 'a local pickup without materials is fine too' );

// Switching and clearing.
$result = $service->set_ship_mode( 100, array( 'mode' => 'with_order', 'with_order_id' => 200 ) );
fst_assert_same( array( 'with_order', null, '500.00' ), array( $result['mode'], FST_Supplies::get_order_materials( 100 ), FST_Supplies::get_stock()[1]['on_hand'] ), 'a local pickup that turns out to have gone in a box gives its materials back' );

$result = $service->set_ship_mode( 100, array( 'mode' => 'label' ) );
fst_assert_same(
    array( 'Cleared: no shipment yet.', 'label', null, null, '', '' ),
    array( $result['message'], $result['mode'], $result['with_order_id'], $result['date'], $fst_test_orders[100]->get_meta( '_fst_ship_mode' ), $fst_test_orders[100]->get_meta( '_fst_ship_with_order' ) ),
    'clearing puts the order back to "no shipment yet" and removes the meta'
);
fst_assert_same( array( 101 ), FST_Ship_Mode::get_riders_of( 200 ), 'and it is no longer listed with the box it was in' );

// Tracking added later: the label wins.
$service->set_ship_mode( 102, array( 'mode' => 'local', 'materials' => array( 'lines' => array( array( 'supply_id' => 1, 'qty' => 1 ) ) ) ) );
$service->add_tracking( array( 'order_id' => 102, 'tracking_number' => 'LATER-1', 'carrier' => 'ups', 'line_item_ids' => array( 4 ) ) );
fst_assert_same(
    array( 'label', null, '500.00' ),
    array( FST_Ship_Mode::get( 102 )['mode'], FST_Supplies::get_order_materials( 102 ), FST_Supplies::get_stock()[1]['on_hand'] ),
    'an order that gets tracking after all is no longer a local pickup, and its pickup materials go back'
);
fst_assert_same( true, in_array( 'ShipTracker: tracking was added, so this order is no longer marked as a local pickup.', $fst_test_orders[102]->notes, true ), 'a note says why' );

// The same when the tracking number is saved with the order form (no AJAX).
$fst_test_orders[103] = new WC_Order( 103, array( 9 ) );
$service->set_ship_mode( 103, array( 'mode' => 'local', 'materials' => array( 'lines' => array( array( 'supply_id' => 1, 'qty' => 2 ) ) ) ) );
fst_assert_same( '498.00', FST_Supplies::get_stock()[1]['on_hand'], 'a pickup that used two bags' );
$_POST = array( 'fst_tracking_nonce_field' => 'valid', 'fst_tracking_number' => 'FORM-1', 'fst_carrier' => 'ups' );
$service->save_tracking_data( 103 );
$_POST = array();
fst_assert_same(
    array( 1, 'label', null, '500.00' ),
    array( count( FST_Shipment::get_by_order( 103 ) ), FST_Ship_Mode::get( 103 )['mode'], FST_Supplies::get_order_materials( 103 ), FST_Supplies::get_stock()[1]['on_hand'] ),
    'gets its tracking through the order form: the mark is gone and its bags are counted once, not as a pickup too'
);
fst_assert_same( true, in_array( 'ShipTracker: tracking was added, so this order is no longer marked as a local pickup.', $fst_test_orders[103]->notes, true ), 'with the same note' );

// What the report reads.
$marked = array();
foreach ( FST_Ship_Mode::get_marked_orders() as $entry ) {
    $marked[ $entry['order_id'] ] = $entry['mode'] . ( $entry['host_order_ids'] ? ':' . implode( ',', $entry['host_order_ids'] ) : '' );
}
ksort( $marked );
fst_assert_same( array( 101 => 'with_order:200', 201 => 'local', 301 => 'with_order:300' ), $marked, 'the marked orders are found again for the report' );

$fst_test_orders[500] = new WC_Order( 500, array( 90 ), array( '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '201, 202' ), 'fulfillment' );
fst_assert_same( array( 500, 201, 202 ), FST_Ship_Mode::host_order_ids( $fst_test_orders[500] ), 'a combined box\'s boxes are on its source orders, so those are looked at too' );
fst_assert_same( array( 200 ), FST_Ship_Mode::host_order_ids( $fst_test_orders[200] ), 'a regular order holds its own boxes' );

// The day of a mark. Neither screen sends one when a pickup's materials are
// saved again, and a local pickup is reported under the day it was marked.
$fst_test_orders[600] = new WC_Order( 600, array( 60 ) );
$service->set_ship_mode( 600, array( 'mode' => 'local', 'date' => '2026-11-05', 'materials' => array( 'lines' => array( array( 'supply_id' => 1, 'qty' => 3 ) ) ) ) );

$fst_test_now     = '2026-11-12 16:30:00';
$fst_test_user_id = 9;
$result           = $service->set_ship_mode( 600, array( 'mode' => 'local', 'materials' => array( 'lines' => array( array( 'supply_id' => 1, 'qty' => 4 ) ) ) ) );
fst_assert_same(
    array( '2026-11-05 00:00:00', '4.00', 7 ),
    array( $result['date'], $result['materials']['lines'][0]['qty'], $fst_test_orders[600]->get_meta( '_fst_ship_mode_by' ) ),
    'materials changed a week later, no day sent: still the pickup of the 5th, marked by whoever marked it'
);
fst_assert_same( '2026-11-06 00:00:00', $service->set_ship_mode( 600, array( 'mode' => 'local', 'date' => '2026-11-06' ) )['date'], 'a day that is sent still corrects it' );

$result = $service->set_ship_mode( 600, array( 'mode' => 'with_order', 'with_order_id' => 200 ) );
fst_assert_same( array( '2026-11-12 16:30:00', 9 ), array( $result['date'], $fst_test_orders[600]->get_meta( '_fst_ship_mode_by' ) ), 'another mode is a new mark: made now, by whoever made it' );
$fst_test_now = '2026-11-13 08:00:00';
fst_assert_same( '2026-11-12 16:30:00', $service->set_ship_mode( 600, array( 'mode' => 'with_order', 'with_order_id' => 200 ) )['date'], 'the same box saved again keeps its day' );
fst_assert_same( '2026-11-13 08:00:00', $service->set_ship_mode( 600, array( 'mode' => 'with_order', 'with_order_id' => 102 ) )['date'], 'another order\'s box is a new mark' );

$service->set_ship_mode( 600, array( 'mode' => 'label' ) );
$fst_test_now     = '2026-11-02 09:00:00';
$fst_test_user_id = 7;

// ---------------------------------------------------------------------------
// Shared-cost maths: the box counts the shipping of the order that came along.
// ---------------------------------------------------------------------------
echo "Went in another order's box\n";

// Order 200 shipped in HOST-1 (label 30.00) and paid 20.00. Order 101 came along and paid 12.50.
$report = FST_Label_Cost::build_report(
    array( 'HOST-1' ),
    array( fst_test_row( 200, 'HOST-1', '30.00' ) ),
    fst_test_orders_paid( array( 200 => '20.00', 101 => '12.50' ) ),
    array(),
    array( 'riders' => array( array( 'order_id' => 101, 'host_order_ids' => array( 200 ) ) ) )
);
$box = $report['boxes'][0];
fst_assert_same( array( '32.50', '2.50', true ), array( $box['paid'], $box['difference'], $box['shared_box'] ), 'the box counts both orders\' shipping against its cost: 20.00 + 12.50 − 30.00' );
fst_assert_same( array( array( 200, false ), array( 101, true ) ), array( array( $box['orders'][0]['id'], $box['orders'][0]['rider'] ), array( $box['orders'][1]['id'], $box['orders'][1]['rider'] ) ), 'and lists the order that came along as such' );
fst_assert_same( array( '32.50', '2.50', 1 ), array( $report['summary']['total_paid'], $report['summary']['difference'], $report['in_other_box']['orders'] ), 'the totals follow, and one order is counted as "went in another box"' );

// Exactly like a shared fan-out box: the same numbers as two rows for one tracking number.
$fan_out = FST_Label_Cost::build_report(
    array( 'HOST-1' ),
    array( fst_test_row( 200, 'HOST-1', '30.00' ), fst_test_row( 101, 'HOST-1' ) ),
    fst_test_orders_paid( array( 200 => '20.00', 101 => '12.50' ) )
);
fst_assert_same( array( $fan_out['summary']['total_cost'], $fan_out['summary']['total_paid'], $fan_out['summary']['difference'] ), array( $report['summary']['total_cost'], $report['summary']['total_paid'], $report['summary']['difference'] ), 'the same cost, paid and difference as a fan-out box shared by those two orders' );

// The other order went out in two boxes: the shipping of the one that came along is split like its own.
$report = FST_Label_Cost::build_report(
    array( 'HOST-A', 'HOST-B' ),
    array( fst_test_row( 200, 'HOST-A', '15.00' ), fst_test_row( 200, 'HOST-B', '15.00' ) ),
    fst_test_orders_paid( array( 200 => '20.00', 101 => '12.51' ) ),
    array(),
    array( 'riders' => array( array( 'order_id' => 101, 'host_order_ids' => array( 200 ) ) ) )
);
$boxes = fst_test_boxes_by_tracking( $report );
fst_assert_same( array( '16.26', '16.25' ), array( $boxes['HOST-A']['paid'], $boxes['HOST-B']['paid'] ), 'two boxes: 10.00 + 6.26 and 10.00 + 6.25 — the odd cent goes to the earlier box' );
fst_assert_same( array( '32.51', 1 ), array( $report['summary']['total_paid'], $report['in_other_box']['orders'] ), 'counted once in the total, and as one order' );

// Only one of the two boxes is in the range.
$report = FST_Label_Cost::build_report(
    array( 'HOST-B' ),
    array( fst_test_row( 200, 'HOST-A', '15.00' ), fst_test_row( 200, 'HOST-B', '15.00' ) ),
    fst_test_orders_paid( array( 200 => '20.00', 101 => '12.51' ) ),
    array(),
    array( 'riders' => array( array( 'order_id' => 101, 'host_order_ids' => array( 200 ) ) ) )
);
fst_assert_same( '16.25', $report['summary']['total_paid'], 'a box whose sibling shipped outside the range carries only its own share' );

// A combined box: the order went with fulfillment 500, whose box is on its sources 201 and 202.
$report = FST_Label_Cost::build_report(
    array( 'COMBINED' ),
    array( fst_test_row( 201, 'COMBINED', '40.00' ), fst_test_row( 202, 'COMBINED' ) ),
    fst_test_orders_paid( array( 201 => '15.00', 202 => '15.00', 101 => '5.00' ) ),
    array(),
    array( 'riders' => array( array( 'order_id' => 101, 'host_order_ids' => array( 500, 201, 202 ) ) ) )
);
fst_assert_same( array( '35.00', '-5.00' ), array( $report['summary']['total_paid'], $report['summary']['difference'] ), 'the box of a combined order counts it once: 15 + 15 + 5 against 40' );

// The other order has no box that moved yet, or the order has a row of its own after all.
$report = FST_Label_Cost::build_report(
    array( 'OTHER' ),
    array( fst_test_row( 200, 'HOST-1', '30.00', 'label_created' ), fst_test_row( 102, 'OTHER', '9.00' ), fst_test_row( 300, 'OTHER' ) ),
    fst_test_orders_paid( array( 200 => '20.00', 101 => '12.50', 102 => '10.00', 300 => '4.00' ) ),
    array(),
    array( 'riders' => array(
        array( 'order_id' => 101, 'host_order_ids' => array( 200 ) ), // 200's box has not moved.
        array( 'order_id' => 102, 'host_order_ids' => array( 300 ) ), // 102 has a row of its own.
    ) )
);
fst_assert_same( array( '14.00', 0 ), array( $report['summary']['total_paid'], $report['in_other_box']['orders'] ), 'neither is added to a box: 10.00 + 4.00 is what the box\'s own orders paid' );

// The order that came along paid nothing ("invoiced later"): it adds nothing, and is no "free shipping".
$report = FST_Label_Cost::build_report(
    array( 'HOST-1' ),
    array( fst_test_row( 200, 'HOST-1', '30.00' ) ),
    fst_test_orders_paid( array( 200 => '20.00', 101 => '0.00' ) ),
    array(),
    array( 'riders' => array( array( 'order_id' => 101, 'host_order_ids' => array( 200 ) ) ) )
);
fst_assert_same( array( '20.00', 1, 0 ), array( $report['summary']['total_paid'], $report['in_other_box']['orders'], $report['free_shipping']['orders'] ), 'an order added to a box for free is counted as "in another box", not as free shipping with a label' );

fst_test_finish();
