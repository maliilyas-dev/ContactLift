<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Tests\Unit\Support;

use ContactLift\Support\Uuid;
use PHPUnit\Framework\TestCase;

final class UuidTest extends TestCase {

	public function test_generates_a_v4_uuid(): void {
		$id = Uuid::v4();

		self::assertMatchesRegularExpression(
			'/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
			$id
		);
	}

	public function test_generates_unique_values(): void {
		self::assertNotSame( Uuid::v4(), Uuid::v4() );
	}
}
