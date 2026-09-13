<?php
/**
 * Plugin Name:       ContactLift Smart Contact Bar
 * Plugin URI:        https://m-ali.dev/contactlift
 * Description:       A floating contact bar that helps visitors call, message, book or request a callback through the right channel for the page they're on.
 * Version:           1.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            Muhammad Ali Ilyas
 * Author URI:        https://m-ali.dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contactlift
 * Domain Path:       /languages
 *
 * @package ContactLift
 */

namespace ContactLift;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CONTACTLIFT_VERSION', '1.0.0' );
define( 'CONTACTLIFT_API_VERSION', 1 );
define( 'CONTACTLIFT_FILE', __FILE__ );
define( 'CONTACTLIFT_PATH', plugin_dir_path( __FILE__ ) );
define( 'CONTACTLIFT_URL', plugin_dir_url( __FILE__ ) );
define( 'CONTACTLIFT_MIN_PHP', '8.2' );
define( 'CONTACTLIFT_MIN_WP', '6.8' );

require_once CONTACTLIFT_PATH . 'src/Support/autoload.php';

/**
 * Whether the current host meets the plugin's minimum PHP/WordPress versions.
 * Plain-PHP so it is always safe to call, even on ancient hosts.
 */
function meets_minimum_requirements(): bool {
	global $wp_version;

	if ( version_compare( PHP_VERSION, CONTACTLIFT_MIN_PHP, '<' ) ) {
		return false;
	}

	if ( isset( $wp_version ) && version_compare( $wp_version, CONTACTLIFT_MIN_WP, '<' ) ) {
		return false;
	}

	return true;
}

/**
 * Shows an actionable admin notice instead of a fatal error on unsupported hosts.
 */
function requirements_notice(): void {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: minimum PHP version, 2: minimum WordPress version. */
				__( 'ContactLift requires PHP %1$s+ and WordPress %2$s+. Please upgrade your site to activate it.', 'contactlift' ),
				CONTACTLIFT_MIN_PHP,
				CONTACTLIFT_MIN_WP
			)
		)
	);
}

register_activation_hook(
	__FILE__,
	static function ( $network_wide ): void {
		if ( $network_wide ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html__( 'ContactLift does not support network-wide activation in this version. Activate it on individual sites instead.', 'contactlift' ),
				esc_html__( 'ContactLift', 'contactlift' ),
				array( 'back_link' => true )
			);
		}

		if ( ! meets_minimum_requirements() ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die(
				esc_html(
					sprintf(
						/* translators: 1: minimum PHP version, 2: minimum WordPress version. */
						__( 'ContactLift requires PHP %1$s+ and WordPress %2$s+.', 'contactlift' ),
						CONTACTLIFT_MIN_PHP,
						CONTACTLIFT_MIN_WP
					)
				),
				esc_html__( 'ContactLift', 'contactlift' ),
				array( 'back_link' => true )
			);
		}

		( new Storage\SettingsRepository() )->install();
	}
);

register_deactivation_hook(
	__FILE__,
	static function (): void {
		// Frontend output stops naturally (hooks aren't registered once deactivated).
		// The free plugin schedules no cron jobs, so there is nothing to unschedule here.
	}
);

add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! meets_minimum_requirements() ) {
			add_action( 'admin_notices', __NAMESPACE__ . '\\requirements_notice' );
			return;
		}

		// No load_plugin_textdomain() call: WordPress.org auto-loads translations
		// for plugins hosted there (since 4.6) as long as the text domain
		// matches the plugin slug, which it does here.
		Plugin::instance()->boot();
	}
);
