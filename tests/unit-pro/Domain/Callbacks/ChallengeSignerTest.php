<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Unit\Domain\Callbacks;

use ContactLiftPro\Domain\Callbacks\ChallengeSigner;
use ContactLiftPro\Domain\Schedule\Clock;
use PHPUnit\Framework\TestCase;

final class ChallengeSignerTest extends TestCase {

	private ChallengeSigner $signer;

	protected function setUp(): void {
		$this->signer = new ChallengeSigner( 'test-secret' );
	}

	private function clock_at( string $iso ): Clock {
		return Clock::at( new \DateTimeImmutable( $iso, new \DateTimeZone( 'UTC' ) ) );
	}

	public function test_a_freshly_issued_token_verifies_successfully(): void {
		$now    = $this->clock_at( '2026-01-01T00:00:00Z' );
		$issued = $this->signer->issue(
			array(
				'source_type' => 'page',
				'source_id'   => 5,
			),
			$now
		);

		$verified = $this->signer->verify( $issued['token'], $now );

		self::assertNotNull( $verified );
		self::assertSame( $issued['uuid'], $verified['uuid'] );
		self::assertSame(
			array(
				'source_type' => 'page',
				'source_id'   => 5,
			),
			$verified['context']
		);
	}

	public function test_each_issued_token_gets_a_distinct_uuid(): void {
		$now = $this->clock_at( '2026-01-01T00:00:00Z' );

		$first  = $this->signer->issue( array(), $now );
		$second = $this->signer->issue( array(), $now );

		self::assertNotSame( $first['uuid'], $second['uuid'] );
	}

	public function test_an_expired_token_fails_verification(): void {
		$issued_at = $this->clock_at( '2026-01-01T00:00:00Z' );
		$issued    = $this->signer->issue( array(), $issued_at, 60 );

		$later = $this->clock_at( '2026-01-01T00:01:01Z' ); // 61 seconds later, past the 60s TTL.

		self::assertNull( $this->signer->verify( $issued['token'], $later ) );
	}

	public function test_a_token_still_valid_at_the_exact_expiry_instant_verifies(): void {
		$issued_at = $this->clock_at( '2026-01-01T00:00:00Z' );
		$issued    = $this->signer->issue( array(), $issued_at, 60 );

		$at_expiry = $this->clock_at( '2026-01-01T00:01:00Z' );

		self::assertNotNull( $this->signer->verify( $issued['token'], $at_expiry ) );
	}

	public function test_a_tampered_payload_fails_verification(): void {
		$now    = $this->clock_at( '2026-01-01T00:00:00Z' );
		$issued = $this->signer->issue( array( 'source_id' => 5 ), $now );

		[ $payload_b64, $signature ] = explode( '.', $issued['token'], 2 );
		$tampered_payload            = strtr( rtrim( base64_encode( '{"uuid":"x","context":{"source_id":999},"expires_at":9999999999}' ), '=' ), '+/', '-_' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- building a fake token payload to verify tampered tokens are rejected; not obfuscating anything.
		$tampered_token              = $tampered_payload . '.' . $signature;

		self::assertNull( $this->signer->verify( $tampered_token, $now ) );
	}

	public function test_a_token_signed_with_a_different_secret_fails_verification(): void {
		$now       = $this->clock_at( '2026-01-01T00:00:00Z' );
		$issued    = $this->signer->issue( array(), $now );
		$other_key = new ChallengeSigner( 'a-different-secret' );

		self::assertNull( $other_key->verify( $issued['token'], $now ) );
	}

	public function test_a_malformed_token_fails_verification_without_error(): void {
		$now = $this->clock_at( '2026-01-01T00:00:00Z' );

		self::assertNull( $this->signer->verify( 'not-a-real-token', $now ) );
		self::assertNull( $this->signer->verify( '', $now ) );
	}
}
