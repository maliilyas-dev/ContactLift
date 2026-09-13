<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Schedule;

use ContactLift\Domain\ValidationException;

defined( 'ABSPATH' ) || exit;

/**
 * Structural validation for the "Schedule model" section: zero/one/two
 * intervals per weekday, no overlaps, no equal start/end, an all_day
 * switch, and up to 100 date exceptions with no duplicate dates.
 */
final class ScheduleValidator {

	private const MAX_INTERVALS_PER_DAY = 2;
	private const MAX_EXCEPTIONS        = 100;

	/**
	 * @param mixed $raw
	 * @return array{enabled:bool,weekly:array,exceptions:array}
	 * @throws ValidationException
	 */
	public function validate( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$errors = array();

		$weekly = array();
		for ( $weekday = 1; $weekday <= 7; $weekday++ ) {
			$weekly[ $weekday ] = $this->validate_day(
				$raw['weekly'][ $weekday ] ?? array(),
				$errors,
				"weekly.{$weekday}"
			);
		}

		$exceptions = $this->validate_exceptions( $raw['exceptions'] ?? array(), $errors );

		if ( $errors ) {
			throw new ValidationException( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field messages are esc_html()'d by the admin screen that catches this, never echoed here.
		}

		return array(
			'enabled'    => ! empty( $raw['enabled'] ),
			'weekly'     => $weekly,
			'exceptions' => $exceptions,
		);
	}

	/**
	 * @param mixed                 $raw
	 * @param array<string,string>  $errors
	 * @return array<int,array<string,mixed>>
	 */
	private function validate_day( $raw, array &$errors, string $field ): array {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$raw = array_slice( array_values( $raw ), 0, self::MAX_INTERVALS_PER_DAY );

		$intervals = array();
		foreach ( $raw as $index => $raw_interval ) {
			$interval = $this->validate_interval( $raw_interval, $errors, "{$field}.{$index}" );

			if ( null !== $interval ) {
				$intervals[] = $interval;
			}
		}

		if ( count( $intervals ) === 2 && $this->ranges_overlap( $intervals[0], $intervals[1] ) ) {
			$errors[ $field ] = __( 'These two time ranges overlap.', 'contactlift-pro' );
		}

		return $intervals;
	}

	/**
	 * @param mixed                $raw
	 * @param array<string,string> $errors
	 * @return array<string,mixed>|null
	 */
	private function validate_interval( $raw, array &$errors, string $field ): ?array {
		if ( ! is_array( $raw ) ) {
			return null;
		}

		if ( ! empty( $raw['all_day'] ) ) {
			return array(
				'all_day' => true,
				'start'   => '00:00',
				'end'     => '24:00',
			);
		}

		$start = Minutes::parse( (string) ( $raw['start'] ?? '' ) );
		$end   = Minutes::parse( (string) ( $raw['end'] ?? '' ) );

		if ( null === $start || null === $end ) {
			$errors[ $field ] = __( 'Enter a valid start and end time.', 'contactlift-pro' );
			return null;
		}

		if ( $start === $end ) {
			$errors[ $field ] = __( 'Start and end can\'t be the same time.', 'contactlift-pro' );
			return null;
		}

		return array(
			'all_day' => false,
			'start'   => ( $raw['start'] ),
			'end'     => ( $raw['end'] ),
		);
	}

	/**
	 * Whether two intervals' *today* portions overlap — an overnight
	 * interval's own carry-over into tomorrow is a different day's concern,
	 * not something that can overlap a sibling interval on this same day.
	 *
	 * @param array<string,mixed> $a
	 * @param array<string,mixed> $b
	 */
	private function ranges_overlap( array $a, array $b ): bool {
		[ $a_start, $a_end ] = $this->todays_range( $a );
		[ $b_start, $b_end ] = $this->todays_range( $b );

		return $a_start < $b_end && $b_start < $a_end;
	}

	/**
	 * @param array<string,mixed> $interval
	 * @return array{0:int,1:int}
	 */
	private function todays_range( array $interval ): array {
		if ( ! empty( $interval['all_day'] ) ) {
			return array( 0, 1440 );
		}

		$start = Minutes::parse( (string) $interval['start'] ) ?? 0;
		$end   = Minutes::parse( (string) $interval['end'] ) ?? 0;

		return $end > $start ? array( $start, $end ) : array( $start, 1440 );
	}

	/**
	 * @param mixed                 $raw
	 * @param array<string,string>  $errors
	 * @return array<string,array<string,mixed>>
	 */
	private function validate_exceptions( $raw, array &$errors ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$raw        = array_slice( $raw, 0, self::MAX_EXCEPTIONS );
		$exceptions = array();

		foreach ( $raw as $index => $raw_exception ) {
			if ( ! is_array( $raw_exception ) ) {
				continue;
			}

			$date = (string) ( $raw_exception['date'] ?? '' );

			// createFromFormat() is lenient about out-of-range values (e.g.
			// month 13 silently rolls over) — checkdate() is the strict
			// calendar-validity check that actually rejects "2026-13-40".
			if ( 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $parts ) || ! checkdate( (int) $parts[2], (int) $parts[3], (int) $parts[1] ) ) {
				$errors[ "exceptions.{$index}.date" ] = __( 'Enter a valid date (YYYY-MM-DD).', 'contactlift-pro' );
				continue;
			}

			if ( isset( $exceptions[ $date ] ) ) {
				$errors[ "exceptions.{$index}.date" ] = __( 'This date already has an exception.', 'contactlift-pro' );
				continue;
			}

			$closed = ! empty( $raw_exception['closed'] );

			$exceptions[ $date ] = array(
				'closed'    => $closed,
				'intervals' => $closed ? array() : $this->validate_day( $raw_exception['intervals'] ?? array(), $errors, "exceptions.{$index}.intervals" ),
			);
		}

		return $exceptions;
	}
}
