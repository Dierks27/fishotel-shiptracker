<?php
/**
 * USPS Letter tracking — core model and lifecycle.
 *
 * A "letter" is a plain stamped First-Class letter carrying a uniquely
 * serialized Intelligent Mail barcode (IMb). Postage is a normal stamp; the
 * IMb is only there so USPS sort machines produce IV-MTR scans we can poll.
 *
 * Each letter is a normal row in wp_fst_shipments (carrier = 'usps_letter',
 * tracking_number = the full IMb digit string) plus a row in wp_fst_letters
 * holding the letter-only details: serial, routing code, address-validation
 * result, mailed/scan timestamps, and admin flags.
 *
 * Package tracking never sees these rows: FST_Shipment::get_due_for_poll()
 * and the analytics queries exclude carrier 'usps_letter', and
 * FST_Tracker::process_status_change() hands letter status changes to
 * FST_Letters::handle_status_change() (letter emails, no order-status moves).
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Letters {

    /** Carrier slug stored in wp_fst_shipments.carrier. */
    const CARRIER = 'usps_letter';

    /** Order meta set by the theme on EA-only orders whose sticker goes by letter. */
    const STICKER_META = '_fishotel_sticker_mail_letter';

    /** wc_get_logger() source. */
    const LOG_SOURCE = 'shiptracker-letters';

    /** USPS IMb serial uniqueness window, in days. */
    const UNIQUENESS_DAYS = 45;

    /** Letter statuses that are still "in flight" (polled + flag-checked). */
    const ACTIVE_STATUSES = array( 'label_created', 'in_transit', 'out_for_delivery' );

    /** Letter statuses that count as "delivery-ish" for the 14-day check. */
    const DELIVERYISH_STATUSES = array( 'out_for_delivery', 'likely_delivered' );

    /* ------------------------------------------------------------------
     * Bootstrapping
     * ---------------------------------------------------------------- */

    public function __construct() {
        // Same cron hook as package polling, after packages + EasyPost backfill.
        add_action( 'fst_poll_shipments', array( __CLASS__, 'run_cron' ), 30 );
    }

    /**
     * Table name for letter details.
     */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'fst_letters';
    }

    /**
     * Is this shipment row a letter?
     *
     * @param object $shipment
     * @return bool
     */
    public static function is_letter_shipment( $shipment ) {
        return $shipment && isset( $shipment->carrier ) && self::CARRIER === $shipment->carrier;
    }

    /* ------------------------------------------------------------------
     * Settings
     * ---------------------------------------------------------------- */

    /**
     * All letter settings with defaults.
     *
     * @return array
     */
    public static function get_settings() {
        $defaults = array(
            'mid'                    => '',
            'crid'                   => '',
            'stid'                   => '',
            'barcode_id'             => '00',
            'return_address'         => '',
            'label_size'             => '4x6',
            'label_custom_width'     => '6',
            'label_custom_height'    => '4',
            'printer_dpi'            => 203,
            'likely_delivered_hours' => 24,
            'no_scan_days'           => 5,
            'stale_days'             => 14,
            'email_in_transit'       => 'yes',
            'email_out_for_delivery' => 'no',
            'email_likely_delivered' => 'yes',
            'email_admin_flags'      => 'yes',
            'opcode_map'             => '',
        );

        $saved = get_option( 'fst_letter_settings', array() );
        if ( ! is_array( $saved ) ) {
            $saved = array();
        }

        return array_merge( $defaults, $saved );
    }

    /**
     * Single setting.
     */
    public static function setting( $key ) {
        $s = self::get_settings();
        return isset( $s[ $key ] ) ? $s[ $key ] : null;
    }

    /**
     * Check the barcode settings are complete enough to build an IMb.
     *
     * @return true|WP_Error
     */
    public static function validate_barcode_settings() {
        $s = self::get_settings();

        if ( ! preg_match( '/^\d{2}$/', $s['barcode_id'] ) || (int) $s['barcode_id'][1] > 4 ) {
            return new WP_Error( 'fst_letter_barcode_id', 'Barcode ID must be 2 digits and the second digit must be 0-4 (normally "00").' );
        }
        if ( ! preg_match( '/^\d{3}$/', $s['stid'] ) ) {
            return new WP_Error( 'fst_letter_stid', 'Service Type ID (STID) is not set. Look it up in the current USPS STID table on PostalPro and enter it under ShipTracker > Settings > Letters.' );
        }
        $mid_len = self::mid_length( $s['mid'] );
        if ( ! $mid_len ) {
            return new WP_Error( 'fst_letter_mid', 'Mailer ID must be 9 digits starting with 9, or 6 digits starting with 0-8.' );
        }
        return true;
    }

    /**
     * 6 or 9 for a valid MID, 0 otherwise. 9-digit MIDs start with 9.
     *
     * @param string $mid
     * @return int
     */
    public static function mid_length( $mid ) {
        $mid = (string) $mid;
        if ( preg_match( '/^9\d{8}$/', $mid ) ) {
            return 9;
        }
        if ( preg_match( '/^[0-8]\d{5}$/', $mid ) ) {
            return 6;
        }
        return 0;
    }

    /**
     * Serial-field width for the configured MID (6 for a 9-digit MID, 9 for a 6-digit MID).
     */
    public static function serial_length() {
        return 9 === self::mid_length( self::setting( 'mid' ) ) ? 6 : 9;
    }

    /* ------------------------------------------------------------------
     * Logging
     * ---------------------------------------------------------------- */

    /**
     * Log to WooCommerce > Status > Logs under source "shiptracker-letters".
     *
     * @param string $message
     * @param string $level   debug|info|notice|warning|error
     * @param array  $context
     */
    public static function log( $message, $level = 'info', $context = array() ) {
        if ( function_exists( 'wc_get_logger' ) ) {
            wc_get_logger()->log( $level, $message, array_merge( array( 'source' => self::LOG_SOURCE ), $context ) );
        }
    }

    /* ------------------------------------------------------------------
     * Serial allocation
     * ---------------------------------------------------------------- */

    /**
     * Atomically take the next serial number.
     *
     * Uses MySQL's LAST_INSERT_ID(expr) trick on the option row so two
     * simultaneous "Print label" clicks can never read the same value: the
     * UPDATE is a single atomic statement and LAST_INSERT_ID is
     * per-connection. Range is 1 .. 10^len - 1, wrapping back to 1.
     *
     * After a wrap, a serial could collide with a letter from the last 45
     * days (only if more than ~1M letters went out in 45 days with a 9-digit
     * MID); allocate_unique_serial() skips those.
     *
     * @param int $len Serial width (6 or 9).
     * @return int|WP_Error
     */
    public static function next_serial( $len ) {
        global $wpdb;

        $max = (int) pow( 10, (int) $len ) - 1; // 999999 or 999999999.

        // Make sure the row exists (no autoload so it's never served stale from alloptions).
        add_option( 'fst_letter_serial', '0', '', 'no' );

        $updated = $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->options}
             SET option_value = LAST_INSERT_ID( ( CAST( option_value AS UNSIGNED ) %% %d ) + 1 )
             WHERE option_name = %s",
            $max,
            'fst_letter_serial'
        ) );

        if ( 1 !== (int) $updated ) {
            return new WP_Error( 'fst_letter_serial', 'Could not increment the letter serial counter.' );
        }

        $serial = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );

        wp_cache_delete( 'fst_letter_serial', 'options' );

        if ( $serial < 1 || $serial > $max ) {
            return new WP_Error( 'fst_letter_serial', 'Serial counter returned an out-of-range value.' );
        }

        return $serial;
    }

    /**
     * Allocate a serial whose tracking code hasn't been used in the last 45 days.
     *
     * @param string $prefix Barcode ID + STID + MID.
     * @param int    $len    Serial width.
     * @return array|WP_Error [ serial string, tracking code ]
     */
    public static function allocate_unique_serial( $prefix, $len ) {
        global $wpdb;

        for ( $attempt = 0; $attempt < 10; $attempt++ ) {
            $serial = self::next_serial( $len );
            if ( is_wp_error( $serial ) ) {
                return $serial;
            }

            $serial_str = str_pad( (string) $serial, $len, '0', STR_PAD_LEFT );
            $tracking   = $prefix . $serial_str;

            $recent = (int) $wpdb->get_var( $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::table() . ' WHERE tracking_code = %s AND created_at > %s',
                $tracking,
                gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::UNIQUENESS_DAYS * DAY_IN_SECONDS )
            ) );

            if ( 0 === $recent ) {
                self::log( sprintf( 'Serial assigned: %s (tracking code %s)', $serial_str, $tracking ) );
                return array( $serial_str, $tracking );
            }

            self::log( sprintf( 'Serial %s already used within %d days, skipping', $serial_str, self::UNIQUENESS_DAYS ), 'warning' );
        }

        return new WP_Error( 'fst_letter_serial', 'Could not find an unused serial number after 10 attempts.' );
    }

    /**
     * Current counter value (read-only display).
     *
     * @return int
     */
    public static function current_serial() {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            'fst_letter_serial'
        ) );
    }

    /**
     * Safe reset: set the counter to a new value, refusing any value that
     * would make the next serials repeat one issued in the last 45 days.
     *
     * @param int $value New counter value (the NEXT serial will be value + 1).
     * @return true|WP_Error
     */
    public static function reset_serial( $value ) {
        global $wpdb;

        $value = (int) $value;
        $len   = self::serial_length();
        $max   = (int) pow( 10, $len ) - 1;

        if ( $value < 0 || $value >= $max ) {
            return new WP_Error( 'fst_letter_serial_range', sprintf( 'Counter must be between 0 and %d.', $max - 1 ) );
        }

        // Block a reset that lands at or below any serial used in the window
        // (the next serials would walk straight back into them).
        $recent_max = (int) $wpdb->get_var( $wpdb->prepare(
            'SELECT MAX( CAST( serial AS UNSIGNED ) ) FROM ' . self::table() . ' WHERE created_at > %s',
            gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::UNIQUENESS_DAYS * DAY_IN_SECONDS )
        ) );

        if ( $recent_max && $value < $recent_max ) {
            return new WP_Error(
                'fst_letter_serial_recent',
                sprintf( 'Serial %d was used in the last %d days. The counter can only be set to %d or higher right now.', $recent_max, self::UNIQUENESS_DAYS, $recent_max )
            );
        }

        add_option( 'fst_letter_serial', '0', '', 'no' );
        $wpdb->update( $wpdb->options, array( 'option_value' => (string) $value ), array( 'option_name' => 'fst_letter_serial' ) );
        wp_cache_delete( 'fst_letter_serial', 'options' );

        self::log( sprintf( 'Serial counter reset to %d by user %d', $value, get_current_user_id() ), 'notice' );
        return true;
    }

    /* ------------------------------------------------------------------
     * Letter rows
     * ---------------------------------------------------------------- */

    public static function get( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
    }

    /**
     * The current (non-voided) letter for a shipment.
     */
    public static function get_by_shipment( $shipment_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE shipment_id = %d AND state != 'voided' ORDER BY id DESC LIMIT 1",
            $shipment_id
        ) );
    }

    /**
     * All letters for an order, newest first (includes voided, for history).
     */
    public static function get_by_order( $order_id ) {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE order_id = %d ORDER BY id DESC',
            $order_id
        ) );
    }

    public static function update( $id, $data ) {
        global $wpdb;
        $data['updated_at'] = current_time( 'mysql' );
        return false !== $wpdb->update( self::table(), $data, array( 'id' => $id ) );
    }

    private static function insert( $data ) {
        global $wpdb;
        $now  = current_time( 'mysql' );
        $data = array_merge( array( 'created_at' => $now, 'updated_at' => $now ), $data );
        if ( false === $wpdb->insert( self::table(), $data ) ) {
            return false;
        }
        return (int) $wpdb->insert_id;
    }

    /* ------------------------------------------------------------------
     * Creating letters
     * ---------------------------------------------------------------- */

    /**
     * Short lock per order so a double-clicked "Print label" can't create two letters.
     */
    private static function acquire_lock( $order_id ) {
        $key = 'fst_letter_lock_' . (int) $order_id;
        // add_option is an INSERT on a unique key → atomic.
        if ( add_option( $key, time(), '', 'no' ) ) {
            return true;
        }
        // Stale lock (crashed request) → clear after 60 s.
        if ( (int) get_option( $key ) < time() - 60 ) {
            delete_option( $key );
            return add_option( $key, time(), '', 'no' );
        }
        return false;
    }

    private static function release_lock( $order_id ) {
        delete_option( 'fst_letter_lock_' . (int) $order_id );
    }

    /**
     * Get the order's current active letter, or create one.
     *
     * "Print letter label" calls this: the first click assigns a serial,
     * builds the IMb and creates the usps_letter shipment; later clicks
     * reuse the same letter (reprints never consume a serial).
     *
     * @param WC_Order $order
     * @param array    $args { contents: 'sticker'|'letter'|... }
     * @return object|WP_Error Letter row.
     */
    public static function get_or_create_for_order( $order, $args = array() ) {
        $existing = self::get_active_unmailed_letter( $order->get_id() );
        if ( $existing ) {
            return $existing;
        }
        return self::create_letter( $order, $args );
    }

    /**
     * Newest letter on the order that hasn't been mailed yet (reprint target).
     */
    public static function get_active_unmailed_letter( $order_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT l.* FROM ' . self::table() . ' l INNER JOIN ' . FST_Shipment::table() . " s ON s.id = l.shipment_id
             WHERE l.order_id = %d AND l.state = 'active' AND l.mailed_at IS NULL ORDER BY l.id DESC LIMIT 1",
            $order_id
        ) );
    }

    /**
     * Create a brand-new letter + shipment for an order (always assigns a new serial).
     *
     * @param WC_Order $order
     * @param array    $args {
     *     @type string $contents   What's inside ('sticker', 'letter', ...). Used in emails.
     *     @type int    $replaces   Letter id this one replaces (resend).
     * }
     * @return object|WP_Error Letter row.
     */
    public static function create_letter( $order, $args = array() ) {
        $order_id = $order->get_id();

        if ( ! self::acquire_lock( $order_id ) ) {
            return new WP_Error( 'fst_letter_locked', 'A letter label is already being created for this order. Try again in a moment.' );
        }

        try {
            $settings_ok = self::validate_barcode_settings();
            if ( is_wp_error( $settings_ok ) ) {
                return $settings_ok;
            }

            $contents = isset( $args['contents'] ) && '' !== $args['contents']
                ? sanitize_text_field( $args['contents'] )
                : self::default_contents( $order );

            // 1. Address → routing code.
            $address    = FST_Letter_Address::from_order( $order );
            $validation = FST_Letter_Address::validate( $address );
            self::log( sprintf(
                'Order %d address validation: %s (routing %s) %s',
                $order_id,
                $validation['status'],
                $validation['routing_code'],
                $validation['message']
            ), 'validated' === $validation['status'] ? 'info' : 'warning' );

            // 2. Serial + tracking code.
            $s        = self::get_settings();
            $len      = self::serial_length();
            $prefix   = $s['barcode_id'] . $s['stid'] . $s['mid'];
            $alloc    = self::allocate_unique_serial( $prefix, $len );
            if ( is_wp_error( $alloc ) ) {
                return $alloc;
            }
            list( $serial, $tracking_code ) = $alloc;

            // 3. Build + verify the barcode.
            $routing = $validation['routing_code'];
            try {
                FST_IMb::encode( $tracking_code, $routing );
            } catch ( InvalidArgumentException $e ) {
                self::log( 'IMb build failed: ' . $e->getMessage(), 'error' );
                return new WP_Error( 'fst_letter_imb', 'Could not build the barcode: ' . $e->getMessage() );
            }
            $imb = $tracking_code . $routing;
            self::log( sprintf( 'IMb built for order %d: %s', $order_id, FST_IMb::format_human( $tracking_code, $routing, self::mid_length( $s['mid'] ) ) ) );

            // 4. Shipment row (status Label Created, tracking ID = IMb).
            $shipment_id = FST_Shipment::create( array(
                'order_id'        => $order_id,
                'tracking_number' => $imb,
                'carrier'         => self::CARRIER,
                'status'          => 'label_created',
                'status_detail'   => 'Letter label created — not mailed yet',
                'ship_date'       => null,
            ) );
            if ( ! $shipment_id ) {
                return new WP_Error( 'fst_letter_shipment', 'Could not create the ShipTracker shipment.' );
            }

            // 5. Letter row.
            $letter_id = self::insert( array(
                'shipment_id'     => $shipment_id,
                'order_id'        => $order_id,
                'imb'             => $imb,
                'tracking_code'   => $tracking_code,
                'routing_code'    => $routing,
                'serial'          => $serial,
                'contents'        => $contents,
                'recipient'       => wp_json_encode( $validation['address'] ),
                'address_status'  => $validation['status'],
                'address_message' => $validation['message'],
                'state'           => 'active',
                'replaces'        => isset( $args['replaces'] ) ? (int) $args['replaces'] : 0,
            ) );
            if ( ! $letter_id ) {
                FST_Shipment::delete( $shipment_id );
                return new WP_Error( 'fst_letter_insert', 'Could not save the letter record.' );
            }

            $note = sprintf( 'ShipTracker: USPS letter label created (%s). IMb %s.', $contents, $imb );
            if ( 'validated' !== $validation['status'] ) {
                $note .= ' Address not fully validated: ' . $validation['message'];
                $order->update_meta_data( '_fst_letter_address_warning', $validation['message'] );
            } else {
                $order->delete_meta_data( '_fst_letter_address_warning' );
            }
            $order->add_order_note( $note, false );
            $order->save();

            return self::get( $letter_id );
        } finally {
            self::release_lock( $order_id );
        }
    }

    /**
     * Void a letter's barcode and issue a new serial on the same shipment.
     *
     * @param int $letter_id
     * @return object|WP_Error New letter row.
     */
    public static function void_and_regenerate( $letter_id ) {
        $old = self::get( $letter_id );
        if ( ! $old || 'voided' === $old->state ) {
            return new WP_Error( 'fst_letter_missing', 'Letter not found or already voided.' );
        }

        $order = wc_get_order( $old->order_id );
        if ( ! $order ) {
            return new WP_Error( 'fst_letter_order', 'Order not found.' );
        }

        $new = self::create_letter( $order, array( 'contents' => $old->contents, 'replaces' => $old->id ) );
        if ( is_wp_error( $new ) ) {
            return $new;
        }

        // Move the new barcode onto the ORIGINAL shipment row and drop the
        // temporary one create_letter() made, so the order keeps one shipment.
        $tmp_shipment_id = (int) $new->shipment_id;
        if ( $old->shipment_id ) {
            FST_Shipment::update( $old->shipment_id, array(
                'tracking_number' => $new->imb,
                'status'          => 'label_created',
                'status_detail'   => 'Letter label regenerated — not mailed yet',
                'ship_date'       => null,
                'last_checked'    => null,
                'last_event_at'   => null,
                'tracking_data'   => '',
                'check_count'     => 0,
            ) );
            global $wpdb;
            $wpdb->delete( FST_Shipment::events_table(), array( 'shipment_id' => $old->shipment_id ), array( '%d' ) );
            self::update( $new->id, array( 'shipment_id' => $old->shipment_id ) );
            FST_Shipment::delete( $tmp_shipment_id );
        }

        self::update( $old->id, array(
            'state'       => 'voided',
            'replaced_by' => $new->id,
            'flag'        => '',
        ) );

        self::log( sprintf( 'Letter %d voided (IMb %s) and regenerated as letter %d (IMb %s) by user %d', $old->id, $old->imb, $new->id, $new->imb, get_current_user_id() ), 'notice' );
        $order->add_order_note( sprintf( 'ShipTracker: letter barcode %s voided and regenerated as %s.', $old->imb, $new->imb ), false );

        return self::get( $new->id );
    }

    /**
     * Resend: a fresh letter shipment on the same order (new serial).
     * The old letter keeps being tracked but is no longer flagged.
     *
     * @param int $letter_id
     * @return object|WP_Error New letter row.
     */
    public static function resend( $letter_id ) {
        $old = self::get( $letter_id );
        if ( ! $old ) {
            return new WP_Error( 'fst_letter_missing', 'Letter not found.' );
        }
        $order = wc_get_order( $old->order_id );
        if ( ! $order ) {
            return new WP_Error( 'fst_letter_order', 'Order not found.' );
        }

        $new = self::create_letter( $order, array( 'contents' => $old->contents, 'replaces' => $old->id ) );
        if ( is_wp_error( $new ) ) {
            return $new;
        }

        self::update( $old->id, array(
            'state'          => 'resent',
            'replaced_by'    => $new->id,
            'flag'           => '',
            'flag_dismissed' => 1,
        ) );

        self::log( sprintf( 'Letter %d resent as letter %d for order %d', $old->id, $new->id, $old->order_id ), 'notice' );
        $order->add_order_note( sprintf( 'ShipTracker: %s resent. New letter barcode %s (previous %s).', $old->contents, $new->imb, $old->imb ), false );

        return $new;
    }

    /**
     * Mark a letter mailed: status → In transit and the "on its way" email.
     *
     * @param int    $letter_id
     * @param string $when      MySQL datetime (site time). Default now.
     * @return true|WP_Error
     */
    public static function mark_mailed( $letter_id, $when = '' ) {
        $letter = self::get( $letter_id );
        if ( ! $letter || 'voided' === $letter->state ) {
            return new WP_Error( 'fst_letter_missing', 'Letter not found.' );
        }
        if ( $letter->mailed_at ) {
            return new WP_Error( 'fst_letter_mailed', 'This letter is already marked mailed.' );
        }

        $when = $when ? $when : current_time( 'mysql' );
        self::update( $letter->id, array( 'mailed_at' => $when ) );

        $shipment = FST_Shipment::get( $letter->shipment_id );
        if ( ! $shipment ) {
            return new WP_Error( 'fst_letter_shipment', 'Shipment not found.' );
        }

        $update = array( 'ship_date' => $when );
        $old    = $shipment->status;

        if ( 'label_created' === $old ) {
            $update['status']        = 'in_transit';
            $update['status_detail'] = 'Mailed — waiting for the first USPS scan';
        }
        FST_Shipment::update( $shipment->id, $update );

        self::log( sprintf( 'Letter %d marked mailed at %s', $letter->id, $when ) );

        if ( 'label_created' === $old ) {
            self::handle_status_change( FST_Shipment::get( $shipment->id ), $old, 'in_transit' );
        }

        return true;
    }

    /**
     * Record that a label was printed (count + timestamp).
     */
    public static function record_print( $letter ) {
        self::update( $letter->id, array(
            'label_printed_at' => current_time( 'mysql' ),
            'print_count'      => (int) $letter->print_count + 1,
        ) );
    }

    /**
     * What's in the envelope, for email copy.
     */
    public static function default_contents( $order ) {
        return $order && $order->get_meta( self::STICKER_META ) ? 'sticker' : 'letter';
    }

    /* ------------------------------------------------------------------
     * Status changes + emails
     * ---------------------------------------------------------------- */

    /**
     * Side effects for a letter status change. Called by FST_Tracker for
     * usps_letter shipments instead of the package path, and by mark_mailed().
     *
     * Letters never change the WooCommerce order status and never say "Delivered".
     *
     * @param object $shipment
     * @param string $old_status
     * @param string $new_status
     */
    public static function handle_status_change( $shipment, $old_status, $new_status ) {
        $order = wc_get_order( $shipment->order_id );
        if ( ! $order ) {
            return;
        }

        $letter = self::get_by_shipment( $shipment->id );

        // First real scan without "Mark mailed" → auto-mark.
        if ( $letter && ! $letter->mailed_at && 'label_created' !== $new_status ) {
            $first = $letter->first_scan_at ? $letter->first_scan_at : current_time( 'mysql' );
            self::update( $letter->id, array( 'mailed_at' => $first ) );
            if ( empty( $shipment->ship_date ) ) {
                FST_Shipment::update( $shipment->id, array( 'ship_date' => $first ) );
            }
        }

        $order->add_order_note( sprintf(
            'Letter %s status changed: %s → %s',
            $shipment->tracking_number,
            FST_Carrier::get_status_label( $old_status ),
            FST_Carrier::get_status_label( $new_status )
        ), false );

        $s   = self::get_settings();
        $key = 'email_' . $new_status;
        if ( isset( $s[ $key ] ) && 'yes' === $s[ $key ] ) {
            // Never email twice for the same status on the same letter.
            $sent_key = '_fst_letter_emailed_' . $shipment->id . '_' . $new_status;
            if ( ! $order->get_meta( $sent_key ) ) {
                $send = apply_filters( 'fst_send_customer_email', true, $shipment, $order, $new_status );
                if ( $send && self::send_customer_email( $shipment, $order, $new_status, $letter ) ) {
                    $order->update_meta_data( $sent_key, current_time( 'mysql' ) );
                    $order->save();
                }
            }
        }

        do_action( 'fst_letter_status_changed', $shipment, $old_status, $new_status, $order, $letter );
    }

    /**
     * Default letter email copy. Package wording ("package", "carrier",
     * "delivered") doesn't fit a stamped letter, so letters get their own.
     *
     * @return array status => [subject, body]
     */
    public static function default_templates() {
        return array(
            'in_transit' => array(
                'subject' => 'Your {letter_contents} is on its way!',
                'body'    => "Hi {customer_name},\n\nYour {letter_contents} for order #{order_number} is in the mail! It's traveling by regular First-Class letter mail, which usually takes 2–5 business days.\n\n{tracking_progress}\n\nWe'll send you a note when it's close.",
            ),
            'out_for_delivery' => array(
                'subject' => 'Your {letter_contents} has reached your local post office',
                'body'    => "Hi {customer_name},\n\nGood news: your {letter_contents} for order #{order_number} has been sorted for your mail carrier's route. It should show up in your mailbox within a day or two.\n\n{tracking_progress}",
            ),
            'likely_delivered' => array(
                'subject' => 'Your {letter_contents} should be in your mailbox',
                'body'    => "Hi {customer_name},\n\nYour {letter_contents} for order #{order_number} was sorted for your carrier's route and hasn't been scanned since, so it should be in your mailbox now. (Letters don't get a delivery scan, so we can't confirm it 100%.)\n\n{tracking_progress}\n\nIf it hasn't turned up in a few days, just reply to this email and we'll sort it out.",
            ),
        );
    }

    /**
     * Saved (or default) template for a status.
     */
    public static function get_template( $status ) {
        $saved    = get_option( 'fst_letter_email_templates', array() );
        $defaults = self::default_templates();
        $tpl      = isset( $saved[ $status ] ) ? $saved[ $status ] : array();

        if ( empty( $tpl['subject'] ) || empty( $tpl['body'] ) ) {
            $tpl = isset( $defaults[ $status ] ) ? $defaults[ $status ] : null;
        }
        return $tpl;
    }

    /**
     * Build + send the letter email to the customer.
     *
     * @return bool Sent.
     */
    public static function send_customer_email( $shipment, $order, $status, $letter = null ) {
        $to = $order->get_billing_email();
        if ( ! $to ) {
            return false;
        }

        $rendered = self::render_email( $status, self::build_replacements( $shipment, $order, $letter, $status ) );
        if ( ! $rendered ) {
            return false;
        }

        $sent = wp_mail( $to, $rendered['subject'], $rendered['html_body'], array( 'Content-Type: text/html; charset=UTF-8' ) );
        self::log( sprintf( 'Letter email (%s) to %s for order %d: %s', $status, $to, $order->get_id(), $sent ? 'sent' : 'FAILED' ), $sent ? 'info' : 'error' );
        return $sent;
    }

    /**
     * Render a letter email.
     *
     * @return array|null { subject, html_body }
     */
    public static function render_email( $status, array $replacements ) {
        $tpl = self::get_template( $status );
        if ( ! $tpl ) {
            return null;
        }

        $subject = str_replace( array_keys( $replacements ), array_values( $replacements ), $tpl['subject'] );
        $body    = str_replace( array_keys( $replacements ), array_values( $replacements ), $tpl['body'] );

        return array(
            'subject'   => $subject,
            'html_body' => FST_Email::wrap( nl2br( $body ), $status ),
        );
    }

    /**
     * Shortcode map for letter emails.
     */
    public static function build_replacements( $shipment, $order, $letter = null, $status = '' ) {
        $status   = $status ? $status : $shipment->status;
        $contents = $letter ? $letter->contents : self::default_contents( $order );

        return array(
            '{customer_name}'     => $order->get_billing_first_name(),
            '{order_number}'      => $order->get_order_number(),
            '{letter_contents}'   => $contents,
            '{tracking_number}'   => $shipment->tracking_number,
            '{carrier}'           => 'USPS',
            '{status}'            => FST_Carrier::get_status_label( $status ),
            '{status_detail}'     => $shipment->status_detail,
            '{ship_date}'         => $shipment->ship_date ? date_i18n( get_option( 'date_format' ), strtotime( $shipment->ship_date ) ) : '',
            '{tracking_progress}' => self::render_progress_bar( $status ),
            '{tracking_timeline}' => self::render_progress_bar( $status ),
            '{tracking_events}'   => FST_Email::render_events( $shipment->id ),
            '{order_summary}'     => '',
            '{track_button}'      => '',
            '{est_delivery}'      => '',
            '{tracking_url}'      => '',
            '{carrier_tracking_url}' => '',
        );
    }

    /**
     * Letter progress steps (shared by email + My Account).
     */
    public static function progress_steps() {
        return array(
            'label_created'    => 'Label Created',
            'in_transit'       => 'Mailed',
            'out_for_delivery' => 'At Local Post Office',
            'likely_delivered' => 'Likely Delivered',
        );
    }

    /**
     * Step index for a letter status.
     */
    public static function progress_index( $status ) {
        $map = array(
            'label_created'    => 0,
            'in_transit'       => 1,
            'out_for_delivery' => 2,
            'likely_delivered' => 3,
        );
        return isset( $map[ $status ] ) ? $map[ $status ] : -1;
    }

    /**
     * Letter progress bar for email — same look as FST_Email::render_progress_bar().
     */
    public static function render_progress_bar( $status ) {
        $current = self::progress_index( $status );
        $accent  = FST_Carrier::get_status_color( $status );

        $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin: 16px 0 24px;"><tr>';
        $i    = 0;
        foreach ( self::progress_steps() as $label ) {
            $done  = $i <= $current;
            $dot   = $done ? $accent : '#e0e0e0';
            $html .= '<td width="25%" align="center" style="padding: 0 4px;">';
            $html .= '<div style="width: 18px; height: 18px; border-radius: 50%; background: ' . $dot . '; border: 2px solid ' . $dot . '; margin: 0 auto 6px;"></div>';
            $html .= '<div style="font-size: 11px; color: ' . ( $done ? '#333' : '#bbb' ) . '; font-weight: ' . ( $done ? '600' : '400' ) . ';">' . esc_html( $label ) . '</div>';
            $html .= '</td>';
            $i++;
        }
        return $html . '</tr></table>';
    }

    /* ------------------------------------------------------------------
     * Cron: polling + flags
     * ---------------------------------------------------------------- */

    /**
     * Runs on the shared fst_poll_shipments action.
     */
    public static function run_cron() {
        self::poll_due_letters();
        self::check_flags();
    }

    /**
     * Letter shipments due for an IV-MTR poll.
     *
     * Cadence: unmailed labels every 6 h (to auto-detect mailing) for up to
     * 30 days; mailed letters every polling interval; after 30 days daily.
     *
     * @return array Shipment rows.
     */
    public static function get_due_for_poll( $limit = 50 ) {
        global $wpdb;

        $shipments = FST_Shipment::table();
        $letters   = self::table();
        $interval  = max( 15, (int) ( (int) get_option( 'fst_polling_interval', 3600 ) / 60 ) );
        $now       = current_time( 'mysql' );

        return $wpdb->get_results( $wpdb->prepare(
            "SELECT s.* FROM {$shipments} s
             INNER JOIN {$letters} l ON l.shipment_id = s.id AND l.state != 'voided'
             WHERE s.carrier = %s
             AND s.status IN ('label_created','in_transit','out_for_delivery')
             AND (
                 ( s.status = 'label_created' AND l.created_at > DATE_SUB(%s, INTERVAL 30 DAY)
                   AND ( s.last_checked IS NULL OR s.last_checked < DATE_SUB(%s, INTERVAL 6 HOUR) ) )
                 OR ( s.status != 'label_created' AND DATEDIFF(%s, COALESCE(l.mailed_at, s.created_at)) <= 30
                   AND ( s.last_checked IS NULL OR s.last_checked < DATE_SUB(%s, INTERVAL %d MINUTE) ) )
                 OR ( s.status != 'label_created' AND DATEDIFF(%s, COALESCE(l.mailed_at, s.created_at)) > 30
                   AND ( s.last_checked IS NULL OR s.last_checked < DATE_SUB(%s, INTERVAL 1 DAY) ) )
             )
             ORDER BY s.last_checked ASC
             LIMIT %d",
            self::CARRIER,
            $now, $now,
            $now, $now, $interval,
            $now, $now,
            $limit
        ) );
    }

    /**
     * Poll due letters through the normal tracker (FST_Tracker::poll_shipment →
     * FST_Carrier_USPS_Letter::track → IV-MTR).
     */
    public static function poll_due_letters() {
        $carrier = new FST_Carrier_USPS_Letter();
        if ( ! $carrier->has_credentials() ) {
            return; // IV-MTR not set up yet — letters still work with manual "Mark mailed".
        }

        $due = self::get_due_for_poll();
        if ( empty( $due ) ) {
            return;
        }

        self::log( sprintf( 'IV-MTR poll cycle: %d letter(s) due', count( $due ) ), 'debug' );

        $tracker = FST()->tracker ? FST()->tracker : new FST_Tracker();
        foreach ( $due as $shipment ) {
            $tracker->poll_shipment( $shipment );
        }
    }

    /**
     * Raise admin flags:
     *  - no_scan: mailed N days ago (default 5) and still zero scans.
     *  - stale:   N days (default 14) after mailing with no delivery-ish status.
     * Flags are admin-only. Customers never hear about them.
     */
    public static function check_flags() {
        global $wpdb;

        $s        = self::get_settings();
        $no_scan  = max( 1, (int) $s['no_scan_days'] );
        $stale    = max( 1, (int) $s['stale_days'] );
        $now      = current_time( 'mysql' );
        $letters  = self::table();
        $ship     = FST_Shipment::table();

        // 14-day stale (takes priority over no_scan).
        $stale_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.* FROM {$letters} l INNER JOIN {$ship} s ON s.id = l.shipment_id
             WHERE l.state = 'active' AND l.mailed_at IS NOT NULL
             AND l.mailed_at < DATE_SUB(%s, INTERVAL %d DAY)
             AND s.status NOT IN ('out_for_delivery','likely_delivered')
             AND l.flag != 'stale'",
            $now,
            $stale
        ) );
        foreach ( $stale_rows as $row ) {
            self::raise_flag( $row, 'stale', sprintf( 'No delivery-type scan %d days after mailing.', $stale ) );
        }

        // 5-day no-scan.
        $no_scan_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT l.* FROM {$letters} l INNER JOIN {$ship} s ON s.id = l.shipment_id
             WHERE l.state = 'active' AND l.mailed_at IS NOT NULL
             AND l.mailed_at < DATE_SUB(%s, INTERVAL %d DAY)
             AND l.scan_count = 0
             AND l.flag = ''",
            $now,
            $no_scan
        ) );
        foreach ( $no_scan_rows as $row ) {
            self::raise_flag( $row, 'no_scan', sprintf( 'No USPS scans %d days after mailing. It may have been hand-sorted (invisible to IV-MTR).', $no_scan ) );
        }
    }

    /**
     * Set a flag, note it on the order, optionally email the admin.
     */
    private static function raise_flag( $letter, $flag, $message ) {
        self::update( $letter->id, array(
            'flag'           => $flag,
            'flagged_at'     => current_time( 'mysql' ),
            'flag_dismissed' => 0,
        ) );

        self::log( sprintf( 'Letter %d (order %d) flagged %s: %s', $letter->id, $letter->order_id, $flag, $message ), 'warning' );

        $order = wc_get_order( $letter->order_id );
        if ( $order ) {
            $order->add_order_note( 'ShipTracker letter flag: ' . $message, false );
        }

        if ( 'yes' === self::setting( 'email_admin_flags' ) && $order ) {
            $subject = sprintf( '[ShipTracker] Letter for order #%s needs a look', $order->get_order_number() );
            $body    = sprintf(
                "%s\n\nOrder: #%s (%s)\nIMb: %s\nMailed: %s\n\n%s",
                $message,
                $order->get_order_number(),
                $order->get_formatted_billing_full_name(),
                $letter->imb,
                $letter->mailed_at,
                $order->get_edit_order_url()
            );
            wp_mail( get_option( 'admin_email' ), $subject, $body );
        }
    }

    /**
     * Active, undismissed flags for the admin notice.
     *
     * @return array Letter rows.
     */
    public static function get_open_flags() {
        global $wpdb;
        return $wpdb->get_results(
            'SELECT l.* FROM ' . self::table() . ' l INNER JOIN ' . FST_Shipment::table() . " s ON s.id = l.shipment_id
             WHERE l.state = 'active' AND l.flag != '' AND l.flag_dismissed = 0 ORDER BY l.flagged_at ASC LIMIT 50"
        );
    }

    /**
     * Record scan stats on the letter (called by the carrier after each poll).
     *
     * @param string $imb
     * @param array  $scans Normalized scans (oldest first).
     */
    public static function record_scans( $imb, array $scans ) {
        global $wpdb;
        $letter = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . " WHERE imb = %s AND state != 'voided' ORDER BY id DESC LIMIT 1",
            $imb
        ) );
        if ( ! $letter ) {
            return;
        }

        $data = array( 'scan_count' => count( $scans ) );
        if ( ! empty( $scans ) ) {
            $data['first_scan_at'] = $scans[0]['event_time'];
            $data['last_scan_at']  = $scans[ count( $scans ) - 1 ]['event_time'];
            // A no_scan flag clears itself once scans appear.
            if ( 'no_scan' === $letter->flag ) {
                $data['flag'] = '';
            }
        }
        self::update( $letter->id, $data );
    }
}
