<?php
/**
 * The /runtime and /events REST flow end-to-end — AT18/AT19 from the
 * spec's acceptance suite.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Integration;

use ContactLift\Domain\Validation\EditionLimits;
use ContactLift\Storage\SettingsRepository;
use ContactLiftPro\Storage\DailyStatsRepository;
use ContactLiftPro\Storage\ProSettingsRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class ReportingRestTest extends WP_UnitTestCase {

	private SettingsRepository $settings;
	private ProSettingsRepository $pro_settings;
	private DailyStatsRepository $stats;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP core's own REST test-bootstrap global, not one this plugin defines.
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP core's own REST bootstrap hook, fired the same way core's own test suite does.

		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'clift_rate_limits' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test-only cleanup, fixed table name.
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'clift_daily_stats' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test-only cleanup, fixed table name.

		$this->settings     = new SettingsRepository();
		$this->pro_settings = new ProSettingsRepository();
		$this->stats        = new DailyStatsRepository();

		$this->settings->install();
		$this->settings->save_settings(
			array(
				'business_name' => 'Acme Clinic',
				'enabled'       => true,
			)
		);
		$this->settings->save_bars(
			array(
				array(
					'id'      => SettingsRepository::DEFAULT_BAR_ID,
					'name'    => 'Main contact bar',
					'enabled' => true,
					'device'  => 'all',
					'actions' => array(
						array(
							'id'          => 'action_call',
							'type'        => 'call',
							'label'       => 'Call us',
							'enabled'     => true,
							'destination' => '+15551234567',
						),
					),
				),
			),
			EditionLimits::pro(),
			SettingsRepository::DEFAULT_BAR_ID
		);

		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $this->post_id ) );
	}

	private function get_json( string $route, array $params ) {
		$request = new WP_REST_Request( 'GET', $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	private function post_json( string $route, array $body, array $headers = array() ) {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'content-type', 'application/json' );
		foreach ( $headers as $name => $value ) {
			$request->set_header( $name, $value );
		}
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	private function runtime_context(): string {
		$response = $this->get_json(
			'/contactlift/v1/runtime',
			array(
				'source_type' => 'post',
				'source_id'   => $this->post_id,
				'device'      => 'desktop',
			)
		);

		return (string) $response->get_data()['context'];
	}

	public function test_runtime_returns_the_eligible_bar_and_a_context_token(): void {
		$response = $this->get_json(
			'/contactlift/v1/runtime',
			array(
				'source_type' => 'post',
				'source_id'   => $this->post_id,
				'device'      => 'desktop',
			)
		);

		self::assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		self::assertSame( SettingsRepository::DEFAULT_BAR_ID, $data['bar']['id'] );
		self::assertNotEmpty( $data['context'] );
	}

	public function test_at18_repeated_events_for_the_same_action_atomically_increment_one_row(): void {
		$this->pro_settings->save_settings( array( 'analytics_mode' => 'local_aggregate' ) );
		$context = $this->runtime_context();

		for ( $i = 0; $i < 5; $i++ ) {
			// Each call gets a fresh context so the in-memory client-side
			// debounce (which this server-side test bypasses entirely) is
			// irrelevant here — this exercises the atomic increment itself.
			$response = $this->post_json(
				'/contactlift/v1/events',
				array(
					'context'   => $context,
					'action_id' => 'action_call',
					'device'    => 'desktop',
				)
			);
			self::assertTrue( $response->get_data()['recorded'] );

			// Each event's own 1-per-minute-per-bucket rate limit would
			// otherwise block the next of these 5 calls; only the atomic
			// increment behaviour is under test here.
			global $wpdb;
			$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'clift_rate_limits' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test-only cleanup, fixed table name.
		}

		$rows = $this->stats->query( array() );
		self::assertCount( 1, $rows, 'all 5 events aggregate into one dimensional row' );
		self::assertSame( 5, (int) $rows[0]['click_count'] );
	}

	public function test_at19_analytics_off_never_records(): void {
		$this->pro_settings->save_settings( array( 'analytics_mode' => 'off' ) );
		$context = $this->runtime_context();

		$response = $this->post_json(
			'/contactlift/v1/events',
			array(
				'context'   => $context,
				'action_id' => 'action_call',
				'device'    => 'desktop',
			)
		);

		self::assertFalse( $response->get_data()['recorded'] );
		self::assertSame( array(), $this->stats->query( array() ) );
	}

	public function test_at19_a_global_privacy_control_signal_stops_recording(): void {
		$this->pro_settings->save_settings( array( 'analytics_mode' => 'local_aggregate' ) );
		$context = $this->runtime_context();

		$response = $this->post_json(
			'/contactlift/v1/events',
			array(
				'context'   => $context,
				'action_id' => 'action_call',
				'device'    => 'desktop',
			),
			array( 'sec-gpc' => '1' )
		);

		self::assertFalse( $response->get_data()['recorded'] );
		self::assertSame( array(), $this->stats->query( array() ) );
	}

	public function test_at19_an_administrator_is_excluded_by_default(): void {
		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$this->pro_settings->save_settings( array( 'analytics_mode' => 'local_aggregate' ) );
		$context = $this->runtime_context();

		$response = $this->post_json(
			'/contactlift/v1/events',
			array(
				'context'   => $context,
				'action_id' => 'action_call',
				'device'    => 'desktop',
			)
		);

		self::assertFalse( $response->get_data()['recorded'] );
		self::assertSame( array(), $this->stats->query( array() ) );
	}

	public function test_an_event_with_a_tampered_context_is_rejected(): void {
		$this->pro_settings->save_settings( array( 'analytics_mode' => 'local_aggregate' ) );

		$response = $this->post_json(
			'/contactlift/v1/events',
			array(
				'context'   => 'not-a-real-token',
				'action_id' => 'action_call',
				'device'    => 'desktop',
			)
		);

		self::assertSame( 400, $response->get_status() );
	}
}
