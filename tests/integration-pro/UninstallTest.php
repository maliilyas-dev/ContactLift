<?php
/**
 * The spec's lifecycle rules for Pro: "Uninstall preserves data by default.
 * The owner's explicit Delete data on uninstall setting enables deletion of
 * the plugin's own options, tables, scheduled events and temporary
 * rate-limit entries. Pro uninstall must not delete the free bar."
 *
 * The delete path really drops tables, so it runs against a throwaway table
 * prefix: WP_UnitTestCase rewrites CREATE TABLE / DROP TABLE to their
 * TEMPORARY forms, which keeps the suite's own tables out of harm's way
 * while still proving uninstall.php drops what it should.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Integration;

use ContactLiftPro\Storage\CallbackRepository;
use ContactLiftPro\Storage\DailyStatsRepository;
use ContactLiftPro\Storage\ProInstaller;
use ContactLiftPro\Storage\RateLimiter;
use WP_UnitTestCase;

final class UninstallTest extends WP_UnitTestCase {

	private const UNINSTALL_FILE = '/var/www/html/wp-content/plugins/contactlift-pro/uninstall.php';

	private string $real_prefix = '';

	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'contactlift-pro/contactlift-pro.php' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- exact constant name WordPress itself sets before including uninstall.php.
		}
	}

	public function set_up(): void {
		parent::set_up();

		global $wpdb;
		$this->real_prefix = $wpdb->prefix;
	}

	public function tear_down(): void {
		global $wpdb;
		$wpdb->prefix = $this->real_prefix;

		parent::tear_down();
	}

	private function table_exists( string $name ): bool {
		global $wpdb;

		$suppress_was     = $wpdb->suppress_errors( true );
		$wpdb->last_error = '';

		$wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only existence probe.

		$exists = '' === $wpdb->last_error;
		$wpdb->suppress_errors( $suppress_was );

		return $exists;
	}

	private function seed_callback( string $name ): void {
		( new CallbackRepository() )->insert_if_new(
			array(
				'request_uuid'    => wp_generate_uuid4(),
				'bar_id'          => 'bar',
				'action_id'       => 'action',
				'source_type'     => 'home',
				'source_id'       => null,
				'name'            => $name,
				'phone'           => '+15550000000',
				'email'           => '',
				'message'         => '',
				'consent_version' => '1',
				'consent_text'    => 'agreed',
			),
			hash( 'sha256', $name )
		);
	}

	public function test_everything_is_preserved_by_default(): void {
		update_option( ProInstaller::SETTINGS_OPTION, array( 'delete_data_on_uninstall' => false ), false );
		$this->seed_callback( 'Kept' );

		include self::UNINSTALL_FILE;

		self::assertIsArray( get_option( ProInstaller::SETTINGS_OPTION ), 'settings survive' );
		self::assertCount( 1, ( new CallbackRepository() )->list(), 'the callback request survives' );
		self::assertTrue( $this->table_exists( $this->real_prefix . CallbackRepository::TABLE ) );
	}

	public function test_nothing_is_deleted_when_the_flag_was_never_set(): void {
		update_option( ProInstaller::SETTINGS_OPTION, array( 'analytics_mode' => 'off' ), false );
		$this->seed_callback( 'Also kept' );

		include self::UNINSTALL_FILE;

		self::assertIsArray( get_option( ProInstaller::SETTINGS_OPTION ) );
		self::assertCount( 1, ( new CallbackRepository() )->list() );
	}

	public function test_opting_in_removes_pro_data_and_only_pro_data(): void {
		global $wpdb;

		$wpdb->prefix = 'uninstalltest_';

		// Fresh, throwaway copies of the three tables under that prefix.
		delete_option( ProInstaller::DB_VERSION_OPTION );
		( new ProInstaller() )->maybe_migrate();

		$tables = array(
			$wpdb->prefix . CallbackRepository::TABLE,
			$wpdb->prefix . DailyStatsRepository::TABLE,
			$wpdb->prefix . RateLimiter::TABLE,
		);

		foreach ( $tables as $table ) {
			self::assertTrue( $this->table_exists( $table ), "precondition: {$table} exists" );
		}

		$this->seed_callback( 'Goes away' );

		update_option( ProInstaller::SETTINGS_OPTION, array( 'delete_data_on_uninstall' => true ), false );
		update_option( 'clift_settings', array( 'business_name' => 'Keep me' ), false );
		update_option( 'clift_bars', array( 'bars' => array( array( 'id' => 'bar_default' ) ) ), false );
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', ProInstaller::CLEANUP_HOOK );
		set_transient( 'clift_pro_import_99', array( 'pending' => true ), 600 );

		include self::UNINSTALL_FILE;

		foreach ( $tables as $table ) {
			self::assertFalse( $this->table_exists( $table ), "{$table} was dropped" );
		}

		self::assertFalse( get_option( ProInstaller::SETTINGS_OPTION ), 'Pro settings deleted' );
		self::assertFalse( get_option( ProInstaller::DB_VERSION_OPTION ), 'Pro schema version deleted' );
		self::assertFalse( wp_next_scheduled( ProInstaller::CLEANUP_HOOK ), 'the cleanup event was unscheduled' );
		self::assertFalse( get_transient( 'clift_pro_import_99' ), 'Pro transients deleted' );

		self::assertSame( 'Keep me', get_option( 'clift_settings' )['business_name'], 'the free plugin\'s settings are untouched' );
		self::assertSame( 'bar_default', get_option( 'clift_bars' )['bars'][0]['id'], 'the free bar is untouched' );
	}
}
