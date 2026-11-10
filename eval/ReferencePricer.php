<?php
/**
 * The slow, obvious implementation: one array entry per unit. Used only to check the engine.
 *
 * @package PricingRules
 */

namespace PricingRules\Evaluation;

use PricingRules\Engine\Context;
use PricingRules\Engine\Line;
use PricingRules\Engine\Rule;

/**
 * Unit-by-unit pricing with the same semantics as PricingEngine. It deliberately shares no code with the engine
 * (its own rounding, floor, ordering, scheduling and matching), so a bug in one cannot hide in both.
 */
final class ReferencePricer {

	/**
	 * Half-up basis points, written differently from the engine on purpose.
	 *
	 * @param int $price Price.
	 * @param int $bp    Basis points.
	 */
	private static function pct( int $price, int $bp ): int {
		$exact = $price * $bp;
		$whole = intdiv( $exact, 10000 );
		return ( $exact - $whole * 10000 ) * 2 >= 10000 ? $whole + 1 : $whole;
	}

	/**
	 * Floor price: the smallest p with p >= base * (1 - cap).
	 *
	 * @param int $base   Base.
	 * @param int $cap_bp Cap.
	 */
	private static function floor_of( int $base, int $cap_bp ): int {
		$keep = 10000 - max( 0, min( 10000, $cap_bp ) );
		$p    = intdiv( $base * $keep, 10000 );
		return $p * 10000 < $base * $keep ? $p + 1 : $p;
	}

	/**
	 * Whether a rule applies now to these roles.
	 *
	 * @param Rule    $r   Rule.
	 * @param Context $ctx Context.
	 */
	private static function on( Rule $r, Context $ctx ): bool {
		$started = 0 === $r->starts || $ctx->now >= $r->starts;
		$running = 0 === $r->ends || $ctx->now < $r->ends;
		$role_ok = array() === $r->roles || count( array_intersect( $r->roles, $ctx->roles ) ) > 0;
		return $started && $running && $role_ok;
	}

	/**
	 * Whether a line is in a rule's scope.
	 *
	 * @param Rule $r    Rule.
	 * @param Line $line Line.
	 */
	private static function in_scope( Rule $r, Line $line ): bool {
		if ( array() === $r->product_ids && array() === $r->category_ids ) {
			return true;
		}
		foreach ( $r->category_ids as $c ) {
			if ( in_array( $c, $line->category_ids, true ) ) {
				return true;
			}
		}
		return in_array( $line->product_id, $r->product_ids, true );
	}

	/**
	 * Tier reached by a quantity.
	 *
	 * @param Rule $r   Rule.
	 * @param int  $qty Quantity.
	 */
	private static function tier( Rule $r, int $qty ): int {
		$best = -1;
		$bp   = 0;
		foreach ( $r->tiers as $t ) {
			if ( $qty >= $t['min'] && $t['min'] > $best ) {
				$best = $t['min'];
				$bp   = $t['bp'];
			}
		}
		return $bp;
	}

	/**
	 * Price a cart.
	 *
	 * @param list<Line> $lines Lines.
	 * @param list<Rule> $rules Rules.
	 * @param Context    $ctx   Context.
	 * @return array<string, array{total: int, applied: array<int, int>}>
	 */
	public static function price( array $lines, array $rules, Context $ctx ): array {
		$units = array();
		foreach ( $lines as $i => $l ) {
			if ( $l->qty <= 0 || $l->unit_cents < 0 ) {
				continue;
			}
			for ( $q = 0; $q < $l->qty; $q++ ) {
				$units[] = array(
					'line'    => $i,
					'price'   => $l->unit_cents,
					'floor'   => self::floor_of( $l->unit_cents, $ctx->cap_bp ),
					'locked'  => false,
					'applied' => array(),
				);
			}
		}
		$give = static function ( array &$u, Rule $r, int $cents ): void {
			$d = max( 0, min( $cents, $u['price'] - $u['floor'] ) );
			if ( $d > 0 ) {
				$u['price']            -= $d;
				$u['applied'][ $r->id ] = ( $u['applied'][ $r->id ] ?? 0 ) + $d;
				$u['locked']            = $u['locked'] || $r->exclusive;
			}
		};

		$sorted = $rules;
		usort(
			$sorted,
			static function ( Rule $a, Rule $b ): int {
				if ( $a->priority !== $b->priority ) {
					return $a->priority < $b->priority ? -1 : 1;
				}
				return $a->id - $b->id;
			}
		);
		foreach ( $sorted as $r ) {
			if ( ! self::on( $r, $ctx ) ) {
				continue;
			}
			$el = array();
			foreach ( $units as $k => $u ) {
				if ( ! $u['locked'] && self::in_scope( $r, $lines[ $u['line'] ] ) ) {
					$el[] = $k;
				}
			}
			if ( ! $el ) {
				continue;
			}
			if ( 'sale' === $r->type ) {
				foreach ( $el as $k ) {
					$give( $units[ $k ], $r, self::pct( $units[ $k ]['price'], $r->bp ) );
				}
			} elseif ( 'bulk' === $r->type ) {
				$count = array();
				foreach ( $el as $k ) {
					$key           = 'rule' === $r->count_by ? 'all' : (string) $units[ $k ]['line'];
					$count[ $key ] = ( $count[ $key ] ?? 0 ) + 1;
				}
				foreach ( $el as $k ) {
					$bp = self::tier( $r, $count[ 'rule' === $r->count_by ? 'all' : (string) $units[ $k ]['line'] ] );
					$give( $units[ $k ], $r, self::pct( $units[ $k ]['price'], $bp ) );
				}
			} elseif ( 'bogo' === $r->type ) {
				usort( $el, static fn ( int $a, int $b ): int => array( $units[ $b ]['price'], $units[ $a ]['line'], $a ) <=> array( $units[ $a ]['price'], $units[ $b ]['line'], $b ) );
				$g   = $r->buy + $r->get;
				$end = $r->limit > 0 ? min( count( $el ), $r->limit * $g ) : count( $el );
				for ( $pos = 0; $pos < $end; $pos++ ) {
					if ( $pos % $g >= $r->buy ) {
						$k = $el[ $pos ];
						$give( $units[ $k ], $r, self::pct( $units[ $k ]['price'], $r->get_bp ) );
					}
				}
			} elseif ( 'cart' === $r->type ) {
				$sub  = 0;
				$room = 0;
				foreach ( $el as $k ) {
					$sub  += $units[ $k ]['price'];
					$room += $units[ $k ]['price'] - $units[ $k ]['floor'];
				}
				if ( $sub <= 0 || $sub < $r->threshold || $room <= 0 ) {
					continue;
				}
				$want = min( $r->bp > 0 ? self::pct( $sub, $r->bp ) : min( $r->fixed, $sub ), $room );
				if ( $want <= 0 ) {
					continue;
				}
				$quota = array();
				$rem   = array();
				$left  = $want;
				foreach ( $el as $k ) {
					$cap         = $units[ $k ]['price'] - $units[ $k ]['floor'];
					$quota[ $k ] = intdiv( $want * $cap, $room );
					$rem[ $k ]   = ( $want * $cap ) % $room;
					$left       -= $quota[ $k ];
				}
				$order = $el;
				usort( $order, static fn ( int $a, int $b ): int => array( $rem[ $b ], $units[ $a ]['line'], $units[ $b ]['price'], $a ) <=> array( $rem[ $a ], $units[ $b ]['line'], $units[ $a ]['price'], $b ) );
				foreach ( $order as $k ) {
					$extra = ( $left > 0 && $rem[ $k ] > 0 ) ? 1 : 0;
					$left -= $extra;
					$give( $units[ $k ], $r, $quota[ $k ] + $extra );
				}
			}
		}

		$out = array();
		foreach ( $lines as $i => $l ) {
			if ( $l->qty <= 0 || $l->unit_cents < 0 ) {
				continue;
			}
			$out[ $l->key ] = array(
				'total'   => 0,
				'applied' => array(),
			);
		}
		foreach ( $units as $u ) {
			$key = $lines[ $u['line'] ]->key;
			if ( ! isset( $out[ $key ] ) ) {
				continue;
			}
			$out[ $key ]['total'] += $u['price'];
			foreach ( $u['applied'] as $id => $d ) {
				$out[ $key ]['applied'][ $id ] = ( $out[ $key ]['applied'][ $id ] ?? 0 ) + $d;
			}
		}
		foreach ( $out as $k => $o ) {
			ksort( $out[ $k ]['applied'] );
		}
		return $out;
	}
}
