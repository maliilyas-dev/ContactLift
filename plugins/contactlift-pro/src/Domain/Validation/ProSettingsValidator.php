<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Validation;

use ContactLift\Domain\Text;
use ContactLift\Domain\ValidationException;

defined( 'ABSPATH' ) || exit;

/**
 * clift_pro_settings: analytics mode, callback recipients/privacy URL,
 * retention windows, consent copy/version, and the rate-limit thresholds
 * the spec requires to be "administrator-configurable." Whether callback
 * is actually *usable* (recipient + privacy URL both present) is a
 * separate readiness check — see Frontend\CallbackReadiness — since an
 * admin may legitimately save one before the other.
 */
final class ProSettingsValidator {

	private const MAX_RECIPIENTS             = 10;
	private const MAX_CONSENT_LENGTH         = 5000;
	private const CALLBACK_RETENTION_CHOICES = array( 30, 90, 180 );
	private const STATS_RETENTION_CHOICES    = array( 30, 90 );
	private const MIN_RATE_LIMIT             = 1;
	private const MAX_RATE_LIMIT             = 100000;

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 * @throws ValidationException
	 */
	public function validate( array $raw ): array {
		$errors = array();

		$analytics_mode = (string) ( $raw['analytics_mode'] ?? 'off' );
		if ( ! in_array( $analytics_mode, array( 'off', 'consent_required', 'local_aggregate' ), true ) ) {
			$analytics_mode = 'off';
		}

		$recipients = array();
		foreach ( (array) ( $raw['callback_recipients'] ?? array() ) as $email ) {
			$email = trim( (string) $email );

			if ( '' === $email ) {
				continue;
			}

			if ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$errors['callback_recipients'] = __( 'Enter valid email addresses only.', 'contactlift-pro' );
				continue;
			}

			$recipients[] = $email;
		}
		$recipients = array_slice( array_values( array_unique( $recipients ) ), 0, self::MAX_RECIPIENTS );

		$privacy_url = trim( (string) ( $raw['privacy_url'] ?? '' ) );
		if ( '' !== $privacy_url ) {
			// Plain parse_url(), not wp_parse_url(): keeps this validator
			// unit-testable without booting WordPress, matching
			// Domain\Channels\LinkChannel's own reasoning for the same choice.
			$parts = parse_url( $privacy_url ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- see comment above.
			if ( ! $parts || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) || empty( $parts['host'] ) ) {
				$errors['privacy_url'] = __( 'Enter a valid web address.', 'contactlift-pro' );
				$privacy_url           = '';
			}
		}

		$callback_retention_days = $this->retention_choice( (int) ( $raw['callback_retention_days'] ?? 90 ), self::CALLBACK_RETENTION_CHOICES );
		$stats_retention_days    = $this->retention_choice( (int) ( $raw['stats_retention_days'] ?? 90 ), self::STATS_RETENTION_CHOICES );

		$consent_copy    = Text::truncate( trim( (string) ( $raw['consent_copy'] ?? '' ) ), self::MAX_CONSENT_LENGTH );
		$consent_version = max( 1, (int) ( $raw['consent_version'] ?? 1 ) );

		$rate_limit_per_10min = $this->clamp_int( $raw['rate_limit_per_10min'] ?? 5, self::MIN_RATE_LIMIT, self::MAX_RATE_LIMIT );
		$rate_limit_per_hour  = $this->clamp_int( $raw['rate_limit_per_hour'] ?? 100, self::MIN_RATE_LIMIT, self::MAX_RATE_LIMIT );

		if ( $errors ) {
			throw new ValidationException( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field messages are esc_html()'d by the admin screen that catches this, never echoed here.
		}

		return array(
			'schema_version'           => 1,
			'analytics_mode'           => $analytics_mode,
			'callback_retention_days'  => $callback_retention_days,
			'stats_retention_days'     => $stats_retention_days,
			'consent_copy'             => $consent_copy,
			'consent_version'          => $consent_version,
			'callback_recipients'      => $recipients,
			'privacy_url'              => $privacy_url,
			'rate_limit_per_10min'     => $rate_limit_per_10min,
			'rate_limit_per_hour'      => $rate_limit_per_hour,
			'delete_data_on_uninstall' => ! empty( $raw['delete_data_on_uninstall'] ),
		);
	}

	private function clamp_int( $value, int $min, int $max ): int {
		return max( $min, min( $max, (int) $value ) );
	}

	/**
	 * "Allow 30, 90 or 180 days for callbacks; 30 or 90 for aggregates"
	 * (spec) — a fixed enum, not a free-form range. An out-of-set value
	 * (only reachable via a tampered request; the admin field is a select)
	 * falls back to 90, matching how every other enum-like field here
	 * (analytics_mode, device) coerces to a safe default rather than erroring.
	 *
	 * @param int[] $choices
	 */
	private function retention_choice( int $value, array $choices ): int {
		return in_array( $value, $choices, true ) ? $value : 90;
	}
}
