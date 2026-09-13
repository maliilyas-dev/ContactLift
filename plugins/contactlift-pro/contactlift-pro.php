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
 * ---------------------------------------------------------------------
 * Freemius SDK — guarded adapter.
 *
 * Per the build spec: "Without real product IDs and dashboard-generated
 * integration values, leave the official SDK behind a guarded adapter...
 * Never fabricate keys or claim a live checkout passed." The two
 * constants below are placeholders, not real (if wrong) credentials —
 * everything in this block is skipped entirely, with a plain admin-only
 * notice, until an owner replaces them. See docs/owner-setup.md for the
 * exact dashboard steps. Once real values are in place, delete this
 * comment and the is-placeholder guard is simply never true again.
 *
 * The SDK itself is not a Composer package: download it from the
 * Freemius dashboard (Product -> Integration -> Download SDK) and place
 * the unzipped contents at vendor/freemius/, so vendor/freemius/start.php
 * exists — same as FormBridge for ActiveCampaign Pro's own integration.
 * ---------------------------------------------------------------------
 */
const FREEMIUS_PLACEHOLDER = 'REPLACE_WITH_REAL_FREEMIUS_PRODUCT_ID';
const FREEMIUS_PRODUCT_ID  = FREEMIUS_PLACEHOLDER;
const FREEMIUS_PUBLIC_KEY  = 'REPLACE_WITH_REAL_FREEMIUS_PUBLIC_KEY';

/**
 * Freemius instance accessor, following Freemius's own required pattern
 * (a global-backed singleton function, not a class) so the SDK can load
 * itself as early as possible.
 *
 * @return \Freemius|null Null while the guarded placeholder above hasn't
 *                         been replaced, or the SDK isn't present yet.
 */
function clift_fs() {
	static $fs        = null;
	static $attempted = false;

	if ( $attempted ) {
		return $fs;
	}

	$attempted = true;

	if ( FREEMIUS_PLACEHOLDER === FREEMIUS_PRODUCT_ID ) {
		return null;
	}

	$sdk = CONTACTLIFT_PRO_PATH . 'vendor/freemius/start.php';

	if ( ! is_readable( $sdk ) ) {
		return null;
	}

	require_once $sdk;

	$fs = fs_dynamic_init(
		array(
			'id'                  => FREEMIUS_PRODUCT_ID,
			'slug'                => 'contactlift-pro',
			'type'                => 'plugin',
			'public_key'          => FREEMIUS_PUBLIC_KEY,
			'is_premium'          => true,
			'has_premium_version' => true,
			'has_addons'          => false,
			'has_paid_plans'      => true,
			'is_org_compliant'    => true,
			'menu'                => array(
				'slug'    => 'contactlift-pro',
				'support' => false,
				'parent'  => array(
					// The free plugin's own top-level menu slug (Menu::SLUG
					// in the free plugin's src/Admin/Menu.php) — keeps
					// Account/Upgrade discoverable under "ContactLift"
					// instead of a second top-level menu.
					'slug' => 'contactlift',
				),
			),
		)
	);

	do_action( 'clift_pro_fs_loaded' );

	return $fs;
}

if ( FREEMIUS_PLACEHOLDER === FREEMIUS_PRODUCT_ID ) {
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			printf(
				'<div class="notice notice-info"><p>%s</p></div>',
				esc_html__( 'ContactLift Pro: Freemius licensing is not configured yet. Local features work normally; add your product ID and public key in contactlift-pro.php to enable Account/Upgrade screens.', 'contactlift-pro' )
			);
		}
	);
} else {
	clift_fs();

	/**
	 * Freemius's post-activation redirect (and some of its own
	 * account/upgrade links) point at the bare menu slug rather than the
	 * actual registered page ("contactlift-pro-account"). Freemius never
	 * registers anything at that bare slug itself, so WordPress's own
	 * capability gate rejects it with "Sorry, you are not allowed to
	 * access this page" — confirmed on the FormBridge sibling project by
	 * reproducing it directly, not assumed. Fixed the same way there: the
	 * bare slug is registered as a real (but hidden) page, and the actual
	 * redirect happens on admin_init, before any admin page output starts
	 * — a submenu page callback runs too late, after headers are sent.
	 */
	add_action(
		'admin_menu',
		static function (): void {
			add_submenu_page(
				'contactlift',
				'ContactLift Pro',
				'ContactLift Pro',
				'manage_options',
				'contactlift-pro',
				'__return_null'
			);
		},
		999
	);

	add_action(
		'admin_init',
		static function (): void {
			if ( isset( $_GET['page'] ) && 'contactlift-pro' === $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only redirect target check, no state changes.
				wp_safe_redirect( admin_url( 'admin.php?page=contactlift-pro-account' ) );
				exit;
			}
		}
	);

	add_action(
		'admin_head',
		static function (): void {
			echo '<style>#adminmenu a[href$="page=contactlift-pro"]{display:none!important}</style>';
		}
	);
}

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
