<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Tests\Unit\Domain\Channels;

use ContactLift\Domain\Channels\PhoneNumber;
use PHPUnit\Framework\TestCase;

final class PhoneNumberTest extends TestCase {

	public function test_strips_presentation_separators(): void {
		self::assertSame( '+15551234567', PhoneNumber::normalize( '+1 (555) 123-4567' ) );
	}

	public function test_rejects_letters(): void {
		self::assertNull( PhoneNumber::normalize( '+1-555-CALL-NOW' ) );
	}

	public function test_digits_only_strips_leading_plus(): void {
		self::assertSame( '15551234567', PhoneNumber::digits_only( '+15551234567' ) );
	}
}
