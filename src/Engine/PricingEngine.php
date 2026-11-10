<?php
/**
 * Prices a cart against a set of rules, exactly, in integer cents.
 *
 * Units of the same line with the same price, floor and lock state are interchangeable, so the engine keeps them
 * in buckets with a count and only splits a bucket when a rule treats some of its units differently (the "get"
 * units of a buy-X-get-Y, or the units that receive a leftover cent of a cart discount). Work therefore grows
 * with the number of lines and rules, not with quantities. The tests check it against a unit-by-unit reference.
 *
 * Semantics, shared with the reference implementation:
 * - rules run in (priority, id) order; percentages apply to the unit's current price, so stacked rules compound;
 * - each discount is rounded half up per unit, and no unit goes below its floor (cap_bp of its original price);
 * - an exclusive rule locks the units it discounted;
 * - ties are broken by line order, then price, never by position, so the result does not depend on how a line's
 *   identical units are ordered.
 *
 * @package PricingRules
 */

namespace PricingRules\Engine;

/**
 * The engine. Stateless.
 */
final class PricingEngine {

	/**
	 * Price a cart.
	 *
	 * @param list<Line> $lines Cart lines.
	 * @param list<Rule> $rules Rules (inactive ones are skipped).
	 * @param Context    $ctx   Context.
	 */
	public function price( array $lines, array $rules, Context $ctx ): Result {
		$buckets = array();
		foreach ( $lines as $i => $line ) {
			if ( $line->qty <= 0 || $line->unit_cents < 0 ) {
				continue;
			}
			$buckets[] = new Bucket( $i, $line->unit_cents, $line->unit_cents, self::floor( $line->unit_cents, $ctx->cap_bp ), $line->qty, false, array() );
		}

		foreach ( self::ordered( $rules ) as $rule ) {
			if ( ! $rule->active( $ctx ) ) {
				continue;
			}
			$eligible = array_values( array_filter( $buckets, static fn ( Bucket $b ): bool => ! $b->locked && $rule->matches( $lines[ $b->line ] ) ) );
			if ( ! $eligible ) {
				continue;
			}
			$new = match ( $rule->type ) {
				'sale' => $this->each( $eligible, $rule, static fn (): int => $rule->bp ),
				'bulk' => $this->bulk( $eligible, $rule ),
				'bogo' => $this->bogo( $eligible, $rule ),
				'cart' => $this->cart( $eligible, $rule ),
				default => array(),
			};
			$buckets = array_merge( $buckets, $new );
		}

		return self::collect( $lines, $buckets );
	}

	/**
	 * Rules in application order.
	 *
	 * @param list<Rule> $rules Rules.
	 * @return list<Rule>
	 */
	public static function ordered( array $rules ): array {
		usort( $rules, static fn ( Rule $a, Rule $b ): int => array( $a->priority, $a->id ) <=> array( $b->priority, $b->id ) );
		return $rules;
	}

	/**
	 * Lowest price a unit may reach.
	 *
	 * @param int $base   Original unit price.
	 * @param int $cap_bp Maximum discount in bp.
	 */
	public static function floor( int $base, int $cap_bp ): int {
		$cap_bp = max( 0, min( 10000, $cap_bp ) );
		return intdiv( $base * ( 10000 - $cap_bp ) + 9999, 10000 );
	}

	/**
	 * Basis points of a price, rounded half up.
	 *
	 * @param int $price Price.
	 * @param int $bp    Basis points.
	 */
	public static function pct( int $price, int $bp ): int {
		return intdiv( $price * $bp + 5000, 10000 );
	}

	/**
	 * Apply a per-unit percentage to whole buckets.
	 *
	 * @param list<Bucket>        $eligible Buckets.
	 * @param Rule                $rule     Rule.
	 * @param callable(Bucket):int $bp_for  Basis points for a bucket.
	 * @return list<Bucket> New buckets (none: whole buckets change).
	 */
	private function each( array $eligible, Rule $rule, callable $bp_for ): array {
		foreach ( $eligible as $b ) {
			$b->discount( $rule, self::pct( $b->price, $bp_for( $b ) ) );
		}
		return array();
	}

	/**
	 * Bulk tiers.
	 *
	 * @param list<Bucket> $eligible Buckets.
	 * @param Rule         $rule     Rule.
	 * @return list<Bucket>
	 */
	private function bulk( array $eligible, Rule $rule ): array {
		$count = array();
		foreach ( $eligible as $b ) {
			$k           = 'rule' === $rule->count_by ? 'all' : (string) $b->line;
			$count[ $k ] = ( $count[ $k ] ?? 0 ) + $b->count;
		}
		return $this->each( $eligible, $rule, static fn ( Bucket $b ): int => $rule->tier_bp( $count[ 'rule' === $rule->count_by ? 'all' : (string) $b->line ] ) );
	}

	/**
	 * Buy X get Y: sort units most expensive first, cut into groups of X+Y, discount the last Y of each group.
	 *
	 * @param list<Bucket> $eligible Buckets.
	 * @param Rule         $rule     Rule.
	 * @return list<Bucket> Buckets split off for the discounted units.
	 */
	private function bogo( array $eligible, Rule $rule ): array {
		usort( $eligible, static fn ( Bucket $a, Bucket $b ): int => array( $b->price, $a->line ) <=> array( $a->price, $b->line ) );
		$g     = $rule->buy + $rule->get;
		$total = 0;
		foreach ( $eligible as $b ) {
			$total += $b->count;
		}
		$end = $rule->limit > 0 ? min( $total, $rule->limit * $g ) : $total;
		// Number of positions k in [0, n) with k mod g >= buy.
		$upto = static fn ( int $n ): int => intdiv( $n, $g ) * $rule->get + max( 0, $n % $g - $rule->buy );
		$pos  = 0;
		$new  = array();
		foreach ( $eligible as $b ) {
			$from = min( $pos, $end );
			$to   = min( $pos + $b->count, $end );
			$pos += $b->count;
			$n    = $upto( $to ) - $upto( $from );
			if ( $n <= 0 ) {
				continue;
			}
			$part = $b->split( $n );
			$part->discount( $rule, self::pct( $part->price, $rule->get_bp ) );
			if ( $part !== $b ) {
				$new[] = $part;
			}
		}
		return $new;
	}

	/**
	 * Cart threshold: a total discount spread over units in proportion to how much each can still be discounted,
	 * with leftover cents to the largest remainders (ties: earlier line, then higher price).
	 *
	 * @param list<Bucket> $eligible Buckets.
	 * @param Rule         $rule     Rule.
	 * @return list<Bucket>
	 */
	private function cart( array $eligible, Rule $rule ): array {
		$subtotal = 0;
		$room     = 0;
		foreach ( $eligible as $b ) {
			$subtotal += $b->price * $b->count;
			$room     += ( $b->price - $b->floor ) * $b->count;
		}
		if ( $subtotal <= 0 || $subtotal < $rule->threshold || $room <= 0 ) {
			return array();
		}
		$want = $rule->bp > 0 ? self::pct( $subtotal, $rule->bp ) : min( $rule->fixed, $subtotal );
		$want = min( $want, $room );
		if ( $want <= 0 ) {
			return array();
		}
		$given = 0;
		$rems  = array();
		foreach ( $eligible as $idx => $b ) {
			$cap          = $b->price - $b->floor;
			$b->quota     = intdiv( $want * $cap, $room );
			$rems[ $idx ] = ( $want * $cap ) % $room;
			$given       += $b->quota * $b->count;
		}
		$left  = $want - $given;
		$order = array_keys( $eligible );
		usort(
			$order,
			static fn ( int $x, int $y ): int => array( $rems[ $y ], $eligible[ $x ]->line, $eligible[ $y ]->price ) <=> array( $rems[ $x ], $eligible[ $y ]->line, $eligible[ $x ]->price )
		);
		$new = array();
		foreach ( $order as $idx ) {
			$b     = $eligible[ $idx ];
			$quota = $b->quota;
			if ( $left > 0 && $rems[ $idx ] > 0 ) {
				$extra = min( $left, $b->count );
				$left -= $extra;
				$part  = $b->split( $extra );
				$part->discount( $rule, $quota + 1 );
				if ( $part !== $b ) {
					$new[] = $part;
					$b->discount( $rule, $quota );
				}
				continue;
			}
			$b->discount( $rule, $quota );
		}
		return $new;
	}

	/**
	 * Sum buckets back into lines.
	 *
	 * @param list<Line>   $lines   Lines.
	 * @param list<Bucket> $buckets Buckets.
	 */
	private static function collect( array $lines, array $buckets ): Result {
		$applied = array();
		$by_rule = array();
		foreach ( $buckets as $b ) {
			$key = $lines[ $b->line ]->key;
			foreach ( $b->applied as $rule_id => $per_unit ) {
				$amount                      = $per_unit * $b->count;
				$applied[ $key ][ $rule_id ] = ( $applied[ $key ][ $rule_id ] ?? 0 ) + $amount;
				$by_rule[ $rule_id ]         = ( $by_rule[ $rule_id ] ?? 0 ) + $amount;
			}
		}
		$out  = array();
		$sub  = 0;
		$disc = 0;
		foreach ( $lines as $line ) {
			if ( $line->qty <= 0 || $line->unit_cents < 0 ) {
				continue;
			}
			$mine = $applied[ $line->key ] ?? array();
			ksort( $mine );
			$base              = $line->unit_cents * $line->qty;
			$off               = (int) array_sum( $mine );
			$out[ $line->key ] = array(
				'base'     => $base,
				'discount' => $off,
				'total'    => $base - $off,
				'qty'      => $line->qty,
				'applied'  => $mine,
			);
			$sub              += $base;
			$disc             += $off;
		}
		ksort( $by_rule );
		return new Result( $out, $by_rule, $sub, $disc );
	}
}
