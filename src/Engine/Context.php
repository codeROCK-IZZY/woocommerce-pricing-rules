<?php
/**
 * Who is buying and when.
 *
 * @package PricingRules
 */

namespace PricingRules\Engine;

/**
 * Pricing context.
 */
final class Context {

	/**
	 * Build a context.
	 *
	 * @param list<string> $roles  Customer roles ("guest" when logged out).
	 * @param int          $now    Unix time.
	 * @param int          $cap_bp Largest total discount on any unit, in basis points of its original price (10000 = may be free).
	 */
	public function __construct(
		public readonly array $roles = array( 'guest' ),
		public readonly int $now = 0,
		public readonly int $cap_bp = 10000,
	) {}
}
