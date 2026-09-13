<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Unit\Domain\Callbacks;

use ContactLift\Domain\ValidationException;
use ContactLiftPro\Domain\Callbacks\CallbackValidator;
use PHPUnit\Framework\TestCase;

final class CallbackValidatorTest extends TestCase {

	private CallbackValidator $validator;

	protected function setUp(): void {
		$this->validator = new CallbackValidator();
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function submission( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'    => 'Jane Doe',
				'phone'   => '+1 555 123 4567',
				'email'   => 'jane@example.com',
				'message' => 'Please call me back.',
				'consent' => true,
			),
			$overrides
		);
	}

	public function test_a_complete_valid_submission_passes(): void {
		$result = $this->validator->validate( $this->submission() );

		self::assertSame( 'Jane Doe', $result['name'] );
		self::assertSame( '+1 555 123 4567', $result['phone'] );
		self::assertSame( 'jane@example.com', $result['email'] );
	}

	public function test_email_and_message_are_optional(): void {
		$result = $this->validator->validate(
			$this->submission(
				array(
					'email'   => '',
					'message' => '',
				)
			)
		);

		self::assertSame( '', $result['email'] );
		self::assertSame( '', $result['message'] );
	}

	public function test_missing_name_is_rejected(): void {
		$this->expectException( ValidationException::class );
		$this->validator->validate( $this->submission( array( 'name' => '' ) ) );
	}

	public function test_missing_phone_is_rejected(): void {
		$this->expectException( ValidationException::class );
		$this->validator->validate( $this->submission( array( 'phone' => '' ) ) );
	}

	public function test_unchecked_consent_is_rejected(): void {
		$this->expectException( ValidationException::class );
		$this->validator->validate( $this->submission( array( 'consent' => false ) ) );
	}

	public function test_invalid_email_format_is_rejected(): void {
		$this->expectException( ValidationException::class );
		$this->validator->validate( $this->submission( array( 'email' => 'not-an-email' ) ) );
	}

	public function test_overlong_name_is_rejected(): void {
		$this->expectException( ValidationException::class );
		$this->validator->validate( $this->submission( array( 'name' => str_repeat( 'a', 101 ) ) ) );
	}

	public function test_overlong_message_is_rejected(): void {
		$this->expectException( ValidationException::class );
		$this->validator->validate( $this->submission( array( 'message' => str_repeat( 'a', 1001 ) ) ) );
	}

	public function test_control_characters_in_name_are_rejected(): void {
		$this->expectException( ValidationException::class );
		$this->validator->validate( $this->submission( array( 'name' => "Jane\r\nDoe" ) ) );
	}

	public function test_message_may_contain_ordinary_newlines(): void {
		$result = $this->validator->validate( $this->submission( array( 'message' => "Line one\nLine two" ) ) );

		self::assertSame( "Line one\nLine two", $result['message'] );
	}

	public function test_all_errors_are_reported_together_not_just_the_first(): void {
		try {
			$this->validator->validate(
				$this->submission(
					array(
						'name'    => '',
						'phone'   => '',
						'consent' => false,
					)
				)
			);
			self::fail( 'Expected a ValidationException.' );
		} catch ( ValidationException $e ) {
			$errors = $e->errors();
			self::assertArrayHasKey( 'name', $errors );
			self::assertArrayHasKey( 'phone', $errors );
			self::assertArrayHasKey( 'consent', $errors );
		}
	}
}
