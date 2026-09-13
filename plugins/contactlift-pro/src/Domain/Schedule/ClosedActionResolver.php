<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Schedule;

defined( 'ABSPATH' ) || exit;

/**
 * Spec's "Closed actions": while a bar's schedule says closed, each action
 * reacts per its own closed_behavior — Keep (unchanged), Hide (removed), or
 * Replace (swapped for its configured replacement channel, keeping the
 * original action's id stable). A replacement is validated with no
 * closed_behavior of its own (see BarValidator::validate_replacement()), so
 * there is never a chain to resolve here — only ever one substitution deep.
 */
final class ClosedActionResolver {

	/**
	 * @param array<int,array<string,mixed>> $actions
	 * @return array<int,array<string,mixed>>
	 */
	public function apply( array $actions, bool $is_open ): array {
		if ( $is_open ) {
			return $actions;
		}

		$resolved = array();

		foreach ( $actions as $action ) {
			$behavior = $action['closed_behavior'] ?? 'keep';

			if ( 'hide' === $behavior ) {
				continue;
			}

			if ( 'replace' === $behavior ) {
				$replacement = $this->replacement_for( $action );

				if ( null !== $replacement ) {
					$resolved[] = $replacement;
				}

				continue;
			}

			$resolved[] = $action;
		}

		return $resolved;
	}

	/**
	 * @param array<string,mixed> $action
	 * @return array<string,mixed>|null
	 */
	private function replacement_for( array $action ): ?array {
		$replacement = $action['replacement'] ?? null;

		if ( ! is_array( $replacement ) || empty( $replacement['enabled'] ) ) {
			return null;
		}

		return array_merge( $replacement, array( 'id' => $action['id'] ?? ( $replacement['id'] ?? '' ) ) );
	}
}
