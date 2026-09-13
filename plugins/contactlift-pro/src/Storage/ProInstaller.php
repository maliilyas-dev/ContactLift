<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Creates Pro's own tables and option on activation — idempotent, via
 * dbDelta and a stored schema version, exactly as the spec's "Storage and
 * migrations" section requires. The free plugin creates only its own
 * options; only Pro creates these tables, only when Pro is activated.
 */
final class ProInstaller {

	public const DB_VERSION_OPTION = 'clift_pro_db_version';
	public const DB_VERSION        = 1;
	public const SETTINGS_OPTION   = 'clift_pro_settings';
	public const CLEANUP_HOOK      = 'clift_pro_daily_cleanup';

	public function install(): void {
		$this->maybe_migrate();

		if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
			add_option( self::SETTINGS_OPTION, $this->default_settings(), '', false );
		}

		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CLEANUP_HOOK );
		}
	}

	public function unschedule_cron(): void {
		$timestamp = wp_next_scheduled( self::CLEANUP_HOOK );

		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CLEANUP_HOOK );
		}
	}

	/**
	 * Safe to call on every request (cheap no-op once the stored version
	 * matches) — dbDelta's own diffing keeps re-running it idempotent even
	 * without the version guard, but the guard avoids the dbDelta include
	 * and query overhead on every single page load.
	 */
	public function maybe_migrate(): void {
		$current = (int) get_option( self::DB_VERSION_OPTION, 0 );

		if ( $current >= self::DB_VERSION ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		dbDelta( $this->callbacks_table_sql( $wpdb->prefix, $charset_collate ) );
		dbDelta( $this->daily_stats_table_sql( $wpdb->prefix, $charset_collate ) );
		dbDelta( $this->rate_limits_table_sql( $wpdb->prefix, $charset_collate ) );

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	private function callbacks_table_sql( string $prefix, string $charset_collate ): string {
		$table = $prefix . 'clift_callbacks';

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_uuid VARCHAR(36) NOT NULL,
			payload_hash VARCHAR(64) NOT NULL,
			created_at_utc DATETIME NOT NULL,
			updated_at_utc DATETIME NOT NULL,
			bar_id VARCHAR(64) NOT NULL,
			action_id VARCHAR(64) NOT NULL,
			source_type VARCHAR(32) NOT NULL,
			source_id BIGINT UNSIGNED NULL,
			name VARCHAR(100) NOT NULL,
			phone VARCHAR(30) NOT NULL,
			email VARCHAR(254) NULL,
			message TEXT NULL,
			consent_version VARCHAR(20) NOT NULL,
			consent_text TEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			private_notes TEXT NULL,
			notification_status VARCHAR(20) NOT NULL DEFAULT 'pending',
			attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY request_uuid (request_uuid),
			KEY created_at_utc (created_at_utc),
			KEY status_created (status, created_at_utc),
			KEY email (email)
		) {$charset_collate};";
	}

	private function daily_stats_table_sql( string $prefix, string $charset_collate ): string {
		$table = $prefix . 'clift_daily_stats';

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			stat_date DATE NOT NULL,
			bar_id VARCHAR(64) NOT NULL,
			action_id VARCHAR(64) NOT NULL,
			channel VARCHAR(32) NOT NULL,
			source_type VARCHAR(32) NOT NULL,
			source_id BIGINT UNSIGNED NULL,
			device VARCHAR(16) NOT NULL,
			click_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY stat_dimensions (stat_date, bar_id, action_id, channel, source_type, source_id, device),
			KEY stat_date (stat_date)
		) {$charset_collate};";
	}

	private function rate_limits_table_sql( string $prefix, string $charset_collate ): string {
		$table = $prefix . 'clift_rate_limits';

		return "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			bucket_key VARCHAR(64) NOT NULL,
			window_id VARCHAR(32) NOT NULL,
			counter INT UNSIGNED NOT NULL DEFAULT 0,
			expires_at_utc DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY bucket_window (bucket_key, window_id),
			KEY expires_at_utc (expires_at_utc)
		) {$charset_collate};";
	}

	/**
	 * @return array<string,mixed>
	 */
	private function default_settings(): array {
		return array(
			'schema_version'           => 1,
			'revision'                 => 1,
			'analytics_mode'           => 'off',
			'callback_retention_days'  => 90,
			'stats_retention_days'     => 90,
			'consent_copy'             => '',
			'consent_version'          => 1,
			'callback_recipients'      => array(),
			'privacy_url'              => '',
			'rate_limit_per_10min'     => 5,
			'rate_limit_per_hour'      => 100,
			'delete_data_on_uninstall' => false,
		);
	}
}
