<?php
/**
 * Bootstrap for the fast unit suite: no WordPress boot. Domain classes only
 * call a handful of WP translation functions for user-facing error strings,
 * so those get a minimal polyfill here rather than pulling in WordPress.
 *
 * Every plugin file also guards against direct web access with
 * `defined( 'ABSPATH' ) || exit;` (a WordPress.org requirement) — harmless
 * on a real site, but it would silently kill this CLI run the moment the
 * autoloader pulled in the first class, so ABSPATH is defined here first.
 * The value itself is never used by anything under test.
 *
 * @package ContactLift
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
	// No listener is ever registered in this isolated context (nothing here
	// ever calls add_filter()), so returning $value unchanged is exactly
	// what real WordPress would do for a filter with zero callbacks.
	// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- polyfill matching WordPress's own apply_filters() signature; extra args are for real listeners, which never exist here.
	function apply_filters( string $tag, $value ) {
		return $value;
	}
}

if ( ! defined( 'CONTACTLIFT_PATH' ) ) {
	define( 'CONTACTLIFT_PATH', dirname( __DIR__, 2 ) . '/plugins/contactlift/' );
}

require_once CONTACTLIFT_PATH . 'src/Support/autoload.php';
