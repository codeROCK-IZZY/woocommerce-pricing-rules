<?php
/**
 * Creates a small catalogue and a realistic rule set (run with: wp eval-file setup.php). Idempotent.
 * Product names and prices are invented.
 *
 * @package PricingRules
 */

use PricingRules\Woo\RuleRepository;

update_option( 'woocommerce_currency', 'USD' );
update_option( 'woocommerce_calc_taxes', 'no' );
update_option( 'woocommerce_price_num_decimals', 2 );

$cat    = static function ( string $name ): int {
	$t = term_exists( $name, 'product_cat' );
	if ( ! $t ) {
		$t = wp_insert_term( $name, 'product_cat' );
	}
	return (int) ( is_array( $t ) ? $t['term_id'] : $t );
};
$coffee = $cat( 'Coffee' );
$mugs   = $cat( 'Mugs' );
$gear   = $cat( 'Brewing gear' );

$catalogue = array(
	array( 'House blend 250 g', '12.50', $coffee ),
	array( 'Single origin Ethiopia 250 g', '16.95', $coffee ),
	array( 'Decaf Colombia 250 g', '13.33', $coffee ),
	array( 'Stoneware mug', '19.99', $mugs ),
	array( 'Travel mug', '24.00', $mugs ),
	array( 'Paper filters (100)', '4.95', $gear ),
	array( 'Pour-over dripper', '29.99', $gear ),
	array( 'Burr grinder', '249.99', $gear ),
	array( 'Sample sachet', '0.99', $coffee ),
);
$ids       = array();
foreach ( $catalogue as [ $name, $price, $term ] ) {
	$existing = get_page_by_title( $name, OBJECT, 'product' ); // phpcs:ignore WordPress.WP.DeprecatedFunctions -- Test fixture.
	$product  = $existing ? wc_get_product( $existing->ID ) : new WC_Product_Simple();
	$product->set_name( $name );
	$product->set_regular_price( $price );
	$product->set_category_ids( array( $term ) );
	$product->set_status( 'publish' );
	$product->set_short_description( 'Freshly roasted and packed to order.' );
	$ids[ $name ] = $product->save();
}
update_option( 'wcpr_test_products', $ids );

foreach ( get_posts(
	array(
		'post_type'      => RuleRepository::POST_TYPE,
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
) as $old ) {
	wp_delete_post( (int) $old, true );
}
$rules = array(
	array(
		'name'         => 'Coffee: buy more, save more',
		'type'         => 'bulk',
		'priority'     => 10,
		'category_ids' => array( $coffee ),
		'count_by'     => 'rule',
		'tiers'        => array(
			array(
				'min' => 3,
				'bp'  => 500,
			),
			array(
				'min' => 6,
				'bp'  => 1000,
			),
			array(
				'min' => 12,
				'bp'  => 1500,
			),
		),
	),
	array(
		'name'         => 'Mugs: buy one, get one half price',
		'type'         => 'bogo',
		'priority'     => 20,
		'category_ids' => array( $mugs ),
		'buy'          => 1,
		'get'          => 1,
		'get_bp'       => 5000,
	),
	array(
		'name'        => 'Filters: buy 2, get 1 free',
		'type'        => 'bogo',
		'priority'    => 20,
		'product_ids' => array( $ids['Paper filters (100)'] ),
		'buy'         => 2,
		'get'         => 1,
		'limit'       => 3,
		'exclusive'   => true,
	),
	array(
		'name'         => 'Brewing gear sale',
		'type'         => 'sale',
		'priority'     => 30,
		'category_ids' => array( $gear ),
		'bp'           => 1500,
	),
	array(
		'name'     => 'Trade customers',
		'type'     => 'sale',
		'priority' => 40,
		'roles'    => array( 'wholesale' ),
		'bp'       => 2000,
	),
	array(
		'name'      => '$10 off orders over $100',
		'type'      => 'cart',
		'priority'  => 90,
		'fixed'     => 1000,
		'threshold' => 10000,
	),
	array(
		'name'     => 'Summer sale (ended)',
		'type'     => 'sale',
		'priority' => 5,
		'bp'       => 5000,
		'starts'   => strtotime( '2025-06-01' ),
		'ends'     => strtotime( '2025-09-01' ),
	),
);
foreach ( $rules as $r ) {
	RuleRepository::save( $r );
}
if ( ! get_role( 'wholesale' ) ) {
	add_role( 'wholesale', 'Wholesale customer', array( 'read' => true ) );
}
update_option( 'wcpr_cap_percent', '100' );
echo 'catalogue and ' . count( $rules ) . " rules ready\n";
