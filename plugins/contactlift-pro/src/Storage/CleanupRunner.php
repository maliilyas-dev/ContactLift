<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Storage;

use ContactLiftPro\Domain\Schedule\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * "Default retention is 90 days for callback requests and 90 days for
 * reporting aggregates... Daily scheduled cleanup deletes batches with
 * indexed cutoffs. Show the last cleanup time and offer Run cleanup now."
 * (spec). Shared by the daily cron and the admin "Run cleanup now" button
 * so both paths do exactly the same thing.
 */
final class CleanupRunner {

	public const LAST_RUN_OPTION = 'clift_pro_last_cleanup';

	public function run( Clock $clock ): void {
		$settings = ( new ProSettingsRepository() )->get_settings();

		$callback_cutoff = $clock->now()->modify( '-' . (int) ( $settings['callback_retention_days'] ?? 90 ) . ' days' );
		( new CallbackRepository() )->delete_older_than( $callback_cutoff );

		$stats_cutoff = $clock->now()->modify( '-' . (int) ( $settings['stats_retention_days'] ?? 90 ) . ' days' )->format( 'Y-m-d' );
		( new DailyStatsRepository() )->delete_older_than( $stats_cutoff );

		( new RateLimiter() )->prune_expired( $clock );

		update_option( self::LAST_RUN_OPTION, $clock->now()->format( 'c' ), false );
	}

	public function last_run_at(): ?string {
		$value = get_option( self::LAST_RUN_OPTION, false );

		return is_string( $value ) ? $value : null;
	}
}
