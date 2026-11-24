<?php
/**
 * Intelligent Mail barcode (IMb) encoder — USPS-B-3200.
 *
 * Pure PHP, no WordPress dependencies, no bcmath/gmp: the 102-bit binary
 * value is held in a 13-byte big-endian array exactly like the USPS
 * reference implementation, so it runs on any PHP 7.4+ host.
 *
 * Usage:
 *   $bars = FST_IMb::encode( '00300123456123456', '50313123401' ); // "ATDF..." (65 chars)
 *   $svg  = FST_IMb::render_svg( $bars );
 *
 * Bar letters: F = full, A = ascender, D = descender, T = tracker.
 *
 * Verified against the USPS-B-3200 published test vectors — see
 * tests/test-imb.php.
 *
 * @package FisHotel_ShipTracker
 */

if ( ! class_exists( 'FST_IMb' ) ) :

class FST_IMb {

    /**
     * Bar-to-character mapping (USPS-B-3200 Table 22 / Appendix).
     * Index = bar position 0..64 (left to right).
     * Each row: [ descender char, descender bit, ascender char, ascender bit ]
     * with characters A..J numbered 0..9.
     */
    private static $bar_table = array(
        array( 7, 2, 4, 3 ),  array( 1, 10, 0, 0 ), array( 9, 12, 2, 8 ), array( 5, 5, 6, 11 ), array( 8, 9, 3, 1 ),
        array( 0, 1, 5, 12 ), array( 2, 5, 1, 8 ),  array( 4, 4, 9, 11 ), array( 6, 3, 8, 10 ), array( 3, 9, 7, 6 ),
        array( 5, 11, 1, 4 ), array( 8, 5, 2, 12 ), array( 9, 10, 0, 2 ), array( 7, 1, 6, 7 ),  array( 3, 6, 4, 9 ),
        array( 0, 3, 8, 6 ),  array( 6, 4, 2, 7 ),  array( 1, 1, 9, 9 ),  array( 7, 10, 5, 2 ), array( 4, 0, 3, 8 ),
        array( 6, 2, 0, 4 ),  array( 8, 11, 1, 0 ), array( 9, 8, 3, 12 ), array( 2, 6, 7, 7 ),  array( 5, 1, 4, 10 ),
        array( 1, 12, 6, 9 ), array( 7, 3, 8, 0 ),  array( 5, 8, 9, 7 ),  array( 4, 6, 2, 10 ), array( 3, 4, 0, 5 ),
        array( 8, 4, 5, 7 ),  array( 7, 11, 1, 9 ), array( 6, 0, 9, 6 ),  array( 0, 6, 4, 8 ),  array( 2, 1, 3, 2 ),
        array( 5, 9, 8, 12 ), array( 4, 11, 6, 1 ), array( 9, 5, 7, 4 ),  array( 3, 3, 1, 2 ),  array( 0, 7, 2, 0 ),
        array( 1, 3, 4, 1 ),  array( 6, 10, 3, 5 ), array( 8, 7, 9, 4 ),  array( 2, 11, 5, 6 ), array( 0, 8, 7, 12 ),
        array( 4, 2, 8, 1 ),  array( 5, 10, 3, 0 ), array( 9, 3, 0, 9 ),  array( 6, 5, 2, 4 ),  array( 7, 8, 1, 7 ),
        array( 5, 0, 4, 5 ),  array( 2, 3, 0, 10 ), array( 6, 12, 9, 2 ), array( 3, 11, 1, 6 ), array( 8, 8, 7, 9 ),
        array( 5, 4, 0, 11 ), array( 1, 5, 2, 2 ),  array( 9, 1, 4, 12 ), array( 8, 3, 6, 6 ),  array( 7, 0, 3, 7 ),
        array( 4, 7, 7, 5 ),  array( 0, 12, 1, 11 ), array( 2, 9, 9, 0 ), array( 6, 8, 5, 3 ),  array( 3, 10, 8, 2 ),
    );

    /** @var int[]|null 5-of-13 table (1287 entries). */
    private static $table5 = null;

    /** @var int[]|null 2-of-13 table (78 entries). */
    private static $table2 = null;

    /**
     * Validate the 20-digit tracking code and the routing code.
     *
     * @param string $tracking 20 digits: Barcode ID (2) + STID (3) + MID+serial (15).
     * @param string $routing  '' or 5, 9, or 11 digits.
     * @return true|string True when valid, otherwise an error message.
     */
    public static function validate( $tracking, $routing = '' ) {
        if ( ! preg_match( '/^\d{20}$/', (string) $tracking ) ) {
            return 'Tracking code must be exactly 20 digits.';
        }
        if ( (int) $tracking[1] > 4 ) {
            return 'Second digit of the Barcode ID must be 0-4.';
        }
        if ( ! preg_match( '/^(\d{5}|\d{9}|\d{11})?$/', (string) $routing ) ) {
            return 'Routing code must be empty or 5, 9, or 11 digits.';
        }
        return true;
    }

    /**
     * Encode a tracking + routing code into the 65-character bar string.
     *
     * @param string $tracking 20-digit tracking code.
     * @param string $routing  Routing (ZIP) code: '', 5, 9, or 11 digits.
     * @return string 65 chars of F/A/D/T.
     * @throws InvalidArgumentException On invalid input.
     */
    public static function encode( $tracking, $routing = '' ) {
        $tracking = (string) $tracking;
        $routing  = (string) $routing;

        $valid = self::validate( $tracking, $routing );
        if ( true !== $valid ) {
            throw new InvalidArgumentException( $valid );
        }

        self::init_tables();

        // Step 1: binary data (13 bytes, big-endian).
        $bytes = array_fill( 0, 13, 0 );

        $len = strlen( $routing );
        if ( 5 === $len ) {
            self::add_decimal( $bytes, $routing );
            self::add_small( $bytes, 1 );
        } elseif ( 9 === $len ) {
            self::add_decimal( $bytes, $routing );
            self::add_small( $bytes, 100000 + 1 );
        } elseif ( 11 === $len ) {
            self::add_decimal( $bytes, $routing );
            self::add_small( $bytes, 1000000000 + 100000 + 1 );
        }

        // First tracking digit ×10, second ×5 (it is 0-4), rest ×10.
        self::mul_small( $bytes, 10 );
        self::add_small( $bytes, (int) $tracking[0] );
        self::mul_small( $bytes, 5 );
        self::add_small( $bytes, (int) $tracking[1] );
        for ( $i = 2; $i < 20; $i++ ) {
            self::mul_small( $bytes, 10 );
            self::add_small( $bytes, (int) $tracking[ $i ] );
        }

        // Step 2: 11-bit frame check sequence.
        $fcs = self::crc11( $bytes );

        // Step 3: codewords. J = mod 636, I..B = mod 1365, A = remainder.
        $codewords     = array_fill( 0, 10, 0 );
        $codewords[9]  = self::divmod_small( $bytes, 636 );
        for ( $i = 8; $i >= 1; $i-- ) {
            $codewords[ $i ] = self::divmod_small( $bytes, 1365 );
        }
        $codewords[0] = self::divmod_small( $bytes, 1365 ); // Remaining value is < 659.

        // Step 4: orientation + FCS bit 10.
        $codewords[9] *= 2;
        if ( $fcs & 0x400 ) {
            $codewords[0] += 659;
        }

        // Step 5: codewords to characters.
        $chars = array();
        for ( $i = 0; $i < 10; $i++ ) {
            $cw          = $codewords[ $i ];
            $chars[ $i ] = $cw < 1287 ? self::$table5[ $cw ] : self::$table2[ $cw - 1287 ];
            if ( $fcs & ( 1 << $i ) ) {
                $chars[ $i ] = ( ~$chars[ $i ] ) & 0x1FFF;
            }
        }

        // Step 6: characters to bars.
        $bars = '';
        foreach ( self::$bar_table as $row ) {
            $desc = ( $chars[ $row[0] ] >> $row[1] ) & 1;
            $asc  = ( $chars[ $row[2] ] >> $row[3] ) & 1;
            if ( $desc && $asc ) {
                $bars .= 'F';
            } elseif ( $asc ) {
                $bars .= 'A';
            } elseif ( $desc ) {
                $bars .= 'D';
            } else {
                $bars .= 'T';
            }
        }

        return $bars;
    }

    /**
     * Render a bar string as an SVG sized in physical inches.
     *
     * Dimensions default to USPS-B-3200 nominals and are snapped to the
     * printer's dot grid so thermal printers don't jitter bar widths.
     *
     * @param string $bars 65-char F/A/D/T string.
     * @param array  $args {
     *     @type int   $dpi          Printer resolution (default 203).
     *     @type float $bar_width    Inches (spec 0.015–0.025, nominal 0.020).
     *     @type float $pitch        Inches per bar (spec 20–24 bars/inch, nominal 1/22).
     *     @type float $full_height  Inches (spec 0.125–0.165, nominal 0.145).
     *     @type float $tracker_height Inches (spec 0.039–0.057, nominal 0.048).
     *     @type float $quiet_h      Horizontal quiet zone, inches (spec min 0.125).
     *     @type float $quiet_v      Vertical quiet zone, inches (spec min 0.028).
     * }
     * @return string SVG markup.
     */
    public static function render_svg( $bars, $args = array() ) {
        $args = array_merge( array(
            'dpi'            => 203,
            'bar_width'      => 0.020,
            'pitch'          => 1 / 22,
            'full_height'    => 0.145,
            'tracker_height' => 0.048,
            'quiet_h'        => 0.125,
            'quiet_v'        => 0.040,
        ), $args );

        $dpi = max( 100, (int) $args['dpi'] );

        // Snap to whole printer dots.
        $snap = static function ( $inches, $min_dots = 1 ) use ( $dpi ) {
            return max( $min_dots, (int) round( $inches * $dpi ) );
        };

        $w_dots     = $snap( $args['bar_width'] );
        $pitch_dots = $snap( $args['pitch'], $w_dots + 1 );
        $full_dots  = $snap( $args['full_height'] );
        $trk_dots   = $snap( $args['tracker_height'] );
        // Keep tracker centred: make (full - tracker) even.
        if ( ( $full_dots - $trk_dots ) % 2 ) {
            $full_dots++;
        }
        $ext_dots = ( $full_dots - $trk_dots ) / 2; // Ascender/descender extension.
        $qh_dots  = $snap( $args['quiet_h'] );
        $qv_dots  = $snap( $args['quiet_v'] );

        $n          = strlen( $bars );
        $width_dots = $qh_dots * 2 + ( $n - 1 ) * $pitch_dots + $w_dots;
        $height_dots = $qv_dots * 2 + $full_dots;

        $rects = '';
        for ( $i = 0; $i < $n; $i++ ) {
            $x = $qh_dots + $i * $pitch_dots;
            switch ( $bars[ $i ] ) {
                case 'F':
                    $y = $qv_dots;
                    $h = $full_dots;
                    break;
                case 'A':
                    $y = $qv_dots;
                    $h = $ext_dots + $trk_dots;
                    break;
                case 'D':
                    $y = $qv_dots + $ext_dots;
                    $h = $ext_dots + $trk_dots;
                    break;
                default: // T
                    $y = $qv_dots + $ext_dots;
                    $h = $trk_dots;
            }
            $rects .= '<rect x="' . $x . '" y="' . $y . '" width="' . $w_dots . '" height="' . $h . '"/>';
        }

        $w_in = round( $width_dots / $dpi, 4 );
        $h_in = round( $height_dots / $dpi, 4 );

        return '<svg xmlns="http://www.w3.org/2000/svg" class="fst-imb" width="' . $w_in . 'in" height="' . $h_in . 'in"'
            . ' viewBox="0 0 ' . $width_dots . ' ' . $height_dots . '" shape-rendering="crispEdges" role="img" aria-label="Intelligent Mail barcode">'
            . '<rect width="100%" height="100%" fill="#fff"/><g fill="#000">' . $rects . '</g></svg>';
    }

    /**
     * Format a 31-digit IMb for human reading: "00 300 123456 123456 50313123401".
     *
     * @param string $tracking 20 digits.
     * @param string $routing  Routing code.
     * @param int    $mid_len  6 or 9.
     * @return string
     */
    public static function format_human( $tracking, $routing = '', $mid_len = 9 ) {
        $mid_len = 6 === (int) $mid_len ? 6 : 9;
        $parts   = array(
            substr( $tracking, 0, 2 ),
            substr( $tracking, 2, 3 ),
            substr( $tracking, 5, $mid_len ),
            substr( $tracking, 5 + $mid_len ),
        );
        if ( '' !== (string) $routing ) {
            $parts[] = $routing;
        }
        return implode( ' ', $parts );
    }

    /* ------------------------------------------------------------------
     * Internals — 13-byte big-endian arithmetic and lookup tables.
     * ---------------------------------------------------------------- */

    /** bytes = bytes * $m (m small). */
    private static function mul_small( array &$bytes, $m ) {
        $carry = 0;
        for ( $i = 12; $i >= 0; $i-- ) {
            $v           = $bytes[ $i ] * $m + $carry;
            $bytes[ $i ] = $v & 0xFF;
            $carry       = $v >> 8;
        }
    }

    /** bytes = bytes + $a (a < 2^31). */
    private static function add_small( array &$bytes, $a ) {
        $carry = $a;
        for ( $i = 12; $i >= 0 && $carry; $i-- ) {
            $v           = $bytes[ $i ] + ( $carry & 0xFF );
            $carry       = ( $carry >> 8 ) + ( $v >> 8 );
            $bytes[ $i ] = $v & 0xFF;
        }
    }

    /** bytes = decimal string value (bytes must start at zero). */
    private static function add_decimal( array &$bytes, $digits ) {
        $len = strlen( $digits );
        for ( $i = 0; $i < $len; $i++ ) {
            self::mul_small( $bytes, 10 );
            self::add_small( $bytes, (int) $digits[ $i ] );
        }
    }

    /** bytes = floor(bytes / d); returns bytes mod d. */
    private static function divmod_small( array &$bytes, $d ) {
        $rem = 0;
        for ( $i = 0; $i < 13; $i++ ) {
            $cur         = ( $rem << 8 ) | $bytes[ $i ];
            $bytes[ $i ] = intdiv( $cur, $d );
            $rem         = $cur % $d;
        }
        return $rem;
    }

    /** USPS CRC-11 over the 102 data bits (generator 0x0F35). */
    private static function crc11( array $bytes ) {
        $poly = 0x0F35;
        $fcs  = 0x07FF;

        // Most significant byte: skip its top two bits.
        $data = $bytes[0] << 5;
        for ( $bit = 2; $bit < 8; $bit++ ) {
            $fcs = ( ( $fcs ^ $data ) & 0x400 ) ? ( ( $fcs << 1 ) ^ $poly ) : ( $fcs << 1 );
            $fcs &= 0x7FF;
            $data <<= 1;
        }

        for ( $b = 1; $b < 13; $b++ ) {
            $data = $bytes[ $b ] << 3;
            for ( $bit = 0; $bit < 8; $bit++ ) {
                $fcs = ( ( $fcs ^ $data ) & 0x400 ) ? ( ( $fcs << 1 ) ^ $poly ) : ( $fcs << 1 );
                $fcs &= 0x7FF;
                $data <<= 1;
            }
        }

        return $fcs;
    }

    /** Build the 5-of-13 and 2-of-13 tables per the USPS reference code. */
    private static function init_tables() {
        if ( null !== self::$table5 ) {
            return;
        }
        self::$table5 = self::build_nof13( 5, 1287 );
        self::$table2 = self::build_nof13( 2, 78 );
    }

    private static function build_nof13( $n, $size ) {
        $table = array_fill( 0, $size, 0 );
        $lower = 0;
        $upper = $size - 1;

        for ( $count = 0; $count < 8192; $count++ ) {
            if ( self::popcount13( $count ) !== $n ) {
                continue;
            }
            $reverse = self::reverse13( $count );
            if ( $reverse < $count ) {
                continue;
            }
            if ( $reverse === $count ) {
                $table[ $upper-- ] = $count;
            } else {
                $table[ $lower++ ] = $count;
                $table[ $lower++ ] = $reverse;
            }
        }

        return $table;
    }

    private static function popcount13( $v ) {
        $c = 0;
        for ( $i = 0; $i < 13; $i++ ) {
            $c += ( $v >> $i ) & 1;
        }
        return $c;
    }

    private static function reverse13( $v ) {
        $r = 0;
        for ( $i = 0; $i < 13; $i++ ) {
            $r = ( $r << 1 ) | ( ( $v >> $i ) & 1 );
        }
        return $r;
    }
}

endif;
