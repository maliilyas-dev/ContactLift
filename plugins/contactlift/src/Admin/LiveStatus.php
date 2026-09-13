<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Admin;

use ContactLift\Domain\BarState;
use ContactLift\Storage\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Whether a bar would actually render on the site right now — the same
 * "has a usable action" check BarResolver applies on the frontend, reused
 * here purely to label the admin status pill honestly.
 */
final class LiveStatus {

	public static function is_live( SettingsRepository $settings ): bool {
		if ( empty( $settings->get_settings()['enabled'] ) ) {
			return false;
		}

		$bar = $settings->get_default_bar();

		if ( ! $bar || empty( $bar['enabled'] ) ) {
			return false;
		}

		return BarState::has_renderable_action( $bar );
	}
}
