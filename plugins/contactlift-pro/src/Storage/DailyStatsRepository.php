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
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (stat_date, bar_id, action_id, channel, source_type, source_id, device, click_count)
				VALUES (%s, %s, %s, %s, %s, %d, %s, 1)
				ON DUPLICATE KEY UPDATE click_count = click_count + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
				$date,
				$bar_id,
				$action_id,
				$channel,
				$source_type,
				$source_id ?? 0,
				$device
			)
		);
	}

	/**
	 * @param array{from?:string,to?:string,bar_id?:string,channel?:string,source_type?:string,device?:string} $filters
	 * @return array<int,array<string,mixed>>
	 */
	public function query( array $filters ): array {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE;
		$where  = array( '1=1' );
		$values = array();

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

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY stat_date DESC'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant; every value in $where is a %s/%d placeholder filled by $wpdb->prepare() below, never interpolated user input.

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $values ? $wpdb->get_results( $wpdb->prepare( $sql, $values ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql has no filter-supplied literals when $values is empty; every dynamic value otherwise flows through prepare() above.

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * "Daily scheduled cleanup deletes batches with indexed cutoffs" (spec)
	 * — one indexed DELETE, not a per-row loop.
	 */
	public function delete_older_than( string $cutoff_date ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE stat_date < %s", $cutoff_date ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
		);
	}
}
