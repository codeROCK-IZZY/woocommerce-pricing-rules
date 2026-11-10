<?php
/**
 * Pricing results.
 *
 * @package PricingRules
 */

namespace PricingRules\Engine;

/**
 * The priced cart. All amounts in cents.
 */
final class Result {

	/**
	 * Build a result.
	 *
	 * @param array<string, array{base: int, discount: int, total: int, qty: int, applied: array<int, int>}> $lines    By cart item key.
	 * @param array<int, int>                                                                                $by_rule  Discount per rule ID.
	 * @param int                                                                                            $subtotal Before discounts.
	 * @param int                                                                                            $discount Total discount.
	 */
	public function __construct(
		public readonly array $lines,
		public readonly array $by_rule,
		public readonly int $subtotal,
		public readonly int $discount,
	) {}

	/**
	 * Total after discounts.
	 */
	public function total(): int {
		return $this->subtotal - $this->discount;
	}
}
