<?php
/**
 * Removes rules and settings when the plugin is deleted. Order history (line-item discount notes) is kept,
 * because it is part of the shop's records.
 *
 * @package PricingRules
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$wcpr_rules = get_posts(
	array(
		'post_type'      => 'wcpr_rule',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $wcpr_rules as $wcpr_id ) {
	wp_delete_post( (int) $wcpr_id, true );
}
foreach ( array( 'wcpr_cap_percent', 'wcpr_show_savings', 'wcpr_show_tiers' ) as $wcpr_option ) {
	delete_option( $wcpr_option );
}
delete_transient( 'wcpr_rules_v1' );
