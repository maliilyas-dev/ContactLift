<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Tests\Unit\Domain\Validation;

use ContactLift\Domain\ValidationException;
use ContactLift\Domain\Validation\SettingsValidator;
use PHPUnit\Framework\TestCase;

final class SettingsValidatorTest extends TestCase {

	private SettingsValidator $validator;

	protected function setUp(): void {
		$this->validator = new SettingsValidator();
	}

	public function test_normalizes_minimal_input(): void {
		$result = $this->validator->validate( array() );

		self::assertSame( '', $result['business_name'] );
		self::assertFalse( $result['enabled'] );
		self::assertSame( 'general', $result['defaults']['preset'] );
	}

	public function test_rejects_overlong_business_name(): void {
		$this->expectException( ValidationException::class );

		$this->validator->validate( array( 'business_name' => str_repeat( 'a', 151 ) ) );
	}

	public function test_preserves_flags(): void {
		$result = $this->validator->validate(
			array(
				'business_name'            => 'Acme Clinic',
				'enabled'                  => true,
				'delete_data_on_uninstall' => true,
			)
		);

		self::assertSame( 'Acme Clinic', $result['business_name'] );
		self::assertTrue( $result['enabled'] );
		self::assertTrue( $result['delete_data_on_uninstall'] );
	}
}
