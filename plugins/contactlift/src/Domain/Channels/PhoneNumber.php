<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * Shared phone-number normalisation for the call and WhatsApp channels.
 */
final class PhoneNumber {

	/**
	 * Strips ordinary presentation separators (spaces, dashes, dots, parens)
	 * and keeps an optional leading "+" plus 7-15 digits. Returns null if the
	 * input doesn't match after stripping — callers decide how to report that.
	 */
	public static function normalize( string $raw ): ?string {
		$stripped = preg_replace( '/[\s\-.()]/', '', $raw );

		if ( null === $stripped ) {
			return null;
		}

		if ( 1 !== preg_match( '/^\+?[0-9]{7,15}$/', $stripped ) ) {
			return null;
		}

		return $stripped;
	}

	/**
	 * Digits-only form (no leading "+"), for building wa.me paths.
	 */
	public static function digits_only( string $normalized ): string {
		return ltrim( $normalized, '+' );
	}
}
