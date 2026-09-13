<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain\Validation;

defined( 'ABSPATH' ) || exit;

/**
 * The one shared bar schema is validated the same way in both editions; what
 * differs is which parts of it an edition is allowed to use. Free and Pro
 * both construct one of these and hand it to BarValidator — see the
 * capability table in the spec's "Scope and editions" section.
 */
final class EditionLimits {

	public function __construct(
		public readonly int $max_bars,
		public readonly int $max_exclude_content_ids,
		public readonly int $max_exclude_product_cat_ids,
		public readonly bool $allow_include,
		public readonly bool $allow_product_targeting,
		public readonly bool $allow_schedule,
		/** @var string[] */
		public readonly array $allowed_channel_types
	) {}

	public static function free(): self {
		return new self(
			max_bars: 1,
			max_exclude_content_ids: 5,
			max_exclude_product_cat_ids: 0,
			allow_include: false,
			allow_product_targeting: false,
			allow_schedule: false,
			allowed_channel_types: array( 'call', 'whatsapp', 'email', 'link' )
		);
	}

	/**
	 * Default plus nine targeted bars; page/post/product/category rules
	 * (both include and exclude); business hours. See the spec's "Scope
	 * and editions" capability table.
	 */
	public static function pro(): self {
		return new self(
			max_bars: 10,
			max_exclude_content_ids: 200,
			max_exclude_product_cat_ids: 200,
			allow_include: true,
			allow_product_targeting: true,
			allow_schedule: true,
			allowed_channel_types: array( 'call', 'whatsapp', 'email', 'link', 'callback' )
		);
	}
}
