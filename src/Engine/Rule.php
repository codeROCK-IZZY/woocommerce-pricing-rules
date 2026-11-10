<?php
/**
 * A pricing rule, validated on construction.
 *
 * @package PricingRules
 */

namespace PricingRules\Engine;

/**
 * Five rule types:
 * - sale:  bp off each matching unit;
 * - bulk:  tiers by quantity (counted per line or across all matching units), the reached tier's bp off every unit;
 * - bogo:  buy `buy`, get `get` at `get_bp` off; units sorted most expensive first, the cheapest of each group are discounted;
 * - cart:  when the matching subtotal reaches `threshold`, bp off it or `fixed` cents, spread over units in proportion to price;
 * Every type can be limited to products, categories, roles and dates, and marked exclusive.
 */
final class Rule {

	public const TYPES = array( 'sale', 'bulk', 'bogo', 'cart' );

	/**
	 * Build a rule. Use from_array() for untrusted input.
	 *
	 * @param int                                $id           Rule ID.
	 * @param string                             $name         Name shown to shoppers.
	 * @param string                             $type         One of TYPES.
	 * @param int                                $priority     Lower runs first.
	 * @param int                                $bp           Basis points (sale, cart).
	 * @param list<array{min: int, bp: int}>     $tiers        Bulk tiers, ascending by min.
	 * @param string                             $count_by     Bulk: 'line' or 'rule'.
	 * @param int                                $buy          BOGO: units paid.
	 * @param int                                $get          BOGO: units discounted.
	 * @param int                                $get_bp       BOGO: discount on the "get" units.
	 * @param int                                $limit        BOGO: maximum groups (0 = no limit).
	 * @param int                                $threshold    Cart: minimum matching subtotal in cents.
	 * @param int                                $fixed        Cart: fixed discount in cents (used when bp is 0).
	 * @param list<int>                          $product_ids  Products it applies to.
	 * @param list<int>                          $category_ids Categories it applies to (either list matching is enough; both empty = everything).
	 * @param list<string>                       $roles        Roles it applies to (empty = everyone).
	 * @param int                                $starts       Unix time it starts (0 = always).
	 * @param int                                $ends         Unix time it ends (0 = never).
	 * @param bool                               $exclusive    Units it discounts get no further rules.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $name,
		public readonly string $type,
		public readonly int $priority = 10,
		public readonly int $bp = 0,
		public readonly array $tiers = array(),
		public readonly string $count_by = 'line',
		public readonly int $buy = 1,
		public readonly int $get = 1,
		public readonly int $get_bp = 10000,
		public readonly int $limit = 0,
		public readonly int $threshold = 0,
		public readonly int $fixed = 0,
		public readonly array $product_ids = array(),
		public readonly array $category_ids = array(),
		public readonly array $roles = array(),
		public readonly int $starts = 0,
		public readonly int $ends = 0,
		public readonly bool $exclusive = false,
	) {}

	/**
	 * Validate and build from an array (admin form, JSON).
	 *
	 * @param array<string, mixed> $a Raw fields.
	 * @throws \InvalidArgumentException When a field is out of range.
	 */
	public static function from_array( array $a ): self {
		$int  = static fn ( string $k, int $fallback, int $min, int $max ): int => self::bounded( $a[ $k ] ?? $fallback, $min, $max, $k );
		$ids  = static fn ( string $k ): array => array_values( array_unique( array_filter( array_map( 'intval', (array) ( $a[ $k ] ?? array() ) ), static fn ( int $v ): bool => $v > 0 ) ) );
		$type = (string) ( $a['type'] ?? '' );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			throw new \InvalidArgumentException( 'type must be one of ' . implode( ', ', self::TYPES ) );
		}
		$tiers = array();
		foreach ( (array) ( $a['tiers'] ?? array() ) as $t ) {
			$t       = (array) $t;
			$tiers[] = array(
				'min' => self::bounded( $t['min'] ?? 0, 1, 1000000, 'tier min' ),
				'bp'  => self::bounded( $t['bp'] ?? 0, 0, 10000, 'tier bp' ),
			);
		}
		usort( $tiers, static fn ( array $x, array $y ): int => $x['min'] <=> $y['min'] );
		if ( 'bulk' === $type && ! $tiers ) {
			throw new \InvalidArgumentException( 'a bulk rule needs at least one tier' );
		}
		$roles = array_values( array_unique( array_filter( array_map( static fn ( $r ): string => strtolower( trim( (string) $r ) ), (array) ( $a['roles'] ?? array() ) ) ) ) );
		$rule  = new self(
			$int( 'id', 0, 0, PHP_INT_MAX ),
			mb_substr( trim( (string) ( $a['name'] ?? '' ) ), 0, 120 ),
			$type,
			$int( 'priority', 10, -1000, 1000 ),
			$int( 'bp', 0, 0, 10000 ),
			$tiers,
			in_array( $a['count_by'] ?? 'line', array( 'rule' ), true ) ? 'rule' : 'line',
			$int( 'buy', 1, 1, 1000 ),
			$int( 'get', 1, 1, 1000 ),
			$int( 'get_bp', 10000, 0, 10000 ),
			$int( 'limit', 0, 0, 1000000 ),
			$int( 'threshold', 0, 0, PHP_INT_MAX >> 16 ),
			$int( 'fixed', 0, 0, PHP_INT_MAX >> 16 ),
			$ids( 'product_ids' ),
			$ids( 'category_ids' ),
			$roles,
			$int( 'starts', 0, 0, PHP_INT_MAX ),
			$int( 'ends', 0, 0, PHP_INT_MAX ),
			! empty( $a['exclusive'] ),
		);
		if ( $rule->ends > 0 && $rule->ends <= $rule->starts ) {
			throw new \InvalidArgumentException( 'ends must be after starts' );
		}
		return $rule;
	}

	/**
	 * Back to an array (storage, export).
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return get_object_vars( $this );
	}

	/**
	 * Whether the rule is on for this customer and time.
	 *
	 * @param Context $c Context.
	 */
	public function active( Context $c ): bool {
		if ( $this->starts > 0 && $c->now < $this->starts ) {
			return false;
		}
		if ( $this->ends > 0 && $c->now >= $this->ends ) {
			return false;
		}
		return ! $this->roles || (bool) array_intersect( $this->roles, $c->roles );
	}

	/**
	 * Whether a line's product is in scope.
	 *
	 * @param Line $line Line.
	 */
	public function matches( Line $line ): bool {
		if ( ! $this->product_ids && ! $this->category_ids ) {
			return true;
		}
		return in_array( $line->product_id, $this->product_ids, true ) || (bool) array_intersect( $line->category_ids, $this->category_ids );
	}

	/**
	 * Bulk: the tier reached by a quantity, in bp (0 if none).
	 *
	 * @param int $qty Quantity.
	 */
	public function tier_bp( int $qty ): int {
		$bp = 0;
		foreach ( $this->tiers as $t ) {
			if ( $qty >= $t['min'] ) {
				$bp = $t['bp'];
			}
		}
		return $bp;
	}

	/**
	 * Integer within bounds.
	 *
	 * @param mixed  $v    Value.
	 * @param int    $min  Minimum.
	 * @param int    $max  Maximum.
	 * @param string $name Field name.
	 * @throws \InvalidArgumentException When out of range or not an integer.
	 */
	private static function bounded( $v, int $min, int $max, string $name ): int {
		if ( is_bool( $v ) || ! is_numeric( $v ) || 0 !== strcmp( (string) (int) $v, trim( (string) $v ) ) ) {
			throw new \InvalidArgumentException( "{$name} must be a whole number" );
		}
		$i = (int) $v;
		if ( $i < $min || $i > $max ) {
			throw new \InvalidArgumentException( "{$name} must be between {$min} and {$max}" );
		}
		return $i;
	}
}
