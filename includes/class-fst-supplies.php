<?php
/**
 * Supplies — everything a box costs besides its label.
 *
 * Three things live here:
 *  - the Supplies list (Settings → Boxes & label costs): boxes, fish bags,
 *    heat packs, ice packs, foam, paper … with a purchase log per supply and,
 *    for the ones that are counted down, the stock on hand;
 *  - the materials of a box: lines of supply + quantity + unit cost and two
 *    flat amounts (foam, paper), SNAPSHOTTED when recorded so a later price
 *    or setting change never rewrites history;
 *  - the maths around both (price each, on hand, defaults, suggestions).
 *
 * "One box, one cost" applies here too: materials are keyed by tracking
 * number in wp_fst_supply_usage, never by shipment row, so a box shared by
 * several orders is counted once. A local pickup has no tracking number; its
 * materials are keyed by the order id in the same table, which keeps "used"
 * a single query.
 *
 * Quantities are whole hundredths, unit costs whole ten-thousandths of a
 * dollar and money whole cents while it is being worked on — no floats.
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Supplies {

    /**
     * Options: the supplies list, the highest supply id ever given out (ids
     * are never reused, so a removed supply's history stays its own) and the
     * foam waste percent.
     */
    const OPTION         = 'fst_supplies';
    const OPTION_LAST_ID = 'fst_supply_last_id';
    const OPTION_WASTE   = 'fst_foam_waste_percent';

    /**
     * Waste added to a suggested foam cost, in percent, until the owner
     * sets another.
     */
    const DEFAULT_WASTE = '15';

    /**
     * What a supply can be used for. At most one supply per role, except
     * `box`: one per standard box.
     */
    const ROLES = array( 'heat_pack', 'ice_pack', 'fish_bag', 'foam', 'paper', 'box' );

    /**
     * The parts a materials total is split into on the reports.
     */
    const SPLIT_KEYS = array( 'boxes', 'foam', 'paper', 'bags', 'packs', 'other' );

    // -----------------------------------------------------------------
    // Tables.
    // -----------------------------------------------------------------

    /**
     * Purchase log: one row per case / sheet / roll bought.
     */
    public static function purchases_table() {
        global $wpdb;
        return $wpdb->prefix . 'fst_supply_purchases';
    }

    /**
     * Materials used, per box (tracking number) or per local pickup (order).
     */
    public static function usage_table() {
        global $wpdb;
        return $wpdb->prefix . 'fst_supply_usage';
    }

    /**
     * Recounts: the correction each one made to the stock.
     */
    public static function counts_table() {
        global $wpdb;
        return $wpdb->prefix . 'fst_supply_counts';
    }

    // -----------------------------------------------------------------
    // Numbers.
    // -----------------------------------------------------------------

    /**
     * Read a quantity: 0 or more, at most two decimals. Nothing is rounded —
     * "1.005 bags" is a typing slip, not 1.01.
     *
     * @param mixed $raw
     * @return int|null|false Whole hundredths, null when empty, false when
     *                        negative, not a number or more than two decimals.
     */
    public static function parse_qty( $raw ) {
        if ( null === $raw ) {
            return null;
        }
        if ( is_bool( $raw ) || ! is_scalar( $raw ) ) {
            return false;
        }
        if ( is_float( $raw ) ) {
            if ( ! is_finite( $raw ) || $raw < 0 || abs( $raw * 100 - round( $raw * 100 ) ) > 0.000001 ) {
                return false;
            }
            $raw = number_format( $raw, 2, '.', '' );
        }

        $value = trim( (string) $raw );
        if ( '' === $value ) {
            return null;
        }
        if ( ! preg_match( '/^(\d{0,9})(?:\.(\d{0,2}))?$/', $value, $m ) ) {
            return false;
        }
        $frac = isset( $m[2] ) ? $m[2] : '';
        if ( '' === $m[1] && '' === $frac ) {
            return false;
        }

        return (int) $m[1] * 100 + (int) str_pad( $frac, 2, '0' );
    }

    /**
     * Whole hundredths as a two-decimal string: -250 is "-2.50".
     *
     * @param int $hundredths
     * @return string
     */
    public static function format_qty( $hundredths ) {
        $hundredths = (int) $hundredths;
        return ( $hundredths < 0 ? '-' : '' ) . sprintf( '%d.%02d', intdiv( abs( $hundredths ), 100 ), abs( $hundredths ) % 100 );
    }

    /**
     * A stored DECIMAL(12,2) as whole hundredths.
     *
     * @param mixed $decimal
     * @return int
     */
    public static function to_hundredths( $decimal ) {
        return (int) round( (float) $decimal * 100 );
    }

    /**
     * Read a unit cost: 0 or more, up to four decimals ("$0.1319").
     *
     * @param mixed $raw
     * @return int|null|false Whole ten-thousandths of a dollar, null when
     *                        empty, false when it is not such an amount.
     */
    public static function parse_rate( $raw ) {
        if ( null === $raw ) {
            return null;
        }
        if ( is_bool( $raw ) || ! is_scalar( $raw ) ) {
            return false;
        }
        if ( is_float( $raw ) ) {
            if ( ! is_finite( $raw ) || $raw < 0 ) {
                return false;
            }
            $raw = number_format( $raw, 4, '.', '' );
        }

        $value = preg_replace( '/^\$\s*/', '', trim( (string) $raw ) );
        if ( '' === $value ) {
            return '' === trim( (string) $raw ) ? null : false;
        }
        if ( ! preg_match( '/^(\d{0,8})(?:\.(\d{0,4}))?$/', $value, $m ) ) {
            return false;
        }
        $frac = isset( $m[2] ) ? $m[2] : '';
        if ( '' === $m[1] && '' === $frac ) {
            return false;
        }

        return (int) $m[1] * 10000 + (int) str_pad( $frac, 4, '0' );
    }

    /**
     * Whole ten-thousandths as a four-decimal string: 6000 is "0.6000".
     *
     * @param int $ten_thousandths
     * @return string
     */
    public static function format_rate( $ten_thousandths ) {
        $ten_thousandths = max( 0, (int) $ten_thousandths );
        return sprintf( '%d.%04d', intdiv( $ten_thousandths, 10000 ), $ten_thousandths % 10000 );
    }

    /**
     * A stored DECIMAL(12,4) as whole ten-thousandths.
     *
     * @param mixed $decimal
     * @return int
     */
    private static function to_ten_thousandths( $decimal ) {
        return (int) round( (float) $decimal * 10000 );
    }

    /**
     * Quantity × unit cost in whole cents, rounded half up.
     *
     * @param int $qty  Whole hundredths.
     * @param int $rate Whole ten-thousandths of a dollar.
     * @return int
     */
    public static function line_cents( $qty, $rate ) {
        return intdiv( (int) $qty * (int) $rate * 2 + 10000, 20000 );
    }

    // -----------------------------------------------------------------
    // The supplies list.
    // -----------------------------------------------------------------

    /**
     * Role => name shown in the settings dropdown and the reports.
     *
     * @return array
     */
    public static function role_labels() {
        return array(
            'heat_pack' => __( 'Heat pack', 'fishotel-shiptracker' ),
            'ice_pack'  => __( 'Ice pack', 'fishotel-shiptracker' ),
            'fish_bag'  => __( 'Fish bag', 'fishotel-shiptracker' ),
            'foam'      => __( 'Foam', 'fishotel-shiptracker' ),
            'paper'     => __( 'Paper', 'fishotel-shiptracker' ),
            'box'       => __( 'Box', 'fishotel-shiptracker' ),
        );
    }

    /**
     * Clean a submitted (or stored) supplies list.
     *
     * A row needs a name; a second supply with the same name is dropped
     * (input finds a supply by name). A second supply with the same role
     * keeps its row but loses the role. A row without an id is new and
     * takes the next id.
     *
     * @param mixed $raw            [ { id, name, unit, counted, reorder_level, role, box_name,
     *                              sheet_length, sheet_width, thickness } ] — `role` may also be
     *                              "box:<standard box name>" as the settings dropdown posts it.
     * @param array $standard_boxes FST_Settings::get_standard_boxes() shape, to tie a box supply to.
     * @param int   $last_id        Highest supply id given out so far.
     * @return array { supplies: array[], last_id: int }
     */
    public static function sanitize_supplies( $raw, array $standard_boxes = array(), $last_id = 0 ) {
        $supplies   = array();
        $seen_names = array();
        $seen_roles = array();
        $used_ids   = array();
        $last_id    = (int) $last_id;

        foreach ( (array) $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }

            $name = isset( $row['name'] ) && is_scalar( $row['name'] )
                ? FST_Label_Cost::limit_length( sanitize_text_field( (string) $row['name'] ), 60 )
                : '';
            $key  = FST_Label_Cost::lower( $name );
            if ( '' === $name || isset( $seen_names[ $key ] ) ) {
                continue;
            }
            $seen_names[ $key ] = true;

            $id = isset( $row['id'] ) && is_scalar( $row['id'] ) ? absint( $row['id'] ) : 0;
            if ( ! $id || isset( $used_ids[ $id ] ) ) {
                $last_id++;
                $id = $last_id;
            }
            $last_id         = max( $last_id, $id );
            $used_ids[ $id ] = true;

            $unit = isset( $row['unit'] ) && is_scalar( $row['unit'] )
                ? FST_Label_Cost::limit_length( sanitize_text_field( (string) $row['unit'] ), 20 )
                : '';
            if ( '' === $unit ) {
                $unit = 'each';
            }

            $counted = ! empty( $row['counted'] );
            $reorder = null;
            if ( $counted && isset( $row['reorder_level'] ) ) {
                $level = self::parse_qty( $row['reorder_level'] );
                if ( is_int( $level ) ) {
                    $reorder = self::format_qty( $level );
                }
            }

            // What the supply is used for.
            $role     = isset( $row['role'] ) && is_scalar( $row['role'] ) ? (string) $row['role'] : '';
            $box_name = isset( $row['box_name'] ) && is_scalar( $row['box_name'] ) ? (string) $row['box_name'] : '';
            if ( 0 === strpos( $role, 'box:' ) ) {
                $box_name = substr( $role, 4 );
                $role     = 'box';
            }
            if ( ! in_array( $role, self::ROLES, true ) ) {
                $role = '';
            }
            if ( 'box' === $role ) {
                $box      = self::standard_box_by_name( $box_name, $standard_boxes );
                $box_name = $box ? $box['name'] : '';
                if ( ! $box ) {
                    $role = '';
                }
            } else {
                $box_name = '';
            }
            $role_key = 'box' === $role ? 'box:' . FST_Label_Cost::lower( $box_name ) : $role;
            if ( '' !== $role ) {
                if ( isset( $seen_roles[ $role_key ] ) ) {
                    $role     = '';
                    $box_name = '';
                } else {
                    $seen_roles[ $role_key ] = true;
                }
            }

            $supply = array(
                'id'            => $id,
                'name'          => $name,
                'unit'          => $unit,
                'counted'       => $counted,
                'reorder_level' => $reorder,
                'role'          => $role,
                'box_name'      => $box_name,
            );

            // Foam is bought in sheets: the sheet size prices a square foot.
            if ( 'foam' === $role ) {
                $supply['sheet_length'] = self::positive_number( isset( $row['sheet_length'] ) ? $row['sheet_length'] : '', '8' );
                $supply['sheet_width']  = self::positive_number( isset( $row['sheet_width'] ) ? $row['sheet_width'] : '', '4' );
                $supply['thickness']    = self::positive_number( isset( $row['thickness'] ) ? $row['thickness'] : '', '0.5' );
            }

            $supplies[] = $supply;
        }

        return array(
            'supplies' => $supplies,
            'last_id'  => $last_id,
        );
    }

    /**
     * A number above 0 with at most two decimals, trailing zeros dropped
     * ("8", "0.5"), or the default when it is anything else.
     *
     * @param mixed  $raw
     * @param string $default
     * @return string
     */
    private static function positive_number( $raw, $default ) {
        $hundredths = self::parse_qty( $raw );
        if ( ! is_int( $hundredths ) || $hundredths <= 0 ) {
            return $default;
        }
        return FST_Label_Cost::trim_decimal( self::format_qty( $hundredths ) );
    }

    /**
     * The saved supplies, without prices or stock — no database access.
     *
     * @return array[] sanitize_supplies() rows.
     */
    public static function get_supplies_raw() {
        $clean = self::sanitize_supplies(
            get_option( self::OPTION, array() ),
            self::standard_boxes(),
            (int) get_option( self::OPTION_LAST_ID, 0 )
        );
        return $clean['supplies'];
    }

    /**
     * The saved supplies with what one costs now and how many are on hand.
     *
     * Public contract — the theme's Shipments box reads this and
     * GET /fst/v1/shipping-options returns it.
     *
     * @return array[] Each: id, name, unit, counted, reorder_level ("10.00" or
     *                 null), role ('' or one of ROLES), box_name, for foam
     *                 sheet_length / sheet_width / thickness, plus price_each
     *                 ("0.6000"; null until a purchase is logged), on_hand
     *                 ("34.00", never below 0; null when the supply is not
     *                 counted), recount_needed (the books say less than 0)
     *                 and low (at or under its reorder level).
     */
    public static function get_supplies() {
        $prices = self::get_prices();
        $stock  = self::get_stock();

        $supplies = array();
        foreach ( self::get_supplies_raw() as $supply ) {
            $id    = $supply['id'];
            $level = isset( $stock[ $id ] ) ? $stock[ $id ] : self::stock_from_totals( 0, 0, 0 );

            $supply['price_each']     = isset( $prices[ $id ] ) ? $prices[ $id ] : null;
            $supply['on_hand']        = $supply['counted'] ? $level['on_hand'] : null;
            $supply['recount_needed'] = $supply['counted'] && $level['recount_needed'];
            $supply['low']            = self::is_low( $supply, $level );

            $supplies[] = $supply;
        }

        return $supplies;
    }

    /**
     * Find a supply by id or, failing that, by name (any letter case).
     *
     * @param mixed $reference Id or name.
     * @param array $supplies  get_supplies_raw() shape.
     * @return array|null
     */
    public static function find_supply( $reference, array $supplies ) {
        if ( is_bool( $reference ) || ! is_scalar( $reference ) ) {
            return null;
        }
        $reference = trim( (string) $reference );
        if ( '' === $reference ) {
            return null;
        }

        if ( ctype_digit( $reference ) ) {
            foreach ( $supplies as $supply ) {
                if ( (int) $supply['id'] === (int) $reference ) {
                    return $supply;
                }
            }
        }
        foreach ( $supplies as $supply ) {
            if ( FST_Label_Cost::lower( $supply['name'] ) === FST_Label_Cost::lower( $reference ) ) {
                return $supply;
            }
        }

        return null;
    }

    /**
     * The supply that plays a role: the heat pack, the fish bag … or the
     * box supply of one standard box.
     *
     * @param string $role
     * @param array  $supplies get_supplies_raw() shape.
     * @param string $box_name Standard box name, for the `box` role.
     * @return array|null
     */
    public static function supply_for_role( $role, array $supplies, $box_name = '' ) {
        foreach ( $supplies as $supply ) {
            if ( $supply['role'] !== $role ) {
                continue;
            }
            if ( 'box' === $role && FST_Label_Cost::lower( $supply['box_name'] ) !== FST_Label_Cost::lower( $box_name ) ) {
                continue;
            }
            return $supply;
        }
        return null;
    }

    /**
     * Saved standard boxes, or none when the settings class is not loaded.
     *
     * @return array
     */
    private static function standard_boxes() {
        return class_exists( 'FST_Settings' ) ? FST_Settings::get_standard_boxes() : array();
    }

    /**
     * A standard box by its name (any letter case).
     *
     * @param string $name
     * @param array  $standard_boxes
     * @return array|null
     */
    public static function standard_box_by_name( $name, array $standard_boxes ) {
        foreach ( $standard_boxes as $box ) {
            if ( isset( $box['name'] ) && FST_Label_Cost::lower( $box['name'] ) === FST_Label_Cost::lower( trim( (string) $name ) ) ) {
                return $box;
            }
        }
        return null;
    }

    /**
     * The standard box a recorded size belongs to — the first saved box of
     * that size, as everywhere else — or null for a custom size.
     *
     * @param string|null $dims           "10x10x10".
     * @param array|null  $standard_boxes Null reads the saved list.
     * @return array|null
     */
    public static function standard_box_for_dims( $dims, $standard_boxes = null ) {
        if ( null === $dims || '' === $dims ) {
            return null;
        }
        if ( null === $standard_boxes ) {
            $standard_boxes = self::standard_boxes();
        }
        foreach ( $standard_boxes as $box ) {
            if ( isset( $box['dims'] ) && $box['dims'] === $dims ) {
                return $box;
            }
        }
        return null;
    }

    /**
     * The foam waste percent: 0 to 100, "15" until it is set.
     *
     * @param mixed $raw Null reads the saved setting.
     * @return string
     */
    public static function get_waste_percent( $raw = null ) {
        if ( null === $raw ) {
            $raw = get_option( self::OPTION_WASTE, self::DEFAULT_WASTE );
        }
        $hundredths = self::parse_qty( $raw );
        if ( ! is_int( $hundredths ) || $hundredths > 10000 ) {
            return self::DEFAULT_WASTE;
        }
        return FST_Label_Cost::trim_decimal( self::format_qty( $hundredths ) );
    }

    // -----------------------------------------------------------------
    // Purchases.
    // -----------------------------------------------------------------

    /**
     * Normalise a purchase: which supply, when, how many, what was paid.
     *
     * @param array $args     supply_id (or supply: its name), purchase_date (Y-m-d; empty = today),
     *                        qty (above 0), total_paid (0 or more), note (where it was bought).
     * @param array $supplies get_supplies_raw() shape.
     * @return array|WP_Error supply_id, purchase_date, qty ("40.00"), total_paid ("24.00"), note —
     *                        or `fst_unknown_supply` / `fst_invalid_purchase`.
     */
    public static function normalize_purchase( array $args, array $supplies ) {
        $reference = isset( $args['supply_id'] ) ? $args['supply_id'] : ( isset( $args['supply'] ) ? $args['supply'] : '' );
        $supply    = self::find_supply( $reference, $supplies );
        if ( ! $supply ) {
            return new WP_Error( 'fst_unknown_supply', 'Choose the supply this purchase is for.' );
        }

        $date = isset( $args['purchase_date'] ) && is_scalar( $args['purchase_date'] ) ? trim( (string) $args['purchase_date'] ) : '';
        if ( '' === $date ) {
            $date = substr( current_time( 'mysql' ), 0, 10 );
        }
        if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
            return new WP_Error( 'fst_invalid_purchase', 'The purchase date must be a valid date.' );
        }

        $qty = self::parse_qty( isset( $args['qty'] ) ? $args['qty'] : null );
        if ( ! is_int( $qty ) || $qty <= 0 ) {
            return new WP_Error( 'fst_invalid_purchase', 'How many must be a number above 0 with at most 2 decimals, for example 40.' );
        }

        $paid = FST_Label_Cost::parse_money( isset( $args['total_paid'] ) ? $args['total_paid'] : null );
        if ( is_wp_error( $paid ) || null === $paid ) {
            return new WP_Error( 'fst_invalid_purchase', 'The total paid must be an amount of 0 or more, for example 24.00.' );
        }

        $note = isset( $args['note'] ) && is_scalar( $args['note'] )
            ? FST_Label_Cost::limit_length( sanitize_text_field( (string) $args['note'] ), 190 )
            : '';

        return array(
            'supply_id'     => (int) $supply['id'],
            'purchase_date' => $date,
            'qty'           => self::format_qty( $qty ),
            'total_paid'    => $paid,
            'note'          => $note,
        );
    }

    /**
     * Log a purchase.
     *
     * @param array $args See normalize_purchase().
     * @return int|WP_Error The purchase id.
     */
    public static function add_purchase( array $args ) {
        global $wpdb;

        $purchase = self::normalize_purchase( $args, self::get_supplies_raw() );
        if ( is_wp_error( $purchase ) ) {
            return $purchase;
        }
        $purchase['created_at'] = current_time( 'mysql' );

        if ( false === $wpdb->insert( self::purchases_table(), $purchase ) ) {
            return new WP_Error( 'fst_purchase_save_failed', 'The purchase could not be saved.' );
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Correct a logged purchase.
     *
     * @param int   $purchase_id
     * @param array $args See normalize_purchase().
     * @return true|WP_Error
     */
    public static function update_purchase( $purchase_id, array $args ) {
        global $wpdb;

        if ( ! self::get_purchase( $purchase_id ) ) {
            return new WP_Error( 'fst_purchase_not_found', 'That purchase no longer exists.' );
        }
        $purchase = self::normalize_purchase( $args, self::get_supplies_raw() );
        if ( is_wp_error( $purchase ) ) {
            return $purchase;
        }

        if ( false === $wpdb->update( self::purchases_table(), $purchase, array( 'id' => absint( $purchase_id ) ) ) ) {
            return new WP_Error( 'fst_purchase_save_failed', 'The purchase could not be saved.' );
        }
        return true;
    }

    /**
     * Remove a logged purchase.
     *
     * @param int $purchase_id
     * @return bool
     */
    public static function delete_purchase( $purchase_id ) {
        global $wpdb;
        return (bool) $wpdb->delete( self::purchases_table(), array( 'id' => absint( $purchase_id ) ) );
    }

    /**
     * One logged purchase.
     *
     * @param int $purchase_id
     * @return object|null
     */
    public static function get_purchase( $purchase_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM " . self::purchases_table() . " WHERE id = %d",
            absint( $purchase_id )
        ) );
    }

    /**
     * Every logged purchase, oldest first. A purchase is a case or a roll,
     * so even years of them are a short list.
     *
     * @return object[]
     */
    public static function get_purchases() {
        global $wpdb;
        return (array) $wpdb->get_results( "SELECT * FROM " . self::purchases_table() . " ORDER BY id ASC" );
    }

    /**
     * The latest purchase of a list: by date, then by id.
     *
     * @param array $purchases Purchase rows (stdClass) of ONE supply.
     * @return object|null
     */
    public static function latest_purchase( array $purchases ) {
        $latest = null;
        foreach ( $purchases as $purchase ) {
            if ( null === $latest
                 || (string) $purchase->purchase_date > (string) $latest->purchase_date
                 || ( (string) $purchase->purchase_date === (string) $latest->purchase_date && (int) $purchase->id > (int) $latest->id ) ) {
                $latest = $purchase;
            }
        }
        return $latest;
    }

    /**
     * Price each = total paid / how many, of one purchase.
     *
     * @param object|null $purchase
     * @return string|null "0.6000", null without a purchase.
     */
    public static function price_each( $purchase ) {
        if ( ! $purchase ) {
            return null;
        }
        $qty = self::to_hundredths( $purchase->qty );
        if ( $qty <= 0 ) {
            return null;
        }
        // (cents / 100) / (qty / 100) dollars, as ten-thousandths, half up.
        $cents = FST_Label_Cost::to_cents( $purchase->total_paid );
        return self::format_rate( intdiv( $cents * 20000 + $qty, 2 * $qty ) );
    }

    /**
     * Group purchase rows by supply and keep the latest of each.
     *
     * @param array $purchases Every purchase row.
     * @return array supply id => purchase row.
     */
    public static function latest_by_supply( array $purchases ) {
        $grouped = array();
        foreach ( $purchases as $purchase ) {
            $grouped[ (int) $purchase->supply_id ][] = $purchase;
        }
        $latest = array();
        foreach ( $grouped as $supply_id => $rows ) {
            $latest[ $supply_id ] = self::latest_purchase( $rows );
        }
        return $latest;
    }

    /**
     * What one of each supply costs now: the price each of its latest purchase.
     *
     * @return array supply id => "0.6000". A supply never bought is absent.
     */
    public static function get_prices() {
        $prices = array();
        foreach ( self::latest_by_supply( self::get_purchases() ) as $supply_id => $purchase ) {
            $price = self::price_each( $purchase );
            if ( null !== $price ) {
                $prices[ $supply_id ] = $price;
            }
        }
        return $prices;
    }

    // -----------------------------------------------------------------
    // Stock.
    // -----------------------------------------------------------------

    /**
     * On hand = bought − used ± corrections. The screen never shows less
     * than nothing: below zero reads 0 and asks for a recount.
     *
     * @param int $bought   Whole hundredths.
     * @param int $used     Whole hundredths.
     * @param int $adjusted Whole hundredths; the sum of the recount corrections.
     * @return array bought, used, adjusted, balance (may be negative), on_hand
     *               (never below "0.00") as decimal strings, and recount_needed.
     */
    public static function stock_from_totals( $bought, $used, $adjusted ) {
        $balance = (int) $bought - (int) $used + (int) $adjusted;

        return array(
            'bought'         => self::format_qty( $bought ),
            'used'           => self::format_qty( $used ),
            'adjusted'       => self::format_qty( $adjusted ),
            'balance'        => self::format_qty( $balance ),
            'on_hand'        => self::format_qty( max( 0, $balance ) ),
            'recount_needed' => $balance < 0,
        );
    }

    /**
     * Whether a counted supply is at or under its reorder level.
     *
     * @param array $supply Supply row.
     * @param array $level  stock_from_totals() result.
     * @return bool
     */
    public static function is_low( array $supply, array $level ) {
        if ( empty( $supply['counted'] ) || ! isset( $supply['reorder_level'] ) ) {
            return false;
        }
        return self::to_hundredths( $level['on_hand'] ) <= self::to_hundredths( $supply['reorder_level'] );
    }

    /**
     * The stock of every supply that has any history — three sums, one
     * query each, however many boxes have been shipped.
     *
     * @return array supply id => stock_from_totals() result.
     */
    public static function get_stock() {
        $bought   = self::sum_by_supply( self::purchases_table(), 'qty' );
        $used     = self::sum_by_supply( self::usage_table(), 'qty' );
        $adjusted = self::sum_by_supply( self::counts_table(), 'delta' );

        $stock = array();
        foreach ( array_keys( $bought + $used + $adjusted ) as $supply_id ) {
            $stock[ $supply_id ] = self::stock_from_totals(
                isset( $bought[ $supply_id ] ) ? $bought[ $supply_id ] : 0,
                isset( $used[ $supply_id ] ) ? $used[ $supply_id ] : 0,
                isset( $adjusted[ $supply_id ] ) ? $adjusted[ $supply_id ] : 0
            );
        }
        return $stock;
    }

    /**
     * SUM( $column ) per supply of one table, in whole hundredths.
     *
     * @param string $table
     * @param string $column 'qty' or 'delta'.
     * @return array supply id => hundredths.
     */
    private static function sum_by_supply( $table, $column ) {
        global $wpdb;

        $column = 'delta' === $column ? 'delta' : 'qty';
        $totals = array();
        // supply_id 0 is the two flat amounts of a box (foam, paper).
        foreach ( (array) $wpdb->get_results( "SELECT supply_id, SUM({$column}) AS total FROM {$table} WHERE supply_id > 0 GROUP BY supply_id" ) as $row ) {
            $totals[ (int) $row->supply_id ] = self::to_hundredths( $row->total );
        }
        return $totals;
    }

    /**
     * Recount a supply: say how many there really are. The difference to
     * the books is stored with the date and who counted.
     *
     * @param int      $supply_id
     * @param mixed    $counted The real number, 0 or more.
     * @param int|null $user_id Null = the current user.
     * @return array|WP_Error stock_from_totals() result after the recount, or
     *                        `fst_unknown_supply` / `fst_invalid_recount`.
     */
    public static function recount( $supply_id, $counted, $user_id = null ) {
        global $wpdb;

        $supply = self::find_supply( absint( $supply_id ), self::get_supplies_raw() );
        if ( ! $supply || ! $supply['counted'] ) {
            return new WP_Error( 'fst_unknown_supply', 'That supply is not counted down.' );
        }

        $real = self::parse_qty( $counted );
        if ( ! is_int( $real ) ) {
            return new WP_Error( 'fst_invalid_recount', 'The count must be a number of 0 or more with at most 2 decimals.' );
        }

        $stock   = self::get_stock();
        $balance = isset( $stock[ $supply['id'] ] ) ? self::to_hundredths( $stock[ $supply['id'] ]['balance'] ) : 0;

        if ( null === $user_id ) {
            $user_id = function_exists( 'get_current_user_id' ) ? get_current_user_id() : 0;
        }

        $saved = $wpdb->insert( self::counts_table(), array(
            'supply_id'  => (int) $supply['id'],
            'counted'    => self::format_qty( $real ),
            'delta'      => self::format_qty( $real - $balance ),
            'counted_at' => current_time( 'mysql' ),
            'user_id'    => absint( $user_id ),
        ) );
        if ( false === $saved ) {
            return new WP_Error( 'fst_recount_save_failed', 'The recount could not be saved.' );
        }

        $stock = self::get_stock();
        return $stock[ $supply['id'] ];
    }

    /**
     * The latest recount of each supply.
     *
     * @return array supply id => row (counted, delta, counted_at, user_id).
     */
    public static function get_last_recounts() {
        global $wpdb;

        $last = array();
        foreach ( (array) $wpdb->get_results( "SELECT * FROM " . self::counts_table() . " ORDER BY id ASC" ) as $row ) {
            $last[ (int) $row->supply_id ] = $row;
        }
        return $last;
    }

    /**
     * Counted supplies at or under their reorder level.
     *
     * @return array[] [ { id, name, unit, on_hand, reorder_level, recount_needed } ]
     */
    public static function get_low_supplies() {
        $low = array();
        foreach ( self::get_supplies() as $supply ) {
            if ( ! $supply['low'] ) {
                continue;
            }
            $low[] = array(
                'id'             => $supply['id'],
                'name'           => $supply['name'],
                'unit'           => $supply['unit'],
                'on_hand'        => $supply['on_hand'],
                'reorder_level'  => $supply['reorder_level'],
                'recount_needed' => $supply['recount_needed'],
            );
        }
        return $low;
    }

    /**
     * Filter callback: `apply_filters( 'fst_low_supplies', array() )` gives
     * another plugin the supplies to reorder.
     *
     * @param mixed $supplies Whatever was passed in; replaced.
     * @return array[] get_low_supplies().
     */
    public static function filter_low_supplies( $supplies = array() ) {
        return self::get_low_supplies();
    }

    // -----------------------------------------------------------------
    // The materials of a box.
    // -----------------------------------------------------------------

    /**
     * Which part of the split a line belongs to, from its role.
     *
     * @param string $role
     * @return string One of SPLIT_KEYS.
     */
    public static function split_key( $role ) {
        switch ( $role ) {
            case 'box':
                return 'boxes';
            case 'foam':
                return 'foam';
            case 'paper':
                return 'paper';
            case 'fish_bag':
                return 'bags';
            case 'heat_pack':
            case 'ice_pack':
                return 'packs';
            default:
                return 'other';
        }
    }

    /**
     * Build a materials record from lines and the two flat amounts.
     *
     * @param array  $lines      [ { supply_id, name, role, unit, qty (hundredths), rate (ten-thousandths) } ]
     * @param string $foam_cost  "1.80"
     * @param string $paper_cost "0.40"
     * @return array {
     *     @type array  $lines      [ { supply_id, name, role, unit, qty "6.00", unit_cost "0.1100", cost "0.66" } ]
     *     @type string $foam_cost
     *     @type string $paper_cost
     *     @type string $total      Lines + foam + paper.
     *     @type array  $split      SPLIT_KEYS => decimal string; adds up to `total`.
     * }
     */
    private static function build_record( array $lines, $foam_cost, $paper_cost ) {
        $split = array_fill_keys( self::SPLIT_KEYS, 0 );
        $out   = array();

        foreach ( $lines as $line ) {
            $cents = self::line_cents( $line['qty'], $line['rate'] );

            $split[ self::split_key( $line['role'] ) ] += $cents;

            $out[] = array(
                'supply_id' => (int) $line['supply_id'],
                'name'      => (string) $line['name'],
                'role'      => (string) $line['role'],
                'unit'      => (string) $line['unit'],
                'qty'       => self::format_qty( $line['qty'] ),
                'unit_cost' => self::format_rate( $line['rate'] ),
                'cost'      => FST_Label_Cost::from_cents( $cents ),
            );
        }

        $split['foam']  += FST_Label_Cost::to_cents( $foam_cost );
        $split['paper'] += FST_Label_Cost::to_cents( $paper_cost );

        $total = array_sum( $split );
        foreach ( $split as $key => $cents ) {
            $split[ $key ] = FST_Label_Cost::from_cents( $cents );
        }

        return array(
            'lines'      => $out,
            'foam_cost'  => FST_Label_Cost::from_cents( FST_Label_Cost::to_cents( $foam_cost ) ),
            'paper_cost' => FST_Label_Cost::from_cents( FST_Label_Cost::to_cents( $paper_cost ) ),
            'total'      => FST_Label_Cost::from_cents( $total ),
            'split'      => $split,
        );
    }

    /**
     * A materials record from its stored rows.
     *
     * @param array $rows wp_fst_supply_usage rows (stdClass) of one box or one local pickup.
     * @return array|null build_record() shape; null when nothing is recorded.
     */
    public static function materials_from_rows( array $rows ) {
        if ( empty( $rows ) ) {
            return null;
        }

        $lines = array();
        $foam  = '0.00';
        $paper = '0.00';

        foreach ( $rows as $row ) {
            if ( 'foam' === $row->kind ) {
                $foam = $row->line_cost;
            } elseif ( 'paper' === $row->kind ) {
                $paper = $row->line_cost;
            } else {
                $lines[] = array(
                    'supply_id' => (int) $row->supply_id,
                    'name'      => (string) $row->supply_name,
                    'role'      => (string) $row->role,
                    'unit'      => (string) $row->unit,
                    'qty'       => self::to_hundredths( $row->qty ),
                    'rate'      => self::to_ten_thousandths( $row->unit_cost ),
                );
            }
        }

        return self::build_record( $lines, $foam, $paper );
    }

    /**
     * Normalise submitted materials into a record, snapshotting what each
     * supply costs now.
     *
     * Rules:
     *  - A line names its supply by `supply_id`, or by `supply` / `name`
     *    (any letter case). An unknown supply is an error, not a guess.
     *  - A quantity is 0 or more with at most two decimals; 0 (or empty)
     *    removes the line. The same supply on two lines is added up.
     *  - A line already on the record keeps the unit cost it was recorded
     *    at; a new line takes the supply's current price each (0 until a
     *    purchase is logged). `unit_cost` on a line overrides both.
     *  - `lines`, `foam_cost` and `paper_cost` are each optional: one that
     *    is absent keeps what the record holds (nothing / 0.00 on a new one).
     *
     * @param mixed      $raw      { lines: [ { supply_id | supply | name, qty, unit_cost? } ],
     *                             foam_cost, paper_cost }, the same as a JSON string, or
     *                             null / '' — remove the record.
     * @param array      $supplies get_supplies_raw() shape.
     * @param array      $prices   get_prices() shape.
     * @param array|null $existing The record as it stands (build_record() shape).
     * @return array|null|WP_Error The record to store, null to remove it, or
     *                             `fst_invalid_materials` / `fst_unknown_supply`.
     */
    public static function normalize_materials( $raw, array $supplies, array $prices, $existing = null ) {
        $invalid = new WP_Error( 'fst_invalid_materials', 'materials must hold lines (supply_id or supply, and qty), foam_cost and paper_cost.' );

        if ( is_string( $raw ) ) {
            if ( '' === trim( $raw ) ) {
                return null;
            }
            $raw = json_decode( $raw, true );
            if ( ! is_array( $raw ) ) {
                return $invalid;
            }
        }
        if ( null === $raw ) {
            return null;
        }
        if ( ! is_array( $raw ) ) {
            return $invalid;
        }

        // What the record holds now, by supply id.
        $recorded = array();
        if ( is_array( $existing ) ) {
            foreach ( $existing['lines'] as $line ) {
                $recorded[ (int) $line['supply_id'] ] = $line;
            }
        }

        $lines = array();
        if ( ! array_key_exists( 'lines', $raw ) ) {
            foreach ( $recorded as $supply_id => $line ) {
                $lines[ $supply_id ] = array(
                    'supply_id' => $supply_id,
                    'name'      => $line['name'],
                    'role'      => $line['role'],
                    'unit'      => $line['unit'],
                    'qty'       => self::to_hundredths( $line['qty'] ),
                    'rate'      => self::to_ten_thousandths( $line['unit_cost'] ),
                );
            }
        } elseif ( null !== $raw['lines'] && '' !== $raw['lines'] ) {
            if ( ! is_array( $raw['lines'] ) ) {
                return $invalid;
            }

            foreach ( $raw['lines'] as $line ) {
                if ( ! is_array( $line ) ) {
                    return $invalid;
                }

                $reference = '';
                foreach ( array( 'supply_id', 'supply', 'name' ) as $key ) {
                    if ( isset( $line[ $key ] ) && is_scalar( $line[ $key ] ) && '' !== trim( (string) $line[ $key ] ) ) {
                        $reference = trim( (string) $line[ $key ] );
                        break;
                    }
                }
                $supply = self::find_supply( $reference, $supplies );
                if ( ! $supply && ctype_digit( $reference ) && isset( $recorded[ (int) $reference ] ) ) {
                    // Removed from the list since, but already on this record.
                    $kept   = $recorded[ (int) $reference ];
                    $supply = array(
                        'id'   => (int) $reference,
                        'name' => $kept['name'],
                        'role' => $kept['role'],
                        'unit' => $kept['unit'],
                    );
                }
                if ( ! $supply ) {
                    return new WP_Error(
                        'fst_unknown_supply',
                        '' === $reference
                            ? 'Every materials line needs a supply (supply_id or supply).'
                            : sprintf( 'Unknown supply: %s. Supplies are listed under Settings > Boxes & label costs.', sanitize_text_field( $reference ) )
                    );
                }

                $qty = self::parse_qty( isset( $line['qty'] ) ? $line['qty'] : null );
                if ( false === $qty ) {
                    return new WP_Error( 'fst_invalid_materials', sprintf( 'The quantity of %s must be 0 or more with at most 2 decimals.', $supply['name'] ) );
                }
                $qty = (int) $qty;

                $supply_id = (int) $supply['id'];
                if ( isset( $line['unit_cost'] ) && '' !== trim( (string) ( is_scalar( $line['unit_cost'] ) ? $line['unit_cost'] : 'x' ) ) ) {
                    $rate = self::parse_rate( $line['unit_cost'] );
                    if ( ! is_int( $rate ) ) {
                        return new WP_Error( 'fst_invalid_materials', sprintf( 'The unit cost of %s must be an amount of 0 or more, for example 0.60.', $supply['name'] ) );
                    }
                } elseif ( isset( $recorded[ $supply_id ] ) ) {
                    $rate = self::to_ten_thousandths( $recorded[ $supply_id ]['unit_cost'] );
                } else {
                    $rate = isset( $prices[ $supply_id ] ) ? self::to_ten_thousandths( $prices[ $supply_id ] ) : 0;
                }

                if ( isset( $lines[ $supply_id ] ) ) {
                    $lines[ $supply_id ]['qty'] += $qty;
                    continue;
                }
                $lines[ $supply_id ] = array(
                    'supply_id' => $supply_id,
                    'name'      => $supply['name'],
                    'role'      => $supply['role'],
                    'unit'      => $supply['unit'],
                    'qty'       => $qty,
                    'rate'      => $rate,
                );
            }
        }

        // A quantity of 0 removes the line.
        foreach ( $lines as $supply_id => $line ) {
            if ( $line['qty'] <= 0 ) {
                unset( $lines[ $supply_id ] );
            }
        }

        $flat = array();
        foreach ( array( 'foam_cost' => 'foam', 'paper_cost' => 'paper' ) as $key => $label ) {
            if ( ! array_key_exists( $key, $raw ) ) {
                $flat[ $key ] = is_array( $existing ) ? $existing[ $key ] : '0.00';
                continue;
            }
            $amount = FST_Label_Cost::parse_money( $raw[ $key ] );
            if ( is_wp_error( $amount ) ) {
                return new WP_Error( 'fst_invalid_materials', sprintf( 'The %s cost must be an amount of 0 or more, for example 1.80.', $label ) );
            }
            $flat[ $key ] = null === $amount ? '0.00' : $amount;
        }

        return self::build_record( array_values( $lines ), $flat['foam_cost'], $flat['paper_cost'] );
    }

    /**
     * The materials a box starts with.
     *
     * A standard box: one of that box's own supply (when it has one), its
     * foam cost and its paper cost. Any box: one fish bag per fish, one heat
     * pack when the weather action is `heat_pack`, and — a standard box
     * only — its ice-pack count when the action is `cooling`. A custom box
     * starts with no foam or paper cost and no box supply.
     *
     * A role nobody plays (no fish bag supply saved …) adds no line.
     *
     * @param array|null $box        Standard box (FST_Settings::get_standard_boxes() row),
     *                               null for a custom size.
     * @param array      $supplies   get_supplies_raw() shape.
     * @param array      $prices     get_prices() shape.
     * @param int|float  $fish_count How many fish are in the box.
     * @param string     $action     The order's weather action ('' = not known).
     * @return array build_record() shape.
     */
    public static function default_materials( $box, array $supplies, array $prices, $fish_count, $action ) {
        $wanted = array();

        if ( $box ) {
            $wanted[] = array( self::supply_for_role( 'box', $supplies, $box['name'] ), 100 );
        }

        $fish = self::parse_qty( is_string( $fish_count ) ? $fish_count : round( (float) $fish_count, 2 ) );
        if ( is_int( $fish ) && $fish > 0 ) {
            $wanted[] = array( self::supply_for_role( 'fish_bag', $supplies ), $fish );
        }

        if ( 'heat_pack' === $action ) {
            $wanted[] = array( self::supply_for_role( 'heat_pack', $supplies ), 100 );
        }

        if ( 'cooling' === $action && $box && isset( $box['ice_packs'] ) ) {
            $ice = self::parse_qty( $box['ice_packs'] );
            if ( is_int( $ice ) && $ice > 0 ) {
                $wanted[] = array( self::supply_for_role( 'ice_pack', $supplies ), $ice );
            }
        }

        $lines = array();
        foreach ( $wanted as $pair ) {
            list( $supply, $qty ) = $pair;
            if ( ! $supply ) {
                continue;
            }
            $lines[] = array(
                'supply_id' => (int) $supply['id'],
                'name'      => $supply['name'],
                'role'      => $supply['role'],
                'unit'      => $supply['unit'],
                'qty'       => $qty,
                'rate'      => isset( $prices[ $supply['id'] ] ) ? self::to_ten_thousandths( $prices[ $supply['id'] ] ) : 0,
            );
        }

        return self::build_record(
            $lines,
            $box && isset( $box['foam_cost'] ) ? $box['foam_cost'] : '0.00',
            $box && isset( $box['paper_cost'] ) ? $box['paper_cost'] : '0.00'
        );
    }

    /**
     * The materials recorded for a box.
     *
     * @param string $tracking_number
     * @return array|null build_record() shape — lines, foam_cost, paper_cost,
     *                    total, split — or null when none are recorded.
     */
    public static function get_box_materials( $tracking_number ) {
        global $wpdb;

        if ( '' === trim( (string) $tracking_number ) ) {
            return null;
        }
        return self::materials_from_rows( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::usage_table() . " WHERE tracking_number = %s ORDER BY id ASC",
            $tracking_number
        ) ) );
    }

    /**
     * Record the materials of a box, replacing what was recorded. Stock
     * follows: the box's old lines are given back, the new ones taken.
     *
     * @param string $tracking_number
     * @param array  $materials normalize_materials() / default_materials() result.
     * @return bool
     */
    public static function set_box_materials( $tracking_number, array $materials ) {
        if ( '' === trim( (string) $tracking_number ) ) {
            return false;
        }
        return self::write_materials( (string) $tracking_number, 0, $materials );
    }

    /**
     * Remove the materials of a box — its supplies go back on the shelf.
     *
     * @param string $tracking_number
     * @return bool
     */
    public static function clear_box_materials( $tracking_number ) {
        global $wpdb;

        if ( '' === trim( (string) $tracking_number ) ) {
            return false;
        }
        return false !== $wpdb->delete( self::usage_table(), array( 'tracking_number' => (string) $tracking_number ) );
    }

    /**
     * The materials recorded for a local pickup.
     *
     * @param int $order_id
     * @return array|null build_record() shape, or null.
     */
    public static function get_order_materials( $order_id ) {
        global $wpdb;

        if ( ! absint( $order_id ) ) {
            return null;
        }
        return self::materials_from_rows( (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM " . self::usage_table() . " WHERE order_id = %d AND tracking_number = '' ORDER BY id ASC",
            absint( $order_id )
        ) ) );
    }

    /**
     * Record the materials of a local pickup, replacing what was recorded.
     *
     * @param int   $order_id
     * @param array $materials normalize_materials() result.
     * @return bool
     */
    public static function set_order_materials( $order_id, array $materials ) {
        if ( ! absint( $order_id ) ) {
            return false;
        }
        return self::write_materials( '', absint( $order_id ), $materials );
    }

    /**
     * Remove the materials of a local pickup.
     *
     * @param int $order_id
     * @return bool
     */
    public static function clear_order_materials( $order_id ) {
        global $wpdb;

        if ( ! absint( $order_id ) ) {
            return false;
        }
        return false !== $wpdb->delete( self::usage_table(), array(
            'order_id'        => absint( $order_id ),
            'tracking_number' => '',
        ) );
    }

    /**
     * Replace the usage rows of a box (tracking number) or a local pickup
     * (order id): one row per line, plus one each for foam and paper — also
     * when they are 0.00, so "recorded as nothing" differs from "not recorded".
     *
     * @param string $tracking_number '' for a local pickup.
     * @param int    $order_id        0 for a box.
     * @param array  $materials       build_record() shape.
     * @return bool
     */
    private static function write_materials( $tracking_number, $order_id, array $materials ) {
        global $wpdb;

        if ( '' !== $tracking_number ) {
            self::clear_box_materials( $tracking_number );
        } else {
            self::clear_order_materials( $order_id );
        }

        $base = array(
            'tracking_number' => $tracking_number,
            'order_id'        => (int) $order_id,
            'recorded_at'     => current_time( 'mysql' ),
        );
        $ok   = true;

        foreach ( $materials['lines'] as $line ) {
            $ok = false !== $wpdb->insert( self::usage_table(), $base + array(
                'kind'        => 'supply',
                'supply_id'   => (int) $line['supply_id'],
                'supply_name' => $line['name'],
                'role'        => $line['role'],
                'unit'        => $line['unit'],
                'qty'         => $line['qty'],
                'unit_cost'   => $line['unit_cost'],
                'line_cost'   => $line['cost'],
            ) ) && $ok;
        }

        // The two flat amounts. qty 0 and supply_id 0: they never touch stock.
        foreach ( array( 'foam' => 'foam_cost', 'paper' => 'paper_cost' ) as $kind => $key ) {
            $ok = false !== $wpdb->insert( self::usage_table(), $base + array(
                'kind'        => $kind,
                'supply_id'   => 0,
                'supply_name' => '',
                'role'        => $kind,
                'unit'        => '',
                'qty'         => '0.00',
                'unit_cost'   => $materials[ $key ],
                'line_cost'   => $materials[ $key ],
            ) ) && $ok;
        }

        return $ok;
    }

    /**
     * The materials of many boxes at once, for the reports.
     *
     * @param string[] $tracking_numbers
     * @return array FST_Label_Cost::box_key() => build_record() shape. A box
     *               without materials is absent.
     */
    public static function get_materials_for_boxes( array $tracking_numbers ) {
        global $wpdb;

        $rows_by_box = array();
        foreach ( array_chunk( array_values( array_filter( $tracking_numbers, 'strlen' ) ), 500 ) as $chunk ) {
            $placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

            $found = $wpdb->get_results( $wpdb->prepare(
                "SELECT * FROM " . self::usage_table() . " WHERE tracking_number IN ({$placeholders}) ORDER BY id ASC",
                ...$chunk
            ) );
            foreach ( (array) $found as $row ) {
                $rows_by_box[ FST_Label_Cost::box_key( $row->tracking_number ) ][] = $row;
            }
        }

        $materials = array();
        foreach ( $rows_by_box as $key => $rows ) {
            $materials[ $key ] = self::materials_from_rows( $rows );
        }
        return $materials;
    }

    // -----------------------------------------------------------------
    // Suggestions for the two flat amounts of a standard box.
    // -----------------------------------------------------------------

    /**
     * Suggested foam cost of a box: its surface — all six sides are lined —
     * 2(LW + LH + WH) in square feet × the price of a square foot from the
     * latest foam purchase × (1 + waste percent).
     *
     * A suggestion only: nothing uses it until the owner fills it in.
     *
     * @param array       $box           Standard box: length, width, height in inches.
     * @param array|null  $foam_supply   The supply with the `foam` role.
     * @param object|null $purchase      Its latest purchase (qty = sheets).
     * @param mixed       $waste_percent 15 = 15%.
     * @return array|null cost ("1.80"), box_sqft, sheet_sqft, price_per_sqft,
     *                    waste_percent and the working as `text`
     *                    ("4.2 sq ft of a 32 sq ft sheet"); null when there
     *                    is nothing to work from.
     */
    public static function foam_suggestion( array $box, $foam_supply, $purchase, $waste_percent ) {
        if ( ! $foam_supply || ! $purchase ) {
            return null;
        }

        $length = (float) $box['length'];
        $width  = (float) $box['width'];
        $height = (float) $box['height'];
        $sheet  = (float) ( isset( $foam_supply['sheet_length'] ) ? $foam_supply['sheet_length'] : 8 )
            * (float) ( isset( $foam_supply['sheet_width'] ) ? $foam_supply['sheet_width'] : 4 );
        $sheets = (float) $purchase->qty;
        if ( $length <= 0 || $width <= 0 || $height <= 0 || $sheet <= 0 || $sheets <= 0 ) {
            return null;
        }

        $box_sqft = 2 * ( $length * $width + $length * $height + $width * $height ) / 144;
        $per_sqft = (float) $purchase->total_paid / $sheets / $sheet;
        $waste    = (float) self::get_waste_percent( $waste_percent );
        $cost     = $box_sqft * $per_sqft * ( 1 + $waste / 100 );

        return array(
            'cost'           => number_format( round( $cost, 2 ), 2, '.', '' ),
            'box_sqft'       => round( $box_sqft, 2 ),
            'sheet_sqft'     => round( $sheet, 2 ),
            'price_per_sqft' => round( $per_sqft, 4 ),
            'waste_percent'  => $waste,
            'text'           => sprintf(
                '%s sq ft of a %s sq ft sheet',
                number_format( $box_sqft, 1, '.', '' ),
                FST_Label_Cost::trim_decimal( number_format( $sheet, 2, '.', '' ) )
            ),
        );
    }

    /**
     * Suggested paper cost of a box: its feet of paper × the price of a
     * foot from the latest paper purchase (qty = feet on the roll).
     *
     * @param array       $box      Standard box with the optional `paper_feet`.
     * @param object|null $purchase Latest purchase of the supply with the `paper` role.
     * @return array|null cost ("0.15"), feet, price_per_foot and `text`
     *                    ("3 ft at $0.0500 a foot"); null when there is
     *                    nothing to work from.
     */
    public static function paper_suggestion( array $box, $purchase ) {
        $feet = isset( $box['paper_feet'] ) ? (float) $box['paper_feet'] : 0;
        if ( $feet <= 0 || ! $purchase || (float) $purchase->qty <= 0 ) {
            return null;
        }

        $per_foot = (float) $purchase->total_paid / (float) $purchase->qty;

        return array(
            'cost'           => number_format( round( $feet * $per_foot, 2 ), 2, '.', '' ),
            'feet'           => $feet,
            'price_per_foot' => round( $per_foot, 4 ),
            'text'           => sprintf(
                '%s ft at $%s a foot',
                FST_Label_Cost::trim_decimal( number_format( $feet, 2, '.', '' ) ),
                number_format( $per_foot, 4, '.', '' )
            ),
        );
    }

    // -----------------------------------------------------------------
    // What an order tells about its box.
    // -----------------------------------------------------------------

    /**
     * The weather action for an order: `heat_pack`, `cooling`, `none`, … or
     * '' when nothing is known.
     *
     * Read from the order meta the FisHotel Misc plugin's Weather section
     * writes, when it is there — no dependency on that plugin. A combined
     * box is noted on its wrapper order, so a source order without a note
     * of its own takes the wrapper's. A note made for another delivery date
     * than the order now has is not used.
     *
     * Filter `fst_order_pack_action` ( $action, $order ) supplies or
     * overrides it.
     *
     * @param WC_Order|mixed $order
     * @return string
     */
    public static function order_pack_action( $order ) {
        $action = '';

        if ( is_object( $order ) && method_exists( $order, 'get_meta' ) ) {
            $action = self::stored_pack_action( $order );

            if ( '' === $action ) {
                $wrapper_id = absint( $order->get_meta( '_fishotel_fulfilled_by_order' ) );
                $wrapper    = $wrapper_id ? wc_get_order( $wrapper_id ) : false;
                if ( is_object( $wrapper ) && method_exists( $wrapper, 'get_meta' ) ) {
                    $action = self::stored_pack_action( $wrapper );
                }
            }
        }

        $action = apply_filters( 'fst_order_pack_action', $action, $order );

        return is_string( $action ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( $action ) ) : '';
    }

    /**
     * The weather action stored on one order, '' when there is none or it
     * was noted for a delivery date the order no longer has.
     *
     * @param object $order
     * @return string
     */
    private static function stored_pack_action( $order ) {
        $action = $order->get_meta( '_fh_weather_action' );
        if ( ! is_string( $action ) || '' === trim( $action ) ) {
            return '';
        }

        $noted_for = $order->get_meta( '_fh_weather_checked_for' );
        $delivery  = $order->get_meta( '_fishotel_shipping_date' );
        $noted_for = is_string( $noted_for ) ? trim( $noted_for ) : '';
        $delivery  = is_string( $delivery ) ? trim( $delivery ) : '';
        if ( '' !== $noted_for && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $delivery ) && $noted_for !== $delivery ) {
            return '';
        }

        return trim( $action );
    }

    /**
     * How many fish are in a box: the quantities of its line items added up.
     *
     * Filter `fst_box_fish_count` ( $count, $order, $item_ids ) adjusts it
     * (an order with dry goods on it, for example).
     *
     * @param WC_Order|mixed $order
     * @param int[]|null     $item_ids The order's items in the box; null = all of them.
     * @return int|float
     */
    public static function fish_count( $order, $item_ids = null ) {
        $count = 0;

        if ( is_object( $order ) && method_exists( $order, 'get_items' ) ) {
            foreach ( $order->get_items() as $item_id => $item ) {
                if ( null !== $item_ids && ! in_array( (int) $item_id, array_map( 'intval', $item_ids ), true ) ) {
                    continue;
                }
                $count += max( 0, (float) $item->get_quantity() );
            }
        }

        $count = apply_filters( 'fst_box_fish_count', $count, $order, $item_ids );
        $count = is_numeric( $count ) ? max( 0, round( (float) $count, 2 ) ) : 0;

        return (float) (int) $count === $count ? (int) $count : $count;
    }

    /**
     * The default materials of every standard box (and of a custom one) for
     * a number of fish and a weather action — what a form prefills.
     *
     * @param int|float $fish_count
     * @param string    $action
     * @return array {
     *     @type array[] $boxes  [ { name, dims, materials } ] — one per standard box.
     *     @type array   $custom Materials of a custom-size box.
     * }
     */
    public static function get_default_materials( $fish_count, $action ) {
        $supplies = self::get_supplies_raw();
        $prices   = self::get_prices();

        $boxes = array();
        foreach ( self::standard_boxes() as $box ) {
            $boxes[] = array(
                'name'      => $box['name'],
                'dims'      => $box['dims'],
                'materials' => self::default_materials( $box, $supplies, $prices, $fish_count, $action ),
            );
        }

        return array(
            'boxes'  => $boxes,
            'custom' => self::default_materials( null, $supplies, $prices, $fish_count, $action ),
        );
    }

    // -----------------------------------------------------------------
    // Supplies report.
    // -----------------------------------------------------------------

    /**
     * The supplies report of a date range — pure.
     *
     * Per supply: bought in the range (how many, money), used on the boxes
     * shipped and the local pickups made in the range, on hand now and
     * whether it is at or under its reorder level. For foam and paper:
     * what was bought beside what the per-box ballparks added up to, so a
     * ballpark that is off shows.
     *
     * @param array  $supplies  get_supplies_raw() shape.
     * @param array  $purchases Every purchase row (stdClass).
     * @param array  $materials Materials records (build_record() shape) of the boxes
     *                          shipped and the local pickups made in the range.
     * @param array  $stock     get_stock() shape.
     * @param string $date_from Y-m-d or ''.
     * @param string $date_to   Y-m-d or ''.
     * @return array {
     *     @type array[] $rows      [ { id, name, unit, role, counted, removed, bought_qty, bought_money,
     *                              purchases, used_qty, used_money, on_hand (null = not counted),
     *                              recount_needed, reorder_level, low } ]
     *     @type array   $ballparks foam / paper => { supply (name or null), bought_money, ballpark, records }
     * }
     */
    public static function build_supplies_report( array $supplies, array $purchases, array $materials, array $stock, $date_from = '', $date_to = '' ) {
        $rows = array();
        foreach ( $supplies as $supply ) {
            $level = isset( $stock[ $supply['id'] ] ) ? $stock[ $supply['id'] ] : self::stock_from_totals( 0, 0, 0 );

            $rows[ (int) $supply['id'] ] = array(
                'id'             => (int) $supply['id'],
                'name'           => $supply['name'],
                'unit'           => $supply['unit'],
                'role'           => $supply['role'],
                'counted'        => (bool) $supply['counted'],
                'removed'        => false,
                'bought'         => 0,
                'bought_cents'   => 0,
                'purchases'      => 0,
                'used'           => 0,
                'used_cents'     => 0,
                'on_hand'        => $supply['counted'] ? $level['on_hand'] : null,
                'recount_needed' => $supply['counted'] && $level['recount_needed'],
                'reorder_level'  => $supply['reorder_level'],
                'low'            => self::is_low( $supply, $level ),
            );
        }

        foreach ( $purchases as $purchase ) {
            $supply_id = (int) $purchase->supply_id;
            $date      = (string) $purchase->purchase_date;
            if ( ! isset( $rows[ $supply_id ] ) || ( '' !== $date_from && $date < $date_from ) || ( '' !== $date_to && $date > $date_to ) ) {
                continue;
            }
            $rows[ $supply_id ]['bought']       += self::to_hundredths( $purchase->qty );
            $rows[ $supply_id ]['bought_cents'] += FST_Label_Cost::to_cents( $purchase->total_paid );
            $rows[ $supply_id ]['purchases']++;
        }

        $ballparks = array(
            'foam'  => array( 'cents' => 0, 'records' => 0 ),
            'paper' => array( 'cents' => 0, 'records' => 0 ),
        );

        foreach ( $materials as $record ) {
            if ( ! is_array( $record ) ) {
                continue;
            }
            foreach ( $record['lines'] as $line ) {
                $supply_id = (int) $line['supply_id'];
                if ( ! isset( $rows[ $supply_id ] ) ) {
                    // Used on a box, removed from the list since.
                    $rows[ $supply_id ] = array(
                        'id'             => $supply_id,
                        'name'           => $line['name'],
                        'unit'           => $line['unit'],
                        'role'           => $line['role'],
                        'counted'        => false,
                        'removed'        => true,
                        'bought'         => 0,
                        'bought_cents'   => 0,
                        'purchases'      => 0,
                        'used'           => 0,
                        'used_cents'     => 0,
                        'on_hand'        => null,
                        'recount_needed' => false,
                        'reorder_level'  => null,
                        'low'            => false,
                    );
                }
                $rows[ $supply_id ]['used']       += self::to_hundredths( $line['qty'] );
                $rows[ $supply_id ]['used_cents'] += FST_Label_Cost::to_cents( $line['cost'] );
            }
            foreach ( array( 'foam' => 'foam_cost', 'paper' => 'paper_cost' ) as $kind => $key ) {
                $cents = FST_Label_Cost::to_cents( $record[ $key ] );
                $ballparks[ $kind ]['cents'] += $cents;
                if ( $cents > 0 ) {
                    $ballparks[ $kind ]['records']++;
                }
            }
        }

        $out = array();
        foreach ( $rows as $row ) {
            $row['bought_qty']   = self::format_qty( $row['bought'] );
            $row['bought_money'] = FST_Label_Cost::from_cents( $row['bought_cents'] );
            $row['used_qty']     = self::format_qty( $row['used'] );
            $row['used_money']   = FST_Label_Cost::from_cents( $row['used_cents'] );
            unset( $row['bought'], $row['bought_cents'], $row['used'], $row['used_cents'] );
            $out[] = $row;
        }

        $ballpark_rows = array();
        foreach ( $ballparks as $kind => $totals ) {
            $supply = self::supply_for_role( $kind, $supplies );
            $bought = 0;
            if ( $supply ) {
                foreach ( $out as $row ) {
                    if ( $row['id'] === (int) $supply['id'] ) {
                        $bought = FST_Label_Cost::to_cents( $row['bought_money'] );
                    }
                }
            }
            $ballpark_rows[ $kind ] = array(
                'supply'       => $supply ? $supply['name'] : null,
                'bought_money' => $supply ? FST_Label_Cost::from_cents( $bought ) : null,
                'ballpark'     => FST_Label_Cost::from_cents( $totals['cents'] ),
                'records'      => $totals['records'],
            );
        }

        return array(
            'rows'      => $out,
            'ballparks' => $ballpark_rows,
        );
    }
}
