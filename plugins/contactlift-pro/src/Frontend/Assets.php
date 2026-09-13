<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Frontend;

use ContactLift\Frontend\BarResolver;
use ContactLift\Frontend\PageContext;
use ContactLiftPro\Storage\ProSettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * "Load callback code only when needed" (spec, Performance budget) — each
 * script here is enqueued only under the specific condition it's actually
 * for: callback.js when the resolved bar has a callback action, tracking.js
 * when reporting is switched on for a real (non-admin) visitor.
 */
final class Assets {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	public function maybe_enqueue(): void {
		$resolver = new BarResolver( \ContactLift\Plugin::instance()->settings() );
		$bar      = $resolver->resolve();

		if ( is_array( $bar ) && $this->has_callback_action( $bar ) ) {
			$this->enqueue_callback();
		}

		$this->maybe_enqueue_tracking();
	}

	private function enqueue_callback(): void {
		wp_enqueue_style(
			'contactlift-pro-callback',
			CONTACTLIFT_PRO_URL . 'assets/css/callback.css',
			array(),
			CONTACTLIFT_PRO_VERSION
		);

		wp_enqueue_script(
			'contactlift-pro-callback',
			CONTACTLIFT_PRO_URL . 'assets/js/callback.js',
			array(),
			CONTACTLIFT_PRO_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'contactlift-pro-callback',
			'clift_cb_i18n',
			array(
				'received' => __( 'Request received.', 'contactlift-pro' ),
				'error'    => __( 'Something went wrong. Please try again.', 'contactlift-pro' ),
			)
		);
	}

	/**
	 * "Exclude administrator previews and administrators by default" (spec)
	 * — skipped here at the source, not just server-side at /events, so an
	 * administrator's own browsing never even loads the tracking script.
	 */
	private function maybe_enqueue_tracking(): void {
		$settings = ( new ProSettingsRepository() )->get_settings();

		if ( 'off' === ( $settings['analytics_mode'] ?? 'off' ) || current_user_can( 'manage_options' ) ) {
			return;
		}

		$context = new PageContext();

		wp_enqueue_script(
			'contactlift-pro-tracking',
			CONTACTLIFT_PRO_URL . 'assets/js/tracking.js',
			array(),
			CONTACTLIFT_PRO_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'contactlift-pro-tracking',
			'clift_tracking_i18n',
			array(
				'restUrl'    => rest_url( 'contactlift/v1/' ),
				'mode'       => $settings['analytics_mode'],
				'sourceType' => $context->source_type(),
				'sourceId'   => $context->current_content_id(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $bar
	 */
	private function has_callback_action( array $bar ): bool {
		foreach ( $bar['actions'] ?? array() as $action ) {
			if ( 'callback' === ( $action['type'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}
}
