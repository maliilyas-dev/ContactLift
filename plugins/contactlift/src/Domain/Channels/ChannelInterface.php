<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain\Channels;

use ContactLift\Domain\ValidationException;

defined( 'ABSPATH' ) || exit;

/**
 * One entry in the allowlisted channel registry. Each channel owns
 * validation and URL-generation for its own fields — the rest of the
 * codebase never builds a tel:/wa.me/mailto: URL by hand.
 */
interface ChannelInterface {

	/**
	 * The action "type" value this channel handles, e.g. "call".
	 */
	public function type(): string;

	/**
	 * Validate and normalize the channel-owned fields of a submitted action.
	 * Common envelope fields (id, label, enabled, closed_behavior, ...) are
	 * validated elsewhere; this only sees/returns this channel's own keys.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 * @throws ValidationException
	 */
	public function validate( array $raw ): array;

	/**
	 * Build the final destination URL/URI for a validated action.
	 *
	 * @param array<string,mixed> $action          Normalized action (channel fields already validated).
	 * @param string               $resolved_message Plain-text, token-resolved message (already length-capped), empty if not applicable.
	 */
	public function build_url( array $action, string $resolved_message = '' ): string;
}
