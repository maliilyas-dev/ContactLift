<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Rest;

use ContactLift\Domain\ValidationException;
use ContactLiftPro\Domain\Callbacks\CallbackReadiness;
use ContactLiftPro\Domain\Callbacks\CallbackValidator;
use ContactLiftPro\Domain\Callbacks\ChallengeSigner;
use ContactLiftPro\Domain\Schedule\Clock;
use ContactLiftPro\Frontend\ContextValidator;
use ContactLiftPro\Storage\CallbackRepository;
use ContactLiftPro\Storage\Mailer;
use ContactLiftPro\Storage\ProSettingsRepository;
use ContactLiftPro\Storage\RateLimiter;
use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * "Register routes under contactlift/v1 on rest_api_init. Every route has a
 * permission_callback plus validation and sanitisation for each argument.
 * Public submission endpoints remain public by design; they must not
 * expose private records." (spec, Public API and authorisation) — this
 * implements the callback-challenge and callbacks routes and the exact
 * "Submission sequence" the spec lists: challenge, validate, rate-limit,
 * persist-first with idempotent UUID handling, one notification attempt.
 */
final class CallbackController {

	private const MAX_BODY_BYTES = 8192;

	private ChallengeSigner $signer;
	private ContextValidator $context_validator;
	private CallbackValidator $validator;
	private CallbackRepository $repository;
	private RateLimiter $rate_limiter;
	private Mailer $mailer;
	private ProSettingsRepository $pro_settings;

	public function __construct() {
		$this->signer            = new ChallengeSigner( $this->site_secret() );
		$this->context_validator = new ContextValidator();
		$this->validator         = new CallbackValidator();
		$this->repository        = new CallbackRepository();
		$this->rate_limiter      = new RateLimiter();
		$this->mailer            = new Mailer();
		$this->pro_settings      = new ProSettingsRepository();
	}

	public function register_routes(): void {
		register_rest_route(
			'contactlift/v1',
			'/callback-challenge',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_challenge' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'source_type'  => array(
						'type'     => 'string',
						'required' => true,
					),
					'source_id'    => array(
						'type'     => 'integer',
						'required' => false,
					),
					'variation_id' => array(
						'type'     => 'integer',
						'required' => false,
					),
					'bar_id'       => array(
						'type'     => 'string',
						'required' => true,
					),
					'action_id'    => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			'contactlift/v1',
			'/callbacks',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_submit' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'token'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'name'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'phone'   => array(
						'type'     => 'string',
						'required' => true,
					),
					'email'   => array(
						'type'     => 'string',
						'required' => false,
					),
					'message' => array(
						'type'     => 'string',
						'required' => false,
					),
					'consent' => array(
						'type'     => 'boolean',
						'required' => true,
					),
					'website' => array( // honeypot — never rendered visibly to a real visitor.
						'type'     => 'string',
						'required' => false,
					),
				),
			)
		);
	}

	public function handle_challenge( WP_REST_Request $request ) {
		if ( ! $this->rate_limiter->attempt( $this->ip_bucket( 'challenge' ), 600, (int) $this->pro_settings->get_settings()['rate_limit_per_10min'], Clock::system() ) ) {
			return $this->too_many_requests( 600 );
		}

		$source = $this->context_validator->validate_source( $request->get_param( 'source_type' ), $request->get_param( 'source_id' ) );

		if ( null === $source ) {
			return $this->error( 400, 'invalid_context', __( 'This page can\'t be resolved.', 'contactlift-pro' ) );
		}

		$variation_id = (int) $request->get_param( 'variation_id' );
		if ( $variation_id > 0 ) {
			if ( 'product' !== $source['source_type'] || null === $this->context_validator->validate_variation( $variation_id, (int) $source['source_id'] ) ) {
				return $this->error( 400, 'invalid_context', __( 'This product variation can\'t be resolved.', 'contactlift-pro' ) );
			}
		}

		$bar_id    = sanitize_text_field( (string) $request->get_param( 'bar_id' ) );
		$action_id = sanitize_text_field( (string) $request->get_param( 'action_id' ) );

		if ( ! $this->is_enabled_callback_action( $bar_id, $action_id ) ) {
			return $this->error( 400, 'invalid_action', __( 'This request option is no longer available.', 'contactlift-pro' ) );
		}

		if ( ! CallbackReadiness::is_configured( $this->pro_settings->get_settings() ) ) {
			return $this->error( 400, 'not_configured', __( 'Callback requests aren\'t available right now.', 'contactlift-pro' ) );
		}

		$issued = $this->signer->issue(
			array(
				'source_type'  => $source['source_type'],
				'source_id'    => $source['source_id'],
				'variation_id' => $variation_id > 0 ? $variation_id : null,
				'bar_id'       => $bar_id,
				'action_id'    => $action_id,
			),
			Clock::system()
		);

		$response = new WP_REST_Response(
			array(
				'token'      => $issued['token'],
				'expires_at' => $issued['expires_at'],
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	public function handle_submit( WP_REST_Request $request ) {
		$content_type = (string) $request->get_header( 'content-type' );
		if ( '' !== $content_type && false === stripos( $content_type, 'application/json' ) ) {
			return $this->error( 400, 'invalid_content_type', __( 'Unsupported request format.', 'contactlift-pro' ) );
		}

		if ( strlen( (string) $request->get_body() ) > self::MAX_BODY_BYTES ) {
			return $this->error( 400, 'payload_too_large', __( 'The request is too large.', 'contactlift-pro' ) );
		}

		if ( ! $this->same_origin_if_declared( $request ) ) {
			return $this->error( 400, 'invalid_origin', __( 'This request could not be verified.', 'contactlift-pro' ) );
		}

		$challenge = $this->signer->verify( (string) $request->get_param( 'token' ), Clock::system() );
		if ( null === $challenge ) {
			return $this->error( 400, 'invalid_challenge', __( 'This form has expired. Please try again.', 'contactlift-pro' ) );
		}

		// Honeypot: a real visitor never sees or fills this field. A filled
		// value gets the same generic response as any other invalid
		// submission — no distinct signal for an automated caller to learn from.
		if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
			return $this->error( 400, 'invalid_submission', __( 'This request couldn\'t be submitted.', 'contactlift-pro' ) );
		}

		$settings = $this->pro_settings->get_settings();

		if ( ! $this->rate_limiter->attempt( $this->ip_bucket( 'submit' ), 600, (int) $settings['rate_limit_per_10min'], Clock::system() ) ) {
			return $this->too_many_requests( 600 );
		}

		if ( ! $this->rate_limiter->attempt( 'clift_sitewide', 3600, (int) $settings['rate_limit_per_hour'], Clock::system() ) ) {
			return $this->too_many_requests( 3600 );
		}

		if ( ! CallbackReadiness::is_configured( $settings ) ) {
			return $this->error( 400, 'not_configured', __( 'Callback requests aren\'t available right now.', 'contactlift-pro' ) );
		}

		try {
			$fields = $this->validator->validate(
				array(
					'name'    => $request->get_param( 'name' ),
					'phone'   => $request->get_param( 'phone' ),
					'email'   => $request->get_param( 'email' ),
					'message' => $request->get_param( 'message' ),
					'consent' => $request->get_param( 'consent' ),
				)
			);
		} catch ( ValidationException $e ) {
			return $this->error( 400, 'invalid_fields', __( 'Please check the form and try again.', 'contactlift-pro' ), $e->errors() );
		}

		$context = $challenge['context'];

		$record = array_merge(
			$fields,
			array(
				'request_uuid'    => $challenge['uuid'],
				'bar_id'          => (string) ( $context['bar_id'] ?? '' ),
				'action_id'       => (string) ( $context['action_id'] ?? '' ),
				'source_type'     => (string) ( $context['source_type'] ?? '' ),
				'source_id'       => $context['source_id'] ?? null,
				'consent_version' => (string) $settings['consent_version'],
				'consent_text'    => (string) $settings['consent_copy'],
			)
		);

		$payload_hash = $this->payload_hash( $record );
		$result       = $this->repository->insert_if_new( $record, $payload_hash );

		$response = $this->response_for_insert_result( $result, $challenge['uuid'], $settings );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * @param array{result:string,record:array<string,mixed>|null} $result
	 * @param array<string,mixed>                                   $settings
	 */
	private function response_for_insert_result( array $result, string $uuid, array $settings ): WP_REST_Response {
		switch ( $result['result'] ) {
			case 'created':
				$this->attempt_notification( (int) $result['record']['id'], $result['record'], $settings );

				return new WP_REST_Response(
					array(
						'status'       => 'received',
						'request_uuid' => $uuid,
					),
					201
				);

			case 'duplicate':
				// "Do not resend notifications on a duplicate successful request."
				return new WP_REST_Response(
					array(
						'status'       => 'received',
						'request_uuid' => $uuid,
					),
					200
				);

			case 'conflict':
				return new WP_REST_Response(
					array(
						'code'    => 'conflict',
						'message' => __( 'This request has already been submitted.', 'contactlift-pro' ),
					),
					409
				);

			default:
				// AT15: a database failure must never be reported as success.
				return new WP_REST_Response(
					array(
						'code'    => 'server_error',
						'message' => __( 'Something went wrong. Please try again.', 'contactlift-pro' ),
					),
					500
				);
		}
	}

	/**
	 * @param array<string,mixed> $record
	 * @param array<string,mixed> $settings
	 */
	private function attempt_notification( int $id, array $record, array $settings ): void {
		if ( ! $this->repository->claim_for_notification( $id ) ) {
			return; // already claimed/sent, or attempts exhausted.
		}

		$sent = $this->mailer->send_callback_notification( $settings['callback_recipients'] ?? array(), $record );
		$this->repository->mark_notification_result( $id, $sent );
	}

	/**
	 * @param array<string,mixed> $record
	 */
	private function payload_hash( array $record ): string {
		$meaningful = array(
			'name'        => $record['name'],
			'phone'       => $record['phone'],
			'email'       => $record['email'],
			'message'     => $record['message'],
			'bar_id'      => $record['bar_id'],
			'action_id'   => $record['action_id'],
			'source_type' => $record['source_type'],
			'source_id'   => $record['source_id'],
		);

		return hash( 'sha256', (string) wp_json_encode( $meaningful ) );
	}

	private function is_enabled_callback_action( string $bar_id, string $action_id ): bool {
		if ( '' === $bar_id || '' === $action_id ) {
			return false;
		}

		foreach ( \ContactLift\Plugin::instance()->settings()->get_bars() as $bar ) {
			if ( ( $bar['id'] ?? '' ) !== $bar_id ) {
				continue;
			}

			foreach ( $bar['actions'] ?? array() as $action ) {
				if (
					( $action['id'] ?? '' ) === $action_id
					&& 'callback' === ( $action['type'] ?? '' )
					&& ! empty( $action['enabled'] )
				) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * "Validate request origin when available" — absence of both headers is
	 * not itself a rejection (not every client sends them), but a present,
	 * mismatched one is.
	 */
	private function same_origin_if_declared( WP_REST_Request $request ): bool {
		$site_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$candidate = $request->get_header( 'origin' );
		$candidate = '' !== (string) $candidate ? $candidate : $request->get_header( 'referer' );

		if ( '' === (string) $candidate ) {
			return true;
		}

		return wp_parse_url( (string) $candidate, PHP_URL_HOST ) === $site_host;
	}

	/**
	 * $scope is folded into the HMAC's rotation input, not prepended to its
	 * output — bucket_key_for_ip() already returns a fixed 64-character hex
	 * digest, exactly filling the clift_rate_limits.bucket_key VARCHAR(64)
	 * column; concatenating a scope prefix onto that output would silently
	 * overflow it (and did, until this was caught by a failing integration
	 * test — the too-long INSERT failed, and the rate limiter correctly
	 * failed closed, which looked like every request being rate-limited).
	 */
	private function ip_bucket( string $scope ): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.NonceVerification.Missing -- read-only server var, sanitized immediately; not user-submitted form data.

		return $this->rate_limiter->bucket_key_for_ip( $ip, $scope . ':' . gmdate( 'Y-m-d' ), $this->site_secret() );
	}

	private function site_secret(): string {
		return wp_salt( 'auth' );
	}

	private function error( int $status, string $code, string $message, array $errors = array() ) {
		$body = array(
			'code'    => $code,
			'message' => $message,
		);

		if ( $errors ) {
			$body['errors'] = $errors;
		}

		$response = new WP_REST_Response( $body, $status );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	private function too_many_requests( int $retry_after_seconds ) {
		$response = $this->error( 429, 'rate_limited', __( 'Too many attempts. Please try again shortly.', 'contactlift-pro' ) );
		$response->header( 'Retry-After', (string) $retry_after_seconds );

		return $response;
	}
}
