<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Storage;

use ContactLiftPro\Domain\Schedule\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * "Apply a baseline limit of five attempts per ten minutes per rotating
 * HMAC of the connecting IP, and 100 attempts per hour sitewide... Use
 * prepared statements... and atomic INSERT ON DUPLICATE KEY UPDATE
 * increments. Do not perform a SELECT followed by an unguarded UPDATE for
 * counters" (spec, Callback requests / Data consistency). The atomic
 * upsert happens first; the read-back afterward only ever sees the
 * already-correct post-increment value, so it can't reintroduce the race
 * the spec is warning against.
 */
final class RateLimiter {

	public const TABLE = 'clift_rate_limits';

	/**
	 * True if this attempt is allowed under the limit (and has been
	 * counted); false if the bucket is already at or over the limit for
	 * this window, or if the counter couldn't be safely written at all —
	 * failing closed, per spec, rather than allowing an unguarded request
	 * through when the abuse-control store itself is unavailable.
	 */
	public function attempt( string $bucket_key, int $window_seconds, int $limit, Clock $clock ): bool {
		global $wpdb;

		$table     = $wpdb->prefix . self::TABLE;
		$now       = $clock->now()->getTimestamp();
		$window_id = (string) intdiv( $now, max( 1, $window_seconds ) );
		$expires   = gmdate( 'Y-m-d H:i:s', $now + $window_seconds );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- rate-limit counters are intentionally uncached and short-lived; dbDelta-created custom table has no wp_query abstraction.
		$written = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (bucket_key, window_id, counter, expires_at_utc)
				VALUES (%s, %s, 1, %s)
				ON DUPLICATE KEY UPDATE counter = counter + 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
				$bucket_key,
				$window_id,
				$expires
			)
		);

		if ( false === $written ) {
			return false; // fail closed: the counter couldn't be written at all.
		}

		$counter = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT counter FROM {$table} WHERE bucket_key = %s AND window_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
				$bucket_key,
				$window_id
			)
		);

		return null !== $counter && (int) $counter <= $limit;
	}

	/**
	 * Deletes expired windows — called from the daily cleanup cron and
	 * opportunistically safe to call more often, per the spec's "expire
	 * stale windows during scheduled cleanup and opportunistically."
	 */
	public function prune_expired( Clock $clock ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$now   = gmdate( 'Y-m-d H:i:s', $clock->now()->getTimestamp() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bulk retention cleanup on a custom table, not a cached query.
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE expires_at_utc < %s", $now ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
		);
	}

	/**
	 * "Rotating HMAC of the connecting IP" (spec) — the bucket key changes
	 * with $rotation_key (the caller passes something like the current
	 * date) so the same visitor's buckets aren't linkable across rotations,
	 * while still being consistent within one. Only REMOTE_ADDR is trusted
	 * by default; the spec's "trust forwarded IP headers only through
	 * explicit server configuration" is a real per-site opt-in this session
	 * doesn't have a concrete server config to build against yet, so no
	 * X-Forwarded-For handling is implemented rather than half-securing it.
	 */
	public function bucket_key_for_ip( string $ip, string $rotation_key, string $secret ): string {
		return hash_hmac( 'sha256', $ip . '|' . $rotation_key, $secret );
	}
}
