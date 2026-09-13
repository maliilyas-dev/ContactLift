<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Carries field-level validation failures. Deliberately not WP_Error: domain
 * validation must stay testable without WordPress loaded.
 */
final class ValidationException extends \RuntimeException {

	/** @var array<string,string> Field key => human-readable error message. */
	private array $errors;

	/**
	 * @param array<string,string> $errors
	 */
	public function __construct( array $errors ) {
		$this->errors = $errors;

		$message = implode( ' ', $errors );

		parent::__construct( '' !== $message ? $message : 'Validation failed.' );
	}

	/**
	 * @return array<string,string>
	 */
	public function errors(): array {
		return $this->errors;
	}
}
