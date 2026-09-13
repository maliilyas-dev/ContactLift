<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Unit\Domain\Schedule;

use ContactLift\Domain\ValidationException;
use ContactLiftPro\Domain\Schedule\ScheduleValidator;
use PHPUnit\Framework\TestCase;

final class ScheduleValidatorTest extends TestCase {

	private ScheduleValidator $validator;

	protected function setUp(): void {
		$this->validator = new ScheduleValidator();
	}

	public function test_normalizes_an_empty_schedule_to_disabled(): void {
		$result = $this->validator->validate( array() );

		self::assertFalse( $result['enabled'] );
		self::assertCount( 7, $result['weekly'] );
	}

	public function test_accepts_two_non_overlapping_intervals_in_one_day(): void {
		$result = $this->validator->validate(
			array(
				'enabled' => true,
				'weekly'  => array(
					1 => array(
						array(
							'start' => '09:00',
							'end'   => '12:00',
						),
						array(
							'start' => '13:00',
							'end'   => '17:00',
						),
					),
				),
			)
		);

		self::assertCount( 2, $result['weekly'][1] );
	}

	public function test_rejects_overlapping_intervals(): void {
		$this->expectException( ValidationException::class );

		$this->validator->validate(
			array(
				'enabled' => true,
				'weekly'  => array(
					1 => array(
						array(
							'start' => '09:00',
							'end'   => '13:00',
						),
						array(
							'start' => '12:00',
							'end'   => '17:00',
						),
					),
				),
			)
		);
	}

	public function test_rejects_equal_start_and_end(): void {
		$this->expectException( ValidationException::class );

		$this->validator->validate(
			array(
				'enabled' => true,
				'weekly'  => array(
					1 => array(
						array(
							'start' => '09:00',
							'end'   => '09:00',
						),
					),
				),
			)
		);
	}

	public function test_caps_intervals_at_two_per_day(): void {
		$result = $this->validator->validate(
			array(
				'enabled' => true,
				'weekly'  => array(
					1 => array(
						array(
							'start' => '01:00',
							'end'   => '02:00',
						),
						array(
							'start' => '03:00',
							'end'   => '04:00',
						),
						array(
							'start' => '05:00',
							'end'   => '06:00',
						),
					),
				),
			)
		);

		self::assertCount( 2, $result['weekly'][1] );
	}

	public function test_rejects_duplicate_exception_dates(): void {
		$this->expectException( ValidationException::class );

		$this->validator->validate(
			array(
				'enabled'    => true,
				'exceptions' => array(
					array(
						'date'   => '2026-12-25',
						'closed' => true,
					),
					array(
						'date'   => '2026-12-25',
						'closed' => true,
					),
				),
			)
		);
	}

	public function test_rejects_an_invalid_date(): void {
		$this->expectException( ValidationException::class );

		$this->validator->validate(
			array(
				'enabled'    => true,
				'exceptions' => array(
					array(
						'date'   => '2026-13-40',
						'closed' => true,
					),
				),
			)
		);
	}

	public function test_caps_exceptions_at_100(): void {
		$exceptions = array();
		$date       = new \DateTimeImmutable( '2027-01-01' );
		for ( $i = 0; $i < 120; $i++ ) {
			$exceptions[] = array(
				'date'   => $date->modify( "+{$i} days" )->format( 'Y-m-d' ),
				'closed' => true,
			);
		}

		$result = $this->validator->validate(
			array(
				'enabled'    => true,
				'exceptions' => $exceptions,
			)
		);

		self::assertCount( 100, $result['exceptions'] );
	}

	public function test_all_day_exception_ignores_submitted_intervals(): void {
		$result = $this->validator->validate(
			array(
				'enabled'    => true,
				'exceptions' => array(
					array(
						'date'      => '2026-12-25',
						'closed'    => true,
						'intervals' => array(
							array(
								'start' => '09:00',
								'end'   => '17:00',
							),
						),
					),
				),
			)
		);

		self::assertTrue( $result['exceptions']['2026-12-25']['closed'] );
		self::assertSame( array(), $result['exceptions']['2026-12-25']['intervals'] );
	}
}
