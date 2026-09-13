<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Tests\Unit\Domain\Channels;

use ContactLift\Domain\Channels\LinkChannel;
use ContactLift\Domain\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Covers part of AT02: script markup, CRLF and javascript: URLs must be
 * rejected outright rather than rendered.
 */
final class LinkChannelTest extends TestCase {

	private LinkChannel $channel;

	protected function setUp(): void {
		$this->channel = new LinkChannel();
	}

	public function test_accepts_https_link(): void {
		$result = $this->channel->validate( array( 'destination' => 'https://example.com/book' ) );

		self::assertSame( 'https://example.com/book', $this->channel->build_url( $result ) );
	}

	public function test_rejects_javascript_scheme(): void {
		$this->expectException( ValidationException::class );

		$this->channel->validate( array( 'destination' => 'javascript:alert(1)' ) );
	}

	public function test_rejects_plain_http(): void {
		$this->expectException( ValidationException::class );

		$this->channel->validate( array( 'destination' => 'http://example.com' ) );
	}

	public function test_rejects_embedded_crlf(): void {
		$this->expectException( ValidationException::class );

		$this->channel->validate( array( 'destination' => "https://example.com/\r\nSet-Cookie: x=1" ) );
	}
}
