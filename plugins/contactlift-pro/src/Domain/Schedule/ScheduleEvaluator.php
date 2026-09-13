<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * "Rule resolution" step 5 of the spec, and the whole "Schedule model"
 * section: weekly hours with an overnight-carry rule, date exceptions that
 * override the whole local calendar date (carry-over included), all
 * evaluated in the site's timezone via an injectable Clock.
 *
 * A schedule is: ['enabled' => bool, 'weekly' => [1..7 => interval[]],
 * 'exceptions' => ['YYYY-MM-DD' => ['closed' => bool, 'intervals' => interval[]]]].
 * An interval is ['start' => 'HH:MM', 'end' => 'HH:MM', 'all_day' => bool].
 */
final class ScheduleEvaluator {

	/**
	 * @param array<string,mixed> $schedule
	 */
	public function is_open( array $schedule, \DateTimeZone $site_timezone, Clock $clock ): bool {
		if ( empty( $schedule['enabled'] ) ) {
			return true;
		}

		$local = $clock->now()->setTimezone( $site_timezone );
		$today = $local->format( 'Y-m-d' );
		$time  = Minutes::of_datetime( $local );

		$today_exception = $schedule['exceptions'][ $today ] ?? null;

		if ( null !== $today_exception && ! empty( $today_exception['closed'] ) ) {
			return false;
		}

		$today_intervals = $this->intervals_for_date( $schedule, $today, $today_exception );

		foreach ( $today_intervals as $interval ) {
			if ( $this->interval_covers( $interval, $time ) ) {
				return true;
			}
		}

		// A closed-or-normal exception on *today* overrides any carry-over
		// from yesterday's overnight interval too — an exception replaces
		// the whole local calendar date, not just its own listed hours.
		if ( null !== $today_exception ) {
			return false;
		}

		$yesterday           = $local->modify( '-1 day' )->format( 'Y-m-d' );
		$yesterday_exception = $schedule['exceptions'][ $yesterday ] ?? null;

		if ( null !== $yesterday_exception && ! empty( $yesterday_exception['closed'] ) ) {
			return false;
		}

		$yesterday_intervals = $this->intervals_for_date( $schedule, $yesterday, $yesterday_exception );

		foreach ( $yesterday_intervals as $interval ) {
			if ( $this->interval_carries_into_next_day( $interval, $time ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed>      $schedule
	 * @param array<string,mixed>|null $exception Already-resolved exception for $date, if any.
	 * @return array<int,array<string,mixed>>
	 */
	private function intervals_for_date( array $schedule, string $date, ?array $exception ): array {
		if ( null !== $exception ) {
			return $exception['intervals'] ?? array();
		}

		$weekday = (int) ( new \DateTimeImmutable( $date ) )->format( 'N' );

		return $schedule['weekly'][ $weekday ] ?? array();
	}

	/**
	 * @param array<string,mixed> $interval
	 */
	private function interval_covers( array $interval, int $time ): bool {
		if ( ! empty( $interval['all_day'] ) ) {
			return true;
		}

		$start = Minutes::parse( (string) ( $interval['start'] ?? '' ) );
		$end   = Minutes::parse( (string) ( $interval['end'] ?? '' ) );

		if ( null === $start || null === $end ) {
			return false;
		}

		if ( $end > $start ) {
			// Ordinary same-day interval. Start inclusive, end exclusive.
			return $time >= $start && $time < $end;
		}

		if ( $end < $start ) {
			// Overnight: today's portion runs from $start through 23:59;
			// the [00:00, $end) portion is tomorrow's problem — handled by
			// interval_carries_into_next_day() on the *next* day's evaluation.
			return $time >= $start;
		}

		// $end === $start is rejected at validation time; treat defensively as never-open.
		return false;
	}

	/**
	 * @param array<string,mixed> $interval
	 */
	private function interval_carries_into_next_day( array $interval, int $time ): bool {
		if ( ! empty( $interval['all_day'] ) ) {
			return false; // An all-day interval doesn't "carry over" — it already covered its own whole day.
		}

		$start = Minutes::parse( (string) ( $interval['start'] ?? '' ) );
		$end   = Minutes::parse( (string) ( $interval['end'] ?? '' ) );

		if ( null === $start || null === $end || $end >= $start ) {
			return false; // Not an overnight interval.
		}

		return $time < $end;
	}
}
