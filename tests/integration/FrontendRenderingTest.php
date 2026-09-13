<?php
/**
 * The renderer end-to-end, through real WP hooks and query context — not
 * just the pure-PHP pieces the unit suite already covers.
 *
 * @package ContactLift
 */

namespace ContactLift\Tests\Integration;

use ContactLift\Frontend\BarResolver;
use ContactLift\Frontend\Renderer;
use ContactLift\Storage\SettingsRepository;
use WP_UnitTestCase;

final class FrontendRenderingTest extends WP_UnitTestCase {

	private SettingsRepository $settings;

	public function set_up(): void {
		parent::set_up();
		$this->settings = new SettingsRepository();
		$this->settings->install();
	}

	private function enable_bar_with_call_action( string $destination = '+15551234567' ): void {
		$this->settings->save_settings(
			array(
				'business_name' => 'Acme Clinic',
				'enabled'       => true,
			)
		);

		$this->settings->save_bars(
			array(
				array(
					'id'      => SettingsRepository::DEFAULT_BAR_ID,
					'name'    => 'Main contact bar',
					'enabled' => true,
					'device'  => 'all',
					'actions' => array(
						array(
							'type'        => 'call',
							'label'       => 'Call us',
							'enabled'     => true,
							'destination' => $destination,
						),
					),
				),
			),
			\ContactLift\Domain\Validation\EditionLimits::free(),
			SettingsRepository::DEFAULT_BAR_ID
		);
	}

	public function test_renders_the_correct_tel_link_on_a_real_page(): void {
		$this->enable_bar_with_call_action();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );

		$renderer = new Renderer( new BarResolver( $this->settings ) );

		ob_start();
		$renderer->render_footer();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'href="tel:+15551234567"', $html );
		$this->assertStringContainsString( 'clift-mobile', $html );
		$this->assertStringContainsString( 'clift-desktop', $html );
	}

	public function test_renders_nothing_when_the_bar_is_disabled(): void {
		$this->enable_bar_with_call_action();
		$this->settings->save_settings(
			array(
				'business_name' => 'Acme Clinic',
				'enabled'       => false,
			)
		);

		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );

		$renderer = new Renderer( new BarResolver( $this->settings ) );

		ob_start();
		$renderer->render_footer();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	public function test_shortcode_and_footer_dedupe_only_one_instance_renders(): void {
		$this->enable_bar_with_call_action();
		$post_id = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		$this->go_to( get_permalink( $post_id ) );

		$renderer = new Renderer( new BarResolver( $this->settings ) );

		$inline_first  = $renderer->render_inline();
		$inline_second = $renderer->render_inline(); // A second [contactlift] on the same page.

		ob_start();
		$renderer->render_footer(); // wp_footer still fires after the shortcode ran.
		$footer_output = ob_get_clean();

		$this->assertStringContainsString( 'clift-inline', $inline_first );
		$this->assertSame( '', $inline_second, 'a repeated shortcode must not render twice' );
		$this->assertSame( '', $footer_output, 'the floating instance must be suppressed once an inline placement has rendered' );
	}

	public function test_page_title_and_url_tokens_resolve_to_the_real_page(): void {
		$this->settings->save_settings(
			array(
				'business_name' => 'Acme Clinic',
				'enabled'       => true,
			)
		);
		$this->settings->save_bars(
			array(
				array(
					'id'      => SettingsRepository::DEFAULT_BAR_ID,
					'name'    => 'Main contact bar',
					'enabled' => true,
					'device'  => 'all',
					'actions' => array(
						array(
							'type'             => 'whatsapp',
							'label'            => 'WhatsApp',
							'enabled'          => true,
							'destination'      => '+15551234567',
							'message_template' => 'Hi, I have a question about {page_title}.',
						),
					),
				),
			),
			\ContactLift\Domain\Validation\EditionLimits::free(),
			SettingsRepository::DEFAULT_BAR_ID
		);

		$post_id = self::factory()->post->create(
			array(
				'post_status' => 'publish',
				'post_title'  => 'Our Pricing',
			)
		);
		$this->go_to( get_permalink( $post_id ) );

		$renderer = new Renderer( new BarResolver( $this->settings ) );

		ob_start();
		$renderer->render_footer();
		$html = ob_get_clean();

		$this->assertStringContainsString( rawurlencode( 'Our Pricing' ), $html );
		$this->assertStringNotContainsString( '{page_title}', $html );
	}
}
