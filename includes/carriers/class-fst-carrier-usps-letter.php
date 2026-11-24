<?php
/**
 * "USPS Letter" carrier: stamped First-Class letters tracked by IMb via IV-MTR.
 *
 * Plugs into the normal FST_Tracker::poll_shipment() flow (events table,
 * status updates, status-change hook). Status mapping:
 *
 *   origin scans (cancellation / outgoing sort)        → in_transit
 *   destination processing or carrier-route/DPS sort   → out_for_delivery
 *   carrier-route/DPS sort ("final") + no new scans for
 *   N hours (setting, default 24)                      → likely_delivered
 *
 * Letters get no delivery scan, so this never returns 'delivered'.
 *
 * Opcodes: IV-MTR Operation Codes List v11.2 (PostalPro, 11/12/2026).
 * Each scan is classified by its IV-MTR mail_phase first, then by opcode.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Carrier_USPS_Letter extends FST_Carrier {

    protected $slug = 'usps_letter';
    protected $name = 'USPS Letter';

    /** Stage rank: higher = further along. */
    const STAGE_RANK = array(
        'ignore'      => 0,
        'origin'      => 1,
        'destination' => 2,
        'final'       => 3,
    );

    /**
     * Built-in opcode → stage (letters, Actual piece events).
     */
    const OPCODES = array(
        // Phase 0 — cancellation.
        '004' => 'origin', '010' => 'origin', '012' => 'origin', '015' => 'origin',
        '017' => 'origin', '066' => 'origin', '067' => 'origin',
        // Phase 1 — origin / outgoing sort.
        '891' => 'origin', '892' => 'origin', '821' => 'origin', '861' => 'origin',
        '481' => 'origin', '482' => 'origin', '291' => 'origin', '030' => 'origin', '040' => 'origin',
        // Phase 2 — destination processing.
        '893' => 'destination', '894' => 'destination', '895' => 'destination', '824' => 'destination',
        '825' => 'destination', '483' => 'destination', '484' => 'destination', '485' => 'destination',
        '864' => 'destination',
        // Phase 3 — incoming secondary / box section / DPS (stop-the-clock).
        '896' => 'final', '826' => 'final', '266' => 'final', '296' => 'final', '897' => 'final',
        '918' => 'final', '919' => 'final', '912' => 'final', '913' => 'final', '898' => 'final', '899' => 'final',
        // Phase 4 — piece out for delivery (IMDAS).
        '872' => 'final',
    );

    public function has_credentials() {
        return FST_IVMTR::is_ready();
    }

    public function get_access_token() {
        return FST_IVMTR::get_token();
    }

    /**
     * No public USPS page tracks an IMb, so there's no carrier link.
     */
    public function get_tracking_url( $tracking_number ) {
        return '';
    }

    /**
     * @param string $tracking_number Full IMb digits.
     * @return array|WP_Error Standard carrier result.
     */
    public function track( $tracking_number ) {
        $piece = FST_IVMTR::get_piece( $tracking_number );
        if ( is_wp_error( $piece ) ) {
            return $piece;
        }

        FST_Letters::record_scans( $tracking_number, $piece['scans'] );

        return self::interpret( $piece['scans'], current_time( 'timestamp' ), (int) FST_Letters::setting( 'likely_delivered_hours' ), $piece['raw'] );
    }

    /**
     * Turn normalized scans into a carrier result. Pure — unit-tested.
     *
     * @param array $scans       From FST_IVMTR::normalize_scans() (oldest first).
     * @param int   $now         "Now" in the same clock as scan timestamps (site-local).
     * @param int   $ld_hours    Hours of silence after a final sort → likely delivered.
     * @param array $raw         Raw piece data, stored as tracking_data.
     * @return array
     */
    public static function interpret( array $scans, $now, $ld_hours = 24, $raw = array() ) {
        $result = array(
            'status'         => '',  // Empty = keep current status.
            'status_detail'  => 'Waiting for the first USPS scan',
            'est_delivery'   => '',
            'delivered_date' => '',  // Never set: letters have no delivery scan.
            'events'         => array(),
            'raw'            => $raw,
        );

        if ( ! empty( $raw['expected_delivery_date'] ) ) {
            $d = date_create( $raw['expected_delivery_date'] );
            if ( $d ) {
                $result['est_delivery'] = $d->format( 'Y-m-d' );
            }
        }

        if ( empty( $scans ) ) {
            return $result;
        }

        $best      = 'ignore';
        $last_time = 0;

        foreach ( $scans as $scan ) {
            $stage = self::classify( $scan['opcode'], $scan['mail_phase'] );

            if ( self::STAGE_RANK[ $stage ] > self::STAGE_RANK[ $best ] ) {
                $best = $stage;
            }
            $last_time = max( $last_time, (int) $scan['timestamp'] );

            $result['events'][] = array(
                'status'      => self::stage_status( $stage ),
                'description' => self::describe( $scan, $stage ),
                'location'    => $scan['location'],
                'event_time'  => $scan['event_time'],
                'raw'         => $scan['raw'],
            );
        }

        // Newest first, like the package carriers (poll_shipment reads events[0] as latest).
        $result['events'] = array_reverse( $result['events'] );

        switch ( $best ) {
            case 'final':
                $silent_hours = ( $now - $last_time ) / HOUR_IN_SECONDS;
                if ( $silent_hours >= max( 1, (int) $ld_hours ) ) {
                    $result['status']        = 'likely_delivered';
                    $result['status_detail'] = 'Sorted for the carrier route; no scans since. Should be in the mailbox.';
                } else {
                    $result['status']        = 'out_for_delivery';
                    $result['status_detail'] = 'Sorted for the mail carrier\'s route';
                }
                break;
            case 'destination':
                $result['status']        = 'out_for_delivery';
                $result['status_detail'] = 'At the destination processing facility';
                break;
            case 'origin':
                $result['status']        = 'in_transit';
                $result['status_detail'] = 'Processed by USPS';
                break;
            default:
                // Scans exist but none we recognize: it's in the mail stream.
                $result['status']        = 'in_transit';
                $result['status_detail'] = 'Scanned by USPS';
        }

        return $result;
    }

    /**
     * Stage for one scan. Overrides → mail phase → built-in opcode table.
     *
     * @param string $opcode
     * @param string $mail_phase e.g. "Phase 2 - Destination Processing"
     * @return string origin|destination|final|ignore
     */
    public static function classify( $opcode, $mail_phase = '' ) {
        $overrides = self::overrides();
        if ( isset( $overrides[ $opcode ] ) ) {
            return $overrides[ $opcode ];
        }

        if ( preg_match( '/phase\s*(\d)/i', (string) $mail_phase, $m ) ) {
            switch ( (int) $m[1] ) {
                case 0:
                case 1:
                    return 'origin';
                case 2:
                    return 'destination';
                case 3:
                case 4:
                    return 'final';
            }
        }

        if ( isset( self::OPCODES[ $opcode ] ) ) {
            return self::OPCODES[ $opcode ];
        }

        if ( function_exists( 'wc_get_logger' ) && '' !== $opcode ) {
            FST_Letters::log( sprintf( 'Unmapped IV-MTR opcode %s (phase "%s"). Add an override in Settings > Letters if needed.', $opcode, $mail_phase ), 'notice' );
        }
        return 'ignore';
    }

    /**
     * Parse the "opcode=stage" override textarea.
     */
    private static function overrides() {
        static $cache = array();
        static $cached_text = null;
        $text = (string) FST_Letters::setting( 'opcode_map' );
        if ( $text === $cached_text ) {
            return $cache;
        }
        $cached_text = $text;
        $cache       = array();
        foreach ( preg_split( '/\R/', $text ) as $line ) {
            if ( preg_match( '/^\s*(\d{1,3})\s*[=:]\s*(origin|destination|final|ignore)\s*$/i', $line, $m ) ) {
                $cache[ str_pad( $m[1], 3, '0', STR_PAD_LEFT ) ] = strtolower( $m[2] );
            }
        }
        return $cache;
    }

    private static function stage_status( $stage ) {
        $map = array(
            'origin'      => 'in_transit',
            'destination' => 'out_for_delivery',
            'final'       => 'out_for_delivery',
            'ignore'      => 'in_transit',
        );
        return $map[ $stage ];
    }

    private static function describe( $scan, $stage ) {
        $labels = array(
            'origin'      => 'Processed at origin facility',
            'destination' => 'Processed at destination facility',
            'final'       => 'Sorted for carrier route',
            'ignore'      => 'Scanned by USPS',
        );
        if ( '872' === $scan['opcode'] ) {
            return 'Out for delivery';
        }
        return $labels[ $stage ];
    }
}
