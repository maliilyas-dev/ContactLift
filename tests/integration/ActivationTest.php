<?php
/**
 * "Create defaults without overwriting existing settings" — and repeated
 * activation must not duplicate or reset anything.
 *
 * @package ContactLift
 */

namespace ContactLift\Tests\Integration;

use ContactLift\Storage\SettingsRepository;
use WP_UnitTestCase;

final class ActivationTest extends WP_UnitTestCase {

	public function test_install_creates_non_autoloaded_defaults(): void {
		delete_option( SettingsRepository::SETTINGS_OPTION );
		delete_option( SettingsRepository::BARS_OPTION );

		( new SettingsRepository() )->install();

		$settings = get_option( SettingsRepository::SETTINGS_OPTION );
		$bars     = get_option( SettingsRepository::BARS_OPTION );

		$this->assertIsArray( $settings );
		$this->assertFalse( $settings['enabled'] );
		$this->assertSame( '', $settings['business_name'] );

		$this->assertIsArray( $bars );
		$this->assertCount( 1, $bars['bars'] );
		$this->assertSame( array(), $bars['bars'][0]['actions'], 'no placeholder action/destination ships by default' );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- verifying the actual DB-level autoload flag is what this test is for; get_option() wouldn't expose it, and no caching is warranted for a one-off test assertion.
		$autoload = $wpdb->get_var(
			$wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", SettingsRepository::SETTINGS_OPTION )
		);
		// WP 6.6+ stores autoload as 'on'/'off' rather than the legacy 'yes'/'no'.
		$this->assertContains( $autoload, array( 'no', 'off' ) );
	}

	public function test_repeated_activation_does_not_overwrite_existing_settings(): void {
		$repository = new SettingsRepository();
		$repository->install();

		$repository->save_settings(
			array(
				'business_name' => 'Acme Clinic',
				'enabled'       => true,
			)
		);

		// Simulate a second activation (e.g. deactivate/reactivate).
		$repository->install();

		$settings = get_option( SettingsRepository::SETTINGS_OPTION );
		$this->assertSame( 'Acme Clinic', $settings['business_name'] );
		$this->assertTrue( $settings['enabled'] );
	}
}
