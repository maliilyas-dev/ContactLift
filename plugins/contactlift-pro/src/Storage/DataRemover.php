<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * "Uninstall preserves data by default. The owner's explicit Delete data on
 * uninstall setting enables deletion of the plugin's own options, tables,
 * scheduled events and temporary rate-limit entries. Pro uninstall must not
 * delete the free bar... Include an administrator Data removal tool to remove
 * retained Pro data while both plugins are active." (spec, Deactivation and
 * uninstall.) One class behind both paths — uninstall.php and the admin tool
 * — so the same, tested code decides what "Pro's data" means.
 *
 * Never touches the free plugin's clift_settings / clift_bars.
 */
final class DataRemover {

	/**
	 * @return array{callbacks:int,stats:int}
	 */
	public function counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, live count for the admin screen.
		$callbacks = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . CallbackRepository::TABLE ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, live count for the admin screen.
		$stats = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . DailyStatsRepository::TABLE ) );

		return array(
			'callbacks' => $callbacks,
			'stats'     => $stats,
		);
	}

	/**
	 * The admin tool: delete the records in the chosen tables while the plugin
	 * stays installed and configured. Settings are left alone.
	 *
	 * @return array{callbacks:int,stats:int} Rows deleted from each table.
	 */
	public function remove_records( bool $callbacks, bool $stats ): array {
		global $wpdb;

		$removed = array(
			'callbacks' => 0,
			'stats'     => 0,
		);

		if ( $callbacks ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- owner-requested removal of the plugin's own records.
			$removed['callbacks'] = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . CallbackRepository::TABLE ) );
		}

		if ( $stats ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- owner-requested removal of the plugin's own records.
			$removed['stats'] = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i', $wpdb->prefix . DailyStatsRepository::TABLE ) );
		}

		return $removed;
	}

	/**
	 * Uninstall with the owner's opt-in: drops Pro's tables (rate-limit
	 * counters included), deletes its options and per-user transients, and
	 * unschedules its cron event.
	 */
	public function remove_everything(): void {
		global $wpdb;

		foreach ( array( CallbackRepository::TABLE, DailyStatsRepository::TABLE, RateLimiter::TABLE ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- owner-requested removal of the plugin's own custom table.
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $table ) );
		}

		delete_option( ProInstaller::SETTINGS_OPTION );
		delete_option( ProInstaller::DB_VERSION_OPTION );

		wp_clear_scheduled_hook( ProInstaller::CLEANUP_HOOK );

		// Per-user transients (the import preview, settings-form feedback) have
		// the user ID in their name, so find them by prefix — then delete each
		// through the API, not with SQL, so the object cache stays consistent
		// with the database. (On a site with an external object cache they
		// live only there and simply expire within minutes.)
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- core options table, one-off lookup of this plugin's own transients.
		$names = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( '_transient_clift_pro_' ) . '%' ) );

		foreach ( $names as $name ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		}
	}
}
