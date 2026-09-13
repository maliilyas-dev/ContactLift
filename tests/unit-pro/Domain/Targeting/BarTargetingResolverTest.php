<?php
/**
 * AT03-AT05 from the spec's acceptance suite.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Unit\Domain\Targeting;

use ContactLiftPro\Domain\Targeting\BarTargetingResolver;
use ContactLiftPro\Domain\Targeting\MatchContext;
use PHPUnit\Framework\TestCase;

final class BarTargetingResolverTest extends TestCase {

	private BarTargetingResolver $resolver;

	protected function setUp(): void {
		$this->resolver = new BarTargetingResolver();
	}

	/**
	 * @param array<string,mixed> $overrides
	 * @return array<string,mixed>
	 */
	private function bar( string $id, array $overrides = array() ): array {
		return array_replace_recursive(
			array(
				'id'       => $id,
				'enabled'  => true,
				'device'   => 'all',
				'priority' => 0,
				'include'  => array(
					'content_ids'     => array(),
					'product_cat_ids' => array(),
				),
				'exclude'  => array(
					'content_ids'     => array(),
					'product_cat_ids' => array(),
				),
			),
			$overrides
		);
	}

	public function test_at03_exclusion_wins_even_when_included(): void {
		$bar = $this->bar(
			'bar_a',
			array(
				'include' => array( 'content_ids' => array( 5 ) ),
				'exclude' => array( 'content_ids' => array( 5 ) ),
			)
		);

		$result = $this->resolver->resolve( array( $bar ), 'bar_default', new MatchContext( 5 ), 'desktop' );

		self::assertNull( $result );
	}

	public function test_at04_equal_priorities_tie_break_on_lowest_id(): void {
		$bar_b = $this->bar( 'bar_b', array( 'priority' => 5 ) );
		$bar_a = $this->bar( 'bar_a', array( 'priority' => 5 ) );

		// Deliberately inserted out of ID order, to prove the tie-break
		// isn't accidentally just "first in the array".
		$result = $this->resolver->resolve( array( $bar_b, $bar_a ), 'bar_default', new MatchContext( 1 ), 'desktop' );

		self::assertSame( 'bar_a', $result['id'] );
	}

	public function test_higher_priority_wins_over_lower(): void {
		$low  = $this->bar( 'bar_low', array( 'priority' => 1 ) );
		$high = $this->bar( 'bar_high', array( 'priority' => 10 ) );

		$result = $this->resolver->resolve( array( $low, $high ), 'bar_default', new MatchContext( 1 ), 'desktop' );

		self::assertSame( 'bar_high', $result['id'] );
	}

	public function test_at05_targeted_bar_wins_over_default(): void {
		$default  = $this->bar( 'bar_default', array( 'priority' => 0 ) );
		$targeted = $this->bar( 'bar_targeted', array( 'priority' => 1 ) );

		$bars = array( $default, $targeted );

		$result = $this->resolver->resolve( $bars, 'bar_default', new MatchContext( 1 ), 'desktop' );

		self::assertSame( 'bar_targeted', $result['id'] );
	}

	public function test_default_bar_used_only_when_no_targeted_bar_matches(): void {
		$default  = $this->bar( 'bar_default' );
		$targeted = $this->bar( 'bar_targeted', array( 'include' => array( 'content_ids' => array( 99 ) ) ) );

		$result = $this->resolver->resolve( array( $default, $targeted ), 'bar_default', new MatchContext( 1 ), 'desktop' );

		self::assertSame( 'bar_default', $result['id'] );
	}

	public function test_default_bar_itself_can_be_excluded(): void {
		$default = $this->bar( 'bar_default', array( 'exclude' => array( 'content_ids' => array( 1 ) ) ) );

		$result = $this->resolver->resolve( array( $default ), 'bar_default', new MatchContext( 1 ), 'desktop' );

		self::assertNull( $result );
	}

	public function test_empty_include_list_means_sitewide(): void {
		$bar = $this->bar( 'bar_a' );

		$result = $this->resolver->resolve( array( $bar ), 'bar_default', new MatchContext( 12345 ), 'desktop' );

		self::assertSame( 'bar_a', $result['id'] );
	}

	public function test_product_category_include_matches_via_or(): void {
		$bar = $this->bar( 'bar_a', array( 'include' => array( 'product_cat_ids' => array( 7 ) ) ) );

		$result = $this->resolver->resolve( array( $bar ), 'bar_default', new MatchContext( 1, array( 7, 8 ) ), 'desktop' );

		self::assertSame( 'bar_a', $result['id'] );
	}

	public function test_device_filters_out_a_bar_targeting_only_the_other_device(): void {
		$mobile_only = $this->bar( 'bar_mobile', array( 'device' => 'mobile' ) );

		$result = $this->resolver->resolve( array( $mobile_only ), 'bar_default', new MatchContext( 1 ), 'desktop' );

		self::assertNull( $result );
	}

	public function test_disabled_bar_never_matches(): void {
		$bar = $this->bar( 'bar_a', array( 'enabled' => false ) );

		$result = $this->resolver->resolve( array( $bar ), 'bar_default', new MatchContext( 1 ), 'desktop' );

		self::assertNull( $result );
	}
}
