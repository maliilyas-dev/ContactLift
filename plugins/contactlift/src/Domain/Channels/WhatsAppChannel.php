<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain\Channels;

use ContactLift\Domain\Text;
use ContactLift\Domain\ValidationException;

defined( 'ABSPATH' ) || exit;

/**
 * https://wa.me/NUMBER?text=ENCODED_MESSAGE — WhatsApp's click-to-chat format.
 */
final class WhatsAppChannel implements ChannelInterface {

	private const MAX_TEMPLATE_LENGTH = 1000;
	private const MAX_MESSAGE_LENGTH  = 1500;

	public function type(): string {
		return 'whatsapp';
	}

	public function validate( array $raw ): array {
		$errors      = array();
		$destination = trim( (string) ( $raw['destination'] ?? '' ) );
		$template    = (string) ( $raw['message_template'] ?? '' );

		$normalized = '';
		if ( '' !== $destination ) {
			$normalized = PhoneNumber::normalize( $destination );

			if ( null === $normalized ) {
				$errors['destination'] = __( 'Enter the international WhatsApp number, including country code.', 'contactlift' );
			}
		}

		if ( Text::has_control_characters( $template ) ) {
			$errors['message_template'] = __( 'The message can\'t contain line breaks.', 'contactlift' );
		} elseif ( Text::length( $template ) > self::MAX_TEMPLATE_LENGTH ) {
			$errors['message_template'] = __( 'The message template is too long (1,000 characters max).', 'contactlift' );
		}

		if ( $errors ) {
			throw new ValidationException( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field messages are esc_html()'d by the admin screen that catches this, never echoed here.
		}

		return array(
			'destination'      => PhoneNumber::digits_only( (string) $normalized ),
			'message_template' => $template,
		);
	}

	public function build_url( array $action, string $resolved_message = '' ): string {
		$url = 'https://wa.me/' . ( $action['destination'] ?? '' );

		if ( '' === $resolved_message ) {
			return $url;
		}

		$capped = Text::truncate( $resolved_message, self::MAX_MESSAGE_LENGTH );

		return $url . '?text=' . rawurlencode( $capped );
	}
}
