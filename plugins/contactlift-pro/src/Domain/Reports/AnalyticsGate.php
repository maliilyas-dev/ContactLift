<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Reports;

defined( 'ABSPATH' ) || exit;

/**
 * "Keep reporting off by default... Off, Consent required and Local
 * aggregate mode... Respect a detected Global Privacy Control signal by
 * disabling analytics... Exclude administrator previews and administrators
 * by default." (spec). Pure decision logic — AT19 exercises this directly;
 * the REST layer supplies the three real-world inputs (settings, the GPC
 * header, current-user capability).
 */
final class AnalyticsGate {

	/**
	 * Consent-required mode's own client-side gate (whether
	 * window.ContactLift.setAnalyticsConsent(true) was called) is enforced
	 * by the visitor's browser deciding whether to send an event at all —
	 * the server side of that mode has nothing further to check beyond
	 * "is reporting on," which is exactly what this covers.
	 */
	public static function is_recording_allowed( string $analytics_mode, bool $global_privacy_control, bool $is_administrator ): bool {
		if ( 'off' === $analytics_mode ) {
			return false;
		}

		if ( $global_privacy_control ) {
			return false;
		}

		if ( $is_administrator ) {
			return false;
		}

		return in_array( $analytics_mode, array( 'consent_required', 'local_aggregate' ), true );
	}
}
