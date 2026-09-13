<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Targeting;

defined( 'ABSPATH' ) || exit;

/**
 * Rule resolution steps 2-4: filter to enabled bars matching the requested
 * device, apply each bar's own include/exclude, then pick the highest
 * priority among matches — tying on the lowest bar ID for a stable result
 * — falling back to the default bar only if no targeted bar matched (and
 * only if the default bar's own exclude/device/enabled gates pass too).
 *
 * Device is resolved twice by the caller (once "as if mobile", once "as if
 * desktop") since a page can't know the visitor's real viewport server-side
 * — see Frontend\BarResolver in this plugin for how the two results get
 * turned into markup.
 */
final class BarTargetingResolver {

	private BarMatcher $matcher;

	public function __construct( ?BarMatcher $matcher = null ) {
		$this->matcher = $matcher ?? new BarMatcher();
	}

	/**
	 * @param array<int,array<string,mixed>> $bars All bars, default included.
	 */
	public function resolve( array $bars, string $default_bar_id, MatchContext $context, string $device ): ?array {
		$targeted = array_values(
			array_filter( $bars, static fn( array $bar ): bool => ( $bar['id'] ?? null ) !== $default_bar_id )
		);

		$winner = $this->resolve_targeted( $targeted, $context, $device );

		if ( null !== $winner ) {
			return $winner;
		}

		foreach ( $bars as $bar ) {
			if ( ( $bar['id'] ?? null ) === $default_bar_id ) {
				return $this->is_eligible( $bar, $context, $device ) ? $bar : null;
			}
		}

		return null;
	}

	/**
	 * @param array<int,array<string,mixed>> $bars
	 * @return array<string,mixed>|null
	 */
	private function resolve_targeted( array $bars, MatchContext $context, string $device ): ?array {
		$candidates = array_values(
			array_filter( $bars, fn( array $bar ): bool => $this->is_eligible( $bar, $context, $device ) )
		);

		if ( ! $candidates ) {
			return null;
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				$priority_a = (int) ( $a['priority'] ?? 0 );
				$priority_b = (int) ( $b['priority'] ?? 0 );

				if ( $priority_a !== $priority_b ) {
					return $priority_b <=> $priority_a; // higher priority first.
				}

				return strcmp( (string) ( $a['id'] ?? '' ), (string) ( $b['id'] ?? '' ) ); // tie: lowest ID first.
			}
		);

		return $candidates[0];
	}

	/**
	 * @param array<string,mixed> $bar
	 */
	private function is_eligible( array $bar, MatchContext $context, string $device ): bool {
		if ( empty( $bar['enabled'] ) ) {
			return false;
		}

		if ( ! in_array( $bar['device'] ?? 'all', array( 'all', $device ), true ) ) {
			return false;
		}

		return $this->matcher->matches( $bar, $context );
	}
}
