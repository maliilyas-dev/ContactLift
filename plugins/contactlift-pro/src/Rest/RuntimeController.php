<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Rest;

use ContactLift\Domain\BarState;
use ContactLift\Frontend\ActionLinkBuilder;
use ContactLiftPro\Domain\Callbacks\ChallengeSigner;
use ContactLiftPro\Domain\Reports\AnalyticsGate;
use ContactLiftPro\Domain\Schedule\Clock;
use ContactLiftPro\Domain\Schedule\ClosedActionResolver;
use ContactLiftPro\Domain\Schedule\ScheduleEvaluator;
use ContactLiftPro\Domain\Targeting\BarTargetingResolver;
use ContactLiftPro\Domain\Targeting\MatchContext;
use ContactLiftPro\Frontend\ContextValidator;
use ContactLiftPro\Storage\DailyStatsRepository;
use ContactLiftPro\Storage\ProSettingsRepository;
use ContactLiftPro\Storage\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * GET /runtime (stale-cache recovery — "Pro pages fetch one small
 * same-origin runtime response to resolve the current configuration,
 * schedule and public content context") and POST /events (local aggregate
 * click reporting). Both public-by-design, no-store, allowlisted-output
 * routes per "Public API and authorisation."
 */
final class RuntimeController {

	private const RUNTIME_TTL_SECONDS  = 300; // matches the spec's "at least every five minutes while visible" client poll floor.
	private const MAX_EVENT_BODY_BYTES = 2048;

	private ContextValidator $context_validator;
	private BarTargetingResolver $targeting;
	private ScheduleEvaluator $schedule;
	private ClosedActionResolver $closed_actions;
	private ChallengeSigner $signer;
	private ProSettingsRepository $pro_settings;
	private RateLimiter $rate_limiter;
	private DailyStatsRepository $stats;

	public function __construct() {
		$this->context_validator = new ContextValidator();
		$this->targeting         = new BarTargetingResolver();
		$this->schedule          = new ScheduleEvaluator();
		$this->closed_actions    = new ClosedActionResolver();
		$this->signer            = new ChallengeSigner( wp_salt( 'auth' ) );
		$this->pro_settings      = new ProSettingsRepository();
		$this->rate_limiter      = new RateLimiter();
		$this->stats             = new DailyStatsRepository();
	}

	public function register_routes(): void {
		register_rest_route(
			'contactlift/v1',
			'/runtime',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_runtime' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'source_type' => array(
						'type'     => 'string',
						'required' => true,
					),
					'source_id'   => array(
						'type'     => 'integer',
						'required' => false,
					),
					'device'      => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'contactlift/v1',
			'/events',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_event' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'context'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'action_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'device'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	public function handle_runtime( WP_REST_Request $request ) {
		$source = $this->context_validator->validate_source( $request->get_param( 'source_type' ), $request->get_param( 'source_id' ) );
		$device = 'mobile' === $request->get_param( 'device' ) ? 'mobile' : 'desktop';

		if ( null === $source ) {
			return $this->no_store(
				array(
					'bar'            => null,
					'clock'          => gmdate( 'c' ),
					'valid_until'    => gmdate( 'c', time() + self::RUNTIME_TTL_SECONDS ),
					'next_change_at' => null,
					'context'        => null,
				)
			);
		}

		$document = \ContactLift\Plugin::instance()->settings()->get_bars_document();
		$bars     = $document['bars'] ?? array();
		$match    = new MatchContext( $source['source_id'], array() );
		$winner   = $this->targeting->resolve( $bars, $document['default_bar_id'] ?? 'bar_default', $match, $device );

		$bar_presentation = null;
		$context_token    = null;

		if ( null !== $winner ) {
			$is_open           = $this->schedule->is_open( $winner['schedule'] ?? array(), wp_timezone(), Clock::system() );
			$winner['actions'] = $this->closed_actions->apply( $winner['actions'] ?? array(), $is_open );

			if ( ! empty( $winner['enabled'] ) && BarState::has_renderable_action( $winner ) ) {
				$bar_presentation = $this->public_bar_fields( $winner );

				$context_token = $this->signer->issue(
					array(
						'source_type' => $source['source_type'],
						'source_id'   => $source['source_id'],
						'bar_id'      => $winner['id'],
						'device'      => $device,
					),
					Clock::system(),
					self::RUNTIME_TTL_SECONDS
				)['token'];
			}
		}

		return $this->no_store(
			array(
				'bar'            => $bar_presentation,
				'clock'          => gmdate( 'c' ),
				'valid_until'    => gmdate( 'c', time() + self::RUNTIME_TTL_SECONDS ),
				// Precise next-boundary computation isn't implemented this pass;
				// the client's own "at least every five minutes" fallback poll
				// (spec) is what valid_until already encodes, so this is always
				// a safe, honest null rather than a guessed timestamp.
				'next_change_at' => null,
				'context'        => $context_token,
			)
		);
	}

	public function handle_event( WP_REST_Request $request ) {
		if ( strlen( (string) $request->get_body() ) > self::MAX_EVENT_BODY_BYTES ) {
			return $this->error( 400, 'payload_too_large', __( 'The request is too large.', 'contactlift-pro' ) );
		}

		$settings = $this->pro_settings->get_settings();
		$gpc      = '1' === (string) $request->get_header( 'sec-gpc' );
		$is_admin = current_user_can( 'manage_options' );

		if ( ! AnalyticsGate::is_recording_allowed( (string) ( $settings['analytics_mode'] ?? 'off' ), $gpc, $is_admin ) ) {
			// Never an error — a disabled/declined/administrator event is a
			// normal, expected no-op, not a client mistake.
			return $this->no_store( array( 'recorded' => false ) );
		}

		$context = $this->signer->verify( (string) $request->get_param( 'context' ), Clock::system() );
		if ( null === $context ) {
			return $this->error( 400, 'invalid_context', __( 'This session has expired.', 'contactlift-pro' ) );
		}

		$bar_id    = (string) ( $context['context']['bar_id'] ?? '' );
		$action_id = sanitize_text_field( (string) $request->get_param( 'action_id' ) );
		$device    = 'mobile' === $request->get_param( 'device' ) ? 'mobile' : 'desktop';

		$action = $this->find_action( $bar_id, $action_id );
		if ( null === $action ) {
			return $this->error( 400, 'invalid_action', __( 'This action is no longer available.', 'contactlift-pro' ) );
		}

		if ( ! $this->rate_limiter->attempt( $this->event_bucket(), 60, 30, Clock::system() ) ) {
			return $this->error( 429, 'rate_limited', __( 'Too many events.', 'contactlift-pro' ) );
		}

		$this->stats->increment(
			current_time( 'Y-m-d' ),
			$bar_id,
			$action_id,
			(string) $action['type'],
			(string) ( $context['context']['source_type'] ?? '' ),
			$context['context']['source_id'] ?? null,
			$device
		);

		return $this->no_store( array( 'recorded' => true ) );
	}

	/**
	 * @param array<string,mixed> $bar
	 * @return array<string,mixed>
	 */
	private function public_bar_fields( array $bar ): array {
		$links = ( new ActionLinkBuilder() )->build( $bar, apply_filters( 'clift_token_values', array() ) );

		return array(
			'id'         => $bar['id'],
			'device'     => $bar['device'] ?? 'all',
			'appearance' => $bar['appearance'] ?? array(),
			'actions'    => $links,
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function find_action( string $bar_id, string $action_id ): ?array {
		foreach ( \ContactLift\Plugin::instance()->settings()->get_bars() as $bar ) {
			if ( ( $bar['id'] ?? '' ) !== $bar_id ) {
				continue;
			}

			foreach ( $bar['actions'] ?? array() as $action ) {
				if ( ( $action['id'] ?? '' ) === $action_id ) {
					return $action;
				}
			}
		}

		return null;
	}

	private function event_bucket(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- read-only server var, sanitized immediately; not user-submitted form data.

		return $this->rate_limiter->bucket_key_for_ip( $ip, 'events:' . gmdate( 'Y-m-d-H-i' ), wp_salt( 'auth' ) );
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function no_store( array $body ): WP_REST_Response {
		$response = new WP_REST_Response( $body, 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	private function error( int $status, string $code, string $message ): WP_REST_Response {
		$response = new WP_REST_Response(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
