<?php
/**
 * Plain-PHP test for the materials of a box: the supplies list, the defaults
 * a box starts with (standard / custom, fish count, each weather action, no
 * weather data), snapshots that later price or setting changes cannot
 * rewrite, the suggested foam and paper cost, and input validation.
 *
 *   php tests/supplies-defaults-test.php
 *
 * Exit code 0 = all assertions passed, 1 = at least one failed.
 *
 * @package FisHotel_ShipTracker
 */

require __DIR__ . '/bootstrap.php';

$wpdb = new FST_Test_WPDB();

/**
 * The lines of a materials record as "qty × name @ unit cost = cost".
 */
function fst_test_lines( $materials ) {
    $lines = array();
    foreach ( $materials['lines'] as $line ) {
        $lines[] = FST_Label_Cost::trim_decimal( $line['qty'] ) . ' x ' . $line['name'] . ' @ ' . $line['unit_cost'] . ' = ' . $line['cost'];
    }
    return $lines;
}

// ---------------------------------------------------------------------------
// The supplies list.
// ---------------------------------------------------------------------------
echo "Supplies list\n";

$boxes = FST_Settings::sanitize_standard_boxes( array(
    array( 'name' => 'Small', 'length' => '10', 'width' => '10', 'height' => '10', 'foam_cost' => '$1.80', 'paper_cost' => '0.4', 'paper_feet' => '3', 'ice_packs' => '2' ),
    array( 'name' => 'Large', 'length' => '16', 'width' => '16', 'height' => '16', 'foam_cost' => 'lots', 'ice_packs' => '-1' ),
) );
fst_assert_same(
    array( 'foam_cost' => '1.80', 'paper_cost' => '0.40', 'paper_feet' => '3', 'ice_packs' => '2' ),
    array_slice( $boxes[0], 4 ),
    'a standard box keeps its foam cost, paper cost, feet of paper and ice-pack count'
);
fst_assert_same(
    array( 'foam_cost' => '0.00', 'paper_cost' => '0.00', 'paper_feet' => '', 'ice_packs' => '0' ),
    array_slice( $boxes[1], 4 ),
    'left out (or not a number) they are 0 — the two amounts default to 0.00'
);
$fst_test_options['fst_standard_boxes'] = $boxes;
$standard_boxes                         = FST_Settings::get_standard_boxes();

$clean = FST_Supplies::sanitize_supplies(
    array(
        array( 'name' => ' Heat pack ', 'unit' => '', 'counted' => '1', 'reorder_level' => '10', 'role' => 'heat_pack' ),
        array( 'name' => 'Ice pack', 'unit' => 'each', 'counted' => '1', 'role' => 'ice_pack' ),
        array( 'name' => 'Fish bag', 'unit' => 'each', 'counted' => '1', 'reorder_level' => '100', 'role' => 'fish_bag' ),
        array( 'name' => 'Foam sheet', 'unit' => 'sheets', 'role' => 'foam', 'reorder_level' => '2' ),
        array( 'name' => 'Packing paper', 'unit' => 'feet', 'role' => 'paper' ),
        array( 'name' => 'Small box', 'unit' => 'each', 'counted' => '1', 'role' => 'box:small' ),
        array( 'name' => 'Large box', 'unit' => 'each', 'counted' => '1', 'role' => 'box', 'box_name' => 'Large' ),
        array( 'name' => 'Spare heat pack', 'role' => 'heat_pack' ),       // The role is taken.
        array( 'name' => 'Another small box', 'role' => 'box:Small' ),     // That box has its supply.
        array( 'name' => 'Odd box', 'role' => 'box:Medium' ),              // No such standard box.
        array( 'name' => 'heat pack', 'role' => 'ice_pack' ),              // Same name again.
        array( 'name' => '', 'unit' => 'each' ),                           // The blank row.
        array( 'name' => 'Rubber bands', 'unit' => 'bag of 100', 'role' => 'nonsense' ),
        'junk',
    ),
    $standard_boxes,
    0
);
$supplies = $clean['supplies'];

$summary = array();
foreach ( $supplies as $supply ) {
    $summary[] = $supply['id'] . ':' . $supply['name'] . ':' . $supply['role'] . ( '' !== $supply['box_name'] ? '=' . $supply['box_name'] : '' );
}
fst_assert_same(
    array(
        '1:Heat pack:heat_pack',
        '2:Ice pack:ice_pack',
        '3:Fish bag:fish_bag',
        '4:Foam sheet:foam',
        '5:Packing paper:paper',
        '6:Small box:box=Small',
        '7:Large box:box=Large',
        '8:Spare heat pack:',
        '9:Another small box:',
        '10:Odd box:',
        '11:Rubber bands:',
    ),
    $summary,
    'one supply per role (one box supply per standard box); a second loses the role, a repeated name and the blank row are dropped'
);
fst_assert_same( 11, $clean['last_id'], 'new supplies take the next ids' );
fst_assert_same( array( 'each', true, '10.00' ), array( $supplies[0]['unit'], $supplies[0]['counted'], $supplies[0]['reorder_level'] ), 'unit defaults to "each"; a counted supply keeps its reorder level' );
fst_assert_same( array( false, null ), array( $supplies[3]['counted'], $supplies[3]['reorder_level'] ), 'a supply that is not counted down has no reorder level' );
fst_assert_same( array( '8', '4', '0.5' ), array( $supplies[3]['sheet_length'], $supplies[3]['sheet_width'], $supplies[3]['thickness'] ), 'the foam supply keeps its sheet: 8 ft by 4 ft, half an inch thick, by default' );
fst_assert_same( 'bag of 100', $supplies[10]['unit'], 'a unit can be free text' );

// Saved again with one removed and one added: ids are stable and never reused.
$again = FST_Supplies::sanitize_supplies(
    array(
        array( 'id' => 1, 'name' => 'Heat pack (40 hr)', 'counted' => '1', 'role' => 'heat_pack' ),
        array( 'id' => 3, 'name' => 'Fish bag', 'counted' => '1', 'role' => 'fish_bag' ),
        array( 'id' => '', 'name' => 'Stickers' ),
        array( 'id' => 3, 'name' => 'Copy of the bag row' ),
    ),
    $standard_boxes,
    $clean['last_id']
);
fst_assert_same(
    array( 1 => 'Heat pack (40 hr)', 3 => 'Fish bag', 12 => 'Stickers', 13 => 'Copy of the bag row' ),
    array_column( $again['supplies'], 'name', 'id' ),
    'a renamed supply keeps its id; a new one (or a row repeating an id) gets an id that was never used'
);

$fst_test_options['fst_supplies']       = $supplies;
$fst_test_options['fst_supply_last_id'] = $clean['last_id'];

fst_assert_same( 'Small box', FST_Supplies::supply_for_role( 'box', $supplies, 'small' )['name'], 'the box supply is found by its standard box (any letter case)' );
fst_assert_same( null, FST_Supplies::supply_for_role( 'box', $supplies, 'Medium' ), 'a box without a supply has none' );
fst_assert_same( 'Fish bag', FST_Supplies::find_supply( 'fish BAG', $supplies )['name'], 'a supply is found by name' );
fst_assert_same( 'Fish bag', FST_Supplies::find_supply( 3, $supplies )['name'], 'or by id' );
fst_assert_same( null, FST_Supplies::find_supply( 'Bubble wrap', $supplies ), 'an unknown supply is not guessed' );

// ---------------------------------------------------------------------------
// The defaults of a box.
// ---------------------------------------------------------------------------
echo "Defaults per box\n";

$prices = array( 1 => '0.6000', 2 => '0.9000', 3 => '0.1100', 6 => '1.2500', 7 => '2.1000' );
$small  = $standard_boxes[0];
$large  = $standard_boxes[1];

$default = FST_Supplies::default_materials( $small, $supplies, $prices, 6, 'heat_pack' );
fst_assert_same(
    array( '1 x Small box @ 1.2500 = 1.25', '6 x Fish bag @ 0.1100 = 0.66', '1 x Heat pack @ 0.6000 = 0.60' ),
    fst_test_lines( $default ),
    'a standard box, 6 fish, heat pack weather: its own box, a bag per fish, ONE heat pack'
);
fst_assert_same( array( '1.80', '0.40', '4.71' ), array( $default['foam_cost'], $default['paper_cost'], $default['total'] ), 'plus its foam and its paper cost: 1.25 + 0.66 + 0.60 + 1.80 + 0.40' );
fst_assert_same(
    array( 'boxes' => '1.25', 'foam' => '1.80', 'paper' => '0.40', 'bags' => '0.66', 'packs' => '0.60', 'other' => '0.00' ),
    $default['split'],
    'and the split adds up to the total'
);

$default = FST_Supplies::default_materials( $small, $supplies, $prices, 6, 'cooling' );
fst_assert_same(
    array( '1 x Small box @ 1.2500 = 1.25', '6 x Fish bag @ 0.1100 = 0.66', '2 x Ice pack @ 0.9000 = 1.80' ),
    fst_test_lines( $default ),
    'cooling weather: the box\'s own ice-pack count (2), no heat pack'
);

$default = FST_Supplies::default_materials( $large, $supplies, $prices, 12, 'cooling' );
fst_assert_same(
    array( '1 x Large box @ 2.1000 = 2.10', '12 x Fish bag @ 0.1100 = 1.32' ),
    fst_test_lines( $default ),
    'a box with an ice-pack count of 0 takes none'
);
fst_assert_same( array( '0.00', '0.00' ), array( $default['foam_cost'], $default['paper_cost'] ), 'and a box without a foam or paper cost starts at 0.00' );

foreach ( array( 'none', 'ups_store_cold', 'ups_store_hot', 'hold_cold', 'hold_hot', 'unavailable', '' ) as $action ) {
    $default = FST_Supplies::default_materials( $small, $supplies, $prices, 2, $action );
    fst_assert_same(
        array( '1 x Small box @ 1.2500 = 1.25', '2 x Fish bag @ 0.1100 = 0.22' ),
        fst_test_lines( $default ),
        'weather action "' . $action . '": no pack of either kind'
    );
}

$default = FST_Supplies::default_materials( null, $supplies, $prices, 3, 'heat_pack' );
fst_assert_same( array( '3 x Fish bag @ 0.1100 = 0.33', '1 x Heat pack @ 0.6000 = 0.60' ), fst_test_lines( $default ), 'a custom box: no box supply' );
fst_assert_same( array( '0.00', '0.00', '0.93' ), array( $default['foam_cost'], $default['paper_cost'], $default['total'] ), 'and foam and paper start at 0' );

$default = FST_Supplies::default_materials( null, $supplies, $prices, 3, 'cooling' );
fst_assert_same( array( '3 x Fish bag @ 0.1100 = 0.33' ), fst_test_lines( $default ), 'a custom box has no ice-pack count, so cooling adds nothing by itself' );

$default = FST_Supplies::default_materials( $small, $supplies, $prices, 0, '' );
fst_assert_same( array( '1 x Small box @ 1.2500 = 1.25' ), fst_test_lines( $default ), 'no fish, no bag line' );

$default = FST_Supplies::default_materials( $small, array(), $prices, 6, 'heat_pack' );
fst_assert_same( array( array(), '2.20' ), array( $default['lines'], $default['total'] ), 'with no supplies saved only the two flat amounts remain' );

$default = FST_Supplies::default_materials( $small, $supplies, array(), 1, 'heat_pack' );
fst_assert_same(
    array( '1 x Small box @ 0.0000 = 0.00', '1 x Fish bag @ 0.0000 = 0.00', '1 x Heat pack @ 0.0000 = 0.00' ),
    fst_test_lines( $default ),
    'a supply never bought costs 0 until a purchase is logged — the line (and the stock) is still recorded'
);

// ---------------------------------------------------------------------------
// Fish count and weather action, read from the order.
// ---------------------------------------------------------------------------
echo "Fish count and weather action\n";

$order             = new WC_Order( 100, array( 1, 2, 3 ) );
$order->quantities = array( 1 => 4, 2 => 2 );
fst_assert_same( 7, FST_Supplies::fish_count( $order ), 'all of the order\'s items: 4 + 2 + 1' );
fst_assert_same( 6, FST_Supplies::fish_count( $order, array( 1, 2 ) ), 'only the items in this shipment: 4 + 2' );
fst_assert_same( 0, FST_Supplies::fish_count( $order, array( 99 ) ), 'none of them' );
fst_assert_same( 0, FST_Supplies::fish_count( false ), 'no order, no fish' );

$fst_test_filters['fst_box_fish_count'] = function ( $count, $filtered_order, $item_ids ) {
    return $count - 1; // One of the items is a bottle of food.
};
fst_assert_same( 6, FST_Supplies::fish_count( $order ), 'the fst_box_fish_count filter adjusts it' );
unset( $fst_test_filters['fst_box_fish_count'] );

fst_assert_same( '', FST_Supplies::order_pack_action( $order ), 'no weather data on the order: no action' );
fst_assert_same( '', FST_Supplies::order_pack_action( false ), 'no order: no action' );

foreach ( array( 'heat_pack', 'cooling', 'none', 'ups_store_cold', 'hold_hot', 'unavailable' ) as $action ) {
    $noted = new WC_Order( 101, array( 1 ), array( '_fh_weather_action' => $action ) );
    fst_assert_same( $action, FST_Supplies::order_pack_action( $noted ), 'the weather action "' . $action . '" is read from the order meta' );
}

$noted = new WC_Order( 102, array( 1 ), array( '_fh_weather_action' => 'heat_pack', '_fh_weather_checked_for' => '2026-11-04', '_fishotel_shipping_date' => '2026-11-04' ) );
fst_assert_same( 'heat_pack', FST_Supplies::order_pack_action( $noted ), 'noted for the delivery date the order has' );
$noted = new WC_Order( 102, array( 1 ), array( '_fh_weather_action' => 'heat_pack', '_fh_weather_checked_for' => '2026-11-04', '_fishotel_shipping_date' => '2026-11-11' ) );
fst_assert_same( '', FST_Supplies::order_pack_action( $noted ), 'noted for another delivery date: not used' );

$fst_test_orders[900] = new WC_Order( 900, array( 90 ), array( '_fishotel_is_fulfillment' => '1', '_fh_weather_action' => 'cooling' ), 'fulfillment' );
$source               = new WC_Order( 103, array( 1 ), array( '_fishotel_fulfilled_by_order' => '900' ) );
fst_assert_same( 'cooling', FST_Supplies::order_pack_action( $source ), 'a source order without a note takes the note of its combined box' );
$source = new WC_Order( 103, array( 1 ), array( '_fishotel_fulfilled_by_order' => '900', '_fh_weather_action' => 'none' ) );
fst_assert_same( 'none', FST_Supplies::order_pack_action( $source ), 'but its own note comes first' );

$fst_test_filters['fst_order_pack_action'] = function ( $action, $filtered_order ) {
    return 'Heat_Pack';
};
fst_assert_same( 'heat_pack', FST_Supplies::order_pack_action( $order ), 'the fst_order_pack_action filter can supply the action' );
unset( $fst_test_filters['fst_order_pack_action'] );

// ---------------------------------------------------------------------------
// Through add_tracking(): absent = defaults, present = as sent.
// ---------------------------------------------------------------------------
echo "add_tracking\n";

FST_Supplies::add_purchase( array( 'supply_id' => 1, 'purchase_date' => '2026-10-01', 'qty' => '40', 'total_paid' => '24.00' ) );
FST_Supplies::add_purchase( array( 'supply_id' => 2, 'purchase_date' => '2026-10-01', 'qty' => '20', 'total_paid' => '18.00' ) );
FST_Supplies::add_purchase( array( 'supply_id' => 3, 'purchase_date' => '2026-10-01', 'qty' => '500', 'total_paid' => '55.00' ) );
FST_Supplies::add_purchase( array( 'supply_id' => 6, 'purchase_date' => '2026-10-01', 'qty' => '25', 'total_paid' => '31.25' ) );

$fst_test_orders[100]             = new WC_Order( 100, array( 1, 2, 3 ), array( '_fh_weather_action' => 'heat_pack' ) );
$fst_test_orders[100]->quantities = array( 1 => 4, 2 => 2 );
$fst_test_orders[101]             = new WC_Order( 101, array( 5 ), array( '_fh_weather_action' => 'cooling' ) );
$fst_test_orders[102]             = new WC_Order( 102, array( 6 ) );

$service = new FST_Order();

$added = $service->add_tracking( array( 'order_id' => 100, 'tracking_number' => 'BOX-1', 'carrier' => 'ups', 'line_item_ids' => array( 1, 2 ), 'box_dims' => 'Small', 'label_cost' => '18.40' ) );
$box   = FST_Supplies::get_box_materials( 'BOX-1' );
fst_assert_same(
    array( '1 x Small box @ 1.2500 = 1.25', '6 x Fish bag @ 0.1100 = 0.66', '1 x Heat pack @ 0.6000 = 0.60' ),
    fst_test_lines( $box ),
    'no materials sent + a standard box: the defaults are recorded (6 fish in the two items of this shipment, not the order\'s 7)'
);
fst_assert_same( array( '1.80', '0.40', '4.71' ), array( $box['foam_cost'], $box['paper_cost'], $box['total'] ), 'with the box\'s foam and paper cost' );
fst_assert_same(
    array( '4.71', '23.11' ),
    array( $added['shipments'][0]['materials_total'], $added['shipments'][0]['total_cost'] ),
    'the reply carries the materials total and label + materials'
);

$service->add_tracking( array( 'order_id' => 101, 'tracking_number' => 'BOX-2', 'carrier' => 'ups', 'line_item_ids' => array( 5 ), 'box_dims' => '10 x 10 x 10' ) );
fst_assert_same(
    array( '1 x Small box @ 1.2500 = 1.25', '1 x Fish bag @ 0.1100 = 0.11', '2 x Ice pack @ 0.9000 = 1.80' ),
    fst_test_lines( FST_Supplies::get_box_materials( 'BOX-2' ) ),
    'a standard box given by its size, cooling weather: the box\'s 2 ice packs'
);

$service->add_tracking( array( 'order_id' => 102, 'tracking_number' => 'BOX-3', 'carrier' => 'ups', 'line_item_ids' => array( 6 ), 'box_dims' => '30x20x20' ) );
fst_assert_same( null, FST_Supplies::get_box_materials( 'BOX-3' ), 'a custom box with no materials sent records none (there is no "usual")' );

$fst_test_orders[104] = new WC_Order( 104, array( 8 ), array( '_fh_weather_action' => 'heat_pack' ) );
$service->add_tracking( array( 'order_id' => 104, 'tracking_number' => 'BOX-4', 'carrier' => 'ups', 'line_item_ids' => array( 8 ) ) );
fst_assert_same( null, FST_Supplies::get_box_materials( 'BOX-4' ), 'no box given at all records none' );

$fst_test_orders[105] = new WC_Order( 105, array( 9 ), array( '_fh_weather_action' => 'heat_pack' ) );
$service->add_tracking( array(
    'order_id'        => 105,
    'tracking_number' => 'BOX-5',
    'carrier'         => 'ups',
    'line_item_ids'   => array( 9 ),
    'box_dims'        => 'Small',
    'materials'       => array( 'lines' => array( array( 'supply' => 'Fish bag', 'qty' => '3' ) ), 'paper_cost' => '0.25' ),
) );
$box = FST_Supplies::get_box_materials( 'BOX-5' );
fst_assert_same(
    array( array( '3 x Fish bag @ 0.1100 = 0.33' ), '0.00', '0.25', '0.58' ),
    array( fst_test_lines( $box ), $box['foam_cost'], $box['paper_cost'], $box['total'] ),
    'materials that ARE sent are taken as sent: no box, no heat pack, no foam cost added from the defaults'
);

$fst_test_orders[106] = new WC_Order( 106, array( 10 ), array( '_fh_weather_action' => 'heat_pack' ) );
$service->add_tracking( array( 'order_id' => 106, 'tracking_number' => 'BOX-6', 'carrier' => 'ups', 'line_item_ids' => array( 10 ), 'box_dims' => 'Small', 'materials' => null ) );
fst_assert_same( null, FST_Supplies::get_box_materials( 'BOX-6' ), 'materials sent as null means "record none" — not the defaults' );

// One box, one record: a fan-out to two source orders.
$fst_test_orders[201]             = new WC_Order( 201, array( 11, 12 ) );
$fst_test_orders[201]->quantities = array( 11 => 3 );
$fst_test_orders[202]             = new WC_Order( 202, array( 21 ) );
$fst_test_orders[500]             = new WC_Order( 500, array( 90 ), array( '_fishotel_is_fulfillment' => '1', '_fishotel_fulfillment_sources' => '201,202', '_fh_weather_action' => 'heat_pack' ), 'fulfillment' );

$service->add_tracking( array( 'order_id' => 500, 'tracking_number' => 'BOX-7', 'carrier' => 'ups', 'box_dims' => 'Small' ) );
fst_assert_same(
    array( '1 x Small box @ 1.2500 = 1.25', '5 x Fish bag @ 0.1100 = 0.55', '1 x Heat pack @ 0.6000 = 0.60' ),
    fst_test_lines( FST_Supplies::get_box_materials( 'BOX-7' ) ),
    'a shared box is ONE box: one box supply, one heat pack, a bag for each fish of both orders (3 + 1 + 1)'
);
$usage_rows = 0;
foreach ( $wpdb->tables['wp_fst_supply_usage'] as $row ) {
    if ( 'BOX-7' === $row['tracking_number'] ) {
        $usage_rows++;
    }
}
fst_assert_same( 5, $usage_rows, 'stored once for the tracking number: three lines, foam, paper — not once per shipment row' );

// The same tracking number added to another order later keeps what the box has.
$fst_test_orders[107]             = new WC_Order( 107, array( 30 ) );
$fst_test_orders[107]->quantities = array( 30 => 9 );
$service->add_tracking( array( 'order_id' => 107, 'tracking_number' => 'BOX-7', 'carrier' => 'ups', 'line_item_ids' => array( 30 ), 'box_dims' => 'Small' ) );
fst_assert_same( '5.00', FST_Supplies::get_box_materials( 'BOX-7' )['lines'][1]['qty'], 'a box that already has materials is not defaulted a second time' );

// ---------------------------------------------------------------------------
// Snapshots: later price or setting changes never rewrite a recorded box.
// ---------------------------------------------------------------------------
echo "Snapshots\n";

$before = FST_Supplies::get_box_materials( 'BOX-1' );

FST_Supplies::add_purchase( array( 'supply_id' => 1, 'purchase_date' => '2026-11-01', 'qty' => '40', 'total_paid' => '40.00' ) ); // Heat packs: 0.60 -> 1.00.
FST_Supplies::add_purchase( array( 'supply_id' => 3, 'purchase_date' => '2026-11-01', 'qty' => '500', 'total_paid' => '75.00' ) ); // Bags: 0.11 -> 0.15.
$fst_test_options['fst_standard_boxes'][0]['foam_cost']  = '2.50';
$fst_test_options['fst_standard_boxes'][0]['paper_cost'] = '0.90';
$fst_test_options['fst_supplies'][2]['name']             = 'Fish bag (4 mil)';

fst_assert_same( array( '1.0000', '0.1500' ), array( FST_Supplies::get_prices()[1], FST_Supplies::get_prices()[3] ), 'prices went up' );
fst_assert_same( $before, FST_Supplies::get_box_materials( 'BOX-1' ), 'the box recorded before is exactly as it was: quantities, unit costs, foam, paper, total, names' );

$fst_test_orders[108] = new WC_Order( 108, array( 40 ), array( '_fh_weather_action' => 'heat_pack' ) );
$new_box              = $service->add_tracking( array( 'order_id' => 108, 'tracking_number' => 'BOX-8', 'carrier' => 'ups', 'line_item_ids' => array( 40 ), 'box_dims' => 'Small' ) );
$box                  = FST_Supplies::get_box_materials( 'BOX-8' );
fst_assert_same(
    array( array( '1 x Small box @ 1.2500 = 1.25', '1 x Fish bag (4 mil) @ 0.1500 = 0.15', '1 x Heat pack @ 1.0000 = 1.00' ), '2.50', '0.90' ),
    array( fst_test_lines( $box ), $box['foam_cost'], $box['paper_cost'] ),
    'a box recorded now uses the new prices and the new amounts'
);

// Editing a quantity keeps the price the line was recorded at; a new line takes today's.
$edited = $service->set_label_cost( $added['shipment_id'], array( 'materials' => array( 'lines' => array(
    array( 'supply_id' => 6, 'qty' => 1 ),
    array( 'supply_id' => 3, 'qty' => 7 ),
    array( 'supply_id' => 2, 'qty' => 1 ),
) ) ) );
fst_assert_same(
    array( '1 x Small box @ 1.2500 = 1.25', '7 x Fish bag (4 mil) @ 0.1100 = 0.77', '1 x Ice pack @ 0.9000 = 0.90' ),
    fst_test_lines( $edited['materials'] ),
    'changing 6 bags to 7 keeps 0.11 each (not today\'s 0.15); the heat pack left off is removed; the new ice pack line costs today\'s price'
);
fst_assert_same( array( '1.80', '0.40' ), array( $edited['materials']['foam_cost'], $edited['materials']['paper_cost'] ), 'foam and paper were not sent, so they stay as recorded' );
fst_assert_same( array( '5.12', '23.52' ), array( $edited['materials_total'], $edited['total_cost'] ), 'the total follows: 1.25 + 0.77 + 0.90 + 1.80 + 0.40, and 18.40 more with the label' );

$edited = $service->set_label_cost( $added['shipment_id'], array( 'materials' => array( 'foam_cost' => '2.00' ) ) );
fst_assert_same( array( 3, '2.00', '0.40' ), array( count( $edited['materials']['lines'] ), $edited['materials']['foam_cost'], $edited['materials']['paper_cost'] ), 'sending only the foam cost changes only the foam cost' );

$edited = $service->set_label_cost( $added['shipment_id'], array( 'materials' => array( 'lines' => array( array( 'supply_id' => 3, 'qty' => 7, 'unit_cost' => '0.2' ) ) ) ) );
fst_assert_same( array( '7 x Fish bag (4 mil) @ 0.2000 = 1.40' ), fst_test_lines( $edited['materials'] ), 'a unit cost sent with a line overrides the snapshot' );

$edited = $service->set_label_cost( $added['shipment_id'], array( 'label_cost' => '19.00' ) );
fst_assert_same( array( '19.00', '3.80' ), array( $edited['label_cost'], $edited['materials_total'] ), 'a cost change without the materials key leaves the materials alone' );

$edited = $service->set_label_cost( $added['shipment_id'], array( 'materials' => '' ) );
fst_assert_same( array( null, null, '19.00' ), array( $edited['materials'], $edited['materials_total'], $edited['total_cost'] ), 'materials sent empty removes them; the box costs its label' );

fst_assert_error( $service->set_label_cost( $added['shipment_id'], array() ), 'fst_no_cost_fields', 'nothing sent at all is still an error' );

// ---------------------------------------------------------------------------
// Suggested foam and paper cost.
// ---------------------------------------------------------------------------
echo "Suggestions\n";

$foam_supply = FST_Supplies::supply_for_role( 'foam', $supplies );
$sheet       = (object) array( 'id' => 1, 'supply_id' => 4, 'purchase_date' => '2026-10-01', 'qty' => '2.00', 'total_paid' => '24.00' ); // 12.00 a sheet.

$suggest = FST_Supplies::foam_suggestion( $small, $foam_supply, $sheet, '15' );
// 10x10x10: 2(100 + 100 + 100) = 600 sq in = 4.1667 sq ft. A sheet is 32 sq ft at 12.00 = 0.375 a sq ft.
// 4.1667 x 0.375 = 1.5625, plus 15% = 1.796875.
fst_assert_same(
    array( 'cost' => '1.80', 'box_sqft' => 4.17, 'sheet_sqft' => 32.0, 'price_per_sqft' => 0.375, 'waste_percent' => 15.0, 'text' => '4.2 sq ft of a 32 sq ft sheet' ),
    $suggest,
    'foam: the surface of all six sides, at the latest price per square foot, plus the waste'
);
fst_assert_same( '1.56', FST_Supplies::foam_suggestion( $small, $foam_supply, $sheet, '0' )['cost'], 'without waste: 1.5625 rounds to 1.56' );
fst_assert_same( '2.34', FST_Supplies::foam_suggestion( $small, $foam_supply, $sheet, '50' )['cost'], 'with 50% waste: 2.34375 rounds to 2.34' );

$suggest = FST_Supplies::foam_suggestion( $large, $foam_supply, $sheet, '15' );
// 16x16x16: 2 x 3 x 256 = 1536 sq in = 10.6667 sq ft x 0.375 x 1.15 = 4.6.
fst_assert_same( array( '4.60', '10.7 sq ft of a 32 sq ft sheet' ), array( $suggest['cost'], $suggest['text'] ), 'a bigger box takes more of the sheet' );

$half_sheet                 = $foam_supply;
$half_sheet['sheet_length'] = '4';
fst_assert_same( array( '3.59', '4.2 sq ft of a 16 sq ft sheet' ), array( FST_Supplies::foam_suggestion( $small, $half_sheet, $sheet, '15' )['cost'], FST_Supplies::foam_suggestion( $small, $half_sheet, $sheet, '15' )['text'] ), 'the sheet size is the foam supply\'s own (4 by 4 here)' );

fst_assert_same( null, FST_Supplies::foam_suggestion( $small, $foam_supply, null, '15' ), 'no foam purchase: no suggestion' );
fst_assert_same( null, FST_Supplies::foam_suggestion( $small, null, $sheet, '15' ), 'no foam supply: no suggestion' );

fst_assert_same( '15', FST_Supplies::get_waste_percent(), 'the waste percent is 15 until it is set' );
fst_assert_same( '12.5', FST_Supplies::get_waste_percent( '12.50' ), 'a set value is kept' );
fst_assert_same( '0', FST_Supplies::get_waste_percent( '0' ), '0 is a value' );
fst_assert_same( '15', FST_Supplies::get_waste_percent( '250' ), 'more than 100 falls back to the default' );
fst_assert_same( '15', FST_Supplies::get_waste_percent( 'some' ), 'so does a word' );

$roll    = (object) array( 'id' => 2, 'supply_id' => 5, 'purchase_date' => '2026-10-01', 'qty' => '1200.00', 'total_paid' => '60.00' ); // 0.05 a foot.
$suggest = FST_Supplies::paper_suggestion( $small, $roll );
fst_assert_same(
    array( 'cost' => '0.15', 'feet' => 3.0, 'price_per_foot' => 0.05, 'text' => '3 ft at $0.0500 a foot' ),
    $suggest,
    'paper: feet per box x the latest price per foot'
);
fst_assert_same( null, FST_Supplies::paper_suggestion( $large, $roll ), 'a box without "feet of paper": no suggestion' );
fst_assert_same( null, FST_Supplies::paper_suggestion( $small, null ), 'no paper purchase: no suggestion' );

// A suggestion is never applied by itself.
fst_assert_same( '2.50', FST_Settings::get_standard_boxes()[0]['foam_cost'], 'the box keeps the foam cost the owner entered, whatever the suggestion says' );

// ---------------------------------------------------------------------------
// Input validation.
// ---------------------------------------------------------------------------
echo "Validation\n";

$live   = FST_Supplies::get_supplies_raw();
$prices = FST_Supplies::get_prices();

fst_assert_error( FST_Supplies::normalize_materials( array( 'lines' => array( array( 'supply_id' => 3, 'qty' => '-1' ) ) ), $live, $prices ), 'fst_invalid_materials', 'a negative quantity' );
fst_assert_error( FST_Supplies::normalize_materials( array( 'lines' => array( array( 'supply_id' => 3, 'qty' => '1.005' ) ) ), $live, $prices ), 'fst_invalid_materials', 'a quantity with three decimals' );
fst_assert_error( FST_Supplies::normalize_materials( array( 'lines' => array( array( 'supply_id' => 3, 'qty' => 'six' ) ) ), $live, $prices ), 'fst_invalid_materials', 'a quantity that is not a number' );
fst_assert_error( FST_Supplies::normalize_materials( array( 'lines' => array( array( 'supply_id' => 99, 'qty' => '1' ) ) ), $live, $prices ), 'fst_unknown_supply', 'an unknown supply id' );
fst_assert_error( FST_Supplies::normalize_materials( array( 'lines' => array( array( 'supply' => 'Bubble wrap', 'qty' => '1' ) ) ), $live, $prices ), 'fst_unknown_supply', 'an unknown supply name' );
fst_assert_error( FST_Supplies::normalize_materials( array( 'lines' => array( array( 'qty' => '1' ) ) ), $live, $prices ), 'fst_unknown_supply', 'a line without a supply' );
fst_assert_error( FST_Supplies::normalize_materials( array( 'lines' => 'two bags' ), $live, $prices ), 'fst_invalid_materials', 'lines that are not a list' );
fst_assert_error( FST_Supplies::normalize_materials( array( 'lines' => array( 'two bags' ) ), $live, $prices ), 'fst_invalid_materials', 'a line that is not a line' );
fst_assert_error( FST_Supplies::normalize_materials( array( 'foam_cost' => '-2' ), $live, $prices ), 'fst_invalid_materials', 'a negative foam cost' );
fst_assert_error( FST_Supplies::normalize_materials( array( 'paper_cost' => 'cheap' ), $live, $prices ), 'fst_invalid_materials', 'a paper cost that is not money' );
fst_assert_error( FST_Supplies::normalize_materials( array( 'foam_cost' => '$' ), $live, $prices ), 'fst_invalid_materials', 'a currency sign on its own, as for the label cost' );
fst_assert_error( FST_Supplies::normalize_materials( array( 'lines' => array( array( 'supply_id' => 3, 'qty' => 1, 'unit_cost' => '-0.5' ) ) ), $live, $prices ), 'fst_invalid_materials', 'a negative unit cost' );
fst_assert_error( FST_Supplies::normalize_materials( 'not json', $live, $prices ), 'fst_invalid_materials', 'text that is not JSON' );
fst_assert_error( FST_Supplies::normalize_materials( 12, $live, $prices ), 'fst_invalid_materials', 'a number' );

fst_assert_same( null, FST_Supplies::normalize_materials( null, $live, $prices ), 'null removes the record' );
fst_assert_same( null, FST_Supplies::normalize_materials( '  ', $live, $prices ), 'so does an empty string' );

$record = FST_Supplies::normalize_materials( '{"lines":[{"supply_id":3,"qty":2.5},{"supply":"heat PACK","qty":"1"},{"name":"Fish bag (4 mil)","qty":"1.5"},{"supply_id":2,"qty":0}],"foam_cost":"$1,001.5","paper_cost":""}', $live, $prices );
fst_assert_same(
    array( array( '4 x Fish bag (4 mil) @ 0.1500 = 0.60', '1 x Heat pack @ 1.0000 = 1.00' ), '1001.50', '0.00', '1003.10' ),
    array( fst_test_lines( $record ), $record['foam_cost'], $record['paper_cost'], $record['total'] ),
    'a JSON string works; a supply named twice is added up; qty 0 removes the line; money follows the label cost rules; an empty amount is 0.00'
);

$record = FST_Supplies::normalize_materials( array(), $live, $prices );
fst_assert_same( array( array(), '0.00', '0.00', '0.00' ), array( $record['lines'], $record['foam_cost'], $record['paper_cost'], $record['total'] ), 'an empty object records "nothing used" — a record of zero, which is not the same as no record' );

// Rounding: 3 bags at 0.1319 is 0.3957 -> 0.40; never fractions of a cent.
$record = FST_Supplies::normalize_materials( array( 'lines' => array( array( 'supply_id' => 3, 'qty' => 3, 'unit_cost' => '0.1319' ) ) ), $live, $prices );
fst_assert_same( array( '0.1319', '0.40', '0.40' ), array( $record['lines'][0]['unit_cost'], $record['lines'][0]['cost'], $record['total'] ), 'a line is rounded to cents, half up' );

// add_tracking() refuses bad materials before anything is saved.
$fst_test_orders[109] = new WC_Order( 109, array( 50 ) );
$rows_before          = count( $wpdb->tables['wp_fst_shipments'] );
fst_assert_error(
    $service->add_tracking( array( 'order_id' => 109, 'tracking_number' => 'BOX-9', 'carrier' => 'ups', 'line_item_ids' => array( 50 ), 'materials' => array( 'lines' => array( array( 'supply' => 'Glitter', 'qty' => 1 ) ) ) ) ),
    'fst_unknown_supply',
    'add_tracking with an unknown supply is refused'
);
fst_assert_same( $rows_before, count( $wpdb->tables['wp_fst_shipments'] ), 'and no shipment row was written' );

$before = FST_Supplies::get_box_materials( 'BOX-8' );
fst_assert_error( $service->set_label_cost( $new_box['shipment_id'], array( 'label_cost' => '5', 'materials' => array( 'foam_cost' => 'x' ) ) ), 'fst_invalid_materials', 'set_label_cost with bad materials is refused' );
fst_assert_same( array( null, $before ), array( FST_Shipment::get_box_cost( 'BOX-8' )['label_cost'], FST_Supplies::get_box_materials( 'BOX-8' ) ), 'and neither the label cost nor the materials changed' );

// A supply that left the list stays editable on a box that has it.
$fst_test_options['fst_supplies'] = array_values( array_filter( $fst_test_options['fst_supplies'], function ( $supply ) {
    return 1 !== $supply['id'];
} ) );
$edited = $service->set_label_cost( $new_box['shipment_id'], array( 'materials' => array( 'lines' => array( array( 'supply_id' => 1, 'qty' => 2 ) ) ) ) );
fst_assert_same( array( '2 x Heat pack @ 1.0000 = 2.00' ), fst_test_lines( $edited['materials'] ), 'a removed supply already on the box can still be changed there' );
fst_assert_error( FST_Supplies::normalize_materials( array( 'lines' => array( array( 'supply_id' => 1, 'qty' => 1 ) ) ), FST_Supplies::get_supplies_raw(), $prices ), 'fst_unknown_supply', 'but it cannot be put on a box that never had it' );

fst_test_finish();
