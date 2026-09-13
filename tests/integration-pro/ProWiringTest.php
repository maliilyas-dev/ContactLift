<?php
/**
 * Proves ContactLift Pro actually wires into the free plugin's real
 * clift_resolve_bar filter chain — not just that each plugin's own domain
 * classes work in isolation (already covered by the unit suites). This is
 * the automated form of the manual browser verification run when Milestone
 * 3 shipped: content targeting, priority, and the schedule/closed-action
 * engine all observed live, through real WP hooks and query context.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Integration;

use ContactLift\Domain\Validation\EditionLimits;
use ContactLift\Frontend\BarResolver;
use ContactLift\Frontend\Renderer;
use ContactLift\Storage\SettingsRepository;
use WP_UnitTestCase;

final class ProWiringTest extends WP_UnitTestCase {

	private SettingsRepository $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = new SettingsRepository();
		$this->settings->install();
		$this->settings->save_settings(
			array(
				'business_name' => 'Acme Clinic',
				'enabled'       => true,
			)
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $extra_bars
	 */
	private function save_bars_with_default( array $extra_bars ): void {
		$default = array(
			'id'      => SettingsRepository::DEFAULT_BAR_ID,
			'name'    => 'Main contact bar',
			'enabled' => true,
			'device'  => 'all',
			'actions' => array(
				array(
					'type'        => 'call',
					'label'       => 'Call us',
					'enabled'     => true,
					'destination' => '+10000000000',
				),
			),
		);

		$this->settings->save_bars(
			array_merge( array( $default ), $extra_bars ),
			EditionLimits::pro(),
			SettingsRepository::DEFAULT_BAR_ID
		);
	}

	public function test_at05_a_targeted_bar_wins_over_the_default_on_the_page_it_includes(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->save_bars_with_default(
			array(
				array(
					'id'       => 'bar_targeted',
					'name'     => 'Targeted',
					'enabled'  => true,
					'device'   => 'all',
					'priority' => 5,
					'include'  => array( 'content_ids' => array( $post_id ) ),
					'actions'  => array(
						array(
							'type'        => 'call',
							'label'       => 'Call us',
							'enabled'     => true,
							'destination' => '+19995551234',
						),
					),
				),
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		$renderer = new Renderer( new BarResolver( $this->settings ) );

		ob_start();
		$renderer->render_footer();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'tel:+19995551234', $html );
		$this->assertStringNotContainsString( 'tel:+10000000000', $html );
	}

	public function test_a_non_matching_page_falls_back_to_the_default_bar(): void {
		$targeted_page = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$other_page    = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->save_bars_with_default(
			array(
				array(
					'id'      => 'bar_targeted',
					'name'    => 'Targeted',
					'enabled' => true,
					'device'  => 'all',
					'include' => array( 'content_ids' => array( $targeted_page ) ),
					'actions' => array(
						array(
							'type'        => 'call',
							'label'       => 'Call us',
							'enabled'     => true,
							'destination' => '+19995551234',
						),
					),
				),
			)
		);

		$this->go_to( get_permalink( $other_page ) );
		$renderer = new Renderer( new BarResolver( $this->settings ) );

		ob_start();
		$renderer->render_footer();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'tel:+10000000000', $html );
		$this->assertStringNotContainsString( 'tel:+19995551234', $html );
	}

	public function test_higher_priority_targeted_bar_wins_a_tie_on_the_same_page(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->save_bars_with_default(
			array(
				array(
					'id'       => 'bar_low',
					'name'     => 'Low priority',
					'enabled'  => true,
					'device'   => 'all',
					'priority' => 1,
					'include'  => array( 'content_ids' => array( $post_id ) ),
					'actions'  => array(
						array(
							'type'        => 'call',
							'label'       => 'Call us',
							'enabled'     => true,
							'destination' => '+11111111111',
						),
					),
				),
				array(
					'id'       => 'bar_high',
					'name'     => 'High priority',
					'enabled'  => true,
					'device'   => 'all',
					'priority' => 9,
					'include'  => array( 'content_ids' => array( $post_id ) ),
					'actions'  => array(
						array(
							'type'        => 'call',
							'label'       => 'Call us',
							'enabled'     => true,
							'destination' => '+19999999999',
						),
					),
				),
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		$renderer = new Renderer( new BarResolver( $this->settings ) );

		ob_start();
		$renderer->render_footer();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'tel:+19999999999', $html );
		$this->assertStringNotContainsString( 'tel:+11111111111', $html );
	}

	public function test_at07_an_always_closed_schedule_with_hide_behavior_suppresses_the_whole_bar(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->save_bars_with_default(
			array(
				array(
					'id'       => 'bar_targeted',
					'name'     => 'Targeted',
					'enabled'  => true,
					'device'   => 'all',
					'include'  => array( 'content_ids' => array( $post_id ) ),
					'actions'  => array(
						array(
							'type'            => 'call',
							'label'           => 'Call us',
							'enabled'         => true,
							'destination'     => '+19995551234',
							'closed_behavior' => 'hide',
						),
					),
					'schedule' => array( 'enabled' => true ), // no weekly intervals at all: always closed.
				),
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		$renderer = new Renderer( new BarResolver( $this->settings ) );

		ob_start();
		$renderer->render_footer();
		$html = ob_get_clean();

		$this->assertSame( '', $html, 'the targeted bar has no renderable action once its only one is hidden while closed' );
	}

	public function test_an_open_schedule_keeps_the_action_visible(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$weekday = (int) gmdate( 'N' );

		$this->save_bars_with_default(
			array(
				array(
					'id'       => 'bar_targeted',
					'name'     => 'Targeted',
					'enabled'  => true,
					'device'   => 'all',
					'include'  => array( 'content_ids' => array( $post_id ) ),
					'actions'  => array(
						array(
							'type'            => 'call',
							'label'           => 'Call us',
							'enabled'         => true,
							'destination'     => '+19995551234',
							'closed_behavior' => 'hide',
						),
					),
					'schedule' => array(
						'enabled' => true,
						'weekly'  => array(
							$weekday => array(
								array(
									'all_day' => true,
									'start'   => '00:00',
									'end'     => '24:00',
								),
							),
						),
					),
				),
			)
		);

		$this->go_to( get_permalink( $post_id ) );
		$renderer = new Renderer( new BarResolver( $this->settings ) );

		ob_start();
		$renderer->render_footer();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'tel:+19995551234', $html );
	}

	/**
	 * Regression test for a real bug: every ChannelRegistry (inside
	 * BarValidator, ActionLinkBuilder, BarEditorPage) used to query the
	 * clift_register_channels filter in its own constructor. Those objects
	 * are built synchronously inside \ContactLift\Plugin::boot(), which
	 * completes — including constructing the long-lived SettingsRepository
	 * this test deliberately reuses via the real singleton below, not a
	 * fresh one — before boot() fires clift_loaded at its very end, which is
	 * only when Pro's register_channels listener actually attaches. So the
	 * 'callback' channel was silently missing from every one of those
	 * instances for the rest of the request, including real saves through
	 * the admin bar editor. Fixed by deferring ChannelRegistry's filter
	 * query to first use instead of construction time.
	 */
	public function test_a_callback_action_validates_through_the_real_long_lived_settings_singleton(): void {
		$document = \ContactLift\Plugin::instance()->settings()->save_bars(
			array(
				array(
					'id'      => SettingsRepository::DEFAULT_BAR_ID,
					'name'    => 'Main contact bar',
					'enabled' => true,
					'device'  => 'all',
					'actions' => array(
						array(
							'id'      => 'action_callback',
							'type'    => 'callback',
							'label'   => 'Request a callback',
							'enabled' => true,
						),
					),
				),
			),
			EditionLimits::pro(),
			SettingsRepository::DEFAULT_BAR_ID
		);

		self::assertSame( 'callback', $document['bars'][0]['actions'][0]['type'] );
	}

	public function test_deleting_a_targeted_bar_preserves_the_default_bar(): void {
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );

		$this->save_bars_with_default(
			array(
				array(
					'id'      => 'bar_targeted',
					'name'    => 'Targeted',
					'enabled' => true,
					'device'  => 'all',
					'include' => array( 'content_ids' => array( $post_id ) ),
					'actions' => array(
						array(
							'type'        => 'call',
							'label'       => 'Call us',
							'enabled'     => true,
							'destination' => '+19995551234',
						),
					),
				),
			)
		);

		$remaining = array_values(
			array_filter(
				$this->settings->get_bars(),
				static fn( array $bar ): bool => 'bar_targeted' !== ( $bar['id'] ?? '' )
			)
		);
		$this->settings->save_bars( $remaining, EditionLimits::pro(), SettingsRepository::DEFAULT_BAR_ID );

		$document = $this->settings->get_bars_document();

		$this->assertCount( 1, $document['bars'] );
		$this->assertSame( SettingsRepository::DEFAULT_BAR_ID, $document['bars'][0]['id'] );
		$this->assertSame( SettingsRepository::DEFAULT_BAR_ID, $document['default_bar_id'] );
	}
}
