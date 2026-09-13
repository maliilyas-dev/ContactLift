<?php
/**
 * Plugin Name:       ContactLift Pro
 * Plugin URI:        https://m-ali.dev/contactlift
 * Description:       Page/post/product/category targeting, business hours, product-aware messages, callback requests and local click reports for ContactLift.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Requires Plugins:  contactlift
 * Author:            Muhammad Ali Ilyas
 * Author URI:        https://m-ali.dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contactlift-pro
 * Domain Path:       /languages
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CONTACTLIFT_PRO_VERSION', '1.0.0' );
define( 'CONTACTLIFT_PRO_REQUIRED_API_VERSION', 1 );
define( 'CONTACTLIFT_PRO_FILE', __FILE__ );
define( 'CONTACTLIFT_PRO_PATH', plugin_dir_path( __FILE__ ) );
define( 'CONTACTLIFT_PRO_URL', plugin_dir_url( __FILE__ ) );

require_once CONTACTLIFT_PRO_PATH . 'src/Support/autoload.php';

/**
 * Shows a plain dependency notice — never a fatal error, never an
 * auto-install — when the free plugin isn't active. WordPress 6.5+'s
 * native `Requires Plugins` header (above) already blocks *activating* Pro
 * without the free plugin; this covers the free plugin being deactivated
 * afterwards, or an incompatible version being present.
 */
function dependency_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'ContactLift Pro requires the free ContactLift plugin to be active.', 'contactlift-pro' )
	);
}

function incompatible_version_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'ContactLift Pro requires a newer version of the free ContactLift plugin. Please update it.', 'contactlift-pro' )
	);
}

register_activation_hook(
	__FILE__,
	static function ( $network_wide ): void {
		if ( $network_wide ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'ContactLift Pro does not support network-wide activation in this version. Activate it on individual sites instead.', 'contactlift-pro' ),
				esc_html__( 'ContactLift Pro', 'contactlift-pro' ),
				array( 'back_link' => true )
			);
		}

		if ( ! function_exists( '\ContactLift\meets_minimum_requirements' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'ContactLift Pro requires the free ContactLift plugin to be installed and active.', 'contactlift-pro' ),
				esc_html__( 'ContactLift Pro', 'contactlift-pro' ),
				array( 'back_link' => true )
			);
		}

		( new Storage\ProInstaller() )->install();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		// Deactivation stops Pro's own output and unschedules its cron jobs
		// while preserving every record — callbacks, stats, settings. The
		// free plugin keeps rendering its own saved default bar on its own;
		// nothing here needs to "restore" that, it never stopped.
		( new Storage\ProInstaller() )->unschedule_cron();
	}
);

add_action(
	'clift_loaded',
	static function ( int $api_version ): void {
		if ( $api_version < CONTACTLIFT_PRO_REQUIRED_API_VERSION ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\incompatible_version_notice' );
			return;
		}

		load_plugin_textdomain( 'contactlift-pro', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		Plugin::instance()->boot();
	}
);

add_action(
	'admin_init',
	static function (): void {
		if ( ! did_action( 'clift_loaded' ) && ! function_exists( '\ContactLift\meets_minimum_requirements' ) ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\dependency_notice' );
		}
	}
);
