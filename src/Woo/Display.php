<?php
/**
 * What the shopper sees: struck-through prices, savings, tier tables, and the record on the order.
 *
 * @package PricingRules
 */

namespace PricingRules\Woo;

use PricingRules\Admin\Settings;
use PricingRules\Engine\Line;
use PricingRules\Engine\PricingEngine;
use PricingRules\Engine\Rule;

/**
 * Storefront and order output.
 */
final class Display {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_cart_item_price', array( self::class, 'cart_item_price' ), 20, 3 );
		add_action( 'woocommerce_cart_totals_before_order_total', array( self::class, 'savings_row' ) );
		add_action( 'woocommerce_review_order_before_order_total', array( self::class, 'savings_row' ) );
		add_action( 'woocommerce_after_add_to_cart_form', array( self::class, 'tier_table' ) );
		add_action( 'woocommerce_checkout_create_order_line_item', array( self::class, 'order_item_meta' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order', array( self::class, 'order_meta' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'styles' ) );
	}

	/**
	 * Front-end styles (small; only where WooCommerce runs).
	 */
	public static function styles(): void {
		if ( function_exists( 'is_woocommerce' ) && ( is_woocommerce() || is_cart() || is_checkout() ) ) {
			wp_enqueue_style( 'wcpr-front', WCPR_URL . 'assets/front.css', array(), WCPR_VERSION );
		}
	}

	/**
	 * Show the original unit price struck through.
	 *
	 * @param string               $html Price HTML.
	 * @param array<string, mixed> $item Cart item.
	 * @param string               $key  Cart item key.
	 */
	public static function cart_item_price( $html, $item, $key ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundInExtendedClassBeforeLastUsed -- Filter signature.
		$line = CartPricer::$last->lines[ (string) $key ] ?? null;
		$base = CartPricer::base( (string) $key );
		if ( null === $line || null === $base || $line['discount'] <= 0 ) {
			return (string) $html;
		}
		$factor = 10 ** wc_get_price_decimals();
		return '<del>' . wc_price( $base ) . '</del> <ins>' . wc_price( $line['total'] / $line['qty'] / $factor ) . '</ins>';
	}

	/**
	 * "You save" row in the cart and checkout totals.
	 */
	public static function savings_row(): void {
		$result = CartPricer::$last;
		if ( null === $result || $result->discount <= 0 || ! Settings::show_savings() ) {
			return;
		}
		$factor = 10 ** wc_get_price_decimals();
		printf(
			'<tr class="wcpr-savings"><th>%s</th><td data-title="%s">%s</td></tr>',
			esc_html__( 'You save', 'pricing-rules-for-woocommerce' ),
			esc_attr__( 'You save', 'pricing-rules-for-woocommerce' ),
			wp_kses_post( wc_price( $result->discount / $factor ) )
		);
	}

	/**
	 * Quantity-discount table on the product page, for bulk rules that apply to this product and shopper.
	 */
	public static function tier_table(): void {
		global $product;
		if ( ! $product instanceof \WC_Product || ! Settings::show_tiers() ) {
			return;
		}
		$ctx  = CartPricer::context();
		$line = new Line( 'p', $product->get_id(), array_values( array_map( 'intval', $product->get_category_ids() ) ), 1, 0 );
		$rows = array();
		foreach ( RuleRepository::all() as $rule ) {
			if ( 'bulk' === $rule->type && $rule->active( $ctx ) && $rule->matches( $line ) ) {
				foreach ( $rule->tiers as $tier ) {
					$rows[] = array( $tier['min'], $tier['bp'], $rule );
				}
			}
		}
		if ( ! $rows ) {
			return;
		}
		// Same arithmetic as the cart (discount rounded half up per unit), so the table never promises a different cent.
		$factor = 10 ** wc_get_price_decimals();
		$cents  = (int) round( (float) $product->get_price() * $factor );
		echo '<table class="wcpr-tiers"><caption>' . esc_html__( 'Buy more, save more', 'pricing-rules-for-woocommerce' ) . '</caption><thead><tr><th scope="col">' . esc_html__( 'Quantity', 'pricing-rules-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'Discount', 'pricing-rules-for-woocommerce' ) . '</th><th scope="col">' . esc_html__( 'Price each', 'pricing-rules-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as [ $min, $bp, $rule ] ) {
			/* translators: %d: minimum quantity */
			$qty = sprintf( __( '%d or more', 'pricing-rules-for-woocommerce' ), $min );
			$qty = 'rule' === $rule->count_by ? $qty . ' *' : $qty;
			printf( '<tr><td>%s</td><td>%s%%</td><td>%s</td></tr>', esc_html( $qty ), esc_html( wc_format_localized_decimal( (string) ( $bp / 100 ) ) ), wp_kses_post( wc_price( ( $cents - PricingEngine::pct( $cents, $bp ) ) / $factor ) ) );
		}
		echo '</tbody></table>';
		if ( array_filter( $rows, static fn ( array $r ): bool => 'rule' === $r[2]->count_by ) ) {
			echo '<p class="wcpr-note">' . esc_html__( '* Counts all qualifying products in your cart together.', 'pricing-rules-for-woocommerce' ) . '</p>';
		}
	}

	/**
	 * Record on each order line which rules discounted it, visible to the shop and the customer.
	 *
	 * @param \WC_Order_Item_Product $item   Order item.
	 * @param string                 $key    Cart item key.
	 * @param array<string, mixed>   $values Cart item.
	 */
	public static function order_item_meta( $item, $key, $values ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Action signature.
		$line = CartPricer::$last->lines[ (string) $key ] ?? null;
		if ( null === $line || $line['discount'] <= 0 ) {
			return;
		}
		$factor = 10 ** wc_get_price_decimals();
		$names  = array();
		foreach ( $line['applied'] as $rule_id => $cents ) {
			$names[] = sprintf( '%s (−%s)', get_the_title( $rule_id ), wp_strip_all_tags( wc_price( $cents / $factor ) ) );
		}
		$item->add_meta_data( __( 'Discounts', 'pricing-rules-for-woocommerce' ), implode( ', ', $names ), true );
		$item->add_meta_data( '_wcpr_applied', (string) wp_json_encode( $line['applied'] ), true );
		$item->add_meta_data( '_wcpr_base_total', (string) ( $line['base'] / $factor ), true );
	}

	/**
	 * Total saving on the order, for reports.
	 *
	 * @param \WC_Order $order Order.
	 */
	public static function order_meta( $order ): void {
		if ( null !== CartPricer::$last && CartPricer::$last->discount > 0 ) {
			$order->update_meta_data( '_wcpr_savings', (string) ( CartPricer::$last->discount / ( 10 ** wc_get_price_decimals() ) ) );
			$order->update_meta_data( '_wcpr_by_rule', (string) wp_json_encode( CartPricer::$last->by_rule ) );
		}
	}

	/**
	 * Short human description of a rule (admin list).
	 *
	 * @param Rule $r Rule.
	 */
	public static function describe( Rule $r ): string {
		$pct   = static fn ( int $bp ): string => rtrim( rtrim( number_format( $bp / 100, 2 ), '0' ), '.' ) . '%';
		$money = static fn ( int $cents ): string => wp_strip_all_tags( wc_price( $cents / ( 10 ** wc_get_price_decimals() ) ) );
		switch ( $r->type ) {
			case 'sale':
				return sprintf( '%s off', $pct( $r->bp ) );
			case 'bulk':
				return implode( ', ', array_map( static fn ( array $t ): string => $t['min'] . '+ → ' . $pct( $t['bp'] ), $r->tiers ) ) . ( 'rule' === $r->count_by ? ' (mix and match)' : '' );
			case 'bogo':
				return sprintf( 'Buy %d, get %d %s', $r->buy, $r->get, 10000 === $r->get_bp ? 'free' : $pct( $r->get_bp ) . ' off' ) . ( $r->limit ? sprintf( ' (max %d×)', $r->limit ) : '' );
			case 'cart':
				return sprintf( '%s off orders of %s+', $r->bp > 0 ? $pct( $r->bp ) : $money( $r->fixed ), $money( $r->threshold ) );
		}
		return '';
	}
}
