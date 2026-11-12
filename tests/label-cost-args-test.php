<?php
/**
 * Plain-PHP test for the label cost field normalisation: every money,
 * weight and box-size spelling, standard box names, empty vs absent on an
 * update, and the settings lists the values are checked against.
 *
 *   php tests/label-cost-args-test.php
 *
 * Exit code 0 = all assertions passed, 1 = at least one failed.
 *
 * @package FisHotel_ShipTracker
 */

require __DIR__ . '/bootstrap.php';

// ---------------------------------------------------------------------------
// Money.
// ---------------------------------------------------------------------------
echo "Label cost (money)\n";

$money = array(
    '18.40'       => '18.40',
    '18.4'        => '18.40',
    '18'          => '18.00',
    '  7 '        => '7.00',
    '$18.40'      => '18.40',
    '$ 18.40'     => '18.40',
    '18.40 USD'   => '18.40',
    'usd 18.40'   => '18.40',
    '1,234.56'    => '1234.56',
    '$1,234'      => '1234.00',
    '12,345,678'  => '12345678.00',
    '0'           => '0.00',
    '0.00'        => '0.00',
    '.5'          => '0.50',
    '12.'         => '12.00',
    '007.10'      => '7.10',
    '12.345'      => '12.35',
    '12.344'      => '12.34',
    '1.005'       => '1.01',
    '99999999.99' => '99999999.99',
);
foreach ( $money as $input => $expected ) {
    fst_assert_same( $expected, FST_Label_Cost::parse_money( (string) $input ), 'money "' . $input . '" is ' . $expected );
}

fst_assert_same( '18.40', FST_Label_Cost::parse_money( 18.4 ), 'float 18.4 (JSON number) is 18.40' );
fst_assert_same( '18.00', FST_Label_Cost::parse_money( 18 ), 'int 18 is 18.00' );
fst_assert_same( '1.01', FST_Label_Cost::parse_money( 1.005 ), 'float 1.005 rounds half up to 1.01' );
fst_assert_same( '0.00', FST_Label_Cost::parse_money( 0 ), 'int 0 is a recorded cost of 0.00, not "empty"' );

fst_assert_same( null, FST_Label_Cost::parse_money( '' ), 'empty string is NULL (not recorded)' );
fst_assert_same( null, FST_Label_Cost::parse_money( '   ' ), 'blank string is NULL' );
fst_assert_same( null, FST_Label_Cost::parse_money( null ), 'null is NULL' );

$bad_money = array( '-5', '-0.01', '$-5', '-$5', '(5.00)', 'abc', '12,50', '1,23', '1e3', '12.5.1', '5 dollars', '.', '100000000', '99999999.995' );
foreach ( $bad_money as $input ) {
    fst_assert_error( FST_Label_Cost::parse_money( $input ), 'fst_invalid_label_cost', 'money "' . $input . '" is rejected' );
}
// A currency sign with no amount is a slip, never "empty".
foreach ( array( '$', ' $ ', '$$', 'USD', 'usd', 'US$', '$ USD' ) as $input ) {
    fst_assert_error( FST_Label_Cost::parse_money( $input ), 'fst_invalid_label_cost', 'money "' . $input . '" (no amount) is rejected, not read as empty' );
}
fst_assert_error( FST_Label_Cost::parse_money( -3 ), 'fst_invalid_label_cost', 'int -3 is rejected' );
fst_assert_error( FST_Label_Cost::parse_money( -2.5 ), 'fst_invalid_label_cost', 'float -2.5 is rejected' );
fst_assert_error( FST_Label_Cost::parse_money( INF ), 'fst_invalid_label_cost', 'INF is rejected' );
fst_assert_error( FST_Label_Cost::parse_money( array( 5 ) ), 'fst_invalid_label_cost', 'array is rejected' );
fst_assert_error( FST_Label_Cost::parse_money( true ), 'fst_invalid_label_cost', 'boolean is rejected' );

// ---------------------------------------------------------------------------
// Weight.
// ---------------------------------------------------------------------------
echo "Box weight\n";

$weights = array(
    '11'         => '11.00',
    '11.5'       => '11.50',
    '11lbs'      => '11.00',
    '11 lb'      => '11.00',
    '11.25 LBS'  => '11.25',
    '11 pounds'  => '11.00',
    '0.01'       => '0.01',
);
foreach ( $weights as $input => $expected ) {
    fst_assert_same( $expected, FST_Label_Cost::parse_weight( (string) $input ), 'weight "' . $input . '" is ' . $expected );
}
fst_assert_same( '11.00', FST_Label_Cost::parse_weight( 11 ), 'int 11 is 11.00' );
fst_assert_same( null, FST_Label_Cost::parse_weight( '' ), 'empty weight is NULL' );
fst_assert_same( null, FST_Label_Cost::parse_weight( null ), 'null weight is NULL' );

foreach ( array( '0', '0.00', '0.001', '-1', 'abc', '176 oz', '5 kg', '10000' ) as $input ) {
    fst_assert_error( FST_Label_Cost::parse_weight( $input ), 'fst_invalid_box_weight', 'weight "' . $input . '" is rejected' );
}
foreach ( array( 'lb', ' lbs ', 'pounds', '#' ) as $input ) {
    fst_assert_error( FST_Label_Cost::parse_weight( $input ), 'fst_invalid_box_weight', 'weight "' . $input . '" (no number) is rejected, not read as empty' );
}
fst_assert_error( FST_Label_Cost::parse_weight( 0 ), 'fst_invalid_box_weight', 'int 0 weight is rejected (must be above 0)' );

// ---------------------------------------------------------------------------
// Box dimensions.
// ---------------------------------------------------------------------------
echo "Box dimensions\n";

$no_boxes = array();
$dims     = array(
    '10x10x10'            => '10x10x10',
    '10 x 10 x 10'        => '10x10x10',
    '10*10*10'            => '10x10x10',
    '10X10X10 in'         => '10x10x10',
    '10x10x10in'          => '10x10x10',
    '10x10x10 inches'     => '10x10x10',
    '10" x 10" x 10"'     => '10x10x10',
    '10 in x 10 in x 10 in' => '10x10x10',
    '10 by 10 by 10'      => '10x10x10',
    "10\xC3\x9710\xC3\x9710" => '10x10x10',
    '  16 X 12 x 8  '     => '16x12x8',
    '12.5x10x8.25'        => '12.5x10x8.25',
    '10.0x10.00x10'       => '10x10x10',
    '12.50 x 10 x 8'      => '12.5x10x8',
);
foreach ( $dims as $input => $expected ) {
    fst_assert_same( $expected, FST_Label_Cost::parse_dims( (string) $input, $no_boxes ), 'dims "' . $input . '" is ' . $expected );
}
fst_assert_same( '10x10x10', FST_Label_Cost::parse_dims( array( 10, 10, 10 ), $no_boxes ), 'a list of three numbers is accepted' );
fst_assert_same( '12.5x10x8', FST_Label_Cost::parse_dims( array( '12.50', 10, '8' ), $no_boxes ), 'a list of three number strings is accepted' );
fst_assert_same( null, FST_Label_Cost::parse_dims( '', $no_boxes ), 'empty dims is NULL' );
fst_assert_same( null, FST_Label_Cost::parse_dims( null, $no_boxes ), 'null dims is NULL' );

$bad_dims = array( '10x10', '10x10x10x10', '0x10x10', '10x0x10', '-1x10x10', 'abc', '10,10,10', '10 10 10', 'x10x10', '10xx10', 'Jumbo' );
foreach ( $bad_dims as $input ) {
    fst_assert_error( FST_Label_Cost::parse_dims( $input, $no_boxes ), 'fst_invalid_box_dims', 'dims "' . $input . '" is rejected' );
}
fst_assert_error( FST_Label_Cost::parse_dims( array( 10, 10 ), $no_boxes ), 'fst_invalid_box_dims', 'a list of two numbers is rejected' );
fst_assert_error( FST_Label_Cost::parse_dims( array( 10, '', 10 ), $no_boxes ), 'fst_invalid_box_dims', 'a list with a blank size is rejected' );
fst_assert_error( FST_Label_Cost::parse_dims( true, $no_boxes ), 'fst_invalid_box_dims', 'boolean dims is rejected' );

// ---------------------------------------------------------------------------
// Settings lists: nothing seeded, sanitised on save and on read.
// ---------------------------------------------------------------------------
echo "Settings lists\n";

fst_assert_same( array(), FST_Settings::get_standard_boxes(), 'no standard boxes until the owner adds some' );
fst_assert_same( array(), FST_Settings::get_label_sources(), 'no label sources until the owner adds some' );

$saved_boxes = FST_Settings::sanitize_standard_boxes( array(
    array( 'name' => ' Small ', 'length' => '10', 'width' => '10', 'height' => '10' ),
    array( 'name' => 'Large flat', 'length' => '16.50', 'width' => '12', 'height' => '8' ),
    array( 'name' => 'small', 'length' => '9', 'width' => '9', 'height' => '9' ),      // Same name again.
    array( 'name' => '', 'length' => '12', 'width' => '12', 'height' => '12' ),         // No name.
    array( 'name' => 'Half done', 'length' => '12', 'width' => '', 'height' => '12' ),  // Missing size.
    array( 'name' => 'Zero', 'length' => '0', 'width' => '5', 'height' => '5' ),        // Not above 0.
    array( 'name' => '', 'length' => '', 'width' => '', 'height' => '' ),               // The blank row.
    'junk',
) );
fst_assert_same(
    array(
        array( 'name' => 'Small', 'length' => '10', 'width' => '10', 'height' => '10' ),
        array( 'name' => 'Large flat', 'length' => '16.5', 'width' => '12', 'height' => '8' ),
    ),
    $saved_boxes,
    'only complete rows are kept; a repeated name and the blank row are dropped'
);

$saved_sources = FST_Settings::sanitize_label_sources( array( ' Partner A ', 'Partner B', 'partner a', '', '<b>Partner B</b>', array( 'x' ) ) );
fst_assert_same( array( 'Partner A', 'Partner B' ), $saved_sources, 'sources are trimmed; blanks and repeats (any letter case) are dropped' );

$fst_test_options['fst_standard_boxes'] = $saved_boxes;
$fst_test_options['fst_label_sources']  = $saved_sources;

fst_assert_same(
    array(
        array( 'name' => 'Small', 'length' => '10', 'width' => '10', 'height' => '10', 'dims' => '10x10x10' ),
        array( 'name' => 'Large flat', 'length' => '16.5', 'width' => '12', 'height' => '8', 'dims' => '16.5x12x8' ),
    ),
    FST_Settings::get_standard_boxes(),
    'get_standard_boxes() adds the normalised dims to each box'
);
fst_assert_same( array( 'Partner A', 'Partner B' ), FST_Settings::get_label_sources(), 'get_label_sources() returns the saved names' );

// ---------------------------------------------------------------------------
// Standard box names and label sources.
// ---------------------------------------------------------------------------
echo "Standard box name / label source\n";

fst_assert_same( '10x10x10', FST_Label_Cost::parse_dims( 'Small' ), 'a standard box name is stored as its dimensions' );
fst_assert_same( '16.5x12x8', FST_Label_Cost::parse_dims( '  large FLAT ' ), 'the name matches in any letter case, trimmed' );
fst_assert_same( '9x9x9', FST_Label_Cost::parse_dims( '9x9x9' ), 'a custom size is still accepted when boxes are saved' );
fst_assert_error( FST_Label_Cost::parse_dims( 'Medium' ), 'fst_invalid_box_dims', 'a name that is not on the list is rejected' );

fst_assert_same( 'Partner A', FST_Label_Cost::parse_source( '  Partner A  ' ), 'source is trimmed' );
fst_assert_same( 'Partner A', FST_Label_Cost::parse_source( 'partner a' ), 'a saved source takes the saved spelling' );
fst_assert_same( 'Somewhere else', FST_Label_Cost::parse_source( 'Somewhere else' ), 'free text that is not on the list is allowed' );
fst_assert_same( 'Partner B', FST_Label_Cost::parse_source( '<b>Partner B</b>' ), 'tags are stripped' );
fst_assert_same( str_repeat( 'a', 40 ), FST_Label_Cost::parse_source( str_repeat( 'a', 55 ) ), 'source is cut to 40 characters' );
fst_assert_same( null, FST_Label_Cost::parse_source( '' ), 'empty source is NULL' );
fst_assert_same( null, FST_Label_Cost::parse_source( '   ' ), 'blank source is NULL' );
fst_assert_same( null, FST_Label_Cost::parse_source( array( 'x' ) ), 'array source is NULL' );

// ---------------------------------------------------------------------------
// normalize_fields(): add (all four keys) vs update (absent vs empty).
// ---------------------------------------------------------------------------
echo "normalize_fields: add\n";

fst_assert_same(
    array( 'label_cost' => null, 'box_weight' => null, 'box_dims' => null, 'label_source' => null ),
    FST_Label_Cost::normalize_fields( array() ),
    'add: nothing sent is four NULLs — never 0'
);
fst_assert_same(
    array( 'label_cost' => '18.40', 'box_weight' => '11.00', 'box_dims' => '10x10x10', 'label_source' => 'Partner A' ),
    FST_Label_Cost::normalize_fields( array(
        'label_cost'   => '$18.40',
        'box_weight'   => '11lbs',
        'box_dims'     => 'Small',
        'label_source' => 'partner a',
        'order_id'     => 5,
    ) ),
    'add: every field normalised, other keys ignored'
);
fst_assert_same(
    array( 'label_cost' => '18.40', 'box_weight' => null, 'box_dims' => null, 'label_source' => null ),
    FST_Label_Cost::normalize_fields( array( 'label_cost' => 18.4, 'box_weight' => '', 'box_dims' => '  ' ) ),
    'add: empty values are NULL'
);
fst_assert_error( FST_Label_Cost::normalize_fields( array( 'label_cost' => '-1' ) ), 'fst_invalid_label_cost', 'add: negative cost' );
fst_assert_error( FST_Label_Cost::normalize_fields( array( 'box_weight' => '0' ) ), 'fst_invalid_box_weight', 'add: zero weight' );
fst_assert_error( FST_Label_Cost::normalize_fields( array( 'box_dims' => '10x10' ) ), 'fst_invalid_box_dims', 'add: two sizes' );

echo "normalize_fields: update (absent vs empty)\n";

fst_assert_same( array(), FST_Label_Cost::normalize_fields( array(), true ), 'update: nothing sent changes nothing' );
fst_assert_same(
    array( 'label_cost' => null ),
    FST_Label_Cost::normalize_fields( array( 'label_cost' => '' ), true ),
    'update: an empty value clears that field; absent keys are left out'
);
fst_assert_same(
    array( 'label_cost' => null, 'label_source' => null ),
    FST_Label_Cost::normalize_fields( array( 'label_cost' => null, 'label_source' => '   ' ), true ),
    'update: an explicit null (JSON null) and a blank both clear'
);
fst_assert_same(
    array( 'box_weight' => '12.00', 'box_dims' => '16.5x12x8' ),
    FST_Label_Cost::normalize_fields( array( 'box_dims' => 'Large flat', 'box_weight' => '12 lb' ), true ),
    'update: only the keys sent come back, normalised'
);
fst_assert_same(
    array( 'label_cost' => '0.00' ),
    FST_Label_Cost::normalize_fields( array( 'label_cost' => '0' ), true ),
    'update: 0 is a value, not "empty"'
);
fst_assert_error( FST_Label_Cost::normalize_fields( array( 'label_cost' => 'free' ), true ), 'fst_invalid_label_cost', 'update: non-numeric cost' );
fst_assert_error( FST_Label_Cost::normalize_fields( array( 'label_cost' => '$' ), true ), 'fst_invalid_label_cost', 'update: "$" alone does not clear the cost' );
fst_assert_error( FST_Label_Cost::normalize_fields( array( 'box_weight' => 'lbs' ), true ), 'fst_invalid_box_weight', 'update: "lbs" alone does not clear the weight' );
fst_assert_error( FST_Label_Cost::normalize_fields( array( 'box_dims' => 'Medium' ), true ), 'fst_invalid_box_dims', 'update: unknown box name' );

// ---------------------------------------------------------------------------
// Through FST_Order::normalize_add_tracking_args().
// ---------------------------------------------------------------------------
echo "normalize_add_tracking_args\n";

$base = array( 'order_id' => 34183, 'tracking_number' => '1Z50W72W0115498880', 'line_item_ids' => array( 1 ) );

$result = FST_Order::normalize_add_tracking_args( $base );
fst_assert_same(
    array( null, null, null, null ),
    array( $result['label_cost'], $result['box_weight'], $result['box_dims'], $result['label_source'] ),
    'no cost fields sent: all four NULL'
);

$result = FST_Order::normalize_add_tracking_args( $base + array(
    'label_cost'   => '$1,018.4',
    'box_weight'   => 11,
    'box_dims'     => '10 x 10 x 10',
    'label_source' => 'Partner B',
) );
fst_assert_same(
    array( '1018.40', '11.00', '10x10x10', 'Partner B' ),
    array( $result['label_cost'], $result['box_weight'], $result['box_dims'], $result['label_source'] ),
    'cost fields are normalised alongside the tracking args'
);

fst_assert_error( FST_Order::normalize_add_tracking_args( $base + array( 'label_cost' => '-4' ) ), 'fst_invalid_label_cost', 'negative cost stops the add' );
fst_assert_error( FST_Order::normalize_add_tracking_args( $base + array( 'label_cost' => 'n/a' ) ), 'fst_invalid_label_cost', 'non-numeric cost stops the add' );
fst_assert_error( FST_Order::normalize_add_tracking_args( $base + array( 'box_weight' => '-2' ) ), 'fst_invalid_box_weight', 'negative weight stops the add' );
fst_assert_error( FST_Order::normalize_add_tracking_args( $base + array( 'box_dims' => 'big' ) ), 'fst_invalid_box_dims', 'unreadable box size stops the add' );
fst_assert_error( FST_Order::normalize_add_tracking_args( array( 'label_cost' => '5' ) ), 'fst_missing_args', 'missing order / tracking is still reported first' );

fst_test_finish();
