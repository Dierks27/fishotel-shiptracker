<?php
/**
 * Serial allocation under concurrency — needs a MySQL/MariaDB server.
 *
 *   FST_TEST_DB_HOST=127.0.0.1 FST_TEST_DB_PORT=3306 FST_TEST_DB_USER=u \
 *   FST_TEST_DB_PASS=p FST_TEST_DB_NAME=scratch_db php tests/test-letters-serial.php
 *
 * Drops and recreates wp_options + wp_fst_letters in that database, so
 * point it at a scratch database, never a live site.
 *
 * Forks 20 processes that each run the real FST_Letters::allocate_unique_serial()
 * 50 times (1,000 "Print label" clicks at once), then checks every serial is unique.
 */

require __DIR__ . '/bootstrap.php';

if ( ! function_exists( 'pcntl_fork' ) ) {
    fwrite( STDERR, "pcntl extension required\n" );
    exit( 2 );
}

if ( ! getenv( 'FST_TEST_DB_NAME' ) ) {
    fwrite( STDERR, "Set FST_TEST_DB_NAME to a scratch database (this test drops tables).\n" );
    exit( 2 );
}

$setup = new FST_Test_WPDB();
$setup->query( 'DROP TABLE IF EXISTS wp_options' );
$setup->query( 'DROP TABLE IF EXISTS wp_fst_letters' );
// Same shape as WordPress core's wp_options.
$setup->query( "CREATE TABLE wp_options (
    option_id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    option_name varchar(191) NOT NULL DEFAULT '',
    option_value longtext NOT NULL,
    autoload varchar(20) NOT NULL DEFAULT 'yes',
    PRIMARY KEY (option_id),
    UNIQUE KEY option_name (option_name)
) ENGINE=InnoDB" );
$setup->query( "CREATE TABLE wp_fst_letters (
    id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
    tracking_code varchar(20) NOT NULL DEFAULT '',
    serial varchar(9) NOT NULL DEFAULT '',
    created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id), KEY tracking_code (tracking_code)
) ENGINE=InnoDB" );

$procs    = 20;
$per_proc = 50;
$prefix   = '00310' . '901234567'; // Barcode ID + STID + 9-digit MID.
$outdir   = sys_get_temp_dir() . '/fst-serial-' . getmypid();
@mkdir( $outdir );

$pids = array();
for ( $p = 0; $p < $procs; $p++ ) {
    $pid = pcntl_fork();
    if ( 0 === $pid ) {
        $GLOBALS['wpdb'] = new FST_Test_WPDB(); // Own connection per process.
        $got = array();
        for ( $i = 0; $i < $per_proc; $i++ ) {
            $r = FST_Letters::allocate_unique_serial( $prefix, 6 );
            $got[] = is_wp_error( $r ) ? 'ERR:' . $r->get_error_message() : $r[0];
        }
        file_put_contents( "$outdir/$p", implode( "\n", $got ) );
        exit( 0 );
    }
    $pids[] = $pid;
}
foreach ( $pids as $pid ) {
    pcntl_waitpid( $pid, $status );
}

$all = array();
for ( $p = 0; $p < $procs; $p++ ) {
    $all = array_merge( $all, explode( "\n", trim( (string) file_get_contents( "$outdir/$p" ) ) ) );
    unlink( "$outdir/$p" );
}
rmdir( $outdir );

$errors = array_filter( $all, function ( $s ) { return 0 === strpos( $s, 'ERR:' ); } );
fst_assert( empty( $errors ), 'no allocation errors (' . count( $errors ) . ')' );
fst_assert( count( $all ) === $procs * $per_proc, 'got ' . ( $procs * $per_proc ) . ' serials' );
fst_assert( count( array_unique( $all ) ) === count( $all ), 'all ' . count( $all ) . ' concurrent serials unique' );
$nums = array_map( 'intval', $all );
sort( $nums );
fst_assert( $nums[0] === 1 && end( $nums ) === $procs * $per_proc, 'serials are exactly 1..' . ( $procs * $per_proc ) . ' (no gaps, no repeats)' );
fst_assert( 6 === strlen( $all[0] ), 'serial zero-padded to 6 digits' );

// Wrap: counter at 999998 → next 999999 → then wraps to 1.
$GLOBALS['wpdb'] = new FST_Test_WPDB();
$wpdb = $GLOBALS['wpdb'];
$wpdb->query( "UPDATE wp_options SET option_value = '999998' WHERE option_name = 'fst_letter_serial'" );
fst_assert( 999999 === FST_Letters::next_serial( 6 ), 'serial 999999 issued before wrap' );
fst_assert( 1 === FST_Letters::next_serial( 6 ), 'counter wraps to 1 (never 0, never 7 digits)' );

// After a wrap, a serial used in the last 45 days is skipped.
$wpdb->query( "UPDATE wp_options SET option_value = '0' WHERE option_name = 'fst_letter_serial'" );
$wpdb->query( "INSERT INTO wp_fst_letters (tracking_code, serial, created_at) VALUES ('{$prefix}000001', '000001', NOW()), ('{$prefix}000002', '000002', NOW())" );
$r = FST_Letters::allocate_unique_serial( $prefix, 6 );
fst_assert( ! is_wp_error( $r ) && '000003' === $r[0], 'serials used within 45 days are skipped after a wrap (got ' . ( is_wp_error( $r ) ? $r->get_error_message() : $r[0] ) . ')' );

// Old (>45 days) serial may be reused.
$wpdb->query( "UPDATE wp_options SET option_value = '9' WHERE option_name = 'fst_letter_serial'" );
$wpdb->query( "INSERT INTO wp_fst_letters (tracking_code, serial, created_at) VALUES ('{$prefix}000010', '000010', DATE_SUB(NOW(), INTERVAL 60 DAY))" );
$r = FST_Letters::allocate_unique_serial( $prefix, 6 );
fst_assert( ! is_wp_error( $r ) && '000010' === $r[0], 'serial older than 45 days can be reused' );

// Safe reset.
$GLOBALS['fst_test_options'] = array();
update_option( 'fst_letter_settings', array( 'mid' => '901234567' ) );
$wpdb->query( "INSERT INTO wp_fst_letters (tracking_code, serial, created_at) VALUES ('{$prefix}000500', '000500', NOW())" );
$r = FST_Letters::reset_serial( 100 );
fst_assert( is_wp_error( $r ), 'reset below a serial used in the last 45 days is refused' );
$r = FST_Letters::reset_serial( 600 );
fst_assert( true === $r && 600 === FST_Letters::current_serial(), 'reset above recent serials is allowed' );
fst_assert( 601 === FST_Letters::next_serial( 6 ), 'next serial after reset is reset+1' );

echo empty( $fst_fail ) ? "\nALL PASSED\n" : "\n{$fst_fail} FAILED\n";
exit( empty( $fst_fail ) ? 0 : 1 );
