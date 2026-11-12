<?php
/**
 * Shared set-up for the plain-PHP label cost tests (tests/label-cost-*.php).
 *
 * No WordPress, WooCommerce or MySQL: the WordPress functions the plugin
 * classes touch are stubbed, `$wpdb` is an in-memory fake that understands
 * the few query shapes FST_Shipment uses, and WC_Order is a minimal double.
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
// WordPress stubs.
// ---------------------------------------------------------------------------

/** @var array Options returned by get_option() during a test. */
$fst_test_options = array();

/** @var string What current_time( 'mysql' ) returns; tests move it forward. */
$fst_test_now = '2026-11-02 09:00:00';

/** @var array order id => WC_Order double returned by wc_get_order(). */
$fst_test_orders = array();

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

function add_action() {
    return true;
}

function add_filter() {
    return true;
}

function wp_parse_args( $args, $defaults = array() ) {
    return array_merge( $defaults, (array) $args );
}

function wp_json_encode( $data ) {
    return json_encode( $data );
}

function current_time( $type ) {
    global $fst_test_now;
    return 'mysql' === $type ? $fst_test_now : substr( $fst_test_now, 0, 10 );
}

function wc_get_order( $order_id ) {
    global $fst_test_orders;
    return isset( $fst_test_orders[ (int) $order_id ] ) ? $fst_test_orders[ (int) $order_id ] : false;
}

class WP_Error {
    private $code;
    private $message;

    public function __construct( $code = '', $message = '' ) {
        $this->code    = $code;
        $this->message = $message;
    }

    public function get_error_code() {
        return $this->code;
    }

    public function get_error_message() {
        return $this->message;
    }
}

// ---------------------------------------------------------------------------
// WooCommerce doubles — just what FST_Order::add_tracking() calls.
// ---------------------------------------------------------------------------

class FST_Test_Order_Item {
    public function get_name() {
        return 'Item';
    }

    public function get_quantity() {
        return 1;
    }
}

class WC_Order {
    private $id;
    private $item_ids;
    private $meta;
    private $status;
    public $notes = array();

    public function __construct( $id, array $item_ids = array(), array $meta = array(), $status = 'processing' ) {
        $this->id       = (int) $id;
        $this->item_ids = $item_ids;
        $this->meta     = $meta;
        $this->status   = $status;
    }

    public function get_id() {
        return $this->id;
    }

    public function get_order_number() {
        return (string) $this->id;
    }

    public function get_meta( $key ) {
        return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
    }

    public function get_items() {
        $items = array();
        foreach ( $this->item_ids as $item_id ) {
            $items[ $item_id ] = new FST_Test_Order_Item();
        }
        return $items;
    }

    public function get_status() {
        return $this->status;
    }

    public function set_status( $status, $note = '' ) {
        $this->status = $status;
    }

    public function add_order_note( $note, $is_customer_note = false ) {
        $this->notes[] = $note;
    }

    public function save() {
        return $this->id;
    }
}

// ---------------------------------------------------------------------------
// In-memory $wpdb.
// ---------------------------------------------------------------------------

/**
 * Understands exactly what FST_Shipment needs: insert / update / delete by
 * column => value, and `SELECT * FROM t WHERE a = x [AND b = y] [ORDER BY c
 * ASC|DESC] [LIMIT n]`. Any other query throws, so a test never passes on a
 * query the fake silently ignored. Text is compared without regard to letter
 * case, like MySQL's default collation.
 */
class FST_Test_WPDB {
    public $prefix    = 'wp_';
    public $insert_id = 0;

    /** @var array table => list of rows (column => value). */
    public $tables = array();

    /** Columns of wp_fst_shipments with their defaults, as in the activator. */
    private $shipment_defaults = array(
        'id'                  => 0,
        'order_id'            => 0,
        'tracking_number'     => '',
        'carrier'             => 'ups',
        'status'              => 'unknown',
        'status_detail'       => null,
        'ship_date'           => null,
        'est_delivery'        => null,
        'delivered_date'      => null,
        'last_checked'        => null,
        'last_event_at'       => null,
        'tracking_data'       => null,
        'line_item_ids'       => null,
        'easypost_tracker_id' => '',
        'tracker_provider'    => '',
        'check_count'         => 0,
        'label_cost'          => null,
        'box_weight'          => null,
        'box_dims'            => null,
        'label_source'        => null,
        'created_at'          => null,
        'updated_at'          => null,
    );

    public function prepare( $query, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        $n = 0;
        return preg_replace_callback(
            '/%[ds]/',
            function ( $m ) use ( &$n, $args ) {
                $value = $args[ $n ];
                $n++;
                return '%d' === $m[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
            },
            $query
        );
    }

    public function insert( $table, $data, $format = null ) {
        $defaults = $table === $this->prefix . 'fst_shipments' ? $this->shipment_defaults : array( 'id' => 0 );

        $this->insert_id++;
        $row       = array_merge( $defaults, $data );
        $row['id'] = $this->insert_id;

        $this->tables[ $table ][] = $row;
        return 1;
    }

    public function update( $table, $data, $where ) {
        $changed = 0;
        if ( empty( $this->tables[ $table ] ) ) {
            return 0;
        }
        foreach ( $this->tables[ $table ] as $index => $row ) {
            if ( $this->matches( $row, $where ) ) {
                $this->tables[ $table ][ $index ] = array_merge( $row, $data );
                $changed++;
            }
        }
        return $changed;
    }

    public function delete( $table, $where, $format = null ) {
        $deleted = 0;
        if ( empty( $this->tables[ $table ] ) ) {
            return 0;
        }
        foreach ( $this->tables[ $table ] as $index => $row ) {
            if ( $this->matches( $row, $where ) ) {
                unset( $this->tables[ $table ][ $index ] );
                $deleted++;
            }
        }
        $this->tables[ $table ] = array_values( $this->tables[ $table ] );
        return $deleted;
    }

    public function get_row( $sql ) {
        $rows = $this->get_results( $sql );
        return empty( $rows ) ? null : $rows[0];
    }

    public function get_results( $sql ) {
        $sql = trim( preg_replace( '/\s+/', ' ', $sql ) );

        if ( ! preg_match( '/^SELECT \* FROM (\w+) WHERE (.+?)(?: ORDER BY (\w+) (ASC|DESC))?(?: LIMIT (\d+))?$/', $sql, $m ) ) {
            throw new Exception( 'FST_Test_WPDB cannot run: ' . $sql );
        }

        $where = array();
        foreach ( explode( ' AND ', $m[2] ) as $condition ) {
            if ( ! preg_match( '/^(\w+) = (\d+|\'(?:[^\'\\\\]|\\\\.)*\')$/', trim( $condition ), $c ) ) {
                throw new Exception( 'FST_Test_WPDB cannot run: ' . $sql );
            }
            $where[ $c[1] ] = "'" === $c[2][0] ? stripslashes( substr( $c[2], 1, -1 ) ) : (int) $c[2];
        }

        $rows = array();
        foreach ( isset( $this->tables[ $m[1] ] ) ? $this->tables[ $m[1] ] : array() as $row ) {
            if ( $this->matches( $row, $where ) ) {
                $rows[] = $row;
            }
        }

        if ( ! empty( $m[3] ) ) {
            $column     = $m[3];
            $descending = 'DESC' === $m[4];
            usort(
                $rows,
                function ( $a, $b ) use ( $column, $descending ) {
                    $order = is_numeric( $a[ $column ] ) && is_numeric( $b[ $column ] )
                        ? $a[ $column ] - $b[ $column ]
                        : strcmp( (string) $a[ $column ], (string) $b[ $column ] );
                    if ( 0 === $order || 0.0 === $order ) {
                        $order = $a['id'] - $b['id'];
                    }
                    return $descending ? -$order : $order;
                }
            );
        }

        if ( ! empty( $m[5] ) ) {
            $rows = array_slice( $rows, 0, (int) $m[5] );
        }

        $objects = array();
        foreach ( $rows as $row ) {
            $objects[] = (object) $row;
        }
        return $objects;
    }

    /**
     * A stored row, straight from the table (no query).
     */
    public function row( $table, $id ) {
        foreach ( isset( $this->tables[ $table ] ) ? $this->tables[ $table ] : array() as $row ) {
            if ( (int) $row['id'] === (int) $id ) {
                return $row;
            }
        }
        return null;
    }

    private function matches( array $row, array $where ) {
        foreach ( $where as $column => $value ) {
            if ( ! array_key_exists( $column, $row ) || 0 !== strcasecmp( (string) $row[ $column ], (string) $value ) ) {
                return false;
            }
        }
        return true;
    }
}

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

function fst_assert_error( $result, $expected_code, $label ) {
    global $fst_test_failed;
    if ( ! is_wp_error( $result ) ) {
        $fst_test_failed++;
        echo "  FAIL {$label}\n";
        echo '       expected WP_Error ' . $expected_code . ', got: ' . var_export( $result, true ) . "\n";
        return;
    }
    fst_assert_same( $expected_code, $result->get_error_code(), $label );
}

/**
 * Print the totals and exit 0 (all passed) or 1.
 */
function fst_test_finish() {
    global $fst_test_passed, $fst_test_failed;
    echo "\n{$fst_test_passed} passed, {$fst_test_failed} failed\n";
    exit( $fst_test_failed > 0 ? 1 : 0 );
}

require_once ABSPATH . 'includes/carriers/class-fst-carrier.php';
require_once ABSPATH . 'includes/carriers/class-fst-carrier-ups.php';
require_once ABSPATH . 'includes/carriers/class-fst-carrier-usps.php';
require_once ABSPATH . 'includes/class-fst-label-cost.php';
require_once ABSPATH . 'includes/class-fst-shipment.php';
require_once ABSPATH . 'includes/class-fst-settings.php';
require_once ABSPATH . 'includes/class-fst-order.php';
