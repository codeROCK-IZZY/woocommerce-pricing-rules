<?php
/**
 * Offline evaluation of the pricing engine. Writes eval/reports/report.{json,md,html}; with --gate exits 1 on a miss.
 *
 * Usage: php eval/run.php [--gate]
 *
 * @package PricingRules
 */

namespace PricingRules\Evaluation;

use PricingRules\Engine\Context;
use PricingRules\Engine\Line;
use PricingRules\Engine\PricingEngine;
use PricingRules\Engine\Rule;

require dirname( __DIR__ ) . '/src/autoload.php';
require __DIR__ . '/ReferencePricer.php';
require __DIR__ . '/RandomCarts.php';
require __DIR__ . '/Mutants.php';

$gate   = in_array( '--gate', $argv, true );
$engine = new PricingEngine();

// ------------------------------------------------------------ 1. engine = independent reference
$cases      = 20000;
$check      = trim( (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/check.php' ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/src' ) . ' ' . $cases ) );
$mismatches = (int) $check;

// ------------------------------------------------------------ 2. mutation score
$mutants = Mutants::run( dirname( __DIR__ ) . '/src', 2000 );
$caught  = count( array_filter( $mutants ) );

// ------------------------------------------------------------ 3. invariants on random carts
mt_srand( 99 );
$inv        = array(
	'below floor or negative' => 0,
	'lines do not sum'        => 0,
	'rules do not sum'        => 0,
);
$discounted = 0;
for ( $i = 0; $i < $cases; $i++ ) {
	$lines = RandomCarts::cart();
	$ctx   = RandomCarts::context();
	$res   = $engine->price( $lines, RandomCarts::rules(), $ctx );
	$sum   = 0;
	foreach ( $lines as $l ) {
		$line = $res->lines[ $l->key ] ?? null;
		if ( null === $line ) {
			continue;
		}
		$sum  += $line['total'];
		$floor = PricingEngine::floor( $l->unit_cents, $ctx->cap_bp ) * $l->qty;
		if ( $line['total'] < $floor || $line['total'] < 0 ) {
			++$inv['below floor or negative'];
		}
	}
	if ( $sum !== $res->total() ) {
		++$inv['lines do not sum'];
	}
	if ( array_sum( $res->by_rule ) !== $res->discount ) {
		++$inv['rules do not sum'];
	}
	$discounted += $res->discount > 0 ? 1 : 0;
}

// ------------------------------------------------------------ 4. common shortcuts, measured
// a) "$10 off orders over $50" spread over lines by rounding each line's share separately.
mt_srand( 7 );
$drift_naive = 0;
$drift_ours  = 0;
$drift_cases = 5000;
$worst       = 0;
for ( $i = 0; $i < $drift_cases; $i++ ) {
	$lines = RandomCarts::cart( 8, 6 );
	$sub   = array_sum( array_map( static fn ( Line $l ): int => $l->unit_cents * $l->qty, $lines ) );
	if ( $sub <= 0 ) {
		--$i;
		continue;
	}
	$want  = mt_rand( 0, 1 ) ? min( $sub, 1000 * mt_rand( 1, 5 ) ) : PricingEngine::pct( $sub, 500 * mt_rand( 1, 6 ) );
	$naive = 0;
	foreach ( $lines as $l ) {
		$naive += (int) round( $want * ( $l->unit_cents * $l->qty ) / $sub ); // Each line rounded on its own.
	}
	if ( $naive !== $want ) {
		++$drift_naive;
		$worst = max( $worst, abs( $naive - $want ) );
	}
	$rule = Rule::from_array(
		array(
			'id'    => 1,
			'type'  => 'cart',
			'fixed' => $want,
		)
	);
	$res  = $engine->price( $lines, array( $rule ), new Context() );
	if ( $res->discount !== $want ) {
		++$drift_ours;
	}
}

// b) Stacking percentages by adding them up (20% sale + 30% member + 60% clearance = 110% off).
mt_srand( 11 );
$stack_cases = 5000;
$stack_naive = 0;
$stack_ours  = 0;
for ( $i = 0; $i < $stack_cases; $i++ ) {
	$lines = RandomCarts::cart( 5, 4 );
	$rules = array();
	$sum   = 0;
	for ( $k = 1, $n = mt_rand( 2, 4 ); $k <= $n; $k++ ) {
		$bp      = 500 * mt_rand( 2, 12 );
		$sum    += $bp;
		$rules[] = Rule::from_array(
			array(
				'id'   => $k,
				'type' => 'sale',
				'bp'   => $bp,
			)
		);
	}
	$naive_negative = false;
	foreach ( $lines as $l ) {
		if ( $l->unit_cents > 0 && $l->unit_cents - PricingEngine::pct( $l->unit_cents, $sum ) < 0 ) {
			$naive_negative = true;
		}
	}
	$stack_naive += $naive_negative ? 1 : 0;
	foreach ( $engine->price( $lines, $rules, new Context() )->lines as $line ) {
		if ( $line['total'] < 0 ) {
			++$stack_ours;
			break;
		}
	}
}

// ------------------------------------------------------------ 5. speed
mt_srand( 5 );
$rules20 = array();
for ( $k = 1; $k <= 20; $k++ ) {
	$rules20 = array_merge( $rules20, array_map( static fn ( Rule $r ): Rule => Rule::from_array( array( 'id' => $k ) + $r->to_array() ), RandomCarts::rules( 1 ) ) );
}
$speed = array();
foreach ( array( array( 5, 3 ), array( 20, 10 ), array( 50, 1000 ) ) as [ $nl, $qty ] ) {
	$times = array();
	$ref   = array();
	for ( $rep = 0; $rep < 60; $rep++ ) {
		$lines = array();
		for ( $j = 0; $j < $nl; $j++ ) {
			$p       = mt_rand( 1, 30 );
			$lines[] = new Line( 'k' . $j, $p, array( 1 + $p % 5 ), $qty, RandomCarts::price() );
		}
		$t0 = hrtime( true );
		$engine->price( $lines, $rules20, new Context() );
		$times[] = ( hrtime( true ) - $t0 ) / 1e6;
		if ( $rep < 5 ) {
			$t0 = hrtime( true );
			ReferencePricer::price( $lines, $rules20, new Context() );
			$ref[] = ( hrtime( true ) - $t0 ) / 1e6;
		}
	}
	sort( $times );
	sort( $ref );
	$speed[] = array(
		'cart'    => sprintf( '%d lines × %d', $nl, $qty ),
		'units'   => $nl * $qty,
		'p50'     => round( $times[ (int) ( 0.5 * count( $times ) ) ], 3 ),
		'p95'     => round( $times[ (int) ( 0.95 * count( $times ) ) ], 3 ),
		'ref_p50' => round( $ref[ (int) ( 0.5 * count( $ref ) ) ], 2 ),
	);
}

$big    = end( $speed );
$checks = array(
	array( 'Carts where the engine differs from the independent reference', $mismatches . ' / ' . $cases, '= 0', 0 === $mismatches ),
	array( 'Planted bugs caught by that comparison', $caught . ' / ' . count( $mutants ), '= all', count( $mutants ) === $caught ),
	array( 'Items priced below their floor or below zero', $inv['below floor or negative'], '= 0', 0 === $inv['below floor or negative'] ),
	array( 'Carts whose lines do not add up to the total', $inv['lines do not sum'], '= 0', 0 === $inv['lines do not sum'] ),
	array( 'Carts whose per-rule savings do not add up', $inv['rules do not sum'], '= 0', 0 === $inv['rules do not sum'] ),
	array( 'Cart discounts off by a cent or more', $drift_ours . ' / ' . $drift_cases, '= 0', 0 === $drift_ours ),
	array( 'Negative prices from stacked percentages', $stack_ours . ' / ' . $stack_cases, '= 0', 0 === $stack_ours ),
	array( 'p95 time, 50 lines × 1,000 units, 20 rules', $big['p95'] . ' ms', '≤ 25 ms', $big['p95'] <= 25 ),
);
$passed = count( array_filter( $checks, static fn ( array $c ): bool => $c[3] ) );

$report = array(
	'cases'      => $cases,
	'mismatches' => $mismatches,
	'mutants'    => $mutants,
	'invariants' => $inv,
	'discounted' => $discounted,
	'drift'      => array(
		'cases'       => $drift_cases,
		'naive'       => $drift_naive,
		'naive_worst' => $worst,
		'ours'        => $drift_ours,
	),
	'stacking'   => array(
		'cases' => $stack_cases,
		'naive' => $stack_naive,
		'ours'  => $stack_ours,
	),
	'speed'      => $speed,
	'checks'     => array_map(
		static fn ( array $c ): array => array(
			'check'  => $c[0],
			'value'  => $c[1],
			'target' => $c[2],
			'pass'   => $c[3],
		),
		$checks
	),
);
$dir    = __DIR__ . '/reports';
if ( ! is_dir( $dir ) ) {
	mkdir( $dir, 0777, true );
}
// Timings vary between machines; the JSON keeps them, the gate only bounds them.
file_put_contents( $dir . '/report.json', json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );

$pct = static fn ( int $a, int $b ): string => rtrim( rtrim( number_format( 100 * $a / max( 1, $b ), 1 ), '0' ), '.' ) . '%';
$md  = array(
	'# Pricing Rules for WooCommerce: evaluation',
	'',
	sprintf( '%s seeded random carts (1 to 8 lines, quantities up to 12, prices from free to $2,500) against random rule sets of all four types, with priorities, scopes, roles, schedules, exclusivity and discount caps. %s of the carts received a discount.', number_format( $cases ), $pct( $discounted, $cases ) ),
	'',
	'## Correctness',
	'',
	sprintf( '- The engine and a separately written unit-by-unit reference agree on every line of **%s / %s** carts (totals and per-rule amounts).', number_format( $cases - $mismatches ), number_format( $cases ) ),
	sprintf( '- That comparison catches **%d of %d** deliberately planted bugs:', $caught, count( $mutants ) ),
);
foreach ( $mutants as $name => $ok ) {
	$md[] = sprintf( '  - %s %s', $ok ? 'caught:' : '**missed:**', $name );
}
$md[] = '';
$md[] = '## Common shortcuts, measured';
$md[] = '';
$md[] = '| Shortcut | Carts affected | This engine |';
$md[] = '|---|---:|---:|';
$md[] = sprintf( '| Spreading a cart discount by rounding each line separately | %s (%d of %d; up to %d¢ off) | 0 |', $pct( $drift_naive, $drift_cases ), $drift_naive, $drift_cases, $worst );
$md[] = sprintf( '| Stacking percentages by adding them (prices below zero) | %s (%d of %d) | 0 |', $pct( $stack_naive, $stack_cases ), $stack_naive, $stack_cases );
$md[] = '';
$md[] = '## Speed (20 rules)';
$md[] = '';
$md[] = '| Cart | Units | Engine p50 | Engine p95 | Unit-by-unit reference p50 |';
$md[] = '|---|---:|---:|---:|---:|';
foreach ( $speed as $sp ) {
	$md[] = sprintf( '| %s | %s | %s ms | %s ms | %s ms |', $sp['cart'], number_format( $sp['units'] ), $sp['p50'], $sp['p95'], $sp['ref_p50'] );
}
$md[] = '';
$md[] = sprintf( '## Gate: %d / %d checks passed', $passed, count( $checks ) );
$md[] = '';
$md[] = '| Check | Value | Target | |';
$md[] = '|---|---|---|---|';
foreach ( $checks as $c ) {
	$md[] = sprintf( '| %s | %s | %s | %s |', $c[0], $c[1], $c[2], $c[3] ? 'pass' : 'FAIL' );
}
file_put_contents( $dir . '/report.md', implode( "\n", $md ) . "\n" );

// ------------------------------------------------------------ html
$h    = static fn ( $s ): string => htmlspecialchars( (string) $s, ENT_QUOTES );
$rows = '';
foreach ( $checks as $c ) {
	$rows .= sprintf( '<tr><th scope="row">%s</th><td class="num">%s</td><td>%s</td><td class="%s">%s</td></tr>', $h( $c[0] ), $h( $c[1] ), $h( $c[2] ), $c[3] ? 'pass' : 'fail', $c[3] ? '✓ pass' : '✗ fail' );
}
$mut = '';
foreach ( $mutants as $name => $ok ) {
	$mut .= sprintf( '<li class="%s">%s %s</li>', $ok ? 'pass' : 'fail', $ok ? '✓' : '✗', $h( $name ) );
}
$bar        = static function ( float $share, string $color ): string {
	$w = max( 1.5, 260 * $share );
	return sprintf( '<svg width="262" height="16" role="img" aria-label="%s%%"><rect x="0" y="0" width="%.1f" height="16" rx="3" fill="%s"/></svg>', round( 100 * $share, 1 ), $w, $color );
};
$speed_rows = '';
$max_ms     = max( array_map( static fn ( array $row ): float => (float) $row['ref_p50'], $speed ) );
foreach ( $speed as $sp ) {
	$speed_rows .= sprintf( '<tr><th scope="row">%s</th><td class="num">%s</td><td>%s <span class="v">%s ms</span></td><td>%s <span class="v">%s ms</span></td></tr>', $h( $sp['cart'] ), number_format( $sp['units'] ), $bar( $sp['p50'] / $max_ms, '#3987e5' ), $h( $sp['p50'] ), $bar( $sp['ref_p50'] / $max_ms, '#d95926' ), $h( $sp['ref_p50'] ) );
}
$html  = '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Pricing Rules for WooCommerce: evaluation</title><style>
:root{--bg:#0f1115;--card:#171a21;--ink:#e8eaee;--muted:#9aa3b2;--line:#2a2f3a}
body{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}
main{max-width:1180px;margin:0 auto;padding:32px 24px}h1{font-size:26px;margin:0 0 4px}h2{font-size:18px;margin:28px 0 10px}
.sub{color:var(--muted);margin:0 0 20px;max-width:900px}.tiles{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.tile{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:14px 16px}.tile b{display:block;font-size:28px}.tile span{color:var(--muted);font-size:13px}
table{width:100%;border-collapse:collapse;background:var(--card);border:1px solid var(--line);border-radius:10px;overflow:hidden}
th,td{padding:9px 12px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}thead th{color:var(--muted);font-weight:600;font-size:13px}
.num{text-align:right;font-variant-numeric:tabular-nums}.pass{color:#5cc98a}.fail{color:#d95926}.v{color:var(--muted);font-size:13px;margin-left:6px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}ul.mut{list-style:none;margin:0;padding:12px 16px;background:var(--card);border:1px solid var(--line);border-radius:10px;columns:2}ul.mut li{padding:3px 0}
</style></head><body><main>';
$html .= '<h1>Pricing Rules for WooCommerce: evaluation</h1><p class="sub">' . $h( sprintf( '%s random carts against random rule sets of every type: sales, bulk tiers, buy-X-get-Y and cart thresholds, with priorities, scopes, roles, schedules, exclusivity and discount caps.', number_format( $cases ) ) ) . '</p>';
$html .= '<div class="tiles"><div class="tile"><b>' . $h( $mismatches ) . '</b><span>of ' . $h( number_format( $cases ) ) . ' carts differ from the independent reference</span></div>'
	. '<div class="tile"><b>' . $h( $caught . '/' . count( $mutants ) ) . '</b><span>planted bugs caught by that comparison</span></div>'
	. '<div class="tile"><b>' . $h( $pct( $drift_naive, $drift_cases ) ) . ' → 0</b><span>cart discounts a cent off: rounding each line vs largest remainder</span></div>'
	. '<div class="tile"><b>' . $h( $big['p95'] ) . ' ms</b><span>p95 for 50 lines × 1,000 units and 20 rules</span></div></div>';
$html .= '<div class="grid2"><div><h2>Gate: ' . $h( $passed . ' / ' . count( $checks ) ) . '</h2><table><thead><tr><th>Check</th><th class="num">value</th><th>target</th><th></th></tr></thead><tbody>' . $rows . '</tbody></table></div>';
$html .= '<div><h2>Planted bugs</h2><ul class="mut">' . $mut . '</ul><h2>Common shortcuts</h2><table><thead><tr><th>Shortcut</th><th class="num">carts affected</th><th class="num">this engine</th></tr></thead><tbody>'
	. '<tr><th scope="row">Cart discount spread by rounding each line</th><td class="num">' . $h( $pct( $drift_naive, $drift_cases ) ) . '</td><td class="num">0</td></tr>'
	. '<tr><th scope="row">Percentages stacked by adding (price below zero)</th><td class="num">' . $h( $pct( $stack_naive, $stack_cases ) ) . '</td><td class="num">0</td></tr></tbody></table></div></div>';
$html .= '<h2>Speed with 20 rules: engine (buckets of identical units) vs unit-by-unit reference, median</h2><table><thead><tr><th>Cart</th><th class="num">units</th><th>engine</th><th>reference</th></tr></thead><tbody>' . $speed_rows . '</tbody></table>';
$html .= '</main></body></html>';
file_put_contents( $dir . '/report.html', $html );

echo implode( "\n", $md ), "\n";
if ( $gate && count( $checks ) !== $passed ) {
	fwrite( STDERR, "Gate failed\n" );
	exit( 1 );
}
