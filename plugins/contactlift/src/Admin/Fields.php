<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Small shared field renderers so every screen's toggle looks and behaves
 * identically instead of each page hand-rolling its own markup.
 */
final class Fields {

	/**
	 * A toggle switch. The real checkbox is visually hidden entirely (not
	 * `appearance:none`'d, which some browsers only partially honor and can
	 * leave native tick artwork bleeding through) — the track/thumb are
	 * separate sibling elements styled purely off :checked, so there is
	 * nothing native left to paint.
	 *
	 * @param bool $hide_label Keeps the label reachable for screen readers
	 *                         but off-screen — for compact contexts (a
	 *                         per-row "enabled" switch) where the visible
	 *                         text would be redundant clutter next to a row
	 *                         that already names itself.
	 */
	public static function toggle( string $name, string $label, bool $checked, bool $hide_label = false, string $extra_attrs = '' ): void {
		printf(
			'<label class="clift-a-toggle"><input type="checkbox" class="clift-a-toggle__input" name="%1$s" value="1" %2$s %3$s /><span class="clift-a-toggle__track"></span><span class="%4$s">%5$s</span></label>',
			esc_attr( $name ),
			checked( $checked, true, false ),
			$extra_attrs, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- caller-supplied plain HTML attributes (data-* hooks), never user input.
			$hide_label ? 'screen-reader-text' : 'clift-a-toggle__text',
			esc_html( $label )
		);
	}

	/**
	 * A searchable checkbox list replacing a native `<select multiple>` —
	 * same "checkboxes sharing one name submit as an array" wire format, so
	 * no server-side collection code needs to know this isn't a plain
	 * multi-select. Used by both free's exclusions field and Pro's
	 * include-targeting field, so they look and behave identically.
	 *
	 * @param array<int,array{id:int,title:string,type:string}> $items
	 * @param int[]                                              $selected
	 */
	public static function picker( string $name, array $items, array $selected, string $search_placeholder, string $empty_message ): void {
		$uid = 'clift-picker-' . wp_unique_id();

		$label_one = __( '1 selected', 'contactlift' );
		/* translators: %d: number of items selected; read and filled in by admin.js, never printf()'d server-side. */
		$label_other = __( '%d selected', 'contactlift' );
		?>
		<div class="clift-a-picker" data-clift-picker>
			<div class="clift-a-picker__search-wrap">
				<?php echo Icons::markup( 'search', 'clift-a-i clift-a-picker__search-ic' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG. ?>
				<input type="search" class="clift-a-picker__search" data-clift-picker-search placeholder="<?php echo esc_attr( $search_placeholder ); ?>" />
			</div>
			<div class="clift-a-picker__list" data-clift-picker-list>
				<?php if ( ! $items ) : ?>
					<p class="clift-a-picker__empty"><?php echo esc_html( $empty_message ); ?></p>
				<?php else : ?>
					<?php foreach ( $items as $item ) : ?>
						<?php $checkbox_id = $uid . '-' . $item['id']; ?>
						<label class="clift-a-picker__row" for="<?php echo esc_attr( $checkbox_id ); ?>" data-clift-picker-row data-search="<?php echo esc_attr( strtolower( $item['title'] . ' ' . $item['type'] ) ); ?>">
							<input type="checkbox" id="<?php echo esc_attr( $checkbox_id ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo (int) $item['id']; ?>" <?php checked( in_array( $item['id'], $selected, true ) ); ?> />
							<span class="clift-a-picker__row-title"><?php echo esc_html( $item['title'] ); ?></span>
							<span class="clift-a-picker__row-type"><?php echo esc_html( $item['type'] ); ?></span>
						</label>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>
			<p class="clift-a-picker__count" data-clift-picker-count
				data-label-one="<?php echo esc_attr( $label_one ); ?>"
				data-label-other="<?php echo esc_attr( $label_other ); ?>"></p>
		</div>
		<?php
	}
}
