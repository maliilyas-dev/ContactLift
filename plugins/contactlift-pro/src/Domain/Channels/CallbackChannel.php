<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Channels;

use ContactLift\Domain\Channels\ChannelInterface;

defined( 'ABSPATH' ) || exit;

/**
 * "Label and selected callback settings" (spec, channel table) — unlike
 * every other channel, a callback action has no per-action destination of
 * its own; whether it can actually notify anyone depends on the sitewide
 * recipient/privacy-policy settings in clift_pro_settings, checked at
 * render time by Frontend\CallbackReadiness, not here. This channel exists
 * mainly to satisfy the shared action schema and the allowlisted-registry
 * pattern every other channel already follows.
 */
final class CallbackChannel implements ChannelInterface {

	/**
	 * A fixed, non-empty sentinel — BarValidator::validate_action() requires
	 * a non-empty "destination" before an action can be enabled, a rule
	 * written for link-shaped channels. It has no meaning here and is never
	 * rendered as a real href: Renderer special-cases type "callback" to
	 * open the request dialog instead of following a link.
	 */
	private const DESTINATION = 'callback';

	public function type(): string {
		return 'callback';
	}

	public function validate( array $raw ): array {
		return array(
			'destination' => self::DESTINATION,
		);
	}

	public function build_url( array $action, string $resolved_message = '' ): string {
		return '#clift-callback';
	}
}
