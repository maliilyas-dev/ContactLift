<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain\Channels;

use ContactLift\Domain\ValidationException;

defined( 'ABSPATH' ) || exit;

final class CallChannel implements ChannelInterface {

	public function type(): string {
		return 'call';
	}

	public function validate( array $raw ): array {
		$destination = trim( (string) ( $raw['destination'] ?? '' ) );

		if ( '' === $destination ) {
			return array( 'destination' => '' );
		}

		$normalized = PhoneNumber::normalize( $destination );

		if ( null === $normalized ) {
			throw new ValidationException( array( 'destination' => __( 'Enter a valid phone number (7-15 digits, optional leading +). Extensions are not supported.', 'contactlift' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field message is esc_html()'d by the admin screen that catches this, never echoed here.
		}

		return array( 'destination' => $normalized );
	}

	public function build_url( array $action, string $resolved_message = '' ): string {
		return 'tel:' . ( $action['destination'] ?? '' );
	}
}
