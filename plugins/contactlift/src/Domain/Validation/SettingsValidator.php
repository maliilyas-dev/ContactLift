<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain\Validation;

use ContactLift\Domain\Text;
use ContactLift\Domain\ValidationException;

defined( 'ABSPATH' ) || exit;

/**
 * Validates the clift_settings option (business identity + lifecycle flags).
 * See docs/decisions.md for fields the spec left to the implementer
 * (business_name length, the "defaults" shape).
 */
final class SettingsValidator {

	private const MAX_BUSINESS_NAME_LENGTH = 150;

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 * @throws ValidationException
	 */
	public function validate( array $raw ): array {
		$errors = array();

		$business_name = trim( (string) ( $raw['business_name'] ?? '' ) );
		if ( Text::length( $business_name ) > self::MAX_BUSINESS_NAME_LENGTH ) {
			$errors['business_name'] = __( 'The business name is too long.', 'contactlift' );
		}

		$preset = (string) ( $raw['defaults']['preset'] ?? 'general' );
		if ( 'general' !== $preset ) {
			// Free ships one preset; Pro's industry presets validate their own values.
			$preset = 'general';
		}

		if ( $errors ) {
			throw new ValidationException( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field messages are esc_html()'d by the admin screen that catches this, never echoed here.
		}

		return array(
			'schema_version'           => 1,
			'business_name'            => Text::truncate( $business_name, self::MAX_BUSINESS_NAME_LENGTH ),
			'enabled'                  => ! empty( $raw['enabled'] ),
			'defaults'                 => array( 'preset' => $preset ),
			'delete_data_on_uninstall' => ! empty( $raw['delete_data_on_uninstall'] ),
		);
	}
}
