<?php
/**
 * Compares the engine in a given src/ directory with the reference on N seeded random carts.
 * Prints "<mismatches> / <cases>". Used by the evaluation and by the mutation tests.
 *
 * Usage: php eval/check.php <src dir> <cases>
 *
 * @package PricingRules
 */

namespace PricingRules\Evaluation;

use PricingRules\Engine\PricingEngine;

require rtrim( $argv[1] ?? dirname( __DIR__ ) . '/src', '/' ) . '/autoload.php';
require_once __DIR__ . '/ReferencePricer.php';
require_once __DIR__ . '/RandomCarts.php';

mt_srand( 20261110 );
$engine = new PricingEngine();
$cases  = (int) ( $argv[2] ?? 2000 );
$bad    = 0;
for ( $i = 0; $i < $cases; $i++ ) {
	$lines = RandomCarts::cart();
	$rules = RandomCarts::rules();
	$ctx   = RandomCarts::context();
	if ( 0 === $i % 10 ) {
		// Boundary case: a cart rule whose threshold equals the matching subtotal exactly.
		$probe = \PricingRules\Engine\Rule::from_array(
			array(
				'id'           => 99,
				'type'         => 'cart',
				'bp'           => 1000,
				'category_ids' => array( 1 + $i % 5 ),
			)
		);
		$sub   = 0;
		foreach ( $lines as $l ) {
			$sub += $probe->matches( $l ) ? $l->unit_cents * $l->qty : 0;
		}
		$rules = array( \PricingRules\Engine\Rule::from_array( array( 'threshold' => $sub ) + $probe->to_array() ) );
	}
	$fast = $engine->price( $lines, $rules, $ctx );
	foreach ( ReferencePricer::price( $lines, $rules, $ctx ) as $key => $ref ) {
		if ( $fast->lines[ $key ]['total'] !== $ref['total'] || $fast->lines[ $key ]['applied'] !== $ref['applied'] ) {
			++$bad;
			break;
		}
	}
}
echo $bad, ' / ', $cases, "\n";
