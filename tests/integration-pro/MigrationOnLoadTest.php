<?php
/**
 * "Idempotent migrations with a stored schema version" (spec) only help if
 * they actually run: plugin updates never fire the activation hook, and a
 * restored or copied site can lack the tables. Pro therefore migrates on
 * load, and once the stored version matches that costs no query.
 *
 * Runs against a throwaway table prefix (CREATE TABLE is rewritten to a
 * TEMPORARY table by WP_UnitTestCase), so the suite's own tables are safe.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Integration;

use ContactLiftPro\Plugin;
use ContactLiftPro\Storage\CallbackRepository;
use ContactLiftPro\Storage\DailyStatsRepository;
use ContactLiftPro\Storage\ProInstaller;
use ContactLiftPro\Storage\RateLimiter;
use WP_UnitTestCase;

final class MigrationOnLoadTest extends WP_UnitTestCase {

	private string $real_prefix = '';

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

	public function test_the_migration_is_hooked_to_init_not_only_to_activation(): void {
		self::assertSame( 1, has_action( 'init', array( Plugin::instance(), 'maybe_migrate' ) ) );
	}

	public function test_missing_tables_are_created_on_load_and_the_next_check_costs_nothing(): void {
		global $wpdb;

		$wpdb->prefix = 'migrationtest_';
		delete_option( ProInstaller::DB_VERSION_OPTION );

		$tables = array(
			$wpdb->prefix . CallbackRepository::TABLE,
			$wpdb->prefix . DailyStatsRepository::TABLE,
			$wpdb->prefix . RateLimiter::TABLE,
		);

		foreach ( $tables as $table ) {
			self::assertFalse( $this->table_exists( $table ), "precondition: {$table} does not exist yet" );
		}

		Plugin::instance()->maybe_migrate();

		foreach ( $tables as $table ) {
			self::assertTrue( $this->table_exists( $table ), "{$table} was created on load" );
		}
		self::assertSame( ProInstaller::DB_VERSION, (int) get_option( ProInstaller::DB_VERSION_OPTION ) );

		$queries_before = $wpdb->num_queries;
		Plugin::instance()->maybe_migrate();

		self::assertSame( $queries_before, $wpdb->num_queries, 'once the stored version matches, the check issues no query at all' );
	}
}
