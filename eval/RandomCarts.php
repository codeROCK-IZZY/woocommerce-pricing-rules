<?php
/**
 * Seeded random carts and rule sets for property testing.
 *
 * @package PricingRules
 */

namespace PricingRules\Evaluation;

use PricingRules\Engine\Context;
use PricingRules\Engine\Line;
use PricingRules\Engine\Rule;

/**
 * Generator.
 */
final class RandomCarts {

	/**
	 * A random cart.
	 *
	 * @param int $max_lines Maximum lines.
	 * @param int $max_qty   Maximum quantity per line.
	 * @return list<Line>
	 */
	public static function cart( int $max_lines = 8, int $max_qty = 12 ): array {
		$lines = array();
		$n     = mt_rand( 1, $max_lines );
		for ( $i = 0; $i < $n; $i++ ) {
			$product = mt_rand( 1, 30 );
			$price   = self::price();
			$lines[] = new Line( 'k' . $i, $product, array( 1 + $product % 5, 10 + $product % 3 ), mt_rand( 1, $max_qty ), $price );
		}
		return $lines;
	}

	/**
	 * A plausible price in cents, including awkward ones (odd cents, zero, large).
	 */
	public static function price(): int {
		$r = mt_rand( 0, 99 );
		if ( $r < 3 ) {
			return 0;
		}
		if ( $r < 10 ) {
			return mt_rand( 1, 99 );
		}
		if ( $r < 50 ) {
			return 100 * mt_rand( 1, 99 ) - ( mt_rand( 0, 1 ) ? 1 : 0 ); // $x.00 and $x.99, as shops price.
		}
		if ( $r < 90 ) {
			return mt_rand( 100, 9999 );
		}
		return mt_rand( 10000, 250000 );
	}

	/**
	 * A random rule set.
	 *
	 * @param int $max Maximum rules.
	 * @return list<Rule>
	 */
	public static function rules( int $max = 5 ): array {
		$rules = array();
		$n     = mt_rand( 1, $max );
		for ( $i = 1; $i <= $n; $i++ ) {
			$type  = Rule::TYPES[ mt_rand( 0, 3 ) ];
			$scope = mt_rand( 0, 2 );
			$a     = array(
				'id'           => $i,
				'name'         => $type . ' ' . $i,
				'type'         => $type,
				'priority'     => mt_rand( 0, 3 ) * 10,
				'bp'           => self::bp(),
				'product_ids'  => 1 === $scope ? array( mt_rand( 1, 30 ), mt_rand( 1, 30 ) ) : array(),
				'category_ids' => 2 === $scope ? array( mt_rand( 1, 5 ) ) : array(),
				'roles'        => 0 === mt_rand( 0, 5 ) ? array( 'wholesale' ) : array(),
				'exclusive'    => 0 === mt_rand( 0, 3 ),
				'starts'       => 0 === mt_rand( 0, 6 ) ? 2000 : 0,
				'ends'         => 0 === mt_rand( 0, 6 ) ? ( mt_rand( 0, 1 ) ? 3000 : 3001 ) : 0,
				'count_by'     => mt_rand( 0, 1 ) ? 'rule' : 'line',
				'buy'          => mt_rand( 1, 3 ),
				'get'          => mt_rand( 1, 2 ),
				'get_bp'       => mt_rand( 0, 1 ) ? 10000 : self::bp(),
				'limit'        => mt_rand( 0, 2 ) ? 0 : mt_rand( 1, 3 ),
				'threshold'    => mt_rand( 0, 1 ) ? max( 0, 100 * mt_rand( 0, 200 ) - ( mt_rand( 0, 3 ) ? 0 : 1 ) ) : mt_rand( 0, 20000 ),
				'fixed'        => mt_rand( 0, 1 ) ? 0 : mt_rand( 1, 5000 ),
				'tiers'        => array(
					array(
						'min' => mt_rand( 2, 4 ),
						'bp'  => self::bp(),
					),
					array(
						'min' => mt_rand( 5, 10 ),
						'bp'  => self::bp(),
					),
				),
			);
			if ( 'cart' === $type && $a['fixed'] > 0 ) {
				$a['bp'] = 0;
			}
			$rules[] = Rule::from_array( $a );
		}
		return $rules;
	}

	/**
	 * A random context.
	 */
	public static function context(): Context {
		return new Context( mt_rand( 0, 3 ) ? array( 'customer' ) : array( 'wholesale' ), mt_rand( 0, 1 ) ? 1000 : 3000, mt_rand( 0, 2 ) ? 10000 : mt_rand( 3000, 9000 ) );
	}

	/**
	 * Basis points, with round numbers more likely.
	 */
	private static function bp(): int {
		return mt_rand( 0, 1 ) ? 500 * mt_rand( 1, 10 ) : mt_rand( 1, 9999 );
	}
}
