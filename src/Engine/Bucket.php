<?php
/**
 * A group of interchangeable units of one cart line.
 *
 * @package PricingRules
 */

namespace PricingRules\Engine;

/**
 * Mutable; owned by one PricingEngine::price() call.
 */
final class Bucket {

	/**
	 * Scratch value for the cart rule.
	 *
	 * @var int
	 */
	public int $quota = 0;

	/**
	 * Build a bucket.
	 *
	 * @param int             $line    Line index.
	 * @param int             $base    Original unit price.
	 * @param int             $price   Current unit price.
	 * @param int             $floor   Lowest allowed unit price.
	 * @param int             $count   Units.
	 * @param bool            $locked  Exclusive rule applied.
	 * @param array<int, int> $applied Discount per unit, per rule ID.
	 */
	public function __construct(
		public readonly int $line,
		public readonly int $base,
		public int $price,
		public readonly int $floor,
		public int $count,
		public bool $locked,
		public array $applied,
	) {}

	/**
	 * Take $n units out into a new bucket (or return this one when $n covers it).
	 *
	 * @param int $n Units.
	 */
	public function split( int $n ): self {
		if ( $n >= $this->count ) {
			return $this;
		}
		$this->count -= $n;
		return new self( $this->line, $this->base, $this->price, $this->floor, $n, $this->locked, $this->applied );
	}

	/**
	 * Discount every unit by up to $cents, never below the floor.
	 *
	 * @param Rule $rule  Rule.
	 * @param int  $cents Wanted discount per unit.
	 */
	public function discount( Rule $rule, int $cents ): void {
		$d = max( 0, min( $cents, $this->price - $this->floor ) );
		if ( $d <= 0 ) {
			return;
		}
		$this->price               -= $d;
		$this->applied[ $rule->id ] = ( $this->applied[ $rule->id ] ?? 0 ) + $d;
		if ( $rule->exclusive ) {
			$this->locked = true;
		}
	}
}
