<?php
/**
 * AT06-AT09 from the spec's acceptance suite, plus the extra edge cases it
 * lists (all-day schedules, fixed-offset timezones).
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Unit\Domain\Schedule;

use ContactLiftPro\Domain\Schedule\Clock;
use ContactLiftPro\Domain\Schedule\ScheduleEvaluator;
use PHPUnit\Framework\TestCase;

final class ScheduleEvaluatorTest extends TestCase {

	private ScheduleEvaluator $evaluator;
	private \DateTimeZone $utc;

	protected function setUp(): void {
		$this->evaluator = new ScheduleEvaluator();
		$this->utc       = new \DateTimeZone( 'UTC' );
	}

	private function clock_at( string $iso ): Clock {
		return Clock::at( new \DateTimeImmutable( $iso, new \DateTimeZone( 'UTC' ) ) );
	}

	public function test_disabled_schedule_is_always_open(): void {
		$schedule = array(
			'enabled'    => false,
			'weekly'     => array(),
			'exceptions' => array(),
		);

		self::assertTrue( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T03:00:00Z' ) ) );
	}

	public function test_at06_open_at_start_boundary_closed_at_end_boundary(): void {
		// 2026-09-14 is a Monday (ISO weekday 1).
		$schedule = array(
			'enabled'    => true,
			'weekly'     => array(
				1 => array(
					array(
						'all_day' => false,
						'start'   => '09:00',
						'end'     => '17:00',
					),
				),
			),
			'exceptions' => array(),
		);

		self::assertTrue( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T09:00:00Z' ) ), 'open exactly at start' );
		self::assertTrue( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T16:59:00Z' ) ), 'still open just before end' );
		self::assertFalse( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T17:00:00Z' ) ), 'closed exactly at end (end is exclusive)' );
		self::assertFalse( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T08:59:00Z' ) ), 'not open yet' );
	}

	public function test_a_weekday_with_no_intervals_is_closed(): void {
		$schedule = array(
			'enabled'    => true,
			'weekly'     => array(),
			'exceptions' => array(),
		);

		self::assertFalse( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T12:00:00Z' ) ) );
	}

	public function test_at07_overnight_interval_correct_availability(): void {
		// Monday 22:00 to Tuesday 02:00. 2026-09-14 = Monday, 2026-09-15 = Tuesday.
		$schedule = array(
			'enabled'    => true,
			'weekly'     => array(
				1 => array(
					array(
						'all_day' => false,
						'start'   => '22:00',
						'end'     => '02:00',
					),
				),
			),
			'exceptions' => array(),
		);

		self::assertTrue( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T22:30:00Z' ) ), 'Monday evening, within the interval' );
		self::assertTrue( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-15T01:00:00Z' ) ), 'Tuesday 01:00 — carried over from Monday' );
		self::assertFalse( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-15T02:00:00Z' ) ), 'Tuesday 02:00 — end is exclusive' );
		self::assertFalse( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T21:59:00Z' ) ), 'Monday, just before opening' );
		self::assertFalse( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-15T12:00:00Z' ) ), 'Tuesday afternoon — Tuesday itself has no intervals of its own' );
	}

	public function test_at08_closed_exception_overrides_mondays_overnight_carry_into_tuesday(): void {
		$schedule = array(
			'enabled'    => true,
			'weekly'     => array(
				1 => array(
					array(
						'all_day' => false,
						'start'   => '22:00',
						'end'     => '02:00',
					),
				),
			),
			'exceptions' => array(
				'2026-09-15' => array(
					'closed'    => true,
					'intervals' => array(),
				),
			),
		);

		self::assertFalse( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-15T01:00:00Z' ) ), 'would have been open via carry-over, but Tuesday is an exception day' );
		self::assertFalse( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-15T15:00:00Z' ) ), 'closed all day Tuesday' );
		self::assertTrue( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T22:30:00Z' ) ), 'Monday evening itself is unaffected' );
	}

	public function test_exception_with_replacement_intervals_is_used_instead_of_the_weekly_default(): void {
		$schedule = array(
			'enabled'    => true,
			'weekly'     => array(
				1 => array(
					array(
						'all_day' => false,
						'start'   => '09:00',
						'end'     => '17:00',
					),
				),
			),
			'exceptions' => array(
				'2026-09-14' => array(
					'closed'    => false,
					'intervals' => array(
						array(
							'all_day' => false,
							'start'   => '10:00',
							'end'     => '12:00',
						),
					),
				),
			),
		);

		self::assertFalse( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T09:30:00Z' ) ), 'the ordinary Monday hours no longer apply' );
		self::assertTrue( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T11:00:00Z' ) ), 'the exception\'s own hours apply' );
	}

	public function test_all_day_switch_is_open_the_whole_day(): void {
		$schedule = array(
			'enabled'    => true,
			'weekly'     => array(
				1 => array(
					array(
						'all_day' => true,
						'start'   => '00:00',
						'end'     => '24:00',
					),
				),
			),
			'exceptions' => array(),
		);

		self::assertTrue( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T00:00:00Z' ) ) );
		self::assertTrue( $this->evaluator->is_open( $schedule, $this->utc, $this->clock_at( '2026-09-14T23:59:00Z' ) ) );
	}

	public function test_at09_correct_local_evaluation_in_asia_karachi(): void {
		// Asia/Karachi is a fixed UTC+5, no DST — a simple, stable non-UTC check.
		$karachi  = new \DateTimeZone( 'Asia/Karachi' );
		$schedule = array(
			'enabled'    => true,
			'weekly'     => array(
				1 => array(
					array(
						'all_day' => false,
						'start'   => '09:00',
						'end'     => '17:00',
					),
				),
			),
			'exceptions' => array(),
		);

		// 04:30 UTC on 2026-09-14 (Monday) = 09:30 in Karachi (UTC+5) — open.
		self::assertTrue( $this->evaluator->is_open( $schedule, $karachi, $this->clock_at( '2026-09-14T04:30:00Z' ) ) );
		// 03:30 UTC = 08:30 Karachi — not open yet.
		self::assertFalse( $this->evaluator->is_open( $schedule, $karachi, $this->clock_at( '2026-09-14T03:30:00Z' ) ) );
	}

	public function test_at09_correct_local_evaluation_across_a_dst_boundary_in_america_new_york(): void {
		// US DST ended 2026-11-01 at 2:00 local (falls back to 1:00). Use a
		// bar open 00:30-01:30 local, which exists *twice* in wall-clock
		// terms that night — both real UTC instants for "01:00 local" must
		// independently evaluate as open, proving evaluation always starts
		// from the (unambiguous) UTC instant rather than guessing a local time.
		$new_york = new \DateTimeZone( 'America/New_York' );
		$schedule = array(
			'enabled'    => true,
			// 2026-11-01 is a Sunday (ISO weekday 7).
			'weekly'     => array(
				7 => array(
					array(
						'all_day' => false,
						'start'   => '00:30',
						'end'     => '01:30',
					),
				),
			),
			'exceptions' => array(),
		);

		// First 01:00 EDT (UTC-4): 2026-11-01 05:00 UTC.
		self::assertTrue( $this->evaluator->is_open( $schedule, $new_york, $this->clock_at( '2026-11-01T05:00:00Z' ) ) );
		// Second 01:00 EST, after falling back (UTC-5): 2026-11-01 06:00 UTC.
		self::assertTrue( $this->evaluator->is_open( $schedule, $new_york, $this->clock_at( '2026-11-01T06:00:00Z' ) ) );
		// 02:00 EST (UTC-5) = 07:00 UTC — past the end boundary on the second pass.
		self::assertFalse( $this->evaluator->is_open( $schedule, $new_york, $this->clock_at( '2026-11-01T07:00:00Z' ) ) );
	}
}
