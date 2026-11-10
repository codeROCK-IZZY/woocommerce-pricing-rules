<?php
/**
 * The engine against the unit-by-unit reference on seeded random carts (the evaluation runs 20,000).
 *
 * @package PricingRules
 */

namespace PricingRules\Tests;

use PricingRules\Engine\PricingEngine;
use PricingRules\Evaluation\RandomCarts;
use PricingRules\Evaluation\ReferencePricer;
use PHPUnit\Framework\TestCase;

final class PropertyTest extends TestCase {

	public function test_engine_matches_reference_on_random_carts(): void {
		mt_srand( 4242 );
		$engine = new PricingEngine();
		for ( $i = 0; $i < 1000; $i++ ) {
			$lines = RandomCarts::cart();
			$rules = RandomCarts::rules();
			$ctx   = RandomCarts::context();
			$fast  = $engine->price( $lines, $rules, $ctx );
			foreach ( ReferencePricer::price( $lines, $rules, $ctx ) as $key => $ref ) {
				$this->assertSame( $ref['total'], $fast->lines[ $key ]['total'], "case {$i}, line {$key}" );
				$this->assertSame( $ref['applied'], $fast->lines[ $key ]['applied'], "case {$i}, line {$key}" );
			}
		}
	}
}
