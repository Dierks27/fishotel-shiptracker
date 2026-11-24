<?php
/**
 * Pure-logic tests for letter tracking (no DB, no network):
 * IV-MTR scan normalization, opcode/phase → status mapping, "likely delivered"
 * timing, USPS Addresses API parsing, credential encryption, MID rules.
 *
 *   php tests/letters-logic-test.php
 */

require __DIR__ . '/letters-bootstrap.php';
require_once __DIR__ . '/../includes/carriers/class-fst-carrier.php';
require_once __DIR__ . '/../includes/carriers/class-fst-carrier-usps-letter.php';
require_once __DIR__ . '/../includes/letters/class-fst-ivmtr.php';
require_once __DIR__ . '/../includes/letters/class-fst-letter-address.php';

function scan( $time, $opcode, $phase = '', $city = 'MINNEAPOLIS', $state = 'MN' ) {
    return array(
        'scan_date_time'      => $time,
        'scan_event_code'     => $opcode,
        'mail_phase'          => $phase,
        'scan_facility_city'  => $city,
        'scan_facility_state' => $state,
        'scan_facility_zip'   => '55401',
    );
}

// --- MID rules -------------------------------------------------------------
fst_assert( 9 === FST_Letters::mid_length( '901234567' ), '9-digit MID starting with 9' );
fst_assert( 6 === FST_Letters::mid_length( '123456' ), '6-digit MID starting 0-8' );
fst_assert( 0 === FST_Letters::mid_length( '912345' ), '6-digit MID starting with 9 rejected' );
fst_assert( 0 === FST_Letters::mid_length( '812345678' ), '9-digit MID not starting with 9 rejected' );

// --- Barcode settings validation: STID must be set (no default) -------------
update_option( 'fst_letter_settings', array( 'mid' => '901234567', 'stid' => '' ) );
$v = FST_Letters::validate_barcode_settings();
fst_assert( is_wp_error( $v ) && 'fst_letter_stid' === $v->get_error_code(), 'missing STID blocks label creation' );
update_option( 'fst_letter_settings', array( 'mid' => '901234567', 'stid' => '310' ) );
fst_assert( true === FST_Letters::validate_barcode_settings(), 'MID + STID + default Barcode ID 00 is valid' );
fst_assert( 6 === FST_Letters::serial_length(), '9-digit MID → 6-digit serial' );

// A full 31-digit IMb built from settings encodes.
$bars = FST_IMb::encode( '00310901234567000123', '55316123456' );
fst_assert( 65 === strlen( $bars ) && strspn( $bars, 'FADT' ) === 65, 'real-shaped 31-digit IMb encodes to 65 bars' );

// --- IV-MTR normalization (toolkit sample shape) ---------------------------
$data = array(
    'imb'   => '0031090123456700012355316123456',
    'scans' => array(
        scan( '2026-11-20T21:10:00', '895', 'Phase 2 - Destination Processing' ),
        scan( '2026-11-19T18:02:11', '891', 'Phase 1a - Origin Processing' ),
        scan( '2026-11-19T16:40:00', '15', '' ),
    ),
);
$scans = FST_IVMTR::normalize_scans( $data );
fst_assert( 3 === count( $scans ), 'three scans normalized' );
fst_assert( '2026-11-19 16:40:00' === $scans[0]['event_time'], 'scans sorted oldest first, T removed' );
fst_assert( '015' === $scans[0]['opcode'], 'opcode zero-padded to 3 digits' );
fst_assert( 'MINNEAPOLIS, MN' === $scans[0]['location'], 'location built from facility city/state' );
fst_assert( array() === FST_IVMTR::normalize_scans( array( 'scans' => array( array( 'scan_event_code' => '891' ) ) ) ), 'scan without a timestamp skipped' );

// --- Classification ----------------------------------------------------------
fst_assert( 'origin' === FST_Carrier_USPS_Letter::classify( '015' ), 'opcode 015 (AFCS cancel) = origin' );
fst_assert( 'destination' === FST_Carrier_USPS_Letter::classify( '895' ), 'opcode 895 = destination' );
fst_assert( 'final' === FST_Carrier_USPS_Letter::classify( '919' ), 'opcode 919 (DPS 2nd pass) = final' );
fst_assert( 'final' === FST_Carrier_USPS_Letter::classify( '123', 'Phase 3c - Destination Sequenced Carrier Sortation' ), 'mail phase 3 wins for unknown opcode' );
fst_assert( 'origin' === FST_Carrier_USPS_Letter::classify( '895', 'Phase 1 - Origin Processing' ), 'mail phase takes priority over opcode table' );
fst_assert( 'ignore' === FST_Carrier_USPS_Letter::classify( '999' ), 'unknown opcode without phase ignored' );

// --- interpret(): status mapping -----------------------------------------------
$now = strtotime( '2026-11-21 12:00:00' );

$r = FST_Carrier_USPS_Letter::interpret( array(), $now, 24 );
fst_assert( '' === $r['status'], 'no scans → status unchanged (empty)' );

$r = FST_Carrier_USPS_Letter::interpret( array_slice( $scans, 0, 2 ), $now, 24 );
fst_assert( 'in_transit' === $r['status'], 'origin scans only → in_transit' );
fst_assert( '2026-11-19 18:02:11' === $r['events'][0]['event_time'], 'events returned newest first' );

$r = FST_Carrier_USPS_Letter::interpret( $scans, $now, 24 );
fst_assert( 'out_for_delivery' === $r['status'], 'destination processing → out_for_delivery' );

$final = array_merge( $scans, FST_IVMTR::normalize_scans( array( 'scans' => array( scan( '2026-11-21T03:00:00', '919', 'Phase 3c - Destination Sequenced Carrier Sortation' ) ) ) ) );
$r = FST_Carrier_USPS_Letter::interpret( $final, $now, 24 );
fst_assert( 'out_for_delivery' === $r['status'], 'final DPS sort 9h ago → still out_for_delivery' );

$r = FST_Carrier_USPS_Letter::interpret( $final, strtotime( '2026-11-22 03:00:00' ), 24 );
fst_assert( 'likely_delivered' === $r['status'], 'final sort + 24h silence → likely_delivered' );
fst_assert( '' === $r['delivered_date'], 'likely_delivered never sets delivered_date' );

$r = FST_Carrier_USPS_Letter::interpret( $final, strtotime( '2026-11-21 20:00:00' ), 12 );
fst_assert( 'likely_delivered' === $r['status'], '"likely delivered" delay setting respected (12h)' );

$r = FST_Carrier_USPS_Letter::interpret( $scans, strtotime( '2026-12-30 00:00:00' ), 24 );
fst_assert( 'out_for_delivery' === $r['status'], 'destination-only silence never becomes likely_delivered' );

foreach ( array( $scans, $final ) as $set ) {
    foreach ( array( $now, $now + 30 * DAY_IN_SECONDS ) as $t ) {
        $r = FST_Carrier_USPS_Letter::interpret( $set, $t, 24 );
        fst_assert( 'delivered' !== $r['status'], 'letters never report "delivered"' );
    }
}

// Opcode overrides from settings.
update_option( 'fst_letter_settings', array( 'mid' => '901234567', 'stid' => '310', 'opcode_map' => "999=final\n 42 : origin" ) );
fst_assert( 'final' === FST_Carrier_USPS_Letter::classify( '999' ), 'opcode override 999=final applied' );
fst_assert( 'origin' === FST_Carrier_USPS_Letter::classify( '042', 'Phase 3 - x' ), 'override beats mail phase ("42 : origin")' );
update_option( 'fst_letter_settings', array( 'mid' => '901234567', 'stid' => '310', 'opcode_map' => '' ) );
fst_assert( 'ignore' === FST_Carrier_USPS_Letter::classify( '999' ), 'clearing overrides takes effect' );

// --- Addresses API v3 parsing ------------------------------------------------
$orig = array( 'name' => 'Jeff Test', 'company' => '', 'address1' => '123 main st', 'address2' => '', 'city' => 'champlin', 'state' => 'MN', 'zip' => '55316', 'country' => 'US' );
$ok = FST_Letter_Address::parse_response( array(
    'firm'           => null,
    'address'        => array( 'streetAddress' => '123 MAIN ST', 'city' => 'CHAMPLIN', 'state' => 'MN', 'ZIPCode' => '55316', 'ZIPPlus4' => '1234' ),
    'additionalInfo' => array( 'deliveryPoint' => '23', 'carrierRoute' => 'C001', 'DPVConfirmation' => 'Y' ),
), $orig );
fst_assert( 'validated' === $ok['status'], 'DPV Y → validated' );
fst_assert( '55316123423' === $ok['routing_code'], '11-digit routing code = ZIP5 + ZIP4 + DP' );
fst_assert( '55316-1234' === $ok['address']['zip'] && '123 MAIN ST' === $ok['address']['address1'], 'standardized address used on label' );

$d = FST_Letter_Address::parse_response( array(
    'address'        => array( 'streetAddress' => '1 APT BLDG', 'city' => 'CHAMPLIN', 'state' => 'MN', 'ZIPCode' => '55316', 'ZIPPlus4' => '9999' ),
    'additionalInfo' => array( 'deliveryPoint' => '01', 'DPVConfirmation' => 'D' ),
), $orig );
fst_assert( 'partial' === $d['status'] && 11 === strlen( $d['routing_code'] ), 'DPV D → partial with warning, still 11 digits' );
fst_assert( false !== strpos( $d['message'], 'apartment' ), 'DPV D message mentions missing apartment' );

$n = FST_Letter_Address::parse_response( array(
    'address'        => array( 'streetAddress' => '1 X', 'city' => 'CHAMPLIN', 'state' => 'MN', 'ZIPCode' => '55316', 'ZIPPlus4' => null ),
    'additionalInfo' => array( 'DPVConfirmation' => 'N' ),
), $orig );
fst_assert( 'partial' === $n['status'] && '55316' === $n['routing_code'], 'no ZIP+4 → 5-digit routing, flagged' );

// Bad address path (no API call possible): still printable with a 5-digit code.
$bad = FST_Letter_Address::validate( array( 'name' => 'X', 'company' => '', 'address1' => '', 'address2' => '', 'city' => '', 'state' => '', 'zip' => '55316-12', 'country' => 'US' ) );
fst_assert( 'unvalidated' === $bad['status'] && '55316' === $bad['routing_code'] && '' !== $bad['message'], 'incomplete address → unvalidated, 5-digit routing, warning' );
fst_assert( 65 === strlen( FST_IMb::encode( '00310901234567000124', $bad['routing_code'] ) ), 'unvalidated address still produces a printable barcode' );

// --- Credential encryption ---------------------------------------------------
$enc = FST_IVMTR::encrypt( 'hunter2!' );
fst_assert( 0 === strpos( $enc, 'enc1:' ) && false === strpos( $enc, 'hunter2' ), 'BCG password stored encrypted' );
fst_assert( 'hunter2!' === FST_IVMTR::decrypt( $enc ), 'BCG password decrypts' );
fst_assert( '' === FST_IVMTR::decrypt( 'enc1:' . base64_encode( str_repeat( 'x', 64 ) ) ), 'tampered ciphertext rejected' );

echo empty( $fst_fail ) ? "\nALL PASSED\n" : "\n{$fst_fail} FAILED\n";
exit( empty( $fst_fail ) ? 0 : 1 );
