<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * "Pro additionally supports {product_name}, {product_sku}, {product_url}
 * and {variation}" (spec, Tokens). Resolves the parent-product context on
 * the initial server render; {variation} starts empty — "Resetting the
 * variation restores the parent product context" is exactly this default
 * state — and TokenResolver already drops an empty token cleanly (see its
 * own docblock), so no separate handling is needed here for the no
 * -variation-selected case.
 */
final class ProductTokens {

	/**
	 * @return array<string,string>
	 */
	public function resolve(): array {
		if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'is_product' ) || ! is_product() ) {
			return array();
		}

		$product = wc_get_product( get_queried_object_id() );

		if ( ! $product ) {
			return array();
		}

		$permalink = get_permalink( $product->get_id() );

		return array(
			'product_name' => $product->get_name(),
			'product_sku'  => (string) $product->get_sku(),
			'product_url'  => is_string( $permalink ) ? $permalink : '',
			'variation'    => '',
		);
	}
}
