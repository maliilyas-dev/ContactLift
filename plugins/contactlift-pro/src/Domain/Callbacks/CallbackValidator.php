<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Callbacks;

use ContactLift\Domain\Text;
use ContactLift\Domain\ValidationException;

defined( 'ABSPATH' ) || exit;

/**
 * "Fields and settings" (spec, Callback requests): name, phone required;
 * email, message optional; a required, unchecked-by-default acknowledgment.
 * Pure PHP so the field-limit rules are unit-testable without booting
 * WordPress — the REST controller is what actually persists a validated
 * result.
 */
final class CallbackValidator {

	private const MAX_NAME_LENGTH    = 100;
	private const MAX_PHONE_LENGTH   = 30;
	private const MAX_EMAIL_LENGTH   = 254;
	private const MAX_MESSAGE_LENGTH = 1000;

	/**
	 * @param array<string,mixed> $raw
	 * @return array{name:string,phone:string,email:string,message:string,consent_text:string}
	 * @throws ValidationException
	 */
	public function validate( array $raw ): array {
		$errors = array();

		$name = trim( (string) ( $raw['name'] ?? '' ) );
		if ( '' === $name ) {
			$errors['name'] = __( 'Enter your name.', 'contactlift-pro' );
		} elseif ( Text::has_control_characters( $name ) ) {
			$errors['name'] = __( 'The name can\'t contain line breaks.', 'contactlift-pro' );
		} elseif ( Text::length( $name ) > self::MAX_NAME_LENGTH ) {
			$errors['name'] = __( 'The name is too long.', 'contactlift-pro' );
		}

		$phone = trim( (string) ( $raw['phone'] ?? '' ) );
		if ( '' === $phone ) {
			$errors['phone'] = __( 'Enter a phone number.', 'contactlift-pro' );
		} elseif ( Text::has_control_characters( $phone ) ) {
			$errors['phone'] = __( 'The phone number can\'t contain line breaks.', 'contactlift-pro' );
		} elseif ( Text::length( $phone ) > self::MAX_PHONE_LENGTH ) {
			$errors['phone'] = __( 'The phone number is too long.', 'contactlift-pro' );
		}

		$email = trim( (string) ( $raw['email'] ?? '' ) );
		if ( '' !== $email ) {
			if ( Text::length( $email ) > self::MAX_EMAIL_LENGTH ) {
				$errors['email'] = __( 'The email address is too long.', 'contactlift-pro' );
			} elseif ( false === filter_var( $email, FILTER_VALIDATE_EMAIL ) ) {
				$errors['email'] = __( 'Enter a valid email address.', 'contactlift-pro' );
			}
		}

		$message = trim( (string) ( $raw['message'] ?? '' ) );
		if ( Text::has_control_characters( str_replace( array( "\r\n", "\n" ), ' ', $message ) ) ) {
			$errors['message'] = __( 'The message contains characters that aren\'t allowed.', 'contactlift-pro' );
		} elseif ( Text::length( $message ) > self::MAX_MESSAGE_LENGTH ) {
			$errors['message'] = __( 'The message is too long (1,000 characters max).', 'contactlift-pro' );
		}

		if ( empty( $raw['consent'] ) ) {
			$errors['consent'] = __( 'Please confirm you agree to be contacted about this request.', 'contactlift-pro' );
		}

		if ( $errors ) {
			throw new ValidationException( $errors );
		}

		return array(
			'name'    => Text::truncate( $name, self::MAX_NAME_LENGTH ),
			'phone'   => Text::truncate( $phone, self::MAX_PHONE_LENGTH ),
			'email'   => Text::truncate( $email, self::MAX_EMAIL_LENGTH ),
			'message' => Text::truncate( $message, self::MAX_MESSAGE_LENGTH ),
		);
	}
}
