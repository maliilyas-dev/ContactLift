<?php
/**
 * Bootstrap for ContactLift Pro's fast unit suite — same no-WP-boot
 * approach as the free plugin's tests/unit/bootstrap.php, plus the free
 * plugin's own autoloader, since Pro's domain code uses free's
 * ValidationException directly (exactly as it would at runtime, where the
 * free plugin is always loaded first).
 *
 * @package ContactLiftPro
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- exact constant name every plugin file's access guard checks for.
}

if ( ! function_exists( '__' ) ) {
	// phpcs:ignore WordPress.WP.I18n, WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- polyfill matching WordPress's own __() signature exactly, not real i18n.
	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}
}

if ( ! function_exists( 'apply_filters' ) ) {
	// No listener is ever registered in this isolated context, so returning
	// $value unchanged is exactly what real WordPress would do for a filter
	// with zero callbacks.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- polyfill matching WordPress's own apply_filters() signature; extra args are for real listeners, which never exist here.
	function apply_filters( string $tag, $value ) {
		return $value;
	}
}

if ( ! defined( 'CONTACTLIFT_PATH' ) ) {
	define( 'CONTACTLIFT_PATH', dirname( __DIR__, 2 ) . '/plugins/contactlift/' );
}

if ( ! defined( 'CONTACTLIFT_PRO_PATH' ) ) {
	define( 'CONTACTLIFT_PRO_PATH', dirname( __DIR__, 2 ) . '/plugins/contactlift-pro/' );
}

require_once CONTACTLIFT_PATH . 'src/Support/autoload.php';
require_once CONTACTLIFT_PRO_PATH . 'src/Support/autoload.php';
