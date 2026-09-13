<?php
/**
 * Step 1 of "Rule resolution": surfaces where the bar never renders,
 * regardless of any bar's own targeting. These all need real WP query
 * context (is_admin, is_feed, is_404, post_password_required, ...), so
 * they're integration- rather than unit-testable.
 *
 * @package ContactLift
 */

namespace ContactLift\Tests\Integration;

use ContactLift\Frontend\GlobalExclusions;
use WP_UnitTestCase;

final class GlobalExclusionsTest extends WP_UnitTestCase {

	private GlobalExclusions $exclusions;

	public function set_up(): void {
		parent::set_up();
		$this->exclusions = new GlobalExclusions();
	}

	public function test_applies_in_wp_admin(): void {
		set_current_screen( 'dashboard' );
		$this->assertTrue( is_admin() );
		$this->assertTrue( $this->exclusions->applies() );
	}

	public function test_does_not_apply_on_an_ordinary_published_post(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );

		$this->assertFalse( $this->exclusions->applies() );
	}

	public function test_applies_on_a_feed(): void {
		self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( home_url( '/?feed=rss2' ) );

		$this->assertTrue( is_feed() );
		$this->assertTrue( $this->exclusions->applies() );
	}

	public function test_applies_on_404(): void {
		// A query-var-based nonexistent ID triggers 404 reliably regardless
		// of whether the test suite's install has pretty permalinks active
		// (a bare nonexistent slug URL doesn't, by default).
		$this->go_to( '/?p=999999999' );

		$this->assertTrue( is_404() );
		$this->assertTrue( $this->exclusions->applies() );
	}

	public function test_applies_on_password_protected_content(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);
		$this->go_to( get_permalink( $post_id ) );
		// A real front-end request runs the theme's main loop before
		// wp_footer fires, which sets global $post via the_post() — GlobalExclusions
		// relies on that same global context, so the test simulates it explicitly.
		global $post;
		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulating the main loop's own setup_postdata() for this test.
		setup_postdata( $post );

		$this->assertTrue( post_password_required() );
		$this->assertTrue( $this->exclusions->applies() );

		wp_reset_postdata();
	}

	public function test_does_not_apply_once_the_correct_password_is_supplied(): void {
		$post_id = self::factory()->post->create(
			array(
				'post_status'   => 'publish',
				'post_password' => 'secret',
			)
		);

		// post_password_required() checks this cookie with its own legacy
		// phpass hasher, not wp_hash_password() — which now produces bcrypt
		// hashes by default (WP 6.8+) that don't start with the "$P$B" prefix
		// post_password_required() requires, so a wp_hash_password() cookie
		// would always (wrongly) read as "no password supplied" here.
		require_once ABSPATH . WPINC . '/class-phpass.php';
		$hasher                                 = new \PasswordHash( 8, true );
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = $hasher->HashPassword( 'secret' );
		$this->go_to( get_permalink( $post_id ) );
		global $post;
		$post = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulating the main loop's own setup_postdata() for this test.
		setup_postdata( $post );

		$this->assertFalse( post_password_required( $post_id ) );
		$this->assertFalse( $this->exclusions->applies() );

		wp_reset_postdata();

		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	}

	public function test_applies_on_the_woocommerce_cart_page_when_woocommerce_is_active(): void {
		if ( ! function_exists( 'is_cart' ) ) {
			self::markTestSkipped( 'WooCommerce is not active in this environment.' );
		}

		// is_cart()'s own page-detection heuristics (block-based cart pages,
		// shortcode detection, ...) are WooCommerce's tested responsibility,
		// not this plugin's — its own first check is exactly this filter, so
		// using it directly tests GlobalExclusions' actual integration point
		// (does it correctly defer to is_cart()) without being fragile to
		// how a given WooCommerce version decides a page is the cart page.
		add_filter( 'woocommerce_is_cart', '__return_true' );

		$this->assertTrue( is_cart() );
		$this->assertTrue( $this->exclusions->applies() );

		remove_filter( 'woocommerce_is_cart', '__return_true' );
	}
}
