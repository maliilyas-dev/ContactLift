<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Storage;

use ContactLift\Domain\ValidationException;
use ContactLift\Domain\Validation\BarValidator;
use ContactLift\Domain\Validation\EditionLimits;
use ContactLift\Domain\Validation\SettingsValidator;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the clift_settings and clift_bars options: reading, atomic validated
 * writes, and first-activation defaults. Both options are stored
 * non-autoloaded (they're only needed on admin screens and the one frontend
 * render pass, not on every page load's autoloaded-options fetch).
 */
final class SettingsRepository {

	public const SETTINGS_OPTION = 'clift_settings';
	public const BARS_OPTION     = 'clift_bars';
	public const DEFAULT_BAR_ID  = 'bar_default';

	private SettingsValidator $settings_validator;
	private BarValidator $bar_validator;

	public function __construct( ?SettingsValidator $settings_validator = null, ?BarValidator $bar_validator = null ) {
		$this->settings_validator = $settings_validator ?? new SettingsValidator();
		$this->bar_validator      = $bar_validator ?? new BarValidator();
	}

	/**
	 * Runs on activation. Never overwrites an existing option — reactivating
	 * (or activating after a prior uninstall that preserved data) must not
	 * clobber what's already there.
	 */
	public function install(): void {
		if ( false === get_option( self::SETTINGS_OPTION, false ) ) {
			add_option( self::SETTINGS_OPTION, $this->default_settings(), '', false );
		}

		if ( false === get_option( self::BARS_OPTION, false ) ) {
			add_option( self::BARS_OPTION, $this->default_bars_document(), '', false );
		}
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_settings(): array {
		$stored = get_option( self::SETTINGS_OPTION, false );

		return is_array( $stored ) ? $stored : $this->default_settings();
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_bars_document(): array {
		$stored = get_option( self::BARS_OPTION, false );

		return is_array( $stored ) ? $stored : $this->default_bars_document();
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function get_bars(): array {
		return $this->get_bars_document()['bars'] ?? array();
	}

	public function get_default_bar(): ?array {
		$document = $this->get_bars_document();

		foreach ( $document['bars'] ?? array() as $bar ) {
			if ( ( $bar['id'] ?? '' ) === ( $document['default_bar_id'] ?? '' ) ) {
				return $bar;
			}
		}

		return null;
	}

	/**
	 * Validates then saves in one step. Nothing is written unless the whole
	 * document validates — a failed save leaves the previous settings live.
	 *
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed> The normalized, now-saved settings.
	 * @throws ValidationException
	 */
	public function save_settings( array $raw ): array {
		$normalized             = $this->settings_validator->validate( $raw );
		$normalized['revision'] = $this->next_revision( self::SETTINGS_OPTION );

		update_option( self::SETTINGS_OPTION, $normalized, false );

		return $normalized;
	}

	/**
	 * Validates every bar before writing anything. On any single bar's
	 * failure, none of the document is saved.
	 *
	 * @param array<int,array<string,mixed>> $raw_bars
	 * @throws ValidationException Aggregated field errors, keyed "bars.{index}.{field}".
	 */
	public function save_bars( array $raw_bars, EditionLimits $limits, ?string $default_bar_id = null ): array {
		$raw_bars = array_slice( array_values( $raw_bars ), 0, max( 1, $limits->max_bars ) );

		$errors      = array();
		$bars        = array();
		$default_ids = array();

		foreach ( $raw_bars as $index => $raw_bar ) {
			$is_default = ( $raw_bar['id'] ?? null ) === self::DEFAULT_BAR_ID;

			try {
				$bar           = $this->bar_validator->validate( is_array( $raw_bar ) ? $raw_bar : array(), $limits, $is_default );
				$bars[]        = $bar;
				$default_ids[] = $bar['id'];
			} catch ( ValidationException $e ) {
				foreach ( $e->errors() as $field => $message ) {
					$errors[ "bars.{$index}.{$field}" ] = $message;
				}
			}
		}

		if ( $errors ) {
			throw new ValidationException( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field messages are esc_html()'d by the admin screen that catches this, never echoed here.
		}

		$default_bar_id = in_array( $default_bar_id, $default_ids, true ) ? $default_bar_id : ( $default_ids[0] ?? self::DEFAULT_BAR_ID );

		$document = array(
			'schema_version' => 1,
			'revision'       => $this->next_revision( self::BARS_OPTION ),
			'default_bar_id' => $default_bar_id,
			'bars'           => $bars,
		);

		update_option( self::BARS_OPTION, $document, false );

		return $document;
	}

	private function next_revision( string $option ): int {
		$stored = get_option( $option, false );

		return is_array( $stored ) && isset( $stored['revision'] ) ? ( (int) $stored['revision'] + 1 ) : 1;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function default_settings(): array {
		return array(
			'schema_version'           => 1,
			'revision'                 => 1,
			'business_name'            => '',
			'enabled'                  => false,
			'defaults'                 => array( 'preset' => 'general' ),
			'delete_data_on_uninstall' => false,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function default_bars_document(): array {
		return array(
			'schema_version' => 1,
			'revision'       => 1,
			'default_bar_id' => self::DEFAULT_BAR_ID,
			'bars'           => array(
				array(
					'id'                  => self::DEFAULT_BAR_ID,
					'name'                => __( 'Main contact bar', 'contactlift' ),
					'enabled'             => false,
					'priority'            => 0,
					'device'              => 'all',
					'include'             => array(
						'content_ids'     => array(),
						'product_cat_ids' => array(),
					),
					'exclude'             => array( // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- the bar schema's own "exclude" field (spec-defined name), not a WP_Query/get_posts() argument.
						'content_ids'     => array(),
						'product_cat_ids' => array(),
					),
					'include_descendants' => false,
					'actions'             => array(),
					'schedule'            => array(
						'enabled'    => false,
						'weekly'     => array(),
						'exceptions' => array(),
					),
					'appearance'          => array(
						'background'     => '#ffffff',
						'text'           => '#0b1d33',
						'accent'         => '#ff5c8a',
						'desktop_side'   => 'right',
						'desktop_layout' => 'launcher',
						'mobile_layout'  => 'bar',
						'show_labels'    => true,
						'bottom_offset'  => 16,
					),
				),
			),
		);
	}
}
