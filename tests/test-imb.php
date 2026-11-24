<?php
/**
 * IMb encoder test — run with: php tests/test-imb.php
 *
 * Vectors are the published examples from USPS-B-3200 (Intelligent Mail
 * Barcode 4-State Specification), Appendix: same tracking code with an
 * empty, 5-, 9- and 11-digit routing code.
 */

if ( 'cli' !== PHP_SAPI ) {
    exit; // Command-line test only — never runs over HTTP.
}

require __DIR__ . '/../includes/letters/class-fst-imb.php';

$vectors = array(
    array( '01234567094987654321', '',            'ATTFATTDTTADTAATTDTDTATTDAFDDFADFDFTFFFFFTATFAAAATDFFTDAADFTFDTDT' ),
    array( '01234567094987654321', '01234',       'DTTAFADDTTFTDTFTFDTDDADADAFADFATDDFTAAAFDTTADFAAATDFDTDFADDDTDFFT' ),
    array( '01234567094987654321', '012345678',   'ADFTTAFDTTTTFATTADTAAATFTFTATDAAAFDDADATATDTDTTDFDTDATADADTDFFTFA' ),
    array( '01234567094987654321', '01234567891', 'AADTFFDFTDADTAADAATFDTDDAAADDTDTTDAFADADDDTFFFDDTTTADFAAADFTDAADA' ),
);

$fail = 0;
foreach ( $vectors as $i => $v ) {
    $got = FST_IMb::encode( $v[0], $v[1] );
    if ( $got === $v[2] ) {
        echo "PASS vector " . ( $i + 1 ) . " (routing '" . $v[1] . "')\n";
    } else {
        $fail++;
        echo "FAIL vector " . ( $i + 1 ) . "\n  expected {$v[2]}\n  got      {$got}\n";
    }
}

// Input validation.
$bad = array( array( '0123', '' ), array( '05234567094987654321', '' ), array( '01234567094987654321', '1234' ) );
foreach ( $bad as $b ) {
    try {
        FST_IMb::encode( $b[0], $b[1] );
        $fail++;
        echo "FAIL: accepted invalid input {$b[0]} / {$b[1]}\n";
    } catch ( InvalidArgumentException $e ) {
        echo "PASS rejects invalid input ({$e->getMessage()})\n";
    }
}

// SVG sanity: 65 rects + background.
$svg = FST_IMb::render_svg( FST_IMb::encode( '01234567094987654321', '01234567891' ) );
$n   = substr_count( $svg, '<rect ' );
if ( 66 === $n ) {
    echo "PASS svg has 65 bars\n";
} else {
    $fail++;
    echo "FAIL svg rect count {$n}\n";
}

// SVG round-trip: rebuild the bar string from the drawn rectangles.
foreach ( array( 203, 300, 600 ) as $dpi ) {
    $bars = FST_IMb::encode( '00310901234567000001', '55316234055' );
    $svg  = FST_IMb::render_svg( $bars, array( 'dpi' => $dpi ) );
    preg_match_all( '/<rect x="(\d+)" y="(\d+)" width="(\d+)" height="(\d+)"\/>/', $svg, $m, PREG_SET_ORDER );
    $tops = array_map( function ( $r ) { return (int) $r[2]; }, $m );
    $bots = array_map( function ( $r ) { return (int) $r[2] + (int) $r[4]; }, $m );
    $top  = min( $tops );
    $bot  = max( $bots );
    $back = '';
    foreach ( $m as $r ) {
        $asc  = (int) $r[2] === $top;
        $desc = (int) $r[2] + (int) $r[4] === $bot;
        $back .= $asc && $desc ? 'F' : ( $asc ? 'A' : ( $desc ? 'D' : 'T' ) );
    }
    preg_match( '/width="([\d.]+)in"/', $svg, $w );
    $pitch = ( (int) $m[1][1] - (int) $m[0][1] ) / $dpi;
    $bw    = (int) $m[0][3] / $dpi;
    $full  = ( $bot - $top ) / $dpi;
    $ok = $back === $bars
        && $pitch >= 1 / 24 && $pitch <= 1 / 20      // 20-24 bars per inch
        && $bw >= 0.015 && $bw <= 0.025               // bar width
        && $full >= 0.125 && $full <= 0.165;          // full bar height
    if ( $ok ) {
        printf( "PASS svg @%d dpi: bars round-trip; pitch %.4f in, width %.4f in, full height %.4f in (in USPS-B-3200 range)\n", $dpi, $pitch, $bw, $full );
    } else {
        $fail++;
        printf( "FAIL svg @%d dpi: roundtrip=%s pitch=%.4f width=%.4f full=%.4f\n", $dpi, $back === $bars ? 'ok' : 'MISMATCH', $pitch, $bw, $full );
    }
}

echo $fail ? "\n{$fail} FAILED\n" : "\nALL PASSED\n";
exit( $fail ? 1 : 0 );
