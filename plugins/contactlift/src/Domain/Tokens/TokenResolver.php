<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain\Tokens;

defined( 'ABSPATH' ) || exit;

/**
 * Plain-text token substitution. Resolution is a single plain-text pass —
 * whatever calls this is responsible for encoding the result exactly once,
 * afterwards, for its own destination scheme (wa.me text=, mailto query, ...).
 *
 * Free tokens: {site_name}, {page_title}, {page_url}. Pro adds
 * {product_name}, {product_sku}, {product_url}, {variation} by passing more
 * entries in $values — this class has no opinion on which tokens exist.
 */
final class TokenResolver {

	/**
	 * @param array<string,string> $values Token name (without braces) => plain-text value. Missing/empty entries are treated as absent.
	 */
	public function resolve( string $template, array $values ): string {
		$replacements = array();

		foreach ( $values as $token => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}

			$replacements[ '{' . $token . '}' ] = (string) $value;
		}

		$resolved = strtr( $template, $replacements );

		// Anything left in {braces} was an unknown or unresolved token — drop
		// it rather than leaving raw braces or "undefined" text visible.
		$resolved = preg_replace( '/\{[a-z_]+\}/', '', $resolved ) ?? $resolved;

		return $this->tidy_punctuation( $resolved );
	}

	private function tidy_punctuation( string $text ): string {
		// Collapse runs of whitespace left behind by a removed token.
		$text = preg_replace( '/[ \t]{2,}/', ' ', $text ) ?? $text;

		// Drop a space that now sits directly before punctuation.
		$text = preg_replace( '/[ \t]+([.,!?])/', '$1', $text ) ?? $text;

		// Trim whitespace left dangling at the very start/end of a line.
		$text = preg_replace( '/[ \t]+$/m', '', $text ) ?? $text;
		$text = preg_replace( '/^[ \t]+/m', '', $text ) ?? $text;

		return trim( $text );
	}
}
