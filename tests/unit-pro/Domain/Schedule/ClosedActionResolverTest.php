<?php
/**
 * Spec's "Closed actions" section.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Unit\Domain\Schedule;

use ContactLiftPro\Domain\Schedule\ClosedActionResolver;
use PHPUnit\Framework\TestCase;

final class ClosedActionResolverTest extends TestCase {

	private ClosedActionResolver $resolver;

	protected function setUp(): void {
		$this->resolver = new ClosedActionResolver();
	}

	public function test_actions_are_unchanged_while_open(): void {
		$actions = array(
			array(
				'id'              => 'a1',
				'closed_behavior' => 'hide',
			),
		);

		self::assertSame( $actions, $this->resolver->apply( $actions, true ) );
	}

	public function test_keep_behavior_is_unchanged_while_closed(): void {
		$actions = array(
			array(
				'id'              => 'a1',
				'closed_behavior' => 'keep',
				'destination'     => '+15551234567',
			),
		);

		self::assertSame( $actions, $this->resolver->apply( $actions, false ) );
	}

	public function test_hide_behavior_removes_the_action_while_closed(): void {
		$actions = array(
			array(
				'id'              => 'a1',
				'closed_behavior' => 'hide',
			),
		);

		self::assertSame( array(), $this->resolver->apply( $actions, false ) );
	}

	public function test_replace_behavior_substitutes_the_replacement_keeping_the_original_id(): void {
		$actions = array(
			array(
				'id'              => 'a1',
				'type'            => 'call',
				'closed_behavior' => 'replace',
				'destination'     => '+15551234567',
				'replacement'     => array(
					'id'          => 'replacement',
					'type'        => 'whatsapp',
					'enabled'     => true,
					'destination' => '+15559876543',
				),
			),
		);

		$result = $this->resolver->apply( $actions, false );

		self::assertCount( 1, $result );
		self::assertSame( 'a1', $result[0]['id'] );
		self::assertSame( 'whatsapp', $result[0]['type'] );
		self::assertSame( '+15559876543', $result[0]['destination'] );
	}

	public function test_replace_behavior_with_no_configured_replacement_drops_the_action(): void {
		$actions = array(
			array(
				'id'              => 'a1',
				'closed_behavior' => 'replace',
				'replacement'     => null,
			),
		);

		self::assertSame( array(), $this->resolver->apply( $actions, false ) );
	}

	public function test_mixed_behaviors_in_one_bar_while_closed(): void {
		$actions = array(
			array(
				'id'              => 'keep-me',
				'closed_behavior' => 'keep',
			),
			array(
				'id'              => 'hide-me',
				'closed_behavior' => 'hide',
			),
			array(
				'id'              => 'replace-me',
				'closed_behavior' => 'replace',
				'replacement'     => array(
					'id'      => 'replacement',
					'type'    => 'email',
					'enabled' => true,
				),
			),
		);

		$result = $this->resolver->apply( $actions, false );
		$ids    = array_column( $result, 'id' );

		self::assertSame( array( 'keep-me', 'replace-me' ), $ids );
	}
}
