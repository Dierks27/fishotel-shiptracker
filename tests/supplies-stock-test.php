<?php
/**
 * Plain-PHP test for supplies: the price of one from the latest purchase,
 * and the stock on hand across purchases, usage on boxes, a recount and a
 * removed box.
 *
 * Runs the real FST_Supplies, FST_Order::add_tracking() and
 * FST_Shipment::delete() against an in-memory $wpdb.
 *
 *   php tests/supplies-stock-test.php
 *
 * Exit code 0 = all assertions passed, 1 = at least one failed.
 *
 * @package FisHotel_ShipTracker
 */

require __DIR__ . '/bootstrap.php';

$wpdb = new FST_Test_WPDB();

/**
 * A purchase row as the database returns it.
 */
function fst_test_purchase( $id, $date, $qty, $paid, $supply_id = 1 ) {
    return (object) array(
        'id'            => $id,
        'supply_id'     => $supply_id,
        'purchase_date' => $date,
        'qty'           => $qty,
        'total_paid'    => $paid,
        'note'          => '',
    );
}

/**
 * On hand (as shown) of one supply.
 */
function fst_test_on_hand( $supply_id ) {
    foreach ( FST_Supplies::get_supplies() as $supply ) {
        if ( $supply['id'] === $supply_id ) {
            return $supply['on_hand'];
        }
    }
    return 'no such supply';
}

/**
 * The row stored last in a table (the fake numbers rows across all tables).
 */
function fst_test_last_row( $table ) {
    global $wpdb;
    return $wpdb->tables[ $table ][ count( $wpdb->tables[ $table ] ) - 1 ];
}

// ---------------------------------------------------------------------------
// Quantities.
// ---------------------------------------------------------------------------
echo "Quantities\n";

fst_assert_same( 600, FST_Supplies::parse_qty( '6' ), '6 is 6.00' );
fst_assert_same( 250, FST_Supplies::parse_qty( 2.5 ), 'a JSON number 2.5 is 2.50' );
fst_assert_same( 50, FST_Supplies::parse_qty( '.5' ), '.5 is 0.50' );
fst_assert_same( 0, FST_Supplies::parse_qty( '0' ), '0 is a quantity' );
fst_assert_same( null, FST_Supplies::parse_qty( '  ' ), 'blank is "not given"' );
fst_assert_same( false, FST_Supplies::parse_qty( '-1' ), 'a negative quantity is refused' );
fst_assert_same( false, FST_Supplies::parse_qty( '1.005' ), 'more than two decimals is refused, not rounded' );
fst_assert_same( false, FST_Supplies::parse_qty( 1.005 ), 'also as a JSON number' );
fst_assert_same( false, FST_Supplies::parse_qty( 'two' ), 'a word is refused' );
fst_assert_same( false, FST_Supplies::parse_qty( array( 2 ) ), 'a list is refused' );
fst_assert_same( '-2.50', FST_Supplies::format_qty( -250 ), 'a negative balance prints with its sign' );

// ---------------------------------------------------------------------------
// Price each: total paid / how many, of the LATEST purchase.
// ---------------------------------------------------------------------------
echo "Price each\n";

fst_assert_same( '0.6000', FST_Supplies::price_each( fst_test_purchase( 1, '2026-10-01', '40.00', '24.00' ) ), '24.00 for a case of 40 is 0.60 each' );
fst_assert_same( '0.1319', FST_Supplies::price_each( fst_test_purchase( 1, '2026-10-01', '144.00', '18.99' ) ), '18.99 for 144 keeps four decimals (0.1319), not 0.13' );
fst_assert_same( '0.0000', FST_Supplies::price_each( fst_test_purchase( 1, '2026-10-01', '10.00', '0.00' ) ), 'a free case is 0 each' );
fst_assert_same( null, FST_Supplies::price_each( null ), 'no purchase, no price' );

$purchases = array(
    fst_test_purchase( 1, '2026-09-01', '40.00', '20.00' ),
    fst_test_purchase( 2, '2026-11-01', '40.00', '28.00' ),
    fst_test_purchase( 3, '2026-10-01', '40.00', '24.00' ), // Logged last, bought earlier.
);
fst_assert_same( 2, FST_Supplies::latest_purchase( $purchases )->id, 'the latest purchase is the latest DATE, not the last one logged' );

$purchases[] = fst_test_purchase( 4, '2026-11-01', '80.00', '48.00' );
fst_assert_same( 4, FST_Supplies::latest_purchase( $purchases )->id, 'two purchases on one day: the later id wins' );
fst_assert_same( '0.6000', FST_Supplies::price_each( FST_Supplies::latest_purchase( $purchases ) ), 'and its price is the price each' );
fst_assert_same( null, FST_Supplies::latest_purchase( array() ), 'nothing bought yet' );

$latest = FST_Supplies::latest_by_supply( array(
    fst_test_purchase( 1, '2026-09-01', '40.00', '20.00', 1 ),
    fst_test_purchase( 2, '2026-09-02', '500.00', '55.00', 2 ),
    fst_test_purchase( 3, '2026-09-03', '40.00', '26.00', 1 ),
) );
fst_assert_same( array( 1 => 3, 2 => 2 ), array( 1 => $latest[1]->id, 2 => $latest[2]->id ), 'each supply has its own latest purchase' );

// ---------------------------------------------------------------------------
// The purchase log.
// ---------------------------------------------------------------------------
echo "Purchase log\n";

$fst_test_options['fst_standard_boxes'] = array(
    array( 'name' => 'Small', 'length' => '10', 'width' => '10', 'height' => '10', 'foam_cost' => '1.80', 'paper_cost' => '0.40', 'ice_packs' => '2' ),
);
$fst_test_options['fst_supplies']       = array(
    array( 'id' => 1, 'name' => 'Heat pack', 'unit' => 'each', 'counted' => true, 'reorder_level' => '10', 'role' => 'heat_pack' ),
    array( 'id' => 2, 'name' => 'Fish bag', 'unit' => 'each', 'counted' => true, 'reorder_level' => '100', 'role' => 'fish_bag' ),
    array( 'id' => 3, 'name' => 'Small box', 'unit' => 'each', 'counted' => true, 'role' => 'box:Small' ),
    array( 'id' => 4, 'name' => 'Tape', 'unit' => 'rolls', 'counted' => false, 'reorder_level' => '5' ),
);
$fst_test_options['fst_supply_last_id'] = 4;

fst_assert_same( array(), FST_Supplies::get_prices(), 'no purchase logged: no supply has a price' );
fst_assert_same( '0.00', fst_test_on_hand( 1 ), 'and a counted supply has none on hand' );
fst_assert_same( null, fst_test_on_hand( 4 ), 'a supply that is not counted has no "on hand" at all' );

$first = FST_Supplies::add_purchase( array( 'supply_id' => 1, 'purchase_date' => '2026-10-01', 'qty' => '40', 'total_paid' => '$24.00', 'note' => ' Hardware store ' ) );
fst_assert_same( 1, $first, 'a purchase is logged' );
fst_assert_same(
    array( 'supply_id' => 1, 'purchase_date' => '2026-10-01', 'qty' => '40.00', 'total_paid' => '24.00', 'note' => 'Hardware store' ),
    array_intersect_key( $wpdb->row( 'wp_fst_supply_purchases', 1 ), array_flip( array( 'supply_id', 'purchase_date', 'qty', 'total_paid', 'note' ) ) ),
    'with its date, how many, the total paid and where it was bought'
);
FST_Supplies::add_purchase( array( 'supply' => 'fish bag', 'purchase_date' => '2026-10-02', 'qty' => '500', 'total_paid' => '55' ) );
FST_Supplies::add_purchase( array( 'supply_id' => 3, 'purchase_date' => '2026-10-02', 'qty' => '25', 'total_paid' => '31.25' ) );
fst_assert_same( array( 1 => '0.6000', 2 => '0.1100', 3 => '1.2500' ), FST_Supplies::get_prices(), 'each supply is priced from its own latest purchase (a supply may be named instead of numbered)' );

fst_assert_same( '2026-11-02', $wpdb->row( 'wp_fst_supply_purchases', FST_Supplies::add_purchase( array( 'supply_id' => 4, 'qty' => '6', 'total_paid' => '18' ) ) )['purchase_date'], 'no date given means today' );

fst_assert_error( FST_Supplies::add_purchase( array( 'supply_id' => 99, 'qty' => '1', 'total_paid' => '1' ) ), 'fst_unknown_supply', 'a purchase of an unknown supply is refused' );
fst_assert_error( FST_Supplies::add_purchase( array( 'supply_id' => 1, 'qty' => '0', 'total_paid' => '1' ) ), 'fst_invalid_purchase', 'how many must be above 0' );
fst_assert_error( FST_Supplies::add_purchase( array( 'supply_id' => 1, 'qty' => '1.999', 'total_paid' => '1' ) ), 'fst_invalid_purchase', 'how many: at most two decimals' );
fst_assert_error( FST_Supplies::add_purchase( array( 'supply_id' => 1, 'qty' => '40', 'total_paid' => '-5' ) ), 'fst_invalid_purchase', 'the total paid cannot be negative' );
fst_assert_error( FST_Supplies::add_purchase( array( 'supply_id' => 1, 'qty' => '40', 'total_paid' => '' ) ), 'fst_invalid_purchase', 'the total paid is required (0 is allowed, empty is not)' );
fst_assert_error( FST_Supplies::add_purchase( array( 'supply_id' => 1, 'qty' => '40', 'total_paid' => '24', 'purchase_date' => '2026-02-30' ) ), 'fst_invalid_purchase', 'the date must exist' );
fst_assert_same( 4, count( FST_Supplies::get_purchases() ), 'none of the refused purchases was stored' );

fst_assert_same( true, FST_Supplies::update_purchase( 1, array( 'supply_id' => 1, 'purchase_date' => '2026-10-01', 'qty' => '40', 'total_paid' => '26.00' ) ), 'a purchase can be corrected' );
fst_assert_same( '0.6500', FST_Supplies::get_prices()[1], 'and the price each follows' );
FST_Supplies::update_purchase( 1, array( 'supply_id' => 1, 'purchase_date' => '2026-10-01', 'qty' => '40', 'total_paid' => '24.00' ) );
fst_assert_error( FST_Supplies::update_purchase( 77, array( 'supply_id' => 1, 'qty' => '1', 'total_paid' => '1' ) ), 'fst_purchase_not_found', 'a purchase that is gone cannot be corrected' );

// ---------------------------------------------------------------------------
// On hand = bought − used ± recounts.
// ---------------------------------------------------------------------------
echo "On hand\n";

$fst_test_orders[100] = new WC_Order( 100, array( 1, 2 ), array( '_fh_weather_action' => 'heat_pack' ) );
$fst_test_orders[101] = new WC_Order( 101, array( 3 ), array( '_fh_weather_action' => 'heat_pack' ) );
$fst_test_orders[100]->quantities = array( 1 => 4, 2 => 2 );

$service = new FST_Order();

fst_assert_same( '40.00', fst_test_on_hand( 1 ), '40 heat packs bought, none used' );

// Box A: Small, order 100 (6 fish, heat pack weather) — the defaults are recorded.
$box_a = $service->add_tracking( array( 'order_id' => 100, 'tracking_number' => 'BOX-A', 'carrier' => 'ups', 'line_item_ids' => array( 1, 2 ), 'box_dims' => 'Small', 'label_cost' => '18.40' ) );
fst_assert_same(
    array( '39.00', '494.00', '24.00' ),
    array( fst_test_on_hand( 1 ), fst_test_on_hand( 2 ), fst_test_on_hand( 3 ) ),
    'a box takes its supplies off the shelf: 1 heat pack, 6 fish bags, 1 box'
);

// Box B: explicit materials.
$box_b = $service->add_tracking( array(
    'order_id'        => 101,
    'tracking_number' => 'BOX-B',
    'carrier'         => 'ups',
    'line_item_ids'   => array( 3 ),
    'materials'       => array( 'lines' => array( array( 'supply_id' => 1, 'qty' => 2 ), array( 'supply' => 'Fish bag', 'qty' => '1' ) ) ),
) );
fst_assert_same( array( '37.00', '493.00' ), array( fst_test_on_hand( 1 ), fst_test_on_hand( 2 ) ), 'a second box takes its own' );

// A second purchase adds to the shelf.
FST_Supplies::add_purchase( array( 'supply_id' => 1, 'purchase_date' => '2026-10-20', 'qty' => '40', 'total_paid' => '28.00' ) );
fst_assert_same( '77.00', fst_test_on_hand( 1 ), 'on hand runs across purchases: 40 + 40 − 3' );

// Changing a box gives back what it held and takes the new amounts.
$service->set_label_cost( $box_b['shipment_id'], array( 'materials' => array( 'lines' => array( array( 'supply_id' => 1, 'qty' => 1 ) ) ) ) );
fst_assert_same( array( '78.00', '494.00' ), array( fst_test_on_hand( 1 ), fst_test_on_hand( 2 ) ), 'editing a box to 1 heat pack and no bag puts the difference back' );

// The sums are one query each, however many boxes there are.
$wpdb->queries = array();
FST_Supplies::get_stock();
$sum_queries = 0;
foreach ( $wpdb->queries as $query ) {
    if ( false !== strpos( $query, 'wp_fst_supply_usage' ) ) {
        $sum_queries++;
    }
}
fst_assert_same( array( 3, 1 ), array( count( $wpdb->queries ), $sum_queries ), 'the stock is three sums — one query for everything used' );

// Recount: the shelf says 70, the books say 78.
$fst_test_now = '2026-11-05 08:30:00';
$recount      = FST_Supplies::recount( 1, '70' );
fst_assert_same( array( '70.00', false ), array( $recount['on_hand'], $recount['recount_needed'] ), 'a recount sets the real number' );
$stored = fst_test_last_row( 'wp_fst_supply_counts' );
fst_assert_same(
    array( 'supply_id' => 1, 'counted' => '70.00', 'delta' => '-8.00', 'counted_at' => '2026-11-05 08:30:00', 'user_id' => 7 ),
    array_intersect_key( $stored, array_flip( array( 'supply_id', 'counted', 'delta', 'counted_at', 'user_id' ) ) ),
    'and stores the difference with the date and who counted'
);
fst_assert_same( '-8.00', FST_Supplies::get_stock()[1]['adjusted'], 'the correction is part of the books from then on' );

$box_c = $service->add_tracking( array( 'order_id' => 101, 'tracking_number' => 'BOX-C', 'carrier' => 'ups', 'line_item_ids' => array( 3 ), 'materials' => '{"lines":[{"supply_id":1,"qty":1}]}' ) );
fst_assert_same( '69.00', fst_test_on_hand( 1 ), 'usage after the recount counts down from the recounted number' );

FST_Supplies::recount( 1, '72.5' );
fst_assert_same( array( '72.50', '3.50' ), array( fst_test_on_hand( 1 ), fst_test_last_row( 'wp_fst_supply_counts' )['delta'] ), 'a recount upwards stores a positive difference' );
FST_Supplies::recount( 1, '69' );

fst_assert_error( FST_Supplies::recount( 1, '-3' ), 'fst_invalid_recount', 'a recount below zero is refused' );
fst_assert_error( FST_Supplies::recount( 1, '' ), 'fst_invalid_recount', 'a recount needs a number' );
fst_assert_error( FST_Supplies::recount( 4, '3' ), 'fst_unknown_supply', 'a supply that is not counted cannot be recounted' );
fst_assert_error( FST_Supplies::recount( 99, '3' ), 'fst_unknown_supply', 'nor can one that does not exist' );

// ---------------------------------------------------------------------------
// Removing a shipment's box gives its supplies back.
// ---------------------------------------------------------------------------
echo "A removed box\n";

FST_Shipment::delete( $box_c['shipment_id'] );
fst_assert_same( '70.00', fst_test_on_hand( 1 ), 'removing the shipment puts its heat pack back' );
fst_assert_same( null, FST_Supplies::get_box_materials( 'BOX-C' ), 'and the box has no materials any more' );

FST_Shipment::delete( $box_a['shipment_id'] );
fst_assert_same(
    array( '71.00', '500.00', '25.00' ),
    array( fst_test_on_hand( 1 ), fst_test_on_hand( 2 ), fst_test_on_hand( 3 ) ),
    'removing box A returns its heat pack, its 6 bags and its box'
);

// A shared box: the box exists until its last row is removed.
$fst_test_orders[201] = new WC_Order( 201, array( 11 ) );
$fst_test_orders[202] = new WC_Order( 202, array( 21 ) );
$fst_test_orders[500] = new WC_Order( 500, array( 90 ), array( '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '201,202' ), 'fulfillment' );

$shared = $service->add_tracking( array( 'order_id' => 500, 'tracking_number' => 'BOX-S', 'carrier' => 'ups', 'box_dims' => '10x10x10' ) );
fst_assert_same( 2, count( $shared['shipment_ids'] ), 'one box, two source orders, two rows' );
fst_assert_same( array( '498.00', '24.00' ), array( fst_test_on_hand( 2 ), fst_test_on_hand( 3 ) ), 'but its supplies are taken once: 2 bags, 1 box' );

FST_Shipment::delete( $shared['shipment_ids'][0] );
fst_assert_same( array( '498.00', '24.00' ), array( fst_test_on_hand( 2 ), fst_test_on_hand( 3 ) ), 'one order leaves the box: the box still exists and keeps its supplies' );
FST_Shipment::delete( $shared['shipment_ids'][1] );
fst_assert_same( array( '500.00', '25.00' ), array( fst_test_on_hand( 2 ), fst_test_on_hand( 3 ) ), 'the last row goes: the supplies come back' );

// ---------------------------------------------------------------------------
// Never negative on screen.
// ---------------------------------------------------------------------------
echo "Below zero\n";

$service->set_label_cost( $box_b['shipment_id'], array( 'materials' => array( 'lines' => array( array( 'supply_id' => 3, 'qty' => 30 ) ) ) ) );
$level = FST_Supplies::get_stock()[3];
fst_assert_same(
    array( 'balance' => '-5.00', 'on_hand' => '0.00', 'recount_needed' => true ),
    array_intersect_key( $level, array_flip( array( 'balance', 'on_hand', 'recount_needed' ) ) ),
    '30 boxes used of 25 bought: the books say −5, the screen says 0 and asks for a recount'
);
$shown = null;
foreach ( FST_Supplies::get_supplies() as $supply ) {
    if ( 3 === $supply['id'] ) {
        $shown = array( $supply['on_hand'], $supply['recount_needed'] );
    }
}
fst_assert_same( array( '0.00', true ), $shown, 'the supplies list shows 0 with "recount needed"' );

$after = FST_Supplies::recount( 3, '4' );
fst_assert_same( array( '4.00', false, '9.00' ), array( $after['on_hand'], $after['recount_needed'], $after['adjusted'] ), 'the recount clears it: 4 on hand, a correction of +9' );
$service->set_label_cost( $box_b['shipment_id'], array( 'materials' => array( 'lines' => array( array( 'supply_id' => 1, 'qty' => 1 ) ) ) ) );

// ---------------------------------------------------------------------------
// Reorder notice.
// ---------------------------------------------------------------------------
echo "Reorder\n";

fst_assert_same( array(), FST_Supplies::get_low_supplies(), 'nothing is at its reorder level (heat packs 70 > 10, bags 500 > 100; boxes have no level)' );

FST_Supplies::recount( 1, '10' );
$low = FST_Supplies::get_low_supplies();
fst_assert_same(
    array( array( 'id' => 1, 'name' => 'Heat pack', 'unit' => 'each', 'on_hand' => '10.00', 'reorder_level' => '10.00', 'recount_needed' => false ) ),
    $low,
    'AT the level counts as time to reorder, and the notice carries how many are on hand'
);
FST_Supplies::recount( 1, '11' );
fst_assert_same( array(), FST_Supplies::get_low_supplies(), 'one above the level is not' );

FST_Supplies::recount( 2, '0' );
$low = FST_Supplies::get_low_supplies();
fst_assert_same( array( 2 ), array_column( $low, 'id' ), 'a supply that ran out is listed; tape (not counted down) is not, although a level was typed for it' );
fst_assert_same( $low, FST_Supplies::filter_low_supplies( array() ), 'the fst_low_supplies filter callback returns the same list for other plugins' );

// Tape is not counted down: it is never on the notice, whatever was typed as its level.
$fst_test_options['fst_supplies'][3]['counted']       = true;
$fst_test_options['fst_supplies'][3]['reorder_level'] = '6';
$low_ids = array();
foreach ( FST_Supplies::get_low_supplies() as $supply ) {
    $low_ids[] = $supply['id'];
}
fst_assert_same( array( 2, 4 ), $low_ids, 'once tape is counted down, its 6 rolls at a level of 6 are listed as well' );

fst_test_finish();
