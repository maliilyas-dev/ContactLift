<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Callbacks;

defined( 'ABSPATH' ) || exit;

/**
 * "Before enabling this channel, require a notification recipient and
 * privacy-policy URL" (spec). Checked at render time (Plugin::resolve_bar()
 * drops an unconfigured callback action the same way a closed+hidden action
 * is dropped) and again at the REST layer before accepting a submission —
 * an admin can save recipients and the privacy URL in either order, so this
 * is a readiness check, not a save-time requirement.
 */
final class CallbackReadiness {

	/**
	 * @param array<string,mixed> $pro_settings
	 */
	public static function is_configured( array $pro_settings ): bool {
		$recipients  = $pro_settings['callback_recipients'] ?? array();
		$privacy_url = trim( (string) ( $pro_settings['privacy_url'] ?? '' ) );

		return is_array( $recipients ) && array() !== $recipients && '' !== $privacy_url;
	}
}
