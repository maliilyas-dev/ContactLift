<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the clift_callbacks table: idempotent insert (AT12/AT13), status and
 * notes, and the atomic notification-claim transition (spec: "Use atomic
 * state transitions to prevent simultaneous manual retries from sending
 * duplicate notifications").
 */
final class CallbackRepository {

	public const TABLE         = 'clift_callbacks';
	public const MAX_ATTEMPTS  = 5;
	public const STATUS_NEW    = 'new';
	public const NOTIF_PENDING = 'pending';
	public const NOTIF_HANDED  = 'handed_to_mailer';
	public const NOTIF_FAILED  = 'failed';

	/**
	 * "Persist the valid request first. Use a unique request UUID to make
	 * retries idempotent... Reuse of that UUID for a different payload
	 * returns a generic conflict without exposing the existing record."
	 *
	 * @param array<string,mixed> $data Every column except id/created_at_utc/updated_at_utc/status/notification_status/attempts.
	 * @return array{result:string,record:array<string,mixed>|null} result is 'created', 'duplicate', 'conflict', or 'error'.
	 */
	public function insert_if_new( array $data, string $payload_hash ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;
		$now   = gmdate( 'Y-m-d H:i:s' );

		// A duplicate request_uuid is an expected, fully handled outcome
		// here (that's the whole idempotency mechanism below) — suppressed
		// so it doesn't log or display as a surprise the way a genuine
		// unexpected query failure should.
		$suppress_was = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table, no wp_query abstraction; uncached by design (each row read at most a few times).
		$inserted = $wpdb->insert(
			$table,
			array(
				'request_uuid'        => $data['request_uuid'],
				'payload_hash'        => $payload_hash,
				'created_at_utc'      => $now,
				'updated_at_utc'      => $now,
				'bar_id'              => $data['bar_id'],
				'action_id'           => $data['action_id'],
				'source_type'         => $data['source_type'],
				'source_id'           => $data['source_id'],
				'name'                => $data['name'],
				'phone'               => $data['phone'],
				'email'               => '' !== $data['email'] ? $data['email'] : null,
				'message'             => $data['message'],
				'consent_version'     => $data['consent_version'],
				'consent_text'        => $data['consent_text'],
				'status'              => self::STATUS_NEW,
				'private_notes'       => '',
				'notification_status' => self::NOTIF_PENDING,
				'attempts'            => 0,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		$wpdb->suppress_errors( $suppress_was );

		if ( false !== $inserted ) {
			return array(
				'result' => 'created',
				'record' => $this->get_by_uuid( $data['request_uuid'] ),
			);
		}

		// insert() returned false — most likely the unique request_uuid
		// constraint. Distinguish a genuine retry from a UUID collision
		// against different fields by comparing the stored payload hash.
		$existing = $this->get_by_uuid( $data['request_uuid'] );

		if ( null === $existing ) {
			return array(
				'result' => 'error',
				'record' => null,
			); // a real, unrelated write failure.
		}

		if ( hash_equals( (string) $existing['payload_hash'], $payload_hash ) ) {
			return array(
				'result' => 'duplicate',
				'record' => $existing,
			);
		}

		return array(
			'result' => 'conflict',
			'record' => null,
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get_by_uuid( string $request_uuid ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE request_uuid = %s", $request_uuid ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function get( int $id ): ?array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
			ARRAY_A
		);

		return is_array( $row ) ? $row : null;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function list( ?string $status = null, int $page = 1, int $per_page = 20 ): array {
		global $wpdb;

		$table  = $wpdb->prefix . self::TABLE;
		$offset = max( 0, ( max( 1, $page ) - 1 ) * $per_page );

		if ( null !== $status ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE status = %s ORDER BY created_at_utc DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
					$status,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} ORDER BY created_at_utc DESC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * "For phone-only requests, provide an administrator search/delete
	 * workflow after the site owner verifies the requester's identity. Do
	 * not create a public phone lookup endpoint." (spec) — an authenticated
	 * admin-screen-only search; there is no REST route for this.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function search_by_phone( string $phone ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE phone LIKE %s ORDER BY created_at_utc DESC LIMIT 50", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
				'%' . $wpdb->esc_like( $phone ) . '%'
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	public function update_status( int $id, string $status ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'status'         => $status,
				'updated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function update_notes( int $id, string $notes ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'private_notes'  => $notes,
				'updated_at_utc' => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Atomically claims this record for a notification attempt — only one
	 * concurrent caller can ever see true. The caller must actually send
	 * the notification immediately after a true result, then call
	 * mark_notification_result() with the real outcome.
	 */
	public function claim_for_notification( int $id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$affected = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET notification_status = %s, attempts = attempts + 1, updated_at_utc = %s
				WHERE id = %d
					AND notification_status IN (%s, %s)
					AND attempts < %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
				self::NOTIF_HANDED,
				gmdate( 'Y-m-d H:i:s' ),
				$id,
				self::NOTIF_PENDING,
				self::NOTIF_FAILED,
				self::MAX_ATTEMPTS
			)
		);

		return is_int( $affected ) && $affected > 0;
	}

	/**
	 * Call after claim_for_notification() returned true and the actual
	 * wp_mail() attempt has been made. A successful send leaves the status
	 * already set by the claim (handed_to_mailer); a failure flips it back.
	 */
	public function mark_notification_result( int $id, bool $sent ): void {
		if ( $sent ) {
			return;
		}

		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array(
				'notification_status' => self::NOTIF_FAILED,
				'updated_at_utc'      => gmdate( 'Y-m-d H:i:s' ),
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	public function delete( int $id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return false !== $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * "Daily scheduled cleanup deletes batches with indexed cutoffs" (spec)
	 * — one indexed DELETE, not a per-row loop.
	 */
	public function delete_older_than( \DateTimeImmutable $cutoff_utc ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at_utc < %s", $cutoff_utc->format( 'Y-m-d H:i:s' ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is our own prefix + fixed constant, never user input.
		);
	}
}
