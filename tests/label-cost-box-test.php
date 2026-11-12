<?php
/**
 * Plain-PHP test for "one box, one cost".
 *
 * Runs the real FST_Order::add_tracking() fan-out, FST_Shipment::delete()
 * and the box helpers against an in-memory $wpdb: however many rows share a
 * tracking number, exactly one holds the cost fields, and the cost survives
 * the removal of the row that held it.
 *
 *   php tests/label-cost-box-test.php
 *
 * Exit code 0 = all assertions passed, 1 = at least one failed.
 *
 * @package FisHotel_ShipTracker
 */

require __DIR__ . '/bootstrap.php';

$wpdb  = new FST_Test_WPDB();
$table = 'wp_fst_shipments';

/**
 * The rows of a tracking number that hold any cost field, as stored.
 */
function fst_test_cost_rows( $tracking_number ) {
    global $wpdb, $table;
    $rows = array();
    foreach ( $wpdb->tables[ $table ] as $row ) {
        if ( 0 !== strcasecmp( $row['tracking_number'], $tracking_number ) ) {
            continue;
        }
        foreach ( FST_Label_Cost::FIELDS as $field ) {
            if ( null !== $row[ $field ] ) {
                $rows[] = $row;
                break;
            }
        }
    }
    return $rows;
}

/**
 * What a careless report would do: add up label_cost over every stored row.
 */
function fst_test_raw_cost_sum( $tracking_number ) {
    global $wpdb, $table;
    $sum = 0;
    foreach ( $wpdb->tables[ $table ] as $row ) {
        if ( 0 === strcasecmp( $row['tracking_number'], $tracking_number ) && null !== $row['label_cost'] ) {
            $sum += (float) $row['label_cost'];
        }
    }
    return number_format( $sum, 2, '.', '' );
}

$fst_test_options['fst_standard_boxes'] = array(
    array( 'name' => 'Large cube', 'length' => '16', 'width' => '16', 'height' => '16' ),
);
$fst_test_options['fst_label_sources']  = array( 'Partner A', 'Partner B' );

// Three source orders packed into one box by fulfillment order 500.
$fst_test_orders[201] = new WC_Order( 201, array( 11, 12 ) );
$fst_test_orders[202] = new WC_Order( 202, array( 21 ) );
$fst_test_orders[203] = new WC_Order( 203, array( 31 ) );
$fst_test_orders[500] = new WC_Order(
    500,
    array( 90 ),
    array(
        '_fishotel_is_fulfillment'      => '1',
        '_fishotel_fulfillment_sources' => '201,202,203',
    ),
    'fulfillment'
);
// Regular orders.
$fst_test_orders[100] = new WC_Order( 100, array( 1, 2 ) );
$fst_test_orders[101] = new WC_Order( 101, array( 3 ) );
$fst_test_orders[102] = new WC_Order( 102, array( 4 ) );

$service = new FST_Order();

$shared = '1Z50W72W0115498880';

// ---------------------------------------------------------------------------
// Fan-out: three rows, one cost.
// ---------------------------------------------------------------------------
echo "Fan-out stores the cost once\n";

$result = $service->add_tracking( array(
    'order_id'        => 500,
    'tracking_number' => $shared,
    'carrier'         => 'ups',
    'label_cost'      => '$42.50',
    'box_weight'      => '20 lbs',
    'box_dims'        => 'Large cube',
    'label_source'    => 'partner a',
) );

fst_assert_same( false, is_wp_error( $result ), 'the add succeeds' );
fst_assert_same( 3, count( $result['shipment_ids'] ), 'one row per source order' );

$first_id = $result['shipment_ids'][0];
$holders  = fst_test_cost_rows( $shared );
fst_assert_same( 1, count( $holders ), 'exactly one of the three rows holds cost data' );
fst_assert_same( $first_id, $holders[0]['id'], 'it is the first row the call created' );
fst_assert_same(
    array( '42.50', '20.00', '16x16x16', 'Partner A' ),
    array( $holders[0]['label_cost'], $holders[0]['box_weight'], $holders[0]['box_dims'], $holders[0]['label_source'] ),
    'the stored values are the normalised ones (box name stored as its size)'
);
fst_assert_same( '42.50', fst_test_raw_cost_sum( $shared ), 'even a plain SUM over the rows gives 42.50, not 127.50' );

foreach ( array_slice( $result['shipment_ids'], 1 ) as $sibling_id ) {
    $sibling = $wpdb->row( $table, $sibling_id );
    fst_assert_same(
        array( null, null, null, null ),
        array( $sibling['label_cost'], $sibling['box_weight'], $sibling['box_dims'], $sibling['label_source'] ),
        "sibling row {$sibling_id} stays NULL"
    );
}

$box = FST_Shipment::get_box_cost( $shared );
fst_assert_same( '42.50', $box['label_cost'], 'get_box_cost(): the cost of the tracking number' );
fst_assert_same( true, $box['shared_box'], 'get_box_cost(): the box is shared' );
fst_assert_same( array( 201, 202, 203 ), $box['order_ids'], 'get_box_cost(): the orders in the box' );
fst_assert_same( $first_id, $box['cost_shipment_id'], 'get_box_cost(): which row holds the fields' );

foreach ( $result['shipments'] as $summary ) {
    fst_assert_same(
        array( '42.50', '20.00', '16x16x16', 'Partner A', true ),
        array( $summary['label_cost'], $summary['box_weight'], $summary['box_dims'], $summary['label_source'], $summary['shared_box'] ),
        "shipment {$summary['id']} (order {$summary['order_id']}) reports the box's cost and shared_box"
    );
}

// ---------------------------------------------------------------------------
// Boxes of an order.
// ---------------------------------------------------------------------------
echo "Boxes of an order\n";

$boxes = FST_Shipment::get_order_boxes( 202 );
fst_assert_same( 1, count( $boxes ), 'order 202 went out in one box' );
fst_assert_same(
    array( $shared, '42.50', '16x16x16', 'Partner A', true ),
    array( $boxes[0]['tracking_number'], $boxes[0]['label_cost'], $boxes[0]['box_dims'], $boxes[0]['label_source'], $boxes[0]['shared_box'] ),
    'the box carries cost, size, source and shared_box although row 202 itself holds NULL'
);
fst_assert_same( $result['shipment_ids'][1], $boxes[0]['shipment_id'], 'shipment_id is order 202\'s own row for the box' );

$boxes = FST_Shipment::get_order_boxes( array( 201, 202, 203 ) );
fst_assert_same( 1, count( $boxes ), 'the three source orders together still have one box' );
fst_assert_same( array(), FST_Shipment::get_order_boxes( 999 ), 'an order without shipments has no boxes' );

// ---------------------------------------------------------------------------
// Updating: acts on the box, whichever row is named.
// ---------------------------------------------------------------------------
echo "Update acts on the box\n";

$fst_test_now   = '2026-11-05 15:30:00';
$updated_before = $wpdb->row( $table, $first_id )['updated_at'];

$sibling_id = $result['shipment_ids'][2];
$update     = $service->set_label_cost( $sibling_id, array( 'label_cost' => '44' ) );
fst_assert_same( '44.00', $update['label_cost'], 'set_label_cost() through a sibling row changes the box cost' );
fst_assert_same( '16x16x16', $update['box_dims'], 'a field that was not sent is left alone' );
fst_assert_same( true, $update['shared_box'], 'the result says the box is shared' );

$holders = fst_test_cost_rows( $shared );
fst_assert_same( 1, count( $holders ), 'still exactly one row holds cost data' );
fst_assert_same( $first_id, $holders[0]['id'], 'the holder did not move to the row that was named' );
fst_assert_same( '44.00', fst_test_raw_cost_sum( $shared ), 'the row sum follows the box cost' );
fst_assert_same( $updated_before, $wpdb->row( $table, $first_id )['updated_at'], 'updated_at is not touched by a cost change' );

$update = $service->set_label_cost( $first_id, array( 'label_source' => '', 'box_weight' => '' ) );
fst_assert_same(
    array( '44.00', null, '16x16x16', null ),
    array( $update['label_cost'], $update['box_weight'], $update['box_dims'], $update['label_source'] ),
    'an empty value clears that field only'
);

fst_assert_error( $service->set_label_cost( $first_id, array() ), 'fst_no_cost_fields', 'no field sent is an error, nothing changes' );
fst_assert_error( $service->set_label_cost( $first_id, array( 'label_cost' => '-1' ) ), 'fst_invalid_label_cost', 'an invalid value is refused' );
fst_assert_error( $service->set_label_cost( 987654, array( 'label_cost' => '1' ) ), 'fst_shipment_not_found', 'unknown shipment id' );
fst_assert_same( '44.00', FST_Shipment::get_box_cost( $shared )['label_cost'], 'the refused updates changed nothing' );

$service->set_label_cost( $first_id, array( 'label_source' => 'Partner A', 'box_weight' => '20' ) );

// ---------------------------------------------------------------------------
// Removing rows: the cost stays with the box.
// ---------------------------------------------------------------------------
echo "Removing the row that holds the cost\n";

$survivor_id = $result['shipment_ids'][1];
fst_assert_same( true, FST_Shipment::delete( $first_id ), 'the holding row is deleted' );

$holders = fst_test_cost_rows( $shared );
fst_assert_same( 1, count( $holders ), 'one surviving row holds the cost data' );
fst_assert_same( $survivor_id, $holders[0]['id'], 'it moved to the oldest surviving row' );
fst_assert_same(
    array( '44.00', '20.00', '16x16x16', 'Partner A' ),
    array( $holders[0]['label_cost'], $holders[0]['box_weight'], $holders[0]['box_dims'], $holders[0]['label_source'] ),
    'all four fields moved'
);
fst_assert_same( '44.00', fst_test_raw_cost_sum( $shared ), 'the box still costs 44.00 once' );
fst_assert_same( array( 202, 203 ), FST_Shipment::get_box_cost( $shared )['order_ids'], 'the box now holds two orders' );

FST_Shipment::delete( $result['shipment_ids'][2] );
$holders = fst_test_cost_rows( $shared );
fst_assert_same( array( $survivor_id ), array( $holders[0]['id'] ), 'removing a row that held nothing moves nothing' );
fst_assert_same( false, FST_Shipment::get_box_cost( $shared )['shared_box'], 'one order left: no longer a shared box' );

FST_Shipment::delete( $survivor_id );
fst_assert_same( null, FST_Shipment::get_box_cost( $shared ), 'the last row gone: the tracking number has no box' );

// ---------------------------------------------------------------------------
// The same tracking number arriving in separate calls.
// ---------------------------------------------------------------------------
echo "Same box added in two calls\n";

$two_calls = '1Z50W72W0115498881';

$first  = $service->add_tracking( array( 'order_id' => 100, 'tracking_number' => $two_calls, 'line_item_ids' => array( 1, 2 ), 'label_cost' => '30' ) );
$second = $service->add_tracking( array( 'order_id' => 101, 'tracking_number' => $two_calls, 'line_item_ids' => array( 3 ), 'label_cost' => '31.25', 'box_dims' => '12x12x12' ) );

$holders = fst_test_cost_rows( $two_calls );
fst_assert_same( 1, count( $holders ), 'a second call for the same box does not create a second cost' );
fst_assert_same( $first['shipment_id'], $holders[0]['id'], 'the row that already held the cost keeps holding it' );
fst_assert_same( array( '31.25', '12x12x12' ), array( $holders[0]['label_cost'], $holders[0]['box_dims'] ), 'the later values replace / complete the box' );
fst_assert_same( '31.25', fst_test_raw_cost_sum( $two_calls ), 'row sum is 31.25, not 61.25' );
fst_assert_same( true, $second['shipments'][0]['shared_box'], 'the second order sees a shared box' );

$third = $service->add_tracking( array( 'order_id' => 102, 'tracking_number' => $two_calls, 'line_item_ids' => array( 4 ) ) );
fst_assert_same( '31.25', $third['shipments'][0]['label_cost'], 'adding the box to another order without cost fields keeps the cost' );

$no_cost_yet = '1Z50W72W0115498882';
$first       = $service->add_tracking( array( 'order_id' => 100, 'tracking_number' => $no_cost_yet, 'line_item_ids' => array( 1 ) ) );
$second      = $service->add_tracking( array( 'order_id' => 101, 'tracking_number' => $no_cost_yet, 'line_item_ids' => array( 3 ), 'label_cost' => '9.99' ) );
$holders     = fst_test_cost_rows( $no_cost_yet );
fst_assert_same( array( $second['shipment_id'] ), array( $holders[0]['id'] ), 'with no holder yet, the cost goes on the first row created by the call that sent it' );

$duplicate = $service->add_tracking( array( 'order_id' => 101, 'tracking_number' => $no_cost_yet, 'line_item_ids' => array( 3 ), 'label_cost' => '500' ) );
fst_assert_error( $duplicate, 'fst_duplicate_tracking', 'the same number on the same order is still refused' );
fst_assert_same( '9.99', FST_Shipment::get_box_cost( $no_cost_yet )['label_cost'], 'a refused add writes no cost' );

// ---------------------------------------------------------------------------
// NULL is "not recorded"; 0 is a cost.
// ---------------------------------------------------------------------------
echo "NULL vs 0\n";

$plain  = '1Z50W72W0115498883';
$result = $service->add_tracking( array( 'order_id' => 102, 'tracking_number' => $plain, 'line_item_ids' => array( 4 ) ) );
$stored = $wpdb->row( $table, $result['shipment_id'] );
fst_assert_same(
    array( null, null, null, null ),
    array( $stored['label_cost'], $stored['box_weight'], $stored['box_dims'], $stored['label_source'] ),
    'no cost fields sent: the columns stay NULL, never 0'
);
fst_assert_same( 0, FST_Shipment::get_box_cost( $plain )['cost_shipment_id'], 'no row holds cost data' );
fst_assert_same( null, $result['shipments'][0]['label_cost'], 'the summary reports NULL' );

$bad = $service->add_tracking( array( 'order_id' => 100, 'tracking_number' => '1Z50W72W0115498884', 'line_item_ids' => array( 2 ), 'label_cost' => 'twelve' ) );
fst_assert_error( $bad, 'fst_invalid_label_cost', 'an invalid cost stops the add' );
fst_assert_same( null, FST_Shipment::get_box_cost( '1Z50W72W0115498884' ), 'and no shipment row was created' );

$free   = '1Z50W72W0115498885';
$result = $service->add_tracking( array( 'order_id' => 100, 'tracking_number' => $free, 'line_item_ids' => array( 2 ), 'label_cost' => 0 ) );
fst_assert_same( '0.00', $wpdb->row( $table, $result['shipment_id'] )['label_cost'], 'a cost of 0 is stored as 0.00' );
fst_assert_same( $result['shipment_id'], FST_Shipment::get_box_cost( $free )['cost_shipment_id'], 'and counts as recorded' );

fst_assert_same( true, FST_Shipment::clear_box_cost( $free ), 'clear_box_cost() succeeds' );
fst_assert_same( array(), fst_test_cost_rows( $free ), 'after clearing, no row of the box holds cost data' );
fst_assert_same( false, FST_Shipment::set_box_cost( 'NO-SUCH-NUMBER', array( 'label_cost' => '1.00' ) ), 'set_box_cost() on an unknown tracking number is false' );

// ---------------------------------------------------------------------------
// A box whose rows were filled in twice by hand still reads as one cost.
// ---------------------------------------------------------------------------
echo "Two rows holding a cost (data edited outside the plugin)\n";

$wpdb->insert( $table, array( 'order_id' => 301, 'tracking_number' => 'HAND-EDITED', 'label_cost' => '15.00' ) );
$wpdb->insert( $table, array( 'order_id' => 302, 'tracking_number' => 'hand-edited', 'label_cost' => '15.00', 'box_dims' => '8x8x8' ) );

$box = FST_Shipment::get_box_cost( 'HAND-EDITED' );
fst_assert_same( '15.00', $box['label_cost'], 'the box cost is read from one row (the oldest holder), not summed' );
fst_assert_same( null, $box['box_dims'], 'all four fields come from that same row' );

FST_Shipment::set_box_cost( 'HAND-EDITED', array( 'box_dims' => '8x8x8' ) );
fst_assert_same( 1, count( fst_test_cost_rows( 'HAND-EDITED' ) ), 'the next save puts the box back on a single row' );
fst_assert_same( '15.00', fst_test_raw_cost_sum( 'HAND-EDITED' ), 'and the row sum is the box cost again' );

fst_test_finish();
