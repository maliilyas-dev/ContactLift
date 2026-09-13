<?php
/**
 * Minimal PSR-4 autoloader for the ContactLift\ namespace.
 *
 * No production Composer dependency is bundled, so releases don't carry a
 * vendor/autoload.php — this keeps the shipped ZIP small and avoids
 * autoloader collisions with other plugins on the same site.
 *
 * @package ContactLift
 */

namespace ContactLift;

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = CONTACTLIFT_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);
