<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * "Export JSON only, with schema version and ordinary bar settings. Exclude
 * callback records, statistics, recipients, personal data, licences and
 * secrets." (spec, Settings import and export). Builds and reads exactly
 * that envelope — clift_settings and clift_bars, nothing from
 * clift_pro_settings (recipients, retention, licensing all live there).
 */
final class ConfigDocument {

	public const SCHEMA_VERSION = 1;

	/**
	 * @param array<string,mixed> $settings clift_settings, as returned by SettingsRepository::get_settings().
	 * @param array<string,mixed> $bars_document clift_bars, as returned by SettingsRepository::get_bars_document().
	 * @return array<string,mixed>
	 */
	public static function build( array $settings, array $bars_document ): array {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'settings'       => array(
				'business_name'            => $settings['business_name'] ?? '',
				'enabled'                  => ! empty( $settings['enabled'] ),
				'defaults'                 => $settings['defaults'] ?? array( 'preset' => 'general' ),
				'delete_data_on_uninstall' => ! empty( $settings['delete_data_on_uninstall'] ),
			),
			'bars'           => array(
				'default_bar_id' => $bars_document['default_bar_id'] ?? 'bar_default',
				'bars'           => $bars_document['bars'] ?? array(),
			),
		);
	}
}
