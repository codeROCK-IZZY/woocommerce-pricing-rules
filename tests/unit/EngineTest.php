<?php
/**
 * Hand-worked pricing scenarios. Each expectation was computed by hand, not by the engine.
 *
 * @package PricingRules
 */

namespace PricingRules\Tests;

use PricingRules\Engine\Context;
use PricingRules\Engine\Line;
use PricingRules\Engine\PricingEngine;
use PricingRules\Engine\Rule;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase {

	private function rule( array $a ): Rule {
		return Rule::from_array( $a + array( 'id' => 1 ) );
	}

	private function price( array $lines, array $rules, ?Context $ctx = null ): \PricingRules\Engine\Result {
		return ( new PricingEngine() )->price( $lines, $rules, $ctx ?? new Context() );
	}

	public function test_sale_rounds_each_unit_half_up(): void {
		$r = $this->price(
			array( new Line( 'a', 1, array(), 3, 1999 ) ),
			array(
				$this->rule(
					array(
						'type' => 'sale',
						'bp'   => 1000,
					)
				),
			)
		);
		// 10% of 19.99 = 1.999 -> 2.00 per unit.
		$this->assertSame( 3 * 1799, $r->lines['a']['total'] );
		$this->assertSame( 600, $r->discount );
	}

	public function test_bulk_tiers_per_line_and_mix_and_match(): void {
		$tiers = array(
			array(
				'min' => 5,
				'bp'  => 500,
			),
			array(
				'min' => 10,
				'bp'  => 1000,
			),
		);
		$r     = $this->price(
			array( new Line( 'a', 1, array(), 10, 1000 ) ),
			array(
				$this->rule(
					array(
						'type'  => 'bulk',
						'tiers' => $tiers,
					)
				),
			)
		);
		$this->assertSame( 9000, $r->lines['a']['total'] );

		$lines = array( new Line( 'a', 1, array(), 3, 1000 ), new Line( 'b', 2, array(), 3, 1000 ) );
		$this->assertSame(
			0,
			$this->price(
				$lines,
				array(
					$this->rule(
						array(
							'type'  => 'bulk',
							'tiers' => $tiers,
						)
					),
				)
			)->discount,
			'per line: 3 and 3 reach no tier'
		);
		$this->assertSame(
			300,
			$this->price(
				$lines,
				array(
					$this->rule(
						array(
							'type'     => 'bulk',
							'tiers'    => $tiers,
							'count_by' => 'rule',
						)
					),
				)
			)->discount,
			'together: 6 units reach 5%'
		);
	}

	public function test_bogo_gives_the_cheaper_item_and_ignores_an_incomplete_group(): void {
		$lines = array( new Line( 'a', 1, array(), 1, 3000 ), new Line( 'b', 2, array(), 1, 1000 ), new Line( 'c', 3, array(), 1, 2000 ) );
		$r     = $this->price(
			$lines,
			array(
				$this->rule(
					array(
						'type' => 'bogo',
						'buy'  => 1,
						'get'  => 1,
					)
				),
			)
		);
		// Sorted 30, 20, 10: the group (30, 20) makes the 20 free; 10 is alone, no discount.
		$this->assertSame( 0, $r->lines['c']['total'] );
		$this->assertSame( 1000, $r->lines['b']['total'] );
		$this->assertSame( 2000, $r->discount );
	}

	public function test_bogo_limit(): void {
		$r = $this->price(
			array( new Line( 'a', 1, array(), 6, 500 ) ),
			array(
				$this->rule(
					array(
						'type'  => 'bogo',
						'buy'   => 1,
						'get'   => 1,
						'limit' => 1,
					)
				),
			)
		);
		$this->assertSame( 500, $r->discount );
	}

	public function test_bogo_half_price_on_the_third(): void {
		$r = $this->price(
			array( new Line( 'a', 1, array(), 7, 999 ) ),
			array(
				$this->rule(
					array(
						'type'   => 'bogo',
						'buy'    => 2,
						'get'    => 1,
						'get_bp' => 5000,
					)
				),
			)
		);
		// Two complete groups of three: two units at 50% of 9.99 = 5.00 (rounded half up from 4.995).
		$this->assertSame( 1000, $r->discount );
	}

	public function test_cart_discount_is_spread_exactly_with_largest_remainder(): void {
		$lines = array( new Line( 'a', 1, array(), 1, 3000 ), new Line( 'b', 2, array(), 1, 2500 ) );
		$r     = $this->price(
			$lines,
			array(
				$this->rule(
					array(
						'type'      => 'cart',
						'fixed'     => 1000,
						'threshold' => 5000,
					)
				),
			)
		);
		// 1000 × 3000/5500 = 545.45 (remainder 2500), 1000 × 2500/5500 = 454.55 (remainder 3000): the leftover cent goes to b.
		$this->assertSame( 3000 - 545, $r->lines['a']['total'] );
		$this->assertSame( 2500 - 455, $r->lines['b']['total'] );
		$this->assertSame( 1000, $r->discount );
	}

	public function test_cart_threshold_is_inclusive(): void {
		$rule = $this->rule(
			array(
				'type'      => 'cart',
				'bp'        => 1000,
				'threshold' => 5000,
			)
		);
		$this->assertSame( 500, $this->price( array( new Line( 'a', 1, array(), 1, 5000 ) ), array( $rule ) )->discount );
		$this->assertSame( 0, $this->price( array( new Line( 'a', 1, array(), 1, 4999 ) ), array( $rule ) )->discount );
	}

	public function test_stacked_sales_compound_and_respect_priority(): void {
		$rules = array(
			$this->rule(
				array(
					'id'       => 2,
					'type'     => 'sale',
					'bp'       => 5000,
					'priority' => 20,
				)
			),
			$this->rule(
				array(
					'id'       => 1,
					'type'     => 'sale',
					'bp'       => 2000,
					'priority' => 10,
				)
			),
		);
		$r     = $this->price( array( new Line( 'a', 1, array(), 1, 10000 ) ), $rules );
		// 100 -20% = 80, then -50% = 40.
		$this->assertSame( 4000, $r->lines['a']['total'] );
		$this->assertSame(
			array(
				1 => 2000,
				2 => 4000,
			),
			$r->lines['a']['applied']
		);
	}

	public function test_exclusive_rule_blocks_later_rules_on_the_units_it_touched(): void {
		$rules = array(
			$this->rule(
				array(
					'id'          => 1,
					'type'        => 'sale',
					'bp'          => 1000,
					'exclusive'   => true,
					'product_ids' => array( 1 ),
				)
			),
			$this->rule(
				array(
					'id'       => 2,
					'type'     => 'sale',
					'bp'       => 1000,
					'priority' => 20,
				)
			),
		);
		$r     = $this->price( array( new Line( 'a', 1, array(), 1, 1000 ), new Line( 'b', 2, array(), 1, 1000 ) ), $rules );
		$this->assertSame( array( 1 => 100 ), $r->lines['a']['applied'] );
		$this->assertSame( array( 2 => 100 ), $r->lines['b']['applied'] );
	}

	public function test_cap_keeps_items_above_the_floor(): void {
		$rules = array(
			$this->rule(
				array(
					'id'   => 1,
					'type' => 'sale',
					'bp'   => 4000,
				)
			),
			$this->rule(
				array(
					'id'   => 2,
					'type' => 'sale',
					'bp'   => 4000,
				)
			),
		);
		// 40% then 40% would leave 36%; a 50% cap stops at 50.
		$r = $this->price( array( new Line( 'a', 1, array(), 1, 10000 ) ), $rules, new Context( array( 'guest' ), 0, 5000 ) );
		$this->assertSame( 5000, $r->lines['a']['total'] );
	}

	public function test_roles_dates_and_scope(): void {
		$wholesale = $this->rule(
			array(
				'type'  => 'sale',
				'bp'    => 2500,
				'roles' => array( 'wholesale' ),
			)
		);
		$line      = array( new Line( 'a', 7, array( 3 ), 1, 1000 ) );
		$this->assertSame( 0, $this->price( $line, array( $wholesale ), new Context( array( 'customer' ) ) )->discount );
		$this->assertSame( 250, $this->price( $line, array( $wholesale ), new Context( array( 'wholesale' ) ) )->discount );

		$window = $this->rule(
			array(
				'type'   => 'sale',
				'bp'     => 1000,
				'starts' => 100,
				'ends'   => 200,
			)
		);
		$this->assertSame( 0, $this->price( $line, array( $window ), new Context( array( 'guest' ), 99 ) )->discount );
		$this->assertSame( 100, $this->price( $line, array( $window ), new Context( array( 'guest' ), 100 ) )->discount );
		$this->assertSame( 0, $this->price( $line, array( $window ), new Context( array( 'guest' ), 200 ) )->discount );

		$this->assertSame(
			100,
			$this->price(
				$line,
				array(
					$this->rule(
						array(
							'type'         => 'sale',
							'bp'           => 1000,
							'category_ids' => array( 3 ),
						)
					),
				)
			)->discount
		);
		$this->assertSame(
			0,
			$this->price(
				$line,
				array(
					$this->rule(
						array(
							'type'        => 'sale',
							'bp'          => 1000,
							'product_ids' => array( 8 ),
						)
					),
				)
			)->discount
		);
	}

	public function test_large_quantities_cost_nothing_extra(): void {
		$t0 = hrtime( true );
		$r  = $this->price(
			array( new Line( 'a', 1, array(), 1000000, 199 ) ),
			array(
				$this->rule(
					array(
						'type' => 'bogo',
						'buy'  => 2,
						'get'  => 1,
					)
				),
			)
		);
		$this->assertLessThan( 50, ( hrtime( true ) - $t0 ) / 1e6 );
		$this->assertSame( 333333 * 199, $r->discount );
	}

	public function test_free_and_empty_lines(): void {
		$r = $this->price(
			array( new Line( 'a', 1, array(), 2, 0 ), new Line( 'b', 1, array(), 0, 500 ) ),
			array(
				$this->rule(
					array(
						'type' => 'cart',
						'bp'   => 1000,
					)
				),
			)
		);
		$this->assertSame( 0, $r->discount );
		$this->assertArrayNotHasKey( 'b', $r->lines );
	}
}
