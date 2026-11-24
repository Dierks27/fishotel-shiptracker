<?php
/**
 * Static check: every callback the plugin hands to WordPress names a method
 * or function that the plugin's code defines. No WordPress, no database.
 *
 *   php tests/hook-callbacks-test.php
 *
 * Why: a hook whose callback names a method that does not exist is a fatal
 * TypeError on PHP 8 the moment the hook fires, and nothing shows until
 * then. Theme 1.24.0 shipped add_action( 'woocommerce_checkout_order_created',
 * array( __CLASS__, 'add_order_notes' ) ) without the method and every checkout
 * died at "Place order"; this is the same check for this plugin.
 *
 * What it reads (PHP tokens, not regexes):
 * - add_action / add_filter / remove_* / has_* / add_shortcode /
 *   register_*_hook callbacks: array( $this, 'x' ), array( __CLASS__, 'x' ),
 *   array( self::class, 'x' ), array( 'Class', 'x' ), array( $var, 'x' ) when $var was
 *   set from new Class / Class::instance(), 'function_name' and
 *   'Class::method' strings;
 * - every other array( $this, 'x' ) / array( __CLASS__, 'x' ) literal (menu pages,
 *   usort, REST routes).
 * A class's own parents and traits count (FST_Carrier_UPS answers for
 * what FST_Carrier defines).
 * Closures and callbacks held in variables are left alone. A self-check at
 * the top proves the scanner still reports each kind of broken callback.
 *
 * CLI only: the plugin zip ships this directory.
 */

if ( 'cli' !== PHP_SAPI ) {
    exit;
}

$root = dirname( __DIR__ );

// WordPress / WooCommerce functions handed over by name.
$wp_functions = array( '__return_true', '__return_false', '__return_empty_array', '__return_null', '__return_zero', '__return_empty_string' );

// 'Class' => array( 'method' ) inherited from a parent outside the plugin.
$outside_methods = array();

// The plugin hands over 84 callbacks today; far fewer means the scanner broke.
$minimum = 70;

/**
 * The scanner. Takes rel-path => source, returns what it found. Kept as one
 * function with no WordPress so the self-check below can feed it fixtures.
 *
 * @param array $sources        'relative/file.php' => PHP source.
 * @param array $wp_functions   Names of WordPress / WooCommerce functions the
 *                              code hands over as a string callback.
 * @param array $outside_methods 'Class' => array( 'method', … ) for methods a
 *                              class inherits from a parent outside this repo.
 * @return array { checked: int, skipped: int, failures: string[] }
 */
function fh_hook_scan( array $sources, array $wp_functions = array(), array $outside_methods = array() ) {
    $hook_functions = array(
        'add_action', 'add_filter', 'remove_action', 'remove_filter', 'has_action', 'has_filter',
        'add_shortcode', 'register_activation_hook', 'register_deactivation_hook', 'register_uninstall_hook',
    );

    $classes   = array(); // lower name => array( name, parent, traits[], methods[], magic )
    $functions = array(); // lower name => true (top-level and conditional functions)
    $files     = array(); // rel => array( tokens, scope-per-token )

    // Pass 1: every class, trait and function the code defines.
    foreach ( $sources as $rel => $src ) {
        $raw    = token_get_all( $src );
        $tokens = array();
        $line   = 1;
        foreach ( $raw as $t ) {
            if ( is_array( $t ) ) {
                $line = $t[2];
                if ( in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG ), true ) ) {
                    continue;
                }
                $tokens[] = array( $t[0], $t[1], $line );
            } else {
                $tokens[] = array( $t, $t, $line );
            }
        }

        $depth   = 0;
        $stack   = array(); // array( depth the body opened at, lower class name )
        $pending = null;    // class-like whose body has not opened yet
        $scope   = array();
        $n       = count( $tokens );
        for ( $i = 0; $i < $n; $i++ ) {
            $id      = $tokens[ $i ][0];
            $current = $stack ? $stack[ count( $stack ) - 1 ][1] : null;
            $scope[ $i ] = $current;

            if ( in_array( $id, array( T_CLASS, T_TRAIT, T_INTERFACE ), true ) ) {
                $prev = $i > 0 ? $tokens[ $i - 1 ][0] : null;
                if ( T_DOUBLE_COLON === $prev || T_NEW === $prev || ! isset( $tokens[ $i + 1 ] ) || T_STRING !== $tokens[ $i + 1 ][0] ) {
                    continue; // Foo::class, new class { … }
                }
                $name   = $tokens[ $i + 1 ][1];
                $parent = null;
                for ( $j = $i + 2; $j < $n && '{' !== $tokens[ $j ][0]; $j++ ) {
                    if ( T_EXTENDS === $tokens[ $j ][0] && T_INTERFACE !== $id ) {
                        $parent = ltrim( $tokens[ $j + 1 ][1], '\\' );
                    }
                }
                $classes[ strtolower( $name ) ] = array(
                    'name'    => $name,
                    'parent'  => $parent,
                    'traits'  => array(),
                    'methods' => array(),
                    'trait'   => T_TRAIT === $id,
                );
                $pending = strtolower( $name );
                continue;
            }

            if ( '{' === $id || T_CURLY_OPEN === $id || T_DOLLAR_OPEN_CURLY_BRACES === $id ) {
                $depth++;
                if ( null !== $pending && '{' === $id ) {
                    $stack[] = array( $depth, $pending );
                    $pending = null;
                }
                continue;
            }
            if ( '}' === $id ) {
                if ( $stack && $stack[ count( $stack ) - 1 ][0] === $depth ) {
                    array_pop( $stack );
                }
                $depth--;
                continue;
            }

            $in_body = $stack && $stack[ count( $stack ) - 1 ][0] === $depth;

            if ( T_USE === $id && $in_body ) {
                for ( $j = $i + 1; $j < $n && ';' !== $tokens[ $j ][0] && '{' !== $tokens[ $j ][0]; $j++ ) {
                    if ( T_STRING === $tokens[ $j ][0] || ( defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $tokens[ $j ][0] ) || ( defined( 'T_NAME_FULLY_QUALIFIED' ) && T_NAME_FULLY_QUALIFIED === $tokens[ $j ][0] ) ) {
                        $classes[ $current ]['traits'][] = strtolower( ltrim( $tokens[ $j ][1], '\\' ) );
                    }
                }
                continue;
            }

            if ( T_FUNCTION === $id ) {
                $j = $i + 1;
                if ( isset( $tokens[ $j ] ) && '&' === $tokens[ $j ][0] ) {
                    $j++;
                }
                if ( ! isset( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] ) {
                    continue; // a closure
                }
                $fname = strtolower( $tokens[ $j ][1] );
                if ( $in_body ) {
                    $classes[ $current ]['methods'][ $fname ] = true;
                } else {
                    $functions[ $fname ] = true;
                }
            }
        }
        $files[ $rel ] = array( $tokens, $scope );
    }

    // Which classes take in a trait, directly or through another trait.
    $users_of = array();
    foreach ( $classes as $lc => $c ) {
        if ( $c['trait'] ) {
            continue;
        }
        $seen  = array();
        $queue = $c['traits'];
        while ( $queue ) {
            $t = array_shift( $queue );
            if ( isset( $seen[ $t ] ) ) {
                continue;
            }
            $seen[ $t ]       = true;
            $users_of[ $t ][] = $lc;
            if ( isset( $classes[ $t ] ) ) {
                $queue = array_merge( $queue, $classes[ $t ]['traits'] );
            }
        }
    }

    // Does $lc (class or trait) answer to $method, with its traits and parents?
    $has_method = function ( $lc, $method, $seen = array() ) use ( &$has_method, $classes, $outside_methods ) {
        if ( isset( $seen[ $lc ] ) || ! isset( $classes[ $lc ] ) ) {
            return false;
        }
        $seen[ $lc ] = true;
        $c           = $classes[ $lc ];
        if ( isset( $c['methods'][ $method ] ) || isset( $c['methods']['__call'] ) || isset( $c['methods']['__callstatic'] ) ) {
            return true;
        }
        foreach ( $c['traits'] as $t ) {
            if ( $has_method( $t, $method, $seen ) ) {
                return true;
            }
        }
        foreach ( $outside_methods as $class => $list ) {
            if ( strtolower( $class ) === $lc && in_array( $method, array_map( 'strtolower', $list ), true ) ) {
                return true;
            }
        }
        if ( null !== $c['parent'] ) {
            $p = strtolower( $c['parent'] );
            if ( isset( $classes[ $p ] ) ) {
                return $has_method( $p, $method, $seen );
            }
        }
        return false;
    };

    // Pass 2: every callback.
    $checked  = 0;
    $skipped  = 0;
    $failures = array();
    $done     = array(); // "rel:token index" of array literals already checked

    foreach ( $files as $rel => $file ) {
        list( $tokens, $scope ) = $file;
        $n = count( $tokens );

        // The tokens of one argument list, split at top-level commas.
        $split_args = function ( $open ) use ( $tokens, $n ) {
            $args  = array();
            $cur   = array();
            $level = 0;
            for ( $j = $open + 1; $j < $n; $j++ ) {
                $id = $tokens[ $j ][0];
                if ( in_array( $id, array( '(', '[', '{', T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
                    $level++;
                } elseif ( in_array( $id, array( ')', ']', '}' ), true ) ) {
                    if ( 0 === $level ) {
                        if ( $cur ) {
                            $args[] = $cur;
                        }
                        return array( $args, $j );
                    }
                    $level--;
                } elseif ( ',' === $id && 0 === $level ) {
                    $args[] = $cur;
                    $cur    = array();
                    continue;
                }
                $cur[] = $j;
            }
            return array( $args, $n );
        };

        // The class a variable was last given before token $at, if plain.
        $class_of_var = function ( $var, $at ) use ( $tokens, $scope ) {
            for ( $j = $at - 1; $j > 1; $j-- ) {
                if ( T_VARIABLE !== $tokens[ $j ][0] || $tokens[ $j ][1] !== $var || '=' !== $tokens[ $j + 1 ][0] ) {
                    continue;
                }
                $a = $tokens[ $j + 2 ];
                $b = isset( $tokens[ $j + 3 ] ) ? $tokens[ $j + 3 ] : array( null, '' );
                if ( T_VARIABLE === $a[0] && '$this' === $a[1] ) {
                    return $scope[ $j ];
                }
                if ( T_NEW === $a[0] ) {
                    $a = $b;
                    $b = array( T_DOUBLE_COLON, '::' );
                }
                if ( T_STRING === $a[0] && T_DOUBLE_COLON === $b[0] ) {
                    $lc = strtolower( ltrim( $a[1], '\\' ) );
                    return in_array( $lc, array( 'self', 'static' ), true ) ? $scope[ $j ] : $lc;
                }
                return false;
            }
            return false;
        };

        // The classes "$this" / self can be at token $at: the class, or every
        // class that takes in the trait the code sits in.
        $contexts = function ( $at ) use ( $scope, $classes, $users_of ) {
            $lc = $scope[ $at ];
            if ( null === $lc ) {
                return array();
            }
            if ( $classes[ $lc ]['trait'] && ! empty( $users_of[ $lc ] ) ) {
                return $users_of[ $lc ];
            }
            return array( $lc );
        };

        $report = function ( $at, $what ) use ( &$failures, $rel, $tokens ) {
            $failures[] = $rel . ':' . $tokens[ $at ][2] . ' — ' . $what;
        };

        // Checks one callback given as token indexes; true when it could be checked.
        $check = function ( array $arg, $hook_position ) use ( &$check_pair, &$checked, &$skipped, $tokens, $functions, $wp_functions, $classes, $has_method, $report ) {
            if ( 1 === count( $arg ) && T_CONSTANT_ENCAPSED_STRING === $tokens[ $arg[0] ][0] ) {
                if ( ! $hook_position ) {
                    return;
                }
                $name = trim( substr( $tokens[ $arg[0] ][1], 1, -1 ) );
                $checked++;
                if ( false !== strpos( $name, '::' ) ) {
                    list( $class, $method ) = explode( '::', ltrim( $name, '\\' ), 2 );
                    if ( ! isset( $classes[ strtolower( $class ) ] ) ) {
                        $report( $arg[0], "callback '$name' names class $class, which this code does not define" );
                    } elseif ( ! $has_method( strtolower( $class ), strtolower( $method ) ) ) {
                        $report( $arg[0], "callback '$name' names $class::$method(), which is not defined" );
                    }
                    return;
                }
                $lc = strtolower( ltrim( $name, '\\' ) );
                if ( isset( $functions[ $lc ] ) || in_array( $lc, array_map( 'strtolower', $wp_functions ), true ) ) {
                    return;
                }
                $is_internal = function_exists( $lc ) && ( new ReflectionFunction( $lc ) )->isInternal();
                if ( ! $is_internal ) {
                    $report( $arg[0], "callback '$name' names a function that is not defined" );
                }
                return;
            }
            $first = $tokens[ $arg[0] ][0];
            $last  = $tokens[ $arg[ count( $arg ) - 1 ] ][0];
            if ( ( T_ARRAY === $first && ')' === $last ) || ( '[' === $first && ']' === $last ) ) {
                $check_pair( $arg[ T_ARRAY === $first ? 1 : 0 ], $hook_position );
                return;
            }
            if ( $hook_position ) {
                $skipped++; // a closure or a variable: nothing to look up
            }
        };

        // An array( X, 'method' ) / [ X, 'method' ] literal opening at $open.
        $check_pair = function ( $open, $hook_position ) use ( &$done, &$checked, &$skipped, $rel, $tokens, $split_args, $class_of_var, $contexts, $classes, $has_method, $report ) {
            $key = $rel . ':' . $open;
            if ( isset( $done[ $key ] ) ) {
                return;
            }
            list( $args ) = $split_args( $open );
            if ( 2 !== count( $args ) || 1 !== count( $args[1] ) || T_CONSTANT_ENCAPSED_STRING !== $tokens[ $args[1][0] ][0] ) {
                if ( $hook_position ) {
                    $done[ $key ] = true;
                    $skipped++;
                }
                return;
            }
            $method = substr( $tokens[ $args[1][0] ][1], 1, -1 );
            $who    = $args[0];
            $t0     = $tokens[ $who[0] ];
            $t1     = isset( $who[1] ) ? $tokens[ $who[1] ] : array( null, '' );
            $t2     = isset( $who[2] ) ? $tokens[ $who[2] ] : array( null, '' );
            $owners = null; // lower class names the method must be on
            $label  = null;

            if ( 1 === count( $who ) && T_VARIABLE === $t0[0] && '$this' === $t0[1] ) {
                $owners = $contexts( $who[0] );
                $label  = '$this';
            } elseif ( 1 === count( $who ) && T_CLASS_C === $t0[0] ) {
                $owners = $contexts( $who[0] );
                $label  = '__CLASS__';
            } elseif ( 3 === count( $who ) && ( T_STRING === $t0[0] || T_STATIC === $t0[0] ) && T_DOUBLE_COLON === $t1[0] && T_CLASS === $t2[0] ) {
                $lc = strtolower( ltrim( $t0[1], '\\' ) );
                if ( in_array( $lc, array( 'self', 'static' ), true ) ) {
                    $owners = $contexts( $who[0] );
                } else {
                    $owners = array( $lc );
                }
                $label = $t0[1] . '::class';
            } elseif ( $hook_position && 1 === count( $who ) && T_CONSTANT_ENCAPSED_STRING === $t0[0] ) {
                $owners = array( strtolower( ltrim( substr( $t0[1], 1, -1 ), '\\' ) ) );
                $label  = $t0[1];
            } elseif ( $hook_position && 1 === count( $who ) && T_VARIABLE === $t0[0] ) {
                $lc = $class_of_var( $t0[1], $who[0] );
                if ( false !== $lc && null !== $lc ) {
                    $owners = array( $lc );
                    $label  = $t0[1];
                }
            } elseif ( $hook_position && T_NEW === $t0[0] && T_STRING === $t1[0] ) {
                $owners = array( strtolower( ltrim( $t1[1], '\\' ) ) );
                $label  = 'new ' . $t1[1];
            }

            if ( null === $owners ) {
                if ( $hook_position ) {
                    $done[ $key ] = true;
                    $skipped++;
                }
                return;
            }
            $done[ $key ] = true;
            if ( ! $owners ) {
                return; // $this outside a class: a template, not a callback
            }
            $checked++;
            foreach ( $owners as $lc ) {
                if ( ! isset( $classes[ $lc ] ) ) {
                    $report( $args[1][0], "callback [ $label, '$method' ] names class $lc, which this code does not define" );
                } elseif ( ! $has_method( $lc, strtolower( $method ) ) ) {
                    $report( $args[1][0], "callback [ $label, '$method' ] names " . $classes[ $lc ]['name'] . "::$method(), which is not defined" );
                }
            }
        };

        // Hook calls first, so a string or a variable in callback position is read.
        for ( $i = 0; $i < $n - 1; $i++ ) {
            if ( T_STRING !== $tokens[ $i ][0] || '(' !== $tokens[ $i + 1 ][0] || ! in_array( strtolower( $tokens[ $i ][1] ), $hook_functions, true ) ) {
                continue;
            }
            $prev = $i > 0 ? $tokens[ $i - 1 ][0] : null;
            if ( in_array( $prev, array( T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON ), true ) ) {
                continue;
            }
            list( $args ) = $split_args( $i + 1 );
            if ( isset( $args[1] ) ) {
                $check( $args[1], true );
            }
        }

        // Then every other array( $this, 'x' ) style literal: menu pages,
        // REST routes, usort and the like.
        for ( $i = 0; $i < $n; $i++ ) {
            if ( '[' === $tokens[ $i ][0] || ( T_ARRAY === $tokens[ $i ][0] && isset( $tokens[ $i + 1 ] ) && '(' === $tokens[ $i + 1 ][0] ) ) {
                $check_pair( '[' === $tokens[ $i ][0] ? $i : $i + 1, false );
            }
        }
    }

    return array(
        'checked'  => $checked,
        'skipped'  => $skipped,
        'failures' => $failures,
    );
}

// ---------------------------------------------------------------------------
// Self-check: the scanner itself must catch each kind of broken callback.
// ---------------------------------------------------------------------------

$fixture = array(
    'fixture/plain.php' => '<?php
        function fh_fixture_present() {}
        class FH_Fixture {
            public function __construct() {
                add_action( "init", array( $this, "present" ) );
                add_action( "init", array( $this, "missing_on_this" ) );
                add_action( "init", [ __CLASS__, "present" ] );
                add_action( "init", [ self::class, "missing_on_self" ] );
                add_filter( "x", "fh_fixture_present" );
                add_filter( "x", "fh_fixture_missing_function" );
                add_filter( "x", "trim" );
                add_filter( "x", "__return_true" );
                add_filter( "x", "FH_Fixture::present" );
                add_filter( "x", "FH_Fixture::missing_static" );
                $other = new FH_Fixture_Other();
                add_action( "init", array( $other, "missing_on_var" ) );
                add_action( "init", function () {} );
                add_menu_page( "a", "b", "c", "d", array( $this, "missing_menu_page" ) );
            }
            public function present() {}
        }
        class FH_Fixture_Other extends FH_Fixture {}',
    'fixture/traits.php' => '<?php
        trait FH_Fixture_Part_A {
            public function hooks_a() {
                add_action( "init", array( $this, "from_part_b" ) );
                add_action( "init", array( $this, "missing_in_trait" ) );
            }
        }
        trait FH_Fixture_Part_B {
            public function from_part_b() {}
        }
        class FH_Fixture_Main {
            use FH_Fixture_Part_A, FH_Fixture_Part_B;
        }',
);
$expect = array(
    'missing_on_this', 'missing_on_self', 'fh_fixture_missing_function', 'missing_static',
    'missing_on_var', 'missing_menu_page', 'missing_in_trait',
);
$self_check = fh_hook_scan( $fixture, array( '__return_true' ) );
$self_fail  = 0;
foreach ( $expect as $name ) {
    $hits = preg_grep( '~\b' . preg_quote( $name, '~' ) . '\b~', $self_check['failures'] );
    if ( 1 !== count( $hits ) ) {
        $self_fail++;
        echo "  FAIL self-check: a callback naming $name() was not reported\n";
    }
}
if ( count( $self_check['failures'] ) !== count( $expect ) ) {
    $self_fail++;
    echo "  FAIL self-check: expected " . count( $expect ) . ' reports, got ' . count( $self_check['failures'] ) . ":\n    " . implode( "\n    ", $self_check['failures'] ) . "\n";
}
if ( 0 === $self_fail ) {
    echo '  ok   self-check: the scanner reports all ' . count( $expect ) . " broken fixture callbacks and nothing else\n";
}

// ---------------------------------------------------------------------------
// The real code.
// ---------------------------------------------------------------------------

$sources = array();
$it      = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
    $path = $f->getPathname();
    if ( substr( $path, -4 ) !== '.php' ) {
        continue;
    }
    $rel = ltrim( str_replace( '\\', '/', substr( $path, strlen( $root ) ) ), '/' );
    if ( preg_match( '~^(vendor|tests|node_modules|\.git)/~', $rel ) ) {
        continue;
    }
    $sources[ $rel ] = file_get_contents( $path );
}
ksort( $sources );

$result   = fh_hook_scan( $sources, $wp_functions, $outside_methods );
$failures = $self_fail;
foreach ( $result['failures'] as $line ) {
    $failures++;
    echo "  FAIL $line\n";
}
echo '  ' . ( $result['failures'] ? 'checked' : 'ok   checked' ) . ' ' . $result['checked'] . ' callbacks in ' . count( $sources ) . ' files (' . $result['skipped'] . " closures or variables left alone)\n";
if ( $result['checked'] < $minimum ) {
    $failures++;
    echo "  FAIL the scan found fewer than $minimum callbacks — the scanner no longer matches the code\n";
}

echo "\n" . ( $failures ? "$failures FAILED\n" : "all passed\n" );
exit( $failures ? 1 : 0 );
