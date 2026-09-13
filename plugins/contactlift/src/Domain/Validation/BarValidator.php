<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain\Validation;

use ContactLift\Domain\Channels\ChannelRegistry;
use ContactLift\Domain\Text;
use ContactLift\Domain\ValidationException;
use ContactLift\Support\Uuid;

defined( 'ABSPATH' ) || exit;

/**
 * Validates and normalizes one bar against the schema in the spec's
 * "Configuration contract" / "Required schema details" sections. The schema
 * itself is shared by both editions; an EditionLimits instance says which
 * parts of it the caller is allowed to use.
 */
final class BarValidator {

	private const MAX_NAME_LENGTH  = 80;
	private const MAX_ID_LENGTH    = 64;
	private const MAX_IDS_PER_LIST = 200;
	private const MIN_PRIORITY     = 0;
	private const MAX_PRIORITY     = 1000;
	private const MAX_ACTIONS      = 4;
	private const MAX_OFFSET       = 300;
	private const MAX_EXCEPTIONS   = 100;
	private const MAX_LABEL_LENGTH = 80;

	private ChannelRegistry $channels;

	public function __construct( ?ChannelRegistry $channels = null ) {
		$this->channels = $channels ?? new ChannelRegistry();
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed> Normalized bar, ready to store.
	 * @throws ValidationException
	 */
	public function validate( array $raw, EditionLimits $limits, bool $is_default = false ): array {
		$errors = array();
		$bar    = array();

		$bar['id'] = $this->validate_id( $raw['id'] ?? '', $errors, 'id' );

		$name = trim( (string) ( $raw['name'] ?? '' ) );
		if ( '' === $name ) {
			$errors['name'] = __( 'Give this bar a name.', 'contactlift' );
		} elseif ( Text::length( $name ) > self::MAX_NAME_LENGTH ) {
			$errors['name'] = __( 'The bar name is too long (80 characters max).', 'contactlift' );
		}
		$bar['name'] = Text::truncate( $name, self::MAX_NAME_LENGTH );

		$bar['enabled'] = ! empty( $raw['enabled'] );

		$priority = (int) ( $raw['priority'] ?? 0 );
		if ( $priority < self::MIN_PRIORITY || $priority > self::MAX_PRIORITY ) {
			$errors['priority'] = __( 'Priority must be between 0 and 1000.', 'contactlift' );
		}
		$bar['priority'] = max( self::MIN_PRIORITY, min( self::MAX_PRIORITY, $priority ) );

		$device = (string) ( $raw['device'] ?? 'all' );
		if ( ! in_array( $device, array( 'all', 'mobile', 'desktop' ), true ) ) {
			$errors['device'] = __( 'Choose a valid display device.', 'contactlift' );
			$device           = 'all';
		}
		$bar['device'] = $device;

		[ $bar['include'], $bar['exclude'], $bar['include_descendants'] ] = $this->validate_targeting( $raw, $limits, $errors, $is_default );

		$bar['actions'] = $this->validate_actions( $raw['actions'] ?? array(), $limits, $errors );

		try {
			$bar['schedule'] = $this->validate_schedule( $raw['schedule'] ?? array(), $limits );
		} catch ( ValidationException $e ) {
			foreach ( $e->errors() as $field => $message ) {
				$errors[ "schedule.{$field}" ] = $message;
			}
			$bar['schedule'] = array(
				'enabled'    => false,
				'weekly'     => array(),
				'exceptions' => array(),
			);
		}

		$bar['appearance'] = $this->validate_appearance( $raw['appearance'] ?? array(), $errors );

		if ( $errors ) {
			throw new ValidationException( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field messages are esc_html()'d by the admin screen that catches this, never echoed here.
		}

		return $bar;
	}

	/**
	 * @param array<string,string> $errors
	 */
	private function validate_id( $raw_id, array &$errors, string $field ): string {
		$id = trim( (string) $raw_id );

		if ( '' === $id ) {
			return Uuid::v4();
		}

		if ( Text::length( $id ) > self::MAX_ID_LENGTH || 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $id ) ) {
			$errors[ $field ] = __( 'Invalid identifier.', 'contactlift' );

			return Uuid::v4();
		}

		return $id;
	}

	/**
	 * @param array<string,string> $errors
	 * @return array{0:array,1:array,2:bool}
	 */
	private function validate_targeting( array $raw, EditionLimits $limits, array &$errors, bool $is_default ): array {
		$include = $raw['include'] ?? array();
		$exclude = $raw['exclude'] ?? array();

		$exclude_content = $this->normalize_id_list( $exclude['content_ids'] ?? array(), self::MAX_IDS_PER_LIST );
		if ( count( $exclude_content ) > $limits->max_exclude_content_ids ) {
			$errors['exclude.content_ids'] = sprintf(
				/* translators: %d: maximum number of excluded pages allowed. */
				__( 'You can exclude up to %d pages on this plan.', 'contactlift' ),
				$limits->max_exclude_content_ids
			);
			$exclude_content = array_slice( $exclude_content, 0, $limits->max_exclude_content_ids );
		}

		$exclude_product_cats = $limits->max_exclude_product_cat_ids > 0
			? array_slice( $this->normalize_id_list( $exclude['product_cat_ids'] ?? array(), self::MAX_IDS_PER_LIST ), 0, $limits->max_exclude_product_cat_ids )
			: array();

		$include_content     = $limits->allow_include ? $this->normalize_id_list( $include['content_ids'] ?? array(), self::MAX_IDS_PER_LIST ) : array();
		$include_product_cat = $limits->allow_include && $limits->allow_product_targeting
			? $this->normalize_id_list( $include['product_cat_ids'] ?? array(), self::MAX_IDS_PER_LIST )
			: array();

		if ( $is_default && ( $include_content || $include_product_cat ) ) {
			// The default bar only ever renders as a fallback; it has no include rules.
			$include_content     = array();
			$include_product_cat = array();
		}

		return array(
			array(
				'content_ids'     => $include_content,
				'product_cat_ids' => $include_product_cat,
			),
			array(
				'content_ids'     => $exclude_content,
				'product_cat_ids' => $exclude_product_cats,
			),
			$limits->allow_product_targeting ? ! empty( $raw['include_descendants'] ) : false,
		);
	}

	/**
	 * @param mixed $raw
	 * @return int[]
	 */
	private function normalize_id_list( $raw, int $max_count ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$ids = array();
		foreach ( $raw as $value ) {
			$id = (int) $value;
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return array_slice( array_values( array_unique( $ids ) ), 0, $max_count );
	}

	/**
	 * @param mixed                $raw
	 * @param array<string,string> $errors
	 * @return array<int,array<string,mixed>>
	 */
	private function validate_actions( $raw, EditionLimits $limits, array &$errors ): array {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$raw = array_slice( array_values( $raw ), 0, self::MAX_ACTIONS );

		$actions = array();
		foreach ( $raw as $index => $raw_action ) {
			if ( ! is_array( $raw_action ) ) {
				continue;
			}

			try {
				$actions[] = $this->validate_action( $raw_action, $limits );
			} catch ( ValidationException $e ) {
				foreach ( $e->errors() as $field => $message ) {
					$errors[ "actions.{$index}.{$field}" ] = $message;
				}
			}
		}

		return $actions;
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 * @throws ValidationException
	 */
	private function validate_action( array $raw, EditionLimits $limits ): array {
		$errors = array();
		$id     = $this->validate_id( $raw['id'] ?? '', $errors, 'id' );

		$type = (string) ( $raw['type'] ?? '' );
		if ( ! in_array( $type, $limits->allowed_channel_types, true ) || ! $this->channels->has( $type ) ) {
			throw new ValidationException( array( 'type' => __( 'Choose a valid channel.', 'contactlift' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field message is esc_html()'d by the admin screen that catches this, never echoed here.
		}

		$label = trim( (string) ( $raw['label'] ?? '' ) );
		if ( Text::length( $label ) > self::MAX_LABEL_LENGTH ) {
			$errors['label'] = __( 'The label is too long.', 'contactlift' );
		}

		$channel_fields = $this->channels->get( $type )->validate( $raw );

		$enabled = ! empty( $raw['enabled'] );
		if ( $enabled && '' === trim( (string) ( $channel_fields['destination'] ?? '' ) ) ) {
			$errors['destination'] = __( 'Add a destination before enabling this action.', 'contactlift' );
			$enabled               = false;
		}

		$closed_behavior = (string) ( $raw['closed_behavior'] ?? 'keep' );
		if ( ! in_array( $closed_behavior, array( 'keep', 'hide', 'replace' ), true ) ) {
			$closed_behavior = 'keep';
		}
		if ( ! $limits->allow_schedule ) {
			// Nothing ever evaluates as "closed" without Pro's schedule engine.
			$closed_behavior = 'keep';
		}

		$replacement = null;
		if ( 'replace' === $closed_behavior ) {
			try {
				$replacement = $this->validate_replacement( $raw['replacement'] ?? array(), $limits );
			} catch ( ValidationException $e ) {
				foreach ( $e->errors() as $field => $message ) {
					$errors[ "replacement.{$field}" ] = $message;
				}
			}
		}

		if ( $errors ) {
			throw new ValidationException( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field messages are esc_html()'d by the admin screen that catches this, never echoed here.
		}

		return array_merge(
			array(
				'id'              => $id,
				'type'            => $type,
				'label'           => Text::truncate( $label, self::MAX_LABEL_LENGTH ),
				'enabled'         => $enabled,
				'closed_behavior' => $closed_behavior,
				'replacement'     => $replacement,
			),
			$channel_fields
		);
	}

	/**
	 * A replacement is validated as a plain channel action — type, label and
	 * that channel's own fields — with no closed_behavior or replacement of
	 * its own. That absence is what "prevents replacement loops" (spec,
	 * "Closed actions") structurally: a replacement can never itself need
	 * replacing.
	 *
	 * @param mixed $raw
	 * @return array<string,mixed>
	 * @throws ValidationException
	 */
	private function validate_replacement( $raw, EditionLimits $limits ): array {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$errors = array();

		$type = (string) ( $raw['type'] ?? '' );
		if ( ! in_array( $type, $limits->allowed_channel_types, true ) || ! $this->channels->has( $type ) ) {
			throw new ValidationException( array( 'type' => __( 'Choose a valid replacement channel.', 'contactlift' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field message is esc_html()'d by the admin screen that catches this, never echoed here.
		}

		$label = trim( (string) ( $raw['label'] ?? '' ) );
		if ( Text::length( $label ) > self::MAX_LABEL_LENGTH ) {
			$errors['label'] = __( 'The label is too long.', 'contactlift' );
		}

		$channel_fields = $this->channels->get( $type )->validate( $raw );

		if ( '' === trim( (string) ( $channel_fields['destination'] ?? '' ) ) ) {
			$errors['destination'] = __( 'Add a destination for the replacement.', 'contactlift' );
		}

		if ( $errors ) {
			throw new ValidationException( $errors ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- field messages are esc_html()'d by the admin screen that catches this, never echoed here.
		}

		return array_merge(
			array(
				'id'      => 'replacement',
				'type'    => $type,
				'label'   => Text::truncate( $label, self::MAX_LABEL_LENGTH ),
				'enabled' => true,
			),
			$channel_fields
		);
	}

	/**
	 * @param mixed $raw
	 * @return array{enabled:bool,weekly:array,exceptions:array}
	 * @throws ValidationException
	 */
	private function validate_schedule( $raw, EditionLimits $limits ): array {
		$disabled = array(
			'enabled'    => false,
			'weekly'     => array(),
			'exceptions' => array(),
		);

		if ( ! $limits->allow_schedule ) {
			return $disabled;
		}

		/**
		 * Lets Pro validate the full weekly/exception schedule schema —
		 * overlap checks, overnight carry, DST-safe date exceptions — via its
		 * own ScheduleValidator. Free never calls this with allow_schedule
		 * true, so the filter has no listener and no effect without Pro
		 * active; a listener may throw ValidationException, which propagates
		 * to validate()'s caller and is merged into the aggregated field
		 * errors there.
		 *
		 * @param array<string,mixed> $disabled The always-disabled fallback.
		 * @param mixed                $raw      The submitted schedule.
		 */
		return apply_filters( 'clift_validate_schedule', $disabled, $raw );
	}

	/**
	 * @param mixed                 $raw
	 * @param array<string,string> $errors
	 * @return array<string,mixed>
	 */
	private function validate_appearance( $raw, array &$errors ): array {
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$desktop_side   = $raw['desktop_side'] ?? 'right';
		$desktop_layout = $raw['desktop_layout'] ?? 'launcher';
		$mobile_layout  = $raw['mobile_layout'] ?? 'bar';

		$appearance = array(
			'background'     => $this->validate_hex_color( $raw['background'] ?? '#ffffff', '#ffffff', $errors, 'appearance.background' ),
			'text'           => $this->validate_hex_color( $raw['text'] ?? '#0b1d33', '#0b1d33', $errors, 'appearance.text' ),
			'accent'         => $this->validate_hex_color( $raw['accent'] ?? '#ff5c8a', '#ff5c8a', $errors, 'appearance.accent' ),
			'desktop_side'   => in_array( $desktop_side, array( 'left', 'right' ), true ) ? $desktop_side : 'right',
			'desktop_layout' => in_array( $desktop_layout, array( 'launcher' ), true ) ? $desktop_layout : 'launcher',
			'mobile_layout'  => in_array( $mobile_layout, array( 'bar' ), true ) ? $mobile_layout : 'bar',
			'show_labels'    => array_key_exists( 'show_labels', $raw ) ? ! empty( $raw['show_labels'] ) : true,
		);

		$offset = (int) ( $raw['bottom_offset'] ?? 16 );
		if ( $offset < 0 || $offset > self::MAX_OFFSET ) {
			$errors['appearance.bottom_offset'] = __( 'Offset must be between 0 and 300 pixels.', 'contactlift' );
		}
		$appearance['bottom_offset'] = max( 0, min( self::MAX_OFFSET, $offset ) );

		return $appearance;
	}

	/**
	 * @param array<string,string> $errors
	 */
	private function validate_hex_color( $raw, string $fallback, array &$errors, string $field ): string {
		$value = trim( (string) $raw );

		if ( 1 !== preg_match( '/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/', $value ) ) {
			$errors[ $field ] = __( 'Enter a valid hex colour.', 'contactlift' );

			return $fallback;
		}

		return $value;
	}
}
