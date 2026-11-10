<?php
/**
 * Mutation testing for the property check: plant a known bug in a copy of the engine and confirm that comparing
 * with the reference implementation catches it. A check that misses planted bugs proves nothing when it passes.
 *
 * @package PricingRules
 */

namespace PricingRules\Evaluation;

/**
 * The mutants and a runner.
 */
final class Mutants {

	/**
	 * Name => [file relative to src, search, replace].
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function all(): array {
		return array(
			'round down instead of half up'   => array( 'Engine/PricingEngine.php', 'intdiv( $price * $bp + 5000, 10000 )', 'intdiv( $price * $bp, 10000 )' ),
			'BOGO discounts the dearest unit' => array( 'Engine/PricingEngine.php', 'array( $b->price, $a->line ) <=> array( $a->price, $b->line )', 'array( $a->price, $a->line ) <=> array( $b->price, $b->line )' ),
			'BOGO ignores the group limit'    => array( 'Engine/PricingEngine.php', '$end = $rule->limit > 0 ? min( $total, $rule->limit * $g ) : $total;', '$end = $total;' ),
			'floor not enforced'              => array( 'Engine/Bucket.php', 'min( $cents, $this->price - $this->floor )', 'min( $cents, $this->price )' ),
			'exclusive does not lock'         => array( 'Engine/Bucket.php', '$this->locked = true;', '$this->locked = false;' ),
			'cart leftover cents dropped'     => array( 'Engine/PricingEngine.php', 'if ( $left > 0 && $rems[ $idx ] > 0 ) {', 'if ( false ) {' ),
			'cart threshold uses >'           => array( 'Engine/PricingEngine.php', '$subtotal < $rule->threshold', '$subtotal <= $rule->threshold' ),
			'bulk counts all lines together'  => array( 'Engine/PricingEngine.php', "\$k           = 'rule' === \$rule->count_by ? 'all' : (string) \$b->line;", "\$k           = 'all';" ),
			'priority order reversed'         => array( 'Engine/PricingEngine.php', 'array( $a->priority, $a->id ) <=> array( $b->priority, $b->id )', 'array( $b->priority, $b->id ) <=> array( $a->priority, $a->id )' ),
			'rule end date inclusive'         => array( 'Engine/Rule.php', '$c->now >= $this->ends', '$c->now > $this->ends' ),
		);
	}

	/**
	 * Run every mutant against $cases random carts. Returns name => caught.
	 *
	 * @param string $src   Path to src/.
	 * @param int    $cases Random carts per mutant.
	 * @return array<string, bool>
	 */
	public static function run( string $src, int $cases ): array {
		$out = array();
		foreach ( self::all() as $name => [ $file, $search, $replace ] ) {
			$dir = sys_get_temp_dir() . '/pr-mutant-' . md5( $name );
			self::copy( $src, $dir );
			$code = (string) file_get_contents( $dir . '/' . $file );
			if ( ! str_contains( $code, $search ) ) {
				throw new \RuntimeException( "mutant '{$name}' no longer applies: update eval/Mutants.php" );
			}
			file_put_contents( $dir . '/' . $file, str_replace( $search, $replace, $code ) );
			$cmd          = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __DIR__ . '/check.php' ) . ' ' . escapeshellarg( $dir ) . ' ' . (int) $cases;
			$result       = (string) shell_exec( $cmd );
			$out[ $name ] = ! str_starts_with( trim( $result ), '0 ' );
		}
		return $out;
	}

	/**
	 * Recursive copy.
	 *
	 * @param string $from Source.
	 * @param string $to   Target.
	 */
	private static function copy( string $from, string $to ): void {
		if ( ! is_dir( $to ) ) {
			mkdir( $to, 0777, true );
		}
		foreach ( (array) scandir( $from ) as $f ) {
			if ( '.' === $f || '..' === $f ) {
				continue;
			}
			if ( is_dir( "$from/$f" ) ) {
				self::copy( "$from/$f", "$to/$f" );
			} else {
				copy( "$from/$f", "$to/$f" );
			}
		}
	}
}
