<?php
/**
 * AT16 for the Pro add-on: every privileged admin-post handler and both
 * Pro-owned screens must refuse logged-out visitors and low-privilege users
 * (subscriber, editor), even when the request carries a perfectly valid
 * nonce — so a denial can only be the capability check doing its job, never
 * a nonce failure in disguise. Pro's callback inbox holds visitors' names,
 * phone numbers and messages, so this is the part of the plugin where a
 * missing gate would matter most.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Integration;

use ContactLiftPro\Admin\BarEditorExtension;
use ContactLiftPro\Admin\CallbackInboxPage;
use ContactLiftPro\Admin\DataRemovalExtension;
use ContactLiftPro\Admin\ImportExportExtension;
use ContactLiftPro\Admin\ReportsPage;
use ContactLiftPro\Admin\SettingsExtension;
use ContactLiftPro\Storage\CallbackRepository;
use WP_UnitTestCase;

final class ProCapabilityGatesTest extends WP_UnitTestCase {

	private const DELETE_BAR_ID = 'bar_at16';

	/**
	 * The nonce action and request field each handler would accept.
	 */
	private const NONCES = array(
		CallbackInboxPage::class     => array( 'clift_pro_callback_action', 'clift_pro_callback_nonce' ),
		ReportsPage::class           => array( 'clift_pro_reports_csv', 'clift_pro_reports_csv_nonce' ),
		SettingsExtension::class     => array( 'clift_pro_save_settings', 'clift_pro_settings_nonce' ),
		ImportExportExtension::class => array( 'clift_pro_import_export', 'clift_pro_import_export_nonce' ),
		BarEditorExtension::class    => array( 'clift_pro_delete_bar_' . self::DELETE_BAR_ID, '_wpnonce' ),
		DataRemovalExtension::class  => array( 'clift_pro_remove_data', 'clift_pro_remove_data_nonce' ),
	);

	public function set_up(): void {
		parent::set_up();
		$_REQUEST = array();
	}

	public function tear_down(): void {
		$_REQUEST = array();
		parent::tear_down();
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public static function privileged_handlers(): array {
		$handlers = array(
			array( CallbackInboxPage::class, 'handle_status_change' ),
			array( CallbackInboxPage::class, 'handle_notes_save' ),
			array( CallbackInboxPage::class, 'handle_retry' ),
			array( CallbackInboxPage::class, 'handle_delete' ),
			array( ReportsPage::class, 'handle_csv_export' ),
			array( SettingsExtension::class, 'handle_save' ),
			array( SettingsExtension::class, 'handle_run_cleanup' ),
			array( ImportExportExtension::class, 'handle_export' ),
			array( ImportExportExtension::class, 'handle_dry_run' ),
			array( ImportExportExtension::class, 'handle_confirm' ),
			array( ImportExportExtension::class, 'handle_cancel' ),
			array( BarEditorExtension::class, 'handle_delete_bar' ),
			array( DataRemovalExtension::class, 'handle_remove' ),
		);

		$cases = array();

		foreach ( $handlers as $handler ) {
			foreach ( array( 'logged_out', 'subscriber', 'editor' ) as $role ) {
				$cases[ substr( (string) strrchr( $handler[0], '\\' ), 1 ) . '::' . $handler[1] . ' as ' . $role ] = array( $handler[0], $handler[1], $role );
			}
		}

		return $cases;
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function privileged_screens(): array {
		$cases = array();

		foreach ( array( CallbackInboxPage::class, ReportsPage::class ) as $screen ) {
			foreach ( array( 'logged_out', 'subscriber', 'editor' ) as $role ) {
				$cases[ substr( (string) strrchr( $screen, '\\' ), 1 ) . ' as ' . $role ] = array( $screen, $role );
			}
		}

		return $cases;
	}

	private function become( string $role ): void {
		wp_set_current_user( 'logged_out' === $role ? 0 : self::factory()->user->create( array( 'role' => $role ) ) );
	}

	private function plant_valid_nonce( string $target ): void {
		list( $action, $field ) = self::NONCES[ $target ];

		$nonce = wp_create_nonce( $action );

		$_POST[ $field ]    = $nonce;
		$_REQUEST[ $field ] = $nonce;

		if ( BarEditorExtension::class === $target ) {
			$_GET['bar_id']     = self::DELETE_BAR_ID;
			$_REQUEST['bar_id'] = self::DELETE_BAR_ID;
		}
	}

	/**
	 * @dataProvider privileged_handlers
	 */
	public function test_at16_handler_denies_low_privilege_users_even_with_a_valid_nonce( string $target, string $method, string $role ): void {
		$this->become( $role );
		$this->plant_valid_nonce( $target );

		$this->expectException( 'WPDieException' );
		( new $target() )->$method();
	}

	/**
	 * @dataProvider privileged_screens
	 */
	public function test_at16_pro_screen_denies_low_privilege_users( string $target, string $role ): void {
		$this->become( $role );

		$this->expectException( 'WPDieException' );
		( new $target() )->render();
	}

	public function test_at16_administrators_can_open_the_pro_screens(): void {
		$this->become( 'administrator' );

		ob_start();
		( new CallbackInboxPage() )->render();
		$inbox = ob_get_clean();

		ob_start();
		( new ReportsPage() )->render();
		$reports = ob_get_clean();

		self::assertStringContainsString( 'Callback requests', $inbox, 'control: the denial above is the capability gate, not a screen that cannot render at all' );
		self::assertStringContainsString( 'Reports', $reports );
	}

	public function test_at16_a_denied_delete_leaves_the_stored_request_untouched(): void {
		$repository = new CallbackRepository();
		$created    = $repository->insert_if_new(
			array(
				'request_uuid'    => wp_generate_uuid4(),
				'bar_id'          => 'bar_at16',
				'action_id'       => 'action_at16',
				'source_type'     => 'home',
				'source_id'       => null,
				'name'            => 'Jane Doe',
				'phone'           => '+15551234567',
				'email'           => '',
				'message'         => '',
				'consent_version' => '1',
				'consent_text'    => 'I agree to be contacted.',
			),
			hash( 'sha256', 'at16' )
		);

		self::assertSame( 'created', $created['result'] );
		$id = (int) $created['record']['id'];

		$this->become( 'editor' );
		$this->plant_valid_nonce( CallbackInboxPage::class );
		$_POST['id'] = $id;

		try {
			( new CallbackInboxPage() )->handle_delete();
			self::fail( 'an editor must not be able to delete a callback request' );
		} catch ( \WPDieException $e ) {
			self::assertNotNull( $repository->get( $id ), 'the personal data is still there after the denied delete' );
		}
	}
}
