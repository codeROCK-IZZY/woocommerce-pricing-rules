<?php
/**
 * One cart line as the engine sees it.
 *
 * @package PricingRules
 */

namespace PricingRules\Engine;

/**
 * Immutable cart line. Prices are integers in the store's smallest unit (cents).
 */
final class Line {

	/**
	 * Build a line.
	 *
	 * @param string    $key          Cart item key.
	 * @param int       $product_id   Product (or variation parent) ID.
	 * @param list<int> $category_ids Product category IDs.
	 * @param int       $qty          Quantity.
	 * @param int       $unit_cents   Unit price before any rule, in cents.
	 */
	public function __construct(
		public readonly string $key,
		public readonly int $product_id,
		public readonly array $category_ids,
		public readonly int $qty,
		public readonly int $unit_cents,
	) {}
}
