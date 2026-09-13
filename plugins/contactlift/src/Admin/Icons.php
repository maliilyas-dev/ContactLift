<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin-chrome icon set (nav, status, editor controls). Channel icons
 * (call/whatsapp/email/link) live in Frontend\Icons and are reused as-is —
 * no reason to define the same shapes twice.
 */
final class Icons {

	private const PATHS = array(
		'bars'          => '<rect x="3" y="10" width="18" height="7" rx="2"/><circle cx="7.5" cy="13.5" r=".8" fill="currentColor" stroke="none"/><path d="M3 10a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4"/>',
		'settings'      => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-1.87-.34 1.7 1.7 0 0 0-1.04 1.56V21a2 2 0 0 1-4 0v-.09A1.7 1.7 0 0 0 8.96 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-1.56-1.04H3a2 2 0 0 1 0-4h.09A1.7 1.7 0 0 0 4.6 8.96a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 8.96 4.6a1.7 1.7 0 0 0 1.04-1.56V3a2 2 0 0 1 4 0v.09A1.7 1.7 0 0 0 15.04 4.6a1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 8.96a1.7 1.7 0 0 0 1.56 1.04H21a2 2 0 0 1 0 4h-.09A1.7 1.7 0 0 0 19.4 15Z"/>',
		'tools'         => '<path d="M14.7 6.3a4 4 0 1 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.6 2.6-2-2Z"/>',
		'plug'          => '<path d="M12 22v-6"/><path d="M9 2v6M15 2v6"/><path d="M7 8h10v3a5 5 0 0 1-10 0V8Z"/>',
		'check-circle'  => '<circle cx="12" cy="12" r="9"/><path d="m8.5 12.5 2.5 2.5 4.5-5"/>',
		'alert'         => '<circle cx="12" cy="12" r="9"/><path d="M12 8v4.5M12 16h.01"/>',
		'chevron-up'    => '<path d="m18 15-6-6-6 6"/>',
		'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
		'trash'         => '<path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>',
		'grip'          => '<circle cx="9" cy="6" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="6" r="1" fill="currentColor" stroke="none"/><circle cx="9" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="9" cy="18" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="18" r="1" fill="currentColor" stroke="none"/>',
		'external-link' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6"/><path d="M10 14 21 3"/>',
		'eye'           => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/>',
		'plus'          => '<path d="M12 5v14M5 12h14"/>',
		'monitor'       => '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>',
		'smartphone'    => '<rect x="6" y="2" width="12" height="20" rx="2.5"/><path d="M11 18h2"/>',
		'search'        => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
	);

	public static function markup( string $name, string $css_class = 'clift-a-i' ): string {
		$body = self::PATHS[ $name ] ?? self::PATHS['alert'];

		return sprintf(
			'<svg class="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
			esc_attr( $css_class ),
			$body // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG path data only, indexed by a fixed set of literal keys.
		);
	}
}
