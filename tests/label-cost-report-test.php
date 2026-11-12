<?php
/**
 * Plain-PHP test for the label cost report maths on the Analytics page:
 * shared boxes, orders in several boxes, a box without a recorded cost
 * (NULL is never 0), the per-size / per-source tables and the biggest losses.
 *
 *   php tests/label-cost-report-test.php
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
        $orders[ $order_id ] = array(
            'shipping' => $paid,
            'number'   => (string) $order_id,
            'url'      => 'https://example.test/order/' . $order_id,
        );
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

$standard_boxes = array(
    array( 'name' => 'Small', 'length' => '10', 'width' => '10', 'height' => '10', 'dims' => '10x10x10' ),
);

// ---------------------------------------------------------------------------
// Recorded, not recorded, and a recorded zero.
// ---------------------------------------------------------------------------
echo "NULL is never 0\n";

$rows = array(
    fst_test_row( 1, 'BOX-A', '20.00', '10x10x10', 'Partner A' ),
    fst_test_row( 2, 'BOX-B' ),                                  // No cost recorded.
    fst_test_row( 3, 'BOX-C', '0.00', '10x10x10', 'Partner A' ), // Recorded as free.
);
$report = FST_Label_Cost::build_report(
    array( 'BOX-A', 'BOX-B', 'BOX-C' ),
    $rows,
    fst_test_orders_paid( array( 1 => '15.00', 2 => '12.00', 3 => '9.99' ) ),
    $standard_boxes
);

fst_assert_same(
    array(
        'boxes'              => 3,
        'boxes_with_cost'    => 2,
        'boxes_without_cost' => 1,
        'total_cost'         => '20.00',
        'total_paid'         => '24.99',
        'difference'         => '4.99',
        'avg_cost'           => '10.00',
        'paid_without_cost'  => '12.00',
    ),
    $report['summary'],
    'the box without a cost is counted as shipped but left out of cost, paid, difference and average; the 0.00 box is in'
);

$boxes = fst_test_boxes_by_tracking( $report );
fst_assert_same( array( null, null ), array( $boxes['BOX-B']['label_cost'], $boxes['BOX-B']['difference'] ), 'a box without a cost has no difference (not "paid minus 0")' );
fst_assert_same( array( '0.00', '9.99' ), array( $boxes['BOX-C']['label_cost'], $boxes['BOX-C']['difference'] ), 'a recorded 0.00 is a cost' );
fst_assert_same( array( '20.00', '15.00', '-5.00' ), array( $boxes['BOX-A']['label_cost'], $boxes['BOX-A']['paid'], $boxes['BOX-A']['difference'] ), 'cost 20.00 against 15.00 paid is a 5.00 loss' );
fst_assert_same( 'Small (10x10x10)', $boxes['BOX-A']['size_label'], 'a standard size is named' );

$report = FST_Label_Cost::build_report(
    array( 'BOX-B' ),
    array( fst_test_row( 2, 'BOX-B' ) ),
    fst_test_orders_paid( array( 2 => '12.00' ) )
);
fst_assert_same(
    array(
        'boxes'              => 1,
        'boxes_with_cost'    => 0,
        'boxes_without_cost' => 1,
        'total_cost'         => null,
        'total_paid'         => null,
        'difference'         => null,
        'avg_cost'           => null,
        'paid_without_cost'  => '12.00',
    ),
    $report['summary'],
    'no cost recorded anywhere: cost, paid, difference and average are unknown (null), never 0.00 — the 12.00 paid is only under paid_without_cost'
);
fst_assert_same( array( array(), array(), array() ), array( $report['by_size'], $report['by_source'], $report['losers'] ), 'and the tables are empty' );

$report = FST_Label_Cost::build_report(
    array( 'BOX-C' ),
    array( fst_test_row( 3, 'BOX-C', '0.00' ) ),
    fst_test_orders_paid( array( 3 => '0.00' ) )
);
fst_assert_same(
    array( 1, '0.00', '0.00', '0.00', '0.00' ),
    array(
        $report['summary']['boxes_with_cost'],
        $report['summary']['total_cost'],
        $report['summary']['total_paid'],
        $report['summary']['difference'],
        $report['summary']['avg_cost'],
    ),
    'a recorded 0.00 against 0.00 paid is a real total of 0.00, not unknown'
);

$report = FST_Label_Cost::build_report( array(), array(), array() );
fst_assert_same(
    array( 0, null, null, null, null ),
    array(
        $report['summary']['boxes'],
        $report['summary']['total_cost'],
        $report['summary']['total_paid'],
        $report['summary']['difference'],
        $report['summary']['avg_cost'],
    ),
    'an empty range has no boxes and no totals'
);

// ---------------------------------------------------------------------------
// One box shared by several orders.
// ---------------------------------------------------------------------------
echo "Shared box\n";

$rows = array(
    fst_test_row( 10, 'SHARED', '45.00', '16x16x16', 'Partner B' ), // Holds the cost.
    fst_test_row( 11, 'SHARED' ),
    fst_test_row( 12, 'SHARED' ),
);
$report = FST_Label_Cost::build_report(
    array( 'SHARED' ),
    $rows,
    fst_test_orders_paid( array( 10 => '15.00', 11 => '12.50', 12 => '10.00' ) )
);
$box    = $report['boxes'][0];

fst_assert_same( 1, $report['summary']['boxes'], 'three rows, one box' );
fst_assert_same( '45.00', $report['summary']['total_cost'], 'the cost is counted once (not 135.00)' );
fst_assert_same( '37.50', $report['summary']['total_paid'], 'the shipping of all three orders is summed' );
fst_assert_same( '-7.50', $report['summary']['difference'], 'difference = paid − cost' );
fst_assert_same( true, $box['shared_box'], 'the box is marked shared' );
fst_assert_same( array( 10, 11, 12 ), array( $box['orders'][0]['id'], $box['orders'][1]['id'], $box['orders'][2]['id'] ), 'its orders are listed for the links' );

// Rows that both hold a cost (edited outside the plugin) still count once.
$rows   = array(
    fst_test_row( 13, 'TWICE', '30.00' ),
    fst_test_row( 14, 'twice', '30.00' ),
);
$report = FST_Label_Cost::build_report( array( 'TWICE' ), $rows, fst_test_orders_paid( array( 13 => '10.00', 14 => '10.00' ) ) );
fst_assert_same( array( 1, '30.00' ), array( $report['summary']['boxes'], $report['summary']['total_cost'] ), 'two rows holding a cost for one tracking number (any letter case) are still one box, one cost' );

// ---------------------------------------------------------------------------
// One order in several boxes.
// ---------------------------------------------------------------------------
echo "Order in several boxes\n";

$rows = array(
    fst_test_row( 20, 'MULTI-X', '25.00' ),
    fst_test_row( 20, 'MULTI-Y', '12.00' ),
);
$report = FST_Label_Cost::build_report( array( 'MULTI-X', 'MULTI-Y' ), $rows, fst_test_orders_paid( array( 20 => '30.01' ) ) );
$boxes  = fst_test_boxes_by_tracking( $report );

fst_assert_same( '30.01', $report['summary']['total_paid'], 'the order\'s shipping is counted once across its two boxes' );
fst_assert_same( array( '15.01', '15.00' ), array( $boxes['MULTI-X']['paid'], $boxes['MULTI-Y']['paid'] ), 'split evenly, the spare cent to the earlier box' );
fst_assert_same( array( '-9.99', '3.00' ), array( $boxes['MULTI-X']['difference'], $boxes['MULTI-Y']['difference'] ), 'each box gets its own difference' );
fst_assert_same( '-6.99', $report['summary']['difference'], 'and the total is 30.01 − 37.00' );

// Only one of the order's boxes is in the date range.
$report = FST_Label_Cost::build_report( array( 'MULTI-X' ), $rows, fst_test_orders_paid( array( 20 => '30.01' ) ) );
fst_assert_same( array( 1, '15.01' ), array( $report['summary']['boxes'], $report['summary']['total_paid'] ), 'a box whose sibling shipped outside the range still carries only its half' );

// The order's second label was printed but has not moved yet.
$rows = array(
    fst_test_row( 21, 'MOVING', '25.00' ),
    fst_test_row( 21, 'PRINTED', '12.00', null, null, 'label_created' ),
);
$report = FST_Label_Cost::build_report( array( 'MOVING' ), $rows, fst_test_orders_paid( array( 21 => '30.00' ) ) );
fst_assert_same( '30.00', $report['summary']['total_paid'], 'a label that is not counted as shipped takes no share of the shipping' );

// One of the order's boxes has no cost: only the costed box is compared.
$rows = array(
    fst_test_row( 22, 'COSTED', '25.00' ),
    fst_test_row( 22, 'UNCOSTED' ),
);
$report = FST_Label_Cost::build_report( array( 'COSTED', 'UNCOSTED' ), $rows, fst_test_orders_paid( array( 22 => '40.00' ) ) );
fst_assert_same(
    array( 2, 1, '25.00', '20.00', '-5.00', '20.00' ),
    array(
        $report['summary']['boxes'],
        $report['summary']['boxes_with_cost'],
        $report['summary']['total_cost'],
        $report['summary']['total_paid'],
        $report['summary']['difference'],
        $report['summary']['paid_without_cost'],
    ),
    'the costed box is compared against its half; the other half is reported as paid for a box without a cost'
);

// A shared box whose orders also have boxes of their own.
$rows = array(
    fst_test_row( 30, 'MIX-SHARED', '50.00' ),
    fst_test_row( 31, 'MIX-SHARED' ),
    fst_test_row( 30, 'MIX-OWN', '10.00' ),
);
$report = FST_Label_Cost::build_report( array( 'MIX-SHARED', 'MIX-OWN' ), $rows, fst_test_orders_paid( array( 30 => '40.00', 31 => '25.00' ) ) );
$boxes  = fst_test_boxes_by_tracking( $report );
fst_assert_same( array( '45.00', '20.00' ), array( $boxes['MIX-SHARED']['paid'], $boxes['MIX-OWN']['paid'] ), 'shared box: half of order 30 plus all of order 31; own box: the other half of order 30' );
fst_assert_same( '65.00', $report['summary']['total_paid'], 'both orders\' shipping counted exactly once (40 + 25)' );

// ---------------------------------------------------------------------------
// An order that no longer exists.
// ---------------------------------------------------------------------------
echo "Deleted order\n";

$report = FST_Label_Cost::build_report(
    array( 'ORPHAN' ),
    array( fst_test_row( 40, 'ORPHAN', '18.00' ) ),
    array( 40 => array( 'shipping' => null, 'number' => '40', 'url' => '' ) )
);
fst_assert_same( array( '18.00', '0.00', '-18.00' ), array( $report['summary']['total_cost'], $report['summary']['total_paid'], $report['summary']['difference'] ), 'the label was still paid for; nothing was paid towards it' );
fst_assert_same( true, $report['boxes'][0]['orders'][0]['missing'], 'the order is flagged as missing' );

// ---------------------------------------------------------------------------
// By box size, by label source, and side by side.
// ---------------------------------------------------------------------------
echo "By size / by source\n";

$rows = array(
    fst_test_row( 50, 'S1', '18.00', '10x10x10', 'Partner A' ),
    fst_test_row( 51, 'S2', '20.00', '10x10x10', 'Partner A' ),
    fst_test_row( 52, 'S3', '21.00', '10x10x10', 'Partner B' ),
    fst_test_row( 53, 'S4', '30.00', '16x16x16', 'Partner B' ),
    fst_test_row( 54, 'S5', '30.00', '16x16x16', 'Partner A' ),
    fst_test_row( 55, 'S6', '14.00', '8x8x8', 'Partner B' ),
    fst_test_row( 56, 'S7', '10.01', null, null ),
    fst_test_row( 57, 'S8', '99.00', '10x10x10', null ),
    fst_test_row( 58, 'S9' ),                                // No cost: in no table.
);
$paid = array( 50 => '20.00', 51 => '20.00', 52 => '20.00', 53 => '25.00', 54 => '35.00', 55 => '14.00', 56 => '10.00', 57 => '20.00', 58 => '20.00' );
$report = FST_Label_Cost::build_report( array( 'S1', 'S2', 'S3', 'S4', 'S5', 'S6', 'S7', 'S8', 'S9' ), $rows, fst_test_orders_paid( $paid ), $standard_boxes );

fst_assert_same(
    array(
        array( 'dims' => '10x10x10', 'label' => 'Small (10x10x10)', 'boxes' => 4, 'avg_cost' => '39.50', 'avg_paid' => '20.00', 'avg_diff' => '-19.50' ),
        array( 'dims' => '16x16x16', 'label' => '16x16x16', 'boxes' => 2, 'avg_cost' => '30.00', 'avg_paid' => '30.00', 'avg_diff' => '0.00' ),
        array( 'dims' => '8x8x8', 'label' => '8x8x8', 'boxes' => 1, 'avg_cost' => '14.00', 'avg_paid' => '14.00', 'avg_diff' => '0.00' ),
        array( 'dims' => '', 'label' => 'Size not recorded', 'boxes' => 1, 'avg_cost' => '10.01', 'avg_paid' => '10.00', 'avg_diff' => '-0.01' ),
    ),
    $report['by_size'],
    'by box size: boxes, average cost, average shipping paid, average difference — most used first, unrecorded last'
);

fst_assert_same(
    array(
        array( 'source' => 'Partner A', 'label' => 'Partner A', 'boxes' => 3, 'total_cost' => '68.00', 'avg_cost' => '22.67' ),
        array( 'source' => 'Partner B', 'label' => 'Partner B', 'boxes' => 3, 'total_cost' => '65.00', 'avg_cost' => '21.67' ),
        array( 'source' => '', 'label' => 'Source not recorded', 'boxes' => 2, 'total_cost' => '109.01', 'avg_cost' => '54.51' ),
    ),
    $report['by_source'],
    'by label source: boxes, total and average cost (averages rounded to the cent)'
);

$matrix = array();
foreach ( $report['size_by_source']['rows'] as $matrix_row ) {
    $matrix[ $matrix_row['dims'] ] = $matrix_row;
}
fst_assert_same(
    array( 'Partner A', 'Partner B', '' ),
    array( $report['size_by_source']['sources'][0]['source'], $report['size_by_source']['sources'][1]['source'], $report['size_by_source']['sources'][2]['source'] ),
    'side by side: one column per source'
);
fst_assert_same(
    array( 'boxes' => 2, 'avg_cost' => '19.00' ),
    $matrix['10x10x10']['cells']['Partner A'],
    '10x10x10 through Partner A: two boxes averaging 19.00'
);
fst_assert_same( array( 'boxes' => 1, 'avg_cost' => '21.00' ), $matrix['10x10x10']['cells']['Partner B'], '10x10x10 through Partner B: one box at 21.00' );
fst_assert_same( 'Partner A', $matrix['10x10x10']['cheapest'], 'the cheaper partner for 10x10x10 is Partner A — the 99.00 box with no source does not count' );
fst_assert_same( null, $matrix['16x16x16']['cheapest'], 'a tie highlights nobody' );
fst_assert_same( null, $matrix['8x8x8']['cheapest'], 'a size bought through one partner only has nothing to compare' );
fst_assert_same( null, $matrix['8x8x8']['cells']['Partner A'], 'an empty cell is null, not 0' );

// A source typed by hand in different letter cases is one source.
$rows = array(
    fst_test_row( 60, 'C1', '10.00', '10x10x10', 'Partner C' ),
    fst_test_row( 61, 'C2', '14.00', '10x10x10', 'partner c' ),
    fst_test_row( 62, 'C3', '30.00', '16x16x16', 'PARTNER C' ),
    fst_test_row( 63, 'C4', '16.00', '10x10x10', 'Partner A' ),
);
$report = FST_Label_Cost::build_report( array( 'C1', 'C2', 'C3', 'C4' ), $rows, array(), $standard_boxes );

fst_assert_same(
    array(
        array( 'source' => 'Partner C', 'label' => 'Partner C', 'boxes' => 3, 'total_cost' => '54.00', 'avg_cost' => '18.00' ),
        array( 'source' => 'Partner A', 'label' => 'Partner A', 'boxes' => 1, 'total_cost' => '16.00', 'avg_cost' => '16.00' ),
    ),
    $report['by_source'],
    '"Partner C", "partner c" and "PARTNER C" are one row, under the first spelling seen'
);
fst_assert_same( 2, count( $report['size_by_source']['sources'] ), 'and one column side by side' );

$matrix = array();
foreach ( $report['size_by_source']['rows'] as $matrix_row ) {
    $matrix[ $matrix_row['dims'] ] = $matrix_row;
}
fst_assert_same( array( 'boxes' => 2, 'avg_cost' => '12.00' ), $matrix['10x10x10']['cells']['Partner C'], 'both spellings land in the same cell' );
fst_assert_same( 'Partner C', $matrix['10x10x10']['cheapest'], 'so the cheaper partner is compared on all of its boxes' );

$boxes = fst_test_boxes_by_tracking( $report );
fst_assert_same( 'partner c', $boxes['C2']['label_source'], 'the box itself keeps the spelling that was recorded' );

// ---------------------------------------------------------------------------
// The boxes that lost the most.
// ---------------------------------------------------------------------------
echo "Biggest losses\n";

$rows    = array();
$numbers = array();
$paid    = array();
for ( $n = 1; $n <= 25; $n++ ) {
    // Box n costs 10 + n and its customer paid 10: it loses n.
    $rows[]         = fst_test_row( 600 + $n, 'LOSS-' . $n, number_format( 10 + $n, 2, '.', '' ), '10x10x10', 'Partner A' );
    $numbers[]      = 'LOSS-' . $n;
    $paid[ 600 + $n ] = '10.00';
}
$rows[]      = fst_test_row( 700, 'EVEN', '10.00' );
$rows[]      = fst_test_row( 701, 'GAIN', '5.00' );
$rows[]      = fst_test_row( 702, 'NOCOST' );
$numbers     = array_merge( $numbers, array( 'EVEN', 'GAIN', 'NOCOST' ) );
$paid[700]   = '10.00';
$paid[701]   = '10.00';
$paid[702]   = '0.00';

$report = FST_Label_Cost::build_report( $numbers, $rows, fst_test_orders_paid( $paid ) );

fst_assert_same( 20, count( $report['losers'] ), 'at most 20 boxes are listed' );
fst_assert_same( array( 'LOSS-25', '-25.00' ), array( $report['losers'][0]['tracking_number'], $report['losers'][0]['difference'] ), 'the biggest loss comes first' );
fst_assert_same( array( 'LOSS-6', '-6.00' ), array( $report['losers'][19]['tracking_number'], $report['losers'][19]['difference'] ), 'the 20th is the 20th biggest' );

$listed = array();
foreach ( $report['losers'] as $loser ) {
    $listed[] = $loser['tracking_number'];
}
fst_assert_same( false, in_array( 'NOCOST', $listed, true ), 'a box without a cost is never listed as a loss' );
fst_assert_same( 'https://example.test/order/625', $report['losers'][0]['orders'][0]['url'], 'each loss carries its order link' );

$report = FST_Label_Cost::build_report(
    array( 'EVEN', 'GAIN' ),
    array( fst_test_row( 700, 'EVEN', '10.00' ), fst_test_row( 701, 'GAIN', '5.00' ) ),
    fst_test_orders_paid( array( 700 => '10.00', 701 => '10.00' ) )
);
fst_assert_same( array(), $report['losers'], 'boxes that broke even or made money are not losses' );

fst_test_finish();
