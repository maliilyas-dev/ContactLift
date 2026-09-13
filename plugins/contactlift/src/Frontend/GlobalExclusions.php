<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Step 1 of the spec's "Rule resolution": surfaces where the bar never
 * renders, regardless of any bar's own targeting. Checked before anything
 * else, for both the wp_footer auto-output and the [contactlift] shortcode.
 */
final class GlobalExclusions {

	public function applies(): bool {
		if ( is_admin() || $this->is_login_screen() || is_feed() || $this->is_rest_request() ) {
			return true;
		}

		if ( is_preview() || post_password_required() || is_404() ) {
			return true;
		}

		return $this->is_excluded_woocommerce_endpoint();
	}

	private function is_login_screen(): bool {
		return isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'];
	}

	private function is_rest_request(): bool {
		return defined( 'REST_REQUEST' ) && REST_REQUEST;
	}

	private function is_excluded_woocommerce_endpoint(): bool {
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return true;
		}

		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return true;
		}

		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}

		return false;
	}
}
