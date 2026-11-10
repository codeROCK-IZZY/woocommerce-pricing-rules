<?php
/**
 * Integration checks inside WordPress + WooCommerce (run with: wp eval-file checks.php).
 * The central check: on random carts, WooCommerce's own cart totals equal the engine's, to the cent.
 *
 * @package PricingRules
 */

use PricingRules\Engine\Line;
use PricingRules\Engine\PricingEngine;
use PricingRules\Woo\CartPricer;
use PricingRules\Woo\RuleRepository;

$failures = 0;
$check    = static function ( string $name, bool $ok, string $detail = '' ) use ( &$failures ): void {
	echo ( $ok ? '  ok    ' : '  FAIL  ' ) . $name . ( $ok || '' === $detail ? '' : ' (' . $detail . ')' ) . "\n";
	$failures += $ok ? 0 : 1;
};
$ids      = (array) get_option( 'wcpr_test_products' );

$check( 'rule post type registered', post_type_exists( RuleRepository::POST_TYPE ) );
$check( 'seven rules loaded', 7 === count( RuleRepository::all() ) );
try {
	RuleRepository::save(
		array(
			'type' => 'sale',
			'bp'   => 20000,
		)
	);
	$check( 'an invalid rule is refused', false );
} catch ( InvalidArgumentException $e ) {
	$check( 'an invalid rule is refused', str_contains( $e->getMessage(), 'between' ) );
}

wc_load_cart();
$cart = WC()->cart;

/**
 * Fill the cart and return the engine's view of it.
 *
 * @param array<int, int> $items Product ID => quantity.
 * @return list<Line>
 */
$fill  = static function ( array $items ) use ( $cart ): array {
	$cart->empty_cart();
	CartPricer::reset();
	foreach ( $items as $pid => $qty ) {
		$cart->add_to_cart( $pid, $qty );
	}
	$lines = array();
	foreach ( $cart->get_cart() as $key => $item ) {
		$p       = wc_get_product( $item['product_id'] );
		$lines[] = new Line( (string) $key, $p->get_id(), $p->get_category_ids(), (int) $item['quantity'], (int) round( (float) $p->get_regular_price() * 100 ) );
	}
	return $lines;
};
$cents = static fn ( $v ): int => (int) round( (float) $v * 100 );

// ---------------------------------------------------------------- random carts: WooCommerce totals = engine totals
mt_srand( 7 );
$cases   = 150;
$bad     = 0;
$applied = 0;
$detail  = '';
foreach ( range( 1, $cases ) as $n ) {
	$items = array();
	foreach ( (array) array_rand( array_flip( array_values( $ids ) ), mt_rand( 1, 6 ) ) as $pid ) {
		$items[ (int) $pid ] = mt_rand( 1, 0 === mt_rand( 0, 9 ) ? 25 : 5 );
	}
	$lines = $fill( $items );
	$cart->calculate_totals();
	$want = ( new PricingEngine() )->price( $lines, RuleRepository::all(), CartPricer::context() );
	$ok   = $cents( $cart->get_subtotal() ) === $want->total();
	foreach ( $cart->get_cart() as $key => $item ) {
		$ok = $ok && $cents( $item['line_subtotal'] ) === $want->lines[ $key ]['total'];
	}
	if ( ! $ok ) {
		++$bad;
		$detail = sprintf( 'cart %d: WooCommerce %s, engine %d', $n, $cart->get_subtotal(), $want->total() );
	}
	$applied += $want->discount > 0 ? 1 : 0;
}
$check( sprintf( 'WooCommerce cart totals equal the engine to the cent on %d random carts (%d discounted)', $cases, $applied ), 0 === $bad, $bad . ' mismatched; ' . $detail );
update_option(
	'wcpr_test_parity',
	array(
		'cases'      => $cases,
		'mismatched' => $bad,
		'discounted' => $applied,
	)
);

// ---------------------------------------------------------------- specific behaviour
$fill(
	array(
		$ids['House blend 250 g'] => 3,
		$ids['Stoneware mug']     => 2,
	)
);
$cart->calculate_totals();
$first = $cart->get_subtotal();
$cart->calculate_totals();
$cart->calculate_totals();
$check( 'recalculating does not compound the discount', $first === $cart->get_subtotal() );
// Coffee: 5% of 12.50 is 0.625, rounded to 0.63, so 11.87 each. Mugs: half of 19.99 is 9.995, rounded to 10.00 off, so 9.99.
$check( 'bulk and BOGO priced as advertised', abs( (float) $cart->get_subtotal() - ( 3 * 11.87 + 19.99 + 9.99 ) ) < 0.001, (string) $cart->get_subtotal() );

$fill( array( $ids['Paper filters (100)'] => 12 ) );
$cart->calculate_totals();
// Buy 2 get 1 free, at most 3 times: 12 filters → 3 free (exclusive, so the gear sale skips them);
// the 9 paid filters still get the 15% brewing-gear sale: 4.95 − 0.74 = 4.21.
$check( 'BOGO limit and exclusivity respected', abs( (float) $cart->get_subtotal() - 9 * 4.21 ) < 0.001, (string) $cart->get_subtotal() );

$fill( array( $ids['Burr grinder'] => 1 ) );
$cart->calculate_totals();
// 249.99 −15% = 212.49 (37.4985 → 37.50), then $10 off over $100 = 202.49.
$check( 'sale then cart threshold', abs( (float) $cart->get_subtotal() - 202.49 ) < 0.001, (string) $cart->get_subtotal() );
$check( 'the ended summer sale does not apply', ! isset( CartPricer::$last->by_rule[ (int) get_page_by_title( 'Summer sale (ended)', OBJECT, RuleRepository::POST_TYPE )->ID ] ) ); // phpcs:ignore WordPress.WP.DeprecatedFunctions -- Test.

$user = wp_insert_user(
	array(
		'user_login' => 'trade' . wp_rand(),
		'user_pass'  => wp_generate_password(),
		'role'       => 'wholesale',
	)
);
wp_set_current_user( (int) $user );
$fill( array( $ids['Travel mug'] => 1 ) );
$cart->calculate_totals();
$check( 'role pricing for a wholesale customer', abs( (float) $cart->get_subtotal() - 19.20 ) < 0.001, (string) $cart->get_subtotal() );
wp_set_current_user( 0 );

// ---------------------------------------------------------------- orders record what was applied
$fill(
	array(
		$ids['House blend 250 g'] => 6,
		$ids['Pour-over dripper'] => 1,
	)
);
$cart->calculate_totals();
$order = wc_create_order();
WC()->checkout()->create_order_line_items( $order, $cart );
do_action( 'woocommerce_checkout_create_order', $order, array() );
$order->calculate_totals( false );
$order->save();
$order  = wc_get_order( $order->get_id() );
$labels = array();
foreach ( $order->get_items() as $item ) {
	$labels[] = (string) $item->get_meta( 'Discounts' );
}
$check( 'order lines record which rules applied', str_contains( implode( ' ', $labels ), 'Coffee: buy more, save more' ) && str_contains( implode( ' ', $labels ), 'Brewing gear sale' ) );
$check( 'order total equals the discounted cart', abs( (float) $order->get_total() - (float) $cart->get_subtotal() ) < 0.001, $order->get_total() . ' vs ' . $cart->get_subtotal() );
$check( 'total savings stored on the order', (float) $order->get_meta( '_wcpr_savings' ) > 0 );

// ---------------------------------------------------------------- storefront output
global $product;
$product = wc_get_product( $ids['House blend 250 g'] ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Simulating the product page.
ob_start();
do_action( 'woocommerce_after_add_to_cart_form' );
$table = (string) ob_get_clean();
$check( 'product page shows the quantity table, priced exactly as the cart will charge ($11.87 at 5%)', str_contains( $table, 'wcpr-tiers' ) && str_contains( $table, '12 or more' ) && str_contains( $table, '11.87' ) && ! str_contains( $table, '11.88' ) );

$fill( array( $ids['Stoneware mug'] => 2 ) );
$cart->calculate_totals();
$key = (string) array_key_first( $cart->get_cart() );
$check( 'cart shows the original price struck through', str_contains( apply_filters( 'woocommerce_cart_item_price', '', $cart->get_cart()[ $key ], $key ), '<del>' ) );
ob_start();
do_action( 'woocommerce_cart_totals_before_order_total' );
$check( 'cart shows "You save"', str_contains( (string) ob_get_clean(), 'You save' ) );
$cart->empty_cart();

echo $failures ? "\n{$failures} integration check(s) failed\n" : "\nall integration checks passed\n";
if ( $failures ) {
	WP_CLI::halt( 1 );
}
