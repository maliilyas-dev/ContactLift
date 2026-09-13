<?php
/**
 * Bootstrap for the Pro integration suite: boots the real WP test suite,
 * then loads both the free plugin and ContactLift Pro exactly as WordPress
 * would (via muplugins_loaded) — proving the clift_resolve_bar wiring runs
 * for real, not just against each plugin's own isolated unit tests. Runs
 * only inside wp-env: `npm run test:integration:pro`.
 *
 * @package ContactLiftPro
 */

$wp_tests_dir = getenv( 'WP_TESTS_DIR' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- local to this bootstrap script, not a plugin-wide global.

if ( ! $wp_tests_dir ) {
	$wp_tests_dir = '/tmp/wordpress-tests-lib'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- local to this bootstrap script, not a plugin-wide global.
}

if ( ! defined( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH' ) ) {
	define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', dirname( __DIR__, 2 ) . '/vendor/yoast/phpunit-polyfills' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- exact constant name the WP core test suite itself requires.
}

require_once $wp_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require '/var/www/html/wp-content/plugins/contactlift/contactlift.php';
		require '/var/www/html/wp-content/plugins/contactlift-pro/contactlift-pro.php';
	}
);

require $wp_tests_dir . '/includes/bootstrap.php';
