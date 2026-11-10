<?php
/**
 * Rule validation.
 *
 * @package PricingRules
 */

namespace PricingRules\Tests;

use PricingRules\Engine\Rule;
use PHPUnit\Framework\TestCase;

final class RuleTest extends TestCase {

	public function test_invalid_rules_are_rejected_with_a_reason(): void {
		$bad = array(
			array( 'type' => 'magic' ),
			array(
				'type' => 'sale',
				'bp'   => 10001,
			),
			array(
				'type' => 'sale',
				'bp'   => '12.5',
			),
			array(
				'type' => 'sale',
				'bp'   => -1,
			),
			array(
				'type'  => 'bulk',
				'tiers' => array(),
			),
			array(
				'type' => 'bogo',
				'buy'  => 0,
			),
			array(
				'type'   => 'sale',
				'starts' => 200,
				'ends'   => 100,
			),
		);
		foreach ( $bad as $a ) {
			try {
				Rule::from_array( $a );
				$this->fail( 'accepted ' . json_encode( $a ) );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertNotSame( '', $e->getMessage() );
			}
		}
	}

	public function test_normalisation(): void {
		$r = Rule::from_array(
			array(
				'type'        => 'bulk',
				'tiers'       => array(
					array(
						'min' => '10',
						'bp'  => '1000',
					),
					array(
						'min' => 5,
						'bp'  => 500,
					),
				),
				'product_ids' => array( '3', 3, 0, -2, 'x' ),
				'roles'       => array( ' Wholesale ', 'wholesale', '' ),
				'name'        => str_repeat( 'n', 300 ),
			)
		);
		$this->assertSame( array( 5, 10 ), array_column( $r->tiers, 'min' ), 'tiers sorted' );
		$this->assertSame( array( 3 ), $r->product_ids );
		$this->assertSame( array( 'wholesale' ), $r->roles );
		$this->assertSame( 120, mb_strlen( $r->name ) );
		$this->assertSame( 0, $r->tier_bp( 4 ) );
		$this->assertSame( 500, $r->tier_bp( 9 ) );
		$this->assertSame( 1000, $r->tier_bp( 10 ) );
	}

	public function test_round_trip(): void {
		$r = Rule::from_array(
			array(
				'id'        => 4,
				'type'      => 'cart',
				'fixed'     => 500,
				'threshold' => 3000,
				'exclusive' => true,
			)
		);
		$this->assertEquals( $r, Rule::from_array( $r->to_array() ) );
	}
}
