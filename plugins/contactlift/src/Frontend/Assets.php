<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the frontend CSS/JS only on requests where a bar will actually
 * render — resolving eligibility is a cheap, side-effect-free options read,
 * so it's safe to call again here purely to decide whether to load assets;
 * Renderer's own dedupe flag still governs what actually gets output.
 */
final class Assets {

	private BarResolver $resolver;

	public function __construct( BarResolver $resolver ) {
		$this->resolver = $resolver;
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	public function maybe_enqueue(): void {
		if ( null === $this->resolver->resolve() ) {
			return;
		}

		wp_enqueue_style(
			'contactlift-frontend',
			CONTACTLIFT_URL . 'assets/css/frontend.css',
			array(),
			CONTACTLIFT_VERSION
		);

		wp_enqueue_script(
			'contactlift-frontend',
			CONTACTLIFT_URL . 'assets/js/frontend.js',
			array(),
			CONTACTLIFT_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
}
