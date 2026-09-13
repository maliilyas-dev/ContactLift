<?php
/**
 * Bootstrap for the integration suite: boots the real WP test suite, then
 * loads the free plugin exactly as WordPress would (via muplugins_loaded),
 * from its real mounted path — not the repo-root mapping used to reach this
 * file itself. Runs only inside wp-env: `npm run test:integration`.
 *
 * @package ContactLift
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

		// Loaded too, when present, so WooCommerce-dependent integration
		// tests (the cart/checkout/account exclusion checks) run for real
		// instead of skipping — the isolated test database doesn't share
		// the dev site's active-plugins list.
		$woocommerce = '/var/www/html/wp-content/plugins/woocommerce.latest-stable/woocommerce.php';

		if ( is_readable( $woocommerce ) ) {
			require $woocommerce;
		}
	}
);

require $wp_tests_dir . '/includes/bootstrap.php';
