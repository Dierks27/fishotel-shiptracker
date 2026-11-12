<?php
/**
 * Label costs — what a box actually cost to ship.
 *
 * One tracking number is one physical box. Its cost data (label cost, weight,
 * dimensions, where the label was bought) is stored ONCE per tracking number
 * on wp_fst_shipments — see FST_Shipment::set_box_cost(). This class holds
 * everything that needs no database: field normalisation, deriving a box
 * from its shipment rows, reading a cost CSV and the Analytics report maths,
 * so those parts are unit-tested without WordPress (tests/label-cost-*.php).
 *
 * @package FisHotel_ShipTracker
 */

defined( 'ABSPATH' ) || exit;

class FST_Label_Cost {

    /**
     * The four per-box columns on wp_fst_shipments (DB version 1.3.0).
     */
    const FIELDS = array( 'label_cost', 'box_weight', 'box_dims', 'label_source' );

    /**
     * Upload / paste limit of the cost import, in bytes (2 MB).
     */
    const MAX_IMPORT_BYTES = 2097152;

    /**
     * Statuses the Analytics page leaves out of every stat.
     */
    const EXCLUDED_STATUSES = array( 'unknown', 'label_created' );

    /**
     * How many rows of each kind the import report lists by name.
     */
    const IMPORT_LIST_LIMIT = 100;

    // -----------------------------------------------------------------
    // Field normalisation.
    // -----------------------------------------------------------------

    /**
     * Normalise the four cost fields.
     *
     * @param array $args    Raw input; only the keys in self::FIELDS are read.
     * @param bool  $partial false (add): all four keys come back, null when
     *                       absent or empty. true (update): only the keys
     *                       PRESENT in $args come back — an empty value is
     *                       null (clear that field), an absent key is left
     *                       out (leave that field alone).
     * @return array|WP_Error Field => normalised value (string) or null, or a
     *                        WP_Error with code `fst_invalid_label_cost`,
     *                        `fst_invalid_box_weight` or `fst_invalid_box_dims`.
     */
    public static function normalize_fields( array $args, $partial = false ) {
        $fields = array();

        foreach ( self::FIELDS as $field ) {
            if ( ! array_key_exists( $field, $args ) ) {
                if ( ! $partial ) {
                    $fields[ $field ] = null;
                }
                continue;
            }

            switch ( $field ) {
                case 'label_cost':
                    $value = self::parse_money( $args[ $field ] );
                    break;
                case 'box_weight':
                    $value = self::parse_weight( $args[ $field ] );
                    break;
                case 'box_dims':
                    $value = self::parse_dims( $args[ $field ] );
                    break;
                default:
                    $value = self::parse_source( $args[ $field ] );
                    break;
            }

            if ( is_wp_error( $value ) ) {
                return $value;
            }
            $fields[ $field ] = $value;
        }

        return $fields;
    }

    /**
     * Money: 0 or more, stored with two decimals. "$" and thousands
     * separators are tolerated ("$1,234.5" is "1234.50"); more than two
     * decimals are rounded half up.
     *
     * @param mixed $raw
     * @return string|null|WP_Error "18.40", null when empty, WP_Error
     *                              `fst_invalid_label_cost` when negative or
     *                              not a number.
     */
    public static function parse_money( $raw ) {
        if ( is_string( $raw ) ) {
            $raw = trim( $raw );
            $raw = preg_replace( '/^(?:usd|us\$|\$)\s*/i', '', $raw );
            $raw = preg_replace( '/\s*(?:usd|\$)$/i', '', $raw );
        }

        $hundredths = self::parse_hundredths( $raw, 8 );
        if ( false === $hundredths ) {
            return new WP_Error( 'fst_invalid_label_cost', 'The label cost must be an amount of 0 or more, for example 18.40.' );
        }

        return null === $hundredths ? null : self::format_hundredths( $hundredths );
    }

    /**
     * Weight in pounds: more than 0, stored with two decimals. A trailing
     * "lb" / "lbs" is tolerated; other units are not converted.
     *
     * @param mixed $raw
     * @return string|null|WP_Error "11.00", null when empty, WP_Error
     *                              `fst_invalid_box_weight` otherwise.
     */
    public static function parse_weight( $raw ) {
        if ( is_string( $raw ) ) {
            $raw = preg_replace( '/\s*(?:lbs?\.?|pounds?|#)$/i', '', trim( $raw ) );
        }

        $hundredths = self::parse_hundredths( $raw, 4 );
        if ( false === $hundredths || 0 === $hundredths ) {
            return new WP_Error( 'fst_invalid_box_weight', 'The box weight must be a number of pounds above 0, for example 11.' );
        }

        return null === $hundredths ? null : self::format_hundredths( $hundredths );
    }

    /**
     * Box dimensions in inches, normalised to "LxWxH".
     *
     * "10x10x10", "10 x 10 x 10", "10*10*10" and "10X10X10 in" all become
     * "10x10x10". The NAME of a standard box (Settings → Boxes & label costs)
     * is accepted and stored as that box's dimensions.
     *
     * @param mixed      $raw            String, or a list of three numbers.
     * @param array|null $standard_boxes FST_Settings::get_standard_boxes()
     *                                   shape; null reads the saved list.
     * @return string|null|WP_Error "10x10x10", null when empty, WP_Error
     *                              `fst_invalid_box_dims` otherwise.
     */
    public static function parse_dims( $raw, $standard_boxes = null ) {
        $invalid = new WP_Error( 'fst_invalid_box_dims', 'The box size must be three sizes in inches such as 10x10x10, or the name of a standard box.' );

        if ( null === $raw ) {
            return null;
        }

        if ( is_array( $raw ) ) {
            // [ 10, 10, 10 ] from a JSON caller or the settings rows.
            $parts = array_values( $raw );
            if ( 3 !== count( $parts ) ) {
                return $invalid;
            }
            foreach ( $parts as $part ) {
                if ( is_bool( $part ) || ! is_scalar( $part ) ) {
                    return $invalid;
                }
            }
            $raw = implode( 'x', $parts );
        }

        if ( is_bool( $raw ) || ! is_scalar( $raw ) ) {
            return $invalid;
        }

        $value = trim( (string) $raw );
        if ( '' === $value ) {
            return null;
        }

        $dims = self::dims_from_string( $value );
        if ( null !== $dims ) {
            return $dims;
        }

        if ( null === $standard_boxes ) {
            $standard_boxes = self::standard_boxes();
        }
        foreach ( $standard_boxes as $box ) {
            if ( isset( $box['name'], $box['dims'] ) && self::lower( $box['name'] ) === self::lower( $value ) ) {
                return $box['dims'];
            }
        }

        return $invalid;
    }

    /**
     * Where the label was bought: free text, trimmed to 40 characters. A
     * value matching a saved label source (any letter case) takes the saved
     * spelling so reports group it with the others.
     *
     * @param mixed      $raw
     * @param array|null $sources FST_Settings::get_label_sources() shape;
     *                            null reads the saved list.
     * @return string|null
     */
    public static function parse_source( $raw, $sources = null ) {
        if ( null === $raw || is_bool( $raw ) || ! is_scalar( $raw ) ) {
            return null;
        }

        $value = self::limit_length( sanitize_text_field( (string) $raw ), 40 );
        if ( '' === $value ) {
            return null;
        }

        if ( null === $sources ) {
            $sources = self::label_sources();
        }
        foreach ( $sources as $source ) {
            if ( self::lower( $source ) === self::lower( $value ) ) {
                return $source;
            }
        }

        return $value;
    }

    /**
     * Read a non-negative decimal as a whole number of hundredths, rounding
     * half up on the digits so no float is involved ("1.005" is 101).
     *
     * @param mixed $raw            Scalar input.
     * @param int   $max_int_digits Digits the column holds before the point.
     * @return int|null|false Hundredths, null when empty, false when not a
     *                        non-negative number that fits the column.
     */
    private static function parse_hundredths( $raw, $max_int_digits ) {
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
            $raw = number_format( abs( $raw ), 4, '.', '' );
        }

        $value = trim( (string) $raw );
        if ( '' === $value ) {
            return null;
        }

        // Thousands separators only in the 1,234,567.89 shape — "12,50" is
        // rejected rather than guessed at.
        if ( preg_match( '/^\d{1,3}(?:,\d{3})+(?:\.\d*)?$/', $value ) ) {
            $value = str_replace( ',', '', $value );
        }

        if ( ! preg_match( '/^(\d*)(?:\.(\d*))?$/', $value, $m ) ) {
            return false;
        }
        $int  = $m[1];
        $frac = isset( $m[2] ) ? $m[2] : '';
        if ( '' === $int && '' === $frac ) {
            return false;
        }

        $int = ltrim( $int, '0' );
        if ( strlen( $int ) > $max_int_digits ) {
            return false;
        }

        $frac       = str_pad( $frac, 3, '0' );
        $hundredths = (int) $int * 100 + (int) substr( $frac, 0, 2 );
        if ( (int) $frac[2] >= 5 ) {
            $hundredths++;
        }

        // Rounding up may have added a digit (99999999.995).
        if ( strlen( (string) intdiv( $hundredths, 100 ) ) > $max_int_digits ) {
            return false;
        }

        return $hundredths;
    }

    /**
     * Hundredths as a two-decimal string: 1840 is "18.40".
     *
     * @param int $hundredths
     * @return string
     */
    private static function format_hundredths( $hundredths ) {
        return sprintf( '%d.%02d', intdiv( $hundredths, 100 ), $hundredths % 100 );
    }

    /**
     * Drop trailing decimal zeros: "10.00" is "10", "12.50" is "12.5".
     *
     * @param string $decimal
     * @return string
     */
    public static function trim_decimal( $decimal ) {
        $decimal = (string) $decimal;
        if ( false === strpos( $decimal, '.' ) ) {
            return $decimal;
        }
        return rtrim( rtrim( $decimal, '0' ), '.' );
    }

    /**
     * Three positive numbers separated by x, X, *, × or "by", with optional
     * inch marks, as "LxWxH". Null when the text is anything else.
     *
     * @param string $value
     * @return string|null
     */
    private static function dims_from_string( $value ) {
        $value = str_replace( array( "\xC3\x97", '*' ), 'x', (string) $value );
        $value = strtolower( $value );
        $value = preg_replace( '/\s+by\s+/', 'x', $value );
        // Inch marks after a number: 10" x 10" x 10", 10x10x10 in.
        $value = preg_replace( '/(?<=[\d.])\s*(?:inches|inch|in\.?|"|\'\')(?=\s*(?:x|$))/', '', $value );
        $value = preg_replace( '/\s+/', '', $value );

        $number = '(\d+(?:\.\d*)?|\.\d+)';
        if ( ! preg_match( '/^' . $number . 'x' . $number . 'x' . $number . '$/', $value, $m ) ) {
            return null;
        }

        $parts = array();
        for ( $i = 1; $i <= 3; $i++ ) {
            $hundredths = self::parse_hundredths( $m[ $i ], 4 );
            if ( ! is_int( $hundredths ) || $hundredths <= 0 ) {
                return null;
            }
            $parts[] = self::trim_decimal( self::format_hundredths( $hundredths ) );
        }

        return implode( 'x', $parts );
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
     * Saved label sources, or none when the settings class is not loaded.
     *
     * @return array
     */
    private static function label_sources() {
        return class_exists( 'FST_Settings' ) ? FST_Settings::get_label_sources() : array();
    }

    /**
     * Lower-case for comparing names.
     *
     * @param string $text
     * @return string
     */
    public static function lower( $text ) {
        return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $text ) : strtolower( (string) $text );
    }

    /**
     * Cut text to a column length, by characters.
     *
     * @param string $text
     * @param int    $length
     * @return string
     */
    public static function limit_length( $text, $length ) {
        $text = function_exists( 'mb_substr' ) ? mb_substr( (string) $text, 0, $length ) : substr( (string) $text, 0, $length );
        return trim( $text );
    }

    // -----------------------------------------------------------------
    // One box, one cost.
    // -----------------------------------------------------------------

    /**
     * Key that groups shipment rows into one box. MySQL compares tracking
     * numbers without regard to letter case, so the PHP side does too.
     *
     * @param string $tracking_number
     * @return string
     */
    public static function box_key( $tracking_number ) {
        return strtoupper( trim( (string) $tracking_number ) );
    }

    /**
     * The cost data of one box, from the shipment rows sharing its tracking
     * number (fan-out writes one row per source order).
     *
     * The four fields are read from ONE row — the lowest id that has any of
     * them — and never summed across rows, so a box cannot count twice.
     *
     * @param array $rows Shipment rows (stdClass) of a single tracking number.
     * @return array {
     *     @type string      $tracking_number
     *     @type string|null $label_cost       "18.40"; null = not recorded.
     *     @type string|null $box_weight       Pounds, "11.00".
     *     @type string|null $box_dims         "10x10x10".
     *     @type string|null $label_source
     *     @type bool        $shared_box       Rows of more than one order share the box.
     *     @type int[]       $order_ids        Every order with a row for the box.
     *     @type int[]       $shipment_ids     Every row of the box.
     *     @type int         $cost_shipment_id Row holding the fields, 0 when none does.
     * }
     */
    public static function box_from_rows( array $rows ) {
        usort( $rows, array( __CLASS__, 'compare_row_ids' ) );

        $box = array(
            'tracking_number'  => '',
            'label_cost'       => null,
            'box_weight'       => null,
            'box_dims'         => null,
            'label_source'     => null,
            'shared_box'       => false,
            'order_ids'        => array(),
            'shipment_ids'     => array(),
            'cost_shipment_id' => 0,
        );

        foreach ( $rows as $row ) {
            if ( '' === $box['tracking_number'] ) {
                $box['tracking_number'] = (string) $row->tracking_number;
            }
            $box['shipment_ids'][] = (int) $row->id;
            if ( ! in_array( (int) $row->order_id, $box['order_ids'], true ) ) {
                $box['order_ids'][] = (int) $row->order_id;
            }

            if ( $box['cost_shipment_id'] || ! self::row_has_cost_data( $row ) ) {
                continue;
            }
            $box['cost_shipment_id'] = (int) $row->id;
            $box['label_cost']       = self::decimal_or_null( isset( $row->label_cost ) ? $row->label_cost : null );
            $box['box_weight']       = self::decimal_or_null( isset( $row->box_weight ) ? $row->box_weight : null );
            $box['box_dims']         = isset( $row->box_dims ) ? (string) $row->box_dims : null;
            $box['label_source']     = isset( $row->label_source ) ? (string) $row->label_source : null;
        }

        $box['shared_box'] = count( $box['order_ids'] ) > 1;

        return $box;
    }

    /**
     * Whether a shipment row holds any of the four cost fields.
     *
     * @param object $row
     * @return bool
     */
    public static function row_has_cost_data( $row ) {
        foreach ( self::FIELDS as $field ) {
            if ( isset( $row->$field ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * usort callback: shipment rows by id, oldest first.
     */
    private static function compare_row_ids( $a, $b ) {
        return (int) $a->id - (int) $b->id;
    }

    /**
     * A DECIMAL column value as a two-decimal string; NULL stays NULL.
     *
     * @param mixed $value
     * @return string|null
     */
    private static function decimal_or_null( $value ) {
        return null === $value ? null : number_format( (float) $value, 2, '.', '' );
    }

    // -----------------------------------------------------------------
    // CSV import.
    // -----------------------------------------------------------------

    /**
     * Split CSV text into rows of cells.
     *
     * Handles a UTF-8 or UTF-16 byte-order mark, CRLF / CR line ends, quoted
     * fields (with commas, doubled quotes and line breaks inside) and a tab
     * or semicolon delimiter (a pasted spreadsheet selection is tab-separated).
     *
     * @param string $text
     * @return array[] Rows of string cells; a blank line is an empty array.
     */
    public static function parse_csv( $text ) {
        $text = (string) $text;

        $bom = substr( $text, 0, 2 );
        if ( ( "\xFF\xFE" === $bom || "\xFE\xFF" === $bom ) && function_exists( 'mb_convert_encoding' ) ) {
            $text = mb_convert_encoding( substr( $text, 2 ), 'UTF-8', "\xFF\xFE" === $bom ? 'UTF-16LE' : 'UTF-16BE' );
        }
        if ( "\xEF\xBB\xBF" === substr( $text, 0, 3 ) ) {
            $text = substr( $text, 3 );
        }

        $text = str_replace( array( "\r\n", "\r" ), "\n", $text );
        if ( '' === trim( $text ) ) {
            return array();
        }

        $delimiter = self::detect_csv_delimiter( $text );

        $handle = fopen( 'php://temp', 'r+' );
        fwrite( $handle, $text );
        rewind( $handle );

        $rows = array();
        // Empty escape character = plain RFC 4180 quoting ("" inside quotes).
        while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) {
            if ( array( null ) === $cells ) {
                $rows[] = array();
                continue;
            }
            $row = array();
            foreach ( $cells as $cell ) {
                $row[] = trim( (string) $cell );
            }
            $rows[] = $row;
        }
        fclose( $handle );

        return $rows;
    }

    /**
     * Pick the delimiter that splits the first line into the most cells.
     *
     * @param string $text
     * @return string
     */
    private static function detect_csv_delimiter( $text ) {
        $first_line = '';
        foreach ( explode( "\n", $text ) as $line ) {
            if ( '' !== trim( $line ) ) {
                $first_line = $line;
                break;
            }
        }

        $best       = ',';
        $best_cells = 0;
        foreach ( array( ',', "\t", ';' ) as $delimiter ) {
            $cells = count( str_getcsv( $first_line, $delimiter, '"', '' ) );
            if ( $cells > $best_cells ) {
                $best       = $delimiter;
                $best_cells = $cells;
            }
        }

        return $best;
    }

    /**
     * Find the import columns by the text of the header row.
     *
     * @param array $header Header cells.
     * @return array {
     *     @type int|null       $tracking tracking / tracking number / tracking #
     *     @type int|null       $cost     cost / amount / total / charge / price
     *     @type int|null       $weight   Optional. Skipped when the header names a unit other than pounds.
     *     @type int|int[]|null $dims     Optional. dimensions / box, or [ length, width, height ] columns.
     *     @type int|null       $source   Optional.
     * }
     */
    public static function detect_csv_columns( array $header ) {
        $names = array();
        foreach ( $header as $index => $cell ) {
            $names[ $index ] = self::normalize_header( $cell );
        }

        $columns = array(
            'tracking' => null,
            'cost'     => null,
            'weight'   => null,
            'dims'     => null,
            'source'   => null,
        );
        $used    = array();

        $columns['tracking'] = self::find_column(
            $names,
            $used,
            array( 'tracking', 'tracking number', 'tracking #', 'tracking no', 'tracking num', 'tracking id', 'tracking code', 'tracking numbers', 'trackingnumber', 'tracking#', 'track #' ),
            array( 'tracking' ),
            array( 'status', 'url', 'link', 'date', 'event', 'detail', 'updated' )
        );

        $columns['cost'] = self::find_column(
            $names,
            $used,
            array(
                'cost', 'amount', 'total', 'charge', 'price',
                'label cost', 'shipping cost', 'shipment cost', 'total cost', 'total charge', 'total charges', 'charges',
                'amount charged', 'amount paid', 'label price', 'total price', 'rate', 'postage', 'net charge', 'billed amount',
            ),
            array( 'cost', 'amount', 'total', 'charge', 'price' ),
            array( 'order', 'insur', 'declared', 'value', 'tax', 'item', 'product', 'weight', 'qty', 'quantity', 'refund', 'surcharge', 'fee', 'discount' )
        );

        // A weight column in ounces, grams or kilograms is left alone — the
        // column stores pounds and nothing is converted on a guess.
        $weight_names = $names;
        foreach ( $header as $index => $cell ) {
            if ( preg_match( '/\b(?:oz|ounces?|kgs?|kilograms?|g|grams?)\b/i', (string) $cell ) ) {
                unset( $weight_names[ $index ] );
            }
        }
        $columns['weight'] = self::find_column(
            $weight_names,
            $used,
            array( 'weight', 'box weight', 'package weight', 'actual weight', 'billed weight', 'billable weight', 'weight lb', 'weight lbs', 'lbs', 'lb' ),
            array( 'weight' ),
            array( 'dim' )
        );

        $columns['dims'] = self::find_column(
            $names,
            $used,
            array( 'dimensions', 'dims', 'box', 'box size', 'box dimensions', 'package dimensions', 'package size', 'size', 'l x w x h', 'lxwxh' ),
            array( 'dimension', 'dims', 'box' ),
            array( 'weight', 'count', 'qty', 'quantity', 'po box' )
        );
        if ( null === $columns['dims'] ) {
            $length = self::find_column( $names, $used, array( 'length', 'l' ), array(), array() );
            $width  = self::find_column( $names, $used, array( 'width', 'w' ), array(), array() );
            $height = self::find_column( $names, $used, array( 'height', 'h' ), array(), array() );
            if ( null !== $length && null !== $width && null !== $height ) {
                $columns['dims'] = array( $length, $width, $height );
            }
        }

        $columns['source'] = self::find_column(
            $names,
            $used,
            array( 'source', 'label source', 'bought through', 'purchased through', 'partner' ),
            array( 'source' ),
            array()
        );

        return $columns;
    }

    /**
     * First unused column whose header equals one of $exact (in that order of
     * preference), else the first one containing a $contains word and none of
     * the $exclude words.
     *
     * @param array $names    Column index => normalised header.
     * @param array $used     Column indexes already taken (updated).
     * @param array $exact
     * @param array $contains
     * @param array $exclude
     * @return int|null
     */
    private static function find_column( array $names, array &$used, array $exact, array $contains, array $exclude ) {
        foreach ( $exact as $wanted ) {
            foreach ( $names as $index => $name ) {
                if ( $name === $wanted && ! isset( $used[ $index ] ) ) {
                    $used[ $index ] = true;
                    return $index;
                }
            }
        }

        foreach ( $contains as $word ) {
            foreach ( $names as $index => $name ) {
                if ( isset( $used[ $index ] ) || false === strpos( $name, $word ) ) {
                    continue;
                }
                foreach ( $exclude as $bad ) {
                    if ( false !== strpos( $name, $bad ) ) {
                        continue 2;
                    }
                }
                $used[ $index ] = true;
                return $index;
            }
        }

        return null;
    }

    /**
     * Header text for matching: lower case, units in brackets and
     * punctuation dropped ("Total Cost ($)" is "total cost").
     *
     * @param string $cell
     * @return string
     */
    private static function normalize_header( $cell ) {
        $name = strtolower( trim( (string) $cell ) );
        $name = preg_replace( '/\([^)]*\)/', ' ', $name );
        $name = str_replace( array( '_', '-', '.', ':' ), ' ', $name );
        $name = preg_replace( '/\s+/', ' ', $name );
        return trim( $name );
    }

    /**
     * A tracking-number cell as typed: spreadsheet text wrappers (="…", a
     * leading apostrophe) and spaces removed.
     *
     * @param string $cell
     * @return string
     */
    private static function clean_tracking_cell( $cell ) {
        $value = trim( (string) $cell );
        if ( preg_match( '/^="(.*)"$/', $value, $m ) ) {
            $value = $m[1];
        }
        $value = ltrim( $value, "'" );
        $value = preg_replace( '/\s+/', '', $value );
        return sanitize_text_field( $value );
    }

    /**
     * Read a cost CSV into validated entries — no database access.
     *
     * @param string      $text           CSV text with a header row.
     * @param string|null $default_source "These labels were bought through" —
     *                                    used only when the file has no source column.
     * @return array {
     *     @type string $error            Why nothing could be read; '' when fine.
     *     @type array  $columns          Field => header text of the column used (null = none).
     *     @type int    $rows             Data rows read (blank lines not counted).
     *     @type array  $entries          [ { row, tracking_number, fields } ] ready to apply;
     *                                    `fields` holds label_cost and whichever optional fields the row gave.
     *     @type array  $invalid          [ { row, tracking_number, reason } ]
     *     @type int    $repeated         Rows repeating an earlier row's tracking number and cost.
     *     @type int    $ignored_optional Rows whose weight or box size could not be read (cost still used).
     * }
     */
    public static function read_import( $text, $default_source = null ) {
        $read = array(
            'error'            => '',
            'columns'          => array(
                'tracking' => null,
                'cost'     => null,
                'weight'   => null,
                'dims'     => null,
                'source'   => null,
            ),
            'rows'             => 0,
            'entries'          => array(),
            'invalid'          => array(),
            'repeated'         => 0,
            'ignored_optional' => 0,
        );

        $rows = self::parse_csv( $text );

        // The header is the first line with anything on it.
        $header       = null;
        $header_index = 0;
        foreach ( $rows as $index => $row ) {
            if ( '' !== implode( '', $row ) ) {
                $header       = $row;
                $header_index = $index;
                break;
            }
        }
        if ( null === $header ) {
            $read['error'] = 'The file is empty.';
            return $read;
        }

        $columns = self::detect_csv_columns( $header );
        foreach ( $columns as $field => $index ) {
            if ( is_array( $index ) ) {
                $read['columns'][ $field ] = $header[ $index[0] ] . ' / ' . $header[ $index[1] ] . ' / ' . $header[ $index[2] ];
            } elseif ( null !== $index ) {
                $read['columns'][ $field ] = $header[ $index ];
            }
        }

        if ( null === $columns['tracking'] || null === $columns['cost'] ) {
            $read['error'] = sprintf(
                'The header row needs a tracking column (tracking, tracking number, tracking #) and a cost column (cost, amount, total, charge, price). It reads: %s',
                implode( ', ', array_filter( $header, 'strlen' ) )
            );
            return $read;
        }

        $default_source = self::parse_source( $default_source );
        $entries        = array();

        foreach ( $rows as $index => $row ) {
            if ( $index <= $header_index || '' === implode( '', $row ) ) {
                continue;
            }
            $read['rows']++;

            $row_number = $index + 1;
            $tracking   = self::clean_tracking_cell( self::cell( $row, $columns['tracking'] ) );

            if ( '' === $tracking ) {
                $read['invalid'][] = array( 'row' => $row_number, 'tracking_number' => '', 'reason' => 'No tracking number.' );
                continue;
            }
            // 9.40011E+21: the spreadsheet rounded the number away. The real
            // digits are gone, so the row is reported instead of guessed.
            if ( preg_match( '/^[+-]?\d+(?:[.,]\d+)?e[+-]?\d+$/i', $tracking ) ) {
                $read['invalid'][] = array(
                    'row'             => $row_number,
                    'tracking_number' => $tracking,
                    'reason'          => 'A spreadsheet turned this tracking number into scientific notation. Format the column as text and export again.',
                );
                continue;
            }

            $cost_cell = self::cell( $row, $columns['cost'] );
            $cost      = self::parse_money( $cost_cell );
            if ( is_wp_error( $cost ) || null === $cost ) {
                $read['invalid'][] = array(
                    'row'             => $row_number,
                    'tracking_number' => $tracking,
                    'reason'          => null === $cost ? 'No cost.' : sprintf( 'The cost "%s" is not an amount of 0 or more.', $cost_cell ),
                );
                continue;
            }

            $fields  = array( 'label_cost' => $cost );
            $ignored = false;

            if ( null !== $columns['weight'] ) {
                $weight = self::parse_weight( self::cell( $row, $columns['weight'] ) );
                if ( is_wp_error( $weight ) ) {
                    $ignored = true;
                } elseif ( null !== $weight ) {
                    $fields['box_weight'] = $weight;
                }
            }

            if ( null !== $columns['dims'] ) {
                if ( is_array( $columns['dims'] ) ) {
                    $dims_cell = array();
                    foreach ( $columns['dims'] as $dims_index ) {
                        $dims_cell[] = self::cell( $row, $dims_index );
                    }
                    $dims_cell = '' === implode( '', $dims_cell ) ? '' : implode( 'x', $dims_cell );
                } else {
                    $dims_cell = self::cell( $row, $columns['dims'] );
                }
                $dims = self::parse_dims( $dims_cell );
                if ( is_wp_error( $dims ) ) {
                    $ignored = true;
                } elseif ( null !== $dims ) {
                    $fields['box_dims'] = $dims;
                }
            }

            $source = null !== $columns['source']
                ? self::parse_source( self::cell( $row, $columns['source'] ) )
                : $default_source;
            if ( null !== $source ) {
                $fields['label_source'] = $source;
            }

            if ( $ignored ) {
                $read['ignored_optional']++;
            }

            $entries[] = array(
                'row'             => $row_number,
                'tracking_number' => $tracking,
                'fields'          => $fields,
            );
        }

        // The same tracking number on several rows: one box has one cost. An
        // identical repeat is dropped; rows that disagree are all reported —
        // picking one of them would be a guess.
        $by_box = array();
        foreach ( $entries as $position => $entry ) {
            $by_box[ self::box_key( $entry['tracking_number'] ) ][] = $position;
        }
        foreach ( $by_box as $positions ) {
            if ( count( $positions ) < 2 ) {
                continue;
            }
            $costs       = array();
            $row_numbers = array();
            foreach ( $positions as $position ) {
                $costs[]       = $entries[ $position ]['fields']['label_cost'];
                $row_numbers[] = $entries[ $position ]['row'];
            }
            if ( 1 === count( array_unique( $costs ) ) ) {
                foreach ( array_slice( $positions, 1 ) as $position ) {
                    unset( $entries[ $position ] );
                    $read['repeated']++;
                }
                continue;
            }
            foreach ( $positions as $position ) {
                $read['invalid'][] = array(
                    'row'             => $entries[ $position ]['row'],
                    'tracking_number' => $entries[ $position ]['tracking_number'],
                    'reason'          => sprintf( 'Listed with different costs on rows %s.', implode( ', ', $row_numbers ) ),
                );
                unset( $entries[ $position ] );
            }
        }

        usort( $read['invalid'], array( __CLASS__, 'compare_import_rows' ) );
        $read['entries'] = array_values( $entries );

        return $read;
    }

    /**
     * A cell of a CSV row, '' when the row is shorter than the header.
     *
     * @param array $row
     * @param int   $index
     * @return string
     */
    private static function cell( array $row, $index ) {
        return isset( $row[ $index ] ) ? (string) $row[ $index ] : '';
    }

    /**
     * usort callback: import report rows in file order.
     */
    private static function compare_import_rows( $a, $b ) {
        return $a['row'] - $b['row'];
    }

    /**
     * Decide what one import row may write to its box.
     *
     * Without $overwrite a field is only filled where the box has none
     * recorded; with it every field the row gave replaces what is there. A
     * field the row did not give is never touched, so nothing is cleared.
     *
     * @param array $box       FST_Shipment::get_box_cost() result.
     * @param array $fields    Normalised fields from read_import().
     * @param bool  $overwrite
     * @return array {
     *     @type string $result 'matched' (cost written or replaced) or
     *                          'had_cost' (a cost was already recorded and kept).
     *     @type array  $write  Field => value to write.
     * }
     */
    public static function plan_import_write( array $box, array $fields, $overwrite ) {
        $write = array();
        foreach ( self::FIELDS as $field ) {
            if ( ! isset( $fields[ $field ] ) ) {
                continue;
            }
            if ( $overwrite || null === $box[ $field ] ) {
                $write[ $field ] = $fields[ $field ];
            }
        }

        return array(
            'result' => ( null !== $box['label_cost'] && ! $overwrite ) ? 'had_cost' : 'matched',
            'write'  => $write,
        );
    }

    /**
     * Import label costs from CSV text. Changes nothing but the four cost
     * fields of tracking numbers that already exist.
     *
     * @param string      $text           CSV with a header row.
     * @param string|null $default_source Used when the file has no source column.
     * @param bool        $overwrite      Replace costs that are already recorded.
     * @return array read_import() keys `error`, `columns`, `rows`, `repeated`,
     *               `ignored_optional`, plus counts `matched`, `had_cost`,
     *               `details_filled` (rows that kept their cost but had an
     *               empty weight / size / source filled in), `not_found`,
     *               `invalid`, and the lists `not_found_list`
     *               [ { row, tracking_number } ] and `invalid_list`
     *               [ { row, tracking_number, reason } ] (first 100 each).
     */
    public static function import( $text, $default_source = null, $overwrite = false ) {
        $read = self::read_import( $text, $default_source );

        $result = array(
            'error'            => $read['error'],
            'columns'          => $read['columns'],
            'rows'             => $read['rows'],
            'matched'          => 0,
            'had_cost'         => 0,
            'details_filled'   => 0,
            'not_found'        => 0,
            'invalid'          => count( $read['invalid'] ),
            'repeated'         => $read['repeated'],
            'ignored_optional' => $read['ignored_optional'],
            'not_found_list'   => array(),
            'invalid_list'     => array_slice( $read['invalid'], 0, self::IMPORT_LIST_LIMIT ),
        );

        foreach ( $read['entries'] as $entry ) {
            $box = FST_Shipment::get_box_cost( $entry['tracking_number'] );

            if ( null === $box ) {
                $result['not_found']++;
                if ( count( $result['not_found_list'] ) < self::IMPORT_LIST_LIMIT ) {
                    $result['not_found_list'][] = array(
                        'row'             => $entry['row'],
                        'tracking_number' => $entry['tracking_number'],
                    );
                }
                continue;
            }

            $plan = self::plan_import_write( $box, $entry['fields'], $overwrite );
            if ( ! empty( $plan['write'] ) ) {
                FST_Shipment::set_box_cost( $box['tracking_number'], $plan['write'] );
            }

            if ( 'had_cost' === $plan['result'] ) {
                $result['had_cost']++;
                if ( ! empty( $plan['write'] ) ) {
                    $result['details_filled']++;
                }
            } else {
                $result['matched']++;
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------
    // Analytics report.
    // -----------------------------------------------------------------

    /**
     * Label cost report for a ship-date range — what the Analytics page shows.
     *
     * @param string $date_from Y-m-d or ''.
     * @param string $date_to   Y-m-d or ''.
     * @return array See build_report().
     */
    public static function get_report( $date_from = '', $date_to = '' ) {
        $data   = FST_Shipment::get_cost_report_rows( $date_from, $date_to );
        $orders = array();

        foreach ( $data['rows'] as $row ) {
            $order_id = (int) $row->order_id;
            if ( isset( $orders[ $order_id ] ) ) {
                continue;
            }

            $order = wc_get_order( $order_id );
            if ( $order instanceof WC_Order ) {
                // What the customer paid for shipping, before tax, less any
                // of it that was refunded.
                $paid = (float) $order->get_shipping_total() - (float) $order->get_total_shipping_refunded();

                $orders[ $order_id ] = array(
                    'shipping' => max( 0, $paid ),
                    'number'   => (string) $order->get_order_number(),
                    'url'      => $order->get_edit_order_url(),
                );
            } else {
                $orders[ $order_id ] = array(
                    'shipping' => null,
                    'number'   => (string) $order_id,
                    'url'      => '',
                );
            }
        }

        return self::build_report( $data['tracking_numbers'], $data['rows'], $orders, self::standard_boxes() );
    }

    /**
     * The report maths — pure, all money in whole cents until the end.
     *
     * Rules:
     *  - A box is one tracking number; its cost is read once (box_from_rows()).
     *  - A box with no recorded cost (NULL) is counted as shipped but left
     *    out of every total, average and difference. NULL is never 0; a
     *    recorded cost of 0.00 is a real cost and is included.
     *  - Shipping paid by a box = the shipping of every order in it (a shared
     *    box sums its orders). An order that went out in several boxes has
     *    its shipping split evenly between them (spare cents to the earliest
     *    boxes), so it is counted once however many boxes it took.
     *
     * @param string[] $tracking_numbers Boxes shipped in the range.
     * @param array    $rows             Every shipment row (stdClass: id, order_id,
     *                                   tracking_number, carrier, status, ship_date and
     *                                   the four cost fields) of every order in those boxes.
     * @param array    $orders           order_id => { shipping (decimal; null = the order no
     *                                   longer exists, counted as 0 paid), number, url }.
     * @param array    $standard_boxes   FST_Settings::get_standard_boxes(), for size names.
     * @return array {
     *     @type array $summary        boxes, boxes_with_cost, boxes_without_cost, total_cost,
     *                                 total_paid, difference (paid − cost), avg_cost (null when
     *                                 no box has a cost), paid_without_cost. Money as decimal strings.
     *     @type array $by_size        [ { dims, label, boxes, avg_cost, avg_paid, avg_diff } ]
     *     @type array $by_source      [ { source, label, boxes, total_cost, avg_cost } ]
     *     @type array $size_by_source { sources: [ { source, label } ], rows: [ { dims, label,
     *                                 cells: source => { boxes, avg_cost }, cheapest } ] }
     *     @type array $losers         The 20 boxes with the most negative difference.
     *     @type array $boxes          Every box in the range (see code for keys).
     * }
     */
    public static function build_report( array $tracking_numbers, array $rows, array $orders, array $standard_boxes = array() ) {
        $rows_by_box = array();
        foreach ( $rows as $row ) {
            $rows_by_box[ self::box_key( $row->tracking_number ) ][] = $row;
        }

        // The boxes each order went out in (box key => that order's first
        // row id). A box nobody has seen move yet is not counted.
        $order_boxes = array();
        foreach ( $rows_by_box as $key => $box_rows ) {
            $shipped = false;
            foreach ( $box_rows as $row ) {
                if ( ! in_array( $row->status, self::EXCLUDED_STATUSES, true ) ) {
                    $shipped = true;
                    break;
                }
            }
            if ( ! $shipped ) {
                continue;
            }
            foreach ( $box_rows as $row ) {
                $order_id = (int) $row->order_id;
                $row_id   = (int) $row->id;
                if ( ! isset( $order_boxes[ $order_id ][ $key ] ) || $row_id < $order_boxes[ $order_id ][ $key ] ) {
                    $order_boxes[ $order_id ][ $key ] = $row_id;
                }
            }
        }

        // Each order's shipping, split between its boxes.
        $share = array();
        foreach ( $order_boxes as $order_id => $keys ) {
            asort( $keys );
            $cents = isset( $orders[ $order_id ]['shipping'] ) ? self::to_cents( $orders[ $order_id ]['shipping'] ) : 0;
            $count = count( $keys );
            $base  = intdiv( $cents, $count );
            $spare = $cents % $count;
            $n     = 0;
            foreach ( $keys as $key => $row_id ) {
                $share[ $key ][ $order_id ] = $base + ( $n < $spare ? 1 : 0 );
                $n++;
            }
        }

        $boxes = array();
        foreach ( $tracking_numbers as $tracking_number ) {
            $key = self::box_key( $tracking_number );
            if ( isset( $boxes[ $key ] ) || empty( $rows_by_box[ $key ] ) ) {
                continue;
            }

            $box   = self::box_from_rows( $rows_by_box[ $key ] );
            $first = null;
            foreach ( $rows_by_box[ $key ] as $row ) {
                if ( null === $first || (int) $row->id < (int) $first->id ) {
                    $first = $row;
                }
            }

            $paid_cents = 0;
            $box_orders = array();
            foreach ( $box['order_ids'] as $order_id ) {
                $paid_cents  += isset( $share[ $key ][ $order_id ] ) ? $share[ $key ][ $order_id ] : 0;
                $known        = isset( $orders[ $order_id ]['shipping'] );
                $box_orders[] = array(
                    'id'      => $order_id,
                    'number'  => isset( $orders[ $order_id ]['number'] ) ? (string) $orders[ $order_id ]['number'] : (string) $order_id,
                    'url'     => isset( $orders[ $order_id ]['url'] ) ? (string) $orders[ $order_id ]['url'] : '',
                    'missing' => ! $known,
                );
            }

            $cost_cents = null === $box['label_cost'] ? null : self::to_cents( $box['label_cost'] );

            $boxes[ $key ] = array(
                'tracking_number' => $box['tracking_number'],
                'carrier'         => isset( $first->carrier ) ? (string) $first->carrier : '',
                'ship_date'       => isset( $first->ship_date ) ? $first->ship_date : null,
                'label_cost'      => $box['label_cost'],
                'box_weight'      => $box['box_weight'],
                'box_dims'        => $box['box_dims'],
                'label_source'    => $box['label_source'],
                'size_label'      => self::size_label( $box['box_dims'], $standard_boxes ),
                'shared_box'      => $box['shared_box'],
                'orders'          => $box_orders,
                'cost_cents'      => $cost_cents,
                'paid_cents'      => $paid_cents,
                'diff_cents'      => null === $cost_cents ? null : $paid_cents - $cost_cents,
                'paid'            => self::from_cents( $paid_cents ),
                'difference'      => null === $cost_cents ? null : self::from_cents( $paid_cents - $cost_cents ),
            );
        }

        // Totals — boxes with a recorded cost only.
        $with_cost         = 0;
        $total_cost        = 0;
        $total_paid        = 0;
        $paid_without_cost = 0;
        $sizes             = array();
        $sources           = array();
        $matrix            = array();
        $losers            = array();

        foreach ( $boxes as $box ) {
            if ( null === $box['cost_cents'] ) {
                $paid_without_cost += $box['paid_cents'];
                continue;
            }

            $with_cost++;
            $total_cost += $box['cost_cents'];
            $total_paid += $box['paid_cents'];

            $dims   = null === $box['box_dims'] ? '' : $box['box_dims'];
            $source = null === $box['label_source'] ? '' : $box['label_source'];

            if ( ! isset( $sizes[ $dims ] ) ) {
                $sizes[ $dims ] = array( 'boxes' => 0, 'cost' => 0, 'paid' => 0 );
            }
            $sizes[ $dims ]['boxes']++;
            $sizes[ $dims ]['cost'] += $box['cost_cents'];
            $sizes[ $dims ]['paid'] += $box['paid_cents'];

            if ( ! isset( $sources[ $source ] ) ) {
                $sources[ $source ] = array( 'boxes' => 0, 'cost' => 0 );
            }
            $sources[ $source ]['boxes']++;
            $sources[ $source ]['cost'] += $box['cost_cents'];

            if ( ! isset( $matrix[ $dims ][ $source ] ) ) {
                $matrix[ $dims ][ $source ] = array( 'boxes' => 0, 'cost' => 0 );
            }
            $matrix[ $dims ][ $source ]['boxes']++;
            $matrix[ $dims ][ $source ]['cost'] += $box['cost_cents'];

            if ( $box['diff_cents'] < 0 ) {
                $losers[] = $box;
            }
        }

        // By box size, most used first.
        $by_size = array();
        foreach ( $sizes as $dims => $totals ) {
            $by_size[] = array(
                'dims'     => (string) $dims,
                'label'    => '' === (string) $dims ? 'Size not recorded' : self::size_label( (string) $dims, $standard_boxes ),
                'boxes'    => $totals['boxes'],
                'avg_cost' => self::from_cents( self::average( $totals['cost'], $totals['boxes'] ) ),
                'avg_paid' => self::from_cents( self::average( $totals['paid'], $totals['boxes'] ) ),
                'avg_diff' => self::from_cents( self::average( $totals['paid'] - $totals['cost'], $totals['boxes'] ) ),
            );
        }
        usort( $by_size, array( __CLASS__, 'compare_group_rows' ) );

        // By label source, most used first.
        $by_source = array();
        foreach ( $sources as $source => $totals ) {
            $by_source[] = array(
                'source'     => (string) $source,
                'label'      => '' === (string) $source ? 'Source not recorded' : (string) $source,
                'boxes'      => $totals['boxes'],
                'total_cost' => self::from_cents( $totals['cost'] ),
                'avg_cost'   => self::from_cents( self::average( $totals['cost'], $totals['boxes'] ) ),
            );
        }
        usort( $by_source, array( __CLASS__, 'compare_group_rows' ) );

        // Average cost per size per source, side by side.
        $matrix_sources = array();
        foreach ( $by_source as $group ) {
            $matrix_sources[] = array( 'source' => $group['source'], 'label' => $group['label'] );
        }
        $matrix_rows = array();
        foreach ( $by_size as $size ) {
            $cells       = array();
            $cheapest    = null;
            $lowest      = null;
            $tied        = false;
            $named_cells = 0;

            foreach ( $matrix_sources as $column ) {
                $source = $column['source'];
                if ( ! isset( $matrix[ $size['dims'] ][ $source ] ) ) {
                    $cells[ $source ] = null;
                    continue;
                }
                $cell             = $matrix[ $size['dims'] ][ $source ];
                $average          = self::average( $cell['cost'], $cell['boxes'] );
                $cells[ $source ] = array(
                    'boxes'    => $cell['boxes'],
                    'avg_cost' => self::from_cents( $average ),
                );

                // Only a named source can be "the cheaper partner".
                if ( '' === $source ) {
                    continue;
                }
                $named_cells++;
                if ( null === $lowest || $average < $lowest ) {
                    $lowest   = $average;
                    $cheapest = $source;
                    $tied     = false;
                } elseif ( $average === $lowest ) {
                    $tied = true;
                }
            }

            $matrix_rows[] = array(
                'dims'     => $size['dims'],
                'label'    => $size['label'],
                'cells'    => $cells,
                'cheapest' => ( $named_cells > 1 && ! $tied ) ? $cheapest : null,
            );
        }

        usort( $losers, array( __CLASS__, 'compare_losers' ) );

        return array(
            'summary'        => array(
                'boxes'              => count( $boxes ),
                'boxes_with_cost'    => $with_cost,
                'boxes_without_cost' => count( $boxes ) - $with_cost,
                'total_cost'         => self::from_cents( $total_cost ),
                'total_paid'         => self::from_cents( $total_paid ),
                'difference'         => self::from_cents( $total_paid - $total_cost ),
                'avg_cost'           => $with_cost > 0 ? self::from_cents( self::average( $total_cost, $with_cost ) ) : null,
                'paid_without_cost'  => self::from_cents( $paid_without_cost ),
            ),
            'by_size'        => $by_size,
            'by_source'      => $by_source,
            'size_by_source' => array(
                'sources' => $matrix_sources,
                'rows'    => $matrix_rows,
            ),
            'losers'         => array_slice( $losers, 0, 20 ),
            'boxes'          => array_values( $boxes ),
        );
    }

    /**
     * "Small (10x10x10)" when the size is a standard box, else the size.
     *
     * @param string|null $dims
     * @param array       $standard_boxes
     * @return string
     */
    public static function size_label( $dims, array $standard_boxes ) {
        if ( null === $dims || '' === $dims ) {
            return '';
        }
        foreach ( $standard_boxes as $box ) {
            if ( isset( $box['dims'], $box['name'] ) && $box['dims'] === $dims ) {
                return $box['name'] . ' (' . $dims . ')';
            }
        }
        return (string) $dims;
    }

    /**
     * A decimal amount as whole cents.
     *
     * @param string|float|int $amount
     * @return int
     */
    private static function to_cents( $amount ) {
        return (int) round( (float) $amount * 100 );
    }

    /**
     * Whole cents as a decimal string: -1230 is "-12.30".
     *
     * @param int $cents
     * @return string
     */
    private static function from_cents( $cents ) {
        $cents = (int) $cents;
        return ( $cents < 0 ? '-' : '' ) . self::format_hundredths( abs( $cents ) );
    }

    /**
     * Average in whole cents, rounded half away from zero.
     *
     * @param int $total_cents
     * @param int $count
     * @return int
     */
    private static function average( $total_cents, $count ) {
        return $count > 0 ? (int) round( $total_cents / $count ) : 0;
    }

    /**
     * usort callback: groups with the most boxes first, unrecorded last.
     */
    private static function compare_group_rows( $a, $b ) {
        $a_name = isset( $a['dims'] ) ? $a['dims'] : $a['source'];
        $b_name = isset( $b['dims'] ) ? $b['dims'] : $b['source'];

        if ( ( '' === $a_name ) !== ( '' === $b_name ) ) {
            return '' === $a_name ? 1 : -1;
        }
        if ( $a['boxes'] !== $b['boxes'] ) {
            return $b['boxes'] - $a['boxes'];
        }
        return strnatcasecmp( $a_name, $b_name );
    }

    /**
     * usort callback: the biggest loss first.
     */
    private static function compare_losers( $a, $b ) {
        if ( $a['diff_cents'] !== $b['diff_cents'] ) {
            return $a['diff_cents'] - $b['diff_cents'];
        }
        return strcmp( $a['tracking_number'], $b['tracking_number'] );
    }
}
