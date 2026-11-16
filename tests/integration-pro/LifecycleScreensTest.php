<?php
/**
 * The two admin surfaces for Pro's data lifecycle render correctly for an
 * administrator: the "On uninstall" opt-in on the Settings screen (which
 * previously had no control at all) and the Data removal card on Tools.
 * Rendering runs under a suite that turns PHP notices into failures, so a
 * sloppy template can't pass.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Integration;

use ContactLiftPro\Admin\DataRemovalExtension;
use ContactLiftPro\Admin\SettingsExtension;
use ContactLiftPro\Storage\ProInstaller;
use ContactLiftPro\Storage\ProSettingsRepository;
use WP_UnitTestCase;

final class LifecycleScreensTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function html_of( object $screen ): string {
		ob_start();
		$screen->render();

		return (string) ob_get_clean();
	}

	public function test_the_data_removal_card_renders_its_controls(): void {
		$html = $this->html_of( new DataRemovalExtension() );

		self::assertStringContainsString( 'Remove retained data', $html );
		self::assertStringContainsString( 'name="remove_callbacks"', $html );
		self::assertStringContainsString( 'name="remove_stats"', $html );
		self::assertStringContainsString( 'value="clift_pro_remove_data"', $html );
		self::assertMatchesRegularExpression( '/name="confirm"[^>]*required/', $html, 'the confirmation box is required in the form too, not only on the server' );
		self::assertStringContainsString( 'clift_pro_remove_data_nonce', $html );
	}

	public function test_the_removal_result_notice_reports_what_was_deleted(): void {
		$_GET['clift_pro_removed']           = '1';
		$_GET['clift_pro_removed_callbacks'] = '3';
		$_GET['clift_pro_removed_stats']     = '5';

		self::assertStringContainsString( 'Deleted 3 callback request(s) and 5 statistics row(s).', $this->html_of( new DataRemovalExtension() ) );
	}

	public function test_a_refused_removal_says_nothing_was_deleted(): void {
		$_GET['clift_pro_removed'] = '0';

		self::assertStringContainsString( 'Nothing was deleted.', $this->html_of( new DataRemovalExtension() ) );
	}

	public function test_the_settings_screen_offers_the_uninstall_opt_in_and_reflects_the_saved_value(): void {
		$settings = new ProSettingsRepository();

		update_option( ProInstaller::SETTINGS_OPTION, array_merge( $settings->get_settings(), array( 'delete_data_on_uninstall' => true ) ), false );
		$on = $this->html_of( new SettingsExtension() );

		update_option( ProInstaller::SETTINGS_OPTION, array_merge( $settings->get_settings(), array( 'delete_data_on_uninstall' => false ) ), false );
		$off = $this->html_of( new SettingsExtension() );

		self::assertStringContainsString( 'Delete all ContactLift Pro data when the plugin is deleted', $on );
		self::assertMatchesRegularExpression( '/name="delete_data_on_uninstall"[^>]*checked/', $on, 'ticked when the saved setting is on' );
		self::assertDoesNotMatchRegularExpression( '/name="delete_data_on_uninstall"[^>]*checked/', $off, 'not ticked when it is off' );
	}
}
