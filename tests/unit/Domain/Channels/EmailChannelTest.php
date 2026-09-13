<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Tests\Unit\Domain\Channels;

use ContactLift\Domain\Channels\EmailChannel;
use ContactLift\Domain\ValidationException;
use PHPUnit\Framework\TestCase;

final class EmailChannelTest extends TestCase {

	private EmailChannel $channel;

	protected function setUp(): void {
		$this->channel = new EmailChannel();
	}

	public function test_rejects_invalid_address(): void {
		$this->expectException( ValidationException::class );

		$this->channel->validate( array( 'destination' => 'not-an-email' ) );
	}

	public function test_builds_mailto_with_each_value_encoded(): void {
		$validated = $this->channel->validate(
			array(
				'destination'      => 'hi@example.com',
				'subject_template' => 'Question & Answer',
				'body_template'    => 'Hi there',
			)
		);

		$url = $this->channel->build_url( $validated );

		self::assertSame(
			'mailto:hi@example.com?subject=' . rawurlencode( 'Question & Answer' ) . '&body=' . rawurlencode( 'Hi there' ),
			$url
		);
	}

	public function test_rejects_header_injection_via_control_characters(): void {
		$this->expectException( ValidationException::class );

		$this->channel->validate(
			array(
				'destination'      => 'hi@example.com',
				'subject_template' => "Hi\r\nBcc: attacker@example.com",
			)
		);
	}
}
