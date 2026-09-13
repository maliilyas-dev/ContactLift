<?php
/**
 * Minimal PSR-4 autoloader for the ContactLiftPro\ namespace. Mirrors the
 * free plugin's own autoload.php exactly — same reasoning: no bundled
 * vendor/autoload.php, small release zip, no autoloader collisions.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro;

defined( 'ABSPATH' ) || exit;

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';

		if ( 0 !== strpos( $class_name, $prefix ) ) {
			return;
		}

		$relative = substr( $class_name, strlen( $prefix ) );
		$path     = CONTACTLIFT_PRO_PATH . 'src/' . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);
