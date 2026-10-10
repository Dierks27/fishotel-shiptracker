<?php
/**
 * Plain-PHP test for FST_Order::normalize_add_tracking_args().
 *
 * Runs without WordPress — the handful of WP functions the normaliser and
 * FST_Carrier::detect_carrier() touch are stubbed below.
 *
 *   php tests/add-tracking-args-test.php
 *
 * Exit code 0 = all assertions passed, 1 = at least one failed.
 *
 * @package FisHotel_ShipTracker
 */

if ( 'cli' !== PHP_SAPI ) {
    echo "Run this from the command line.\n";
    exit( 1 );
}

// Satisfy the `defined( 'ABSPATH' ) || exit;` guards in the plugin files.
define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// ---------------------------------------------------------------------------
// WordPress stubs (minimal, behaviour-matching for what the normaliser uses).
// ---------------------------------------------------------------------------

/** @var array Options returned by get_option() during a test. */
$fst_test_options = array();

function absint( $maybeint ) {
    return abs( (int) $maybeint );
}

function sanitize_text_field( $str ) {
    $str = (string) $str;
    $str = strip_tags( $str );
    $str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
    return trim( $str );
}

function get_option( $option, $default = false ) {
    global $fst_test_options;
    return array_key_exists( $option, $fst_test_options ) ? $fst_test_options[ $option ] : $default;
}

function __( $text, $domain = 'default' ) {
    return $text;
}

function is_wp_error( $thing ) {
    return $thing instanceof WP_Error;
}

class WP_Error {
    private $code;
    private $message;
    private $data;

    public function __construct( $code = '', $message = '', $data = '' ) {
        $this->code    = $code;
        $this->message = $message;
        $this->data    = $data;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }

    public function get_error_data() {
        return $this->data;
    }
}

require_once ABSPATH . 'includes/carriers/class-fst-carrier.php';
require_once ABSPATH . 'includes/class-fst-order.php';

// ---------------------------------------------------------------------------
// Tiny assertion harness.
// ---------------------------------------------------------------------------

$fst_test_passed = 0;
$fst_test_failed = 0;

function fst_assert_same( $expected, $actual, $label ) {
    global $fst_test_passed, $fst_test_failed;
    if ( $expected === $actual ) {
        $fst_test_passed++;
        echo "  ok   {$label}\n";
        return;
    }
    $fst_test_failed++;
    echo "  FAIL {$label}\n";
    echo '       expected: ' . var_export( $expected, true ) . "\n";
    echo '       actual:   ' . var_export( $actual, true ) . "\n";
}

function fst_assert_error( $result, $expected_code, $expected_message, $label ) {
    global $fst_test_failed;
    if ( ! is_wp_error( $result ) ) {
        $fst_test_failed++;
        echo "  FAIL {$label}\n";
        echo '       expected WP_Error, got: ' . var_export( $result, true ) . "\n";
        return;
    }
    fst_assert_same( $expected_code, $result->get_error_code(), "{$label}: code" );
    fst_assert_same( $expected_message, $result->get_error_message(), "{$label}: message" );
}

$ups_number  = '1Z50W72W0115498880';      // UPS: 1Z + 16 alphanumerics.
$usps_number = '94001234567890123456';    // USPS: 20 digits.

// ---------------------------------------------------------------------------
// Carrier detection.
// ---------------------------------------------------------------------------
echo "Carrier detection\n";

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => 34183,
    'tracking_number' => $ups_number,
    'carrier'         => 'auto',
    'line_item_ids'   => array( 123 ),
) );
fst_assert_same( 'ups', $result['carrier'], 'auto + 1Z number detects ups' );
fst_assert_same( $ups_number, $result['tracking_number'], 'tracking number passes through untouched' );

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => '34183',
    'tracking_number' => $usps_number,
    'carrier'         => 'auto',
    'line_item_ids'   => array( 123 ),
) );
fst_assert_same( 'usps', $result['carrier'], 'auto + 20-digit number detects usps' );
fst_assert_same( 34183, $result['order_id'], 'string order id becomes int' );

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => 34183,
    'tracking_number' => $ups_number,
    'line_item_ids'   => array( 123 ),
) );
fst_assert_same( 'ups', $result['carrier'], 'missing carrier defaults to auto' );

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => 34183,
    'tracking_number' => $usps_number,
    'carrier'         => '',
    'line_item_ids'   => array( 123 ),
) );
fst_assert_same( 'usps', $result['carrier'], 'empty carrier counts as auto' );

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => 34183,
    'tracking_number' => $ups_number,
    'carrier'         => 'usps',
    'line_item_ids'   => array( 123 ),
) );
fst_assert_same( 'usps', $result['carrier'], 'explicit carrier is not overridden by detection' );

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => 34183,
    'tracking_number' => 'NOT-A-KNOWN-FORMAT',
    'carrier'         => 'auto',
    'line_item_ids'   => array( 123 ),
) );
fst_assert_same( 'ups', $result['carrier'], 'unrecognised format falls back to the default carrier (ups)' );

$fst_test_options['fst_default_carrier'] = 'usps';
$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => 34183,
    'tracking_number' => 'NOT-A-KNOWN-FORMAT',
    'carrier'         => 'auto',
    'line_item_ids'   => array( 123 ),
) );
fst_assert_same( 'usps', $result['carrier'], 'unrecognised format honours fst_default_carrier' );
$fst_test_options = array();

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => 34183,
    'tracking_number' => "  <b>{$ups_number}</b>\n",
    'carrier'         => 'auto',
    'line_item_ids'   => array( 123 ),
) );
fst_assert_same( $ups_number, $result['tracking_number'], 'tracking number is sanitised (tags stripped, trimmed)' );
fst_assert_same( 'ups', $result['carrier'], 'detection runs on the sanitised number' );

// ---------------------------------------------------------------------------
// line_item_ids sanitisation.
// ---------------------------------------------------------------------------
echo "line_item_ids sanitisation\n";

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => 34183,
    'tracking_number' => $ups_number,
    'line_item_ids'   => array( '12', 12, '0', 'abc', '15 ', 7, array( 99 ), null ),
) );
fst_assert_same( array( 12, 15, 7 ), $result['line_item_ids'], 'ints cast, zeros/junk/nested dropped, deduped, re-indexed' );

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => 34183,
    'tracking_number' => $ups_number,
    'line_item_ids'   => '12, 15,15,0',
) );
fst_assert_same( array( 12, 15 ), $result['line_item_ids'], 'comma-separated string is accepted' );

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => 34183,
    'tracking_number' => $ups_number,
    'line_item_ids'   => '42',
) );
fst_assert_same( array( 42 ), $result['line_item_ids'], 'scalar string becomes a one-element array' );

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => 34183,
    'tracking_number' => $ups_number,
) );
fst_assert_same( array(), $result['line_item_ids'], 'missing line_item_ids is an empty array (fulfillment fan-out path)' );

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => 34183,
    'tracking_number' => $ups_number,
    'line_item_ids'   => '',
) );
fst_assert_same( array(), $result['line_item_ids'], 'empty string line_item_ids is an empty array' );

// ---------------------------------------------------------------------------
// Required arguments.
// ---------------------------------------------------------------------------
echo "Required arguments\n";

$missing_msg = 'Order ID and tracking number are required.';

fst_assert_error(
    FST_Order::normalize_add_tracking_args( array( 'order_id' => 0, 'tracking_number' => $ups_number ) ),
    'fst_missing_args', $missing_msg, 'order_id 0'
);
fst_assert_error(
    FST_Order::normalize_add_tracking_args( array( 'order_id' => 'abc', 'tracking_number' => $ups_number ) ),
    'fst_missing_args', $missing_msg, 'non-numeric order_id'
);
fst_assert_error(
    FST_Order::normalize_add_tracking_args( array( 'tracking_number' => $ups_number ) ),
    'fst_missing_args', $missing_msg, 'missing order_id'
);
fst_assert_error(
    FST_Order::normalize_add_tracking_args( array( 'order_id' => array( 5 ), 'tracking_number' => $ups_number ) ),
    'fst_missing_args', $missing_msg, 'array order_id'
);
fst_assert_error(
    FST_Order::normalize_add_tracking_args( array( 'order_id' => 34183, 'tracking_number' => '   ' ) ),
    'fst_missing_args', $missing_msg, 'blank tracking number'
);
fst_assert_error(
    FST_Order::normalize_add_tracking_args( array( 'order_id' => 34183 ) ),
    'fst_missing_args', $missing_msg, 'missing tracking number'
);
fst_assert_error(
    FST_Order::normalize_add_tracking_args( array( 'order_id' => 34183, 'tracking_number' => '0' ) ),
    'fst_missing_args', $missing_msg, 'tracking number "0" (empty() semantics, as before 1.9.16)'
);

// ---------------------------------------------------------------------------
// ship_date.
// ---------------------------------------------------------------------------
echo "ship_date\n";

$base = array( 'order_id' => 34183, 'tracking_number' => $ups_number, 'line_item_ids' => array( 1 ) );

$result = FST_Order::normalize_add_tracking_args( $base );
fst_assert_same( null, $result['ship_date'], 'omitted ship_date is null (service uses current time)' );

$result = FST_Order::normalize_add_tracking_args( $base + array( 'ship_date' => '' ) );
fst_assert_same( null, $result['ship_date'], 'empty ship_date is null' );

$result = FST_Order::normalize_add_tracking_args( $base + array( 'ship_date' => ' 2026-10-09 ' ) );
fst_assert_same( '2026-10-09 00:00:00', $result['ship_date'], 'Y-m-d becomes a midnight datetime' );

$date_msg = 'ship_date must be a valid date in Y-m-d format.';
fst_assert_error(
    FST_Order::normalize_add_tracking_args( $base + array( 'ship_date' => '2026-13-40' ) ),
    'fst_invalid_ship_date', $date_msg, 'impossible date'
);
fst_assert_error(
    FST_Order::normalize_add_tracking_args( $base + array( 'ship_date' => '10/09/2026' ) ),
    'fst_invalid_ship_date', $date_msg, 'wrong format'
);
fst_assert_error(
    FST_Order::normalize_add_tracking_args( $base + array( 'ship_date' => '2026-10-09 09:15:00' ) ),
    'fst_invalid_ship_date', $date_msg, 'datetime is rejected (Y-m-d only)'
);

// ---------------------------------------------------------------------------
// Full shape.
// ---------------------------------------------------------------------------
echo "Result shape\n";

$result = FST_Order::normalize_add_tracking_args( array(
    'order_id'        => '34183',
    'tracking_number' => $ups_number,
    'carrier'         => 'auto',
    'line_item_ids'   => array( '123' ),
    'ship_date'       => '2026-10-09',
) );
fst_assert_same(
    array(
        'order_id'        => 34183,
        'tracking_number' => $ups_number,
        'carrier'         => 'ups',
        'line_item_ids'   => array( 123 ),
        'ship_date'       => '2026-10-09 00:00:00',
    ),
    $result,
    'normalised array has exactly the five keys the service reads'
);

// ---------------------------------------------------------------------------
echo "\n{$fst_test_passed} passed, {$fst_test_failed} failed\n";
exit( $fst_test_failed > 0 ? 1 : 0 );
