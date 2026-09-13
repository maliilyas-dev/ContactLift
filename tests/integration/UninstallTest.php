<?php
/**
 * "Uninstall preserves data by default." Deletion only happens when the
 * owner's explicit delete_data_on_uninstall flag is set.
 *
 * @package ContactLift
 */

namespace ContactLift\Tests\Integration;

use ContactLift\Storage\SettingsRepository;
use WP_UnitTestCase;

final class UninstallTest extends WP_UnitTestCase {

	private const UNINSTALL_FILE = '/var/www/html/wp-content/plugins/contactlift/uninstall.php';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'contactlift/contactlift.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- exact constant name WordPress itself sets before including uninstall.php.
		}
	}

	public function test_data_is_preserved_by_default(): void {
		update_option(
			SettingsRepository::SETTINGS_OPTION,
			array(
				'business_name'            => 'Acme Clinic',
				'delete_data_on_uninstall' => false,
			),
			false
		);
		update_option( SettingsRepository::BARS_OPTION, array( 'bars' => array() ), false );

		include self::UNINSTALL_FILE;

		$this->assertIsArray( get_option( SettingsRepository::SETTINGS_OPTION ) );
		$this->assertIsArray( get_option( SettingsRepository::BARS_OPTION ) );
	}

	public function test_data_is_deleted_when_the_owner_opted_in(): void {
		update_option(
			SettingsRepository::SETTINGS_OPTION,
			array(
				'business_name'            => 'Acme Clinic',
				'delete_data_on_uninstall' => true,
			),
			false
		);
		update_option( SettingsRepository::BARS_OPTION, array( 'bars' => array() ), false );

		include self::UNINSTALL_FILE;

		$this->assertFalse( get_option( SettingsRepository::SETTINGS_OPTION ) );
		$this->assertFalse( get_option( SettingsRepository::BARS_OPTION ) );
	}
}
