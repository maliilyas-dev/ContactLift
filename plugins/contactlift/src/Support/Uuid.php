<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Pure-PHP UUID v4 generation, deliberately independent of wp_generate_uuid4()
 * so domain code stays testable without booting WordPress.
 */
final class Uuid {

	public static function v4(): string {
		$data = random_bytes( 16 );

		$data[6] = chr( ( ord( $data[6] ) & 0x0f ) | 0x40 );
		$data[8] = chr( ( ord( $data[8] ) & 0x3f ) | 0x80 );

		return vsprintf( '%s%s-%s-%s-%s-%s%s%s', str_split( bin2hex( $data ), 4 ) );
	}
}
