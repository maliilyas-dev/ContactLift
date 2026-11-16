<?php
/**
 * One racing worker for scripts/concurrency-check.sh — run through
 * `wp eval-file`, which supplies $args: [ start_epoch, increments, uuid ].
 *
 * Every worker waits for the same wall-clock instant, then (1) races to
 * insert the same callback request, (2) races to claim its notification, and
 * (3) hammers the same daily-stats counter. Each worker is a separate PHP
 * process with its own database connection, so the contention is real.
 *
 * The caller runs WP-CLI with --skip-plugins --skip-themes so each worker is
 * just WordPress core (tens of MB, not hundreds); the storage classes under
 * test only need $wpdb and Pro's autoloader, which is loaded by hand below.
 */

if ( ! defined( 'CONTACTLIFT_PRO_PATH' ) ) {
	define( 'CONTACTLIFT_PRO_PATH', dirname( __DIR__ ) . '/plugins/contactlift-pro/' );
}

require_once CONTACTLIFT_PRO_PATH . 'src/Support/autoload.php';

( static function ( array $worker_args ): void {
	$start      = (float) ( $worker_args[0] ?? 0 );
	$increments = (int) ( $worker_args[1] ?? 0 );
	$uuid       = (string) ( $worker_args[2] ?? '' );

	while ( microtime( true ) < $start ) {
		usleep( 500 );
	}

	$callbacks = new \ContactLiftPro\Storage\CallbackRepository();
	$result    = $callbacks->insert_if_new(
		array(
			'request_uuid'    => $uuid,
			'bar_id'          => 'stress-at12',
			'action_id'       => 'stress-at12',
			'source_type'     => 'home',
			'source_id'       => null,
			'name'            => 'Concurrency check',
			'phone'           => '+15550000000',
			'email'           => '',
			'message'         => '',
			'consent_version' => '1',
			'consent_text'    => 'concurrency check',
		),
		hash( 'sha256', 'stress-at12' )
	);

	echo 'RESULT:' . $result['result'] . "\n";

	if ( in_array( $result['result'], array( 'created', 'duplicate' ), true ) ) {
		echo 'CLAIM:' . ( $callbacks->claim_for_notification( (int) $result['record']['id'] ) ? 'yes' : 'no' ) . "\n";
	}

	$stats = new \ContactLiftPro\Storage\DailyStatsRepository();

	for ( $i = 0; $i < $increments; $i++ ) {
		$stats->increment( '2001-01-01', 'stress-at18', 'action', 'call', 'home', null, 'mobile' );
	}

	echo "DONE\n";
} )( isset( $args ) ? $args : array() );
