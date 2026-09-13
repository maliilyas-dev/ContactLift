<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * "Only accept page, post, product, product_category, home and ordinary
 * public archive context types. Resolve content on the server and check
 * that it is public and not password protected... For variation context,
 * verify that the selected variation belongs to the current public
 * product." (spec, Public API / Context validation — AT11's "private post
 * or mismatched variation ID" case). Never trusts a client-submitted title,
 * URL, or product data — only IDs, resolved fresh against the database.
 */
final class ContextValidator {

	private const ALLOWED_TYPES = array( 'page', 'post', 'product', 'product_category', 'home', 'archive' );

	/**
	 * @param mixed $source_type
	 * @param mixed $source_id
	 * @return array{source_type:string,source_id:int|null}|null
	 */
	public function validate_source( $source_type, $source_id ): ?array {
		$type = is_string( $source_type ) ? $source_type : '';

		if ( ! in_array( $type, self::ALLOWED_TYPES, true ) ) {
			return null;
		}

		if ( in_array( $type, array( 'home', 'archive', 'product_category' ), true ) ) {
			// No individual object ID to verify for these — they describe a
			// kind of page, not a specific piece of content.
			return array(
				'source_type' => $type,
				'source_id'   => null,
			);
		}

		$id = is_numeric( $source_id ) ? (int) $source_id : 0;

		if ( $id <= 0 ) {
			return null;
		}

		$post = get_post( $id );

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status ) {
			return null; // covers nonexistent, unpublished, and private posts alike.
		}

		if ( '' !== (string) $post->post_password ) {
			return null;
		}

		if ( 'product' === $type ) {
			if ( ! function_exists( 'wc_get_product' ) || 'product' !== $post->post_type ) {
				return null;
			}
		} elseif ( ! in_array( $post->post_type, array( 'page', 'post' ), true ) ) {
			return null;
		}

		return array(
			'source_type' => $type,
			'source_id'   => $id,
		);
	}

	/**
	 * Verifies a variation ID actually belongs to the given (already
	 * validated public) product ID — AT11's "mismatched variation ID" case.
	 * Returns null on any mismatch or when WooCommerce isn't available,
	 * rather than guessing.
	 */
	public function validate_variation( int $variation_id, int $product_id ): ?int {
		if ( $variation_id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$variation = wc_get_product( $variation_id );

		if ( ! $variation || 'variation' !== $variation->get_type() ) {
			return null;
		}

		if ( $variation->get_parent_id() !== $product_id ) {
			return null;
		}

		if ( ! $variation->is_visible() && ! $variation->variation_is_visible() ) {
			return null;
		}

		return $variation_id;
	}
}
