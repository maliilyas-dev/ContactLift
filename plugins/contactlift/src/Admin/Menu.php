<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Admin;

use ContactLift\Storage\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Registers the top-level "ContactLift" menu and its screens: Bars (the
 * landing page), a Bar editor reachable only via an "Edit" link, Settings
 * and Tools. Pro adds Callback requests / Reports / Account through the
 * clift_admin_tabs filter rather than this class knowing about them.
 */
final class Menu {

	private const CAPABILITY  = 'manage_options';
	private const SLUG        = 'contactlift';
	private const EDITOR_SLUG = 'contactlift-bar-editor';

	private SettingsRepository $settings;
	private BarsPage $bars_page;
	private BarEditorPage $bar_editor_page;
	private SettingsPage $settings_page;
	private ToolsPage $tools_page;

	public function __construct( SettingsRepository $settings ) {
		$this->settings        = $settings;
		$this->bars_page       = new BarsPage( $settings );
		$this->bar_editor_page = new BarEditorPage( $settings );
		$this->settings_page   = new SettingsPage( $settings );
		$this->tools_page      = new ToolsPage( $settings );
	}

	public function register(): void {
		( new AdminAssets() )->register();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_clift_save_settings', array( $this->settings_page, 'handle_save' ) );
		add_action( 'admin_post_clift_save_bar', array( $this->bar_editor_page, 'handle_save' ) );

		// The bar editor is registered as an ordinary submenu (so WordPress's
		// own title/capability lookups for admin.php?page=... work exactly
		// like every other screen here) and then hidden from the sidebar with
		// a single rule everywhere in wp-admin, not just on ContactLift's own
		// pages — the nav sidebar persists across every admin screen.
		// remove_submenu_page() looks like the "correct" API for this, but it
		// also strips the entry get_admin_page_title() and
		// user_can_access_admin_page() rely on, breaking direct access to the
		// very page it's supposed to just hide from navigation. A `null`
		// parent has the same problem: get_admin_page_title() can't resolve a
		// page title for a parent-less page and passes null into
		// strip_tags(), a PHP 8.1+ deprecation.
		add_action( 'admin_head', array( $this, 'hide_bar_editor_from_nav' ) );
	}

	public function register_menu(): void {
		add_menu_page(
			__( 'ContactLift', 'contactlift' ),
			__( 'ContactLift', 'contactlift' ),
			self::CAPABILITY,
			self::SLUG,
			array( $this->bars_page, 'render' ),
			'dashicons-phone',
			58
		);

		add_submenu_page( self::SLUG, __( 'Bars', 'contactlift' ), __( 'Bars', 'contactlift' ), self::CAPABILITY, self::SLUG, array( $this->bars_page, 'render' ) );

		add_submenu_page( self::SLUG, __( 'Edit bar', 'contactlift' ), __( 'Edit bar', 'contactlift' ), self::CAPABILITY, self::EDITOR_SLUG, array( $this->bar_editor_page, 'render' ) );

		add_submenu_page( self::SLUG, __( 'Settings', 'contactlift' ), __( 'Settings', 'contactlift' ), self::CAPABILITY, self::SLUG . '-settings', array( $this->settings_page, 'render' ) );

		add_submenu_page( self::SLUG, __( 'Tools', 'contactlift' ), __( 'Tools', 'contactlift' ), self::CAPABILITY, self::SLUG . '-tools', array( $this->tools_page, 'render' ) );
	}

	public function hide_bar_editor_from_nav(): void {
		printf(
			'<style>#adminmenu a[href$="page=%s"]{display:none}</style>',
			esc_attr( self::EDITOR_SLUG )
		);
	}
}
