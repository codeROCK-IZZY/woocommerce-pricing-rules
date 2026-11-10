<?php
/**
 * Plugin Name:          Pricing Rules for WooCommerce
 * Plugin URI:           https://github.com/codeROCK-IZZY/woocommerce-pricing-rules
 * Description:          Bulk tiers, buy-X-get-Y, sales, role prices and cart-total discounts, priced exactly to the cent.
 * Version:              1.0.0
 * Requires at least:    6.7
 * Requires PHP:         8.1
 * Requires Plugins:     woocommerce
 * WC requires at least: 9.9
 * WC tested up to:      11.2
 * Author:               Israel Rocklyn
 * Author URI:           https://israelrocklyn.info
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          pricing-rules-for-woocommerce
 *
 * @package PricingRules
 */

defined( 'ABSPATH' ) || exit;

define( 'WCPR_VERSION', '1.0.0' );
define( 'WCPR_FILE', __FILE__ );
define( 'WCPR_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCPR_URL', plugin_dir_url( __FILE__ ) );

require_once WCPR_DIR . 'src/autoload.php';

// Declare compatibility with WooCommerce's order tables and cart/checkout blocks.
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

add_action( 'plugins_loaded', array( \PricingRules\Plugin::class, 'boot' ) );
