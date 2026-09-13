<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\Callbacks;

use ContactLiftPro\Domain\Schedule\Clock;

defined( 'ABSPATH' ) || exit;

/**
 * "Request a fresh short-lived signed challenge from POST
 * /callback-challenge... It contains validated public context, an expiry
 * and a random request UUID" (spec, Submission sequence step 1). The token
 * is self-contained and stateless — verify() needs only the secret and the
 * current time, no database round trip — so issuing one is cheap enough to
 * rate-limit on its own (spec step 3) without a separate storage cost.
 *
 * The secret is injected rather than read from wp_salt() here, keeping this
 * class unit-testable without WordPress; the REST layer supplies the real
 * site secret.
 */
final class ChallengeSigner {

	private const DEFAULT_TTL_SECONDS = 300;

	public function __construct( private readonly string $secret ) {}

	/**
	 * @param array<string,mixed> $context Validated public context to bind the challenge to.
	 * @return array{token:string,uuid:string,expires_at:int}
	 */
	public function issue( array $context, Clock $clock, int $ttl_seconds = self::DEFAULT_TTL_SECONDS ): array {
		$uuid       = $this->generate_uuid();
		$expires_at = $clock->now()->getTimestamp() + max( 1, $ttl_seconds );

		$payload = array(
			'uuid'       => $uuid,
			'context'    => $context,
			'expires_at' => $expires_at,
		);

		return array(
			'token'      => $this->encode( $payload ),
			'uuid'       => $uuid,
			'expires_at' => $expires_at,
		);
	}

	/**
	 * Verifies the signature and expiry, and returns the original payload
	 * (including the context it was bound to) — or null if the token is
	 * malformed, tampered with, or expired.
	 *
	 * @return array{uuid:string,context:array<string,mixed>,expires_at:int}|null
	 */
	public function verify( string $token, Clock $clock ): ?array {
		$parts = explode( '.', $token, 2 );

		if ( 2 !== count( $parts ) ) {
			return null;
		}

		[ $payload_b64, $signature ] = $parts;

		if ( ! hash_equals( $this->sign( $payload_b64 ), $signature ) ) {
			return null;
		}

		$json = base64_decode( strtr( $payload_b64, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- decoding this class's own signed token, not user-supplied opaque data; the signature above is what's actually trusted.

		if ( ! is_string( $json ) ) {
			return null;
		}

		$payload = json_decode( $json, true );

		if ( ! is_array( $payload ) || ! isset( $payload['uuid'], $payload['context'], $payload['expires_at'] ) ) {
			return null;
		}

		if ( ! is_int( $payload['expires_at'] ) || $payload['expires_at'] < $clock->now()->getTimestamp() ) {
			return null;
		}

		return array(
			'uuid'       => (string) $payload['uuid'],
			'context'    => (array) $payload['context'],
			'expires_at' => (int) $payload['expires_at'],
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function encode( array $payload ): string {
		// Plain json_encode(), not wp_json_encode(): this serializes the
		// class's own internal token payload, never a value returned
		// straight to a REST response body, so none of wp_json_encode()'s
		// extra behaviour (its own unicode/slash escaping, apply_filters)
		// applies — and using it would pull a WP function into an otherwise
		// WP-independent, plain-unit-testable class.
		$payload_b64 = rtrim( strtr( base64_encode( (string) json_encode( $payload ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- see comment above for json_encode(); base64 here is URL-safe token encoding, not obfuscation — the token's integrity comes from the HMAC signature below, not from hiding its (non-secret) structure.

		return $payload_b64 . '.' . $this->sign( $payload_b64 );
	}

	private function sign( string $payload_b64 ): string {
		return hash_hmac( 'sha256', $payload_b64, $this->secret );
	}

	private function generate_uuid(): string {
		$data    = random_bytes( 16 );
		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
