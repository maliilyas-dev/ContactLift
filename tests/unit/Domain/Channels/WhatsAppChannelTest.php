<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Tests\Unit\Domain\Channels;

use ContactLift\Domain\Channels\WhatsAppChannel;
use ContactLift\Domain\ValidationException;
use PHPUnit\Framework\TestCase;

/**
 * Covers AT01 (normalised international number + Unicode message, single
 * encoding pass) and part of AT02 (control characters rejected).
 */
final class WhatsAppChannelTest extends TestCase {

	private WhatsAppChannel $channel;

	protected function setUp(): void {
		$this->channel = new WhatsAppChannel();
	}

	public function test_at01_digits_only_path_and_single_encoding_pass(): void {
		$validated = $this->channel->validate(
			array(
				'destination'      => '+92 300 123 4567',
				'message_template' => 'Hello',
			)
		);

		self::assertSame( '923001234567', $validated['destination'], 'digits only, no leading +' );

		$url = $this->channel->build_url( $validated, 'Hello, مرحبا 👋' );

		self::assertSame(
			'https://wa.me/923001234567?text=' . rawurlencode( 'Hello, مرحبا 👋' ),
			$url,
			'encoded exactly once'
		);
	}

	public function test_at02_rejects_control_characters_in_template(): void {
		$this->expectException( ValidationException::class );

		$this->channel->validate(
			array(
				'destination'      => '923001234567',
				'message_template' => "line one\r\nline two",
			)
		);
	}

	public function test_caps_final_message_at_1500_characters_without_splitting_unicode(): void {
		$validated = $this->channel->validate( array( 'destination' => '923001234567' ) );

		$long = str_repeat( '😀', 2000 ); // multi-byte, deliberately over the cap.
		$url  = $this->channel->build_url( $validated, $long );

		$decoded = rawurldecode( substr( $url, strpos( $url, 'text=' ) + 5 ) );

		self::assertSame( 1500, mb_strlen( $decoded ) );
	}

	public function test_rejects_overlong_template(): void {
		$this->expectException( ValidationException::class );

		$this->channel->validate(
			array(
				'destination'      => '923001234567',
				'message_template' => str_repeat( 'a', 1001 ),
			)
		);
	}
}
