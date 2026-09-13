<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Domain\ImportExport;

use ContactLift\Domain\ValidationException;
use ContactLift\Domain\Validation\BarValidator;
use ContactLift\Domain\Validation\EditionLimits;
use ContactLift\Domain\Validation\SettingsValidator;

defined( 'ABSPATH' ) || exit;

/**
 * "Parse JSON as data; never unserialize user input. Show a dry-run
 * summary, require explicit replacement confirmation and validate against
 * the edition's feature limits. Reject newer schemas... Revalidate or clear
 * content IDs that do not exist locally." (spec). validate() only ever
 * reads and normalizes — nothing is written to clift_settings/clift_bars
 * here; the caller commits a successful result explicitly, only after the
 * admin confirms the dry-run summary this returns.
 */
final class ConfigImporter {

	private SettingsValidator $settings_validator;
	private BarValidator $bar_validator;

	public function __construct( ?SettingsValidator $settings_validator = null, ?BarValidator $bar_validator = null ) {
		$this->settings_validator = $settings_validator ?? new SettingsValidator();
		$this->bar_validator      = $bar_validator ?? new BarValidator();
	}

	/**
	 * @return array{ok:true,settings:array<string,mixed>,bars:array<int,array<string,mixed>>,default_bar_id:string,summary:array<int,array<string,mixed>>}|array{ok:false,errors:array<string,string>}
	 */
	public function validate( string $raw_json, EditionLimits $limits ): array {
		$decoded = json_decode( $raw_json, true );

		if ( ! is_array( $decoded ) ) {
			return $this->failure( array( 'file' => __( 'That file isn\'t valid JSON.', 'contactlift-pro' ) ) );
		}

		$schema_version = (int) ( $decoded['schema_version'] ?? 0 );
		if ( $schema_version > ConfigDocument::SCHEMA_VERSION ) {
			return $this->failure( array( 'file' => __( 'This file was exported from a newer version of ContactLift and can\'t be imported here.', 'contactlift-pro' ) ) );
		}

		$errors   = array();
		$settings = null;

		try {
			$settings = $this->settings_validator->validate( is_array( $decoded['settings'] ?? null ) ? $decoded['settings'] : array() );
		} catch ( ValidationException $e ) {
			foreach ( $e->errors() as $field => $message ) {
				$errors[ "settings.{$field}" ] = $message;
			}
		}

		$raw_bars       = is_array( $decoded['bars']['bars'] ?? null ) ? $decoded['bars']['bars'] : array();
		$default_bar_id = (string) ( $decoded['bars']['default_bar_id'] ?? 'bar_default' );

		// Mirrors SettingsRepository::save_bars()'s own slicing exactly, so
		// this dry-run summary always matches what confirming it would
		// actually commit — importing a Pro export's 10 bars under a free
		// edition's limits must be truncated here too, not just at save time.
		$raw_bars = array_slice( array_values( $raw_bars ), 0, max( 1, $limits->max_bars ) );

		$summary         = array();
		$normalized_bars = array();

		foreach ( $raw_bars as $index => $raw_bar ) {
			if ( ! is_array( $raw_bar ) ) {
				continue;
			}

			$cleaned    = $this->drop_missing_content_ids( $raw_bar );
			$is_default = ( $cleaned['bar']['id'] ?? null ) === $default_bar_id;

			try {
				$bar               = $this->bar_validator->validate( $cleaned['bar'], $limits, $is_default );
				$normalized_bars[] = $bar;
				$summary[]         = array(
					'name'        => $bar['name'],
					'actions'     => count( $bar['actions'] ),
					'dropped_ids' => $cleaned['dropped'],
				);
			} catch ( ValidationException $e ) {
				foreach ( $e->errors() as $field => $message ) {
					$errors[ "bars.{$index}.{$field}" ] = $message;
				}
			}
		}

		if ( $errors || null === $settings ) {
			return $this->failure( $errors );
		}

		if ( ! $normalized_bars ) {
			return $this->failure( array( 'bars' => __( 'This file has no bars to import.', 'contactlift-pro' ) ) );
		}

		if ( ! in_array( $default_bar_id, array_column( $normalized_bars, 'id' ), true ) ) {
			$default_bar_id = $normalized_bars[0]['id'];
		}

		return array(
			'ok'             => true,
			'settings'       => $settings,
			'bars'           => $normalized_bars,
			'default_bar_id' => $default_bar_id,
			'summary'        => $summary,
		);
	}

	/**
	 * @param array<string,mixed> $bar
	 * @return array{bar:array<string,mixed>,dropped:int}
	 */
	private function drop_missing_content_ids( array $bar ): array {
		$dropped = 0;

		foreach ( array( 'include', 'exclude' ) as $key ) {
			if ( ! is_array( $bar[ $key ]['content_ids'] ?? null ) ) {
				continue;
			}

			$kept = array();
			foreach ( $bar[ $key ]['content_ids'] as $id ) {
				if ( null !== get_post( (int) $id ) ) {
					$kept[] = $id;
				} else {
					++$dropped;
				}
			}
			$bar[ $key ]['content_ids'] = $kept;
		}

		return array(
			'bar'     => $bar,
			'dropped' => $dropped,
		);
	}

	/**
	 * @param array<string,string> $errors
	 * @return array{ok:false,errors:array<string,string>}
	 */
	private function failure( array $errors ): array {
		return array(
			'ok'     => false,
			'errors' => $errors,
		);
	}
}
