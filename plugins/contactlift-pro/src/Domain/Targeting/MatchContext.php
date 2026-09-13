<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Targeting;

defined( 'ABSPATH' ) || exit;

/**
 * The current page reduced to exactly what targeting needs to compare
 * against a bar's include/exclude lists. Product-category *descendant*
 * expansion (the include_descendants flag) happens upstream, in the
 * WP-dependent adapter — $product_cat_ids here is already the final set to
 * intersect against, plain array membership, nothing WP-specific.
 */
final class MatchContext {

	/**
	 * @param int[] $product_cat_ids
	 */
	public function __construct(
		public readonly ?int $content_id,
		public readonly array $product_cat_ids = array()
	) {}
}
