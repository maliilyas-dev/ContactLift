<?php
/**
 * RateLimiter against the real database — AT17 from the spec's acceptance
 * suite ("rate-limit threshold and expired window: predictable 429 and
 * recovery after expiry"). The REST-level 429 is asserted in
 * CallbackRestTest; this covers the half that needs an injectable clock,
 * recovery once a window has expired.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Integration;

use ContactLiftPro\Domain\Schedule\Clock;
use ContactLiftPro\Storage\RateLimiter;
use WP_UnitTestCase;

final class RateLimiterTest extends WP_UnitTestCase {

	/**
	 * An exact multiple of 600 (and far in the past), so +600 seconds lands
	 * precisely on the next ten-minute window, and pruning at this fake time
	 * can never touch a row written by a test using the real clock.
	 */
	private const WINDOW_START = 1000000200;

	private function clock_at( int $timestamp ): Clock {
		return Clock::at( new \DateTimeImmutable( '@' . $timestamp ) );
	}

	private function row_count( string $bucket_key ): int {
		global $wpdb;

		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE bucket_key = %s', $wpdb->prefix . RateLimiter::TABLE, $bucket_key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only assertion query.
	}

	public function test_at17_attempts_over_the_limit_are_refused_then_allowed_again_once_the_window_expires(): void {
		$limiter = new RateLimiter();
		$key     = 'at17-' . wp_generate_uuid4();

		self::assertTrue( $limiter->attempt( $key, 600, 2, $this->clock_at( self::WINDOW_START ) ) );
		self::assertTrue( $limiter->attempt( $key, 600, 2, $this->clock_at( self::WINDOW_START + 1 ) ) );
		self::assertFalse( $limiter->attempt( $key, 600, 2, $this->clock_at( self::WINDOW_START + 2 ) ), 'the third attempt inside the window is refused' );
		self::assertFalse( $limiter->attempt( $key, 600, 2, $this->clock_at( self::WINDOW_START + 599 ) ), 'still refused on the last second of the window' );

		self::assertTrue( $limiter->attempt( $key, 600, 2, $this->clock_at( self::WINDOW_START + 600 ) ), 'the next window starts a fresh count' );
	}

	public function test_different_buckets_never_share_a_count(): void {
		$limiter = new RateLimiter();
		$a       = 'at17-a-' . wp_generate_uuid4();
		$b       = 'at17-b-' . wp_generate_uuid4();
		$now     = $this->clock_at( self::WINDOW_START );

		self::assertTrue( $limiter->attempt( $a, 600, 1, $now ) );
		self::assertFalse( $limiter->attempt( $a, 600, 1, $now ) );
		self::assertTrue( $limiter->attempt( $b, 600, 1, $now ), 'a different visitor is unaffected by the first one hitting the limit' );
	}

	public function test_prune_expired_removes_only_windows_that_have_expired(): void {
		$limiter  = new RateLimiter();
		$expiring = 'at17-expiring-' . wp_generate_uuid4();
		$lasting  = 'at17-lasting-' . wp_generate_uuid4();

		$limiter->attempt( $expiring, 600, 5, $this->clock_at( self::WINDOW_START ) );
		$limiter->attempt( $lasting, 3600, 5, $this->clock_at( self::WINDOW_START ) );

		self::assertSame( 1, $this->row_count( $expiring ) );
		self::assertSame( 1, $this->row_count( $lasting ) );

		$limiter->prune_expired( $this->clock_at( self::WINDOW_START + 601 ) );

		self::assertSame( 0, $this->row_count( $expiring ), 'the ten-minute window has expired and is pruned' );
		self::assertSame( 1, $this->row_count( $lasting ), 'the one-hour window is still live and is kept' );
	}

	public function test_attempt_fails_closed_when_the_counter_cannot_be_written(): void {
		$break_insert = static function ( $sql ) {
			if ( is_string( $sql ) && false !== stripos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, 'clift_rate_limits' ) ) {
				return 'INSERT INTO clift_at17_table_that_does_not_exist (id) VALUES (1)';
			}

			return $sql;
		};

		add_filter( 'query', $break_insert );

		global $wpdb;
		$suppress_was = $wpdb->suppress_errors( true );
		$allowed      = ( new RateLimiter() )->attempt( 'at17-closed-' . wp_generate_uuid4(), 600, 100, $this->clock_at( self::WINDOW_START ) );
		$wpdb->suppress_errors( $suppress_was );

		remove_filter( 'query', $break_insert );

		self::assertFalse( $allowed, 'an unavailable abuse-control store must refuse, not let the request through' );
	}
}
