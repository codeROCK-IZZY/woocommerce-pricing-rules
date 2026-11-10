<?php
/**
 * WooCommerce → Settings → Products → Pricing rules.
 *
 * @package PricingRules
 */

namespace PricingRules\Admin;

/**
 * A section in WooCommerce's own settings screens.
 */
final class Settings {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_filter( 'woocommerce_get_sections_products', array( self::class, 'section' ) );
		add_filter( 'woocommerce_get_settings_products', array( self::class, 'fields' ), 10, 2 );
	}

	/**
	 * Add the section.
	 *
	 * @param array<string, string> $sections Sections.
	 * @return array<string, string>
	 */
	public static function section( $sections ) {
		$sections['wcpr'] = __( 'Pricing rules', 'pricing-rules-for-woocommerce' );
		return $sections;
	}

	/**
	 * Section fields.
	 *
	 * @param array<int, array<string, mixed>> $settings Settings.
	 * @param string                           $section  Current section.
	 * @return array<int, array<string, mixed>>
	 */
	public static function fields( $settings, $section ) {
		if ( 'wcpr' !== $section ) {
			return $settings;
		}
		return array(
			array(
				'title' => __( 'Pricing rules', 'pricing-rules-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Rules themselves are under WooCommerce → Pricing rules.', 'pricing-rules-for-woocommerce' ),
				'id'    => 'wcpr_options',
			),
			array(
				'title'             => __( 'Maximum discount per item (%)', 'pricing-rules-for-woocommerce' ),
				'desc'              => __( 'However rules combine, no item is sold for less than this far below its normal price. 100 allows free items (buy one, get one free).', 'pricing-rules-for-woocommerce' ),
				'id'                => 'wcpr_cap_percent',
				'type'              => 'number',
				'default'           => '100',
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => '100',
					'step' => '0.01',
				),
			),
			array(
				'title'   => __( 'Show savings', 'pricing-rules-for-woocommerce' ),
				'desc'    => __( 'Show a "You save" line in the cart and at checkout', 'pricing-rules-for-woocommerce' ),
				'id'      => 'wcpr_show_savings',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'title'   => __( 'Quantity tables', 'pricing-rules-for-woocommerce' ),
				'desc'    => __( 'Show bulk-discount tiers on product pages', 'pricing-rules-for-woocommerce' ),
				'id'      => 'wcpr_show_tiers',
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'wcpr_options',
			),
		);
	}

	/**
	 * Cap in basis points.
	 */
	public static function cap_bp(): int {
		$pct = (float) get_option( 'wcpr_cap_percent', '100' );
		return (int) round( max( 0.0, min( 100.0, $pct ) ) * 100 );
	}

	/**
	 * Whether to show the savings row.
	 */
	public static function show_savings(): bool {
		return 'no' !== get_option( 'wcpr_show_savings', 'yes' );
	}

	/**
	 * Whether to show tier tables.
	 */
	public static function show_tiers(): bool {
		return 'no' !== get_option( 'wcpr_show_tiers', 'yes' );
	}
}
