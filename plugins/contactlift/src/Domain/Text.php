<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text helpers shared by validators and the token resolver. Everything
 * here treats input as inert data — no HTML, no markup, no execution.
 */
final class Text {

	/**
	 * True if the string contains line breaks or other control characters
	 * (destinations, single-line fields). Tab and newline both rejected.
	 */
	public static function has_control_characters( string $value ): bool {
		return 1 === preg_match( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]|\r|\n/', $value );
	}

	/**
	 * Multibyte-safe truncation that never splits a character apart.
	 */
	public static function truncate( string $value, int $max_length ): string {
		if ( function_exists( 'mb_strlen' ) ) {
			return mb_strlen( $value ) > $max_length ? mb_substr( $value, 0, $max_length ) : $value;
		}

		return strlen( $value ) > $max_length ? substr( $value, 0, $max_length ) : $value;
	}

	public static function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $value ) : strlen( $value );
	}
}
