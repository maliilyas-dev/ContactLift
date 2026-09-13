<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Targeting;

defined( 'ABSPATH' ) || exit;

/**
 * "For each bar, exclusion matches always win. Include matches use OR
 * across selected content IDs and product-category IDs. An empty include
 * list means all otherwise eligible pages."
 */
final class BarMatcher {

	/**
	 * @param array<string,mixed> $bar
	 */
	public function matches( array $bar, MatchContext $context ): bool {
		$exclude = $bar['exclude'] ?? array();

		if ( $this->id_matches( $context->content_id, $exclude['content_ids'] ?? array() ) ) {
			return false;
		}

		if ( $this->any_match( $context->product_cat_ids, $exclude['product_cat_ids'] ?? array() ) ) {
			return false;
		}

		$include         = $bar['include'] ?? array();
		$include_content = $include['content_ids'] ?? array();
		$include_cats    = $include['product_cat_ids'] ?? array();

		if ( ! $include_content && ! $include_cats ) {
			return true;
		}

		return $this->id_matches( $context->content_id, $include_content )
			|| $this->any_match( $context->product_cat_ids, $include_cats );
	}

	/**
	 * @param int[] $set
	 */
	private function id_matches( ?int $id, array $set ): bool {
		return null !== $id && in_array( $id, $set, true );
	}

	/**
	 * @param int[] $ids
	 * @param int[] $set
	 */
	private function any_match( array $ids, array $set ): bool {
		return (bool) array_intersect( $ids, $set );
	}
}
