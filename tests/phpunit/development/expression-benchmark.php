<?php
/**
 * Performance benchmark comparing Approach A vs Approach B for the
 * Interactivity API full-expression evaluation.
 *
 * Usage: php tests/phpunit/development/expression-benchmark.php
 *
 * @package WordPress
 * @subpackage Interactivity API
 */

// ── Minimal WordPress stubs ──────────────────────────────────────────────
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__, 3 ) . '/src/' );
}
if ( ! defined( 'WPINC' ) ) {
    define( 'WPINC', 'wp-includes' );
}

// Silence WordPress functions that the SSR evaluator calls but that are
// irrelevant for benchmarking.
if ( ! function_exists( '_doing_it_wrong' ) ) {
    function _doing_it_wrong( ...$args ) {}
}
if ( ! function_exists( 'wp_trigger_error' ) ) {
    function wp_trigger_error( ...$args ) {}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
    function wp_json_encode( $value ) { return json_encode( $value ); }
}
if ( ! function_exists( '__' ) ) {
    function __( $text ) { return $text; }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $text ) { return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' ); }
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter( ...$args ) {}
}
if ( ! function_exists( 'wp_register_style' ) ) {
    function wp_register_style( ...$args ) {}
}
if ( ! function_exists( 'wp_add_inline_style' ) ) {
    function wp_add_inline_style( ...$args ) {}
}
if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style( ...$args ) {}
}

// ── Include the real Interactivity API source files ────────────────────
$src_dir = __DIR__ . '/../../../src/wp-includes/interactivity-api';

require_once $src_dir . '/class-wp-interactivity-expression-evaluator.php';
require_once $src_dir . '/class-wp-interactivity-api.php';

// ── Benchmark configuration ────────────────────────────────────────────
/** Number of iterations per expression for stable timing. */
define( 'ITER', 1000 );

/**
 * Collection of test expressions covering the supported grammar.
 * Includes comparisons, logical operators, ternaries, nullish coalescing,
 * nested paths, bitwise, truthiness, multi-statement, and the new
 * el/evt / actions/callbacks client-only identifiers.
 */
$expressions = [
    // Basic comparisons
    'state.count === 5',
    'state.count !== context.n',
    'state.count > 0',
    'state.count >= 5',
    'state.count < 10',
    'state.count <= 5',

    // Logical operators
    'state.flag && context.x',
    'state.flag || context.y',
    '!state.flag',
    '!!state.flag',

    // Ternary
    'state.count > 0 ? "yes" : "no"',
    'context.x ? state.count : context.n',
    '(state.count > 10) ? "big" : (state.flag ? "mid" : "small")',

    // Nullish coalescing
    'state.count ?? state.flag',
    'state.zero ?? 7',
    'state.emptyString ?? "fallback"',
    'state.nullish ?? "fallback"',

    // Nested/compound
    'context.currentUserId !== context.authorId && context.isPinned',
    '(context.x || context.y) && (context.c || context.d)',
    '(context.x || context.y) && (context.d && context.y)',

    // Array/string length
    'state.count + context.n',
    'state.name + context.n',

    // Bitwise / shift / exponentiation
    'state.a & state.b',
    'state.a | state.b',
    'state.a ^ state.b',
    '~state.a',
    'state.a << state.shift',
    'state.a >> state.shift',
    'state.count ** 2',

    // JS truthiness
    'state.emptyArray ? "yes" : "no"',
    'state.zeroString ? "yes" : "no"',
    '!state.emptyArray',
    'state.emptyArray || state.flag',

    // Loose vs strict equality
    'state.stringNumber == state.count',
    'state.stringNumber === state.count',
    'state.emptyString == 0',

    // Multi-statement
    'context.y; context.x',
    'state.count; state.flag && context.x',
    'state.count;',

    // actions.* / callbacks.* (client-only → null)
    'actions.someAction',
    'callbacks.myCallback',
    'callbacks.x || context.x',

    // el / evt (client-only → null)
    'el',
    'evt',
    'el.tagName',
    'evt.type',
    '!el',
    '!evt',
    'el || state.flag',
];

// ── Helpers ─────────────────────────────────────────────────────────────

/**
 * Set a private/protected property on an object via reflection.
 */
function set_private( object $obj, string $prop, mixed $value ): void {
    $rp = new ReflectionProperty( $obj, $prop );
    if ( PHP_VERSION_ID < 80100 ) {
        $rp->setAccessible( true );
    }
    $rp->setValue( $obj, $value );
}

/**
 * Get a private/protected property from an object via reflection.
 */
function get_private( object $obj, string $prop ): mixed {
    $rp = new ReflectionProperty( $obj, $prop );
    if ( PHP_VERSION_ID < 80100 ) {
        $rp->setAccessible( true );
    }
    return $rp->getValue( $obj );
}

/**
 * Time a callable over ITER iterations, returning the elapsed ms.
 */
function bench( string $label, callable $fn ): float {
    $t = microtime( true );
    for ( $i = 0; $i < ITER; $i++ ) {
        $fn();
    }
    return ( microtime( true ) - $t ) * 1000;
}

/**
 * Format a microsecond-level duration with colour (ANSI) for the terminal.
 */
function fmt_ms( float $ms ): string {
    if ( $ms > 500 ) {
        return "\033[31m" . number_format( $ms, 1 ) . "ms\033[0m";  // red
    }
    if ( $ms > 100 ) {
        return "\033[33m" . number_format( $ms, 1 ) . "ms\033[0m";  // yellow
    }
    return "\033[32m" . number_format( $ms, 1 ) . "ms\033[0m";      // green
}

// ── Set up WP_Interactivity_API instance with fixture data ─────────────
$interactivity = new WP_Interactivity_API();

// Set the namespace stack to a single namespace.
set_private( $interactivity, 'namespace_stack', [ 'myplugin' ] );

// Set the context stack to a single context frame.
set_private( $interactivity, 'context_stack', [ [
    'myplugin' => [
        'currentUserId' => 8,
        'authorId'      => 6,
        'isPinned'      => true,
        'count'         => 5,
        'x'             => true,
        'y'             => false,
        'n'             => 42,
        'c'             => true,
        'd'             => false,
    ],
] ] );

// Populate state.
$interactivity->state( 'myplugin', [
    'count'        => 5,
    'flag'         => true,
    'name'         => 'bob',
    'zero'         => 0,
    'stringNumber' => '5',
    'zeroString'   => '0',
    'emptyString'  => '',
    'nullish'      => null,
    'emptyArray'   => [],
    'emptyObject'  => new stdClass(),
    'a'            => 6,
    'b'            => 3,
    'shift'        => 1,
    'items'        => [
        [ 'name' => 'a', 'active' => true ],
        [ 'name' => 'b', 'active' => false ],
        [ 'name' => 'c', 'active' => true ],
    ],
] );

// Build the $store array mirroring what evaluate() constructs.
$store = [
    'state'   => $interactivity->state( 'myplugin' ),
    'context' => $interactivity->get_context( 'myplugin' ),
];

// Pre-bind the private methods via reflection (once, not per-call).
$rm_a = new ReflectionMethod( $interactivity, 'evaluate_full_expression_approach_a' );
$rm_b = new ReflectionMethod( $interactivity, 'evaluate_full_expression_approach_b' );
if ( PHP_VERSION_ID < 80100 ) {
    $rm_a->setAccessible( true );
    $rm_b->setAccessible( true );
}

$approach_a = function ( string $path ) use ( $interactivity, $rm_a, $store ) {
    return $rm_a->invoke( $interactivity, $path, $store, 'myplugin' );
};
$approach_b = function ( string $path ) use ( $interactivity, $rm_b, $store ) {
    return $rm_b->invoke( $interactivity, $path, $store, 'myplugin' );
};

// ── Results ─────────────────────────────────────────────────────────────
echo "\n=== Expression evaluation benchmark (" . ITER . " iterations each) ===\n\n";
printf( "%-55s  %12s  %12s  %10s\n", 'Expression', 'A (ms)', 'B (ms)', 'Match' );
echo str_repeat( '-', 94 ) . "\n";

$total_a = 0.0;
$total_b = 0.0;
$match_count = 0;
$mismatch_count = 0;

foreach ( $expressions as $expr ) {
    // Pre-warm: run once before timing to eliminate one-time setup cost
    // (class autoloading, JIT warm-up, etc.).
    $result_a_warm = $approach_a( $expr );
    $result_b_warm = $approach_b( $expr );

    $ms_a = bench( 'a', fn() => $approach_a( $expr ) );
    $ms_b = bench( 'b', fn() => $approach_b( $expr ) );
    $total_a += $ms_a;
    $total_b += $ms_b;

    $result_a = $approach_a( $expr );
    $result_b = $approach_b( $expr );
    $match    = $result_a === $result_b;

    if ( $match ) {
        ++$match_count;
    } else {
        ++$mismatch_count;
    }

    $norm_a = is_bool( $result_a ) ? ( $result_a ? 'true' : 'false' ) : var_export( $result_a, true );
    $norm_b = is_bool( $result_b ) ? ( $result_b ? 'true' : 'false' ) : var_export( $result_b, true );

    printf(
        "%-55s  %12s  %12s  %6s\n",
        mb_substr( $expr, 0, 54 ),
        fmt_ms( $ms_a ),
        fmt_ms( $ms_b ),
        $match ? "\033[32m✓\033[0m" : "\033[31m✗ $norm_a/$norm_b\033[0m"
    );
}

// ── Summary ─────────────────────────────────────────────────────────────
echo str_repeat( '-', 94 ) . "\n";
$winner = $total_a < $total_b ? 'A' : 'B';
$ratio  = $total_a < $total_b ? $total_b / $total_a : $total_a / $total_b;
printf(
    "%-55s  %12s  %12s  %6s\n",
    'TOTAL (' . count( $expressions ) . ' expressions)',
    fmt_ms( $total_a ),
    fmt_ms( $total_b ),
    ''
);
printf(
    "\n\033[1mWinner: Approach %s (%.2f× faster)\033[0m\n",
    $winner,
    $ratio
);
printf( "Matching results: %d / %d\n", $match_count, $match_count + $mismatch_count );
if ( $mismatch_count > 0 ) {
    printf( "\033[33mMismatches: %d\033[0m\n", $mismatch_count );
}
echo "\n";
