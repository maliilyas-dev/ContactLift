<?php
/**
 * The admin Data removal tool's engine: deletes only what was chosen, reports
 * what it deleted, and leaves settings (Pro's and the free plugin's) alone.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Integration;

use ContactLiftPro\Storage\CallbackRepository;
use ContactLiftPro\Storage\DailyStatsRepository;
use ContactLiftPro\Storage\DataRemover;
use ContactLiftPro\Storage\ProInstaller;
use WP_UnitTestCase;

final class DataRemoverTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		// Start from empty tables whatever earlier tests left behind.
		( new DataRemover() )->remove_records( true, true );

		$callbacks = new CallbackRepository();

		foreach ( array( 'one', 'two' ) as $name ) {
			$callbacks->insert_if_new(
				array(
					'request_uuid'    => wp_generate_uuid4(),
					'bar_id'          => 'bar',
					'action_id'       => 'action',
					'source_type'     => 'home',
					'source_id'       => null,
					'name'            => $name,
					'phone'           => '+15550000000',
					'email'           => '',
					'message'         => '',
					'consent_version' => '1',
					'consent_text'    => 'agreed',
				),
				hash( 'sha256', $name )
			);
		}

		$stats = new DailyStatsRepository();
		$stats->increment( '2026-01-01', 'bar', 'action', 'call', 'home', null, 'mobile' );
		$stats->increment( '2026-01-02', 'bar', 'action', 'call', 'home', null, 'mobile' );
		$stats->increment( '2026-01-02', 'bar', 'action', 'call', 'home', null, 'mobile' );
	}

	public function test_counts_report_what_is_stored(): void {
		self::assertSame(
			array(
				'callbacks' => 2,
				'stats'     => 2,
			),
			( new DataRemover() )->counts(),
			'two callback requests and two statistics rows (the repeated increment shares a row)'
		);
	}

	public function test_only_the_chosen_categories_are_deleted_and_the_counts_are_reported(): void {
		$remover = new DataRemover();

		self::assertSame(
			array(
				'callbacks' => 2,
				'stats'     => 0,
			),
			$remover->remove_records( true, false )
		);
		self::assertSame(
			array(
				'callbacks' => 0,
				'stats'     => 2,
			),
			$remover->counts(),
			'statistics were not chosen, so they are still there'
		);

		self::assertSame(
			array(
				'callbacks' => 0,
				'stats'     => 2,
			),
			$remover->remove_records( false, true )
		);
		self::assertSame(
			array(
				'callbacks' => 0,
				'stats'     => 0,
			),
			$remover->counts()
		);
	}

	public function test_choosing_nothing_deletes_nothing(): void {
		$remover = new DataRemover();

		self::assertSame(
			array(
				'callbacks' => 0,
				'stats'     => 0,
			),
			$remover->remove_records( false, false )
		);
		self::assertSame(
			array(
				'callbacks' => 2,
				'stats'     => 2,
			),
			$remover->counts()
		);
	}

	public function test_removing_records_leaves_every_setting_alone(): void {
		update_option( ProInstaller::SETTINGS_OPTION, array( 'marker' => 'pro-settings' ), false );
		update_option( 'clift_settings', array( 'business_name' => 'Keep me' ), false );
		update_option( 'clift_bars', array( 'bars' => array() ), false );

		( new DataRemover() )->remove_records( true, true );

		self::assertSame( 'pro-settings', get_option( ProInstaller::SETTINGS_OPTION )['marker'] );
		self::assertSame( 'Keep me', get_option( 'clift_settings' )['business_name'] );
		self::assertIsArray( get_option( 'clift_bars' ) );
	}
}
