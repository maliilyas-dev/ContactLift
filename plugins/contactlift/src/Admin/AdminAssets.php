<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the admin design system only on ContactLift's own screens — never
 * globally across wp-admin.
 */
final class AdminAssets {

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue' ) );
	}

	public function maybe_enqueue(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen check, no state change.

		if ( ! $page || 0 !== strpos( $page, 'contactlift' ) ) {
			return;
		}

		wp_enqueue_style( 'contactlift-admin', CONTACTLIFT_URL . 'assets/css/admin.css', array(), CONTACTLIFT_VERSION );
		wp_enqueue_script( 'contactlift-admin', CONTACTLIFT_URL . 'assets/js/admin.js', array(), CONTACTLIFT_VERSION, array( 'in_footer' => true ) );

		if ( 'contactlift-bar-editor' === $page ) {
			// The live preview reuses the real frontend styles so it's accurate, not a lookalike.
			wp_enqueue_style( 'contactlift-frontend', CONTACTLIFT_URL . 'assets/css/frontend.css', array(), CONTACTLIFT_VERSION );
		}
	}
}
