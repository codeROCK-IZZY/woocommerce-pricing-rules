<?php
/**
 * Applies the engine to the WooCommerce cart.
 *
 * WooCommerce recalculates totals several times per request, and the product objects in the cart are rebuilt from
 * the session on each request. So the original price is captured once per request (after any plugin that runs
 * earlier, such as a currency switcher) and every recalculation starts again from it. Pricing from an already
 * discounted price would compound the discount on each recalculation.
 *
 * @package PricingRules
 */

namespace PricingRules\Woo;

use PricingRules\Admin\Settings;
use PricingRules\Engine\Context;
use PricingRules\Engine\Line;
use PricingRules\Engine\PricingEngine;
use PricingRules\Engine\Result;

/**
 * The cart hook.
 */
final class CartPricer {

	/**
	 * The last result, for display.
	 *
	 * @var Result|null
	 */
	public static ?Result $last = null;

	/**
	 * Original unit prices captured this request, by cart item key.
	 *
	 * @var array<string, float>
	 */
	private static array $base = array();

	/**
	 * Re-entrancy guard.
	 *
	 * @var bool
	 */
	private static bool $running = false;

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'woocommerce_before_calculate_totals', array( self::class, 'apply' ), 20 );
	}

	/**
	 * Price the cart.
	 *
	 * @param \WC_Cart $cart Cart.
	 */
	public static function apply( $cart ): void {
		if ( self::$running || ! $cart instanceof \WC_Cart ) {
			return;
		}
		self::$running = true;
		try {
			$factor = 10 ** wc_get_price_decimals();
			$lines  = array();
			$items  = array();
			foreach ( $cart->get_cart() as $key => $item ) {
				$product = $item['data'] ?? null;
				if ( ! $product instanceof \WC_Product ) {
					continue;
				}
				$key = (string) $key;
				if ( ! isset( self::$base[ $key ] ) ) {
					self::$base[ $key ] = (float) $product->get_price( 'edit' );
				}
				$parent        = $product->get_parent_id() ? $product->get_parent_id() : $product->get_id();
				$categories    = $product->get_parent_id() ? wc_get_product_cat_ids( $parent ) : $product->get_category_ids();
				$lines[]       = new Line( $key, (int) $parent, array_values( array_map( 'intval', $categories ) ), (int) $item['quantity'], (int) round( self::$base[ $key ] * $factor ) );
				$items[ $key ] = $product;
			}
			$result = ( new PricingEngine() )->price( $lines, RuleRepository::all(), self::context() );
			foreach ( $items as $key => $product ) {
				$line = $result->lines[ $key ] ?? null;
				if ( null === $line || $line['discount'] <= 0 ) {
					$product->set_price( (string) self::$base[ $key ] );
					continue;
				}
				// Line total / quantity. WooCommerce multiplies back and rounds the line to the store's decimals,
				// which restores the exact line total in cents; the integration tests check this on random carts.
				$product->set_price( (string) ( $line['total'] / $line['qty'] / $factor ) );
			}
			self::$last = $result;
		} finally {
			self::$running = false;
		}
	}

	/**
	 * The current shopper and time.
	 */
	public static function context(): Context {
		$roles = is_user_logged_in() ? array_values( (array) wp_get_current_user()->roles ) : array( 'guest' );
		return new Context( $roles ? $roles : array( 'guest' ), time(), Settings::cap_bp() );
	}

	/**
	 * Original unit price of a cart item, if captured.
	 *
	 * @param string $key Cart item key.
	 */
	public static function base( string $key ): ?float {
		return self::$base[ $key ] ?? null;
	}

	/**
	 * Forget captured prices (tests that reuse one request).
	 */
	public static function reset(): void {
		self::$base = array();
		self::$last = null;
	}
}
