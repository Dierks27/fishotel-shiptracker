<?php
/**
 * Minimal WordPress stand-ins so the letter classes can be unit-tested
 * from the CLI without a WordPress install.
 *
 * DB-backed tests (serial allocation) need a MySQL/MariaDB server:
 *   FST_TEST_DB_HOST=127.0.0.1 FST_TEST_DB_PORT=3306 FST_TEST_DB_USER=... \
 *   FST_TEST_DB_PASS=... FST_TEST_DB_NAME=... php tests/letters-serial-mysql.php
 */

if ( 'cli' !== PHP_SAPI ) {
    exit; // Command-line test only — never runs over HTTP.
}

define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'AUTH_KEY', 'test-auth-key' );
define( 'SECURE_AUTH_SALT', 'test-salt' );

date_default_timezone_set( 'UTC' );

$GLOBALS['fst_test_options'] = array();
$GLOBALS['fst_test_log']     = array();

class WP_Error {
    private $code;
    private $message;
    public function __construct( $code = '', $message = '' ) {
        $this->code    = $code;
        $this->message = $message;
    }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}

function is_wp_error( $v ) { return $v instanceof WP_Error; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function current_time( $type ) { return 'timestamp' === $type ? time() : gmdate( 'Y-m-d H:i:s' ); }
function wp_cache_delete() { return true; }
function get_current_user_id() { return 1; }
function wp_parse_args( $args, $defaults ) { return array_merge( $defaults, (array) $args ); }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wc_get_logger() {
    return new class() {
        public function log( $level, $message, $context = array() ) {
            $GLOBALS['fst_test_log'][] = "[$level] $message";
        }
    };
}

/**
 * Options: DB-backed when a $wpdb shim is connected, in-memory otherwise.
 */
function get_option( $name, $default = false ) {
    global $wpdb;
    if ( $wpdb instanceof FST_Test_WPDB ) {
        $v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        if ( null === $v ) {
            return $default;
        }
        $u = @unserialize( $v );
        return false !== $u || 'b:0;' === $v ? $u : $v;
    }
    return array_key_exists( $name, $GLOBALS['fst_test_options'] ) ? $GLOBALS['fst_test_options'][ $name ] : $default;
}
function add_option( $name, $value = '', $deprecated = '', $autoload = 'yes' ) {
    global $wpdb;
    if ( $wpdb instanceof FST_Test_WPDB ) {
        $v = is_scalar( $value ) ? (string) $value : serialize( $value );
        // INSERT IGNORE on the unique option_name — atomic like WordPress's add_option.
        return 1 === $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $v, $autoload ) );
    }
    if ( array_key_exists( $name, $GLOBALS['fst_test_options'] ) ) {
        return false;
    }
    $GLOBALS['fst_test_options'][ $name ] = $value;
    return true;
}
function update_option( $name, $value ) {
    global $wpdb;
    if ( $wpdb instanceof FST_Test_WPDB ) {
        $v = is_scalar( $value ) ? (string) $value : serialize( $value );
        $wpdb->query( $wpdb->prepare( "INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $name, $v ) );
        return true;
    }
    $GLOBALS['fst_test_options'][ $name ] = $value;
    return true;
}
function delete_option( $name ) {
    global $wpdb;
    if ( $wpdb instanceof FST_Test_WPDB ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s", $name ) );
        return true;
    }
    unset( $GLOBALS['fst_test_options'][ $name ] );
    return true;
}

/**
 * Tiny mysqli-backed subset of wpdb (prepare/query/get_var/get_row/get_results/insert/update).
 */
class FST_Test_WPDB {
    public $prefix  = 'wp_';
    public $options = 'wp_options';
    public $insert_id = 0;
    private $db;

    public function __construct() {
        $this->db = new mysqli(
            getenv( 'FST_TEST_DB_HOST' ) ?: '127.0.0.1',
            getenv( 'FST_TEST_DB_USER' ) ?: 'root',
            getenv( 'FST_TEST_DB_PASS' ) ?: '',
            (string) getenv( 'FST_TEST_DB_NAME' ),
            (int) ( getenv( 'FST_TEST_DB_PORT' ) ?: 3306 )
        );
    }

    public function prepare( $query, ...$args ) {
        if ( 1 === count( $args ) && is_array( $args[0] ) ) {
            $args = $args[0];
        }
        $i  = 0;
        $db = $this->db;
        return preg_replace_callback( '/%%|%d|%s|%f/', function ( $m ) use ( &$i, $args, $db ) {
            if ( '%%' === $m[0] ) {
                return '%';
            }
            $v = $args[ $i++ ];
            if ( '%d' === $m[0] ) {
                return (string) (int) $v;
            }
            if ( '%f' === $m[0] ) {
                return (string) (float) $v;
            }
            return "'" . $db->real_escape_string( (string) $v ) . "'";
        }, $query );
    }

    public function query( $sql ) {
        $r = $this->db->query( $sql );
        if ( false === $r ) {
            throw new RuntimeException( $this->db->error . "\n" . $sql );
        }
        $this->insert_id = $this->db->insert_id;
        return $r instanceof mysqli_result ? $r->num_rows : $this->db->affected_rows;
    }

    public function get_results( $sql ) {
        $r = $this->db->query( $sql );
        if ( false === $r ) {
            throw new RuntimeException( $this->db->error . "\n" . $sql );
        }
        $rows = array();
        while ( $o = $r->fetch_object() ) {
            $rows[] = $o;
        }
        return $rows;
    }

    public function get_row( $sql ) {
        $rows = $this->get_results( $sql );
        return $rows ? $rows[0] : null;
    }

    public function get_var( $sql ) {
        $r = $this->db->query( $sql );
        if ( false === $r ) {
            throw new RuntimeException( $this->db->error . "\n" . $sql );
        }
        $row = $r->fetch_row();
        return $row ? $row[0] : null;
    }

    public function insert( $table, $data ) {
        $cols = implode( ',', array_map( function ( $c ) { return "`$c`"; }, array_keys( $data ) ) );
        $vals = implode( ',', array_map( function ( $v ) {
            return null === $v ? 'NULL' : "'" . $this->db->real_escape_string( (string) $v ) . "'";
        }, array_values( $data ) ) );
        return $this->query( "INSERT INTO `$table` ($cols) VALUES ($vals)" );
    }

    public function update( $table, $data, $where ) {
        $set = array();
        foreach ( $data as $k => $v ) {
            $set[] = "`$k` = " . ( null === $v ? 'NULL' : "'" . $this->db->real_escape_string( (string) $v ) . "'" );
        }
        $w = array();
        foreach ( $where as $k => $v ) {
            $w[] = "`$k` = '" . $this->db->real_escape_string( (string) $v ) . "'";
        }
        return $this->query( "UPDATE `$table` SET " . implode( ',', $set ) . ' WHERE ' . implode( ' AND ', $w ) );
    }
}

/** Assertion helper. */
function fst_assert( $cond, $label ) {
    global $fst_fail;
    if ( $cond ) {
        echo "PASS $label\n";
    } else {
        $fst_fail = ( $fst_fail ?? 0 ) + 1;
        echo "FAIL $label\n";
    }
}

require_once __DIR__ . '/../includes/letters/class-fst-imb.php';
require_once __DIR__ . '/../includes/letters/class-fst-letters.php';
