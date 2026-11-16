<?php
/**
 * WordPress Privacy Tools integration (Tools > Export / Erase Personal Data):
 * "personal-data export and erasure for callback entries matched by email"
 * (spec). A person asking for their data must get exactly their own requests
 * — never someone else's — and an erasure must remove exactly those.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Integration;

use ContactLiftPro\Privacy\PersonalData;
use ContactLiftPro\Storage\CallbackRepository;
use ContactLiftPro\Storage\DataRemover;
use WP_UnitTestCase;

final class PersonalDataTest extends WP_UnitTestCase {

	private CallbackRepository $repository;

	public function set_up(): void {
		parent::set_up();

		( new DataRemover() )->remove_records( true, false );

		$this->repository = new CallbackRepository();

		$this->store( 'Alice', '+15551110000', 'alice@example.com', 'Please call after 5' );
		$this->store( 'Alice again', '+15551110001', 'alice@example.com', '' );
		$this->store( 'Bob', '+15552220000', 'bob@example.com', 'Bob\'s private message' );
		$this->store( 'Phone only', '+15553330000', '', '' );
	}

	private function store( string $name, string $phone, string $email, string $message ): void {
		$this->repository->insert_if_new(
			array(
				'request_uuid'    => wp_generate_uuid4(),
				'bar_id'          => 'bar',
				'action_id'       => 'action',
				'source_type'     => 'home',
				'source_id'       => null,
				'name'            => $name,
				'phone'           => $phone,
				'email'           => $email,
				'message'         => $message,
				'consent_version' => '1',
				'consent_text'    => 'agreed',
			),
			hash( 'sha256', $name )
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function stored_names(): array {
		$names = array_map( static fn( array $row ): string => (string) $row['name'], $this->repository->list( null, 1, 100 ) );
		sort( $names );

		return $names;
	}

	public function test_the_exporter_and_eraser_are_registered_with_wordpress_privacy_tools(): void {
		// Core's own hooks, invoked here to see what Pro registered on them.
		$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound

		self::assertIsCallable( $exporters['contactlift-pro-callbacks']['callback'] );
		self::assertIsCallable( $erasers['contactlift-pro-callbacks']['callback'] );
	}

	public function test_an_export_contains_only_the_requesters_own_requests(): void {
		$result = ( new PersonalData() )->export( 'alice@example.com' );

		self::assertTrue( $result['done'] );
		self::assertCount( 2, $result['data'] );

		$names = array();
		foreach ( $result['data'] as $item ) {
			self::assertSame( 'contactlift-callbacks', $item['group_id'] );
			$fields  = array_column( $item['data'], 'value', 'name' );
			$names[] = $fields['Name'];
			self::assertSame( 'alice@example.com', $fields['Email'] );
			self::assertArrayHasKey( 'Phone', $fields );
			self::assertArrayHasKey( 'Submitted', $fields );
		}
		sort( $names );

		self::assertSame( array( 'Alice', 'Alice again' ), $names );
		self::assertStringNotContainsString( 'private message', (string) wp_json_encode( $result ), 'another person\'s message never appears in the export' );
	}

	public function test_an_export_for_an_unknown_email_is_empty(): void {
		$result = ( new PersonalData() )->export( 'nobody@example.com' );

		self::assertSame( array(), $result['data'] );
		self::assertTrue( $result['done'] );
	}

	public function test_matching_is_exact_not_a_substring(): void {
		self::assertSame( array(), ( new PersonalData() )->export( 'example.com' )['data'] );
		self::assertSame( array(), ( new PersonalData() )->export( 'alice@example' )['data'] );
	}

	public function test_email_casing_does_not_hide_a_persons_records(): void {
		self::assertCount( 2, ( new PersonalData() )->export( 'ALICE@Example.COM' )['data'], 'addresses are case-insensitive, so a differently-cased request must still find everything' );
	}

	public function test_erasure_removes_only_that_requesters_requests(): void {
		$result = ( new PersonalData() )->erase( 'alice@example.com' );

		self::assertTrue( $result['items_removed'] );
		self::assertFalse( $result['items_retained'] );
		self::assertTrue( $result['done'] );

		self::assertSame( array( 'Bob', 'Phone only' ), $this->stored_names(), 'only Alice\'s two requests are gone' );
	}

	public function test_erasure_for_an_unknown_email_removes_nothing(): void {
		$result = ( new PersonalData() )->erase( 'nobody@example.com' );

		self::assertFalse( $result['items_removed'] );
		self::assertCount( 4, $this->repository->list( null, 1, 100 ) );
	}
}
