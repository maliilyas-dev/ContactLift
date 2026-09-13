<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Privacy;

use ContactLiftPro\Storage\CallbackRepository;

defined( 'ABSPATH' ) || exit;

/**
 * "Provide WordPress privacy-policy suggested text, personal-data export
 * and erasure for callback entries matched by email. For phone-only
 * requests, provide an administrator search/delete workflow after the site
 * owner verifies the requester's identity. Do not create a public phone
 * lookup endpoint." (spec). The phone-only workflow lives in
 * Admin\CallbackInboxPage's search — an authenticated admin screen, never
 * a public route — this class covers only the email-matched WP core
 * Privacy Tools integration.
 */
final class PersonalData {

	public function register(): void {
		add_action( 'admin_init', array( $this, 'register_policy_content' ) );
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
	}

	public function register_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">' .
			esc_html__( 'When a visitor requests a callback through ContactLift, we store their name, phone number, and any optional email address or message they provide, along with the page they submitted it from and the time of the request. This information is used only to contact them back about their request. It is not shared with third parties or used for marketing.', 'contactlift-pro' ) .
			'</p>';

		wp_add_privacy_policy_content( __( 'ContactLift', 'contactlift-pro' ), wp_kses_post( $content ) );
	}

	/**
	 * @param array<int,array{exporter_friendly_name:string,callback:callable}> $exporters
	 * @return array<int,array{exporter_friendly_name:string,callback:callable}>
	 */
	public function register_exporter( array $exporters ): array {
		$exporters['contactlift-pro-callbacks'] = array(
			'exporter_friendly_name' => __( 'ContactLift callback requests', 'contactlift-pro' ),
			'callback'               => array( $this, 'export' ),
		);

		return $exporters;
	}

	/**
	 * @param array<int,array{eraser_friendly_name:string,callback:callable}> $erasers
	 * @return array<int,array{eraser_friendly_name:string,callback:callable}>
	 */
	public function register_eraser( array $erasers ): array {
		$erasers['contactlift-pro-callbacks'] = array(
			'eraser_friendly_name' => __( 'ContactLift callback requests', 'contactlift-pro' ),
			'callback'             => array( $this, 'erase' ),
		);

		return $erasers;
	}

	/**
	 * @return array{data:array<int,array<string,mixed>>,done:bool}
	 */
	public function export( string $email_address ): array {
		$rows = $this->matching_rows( $email_address );
		$data = array();

		foreach ( $rows as $row ) {
			$data[] = array(
				'group_id'    => 'contactlift-callbacks',
				'group_label' => __( 'ContactLift callback requests', 'contactlift-pro' ),
				'item_id'     => 'contactlift-callback-' . $row['id'],
				'data'        => array(
					array(
						'name'  => __( 'Name', 'contactlift-pro' ),
						'value' => $row['name'],
					),
					array(
						'name'  => __( 'Phone', 'contactlift-pro' ),
						'value' => $row['phone'],
					),
					array(
						'name'  => __( 'Email', 'contactlift-pro' ),
						'value' => $row['email'],
					),
					array(
						'name'  => __( 'Message', 'contactlift-pro' ),
						'value' => $row['message'],
					),
					array(
						'name'  => __( 'Submitted', 'contactlift-pro' ),
						'value' => $row['created_at_utc'],
					),
				),
			);
		}

		// Every matching row is fetched in one query (list()'s own cap is
		// generous relative to how many callback requests one email address
		// would plausibly submit), so this exporter is always done in a
		// single WP Privacy Tools page.
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * @return array{items_removed:bool,items_retained:bool,messages:array<int,string>,done:bool}
	 */
	public function erase( string $email_address ): array {
		$repository = new CallbackRepository();
		$rows       = $this->matching_rows( $email_address );

		foreach ( $rows as $row ) {
			$repository->delete( (int) $row['id'] );
		}

		return array(
			'items_removed'  => (bool) $rows,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => true,
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function matching_rows( string $email_address ): array {
		global $wpdb;

		$table = $wpdb->prefix . CallbackRepository::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- privacy-tools export/erasure request, not a cached page-render query.
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email_address ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}
}
