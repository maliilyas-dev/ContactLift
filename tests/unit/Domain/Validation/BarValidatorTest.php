<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Tests\Unit\Domain\Validation;

use ContactLift\Domain\ValidationException;
use ContactLift\Domain\Validation\BarValidator;
use ContactLift\Domain\Validation\EditionLimits;
use PHPUnit\Framework\TestCase;

final class BarValidatorTest extends TestCase {

	private BarValidator $validator;
	private EditionLimits $free;

	protected function setUp(): void {
		$this->validator = new BarValidator();
		$this->free      = EditionLimits::free();
	}

	public function test_generates_an_id_when_none_given(): void {
		$bar = $this->validator->validate( $this->validBar( array( 'id' => '' ) ), $this->free );

		self::assertNotSame( '', $bar['id'] );
	}

	public function test_rejects_empty_name(): void {
		$this->expectException( ValidationException::class );

		$this->validator->validate( $this->validBar( array( 'name' => '' ) ), $this->free );
	}

	public function test_rejects_out_of_range_priority(): void {
		$this->expectException( ValidationException::class );

		$this->validator->validate( $this->validBar( array( 'priority' => 5000 ) ), $this->free );
	}

	public function test_free_edition_caps_excluded_content_ids_at_five(): void {
		$this->expectException( ValidationException::class );

		$this->validator->validate(
			$this->validBar(
				array(
					'exclude' => array( 'content_ids' => range( 1, 6 ) ),
				)
			),
			$this->free
		);
	}

	public function test_free_edition_ignores_include_rules(): void {
		$bar = $this->validator->validate(
			$this->validBar(
				array(
					'include' => array( 'content_ids' => array( 1, 2, 3 ) ),
				)
			),
			$this->free
		);

		self::assertSame( array(), $bar['include']['content_ids'] );
	}

	public function test_free_edition_forces_schedule_disabled(): void {
		$bar = $this->validator->validate(
			$this->validBar(
				array(
					'schedule' => array( 'enabled' => true ),
				)
			),
			$this->free
		);

		self::assertFalse( $bar['schedule']['enabled'] );
	}

	public function test_rejects_callback_channel_in_free_edition(): void {
		$this->expectException( ValidationException::class );

		$this->validator->validate(
			$this->validBar(
				array(
					'actions' => array(
						array(
							'type'        => 'callback',
							'label'       => 'Request a callback',
							'enabled'     => false,
							'destination' => '',
						),
					),
				)
			),
			$this->free
		);
	}

	public function test_caps_actions_at_four(): void {
		$actions = array();
		for ( $i = 0; $i < 6; $i++ ) {
			$actions[] = array(
				'type'        => 'call',
				'label'       => 'Call',
				'enabled'     => false,
				'destination' => '',
			);
		}

		$bar = $this->validator->validate( $this->validBar( array( 'actions' => $actions ) ), $this->free );

		self::assertCount( 4, $bar['actions'] );
	}

	public function test_rejects_invalid_hex_colour(): void {
		$this->expectException( ValidationException::class );

		$this->validator->validate(
			$this->validBar(
				array(
					'appearance' => array( 'accent' => 'not-a-colour' ),
				)
			),
			$this->free
		);
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function validBar( array $overrides = array() ): array {
		return array_replace_recursive(
			array(
				'id'       => 'bar_test',
				'name'     => 'Test bar',
				'enabled'  => false,
				'priority' => 0,
				'device'   => 'all',
				'actions'  => array(),
			),
			$overrides
		);
	}
}
