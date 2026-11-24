<?php
/**
 * Plain-PHP test for the "Import label costs" tool: reading the CSV (BOM,
 * CRLF, quoted fields, pasted spreadsheet cells), finding the columns by
 * their header text, validating each row and applying the result to boxes
 * in an in-memory $wpdb.
 *
 *   php tests/label-cost-import-test.php
 *
 * Exit code 0 = all assertions passed, 1 = at least one failed.
 *
 * @package FisHotel_ShipTracker
 */

require __DIR__ . '/bootstrap.php';

$wpdb  = new FST_Test_WPDB();
$table = 'wp_fst_shipments';

$fst_test_options['fst_standard_boxes'] = array(
    array( 'name' => 'Small', 'length' => '10', 'width' => '10', 'height' => '10' ),
);
$fst_test_options['fst_label_sources']  = array( 'Partner A', 'Partner B' );

// ---------------------------------------------------------------------------
// Reading the text.
// ---------------------------------------------------------------------------
echo "parse_csv\n";

fst_assert_same(
    array( array( 'Tracking #', 'Cost' ), array( '1Z1', '18.40' ) ),
    FST_Label_Cost::parse_csv( "\xEF\xBB\xBFTracking #,Cost\r\n1Z1,18.40\r\n" ),
    'UTF-8 BOM and CRLF line ends are handled'
);
fst_assert_same(
    array( array( 'Tracking #', 'Cost' ), array( '1Z1', '18.40' ) ),
    FST_Label_Cost::parse_csv( "Tracking #,Cost\r1Z1,18.40\r" ),
    'bare CR line ends are handled'
);
fst_assert_same(
    array( array( 'Tracking', 'Note', 'Cost' ), array( '1Z1', 'Box, the "big" one', '1,234.50' ) ),
    FST_Label_Cost::parse_csv( "Tracking,Note,Cost\n\"1Z1\",\"Box, the \"\"big\"\" one\",\"1,234.50\"\n" ),
    'quoted fields keep their commas and doubled quotes'
);
fst_assert_same(
    array( array( 'Tracking', 'Note', 'Cost' ), array( '1Z1', "two\nlines", '5' ) ),
    FST_Label_Cost::parse_csv( "Tracking,Note,Cost\n1Z1,\"two\nlines\",5" ),
    'a line break inside a quoted field stays in the field'
);
fst_assert_same(
    array( array( 'Tracking #', 'Cost' ), array( '1Z1', '18.40' ) ),
    FST_Label_Cost::parse_csv( "Tracking #\tCost\n1Z1\t18.40\n" ),
    'cells pasted from a spreadsheet (tab-separated) are read'
);
fst_assert_same(
    array( array( 'Tracking #', 'Cost' ), array( '1Z1', '18.40' ) ),
    FST_Label_Cost::parse_csv( "Tracking #;Cost\n1Z1;18.40\n" ),
    'a semicolon-separated export is read'
);
fst_assert_same(
    array( array( 'Tracking #', 'Cost' ), array(), array( '1Z1', '18.40' ) ),
    FST_Label_Cost::parse_csv( "Tracking #,Cost\n\n 1Z1 , 18.40 \n" ),
    'cells are trimmed; a blank line is an empty row'
);
fst_assert_same( array(), FST_Label_Cost::parse_csv( "\xEF\xBB\xBF  \r\n" ), 'a file with nothing in it has no rows' );

if ( function_exists( 'mb_convert_encoding' ) ) {
    fst_assert_same(
        array( array( 'Tracking #', 'Cost' ), array( '1Z1', '18.40' ) ),
        FST_Label_Cost::parse_csv( "\xFF\xFE" . mb_convert_encoding( "Tracking #\tCost\r\n1Z1\t18.40\r\n", 'UTF-16LE', 'UTF-8' ) ),
        'a UTF-16 "Unicode text" export is converted'
    );
}

// ---------------------------------------------------------------------------
// Finding the columns.
// ---------------------------------------------------------------------------
echo "detect_csv_columns\n";

$tracking_headers = array( 'tracking', 'Tracking', 'Tracking Number', 'TRACKING NUMBER', 'Tracking #', 'tracking_number', 'Tracking No.', 'Carrier Tracking Number' );
foreach ( $tracking_headers as $header ) {
    $columns = FST_Label_Cost::detect_csv_columns( array( 'Order', $header, 'Cost' ) );
    fst_assert_same( 1, $columns['tracking'], 'tracking column found by header "' . $header . '"' );
}

$cost_headers = array( 'cost', 'Cost', 'Amount', 'Total', 'Charge', 'Price', 'Label Cost', 'Total Charge', 'Cost ($)', 'Amount Paid', 'Shipping Cost (USD)' );
foreach ( $cost_headers as $header ) {
    $columns = FST_Label_Cost::detect_csv_columns( array( 'Tracking #', 'Service', $header ) );
    fst_assert_same( 2, $columns['cost'], 'cost column found by header "' . $header . '"' );
}

$columns = FST_Label_Cost::detect_csv_columns( array( 'Tracking Number', 'Order Total', 'Insurance Cost', 'Label Cost' ) );
fst_assert_same( 3, $columns['cost'], 'an exact "Label Cost" wins over "Order Total" and "Insurance Cost"' );

$columns = FST_Label_Cost::detect_csv_columns( array( 'Tracking Number', 'Base Charge', 'Fuel Surcharge', 'Total Charge' ) );
fst_assert_same( 3, $columns['cost'], '"Total Charge" wins over "Base Charge" and "Fuel Surcharge"' );

$columns = FST_Label_Cost::detect_csv_columns( array( 'Tracking Status', 'Tracking URL', 'Tracking Number', 'Cost' ) );
fst_assert_same( 2, $columns['tracking'], '"Tracking Number" wins over "Tracking Status" / "Tracking URL"' );

$columns = FST_Label_Cost::detect_csv_columns( array( 'Tracking URL', 'Cost' ) );
fst_assert_same( null, $columns['tracking'], 'a tracking URL column is not a tracking number column' );

$columns = FST_Label_Cost::detect_csv_columns( array( 'Tracking #', 'Order Total' ) );
fst_assert_same( null, $columns['cost'], 'the customer\'s order total is not taken for the label cost' );

$columns = FST_Label_Cost::detect_csv_columns( array( 'Date', 'Tracking #', 'Weight (lb)', 'Dimensions', 'Source', 'Total' ) );
fst_assert_same(
    array( 'tracking' => 1, 'cost' => 5, 'weight' => 2, 'dims' => 3, 'source' => 4, 'cost_candidates' => array( 5 ) ),
    $columns,
    'optional weight, dimensions and source columns are found'
);

// More than one header that could be the cost: none is taken.
$ambiguous_headers = array(
    array( 'Order #', 'Tracking', 'Customer Paid Amount', 'Label Amount' ),
    array( 'Order #', 'Tracking', 'Shipping Charged To Customer', 'Label Charge' ),
    array( 'Order #', 'Tracking', 'Amount Paid', 'Label Amount' ),
    array( 'Order #', 'Tracking', 'Cost', 'Total' ),
    array( 'Order #', 'Tracking', 'Cost', 'Cost' ),
);
foreach ( $ambiguous_headers as $header ) {
    $columns = FST_Label_Cost::detect_csv_columns( $header );
    fst_assert_same(
        array( null, array( 2, 3 ) ),
        array( $columns['cost'], $columns['cost_candidates'] ),
        '"' . $header[2] . '" and "' . $header[3] . '" both fit: no cost column is picked'
    );
}

$columns = FST_Label_Cost::detect_csv_columns( array( 'Tracking Number', 'Order Total', 'Insurance Cost', 'Fuel Surcharge', 'Base Charge', 'Total Charge' ) );
fst_assert_same( array( 5 ), $columns['cost_candidates'], 'order, insurance, surcharge and base columns are not candidates' );

$columns = FST_Label_Cost::detect_csv_columns( array( 'Tracking Total', 'Cost' ) );
fst_assert_same( array( 0, 1, array( 1 ) ), array( $columns['tracking'], $columns['cost'], $columns['cost_candidates'] ), 'the tracking column is not a cost candidate' );

$columns = FST_Label_Cost::detect_csv_columns( array( 'Tracking #', 'Cost', 'Box' ) );
fst_assert_same( 2, $columns['dims'], 'a "Box" column is the box size' );

$columns = FST_Label_Cost::detect_csv_columns( array( 'Tracking #', 'Cost', 'Weight (oz)' ) );
fst_assert_same( null, $columns['weight'], 'a weight column in ounces is left alone, not converted' );

$columns = FST_Label_Cost::detect_csv_columns( array( 'Tracking #', 'Cost', 'Length', 'Width', 'Height' ) );
fst_assert_same( array( 2, 3, 4 ), $columns['dims'], 'separate Length / Width / Height columns make the box size' );

$columns = FST_Label_Cost::detect_csv_columns( array( 'Name', 'City' ) );
fst_assert_same( array( null, null ), array( $columns['tracking'], $columns['cost'] ), 'neither column in an unrelated file' );

// ---------------------------------------------------------------------------
// Validating the rows.
// ---------------------------------------------------------------------------
echo "read_import\n";

$csv = implode( "\r\n", array(
    "\xEF\xBB\xBFOrder,Tracking Number,Service,Total Charge,Weight,Box",
    '34183,1Z50W72W0115498880,Next Day Air,"$1,018.40",11 lbs,Small',   // Row 2: valid.
    '34184,1z 50w 72w 011 549 8881,Ground,7,,12 x 10 x 8',              // Row 3: valid; spaces removed.
    '',                                                                // Blank line.
    '34185,9.40011E+21,Priority,8.10,,',                                // Row 5: scientific notation.
    '34186,,Priority,8.10,,',                                           // Row 6: no tracking number.
    '34187,1Z50W72W0115498882,Ground,-4.00,,',                          // Row 7: negative cost.
    '34188,1Z50W72W0115498883,Ground,n/a,,',                            // Row 8: non-numeric cost.
    '34189,1Z50W72W0115498884,Ground,,,',                               // Row 9: no cost.
    '34190,="1Z50W72W0115498885",Ground,12.345,heavy,Flat Rate Bag',    // Row 10: valid; weight / box unreadable.
    '34191,\'9400111899223197428490,Priority,5.5,2,10x10x10',           // Row 11: valid; leading apostrophe.
) );

$read = FST_Label_Cost::read_import( $csv, 'Partner B' );

fst_assert_same( '', $read['error'], 'the file is readable' );
fst_assert_same( 9, $read['rows'], 'nine data rows (the blank line is not counted)' );
fst_assert_same(
    array( 'tracking' => 'Tracking Number', 'cost' => 'Total Charge', 'weight' => 'Weight', 'dims' => 'Box', 'source' => null ),
    $read['columns'],
    'the report names the columns that were used'
);

fst_assert_same(
    array(
        array(
            'row'             => 2,
            'tracking_number' => '1Z50W72W0115498880',
            'fields'          => array( 'label_cost' => '1018.40', 'box_weight' => '11.00', 'box_dims' => '10x10x10', 'label_source' => 'Partner B' ),
        ),
        array(
            'row'             => 3,
            'tracking_number' => '1z50w72w0115498881',
            'fields'          => array( 'label_cost' => '7.00', 'box_dims' => '12x10x8', 'label_source' => 'Partner B' ),
        ),
        array(
            'row'             => 10,
            'tracking_number' => '1Z50W72W0115498885',
            'fields'          => array( 'label_cost' => '12.35', 'label_source' => 'Partner B' ),
        ),
        array(
            'row'             => 11,
            'tracking_number' => '9400111899223197428490',
            'fields'          => array( 'label_cost' => '5.50', 'box_weight' => '2.00', 'box_dims' => '10x10x10', 'label_source' => 'Partner B' ),
        ),
    ),
    $read['entries'],
    'valid rows: money / weight / size normalised, box name stored as its size, whole-file source applied'
);
fst_assert_same( 1, $read['ignored_optional'], 'one row had an unreadable weight / box size but its cost is still used' );

$invalid_rows = array();
foreach ( $read['invalid'] as $invalid ) {
    $invalid_rows[ $invalid['row'] ] = $invalid['tracking_number'];
}
fst_assert_same(
    array( 5 => '9.40011E+21', 6 => '', 7 => '1Z50W72W0115498882', 8 => '1Z50W72W0115498883', 9 => '1Z50W72W0115498884' ),
    $invalid_rows,
    'invalid: scientific notation, no tracking number, negative, non-numeric and missing cost'
);
fst_assert_same( true, false !== strpos( $read['invalid'][0]['reason'], 'scientific notation' ), 'the mangled tracking number is reported as such, not guessed' );

$read = FST_Label_Cost::read_import( "Tracking,Cost,Source\n1Z1,5,partner a\n1Z2,6,\n1Z3,7,Somewhere else\n", 'Partner B' );
fst_assert_same(
    array( array( 'label_cost' => '5.00', 'label_source' => 'Partner A' ), array( 'label_cost' => '6.00' ), array( 'label_cost' => '7.00', 'label_source' => 'Somewhere else' ) ),
    array( $read['entries'][0]['fields'], $read['entries'][1]['fields'], $read['entries'][2]['fields'] ),
    'a source column wins over the whole-file choice; an empty cell records no source'
);

$read = FST_Label_Cost::read_import( "Tracking,Cost\n1Z1,5\n", '' );
fst_assert_same( array( 'label_cost' => '5.00' ), $read['entries'][0]['fields'], 'no source column and no choice: source is left alone' );

$read = FST_Label_Cost::read_import( "Tracking,Cost,Length,Width,Height\n1Z1,5,16,12,8\n1Z2,6,,,\n" );
fst_assert_same( '16x12x8', $read['entries'][0]['fields']['box_dims'], 'Length / Width / Height cells are joined into the box size' );
fst_assert_same( array( 'label_cost' => '6.00' ), $read['entries'][1]['fields'], 'empty Length / Width / Height cells record no size' );
fst_assert_same( 0, $read['ignored_optional'], 'and are not counted as unreadable' );

$read = FST_Label_Cost::read_import( "Tracking,Cost\n1Z1,5\n1z1,5.00\n1Z2,6\n1Z2,9\n1Z3,1\n" );
fst_assert_same( 1, $read['repeated'], 'a row repeating the same tracking number and cost is skipped' );
fst_assert_same( array( '1Z1', '1Z3' ), array( $read['entries'][0]['tracking_number'], $read['entries'][1]['tracking_number'] ), 'the first of the repeats is kept' );
fst_assert_same( array( 4, 5 ), array( $read['invalid'][0]['row'], $read['invalid'][1]['row'] ), 'a tracking number listed with two different costs is invalid on both rows' );

// Two cost-like columns: 45.00 is what the customer paid, 53.73 the label.
$read = FST_Label_Cost::read_import( "Order #,Tracking,Customer Paid Amount,Label Amount\n34183,1Z1,45.00,53.73\n" );
fst_assert_same(
    array( true, true ),
    array( false !== strpos( $read['error'], '"Customer Paid Amount", "Label Amount"' ), false !== strpos( $read['error'], 'Nothing was imported' ) ),
    'two columns that could be the cost: the error names both'
);
fst_assert_same( array( array(), 0 ), array( $read['entries'], $read['rows'] ), 'and no row is read, so neither amount can be saved' );

$read = FST_Label_Cost::read_import( "Order #,Tracking,Label Amount\n34183,1Z1,53.73\n" );
fst_assert_same( array( '', '53.73' ), array( $read['error'], $read['entries'][0]['fields']['label_cost'] ), 'with the other column removed the same file imports' );

$read = FST_Label_Cost::read_import( "Tracking,Cost\n1Z1,\$\n1Z2,USD\n1Z3,\$ 5\n" );
fst_assert_same(
    array( array( 2, 3 ), array( '1Z3' ) ),
    array( array( $read['invalid'][0]['row'], $read['invalid'][1]['row'] ), array( $read['entries'][0]['tracking_number'] ) ),
    'a cost cell holding only a currency sign is invalid'
);

$read = FST_Label_Cost::read_import( "Name,City\nA,B\n" );
fst_assert_same( true, '' !== $read['error'], 'no tracking / cost column: an error, nothing read' );
fst_assert_same( array(), $read['entries'], 'and no entries' );

$read = FST_Label_Cost::read_import( '' );
fst_assert_same( 'The file is empty.', $read['error'], 'an empty file is reported' );

// ---------------------------------------------------------------------------
// What a row may write.
// ---------------------------------------------------------------------------
echo "plan_import_write\n";

$empty_box = array( 'label_cost' => null, 'box_weight' => null, 'box_dims' => null, 'label_source' => null );
$full_row  = array( 'label_cost' => '18.40', 'box_weight' => '11.00', 'box_dims' => '10x10x10', 'label_source' => 'Partner A' );

fst_assert_same(
    array( 'result' => 'matched', 'write' => $full_row ),
    FST_Label_Cost::plan_import_write( $empty_box, $full_row, false ),
    'nothing recorded: every field of the row is written'
);

$costed_box = array( 'label_cost' => '20.00', 'box_weight' => null, 'box_dims' => '12x12x12', 'label_source' => null );
fst_assert_same(
    array( 'result' => 'had_cost', 'write' => array( 'box_weight' => '11.00', 'label_source' => 'Partner A' ) ),
    FST_Label_Cost::plan_import_write( $costed_box, $full_row, false ),
    'a cost is recorded: it is kept, only the empty fields are filled'
);
fst_assert_same(
    array( 'result' => 'matched', 'write' => $full_row ),
    FST_Label_Cost::plan_import_write( $costed_box, $full_row, true ),
    'overwrite ticked: the row replaces what is recorded'
);
fst_assert_same(
    array( 'result' => 'matched', 'write' => array( 'label_cost' => '18.40' ) ),
    FST_Label_Cost::plan_import_write( array( 'label_cost' => null, 'box_weight' => '9.00', 'box_dims' => '8x8x8', 'label_source' => 'Partner B' ), array( 'label_cost' => '18.40' ), true ),
    'fields the row does not give are never cleared, even with overwrite'
);

// ---------------------------------------------------------------------------
// The whole import against stored shipments.
// ---------------------------------------------------------------------------
echo "import\n";

// A shared box (two fan-out rows, nothing recorded), a box with a cost, and
// a box the file does not mention.
$wpdb->insert( $table, array( 'order_id' => 201, 'tracking_number' => '1Z50W72W0115498880', 'status' => 'delivered', 'updated_at' => '2026-10-01 10:00:00', 'ship_date' => '2026-09-29 00:00:00' ) );
$wpdb->insert( $table, array( 'order_id' => 202, 'tracking_number' => '1Z50W72W0115498880', 'status' => 'delivered', 'updated_at' => '2026-10-01 10:00:00', 'ship_date' => '2026-09-29 00:00:00' ) );
$wpdb->insert( $table, array( 'order_id' => 203, 'tracking_number' => '1Z50W72W0115498881', 'status' => 'in_transit', 'updated_at' => '2026-10-02 11:00:00', 'label_cost' => '10.00' ) );
$wpdb->insert( $table, array( 'order_id' => 204, 'tracking_number' => '1Z50W72W0115498889', 'status' => 'in_transit', 'updated_at' => '2026-10-03 12:00:00' ) );

$fst_test_now = '2026-11-10 08:00:00';

/**
 * Every stored row with the four cost fields taken out — what an import
 * must leave exactly as it was.
 */
function fst_test_rows_without_cost_fields() {
    global $wpdb, $table;
    $rows = array();
    foreach ( $wpdb->tables[ $table ] as $row ) {
        foreach ( FST_Label_Cost::FIELDS as $field ) {
            unset( $row[ $field ] );
        }
        $rows[] = $row;
    }
    return $rows;
}

$before = fst_test_rows_without_cost_fields();

$csv = implode( "\n", array(
    'Tracking #,Cost,Weight,Box',
    '1Z50W72W0115498880,42.50,20,16x16x16',   // Shared box, nothing recorded.
    '1Z50W72W0115498881,13.75,5,Small',       // Already has a cost.
    '1Z50W72W0115498887,9.00,,',              // Not in ShipTracker.
    '9.40011E+21,8.10,,',                     // Mangled by a spreadsheet.
    '1Z50W72W0115498886,-1,,',                // Negative cost.
) );

$result = FST_Label_Cost::import( $csv, 'Partner A', false );

fst_assert_same(
    array( 5, 1, 1, 1, 2 ),
    array( $result['rows'], $result['matched'], $result['had_cost'], $result['not_found'], $result['invalid'] ),
    'report: 5 rows — 1 matched, 1 already had a cost, 1 not found, 2 invalid'
);
fst_assert_same( 1, $result['details_filled'], 'the box that kept its cost had its empty fields filled' );
fst_assert_same( array( array( 'row' => 4, 'tracking_number' => '1Z50W72W0115498887' ) ), $result['not_found_list'], 'the missing tracking number is listed with its row' );
fst_assert_same( array( 5, 6 ), array( $result['invalid_list'][0]['row'], $result['invalid_list'][1]['row'] ), 'the invalid rows are listed' );

$box = FST_Shipment::get_box_cost( '1Z50W72W0115498880' );
fst_assert_same(
    array( '42.50', '20.00', '16x16x16', 'Partner A' ),
    array( $box['label_cost'], $box['box_weight'], $box['box_dims'], $box['label_source'] ),
    'the shared box got its cost, weight, size and the whole-file source'
);
$holding = 0;
foreach ( $wpdb->tables[ $table ] as $row ) {
    if ( '1Z50W72W0115498880' === $row['tracking_number'] && null !== $row['label_cost'] ) {
        $holding++;
    }
}
fst_assert_same( 1, $holding, 'on one of its two rows only' );

$box = FST_Shipment::get_box_cost( '1Z50W72W0115498881' );
fst_assert_same(
    array( '10.00', '5.00', '10x10x10', 'Partner A' ),
    array( $box['label_cost'], $box['box_weight'], $box['box_dims'], $box['label_source'] ),
    'the recorded cost of 10.00 was kept; weight, size and source were filled in'
);

$box = FST_Shipment::get_box_cost( '1Z50W72W0115498889' );
fst_assert_same( null, $box['label_cost'], 'a box the file does not mention is untouched' );

fst_assert_same( $before, fst_test_rows_without_cost_fields(), 'nothing but the four cost fields changed on any row (status, dates, updated_at …)' );
fst_assert_same( 4, count( $wpdb->tables[ $table ] ), 'no row was created or removed' );

$result = FST_Label_Cost::import( $csv, 'Partner B', true );
fst_assert_same( array( 2, 0 ), array( $result['matched'], $result['had_cost'] ), 'overwrite ticked: both existing boxes are matched' );
$box = FST_Shipment::get_box_cost( '1Z50W72W0115498881' );
fst_assert_same( array( '13.75', 'Partner B' ), array( $box['label_cost'], $box['label_source'] ), 'the recorded cost and source were replaced' );
fst_assert_same( $before, fst_test_rows_without_cost_fields(), 'still nothing else changed' );

$result = FST_Label_Cost::import( "Name,City\nA,B\n", '', true );
fst_assert_same( true, '' !== $result['error'], 'a file without the columns reports an error' );
fst_assert_same( array( 0, 0 ), array( $result['matched'], $result['rows'] ), 'and changes nothing' );

// The wrong-column case end to end: the customer's 45.00 must not replace
// (or become) the label cost of a box, even with overwrite ticked.
$stored = $wpdb->tables[ $table ];
$result = FST_Label_Cost::import( "Order #,Tracking,Customer Paid Amount,Label Amount\n203,1Z50W72W0115498881,45.00,53.73\n204,1Z50W72W0115498889,45.00,53.73\n", '', true );
fst_assert_same( true, false !== strpos( $result['error'], 'More than one column could be the label cost' ), 'a file with two cost-like columns is refused' );
fst_assert_same( array( 0, 0 ), array( $result['matched'], $result['had_cost'] ), 'nothing is matched' );
fst_assert_same( $stored, $wpdb->tables[ $table ], 'and no row is written' );

// A large file must not be cut off by PHP's time limit part-way through.
ini_set( 'max_execution_time', '30' );
$result = FST_Label_Cost::import( "Tracking,Cost\n1Z50W72W0115498889,7.00\n" );
fst_assert_same( array( 1, '0' ), array( $result['matched'], (string) ini_get( 'max_execution_time' ) ), 'the import lifts PHP\'s time limit before it writes' );

fst_test_finish();
