<?php
/**
 * Uninstall cleanup for ContactLift Pro only. Preserves everything unless the
 * owner explicitly enabled "delete data on uninstall" in Pro's own settings —
 * callback requests are visitors' personal data, so removal is never implied.
 * Never touches the free plugin's clift_settings / clift_bars: deleting Pro
 * must not delete the free bar, and the free plugin's own uninstall.php must
 * never delete these tables (see its comment).
 *
 * @package ContactLiftPro
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$clift_pro_settings = get_option( 'clift_pro_settings', array() );

if ( empty( $clift_pro_settings['delete_data_on_uninstall'] ) ) {
	return;
}

// The plugin itself isn't loaded while WordPress runs this file, so bring in
// just its autoloader.
if ( ! defined( 'CONTACTLIFT_PRO_PATH' ) ) {
	define( 'CONTACTLIFT_PRO_PATH', plugin_dir_path( __FILE__ ) );
}

require_once CONTACTLIFT_PRO_PATH . 'src/Support/autoload.php';

( new \ContactLiftPro\Storage\DataRemover() )->remove_everything();
