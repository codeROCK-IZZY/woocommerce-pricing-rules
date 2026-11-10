<?php
/**
 * Editing rules: a meta box on the rule post type, validation on save, and list-screen columns.
 *
 * @package PricingRules
 */

namespace PricingRules\Admin;

use PricingRules\Engine\Rule;
use PricingRules\Woo\Display;
use PricingRules\Woo\RuleRepository;

/**
 * Admin UI for rules.
 */
final class RuleEditor {

	/**
	 * Register hooks.
	 */
	public static function register(): void {
		add_action( 'add_meta_boxes_' . RuleRepository::POST_TYPE, array( self::class, 'meta_box' ) );
		add_action( 'save_post_' . RuleRepository::POST_TYPE, array( self::class, 'save' ), 10, 2 );
		add_action( 'admin_notices', array( self::class, 'notice' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
		add_filter( 'manage_' . RuleRepository::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . RuleRepository::POST_TYPE . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
	}

	/**
	 * Scripts and styles on rule screens.
	 */
	public static function assets(): void {
		$screen = get_current_screen();
		if ( $screen && RuleRepository::POST_TYPE === $screen->post_type ) {
			wp_enqueue_style( 'wcpr-admin', WCPR_URL . 'assets/admin.css', array(), WCPR_VERSION );
			wp_enqueue_script( 'wcpr-admin', WCPR_URL . 'assets/admin.js', array( 'wc-enhanced-select' ), WCPR_VERSION, true );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
	}

	/**
	 * Add the meta box.
	 */
	public static function meta_box(): void {
		add_meta_box( 'wcpr-rule', __( 'Rule', 'pricing-rules-for-woocommerce' ), array( self::class, 'render' ), RuleRepository::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Current values for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array<string, mixed>
	 */
	private static function values( int $post_id ): array {
		$data = json_decode( (string) get_post_meta( $post_id, RuleRepository::META, true ), true );
		return is_array( $data ) ? $data : array(
			'type'  => 'sale',
			'bp'    => 1000,
			'tiers' => array(
				array(
					'min' => 5,
					'bp'  => 500,
				),
				array(
					'min' => 10,
					'bp'  => 1000,
				),
			),
		);
	}

	/**
	 * Render the meta box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render( $post ): void {
		$v      = self::values( (int) $post->ID );
		$factor = 10 ** wc_get_price_decimals();
		$pct    = static fn ( $bp ): string => rtrim( rtrim( number_format( ( (int) $bp ) / 100, 2, '.', '' ), '0' ), '.' );
		$money  = static fn ( $cents ): string => wc_format_localized_price( (string) ( ( (int) $cents ) / $factor ) );
		$date   = static fn ( $ts ): string => (int) $ts > 0 ? (string) wp_date( 'Y-m-d\TH:i', (int) $ts ) : '';
		$type   = (string) ( $v['type'] ?? 'sale' );
		wp_nonce_field( 'wcpr_save_rule', 'wcpr_nonce' );
		$types = array(
			'sale' => __( 'Sale: a percentage off', 'pricing-rules-for-woocommerce' ),
			'bulk' => __( 'Bulk: tiers by quantity', 'pricing-rules-for-woocommerce' ),
			'bogo' => __( 'Buy X, get Y', 'pricing-rules-for-woocommerce' ),
			'cart' => __( 'Cart total: spend more, save more', 'pricing-rules-for-woocommerce' ),
		);
		echo '<div class="wcpr-editor" data-type="' . esc_attr( $type ) . '"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="wcpr-type">' . esc_html__( 'Type', 'pricing-rules-for-woocommerce' ) . '</label></th><td><select id="wcpr-type" name="wcpr[type]">';
		foreach ( $types as $k => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $type, $k, false ), esc_html( $label ) );
		}
		echo '</select></td></tr>';

		printf( '<tr class="wcpr-for wcpr-for-sale wcpr-for-cart"><th scope="row"><label for="wcpr-pct">%s</label></th><td><input id="wcpr-pct" name="wcpr[percent]" type="number" min="0" max="100" step="0.01" value="%s"> %% <p class="description">%s</p></td></tr>', esc_html__( 'Discount', 'pricing-rules-for-woocommerce' ), esc_attr( $pct( $v['bp'] ?? 0 ) ), esc_html__( 'For cart rules, leave at 0 to use a fixed amount instead.', 'pricing-rules-for-woocommerce' ) );
		printf( '<tr class="wcpr-for wcpr-for-cart"><th scope="row"><label for="wcpr-threshold">%s</label></th><td><input id="wcpr-threshold" name="wcpr[threshold]" type="text" class="wc_input_price short" value="%s"></td></tr>', esc_html__( 'When qualifying items total at least', 'pricing-rules-for-woocommerce' ), esc_attr( $money( $v['threshold'] ?? 0 ) ) );
		printf( '<tr class="wcpr-for wcpr-for-cart"><th scope="row"><label for="wcpr-fixed">%s</label></th><td><input id="wcpr-fixed" name="wcpr[fixed]" type="text" class="wc_input_price short" value="%s"></td></tr>', esc_html__( 'Fixed amount off', 'pricing-rules-for-woocommerce' ), esc_attr( $money( $v['fixed'] ?? 0 ) ) );

		echo '<tr class="wcpr-for wcpr-for-bulk"><th scope="row">' . esc_html__( 'Tiers', 'pricing-rules-for-woocommerce' ) . '</th><td><table class="wcpr-tier-rows"><thead><tr><th>' . esc_html__( 'Minimum quantity', 'pricing-rules-for-woocommerce' ) . '</th><th>' . esc_html__( '% off each item', 'pricing-rules-for-woocommerce' ) . '</th><th></th></tr></thead><tbody>';
		$tiers = (array) ( $v['tiers'] ?? array() );
		$tiers = $tiers ? $tiers : array(
			array(
				'min' => 5,
				'bp'  => 500,
			),
		);
		foreach ( $tiers as $t ) {
			printf( '<tr><td><input type="number" min="1" name="wcpr[tier_min][]" value="%d"></td><td><input type="number" min="0" max="100" step="0.01" name="wcpr[tier_pct][]" value="%s"></td><td><button type="button" class="button-link wcpr-remove-tier">%s</button></td></tr>', (int) ( $t['min'] ?? 1 ), esc_attr( $pct( $t['bp'] ?? 0 ) ), esc_html__( 'Remove', 'pricing-rules-for-woocommerce' ) );
		}
		echo '</tbody></table><button type="button" class="button wcpr-add-tier">' . esc_html__( 'Add tier', 'pricing-rules-for-woocommerce' ) . '</button>';
		printf( '<p><label><input type="checkbox" name="wcpr[count_by_rule]" value="1"%s> %s</label></p></td></tr>', checked( 'rule' === ( $v['count_by'] ?? 'line' ), true, false ), esc_html__( 'Mix and match: count all qualifying products together, not each product separately', 'pricing-rules-for-woocommerce' ) );

		printf(
			'<tr class="wcpr-for wcpr-for-bogo"><th scope="row">%s</th><td>%s <input type="number" min="1" name="wcpr[buy]" value="%d" class="small-text"> %s <input type="number" min="1" name="wcpr[get]" value="%d" class="small-text"> %s <input type="number" min="0" max="100" step="0.01" name="wcpr[get_percent]" value="%s" class="small-text"> %% %s <p class="description">%s <input type="number" min="0" name="wcpr[limit]" value="%d" class="small-text"> %s</p></td></tr>',
			esc_html__( 'Offer', 'pricing-rules-for-woocommerce' ),
			esc_html__( 'Buy', 'pricing-rules-for-woocommerce' ),
			(int) ( $v['buy'] ?? 1 ),
			esc_html__( 'get', 'pricing-rules-for-woocommerce' ),
			(int) ( $v['get'] ?? 1 ),
			esc_html__( 'at', 'pricing-rules-for-woocommerce' ),
			esc_attr( $pct( $v['get_bp'] ?? 10000 ) ),
			esc_html__( 'off (100 = free). The cheapest items are discounted.', 'pricing-rules-for-woocommerce' ),
			esc_html__( 'At most', 'pricing-rules-for-woocommerce' ),
			(int) ( $v['limit'] ?? 0 ),
			esc_html__( 'times per order (0 = no limit).', 'pricing-rules-for-woocommerce' )
		);

		echo '<tr><th scope="row"><label for="wcpr-products">' . esc_html__( 'Products', 'pricing-rules-for-woocommerce' ) . '</label></th><td><select id="wcpr-products" class="wc-product-search" multiple style="width:100%" name="wcpr[product_ids][]" data-placeholder="' . esc_attr__( 'All products', 'pricing-rules-for-woocommerce' ) . '" data-action="woocommerce_json_search_products">';
		foreach ( (array) ( $v['product_ids'] ?? array() ) as $pid ) {
			$p = wc_get_product( (int) $pid );
			if ( $p ) {
				printf( '<option value="%d" selected>%s</option>', (int) $pid, esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) );
			}
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="wcpr-cats">' . esc_html__( 'Categories', 'pricing-rules-for-woocommerce' ) . '</label></th><td><select id="wcpr-cats" class="wc-enhanced-select" multiple style="width:100%" name="wcpr[category_ids][]" data-placeholder="' . esc_attr__( 'All categories', 'pricing-rules-for-woocommerce' ) . '">';
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			printf( '<option value="%d"%s>%s</option>', (int) $term->term_id, selected( in_array( (int) $term->term_id, array_map( 'intval', (array) ( $v['category_ids'] ?? array() ) ), true ), true, false ), esc_html( $term->name ) );
		}
		echo '</select><p class="description">' . esc_html__( 'Leave both empty to apply to everything. Otherwise an item qualifies if it is one of the products or in one of the categories.', 'pricing-rules-for-woocommerce' ) . '</p></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Customers', 'pricing-rules-for-woocommerce' ) . '</th><td><fieldset>';
		$roles = array( 'guest' => __( 'Guests (not logged in)', 'pricing-rules-for-woocommerce' ) );
		foreach ( wp_roles()->get_names() as $slug => $name ) {
			$roles[ $slug ] = translate_user_role( $name );
		}
		foreach ( $roles as $slug => $name ) {
			printf( '<label class="wcpr-role"><input type="checkbox" name="wcpr[roles][]" value="%s"%s> %s</label> ', esc_attr( $slug ), checked( in_array( $slug, (array) ( $v['roles'] ?? array() ), true ), true, false ), esc_html( $name ) );
		}
		echo '<p class="description">' . esc_html__( 'None ticked: everyone.', 'pricing-rules-for-woocommerce' ) . '</p></fieldset></td></tr>';

		printf( '<tr><th scope="row">%s</th><td><label>%s <input type="datetime-local" name="wcpr[starts]" value="%s"></label> <label>%s <input type="datetime-local" name="wcpr[ends]" value="%s"></label><p class="description">%s</p></td></tr>', esc_html__( 'Schedule', 'pricing-rules-for-woocommerce' ), esc_html__( 'From', 'pricing-rules-for-woocommerce' ), esc_attr( $date( $v['starts'] ?? 0 ) ), esc_html__( 'until', 'pricing-rules-for-woocommerce' ), esc_attr( $date( $v['ends'] ?? 0 ) ), esc_html__( 'Site time zone. Leave empty for no limit.', 'pricing-rules-for-woocommerce' ) );
		printf( '<tr><th scope="row"><label for="wcpr-priority">%s</label></th><td><input id="wcpr-priority" type="number" name="wcpr[priority]" value="%d" class="small-text"><p class="description">%s</p><p><label><input type="checkbox" name="wcpr[exclusive]" value="1"%s> %s</label></p></td></tr>', esc_html__( 'Priority', 'pricing-rules-for-woocommerce' ), (int) ( $v['priority'] ?? 10 ), esc_html__( 'Lower numbers run first. Percentages apply to the price left by earlier rules, so stacked discounts compound.', 'pricing-rules-for-woocommerce' ), checked( ! empty( $v['exclusive'] ), true, false ), esc_html__( 'Exclusive: items this rule discounts get no further rules', 'pricing-rules-for-woocommerce' ) );
		echo '</tbody></table></div>';
	}

	/**
	 * Turn the submitted form into rule fields.
	 *
	 * @param array<string, mixed> $in Submitted values (unslashed).
	 * @return array<string, mixed>
	 */
	public static function from_form( array $in ): array {
		$factor = 10 ** wc_get_price_decimals();
		$bp     = static fn ( $v ): int => (int) round( (float) wc_format_decimal( (string) $v ) * 100 );
		$cents  = static fn ( $v ): int => (int) round( (float) wc_format_decimal( (string) $v ) * $factor );
		$ts     = static function ( $v ): int {
			$v = trim( (string) $v );
			if ( '' === $v ) {
				return 0;
			}
			$d = date_create_immutable( $v, wp_timezone() );
			return $d ? $d->getTimestamp() : 0;
		};
		$tiers  = array();
		foreach ( (array) ( $in['tier_min'] ?? array() ) as $i => $min ) {
			if ( '' !== trim( (string) $min ) ) {
				$tiers[] = array(
					'min' => (int) $min,
					'bp'  => $bp( $in['tier_pct'][ $i ] ?? 0 ),
				);
			}
		}
		return array(
			'type'         => sanitize_key( (string) ( $in['type'] ?? '' ) ),
			'priority'     => (int) ( $in['priority'] ?? 10 ),
			'bp'           => $bp( $in['percent'] ?? 0 ),
			'tiers'        => $tiers,
			'count_by'     => empty( $in['count_by_rule'] ) ? 'line' : 'rule',
			'buy'          => (int) ( $in['buy'] ?? 1 ),
			'get'          => (int) ( $in['get'] ?? 1 ),
			'get_bp'       => $bp( $in['get_percent'] ?? 100 ),
			'limit'        => (int) ( $in['limit'] ?? 0 ),
			'threshold'    => $cents( $in['threshold'] ?? 0 ),
			'fixed'        => $cents( $in['fixed'] ?? 0 ),
			'product_ids'  => array_map( 'intval', (array) ( $in['product_ids'] ?? array() ) ),
			'category_ids' => array_map( 'intval', (array) ( $in['category_ids'] ?? array() ) ),
			'roles'        => array_map( 'sanitize_key', (array) ( $in['roles'] ?? array() ) ),
			'starts'       => $ts( $in['starts'] ?? '' ),
			'ends'         => $ts( $in['ends'] ?? '' ),
			'exclusive'    => ! empty( $in['exclusive'] ),
		);
	}

	/**
	 * Validate and save. An invalid rule is not saved; the previous version stays in force and an error is shown.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function save( $post_id, $post ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Action signature.
		if ( ! isset( $_POST['wcpr_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wcpr_nonce'] ) ), 'wcpr_save_rule' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$in   = isset( $_POST['wcpr'] ) && is_array( $_POST['wcpr'] ) ? map_deep( wp_unslash( $_POST['wcpr'] ), 'sanitize_text_field' ) : array();
		$data = self::from_form( $in );
		try {
			Rule::from_array( $data + array( 'id' => (int) $post_id ) );
		} catch ( \InvalidArgumentException $e ) {
			set_transient( 'wcpr_error_' . get_current_user_id(), $e->getMessage(), 60 );
			return;
		}
		update_post_meta( (int) $post_id, RuleRepository::META, wp_slash( (string) wp_json_encode( $data ) ) );
		RuleRepository::flush();
	}

	/**
	 * Show a validation error after redirect.
	 */
	public static function notice(): void {
		$msg = get_transient( 'wcpr_error_' . get_current_user_id() );
		if ( $msg ) {
			delete_transient( 'wcpr_error_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The rule was not saved:', 'pricing-rules-for-woocommerce' ) . ' ' . esc_html( (string) $msg ) . '</p></div>';
		}
	}

	/**
	 * List columns.
	 *
	 * @param array<string, string> $cols Columns.
	 * @return array<string, string>
	 */
	public static function columns( $cols ) {
		return array(
			'cb'            => $cols['cb'] ?? '',
			'title'         => __( 'Rule', 'pricing-rules-for-woocommerce' ),
			'wcpr_offer'    => __( 'Offer', 'pricing-rules-for-woocommerce' ),
			'wcpr_scope'    => __( 'Applies to', 'pricing-rules-for-woocommerce' ),
			'wcpr_when'     => __( 'Schedule', 'pricing-rules-for-woocommerce' ),
			'wcpr_priority' => __( 'Priority', 'pricing-rules-for-woocommerce' ),
		);
	}

	/**
	 * Column content.
	 *
	 * @param string $col     Column.
	 * @param int    $post_id Post ID.
	 */
	public static function column( $col, $post_id ): void {
		try {
			$r = Rule::from_array( self::values( (int) $post_id ) + array( 'id' => (int) $post_id ) );
		} catch ( \InvalidArgumentException $e ) {
			echo '<span class="wcpr-invalid">' . esc_html( $e->getMessage() ) . '</span>';
			return;
		}
		switch ( $col ) {
			case 'wcpr_offer':
				echo esc_html( Display::describe( $r ) ) . ( $r->exclusive ? ' <span class="wcpr-chip">' . esc_html__( 'exclusive', 'pricing-rules-for-woocommerce' ) . '</span>' : '' );
				break;
			case 'wcpr_scope':
				$parts = array();
				if ( $r->product_ids ) {
					/* translators: %d: number of products */
					$parts[] = sprintf( _n( '%d product', '%d products', count( $r->product_ids ), 'pricing-rules-for-woocommerce' ), count( $r->product_ids ) );
				}
				foreach ( $r->category_ids as $cid ) {
					$term    = get_term( $cid, 'product_cat' );
					$parts[] = $term instanceof \WP_Term ? $term->name : '#' . $cid;
				}
				$who = $r->roles ? ' · ' . implode( ', ', $r->roles ) : '';
				echo esc_html( ( $parts ? implode( ', ', $parts ) : __( 'Everything', 'pricing-rules-for-woocommerce' ) ) . $who );
				break;
			case 'wcpr_when':
				$now = time();
				if ( $r->ends > 0 && $now >= $r->ends ) {
					echo '<span class="wcpr-chip is-off">' . esc_html__( 'ended', 'pricing-rules-for-woocommerce' ) . '</span>';
				} elseif ( $r->starts > $now ) {
					/* translators: %s: date */
					echo '<span class="wcpr-chip">' . esc_html( sprintf( __( 'starts %s', 'pricing-rules-for-woocommerce' ), wp_date( get_option( 'date_format' ), $r->starts ) ) ) . '</span>';
				} else {
					echo '<span class="wcpr-chip is-on">' . esc_html__( 'running', 'pricing-rules-for-woocommerce' ) . '</span>' . ( $r->ends > 0 ? ' ' . esc_html( sprintf( /* translators: %s: date */ __( 'until %s', 'pricing-rules-for-woocommerce' ), wp_date( get_option( 'date_format' ), $r->ends ) ) ) : '' );
				}
				break;
			case 'wcpr_priority':
				echo (int) $r->priority;
				break;
		}
	}
}
