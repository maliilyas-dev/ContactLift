<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * "HH:MM" <-> minutes-since-midnight, so interval comparisons are plain
 * integer comparisons rather than repeated string parsing.
 */
final class Minutes {

	public static function parse( string $hhmm ): ?int {
		if ( 1 !== preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $matches ) ) {
			return null;
		}

		return ( (int) $matches[1] * 60 ) + (int) $matches[2];
	}

	public static function of_datetime( \DateTimeImmutable $when ): int {
		return ( (int) $when->format( 'H' ) * 60 ) + (int) $when->format( 'i' );
	}
}
