<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * "Aggregate directly with atomic SQL increments... Use prepared
 * statements... and atomic INSERT ON DUPLICATE KEY UPDATE increments. Do
 * not perform a SELECT followed by an unguarded UPDATE for counters."
 * (spec). The reporting date bucket uses the site's timezone at event
 * receipt, per "Storage and migrations" — the caller supplies that already
 * -localized date string; this class is timezone-agnostic.
 */
final class DailyStatsRepository {

	public const TABLE = 'clift_daily_stats';

	/**
	 * $source_id is stored as 0, never SQL NULL, for a context with no
	 * specific object (home/archive) — MySQL's unique index treats every
	 * NULL as distinct from every other NULL, so a real NULL here would
	 * silently defeat the composite unique key this increment depends on:
	 * every home-page click would INSERT a new row instead of incrementing
	 * the existing one. 0 is never a real WordPress post ID, so it's a safe,
	 * unambiguous sentinel — see ReportsPage::source_label() for the
	 * matching display-side handling.
	 */
	public function increment( string $date, string $bar_id, string $action_id, string $channel, string $source_type, ?int $source_id, string $device ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, atomic counter by design.
		$wpdb->query( $wpdb->prepare( 'INSERT INTO %i (stat_date, bar_id, action_id, channel, source_type, source_id, device, click_count) VALUES (%s, %s, %s, %s, %s, %d, %s, 1) ON DUPLICATE KEY UPDATE click_count = click_count + 1', $table, $date, $bar_id, $action_id, $channel, $source_type, $source_id ?? 0, $device ) );
	}

	/**
	 * @param array{from?:string,to?:string,bar_id?:string,channel?:string,source_type?:string,device?:string} $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function query( array $filters ): array {
		global $wpdb;

		$where  = array( '1=1' );
		$values = array( $wpdb->prefix . self::TABLE );

		if ( ! empty( $filters['from'] ) ) {
			$where[]  = 'stat_date >= %s';
			$values[] = $filters['from'];
		}
		if ( ! empty( $filters['to'] ) ) {
			$where[]  = 'stat_date <= %s';
			$values[] = $filters['to'];
		}
		if ( ! empty( $filters['bar_id'] ) ) {
			$where[]  = 'bar_id = %s';
			$values[] = $filters['bar_id'];
		}
		if ( ! empty( $filters['channel'] ) ) {
			$where[]  = 'channel = %s';
			$values[] = $filters['channel'];
		}
		if ( ! empty( $filters['source_type'] ) ) {
			$where[]  = 'source_type = %s';
			$values[] = $filters['source_type'];
		}
		if ( ! empty( $filters['device'] ) ) {
			$where[]  = 'device = %s';
			$values[] = $filters['device'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom table, admin-facing report query; every element ever pushed into $where above is a fixed string literal from this method's own source, never derived from $filters' values — only whether a literal is included depends on $filters, not its text — and every actual dynamic value flows through the %i/%s placeholders resolved by prepare() in this same expression.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . implode( ' AND ', $where ) . ' ORDER BY stat_date DESC', $values ), ARRAY_A );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * "Daily scheduled cleanup deletes batches with indexed cutoffs" (spec)
	 * — one indexed DELETE, not a per-row loop.
	 */
	public function delete_older_than( string $cutoff_date ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk retention cleanup on a custom table, not a cached query.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE stat_date < %s', $wpdb->prefix . self::TABLE, $cutoff_date ) );
	}
}
