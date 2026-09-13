<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Storage;

use ContactLift\Domain\ValidationException;
use ContactLiftPro\Domain\Validation\ProSettingsValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Owns clift_pro_settings reads/validated writes — the Pro-only sibling of
 * the free plugin's SettingsRepository, same atomic-validated-write and
 * non-autoloaded-option shape.
 */
final class ProSettingsRepository {

	public const OPTION = ProInstaller::SETTINGS_OPTION;

	private ProSettingsValidator $validator;

	public function __construct( ?ProSettingsValidator $validator = null ) {
		$this->validator = $validator ?? new ProSettingsValidator();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_settings(): array {
		$stored = get_option( self::OPTION, false );

		return is_array( $stored ) ? $stored : $this->validator->validate( array() );
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 * @throws ValidationException
	 */
	public function save_settings( array $raw ): array {
		$normalized             = $this->validator->validate( $raw );
		$normalized['revision'] = $this->next_revision();

		update_option( self::OPTION, $normalized, false );

		return $normalized;
	}

	private function next_revision(): int {
		$stored = get_option( self::OPTION, false );

		return is_array( $stored ) && isset( $stored['revision'] ) ? ( (int) $stored['revision'] + 1 ) : 1;
	}
}
