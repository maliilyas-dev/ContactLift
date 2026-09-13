<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain\Channels;

use ContactLift\Domain\Text;
use ContactLift\Domain\ValidationException;

defined( 'ABSPATH' ) || exit;

/**
 * mailto: with each query value encoded separately. Stores its own
 * subject_template/body_template fields alongside the common action envelope.
 */
final class EmailChannel implements ChannelInterface {

	private const MAX_SUBJECT_LENGTH = 200;
	private const MAX_BODY_LENGTH    = 1000;

	public function type(): string {
		return 'email';
	}

	public function validate( array $raw ): array {
		$errors  = array();
		$address = trim( (string) ( $raw['destination'] ?? '' ) );
		$subject = (string) ( $raw['subject_template'] ?? '' );
		$body    = (string) ( $raw['body_template'] ?? '' );

		if ( '' !== $address && ! self::is_valid_email( $address ) ) {
			$errors['destination'] = __( 'Enter a valid email address.', 'contactlift' );
		}

		if ( Text::has_control_characters( $subject ) || Text::has_control_characters( $body ) ) {
			$errors['message_template'] = __( 'The subject and body can\'t contain line breaks.', 'contactlift' );
		} elseif ( Text::length( $subject ) > self::MAX_SUBJECT_LENGTH ) {
			$errors['subject_template'] = __( 'The subject is too long.', 'contactlift' );
		} elseif ( Text::length( $body ) > self::MAX_BODY_LENGTH ) {
			$errors['body_template'] = __( 'The body is too long (1,000 characters max).', 'contactlift' );
		}

		if ( $errors ) {
			throw new ValidationException( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field messages are esc_html()'d by the admin screen that catches this, never echoed here.
		}

		return array(
			'destination'      => $address,
			'subject_template' => $subject,
			'body_template'    => $body,
		);
	}

	public function build_url( array $action, string $resolved_message = '' ): string {
		$address = (string) ( $action['destination'] ?? '' );
		$params  = array();

		if ( '' !== (string) ( $action['subject_template'] ?? '' ) ) {
			$params['subject'] = $action['resolved_subject'] ?? $action['subject_template'];
		}

		if ( '' !== (string) ( $action['body_template'] ?? '' ) ) {
			$params['body'] = $action['resolved_body'] ?? $action['body_template'];
		}

		$query = array();
		foreach ( $params as $key => $value ) {
			$query[] = $key . '=' . rawurlencode( (string) $value );
		}

		// The address itself is left unencoded (already validated as a plain
		// email address) — only the query values need per-RFC 6068 encoding.
		return 'mailto:' . $address . ( $query ? '?' . implode( '&', $query ) : '' );
	}

	private static function is_valid_email( string $address ): bool {
		return false !== filter_var( $address, FILTER_VALIDATE_EMAIL );
	}
}
