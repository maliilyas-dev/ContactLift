<?php
/**
 * The callback REST flow end-to-end, through the real WP REST server —
 * AT12-AT16 from the spec's acceptance suite.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Integration;

use ContactLift\Domain\Validation\EditionLimits;
use ContactLift\Storage\SettingsRepository;
use ContactLiftPro\Storage\CallbackRepository;
use ContactLiftPro\Storage\ProSettingsRepository;
use WP_REST_Request;
use WP_UnitTestCase;

final class CallbackRestTest extends WP_UnitTestCase {

	private SettingsRepository $settings;
	private ProSettingsRepository $pro_settings;
	private CallbackRepository $repository;
	private int $post_id;

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new \WP_REST_Server(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP core's own REST test-bootstrap global, not one this plugin defines.
		do_action( 'rest_api_init', $wp_rest_server ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals -- WP core's own REST bootstrap hook, fired the same way core's own test suite does.

		$this->settings     = new SettingsRepository();
		$this->pro_settings = new ProSettingsRepository();
		$this->repository   = new CallbackRepository();

		// Rate-limit counters are keyed by IP + day, so every test in this
		// class shares one bucket within the same test run; WP_UnitTestCase's
		// transactional rollback doesn't reliably reset this custom table
		// between tests, so it's cleared explicitly instead.
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'clift_rate_limits' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- test-only cleanup, fixed table name.

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
							'id'      => 'action_callback',
							'type'    => 'callback',
							'label'   => 'Request a callback',
							'enabled' => true,
						),
					),
				),
			),
			EditionLimits::pro(),
			SettingsRepository::DEFAULT_BAR_ID
		);

		$this->pro_settings->save_settings(
			array(
				'callback_recipients'  => array( 'owner@example.com' ),
				'privacy_url'          => 'https://example.com/privacy',
				'rate_limit_per_10min' => 5,
				'rate_limit_per_hour'  => 100,
			)
		);

		$this->post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $this->post_id ) );
	}

	private function post_json( string $route, array $body ) {
		$request = new WP_REST_Request( 'POST', $route );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $body ) );

		return rest_get_server()->dispatch( $request );
	}

	private function request_challenge(): array {
		$response = $this->post_json(
			'/contactlift/v1/callback-challenge',
			array(
				'source_type' => 'post',
				'source_id'   => $this->post_id,
				'bar_id'      => SettingsRepository::DEFAULT_BAR_ID,
				'action_id'   => 'action_callback',
			)
		);

		self::assertSame( 200, $response->get_status() );

		return $response->get_data();
	}

	private function submit( string $token, array $overrides = array() ) {
		return $this->post_json(
			'/contactlift/v1/callbacks',
			array_merge(
				array(
					'token'   => $token,
					'name'    => 'Jane Doe',
					'phone'   => '+15551234567',
					'email'   => '',
					'message' => '',
					'consent' => true,
					'website' => '',
				),
				$overrides
			)
		);
	}

	public function test_happy_path_challenge_then_submit_creates_a_record(): void {
		$challenge = $this->request_challenge();
		$response  = $this->submit( $challenge['token'] );

		self::assertSame( 201, $response->get_status() );
		self::assertSame( 'received', $response->get_data()['status'] );

		$record = $this->repository->get_by_uuid( $response->get_data()['request_uuid'] );
		self::assertNotNull( $record );
		self::assertSame( 'Jane Doe', $record['name'] );
		self::assertSame( (string) $this->post_id, (string) $record['source_id'] );
	}

	public function test_at12_two_retries_with_the_same_uuid_and_payload_store_only_one_request(): void {
		$challenge = $this->request_challenge();

		$first  = $this->submit( $challenge['token'] );
		$second = $this->submit( $challenge['token'] ); // simulated retry: same token, same fields.

		self::assertSame( 201, $first->get_status() );
		self::assertSame( 200, $second->get_status(), 'a retry of an already-accepted UUID returns 200, not another 201' );

		$uuid = $first->get_data()['request_uuid'];
		self::assertSame( $uuid, $second->get_data()['request_uuid'] );

		global $wpdb;
		$table = $wpdb->prefix . CallbackRepository::TABLE;
		$count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE request_uuid = %s', $table, $uuid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only assertion query.

		self::assertSame( 1, $count, 'only one row exists for this UUID' );

		$record = $this->repository->get_by_uuid( $uuid );
		self::assertSame( 1, (int) $record['attempts'], 'the duplicate must not trigger a second notification attempt' );
	}

	public function test_at13_same_uuid_with_a_different_payload_returns_conflict_and_does_not_leak_the_record(): void {
		$challenge = $this->request_challenge();

		$first       = $this->submit( $challenge['token'] );
		$conflicting = $this->submit( $challenge['token'], array( 'name' => 'A Different Name' ) );

		self::assertSame( 409, $conflicting->get_status() );
		self::assertArrayNotHasKey( 'name', $conflicting->get_data() );
		self::assertArrayNotHasKey( 'phone', $conflicting->get_data() );

		$record = $this->repository->get_by_uuid( $first->get_data()['request_uuid'] );
		self::assertSame( 'Jane Doe', $record['name'], 'the original stored record is unchanged' );
	}

	public function test_at14_a_mail_failure_after_insertion_retains_the_request_and_marks_notification_failed(): void {
		add_filter( 'pre_wp_mail', '__return_false' );

		$challenge = $this->request_challenge();
		$response  = $this->submit( $challenge['token'] );

		remove_filter( 'pre_wp_mail', '__return_false' );

		self::assertSame( 201, $response->get_status(), 'the request is still stored even though notification will fail' );

		$record = $this->repository->get_by_uuid( $response->get_data()['request_uuid'] );
		self::assertSame( CallbackRepository::NOTIF_FAILED, $record['notification_status'] );
	}

	public function test_at15_a_database_failure_is_never_reported_as_success(): void {
		$challenge = $this->request_challenge();

		$mail_attempts = 0;
		$count_mail    = static function () use ( &$mail_attempts ) {
			++$mail_attempts;

			return true;
		};

		// Turns only the callback INSERT into a statement against a table that
		// doesn't exist — a genuine database failure from wpdb's point of view,
		// while the rate limiter's own queries on the same request still work.
		$break_insert = static function ( $sql ) {
			if ( is_string( $sql ) && false !== stripos( $sql, 'INSERT INTO' ) && false !== strpos( $sql, 'clift_callbacks' ) ) {
				return 'INSERT INTO clift_at15_table_that_does_not_exist (id) VALUES (1)';
			}

			return $sql;
		};

		add_filter( 'pre_wp_mail', $count_mail );
		add_filter( 'query', $break_insert );

		$response = $this->submit( $challenge['token'] );

		remove_filter( 'query', $break_insert );
		remove_filter( 'pre_wp_mail', $count_mail );

		self::assertSame( 500, $response->get_status() );
		self::assertSame( 'server_error', $response->get_data()['code'] );
		self::assertArrayNotHasKey( 'status', $response->get_data(), 'a failed write must never carry the "received" status' );
		self::assertSame( 0, $mail_attempts, 'no notification may go out for a request that was never stored' );
		self::assertSame( array(), $this->repository->list(), 'nothing was stored' );
	}

	public function test_at11_a_variation_belonging_to_a_different_product_is_rejected(): void {
		if ( ! function_exists( 'wc_get_product' ) ) {
			self::markTestSkipped( 'WooCommerce is not loaded in this environment.' );
		}

		$product_a      = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);
		$product_b      = self::factory()->post->create(
			array(
				'post_type'   => 'product',
				'post_status' => 'publish',
			)
		);
		$variation_of_b = self::factory()->post->create(
			array(
				'post_type'   => 'product_variation',
				'post_status' => 'publish',
				'post_parent' => $product_b,
			)
		);
		update_post_meta( $variation_of_b, '_price', '10' );

		$challenge_for = function ( int $product_id, int $variation_id ) {
			return $this->post_json(
				'/contactlift/v1/callback-challenge',
				array(
					'source_type'  => 'product',
					'source_id'    => $product_id,
					'variation_id' => $variation_id,
					'bar_id'       => SettingsRepository::DEFAULT_BAR_ID,
					'action_id'    => 'action_callback',
				)
			);
		};

		self::assertSame( 200, $challenge_for( $product_b, $variation_of_b )->get_status(), 'control: a variation of the very product being viewed is accepted' );

		$mismatch = $challenge_for( $product_a, $variation_of_b );
		self::assertSame( 400, $mismatch->get_status(), 'a variation that belongs to another product is rejected' );
		self::assertSame( 'invalid_context', $mismatch->get_data()['code'] );
		self::assertArrayNotHasKey( 'token', $mismatch->get_data() );

		self::assertSame( 400, $challenge_for( $product_a, $product_b )->get_status(), 'an ID that is not a variation at all is rejected' );
	}

	public function test_at11_a_variation_id_is_rejected_outside_a_product_context(): void {
		$response = $this->post_json(
			'/contactlift/v1/callback-challenge',
			array(
				'source_type'  => 'post',
				'source_id'    => $this->post_id,
				'variation_id' => $this->post_id,
				'bar_id'       => SettingsRepository::DEFAULT_BAR_ID,
				'action_id'    => 'action_callback',
			)
		);

		self::assertSame( 400, $response->get_status() );
		self::assertArrayNotHasKey( 'token', $response->get_data() );
	}

	public function test_invalid_challenge_token_is_rejected(): void {
		$response = $this->submit( 'not-a-real-token' );

		self::assertSame( 400, $response->get_status() );
	}

	public function test_a_filled_honeypot_is_rejected_generically(): void {
		$challenge = $this->request_challenge();
		$response  = $this->submit( $challenge['token'], array( 'website' => 'http://spam.example' ) );

		self::assertSame( 400, $response->get_status() );
		self::assertSame( array(), $this->repository->list(), 'nothing was stored for a honeypot-tripped submission' );
	}

	public function test_missing_required_fields_are_rejected_with_field_errors(): void {
		$challenge = $this->request_challenge();
		$response  = $this->submit(
			$challenge['token'],
			array(
				'name'    => '',
				'consent' => false,
			)
		);

		self::assertSame( 400, $response->get_status() );
		self::assertArrayHasKey( 'errors', $response->get_data() );
	}

	public function test_a_private_post_is_rejected_as_context(): void {
		$private_post = self::factory()->post->create( array( 'post_status' => 'private' ) );

		$response = $this->post_json(
			'/contactlift/v1/callback-challenge',
			array(
				'source_type' => 'post',
				'source_id'   => $private_post,
				'bar_id'      => SettingsRepository::DEFAULT_BAR_ID,
				'action_id'   => 'action_callback',
			)
		);

		self::assertSame( 400, $response->get_status() );
	}

	public function test_challenge_is_refused_for_an_unknown_action_id(): void {
		$response = $this->post_json(
			'/contactlift/v1/callback-challenge',
			array(
				'source_type' => 'post',
				'source_id'   => $this->post_id,
				'bar_id'      => SettingsRepository::DEFAULT_BAR_ID,
				'action_id'   => 'not-a-real-action',
			)
		);

		self::assertSame( 400, $response->get_status() );
	}

	public function test_challenge_is_refused_when_recipients_are_not_configured(): void {
		$this->pro_settings->save_settings(
			array(
				'callback_recipients' => array(),
				'privacy_url'         => 'https://example.com/privacy',
			)
		);

		$response = $this->post_json(
			'/contactlift/v1/callback-challenge',
			array(
				'source_type' => 'post',
				'source_id'   => $this->post_id,
				'bar_id'      => SettingsRepository::DEFAULT_BAR_ID,
				'action_id'   => 'action_callback',
			)
		);

		self::assertSame( 400, $response->get_status() );
	}

	public function test_the_rate_limit_returns_429_with_retry_after(): void {
		$this->pro_settings->save_settings(
			array(
				'callback_recipients'  => array( 'owner@example.com' ),
				'privacy_url'          => 'https://example.com/privacy',
				'rate_limit_per_10min' => 2,
				'rate_limit_per_hour'  => 100,
			)
		);

		$args = array(
			'source_type' => 'post',
			'source_id'   => $this->post_id,
			'bar_id'      => SettingsRepository::DEFAULT_BAR_ID,
			'action_id'   => 'action_callback',
		);

		$this->post_json( '/contactlift/v1/callback-challenge', $args );
		$this->post_json( '/contactlift/v1/callback-challenge', $args );
		$third = $this->post_json( '/contactlift/v1/callback-challenge', $args );

		self::assertSame( 429, $third->get_status() );
		self::assertNotEmpty( $third->get_headers()['Retry-After'] ?? null );
	}
}
