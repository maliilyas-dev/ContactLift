<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain\Channels;

use ContactLift\Domain\Text;
use ContactLift\Domain\ValidationException;

defined( 'ABSPATH' ) || exit;

/**
 * An ordinary HTTPS link. No arbitrary schemes: only https:// is accepted,
 * so a merchant can't turn this into a javascript: or data: sink.
 */
final class LinkChannel implements ChannelInterface {

	public function type(): string {
		return 'link';
	}

	public function validate( array $raw ): array {
		$destination  = trim( (string) ( $raw['destination'] ?? '' ) );
		$open_new_tab = ! empty( $raw['open_in_new_tab'] );

		if ( '' === $destination ) {
			return array(
				'destination'     => '',
				'open_in_new_tab' => $open_new_tab,
			);
		}

		if ( Text::has_control_characters( $destination ) ) {
			throw new ValidationException( array( 'destination' => __( 'The link can\'t contain line breaks.', 'contactlift' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field message is esc_html()'d by the admin screen that catches this, never echoed here.
		}

		$parts = parse_url( $destination ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- domain code stays WP-independent for unit testing; output is still esc_url()'d wherever it's rendered.

		if ( ! $parts || empty( $parts['scheme'] ) || 'https' !== strtolower( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			throw new ValidationException( array( 'destination' => __( 'Enter a valid https:// link.', 'contactlift' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field message is esc_html()'d by the admin screen that catches this, never echoed here.
		}

		return array(
			'destination'     => $destination,
			'open_in_new_tab' => $open_new_tab,
		);
	}

	public function build_url( array $action, string $resolved_message = '' ): string {
		return (string) ( $action['destination'] ?? '' );
	}
}
