<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Frontend;

use ContactLift\Domain\BarState;
use ContactLift\Storage\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The spec's "Rule resolution" steps 1-3, scoped to what the free edition
 * can express: one bar, sitewide unless the current content ID is in its
 * (up to 5) excluded IDs. Pro's targeting/priority/schedule steps extend
 * this by hooking `clift_resolve_bar` — see the clift_loaded contract.
 */
final class BarResolver {

	private SettingsRepository $settings;
	private GlobalExclusions $exclusions;
	private PageContext $context;

	public function __construct( SettingsRepository $settings, ?GlobalExclusions $exclusions = null, ?PageContext $context = null ) {
		$this->settings   = $settings;
		$this->exclusions = $exclusions ?? new GlobalExclusions();
		$this->context    = $context ?? new PageContext();
	}

	/**
	 * Used by the [contactlift] shortcode: one inline placement, so there's
	 * no mobile/desktop pair to resolve independently — device is passed to
	 * the filter as null and never gate-checked here, exactly as before this
	 * class supported per-device resolution at all.
	 *
	 * @return array<string,mixed>|null The eligible bar, or null if nothing should render.
	 */
	public function resolve(): ?array {
		return $this->resolve_internal( null );
	}

	/**
	 * Used by the wp_footer floating output, once per device, since the
	 * server can't know the visitor's real viewport. Pro's targeting can
	 * therefore choose a different bar (or no bar) for mobile than for
	 * desktop — see Renderer::render_footer().
	 *
	 * @return array<string,mixed>|null
	 */
	public function resolve_for_device( string $device ): ?array {
		return $this->resolve_internal( $device );
	}

	private function resolve_internal( ?string $device ): ?array {
		if ( $this->exclusions->applies() ) {
			return null;
		}

		$settings = $this->settings->get_settings();
		if ( empty( $settings['enabled'] ) ) {
			return null;
		}

		$bar = $this->settings->get_default_bar();

		/**
		 * Lets Pro's targeting/schedule engine choose a different (or no) bar.
		 * Free ignores this filter entirely when Pro isn't active.
		 *
		 * @param array<string,mixed>|null $bar     The free edition's own resolution.
		 * @param PageContext               $context Current request context.
		 * @param string|null               $device  'mobile'/'desktop' for the floating
		 *                                            output, or null for the [contactlift]
		 *                                            shortcode's single inline placement.
		 */
		$bar = apply_filters( 'clift_resolve_bar', $bar, $this->context, $device );

		if ( ! is_array( $bar ) || empty( $bar['enabled'] ) ) {
			return null;
		}

		if ( null !== $device && ! in_array( $bar['device'] ?? 'all', array( 'all', $device ), true ) ) {
			return null;
		}

		if ( $this->is_content_excluded( $bar ) ) {
			return null;
		}

		if ( ! BarState::has_renderable_action( $bar ) ) {
			return null;
		}

		return $bar;
	}

	private function is_content_excluded( array $bar ): bool {
		$content_id = $this->context->current_content_id();

		if ( null === $content_id ) {
			return false;
		}

		$excluded = $bar['exclude']['content_ids'] ?? array();

		return in_array( $content_id, $excluded, true );
	}
}
