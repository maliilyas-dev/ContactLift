<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Small predicates over a normalized bar array, shared by the frontend
 * resolver and the admin "is this live" status pill so the two can never
 * silently disagree on what counts as renderable.
 */
final class BarState {

	/**
	 * @param array<string,mixed> $bar
	 */
	public static function has_renderable_action( array $bar ): bool {
		foreach ( $bar['actions'] ?? array() as $action ) {
			if ( ! empty( $action['enabled'] ) && '' !== trim( (string) ( $action['destination'] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}
}
