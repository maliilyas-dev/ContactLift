<?php
/**
 * Uninstall cleanup for the free plugin only. Pro's own uninstall.php
 * handles its own tables/options independently — this file must never
 * touch clift_pro_settings, clift_callbacks, clift_daily_stats or
 * clift_rate_limits, even if Pro happens to be inactive at the time.
 *
 * @package ContactLift
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$clift_settings = get_option( 'clift_settings', array() );

if ( empty( $clift_settings['delete_data_on_uninstall'] ) ) {
	return;
}

delete_option( 'clift_settings' );
delete_option( 'clift_bars' );
