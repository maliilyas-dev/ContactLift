<?php
/**
 * AT16: unauthenticated and low-privilege admin actions must be denied,
 * regardless of nonce manipulation — every screen and save handler checks
 * capability before anything else, including before the nonce check.
 *
 * @package ContactLift
 */

namespace ContactLift\Tests\Integration;

use ContactLift\Admin\BarEditorPage;
use ContactLift\Admin\BarsPage;
use ContactLift\Admin\SettingsPage;
use ContactLift\Admin\ToolsPage;
use ContactLift\Storage\SettingsRepository;
use WP_UnitTestCase;

final class CapabilityGatesTest extends WP_UnitTestCase {

	private SettingsRepository $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = new SettingsRepository();
	}

	public function test_at16_logged_out_visitor_cannot_view_bars_screen(): void {
		wp_set_current_user( 0 );

		$this->expectException( 'WPDieException' );
		( new BarsPage( $this->settings ) )->render();
	}

	public function test_at16_subscriber_cannot_view_bars_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( 'WPDieException' );
		( new BarsPage( $this->settings ) )->render();
	}

	public function test_at16_subscriber_cannot_view_bar_editor(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( 'WPDieException' );
		( new BarEditorPage( $this->settings ) )->render();
	}

	public function test_at16_subscriber_cannot_view_settings_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( 'WPDieException' );
		( new SettingsPage( $this->settings ) )->render();
	}

	public function test_at16_subscriber_cannot_view_tools_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		$this->expectException( 'WPDieException' );
		( new ToolsPage( $this->settings ) )->render();
	}

	public function test_at16_editor_cannot_save_settings_even_with_a_valid_nonce(): void {
		// An "editor" is a real, common, non-admin WP role — capability
		// gate must reject it even though it's not a bare subscriber.
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_POST['_wpnonce']      = wp_create_nonce( 'clift_save_settings' );
		$_POST['business_name'] = 'Attacker Co';

		$this->expectException( 'WPDieException' );
		( new SettingsPage( $this->settings ) )->handle_save();
	}

	public function test_at16_editor_cannot_save_a_bar_even_with_a_valid_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$_POST['_wpnonce'] = wp_create_nonce( 'clift_save_bar' );
		$_POST['name']     = 'Hijacked bar';

		$this->expectException( 'WPDieException' );
		( new BarEditorPage( $this->settings ) )->handle_save();
	}

	public function test_at16_administrator_can_view_bars_screen(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		( new BarsPage( $this->settings ) )->render();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Bars', $output );
	}
}
