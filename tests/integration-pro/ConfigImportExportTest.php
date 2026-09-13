<?php
/**
 * AT20 from the spec's acceptance suite, plus the round trip the Tools
 * screen's export/import cards depend on.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Integration;

use ContactLift\Domain\Validation\EditionLimits;
use ContactLift\Storage\SettingsRepository;
use ContactLiftPro\Domain\ImportExport\ConfigDocument;
use ContactLiftPro\Domain\ImportExport\ConfigImporter;
use WP_UnitTestCase;

final class ConfigImportExportTest extends WP_UnitTestCase {

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
							'destination' => '+15551234567',
						),
					),
				),
			),
			EditionLimits::pro(),
			SettingsRepository::DEFAULT_BAR_ID
		);
	}

	public function test_a_freshly_exported_document_validates_and_reproduces_the_same_configuration(): void {
		$document = ConfigDocument::build( $this->settings->get_settings(), $this->settings->get_bars_document() );
		$result   = ( new ConfigImporter() )->validate( (string) wp_json_encode( $document ), EditionLimits::pro() );

		self::assertTrue( $result['ok'] );
		self::assertSame( 'Main contact bar', $result['bars'][0]['name'] );
		self::assertSame( '+15551234567', $result['bars'][0]['actions'][0]['destination'] );
	}

	public function test_at20_malformed_json_is_rejected_without_changing_anything(): void {
		$before = $this->settings->get_bars_document();

		$result = ( new ConfigImporter() )->validate( '{not valid json', EditionLimits::pro() );

		self::assertFalse( $result['ok'] );
		self::assertEquals( $before, $this->settings->get_bars_document(), 'validate() never writes, regardless of outcome' );
	}

	public function test_at20_a_newer_schema_version_is_rejected(): void {
		$document                   = ConfigDocument::build( $this->settings->get_settings(), $this->settings->get_bars_document() );
		$document['schema_version'] = ConfigDocument::SCHEMA_VERSION + 1;

		$result = ( new ConfigImporter() )->validate( (string) wp_json_encode( $document ), EditionLimits::pro() );

		self::assertFalse( $result['ok'] );
	}

	public function test_at20_a_bar_exceeding_the_edition_limit_is_rejected_entirely_not_partially(): void {
		$document                            = ConfigDocument::build( $this->settings->get_settings(), $this->settings->get_bars_document() );
		$document['bars']['bars'][0]['name'] = str_repeat( 'a', 500 ); // exceeds the 80-character cap.

		$result = ( new ConfigImporter() )->validate( (string) wp_json_encode( $document ), EditionLimits::pro() );

		self::assertFalse( $result['ok'] );
		self::assertArrayHasKey( 'bars.0.name', $result['errors'] );
	}

	public function test_a_nonexistent_content_id_is_dropped_and_reported_not_rejected(): void {
		$document = ConfigDocument::build( $this->settings->get_settings(), $this->settings->get_bars_document() );
		$document['bars']['bars'][0]['exclude']['content_ids'] = array( 999999999 );

		$result = ( new ConfigImporter() )->validate( (string) wp_json_encode( $document ), EditionLimits::pro() );

		self::assertTrue( $result['ok'] );
		self::assertSame( array(), $result['bars'][0]['exclude']['content_ids'] );
		self::assertSame( 1, $result['summary'][0]['dropped_ids'] );
	}

	public function test_importing_a_pro_export_with_two_bars_under_free_limits_truncates_to_one_in_the_dry_run(): void {
		$this->settings->save_bars(
			array(
				array(
					'id'      => SettingsRepository::DEFAULT_BAR_ID,
					'name'    => 'Default',
					'enabled' => true,
					'device'  => 'all',
				),
				array(
					'id'       => 'bar_targeted',
					'name'     => 'Targeted',
					'enabled'  => true,
					'device'   => 'all',
					'priority' => 1,
				),
			),
			EditionLimits::pro(),
			SettingsRepository::DEFAULT_BAR_ID
		);

		$document = ConfigDocument::build( $this->settings->get_settings(), $this->settings->get_bars_document() );

		$under_pro  = ( new ConfigImporter() )->validate( (string) wp_json_encode( $document ), EditionLimits::pro() );
		$under_free = ( new ConfigImporter() )->validate( (string) wp_json_encode( $document ), EditionLimits::free() );

		self::assertTrue( $under_pro['ok'] );
		self::assertCount( 2, $under_pro['bars'], 'both bars survive under Pro\'s own limits' );

		self::assertTrue( $under_free['ok'] );
		self::assertCount( 1, $under_free['bars'], 'the dry-run summary matches what save_bars() would actually commit — never more' );
	}
}
