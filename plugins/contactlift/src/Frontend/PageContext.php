<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * The current request's public, token-safe context. Never a cart, checkout,
 * order-pay, preview or account URL — those are excluded upstream by
 * GlobalExclusions before this is ever built.
 */
final class PageContext {

	public function current_content_id(): ?int {
		if ( ! is_singular() ) {
			return null;
		}

		$id = get_queried_object_id();

		return $id > 0 ? $id : null;
	}

	/**
	 * A coarse classification of the current request — used by Pro's
	 * callback challenge/submission context, which needs a type alongside
	 * current_content_id() to know what kind of object it's looking at
	 * (the spec's "Only accept page, post, product, product_category, home
	 * and ordinary public archive context types").
	 */
	public function source_type(): string {
		if ( is_front_page() || is_home() ) {
			return 'home';
		}

		if ( is_singular( 'product' ) ) {
			return 'product';
		}

		if ( is_singular() ) {
			return 'page' === get_post_type() ? 'page' : 'post';
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return 'product_category';
		}

		return 'archive';
	}

	/**
	 * @return array<string,string>
	 */
	public function free_tokens(): array {
		$tokens = array(
			'site_name'  => get_bloginfo( 'name' ),
			'page_title' => wp_strip_all_tags( wp_get_document_title() ),
			'page_url'   => $this->canonical_url(),
		);

		/**
		 * Lets Pro add {product_name}, {product_sku}, {product_url} and
		 * {variation} for the current request. Free ignores this filter
		 * entirely when Pro isn't active; TokenResolver treats any value
		 * left absent or empty the same way — cleanly dropped, never a raw
		 * {token} left visible.
		 *
		 * @param array<string,string> $tokens
		 */
		return apply_filters( 'clift_token_values', $tokens );
	}

	private function canonical_url(): string {
		if ( is_singular() ) {
			$permalink = get_permalink();

			return is_string( $permalink ) && '' !== $permalink ? $permalink : home_url( '/' );
		}

		global $wp;

		$request = isset( $wp->request ) ? $wp->request : '';

		return home_url( '/' . ltrim( (string) $request, '/' ) );
	}
}
