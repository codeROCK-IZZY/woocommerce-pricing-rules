<?php
/**
 * Rules are a private custom post type, so they get WordPress's list screen, trash, revisions-free editing
 * and capabilities for free. The rule itself is one validated JSON meta value.
 *
 * @package PricingRules
 */

namespace PricingRules\Woo;

use PricingRules\Engine\Rule;

/**
 * Storage and loading of rules.
 */
final class RuleRepository {

	public const POST_TYPE = 'wcpr_rule';
	public const META      = '_wcpr_rule';
	public const CACHE     = 'wcpr_rules_v1';

	/**
	 * Register the post type and cache invalidation.
	 */
	public static function register(): void {
		add_action( 'init', array( self::class, 'post_type' ) );
		foreach ( array( 'save_post_' . self::POST_TYPE, 'trashed_post', 'untrashed_post', 'deleted_post', 'transition_post_status' ) as $hook ) {
			add_action( $hook, array( self::class, 'flush' ) );
		}
	}

	/**
	 * The post type, shown under the WooCommerce menu.
	 */
	public static function post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Pricing rules', 'pricing-rules-for-woocommerce' ),
					'singular_name' => __( 'Pricing rule', 'pricing-rules-for-woocommerce' ),
					'add_new_item'  => __( 'Add pricing rule', 'pricing-rules-for-woocommerce' ),
					'edit_item'     => __( 'Edit pricing rule', 'pricing-rules-for-woocommerce' ),
					'not_found'     => __( 'No pricing rules yet.', 'pricing-rules-for-woocommerce' ),
					'search_items'  => __( 'Search pricing rules', 'pricing-rules-for-woocommerce' ),
					'menu_name'     => __( 'Pricing rules', 'pricing-rules-for-woocommerce' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'woocommerce',
				'supports'        => array( 'title' ),
				'capability_type' => 'product',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Drop the cached rule list.
	 */
	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * Published rules, cached until a rule changes.
	 *
	 * @return list<Rule>
	 */
	public static function all(): array {
		$cached = get_transient( self::CACHE );
		$rows   = is_array( $cached ) ? $cached : null;
		if ( null === $rows ) {
			$rows  = array();
			$posts = get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- Loaded once, then cached until a rule changes.
					'orderby'        => 'ID',
					'order'          => 'ASC',
				)
			);
			foreach ( $posts as $post ) {
				$data = json_decode( (string) get_post_meta( $post->ID, self::META, true ), true );
				if ( is_array( $data ) ) {
					$data['id']   = $post->ID;
					$data['name'] = $post->post_title;
					$rows[]       = $data;
				}
			}
			set_transient( self::CACHE, $rows, DAY_IN_SECONDS );
		}
		$rules = array();
		foreach ( $rows as $row ) {
			try {
				$rules[] = Rule::from_array( (array) $row );
			} catch ( \InvalidArgumentException $e ) {
				continue; // An invalid stored rule is skipped, never half-applied; the editor shows the error.
			}
		}
		return $rules;
	}

	/**
	 * Create or update a rule (also used by tests and the demo).
	 *
	 * @param array<string, mixed> $data   Rule fields.
	 * @param int                  $post_id Existing post, or 0.
	 * @return int Post ID.
	 * @throws \InvalidArgumentException When the rule is invalid.
	 */
	public static function save( array $data, int $post_id = 0 ): int {
		$rule = Rule::from_array( $data + array( 'id' => max( 1, $post_id ) ) );
		if ( 0 === $post_id ) {
			$post_id = (int) wp_insert_post(
				array(
					'post_type'   => self::POST_TYPE,
					'post_status' => 'publish',
					'post_title'  => '' !== $rule->name ? $rule->name : ucfirst( $rule->type ),
				)
			);
		}
		$stored = $rule->to_array();
		unset( $stored['id'], $stored['name'] );
		update_post_meta( $post_id, self::META, wp_slash( (string) wp_json_encode( $stored ) ) );
		self::flush();
		return $post_id;
	}
}
