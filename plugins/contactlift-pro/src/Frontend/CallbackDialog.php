<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Frontend;

use ContactLift\Frontend\Icons;
use ContactLift\Frontend\PageContext;
use ContactLiftPro\Storage\ProSettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * The shared callback request dialog — one per page, opened by whichever
 * clift-action--callback button was clicked (see assets/js/callback.js).
 * "The callback dialog traps focus, has an accessible name and restores
 * focus when closed" (spec, Placement and accessibility). Its own JS reads
 * the data-* attributes here to know the REST base and this page's context;
 * per-click bar_id/action_id come from the button that opened it instead.
 */
final class CallbackDialog {

	public function render( string $markup, bool $has_callback, PageContext $context ): string {
		if ( ! $has_callback ) {
			return $markup;
		}

		$settings     = ( new ProSettingsRepository() )->get_settings();
		$consent_text = '' !== trim( (string) ( $settings['consent_copy'] ?? '' ) )
			? $settings['consent_copy']
			: __( 'I agree to be contacted about this request.', 'contactlift-pro' );
		$privacy_url  = (string) ( $settings['privacy_url'] ?? '' );

		ob_start();
		?>
		<div class="clift-cb" id="clift-cb-dialog" hidden
			data-clift-callback-dialog
			data-clift-overlay
			data-rest-url="<?php echo esc_url( rest_url( 'contactlift/v1/' ) ); ?>"
			data-source-type="<?php echo esc_attr( $context->source_type() ); ?>"
			data-source-id="<?php echo esc_attr( (string) ( $context->current_content_id() ?? '' ) ); ?>">
			<div class="clift-cb__backdrop" data-clift-callback-close></div>
			<div class="clift-cb__panel" role="dialog" aria-modal="true" aria-labelledby="clift-cb-title">
				<button type="button" class="clift-cb__close" data-clift-callback-close aria-label="<?php esc_attr_e( 'Close', 'contactlift-pro' ); ?>">
					<?php echo Icons::get( 'close' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG. ?>
				</button>
				<h2 id="clift-cb-title"><?php esc_html_e( 'Request a callback', 'contactlift-pro' ); ?></h2>
				<p class="clift-cb__lead"><?php esc_html_e( 'Leave your details and we\'ll get back to you.', 'contactlift-pro' ); ?></p>

				<form data-clift-callback-form novalidate>
					<div class="clift-cb__field">
						<label for="clift-cb-name"><?php esc_html_e( 'Name', 'contactlift-pro' ); ?></label>
						<input type="text" id="clift-cb-name" name="name" required maxlength="100" autocomplete="name" />
					</div>
					<div class="clift-cb__field">
						<label for="clift-cb-phone"><?php esc_html_e( 'Phone', 'contactlift-pro' ); ?></label>
						<input type="tel" id="clift-cb-phone" name="phone" required maxlength="30" autocomplete="tel" />
					</div>
					<div class="clift-cb__field">
						<label for="clift-cb-email"><?php esc_html_e( 'Email (optional)', 'contactlift-pro' ); ?></label>
						<input type="email" id="clift-cb-email" name="email" maxlength="254" autocomplete="email" />
					</div>
					<div class="clift-cb__field">
						<label for="clift-cb-message"><?php esc_html_e( 'Message (optional)', 'contactlift-pro' ); ?></label>
						<textarea id="clift-cb-message" name="message" maxlength="1000" rows="3"></textarea>
					</div>
					<div class="clift-cb__field clift-cb__field--consent">
						<label>
							<input type="checkbox" id="clift-cb-consent" name="consent" required />
							<span>
								<?php echo esc_html( $consent_text ); ?>
								<?php if ( '' !== $privacy_url ) : ?>
									<a href="<?php echo esc_url( $privacy_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Privacy policy', 'contactlift-pro' ); ?></a>
								<?php endif; ?>
							</span>
						</label>
					</div>

					<div class="clift-cb__hp" aria-hidden="true">
						<label for="clift-cb-website"><?php esc_html_e( 'Leave this field empty', 'contactlift-pro' ); ?></label>
						<input type="text" id="clift-cb-website" name="website" tabindex="-1" autocomplete="off" />
					</div>

					<button type="submit" class="clift-cb__submit" data-clift-callback-submit>
						<?php esc_html_e( 'Send request', 'contactlift-pro' ); ?>
					</button>
				</form>

				<?php // Outside the form deliberately: hiding the form on success (see callback.js) must not also hide this confirmation. ?>
				<div class="clift-cb__status" data-clift-callback-status role="status" aria-live="polite"></div>
			</div>
		</div>
		<?php

		return $markup . (string) ob_get_clean();
	}
}
