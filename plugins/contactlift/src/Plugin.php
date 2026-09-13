<?php
/**
 * @package ContactLift
 */

namespace ContactLift;

use ContactLift\Admin\Menu;
use ContactLift\Frontend\Assets;
use ContactLift\Frontend\BarResolver;
use ContactLift\Frontend\Renderer;
use ContactLift\Frontend\ShortcodeController;
use ContactLift\Storage\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Service wiring for the free plugin. Boots admin screens and the frontend
 * renderer, then fires clift_loaded so ContactLift Pro (a separate plugin)
 * can register its own features once it has confirmed this API version is
 * compatible.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private SettingsRepository $settings;
	private ?Renderer $renderer = null;

	private function __construct() {
		$this->settings = new SettingsRepository();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function settings(): SettingsRepository {
		return $this->settings;
	}

	/**
	 * The one shared Renderer instance for this request, so the wp_footer
	 * auto-output and the [contactlift] shortcode dedupe against each other.
	 */
	public function renderer(): Renderer {
		if ( null === $this->renderer ) {
			$this->renderer = new Renderer( new BarResolver( $this->settings ) );
		}

		return $this->renderer;
	}

	public function boot(): void {
		if ( is_admin() ) {
			( new Menu( $this->settings ) )->register();
		} else {
			$this->boot_frontend();
		}

		/**
		 * Fires once the free plugin has finished booting.
		 *
		 * @param int $api_version ContactLift's extension API version. Pro
		 *                         checks this before registering anything.
		 */
		do_action( 'clift_loaded', CONTACTLIFT_API_VERSION );
	}

	private function boot_frontend(): void {
		( new Assets( new BarResolver( $this->settings ) ) )->register();
		( new ShortcodeController( $this->renderer() ) )->register();

		add_action( 'wp_footer', array( $this->renderer(), 'render_footer' ) );
	}
}
