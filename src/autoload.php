<?php
/**
 * PSR-4 autoloader for the PricingRules namespace, so the plugin needs no Composer at runtime.
 *
 * @package PricingRules
 */

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'PricingRules\\';
		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}
		$file = __DIR__ . '/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);
