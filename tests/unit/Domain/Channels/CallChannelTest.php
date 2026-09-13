<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Tests\Unit\Domain\Channels;

use ContactLift\Domain\Channels\CallChannel;
use ContactLift\Domain\ValidationException;
use PHPUnit\Framework\TestCase;

final class CallChannelTest extends TestCase {

	private CallChannel $channel;

	protected function setUp(): void {
		$this->channel = new CallChannel();
	}

	public function test_normalizes_presentation_separators(): void {
		$result = $this->channel->validate( array( 'destination' => '+1 (555) 123-4567' ) );

		self::assertSame( '+15551234567', $result['destination'] );
	}

	public function test_builds_tel_link(): void {
		$result = $this->channel->validate( array( 'destination' => '15551234567' ) );

		self::assertSame( 'tel:15551234567', $this->channel->build_url( $result ) );
	}

	public function test_rejects_too_short_number(): void {
		$this->expectException( ValidationException::class );

		$this->channel->validate( array( 'destination' => '12345' ) );
	}

	public function test_rejects_too_long_number(): void {
		$this->expectException( ValidationException::class );

		$this->channel->validate( array( 'destination' => '1234567890123456' ) );
	}

	public function test_empty_destination_is_allowed_but_stays_empty(): void {
		$result = $this->channel->validate( array( 'destination' => '' ) );

		self::assertSame( '', $result['destination'] );
	}
}
