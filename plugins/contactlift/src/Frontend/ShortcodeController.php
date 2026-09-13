<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * [contactlift] for manual placement. Renderer's own $rendered flag handles
 * both "deduplicate repeated shortcodes" and "the first valid inline
 * placement suppresses the floating instance" — this class just wires the
 * shortcode tag to it.
 */
final class ShortcodeController {

	private Renderer $renderer;

	public function __construct( Renderer $renderer ) {
		$this->renderer = $renderer;
	}

	public function register(): void {
		add_shortcode( 'contactlift', array( $this, 'render' ) );
	}

	public function render(): string {
		return $this->renderer->render_inline();
	}
}
