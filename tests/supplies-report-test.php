<?php
/**
 * Plain-PHP test for the reports once a box costs its label plus its
 * materials: the split adding up to the totals (overall, by box size, by
 * label source), boxes without a label cost, the free shipping and local
 * pickup lines, and the supplies report.
 *
 *   php tests/supplies-report-test.php
 *
 * Exit code 0 = all assertions passed, 1 = at least one failed.
 *
 * @package FisHotel_ShipTracker
 */

require __DIR__ . '/bootstrap.php';

$fst_test_row_id = 0;

/**
 * A wp_fst_shipments row as the report query returns it.
 */
function fst_test_row( $order_id, $tracking_number, $cost = null, $dims = null, $source = null, $status = 'delivered' ) {
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
        'box_dims'        => $dims,
        'label_source'    => $source,
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
 * Add up the parts of a split (everything but its own total), in cents.
 */
function fst_test_split_sum( array $split ) {
    $cents = 0;
    foreach ( $split as $part => $amount ) {
        if ( 'total' !== $part ) {
            $cents += (int) round( (float) $amount * 100 );
        }
    }
    return number_format( $cents / 100, 2, '.', '' );
}

$supplies = array(
    array( 'id' => 1, 'name' => 'Heat pack', 'unit' => 'each', 'counted' => true, 'reorder_level' => '10.00', 'role' => 'heat_pack', 'box_name' => '' ),
    array( 'id' => 2, 'name' => 'Ice pack', 'unit' => 'each', 'counted' => true, 'reorder_level' => null, 'role' => 'ice_pack', 'box_name' => '' ),
    array( 'id' => 3, 'name' => 'Fish bag', 'unit' => 'each', 'counted' => true, 'reorder_level' => '100.00', 'role' => 'fish_bag', 'box_name' => '' ),
    array( 'id' => 4, 'name' => 'Foam sheet', 'unit' => 'sheets', 'counted' => false, 'reorder_level' => null, 'role' => 'foam', 'box_name' => '' ),
    array( 'id' => 5, 'name' => 'Packing paper', 'unit' => 'feet', 'counted' => false, 'reorder_level' => null, 'role' => 'paper', 'box_name' => '' ),
    array( 'id' => 6, 'name' => 'Small box', 'unit' => 'each', 'counted' => true, 'reorder_level' => null, 'role' => 'box', 'box_name' => 'Small' ),
    array( 'id' => 7, 'name' => 'Tape', 'unit' => 'rolls', 'counted' => false, 'reorder_level' => null, 'role' => '', 'box_name' => '' ),
);
$prices   = array( 1 => '0.6000', 2 => '0.9000', 3 => '0.1100', 6 => '1.2500', 7 => '3.0000' );

$standard_boxes = array(
    array( 'name' => 'Small', 'length' => '10', 'width' => '10', 'height' => '10', 'dims' => '10x10x10', 'foam_cost' => '1.80', 'paper_cost' => '0.40', 'ice_packs' => '2' ),
);

/**
 * Materials as recorded on a box.
 */
function fst_test_materials( array $lines, $foam = '0.00', $paper = '0.00' ) {
    global $supplies, $prices;
    return FST_Supplies::normalize_materials( array( 'lines' => $lines, 'foam_cost' => $foam, 'paper_cost' => $paper ), $supplies, $prices );
}

// ---------------------------------------------------------------------------
// Label + materials, and the split adding up.
// ---------------------------------------------------------------------------
echo "The split adds up\n";

$materials = array(
    // A Small box from Partner A: box 1.25, 6 bags 0.66, heat pack 0.60, foam 1.80, paper 0.40 = 4.71.
    'BOX-A' => fst_test_materials( array( array( 'supply_id' => 6, 'qty' => 1 ), array( 'supply_id' => 3, 'qty' => 6 ), array( 'supply_id' => 1, 'qty' => 1 ) ), '1.80', '0.40' ),
    // A Small box from Partner B: box 1.25, 2 bags 0.22, 2 ice packs 1.80, tape 0.75, foam 1.80, paper 0.40 = 6.22.
    'BOX-B' => fst_test_materials( array( array( 'supply_id' => 6, 'qty' => 1 ), array( 'supply_id' => 3, 'qty' => 2 ), array( 'supply_id' => 2, 'qty' => 2 ), array( 'supply_id' => 7, 'qty' => '0.25' ) ), '1.80', '0.40' ),
    // A custom box from Partner A: 3 bags 0.33.
    'BOX-C' => fst_test_materials( array( array( 'supply_id' => 3, 'qty' => 3 ) ) ),
    // No label cost recorded, but materials: 1 bag 0.11 + foam 1.80.
    'BOX-E' => fst_test_materials( array( array( 'supply_id' => 3, 'qty' => 1 ) ), '1.80' ),
);

$rows = array(
    fst_test_row( 1, 'BOX-A', '18.40', '10x10x10', 'Partner A' ),
    fst_test_row( 2, 'BOX-B', '21.00', '10x10x10', 'Partner B' ),
    fst_test_row( 3, 'BOX-C', '30.00', '30x20x20', 'Partner A' ),
    fst_test_row( 4, 'BOX-D', '9.00', '10x10x10', 'Partner A' ),   // A label cost, no materials recorded.
    fst_test_row( 5, 'BOX-E' ),                                    // Materials, no label cost.
);
$orders = fst_test_orders_paid( array( 1 => '25.00', 2 => '25.00', 3 => '0.00', 4 => '12.00', 5 => '14.00' ) );

$report = FST_Label_Cost::build_report( array( 'box-a', 'BOX-B', 'BOX-C', 'BOX-D', 'BOX-E' ), $rows, $orders, $standard_boxes, array( 'materials' => $materials ) );

fst_assert_same(
    array(
        'boxes'              => 5,
        'boxes_with_cost'    => 4,
        'boxes_without_cost' => 1,
        'total_cost'         => '89.66',
        'total_paid'         => '62.00',
        'difference'         => '-27.66',
        'avg_cost'           => '22.42',
        'paid_without_cost'  => '14.00',
    ),
    $report['summary'],
    'cost is label + materials: 78.40 of labels + 11.26 of materials, on the four boxes that have a label cost'
);
fst_assert_same(
    array( 'label' => '78.40', 'boxes' => '2.50', 'foam' => '3.60', 'paper' => '0.80', 'bags' => '1.21', 'packs' => '2.40', 'other' => '0.75', 'total' => '89.66' ),
    $report['split'],
    'the split: label, boxes, foam, paper, bags, packs (heat + ice), other'
);
fst_assert_same( $report['summary']['total_cost'], fst_test_split_sum( $report['split'] ), 'the parts add up to the total cost' );
fst_assert_same( $report['summary']['total_cost'], $report['split']['total'], 'and the split\'s own total is that total' );
fst_assert_same(
    array( 'total' => '11.26', 'without_label_cost' => '1.91', 'boxes_with_materials' => 4 ),
    $report['materials'],
    'materials: 11.26 in the totals; the 1.91 on the box without a label cost is named, not added'
);

$boxes = array();
foreach ( $report['boxes'] as $box ) {
    $boxes[ $box['tracking_number'] ] = $box;
}
fst_assert_same( array( '18.40', '4.71', '23.11', '1.89' ), array( $boxes['BOX-A']['label_cost'], $boxes['BOX-A']['materials_total'], $boxes['BOX-A']['total_cost'], $boxes['BOX-A']['difference'] ), 'a box: label 18.40 + materials 4.71 = 23.11, against 25.00 paid' );
fst_assert_same( array( '9.00', '0.00', '9.00', null ), array( $boxes['BOX-D']['label_cost'], $boxes['BOX-D']['materials_total'], $boxes['BOX-D']['total_cost'], $boxes['BOX-D']['materials'] ), 'a box without materials costs its label' );
fst_assert_same( array( null, '1.91', null, null ), array( $boxes['BOX-E']['label_cost'], $boxes['BOX-E']['materials_total'], $boxes['BOX-E']['total_cost'], $boxes['BOX-E']['difference'] ), 'a box without a label cost has no total cost and no difference — its materials are not "the cost"' );

// By box size.
$by_size = array();
foreach ( $report['by_size'] as $size_row ) {
    $by_size[ $size_row['dims'] ] = $size_row;
}
fst_assert_same(
    array( 'dims' => '10x10x10', 'label' => 'Small (10x10x10)', 'boxes' => 3, 'avg_cost' => '19.78', 'avg_paid' => '20.67', 'avg_diff' => '0.89' ),
    $by_size['10x10x10'],
    'by size: the average cost includes the materials ((18.40 + 4.71) + (21.00 + 6.22) + 9.00 = 59.33 over 3 boxes)'
);
fst_assert_same(
    array( 'label' => '48.40', 'boxes' => '2.50', 'foam' => '3.60', 'paper' => '0.80', 'bags' => '0.88', 'packs' => '2.40', 'other' => '0.75', 'total' => '59.33' ),
    $report['split_by_size']['10x10x10'],
    'with its own split'
);
fst_assert_same( array( 'label' => '30.00', 'boxes' => '0.00', 'foam' => '0.00', 'paper' => '0.00', 'bags' => '0.33', 'packs' => '0.00', 'other' => '0.00', 'total' => '30.33' ), $report['split_by_size']['30x20x20'], 'the custom size: a label and three bags' );

$size_total = 0;
foreach ( $report['split_by_size'] as $split ) {
    fst_assert_same( $split['total'], fst_test_split_sum( $split ), 'a size\'s parts add up to its total (' . $split['total'] . ')' );
    $size_total += (int) round( (float) $split['total'] * 100 );
}
fst_assert_same( $report['summary']['total_cost'], number_format( $size_total / 100, 2, '.', '' ), 'and the sizes add up to the total cost' );

// By label source.
$by_source = array();
foreach ( $report['by_source'] as $source_row ) {
    $by_source[ $source_row['source'] ] = $source_row;
}
fst_assert_same(
    array( 'source' => 'Partner A', 'label' => 'Partner A', 'boxes' => 3, 'total_cost' => '62.44', 'avg_cost' => '20.81' ),
    $by_source['Partner A'],
    'by source: total and average include the materials'
);
fst_assert_same( array( '62.44', '27.22' ), array( $report['split_by_source']['Partner A']['total'], $report['split_by_source']['Partner B']['total'] ), 'each source has its split' );
$source_total = 0;
foreach ( $report['split_by_source'] as $split ) {
    fst_assert_same( $split['total'], fst_test_split_sum( $split ), 'a source\'s parts add up to its total (' . $split['total'] . ')' );
    $source_total += (int) round( (float) $split['total'] * 100 );
}
fst_assert_same( $report['summary']['total_cost'], number_format( $source_total / 100, 2, '.', '' ), 'and the sources add up to the total cost' );

// Side by side and the losers use the full cost too.
$matrix = array();
foreach ( $report['size_by_source']['rows'] as $matrix_row ) {
    $matrix[ $matrix_row['dims'] ] = $matrix_row;
}
fst_assert_same( array( '16.06', '27.22', 'Partner A' ), array( $matrix['10x10x10']['cells']['Partner A']['avg_cost'], $matrix['10x10x10']['cells']['Partner B']['avg_cost'], $matrix['10x10x10']['cheapest'] ), 'cheaper source per size: by label + materials' );
fst_assert_same( array( array( 'BOX-C', '-30.33' ), array( 'BOX-B', '-2.22' ) ), array( array( $report['losers'][0]['tracking_number'], $report['losers'][0]['difference'] ), array( $report['losers'][1]['tracking_number'], $report['losers'][1]['difference'] ) ), 'a box that only loses money once its materials are counted (25.00 paid, 21.00 label, 6.22 materials) is a loss' );

// Without the extras the report is what it was in 1.9.17.
$plain = FST_Label_Cost::build_report( array( 'BOX-A' ), array( fst_test_row( 1, 'BOX-A', '18.40' ) ), $orders );
fst_assert_same( array( '18.40', '0.00', '18.40' ), array( $plain['summary']['total_cost'], $plain['materials']['total'], $plain['split']['label'] ), 'no materials anywhere: the cost is the label cost' );

$empty = FST_Label_Cost::build_report( array(), array(), array() );
fst_assert_same(
    array( '0.00', 0, '0.00', 0, 0, '0.00', 0 ),
    array( $empty['split']['total'], $empty['free_shipping']['orders'], $empty['free_shipping']['cost'], $empty['local_pickups']['orders'], $empty['in_other_box']['orders'], $empty['materials']['total'], $empty['materials']['boxes_with_materials'] ),
    'an empty range has every line, at zero'
);

// ---------------------------------------------------------------------------
// Free shipping given.
// ---------------------------------------------------------------------------
echo "Free shipping given\n";

fst_assert_same(
    array( 'orders' => 1, 'cost' => '30.33', 'orders_without_cost' => 0 ),
    $report['free_shipping'],
    'one order shipped with a label and paid 0.00 shipping; it cost its label + materials'
);

// A shared box: three orders, one of them on free shipping. Label 30.00 + materials 1.00 = 31.00.
$shared = FST_Label_Cost::build_report(
    array( 'SHARED' ),
    array( fst_test_row( 12, 'SHARED', '30.00' ), fst_test_row( 11, 'SHARED' ), fst_test_row( 13, 'SHARED' ) ),
    fst_test_orders_paid( array( 11 => '0.00', 12 => '10.00', 13 => '10.00' ) ),
    array(),
    array( 'materials' => array( 'SHARED' => fst_test_materials( array(), '1.00' ) ) )
);
fst_assert_same( array( 'orders' => 1, 'cost' => '10.34', 'orders_without_cost' => 0 ), $shared['free_shipping'], 'in a shared box the order takes an even share of the cost (31.00 / 3, the spare cent to the lowest order number)' );

// Free shipping over two boxes, a refund-to-zero order, an order that is gone, and a box without a label cost.
$free = FST_Label_Cost::build_report(
    array( 'F1', 'F2', 'G1', 'H1' ),
    array( fst_test_row( 21, 'F1', '10.00' ), fst_test_row( 21, 'F2', '12.00' ), fst_test_row( 22, 'G1' ), fst_test_row( 23, 'H1', '8.00' ) ),
    array( 21 => array( 'shipping' => 0.0, 'number' => '21', 'url' => '' ), 22 => array( 'shipping' => '0', 'number' => '22', 'url' => '' ), 23 => array( 'shipping' => null, 'number' => '23', 'url' => '' ) )
);
fst_assert_same(
    array( 'orders' => 2, 'cost' => '22.00', 'orders_without_cost' => 1 ),
    $free['free_shipping'],
    'two free orders: one in two boxes (10.00 + 12.00), one in a box without a label cost (counted, no amount); a deleted order is not "free shipping"'
);

// ---------------------------------------------------------------------------
// Local pickups.
// ---------------------------------------------------------------------------
echo "Local pickups\n";

$pickups = array(
    array( 'order_id' => 31, 'date' => '2026-11-03 09:00:00', 'materials' => fst_test_materials( array( array( 'supply_id' => 3, 'qty' => 4 ), array( 'supply_id' => 7, 'qty' => '0.1' ) ), '0.00', '0.20' ) ),
    array( 'order_id' => 32, 'date' => '2026-11-04 09:00:00', 'materials' => null ),
);
$with_pickups = FST_Label_Cost::build_report( array( 'BOX-A' ), array( fst_test_row( 1, 'BOX-A', '18.40' ) ), $orders, array(), array( 'local_pickups' => $pickups ) );
fst_assert_same(
    array(
        'orders'         => 2,
        'with_materials' => 1,
        'materials'      => '0.94',
        'split'          => array( 'boxes' => '0.00', 'foam' => '0.00', 'paper' => '0.20', 'bags' => '0.44', 'packs' => '0.00', 'other' => '0.30', 'total' => '0.94' ),
    ),
    $with_pickups['local_pickups'],
    'two local pickups, one with materials: 4 bags + a bit of tape + paper; there is no label part'
);
fst_assert_same( array( 1, '18.40', '18.40' ), array( $with_pickups['summary']['boxes'], $with_pickups['summary']['total_cost'], $with_pickups['split']['total'] ), 'a pickup is not a box: the box totals are untouched' );
fst_assert_same( 0, $with_pickups['free_shipping']['orders'], 'and not "free shipping with a label"' );

// ---------------------------------------------------------------------------
// Supplies report.
// ---------------------------------------------------------------------------
echo "Supplies report\n";

$purchase_id = 0;
function fst_test_bought( $supply_id, $date, $qty, $paid ) {
    global $purchase_id;
    $purchase_id++;
    return (object) array( 'id' => $purchase_id, 'supply_id' => $supply_id, 'purchase_date' => $date, 'qty' => $qty, 'total_paid' => $paid, 'note' => '' );
}

$purchases = array(
    fst_test_bought( 1, '2026-09-15', '40.00', '24.00' ),   // Before the range.
    fst_test_bought( 1, '2026-11-02', '40.00', '26.00' ),
    fst_test_bought( 3, '2026-11-03', '500.00', '55.00' ),
    fst_test_bought( 3, '2026-11-30', '500.00', '60.00' ),  // The last day of the range.
    fst_test_bought( 4, '2026-11-05', '2.00', '24.00' ),    // Two sheets of foam.
    fst_test_bought( 5, '2026-12-01', '1200.00', '60.00' ), // After the range.
    fst_test_bought( 99, '2026-11-05', '5.00', '5.00' ),    // A supply that is gone.
);
$stock     = array(
    1 => FST_Supplies::stock_from_totals( 8000, 7200, 0 ),    // 8 left: under its level of 10.
    3 => FST_Supplies::stock_from_totals( 100000, 1300, 0 ),  // 987 left.
    6 => FST_Supplies::stock_from_totals( 0, 200, 0 ),        // Used, never bought: −2.
);

// Used: the materials of every box shipped in the range — with or without a label cost — and of the local pickup.
$used            = array_values( $materials );
$used[]          = $pickups[0]['materials'];
$used[]          = FST_Supplies::materials_from_rows( array(
    (object) array( 'kind' => 'supply', 'supply_id' => 50, 'supply_name' => 'Old bag', 'role' => 'fish_bag', 'unit' => 'each', 'qty' => '2.00', 'unit_cost' => '0.1000', 'line_cost' => '0.20' ),
    (object) array( 'kind' => 'foam', 'supply_id' => 0, 'supply_name' => '', 'role' => 'foam', 'unit' => '', 'qty' => '0.00', 'unit_cost' => '0.0000', 'line_cost' => '0.00' ),
    (object) array( 'kind' => 'paper', 'supply_id' => 0, 'supply_name' => '', 'role' => 'paper', 'unit' => '', 'qty' => '0.00', 'unit_cost' => '0.0000', 'line_cost' => '0.00' ),
) );
$supplies_report = FST_Supplies::build_supplies_report( $supplies, $purchases, $used, $stock, '2026-11-01', '2026-11-30' );

$by_name = array();
foreach ( $supplies_report['rows'] as $row ) {
    $by_name[ $row['name'] ] = $row;
}

fst_assert_same(
    array( 'id' => 1, 'name' => 'Heat pack', 'unit' => 'each', 'role' => 'heat_pack', 'counted' => true, 'removed' => false, 'purchases' => 1, 'on_hand' => '8.00', 'recount_needed' => false, 'reorder_level' => '10.00', 'low' => true, 'bought_qty' => '40.00', 'bought_money' => '26.00', 'used_qty' => '1.00', 'used_money' => '0.60' ),
    $by_name['Heat pack'],
    'heat packs: one purchase in the range (the September one is not), 1 used, 8 on hand now — under the reorder level'
);
fst_assert_same(
    array( '1000.00', '115.00', 2, '16.00', '1.76', '987.00', false ),
    array( $by_name['Fish bag']['bought_qty'], $by_name['Fish bag']['bought_money'], $by_name['Fish bag']['purchases'], $by_name['Fish bag']['used_qty'], $by_name['Fish bag']['used_money'], $by_name['Fish bag']['on_hand'], $by_name['Fish bag']['low'] ),
    'bags: both purchases (the last day of the range counts); used = 6 + 2 + 3 on costed boxes, 1 on the box WITHOUT a label cost and 4 at the local pickup'
);
fst_assert_same(
    array( '0.00', '2.00', '0.00', true ),
    array( $by_name['Small box']['bought_qty'], $by_name['Small box']['used_qty'], $by_name['Small box']['on_hand'], $by_name['Small box']['recount_needed'] ),
    'a supply used but never bought: 0 on hand, never below, and "recount needed"'
);
fst_assert_same( array( null, false, null ), array( $by_name['Tape']['on_hand'], $by_name['Tape']['low'], $by_name['Tape']['reorder_level'] ), 'a supply that is not counted has no "on hand" and is never low' );
fst_assert_same( array( '0.35', '1.05' ), array( $by_name['Tape']['used_qty'], $by_name['Tape']['used_money'] ), 'but what was used of it is still added up (0.25 + 0.10 rolls)' );
fst_assert_same( array( true, '2.00', null ), array( $by_name['Old bag']['removed'], $by_name['Old bag']['used_qty'], $by_name['Old bag']['on_hand'] ), 'a supply that left the list is still shown with what was used of it' );
fst_assert_same( 8, count( $supplies_report['rows'] ), 'every saved supply has a row, plus the removed one that was used — the purchase of an unknown supply has none' );

fst_assert_same(
    array(
        'foam'  => array( 'supply' => 'Foam sheet', 'bought_money' => '24.00', 'ballpark' => '5.40', 'records' => 3 ),
        'paper' => array( 'supply' => 'Packing paper', 'bought_money' => '0.00', 'ballpark' => '1.00', 'records' => 3 ),
    ),
    $supplies_report['ballparks'],
    'foam and paper: what was bought in the range beside what the per-box amounts added up to (3 × 1.80; 0.40 + 0.40 + 0.20), the box without a label cost included'
);

$all_time = FST_Supplies::build_supplies_report( $supplies, $purchases, array(), $stock );
$all      = array();
foreach ( $all_time['rows'] as $row ) {
    $all[ $row['name'] ] = $row;
}
fst_assert_same( array( '80.00', '50.00', '60.00' ), array( $all['Heat pack']['bought_qty'], $all['Heat pack']['bought_money'], $all['Packing paper']['bought_money'] ), 'with no dates every purchase counts' );

$no_roles = FST_Supplies::build_supplies_report( array( $supplies[6] ), array(), array( $materials['BOX-A'] ), array() );
fst_assert_same(
    array( 'supply' => null, 'bought_money' => null, 'ballpark' => '1.80', 'records' => 1 ),
    $no_roles['ballparks']['foam'],
    'with no supply marked as foam the ballpark still adds up; there is just nothing bought to set beside it'
);

fst_test_finish();
