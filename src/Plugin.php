<?php
/**
 * Wires the plugin into WordPress and WooCommerce.
 *
 * @package PricingRules
 */

namespace PricingRules;

use PricingRules\Admin\RuleEditor;
use PricingRules\Admin\Settings;
use PricingRules\Woo\CartPricer;
use PricingRules\Woo\Display;
use PricingRules\Woo\RuleRepository;

/**
 * Hook registration.
 */
final class Plugin {

	/**
	 * Register hooks, or explain why not.
	 */
	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'Pricing Rules for WooCommerce needs WooCommerce to be active.', 'pricing-rules-for-woocommerce' ) . '</p></div>';
				}
			);
			return;
		}
		RuleRepository::register();
		CartPricer::register();
		Display::register();
		Settings::register();
		if ( is_admin() ) {
			RuleEditor::register();
		}
	}
}
