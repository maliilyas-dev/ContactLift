<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * An injectable "now", always a real UTC instant. Schedule evaluation
 * always starts from an unambiguous UTC instant and converts *to* the
 * site's local timezone — never the other direction — which is exactly
 * what sidesteps DST's ambiguous/skipped local-time problem: there's only ever
 * one correct local representation of a given instant, never two candidate
 * instants for a given local time.
 */
final class Clock {

	private function __construct( private readonly \DateTimeImmutable $now ) {}

	public static function at( \DateTimeImmutable $now ): self {
		return new self( $now );
	}

	public static function system(): self {
		return new self( new \DateTimeImmutable( 'now', new \DateTimeZone( 'UTC' ) ) );
	}

	public function now(): \DateTimeImmutable {
		return $this->now;
	}
}
