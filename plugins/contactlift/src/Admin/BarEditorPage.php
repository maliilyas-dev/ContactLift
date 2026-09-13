<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Admin;

use ContactLift\Domain\Channels\ChannelRegistry;
use ContactLift\Domain\ValidationException;
use ContactLift\Domain\Validation\EditionLimits;
use ContactLift\Frontend\Icons as FrontendIcons;
use ContactLift\Storage\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Edits one bar: actions, exclusions, and appearance — the default bar in
 * the free edition, or (via ?bar_id=<id>|new) any of Pro's additional
 * targeted bars. Pro adds its own targeting/schedule fields to this same
 * screen through the clift_bar_editor_fields action and merges its own
 * submitted fields through the clift_bar_editor_submission filter, rather
 * than forking a parallel editor.
 */
final class BarEditorPage {

	private const NONCE_ACTION = 'clift_save_bar';
	private const NONCE_FIELD  = 'clift_bar_nonce';
	private const MAX_ACTIONS  = 4;

	private SettingsRepository $settings;
	private ChannelRegistry $channels;

	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
		$this->channels = new ChannelRegistry();
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'contactlift' ) );
		}

		$bar_id_param = isset( $_GET['bar_id'] ) ? sanitize_text_field( wp_unslash( $_GET['bar_id'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing, not a state change.
		$is_new       = 'new' === $bar_id_param;
		$is_default   = ! $is_new && ( null === $bar_id_param || SettingsRepository::DEFAULT_BAR_ID === $bar_id_param );

		$feedback = $this->consume_feedback();

		if ( isset( $feedback['values'] ) ) {
			$bar = $feedback['values'];
		} elseif ( $is_new ) {
			$bar = array();
		} elseif ( $is_default ) {
			$bar = $this->settings->get_default_bar() ?? array();
		} else {
			$bar = $this->find_bar( (string) $bar_id_param ) ?? array();
		}

		$errors = $feedback['errors'] ?? array();
		$saved  = isset( $_GET['clift_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success flag, no state change.
		$limits = apply_filters( 'clift_edition_limits', EditionLimits::free() );

		$appearance = $bar['appearance'] ?? array();
		$actions    = $bar['actions'] ?? array();
		$excluded   = $bar['exclude']['content_ids'] ?? array();

		$heading = $is_default
			? __( 'Main contact bar', 'contactlift' )
			: ( $is_new ? __( 'New targeted bar', 'contactlift' ) : ( ( $bar['name'] ?? '' ) ? $bar['name'] : __( 'Edit bar', 'contactlift' ) ) );

		?>
		<div class="wrap clift-admin">
			<?php Header::render( 'bars', LiveStatus::is_live( $this->settings ) ); ?>

			<div class="clift-a-pagehead">
				<div>
					<h1><?php echo esc_html( $heading ); ?></h1>
					<p><?php esc_html_e( 'What visitors see, and how to reach you.', 'contactlift' ); ?></p>
				</div>
				<a class="clift-a-btn clift-a-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=contactlift' ) ); ?>">
					<?php esc_html_e( 'Back to Bars', 'contactlift' ); ?>
				</a>
			</div>

			<?php if ( $saved && ! $errors ) : ?>
				<div class="clift-a-notice clift-a-notice--success">
					<?php echo Icons::markup( 'check-circle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<p><?php esc_html_e( 'Bar saved.', 'contactlift' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $errors ) : ?>
				<div class="clift-a-notice clift-a-notice--error">
					<?php echo Icons::markup( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<div>
						<p><?php esc_html_e( 'Some fields need attention:', 'contactlift' ); ?></p>
						<ul>
							<?php foreach ( $errors as $field => $message ) : ?>
								<li><?php echo esc_html( $this->describe_error( $field, $message ) ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="clift-bar-form" data-clift-unsaved-guard>
				<input type="hidden" name="action" value="clift_save_bar" />
				<input type="hidden" name="id" value="<?php echo esc_attr( $bar['id'] ?? ( $is_default ? SettingsRepository::DEFAULT_BAR_ID : '' ) ); ?>" />
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

				<div class="clift-a-columns">
					<div>
						<div class="clift-a-card">
							<div class="clift-a-card__head">
								<span class="clift-a-card__ic"><?php echo Icons::markup( 'bars' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<div>
									<h2><?php esc_html_e( 'Bar', 'contactlift' ); ?></h2>
									<p><?php esc_html_e( 'Name is for your reference only — visitors never see it.', 'contactlift' ); ?></p>
								</div>
							</div>
							<div class="clift-a-card__body">
								<div class="clift-a-field <?php echo isset( $errors['name'] ) ? 'has-error' : ''; ?>">
									<label class="clift-a-label" for="clift_name"><?php esc_html_e( 'Name', 'contactlift' ); ?></label>
									<input type="text" id="clift_name" name="name" maxlength="80"
										value="<?php echo esc_attr( $bar['name'] ?? ( $is_default ? __( 'Main contact bar', 'contactlift' ) : '' ) ); ?>" />
								</div>
								<div class="clift-a-field">
									<?php Fields::toggle( 'enabled', __( 'Enabled', 'contactlift' ), ! empty( $bar['enabled'] ) ); ?>
								</div>
								<div class="clift-a-field">
									<span class="clift-a-label"><?php esc_html_e( 'Show on', 'contactlift' ); ?></span>
									<?php $this->render_device_field( $bar['device'] ?? 'all' ); ?>
								</div>
								<?php if ( ! $is_default ) : ?>
									<div class="clift-a-field <?php echo isset( $errors['priority'] ) ? 'has-error' : ''; ?>">
										<label class="clift-a-label" for="clift_priority"><?php esc_html_e( 'Priority', 'contactlift' ); ?></label>
										<input type="number" id="clift_priority" name="priority" min="0" max="1000" style="max-width:100px;"
											value="<?php echo esc_attr( (string) ( $bar['priority'] ?? 0 ) ); ?>" />
										<p class="description"><?php esc_html_e( 'When more than one bar matches a page, the highest priority wins.', 'contactlift' ); ?></p>
									</div>
								<?php endif; ?>
							</div>
						</div>

						<div class="clift-a-card">
							<div class="clift-a-card__head">
								<span class="clift-a-card__ic"><?php echo FrontendIcons::get( 'chat' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<div>
									<h2><?php esc_html_e( 'Actions', 'contactlift' ); ?></h2>
									<p><?php esc_html_e( 'Up to 4 buttons. A real destination is required before an action can be enabled.', 'contactlift' ); ?></p>
								</div>
							</div>
							<div class="clift-a-card__body">
								<div class="clift-a-actions" id="clift-actions">
									<?php foreach ( array_slice( $actions, 0, self::MAX_ACTIONS ) as $index => $action ) : ?>
										<?php $this->render_action_row( $action, (string) $index, $limits ); ?>
									<?php endforeach; ?>
								</div>
								<template id="clift-action-template">
									<?php $this->render_action_row( array(), '__INDEX__', $limits ); ?>
								</template>
								<div class="clift-a-addaction">
									<button type="button" class="clift-a-btn clift-a-btn--ghost" id="clift-add-action" data-max="<?php echo (int) self::MAX_ACTIONS; ?>">
										<?php echo Icons::markup( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php esc_html_e( 'Add action', 'contactlift' ); ?>
									</button>
								</div>
							</div>
						</div>

						<div class="clift-a-card">
							<div class="clift-a-card__head">
								<span class="clift-a-card__ic"><?php echo Icons::markup( 'monitor' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<div>
									<h2><?php esc_html_e( 'Appearance', 'contactlift' ); ?></h2>
									<p><?php esc_html_e( 'Make it match your brand — or make it pop.', 'contactlift' ); ?></p>
								</div>
							</div>
							<div class="clift-a-card__body">
								<div class="clift-a-colorgrid" style="margin-bottom:18px;">
									<?php $this->render_color_field( 'background', __( 'Background', 'contactlift' ), $appearance['background'] ?? '#ffffff' ); ?>
									<?php $this->render_color_field( 'text', __( 'Text', 'contactlift' ), $appearance['text'] ?? '#0b1d33' ); ?>
									<?php $this->render_color_field( 'accent', __( 'Accent', 'contactlift' ), $appearance['accent'] ?? '#ff5c8a' ); ?>
								</div>

								<div class="clift-a-field">
									<span class="clift-a-label"><?php esc_html_e( 'Desktop position', 'contactlift' ); ?></span>
									<div class="clift-a-segmented" data-preview-target="desktop_side">
										<?php
										foreach ( array(
											'right' => __( 'Bottom right', 'contactlift' ),
											'left'  => __( 'Bottom left', 'contactlift' ),
										) as $value => $label ) :
											?>
											<input type="radio" id="clift_side_<?php echo esc_attr( $value ); ?>" name="desktop_side" value="<?php echo esc_attr( $value ); ?>"
																	<?php checked( ( $appearance['desktop_side'] ?? 'right' ), $value ); ?> />
											<label for="clift_side_<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></label>
										<?php endforeach; ?>
									</div>
								</div>

								<div class="clift-a-field">
									<label class="clift-a-label" for="clift_offset"><?php esc_html_e( 'Bottom offset (px)', 'contactlift' ); ?></label>
									<input type="number" id="clift_offset" name="bottom_offset" min="0" max="300" style="max-width:100px;"
										value="<?php echo esc_attr( (string) ( $appearance['bottom_offset'] ?? 16 ) ); ?>" />
								</div>

								<div class="clift-a-field">
									<?php Fields::toggle( 'show_labels', __( 'Show text labels next to icons', 'contactlift' ), ! isset( $appearance['show_labels'] ) || ! empty( $appearance['show_labels'] ) ); ?>
								</div>
							</div>
						</div>

						<div class="clift-a-card">
							<div class="clift-a-card__head">
								<span class="clift-a-card__ic"><?php echo Icons::markup( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<div>
									<h2><?php esc_html_e( 'Exclusions', 'contactlift' ); ?></h2>
									<p>
										<?php
										printf(
											/* translators: %d: maximum number of excluded pages allowed on this plan. */
											esc_html__( 'This bar never shows on the pages you pick here (up to %d).', 'contactlift' ),
											(int) $limits->max_exclude_content_ids
										);
										?>
									</p>
								</div>
							</div>
							<div class="clift-a-card__body">
								<div class="clift-a-field <?php echo isset( $errors['exclude.content_ids'] ) ? 'has-error' : ''; ?>">
									<?php
									Fields::picker(
										'exclude_content_ids[]',
										$this->excludable_content(),
										$excluded,
										__( 'Search pages, posts…', 'contactlift' ),
										__( 'Nothing to exclude yet — publish a page or post first.', 'contactlift' )
									);
									?>
									<p class="description">
										<?php
										printf(
											/* translators: %d: maximum number of excluded pages allowed on this plan. */
											esc_html__( 'This plan can exclude up to %d pages.', 'contactlift' ),
											(int) $limits->max_exclude_content_ids
										);
										?>
									</p>
									<?php if ( isset( $errors['exclude.content_ids'] ) ) : ?>
										<p class="clift-a-field__error"><?php echo esc_html( $errors['exclude.content_ids'] ); ?></p>
									<?php endif; ?>
								</div>
							</div>
						</div>

						<?php
						/**
						 * Lets Pro render its own targeting (include rules) and schedule
						 * cards on this same screen. No-op when Pro isn't active.
						 *
						 * @param array<string,mixed> $bar        The bar being edited (empty array for a new bar).
						 * @param EditionLimits         $limits
						 * @param bool                  $is_default Whether this is the fixed sitewide fallback bar.
						 * @param array<string,string>  $errors     Field errors from the last failed save, if any.
						 */
						do_action( 'clift_bar_editor_fields', $bar, $limits, $is_default, $errors );
						?>

						<?php submit_button( __( 'Save bar', 'contactlift' ), 'primary', 'submit', false ); ?>
					</div>

					<div class="clift-a-sticky">
						<div class="clift-a-card clift-a-preview" id="clift-preview">
							<div class="clift-a-card__head">
								<span class="clift-a-card__ic"><?php echo Icons::markup( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
								<div>
									<h2><?php esc_html_e( 'Live preview', 'contactlift' ); ?></h2>
									<p><?php esc_html_e( 'Updates as you type.', 'contactlift' ); ?></p>
								</div>
							</div>
							<div class="clift-a-preview__toggle">
								<div class="clift-a-segmented">
									<input type="radio" id="clift_preview_mobile" name="clift_preview_device" value="mobile" checked />
									<label for="clift_preview_mobile"><?php esc_html_e( 'Mobile', 'contactlift' ); ?></label>
									<input type="radio" id="clift_preview_desktop" name="clift_preview_device" value="desktop" />
									<label for="clift_preview_desktop"><?php esc_html_e( 'Desktop', 'contactlift' ); ?></label>
								</div>
							</div>
							<div class="clift-a-preview__frame is-mobile" id="clift-preview-frame">
								<div class="clift-a-preview__page"></div>
							</div>
							<p class="clift-a-preview__hint"><?php esc_html_e( 'Preview links are inert — nothing is sent.', 'contactlift' ); ?></p>
						</div>
					</div>
				</div>
			</form>

			<?php Header::render_footer(); ?>
		</div>
		<?php
	}

	private function describe_error( string $field, string $message ): string {
		if ( 1 === preg_match( '/^actions\.(\d+)\./', $field, $matches ) ) {
			return sprintf(
				/* translators: 1: action position (1-based), 2: validation message. */
				__( 'Action %1$d: %2$s', 'contactlift' ),
				( (int) $matches[1] ) + 1,
				$message
			);
		}

		return $message;
	}

	private function render_device_field( string $current ): void {
		$options = array(
			'all'     => __( 'All devices', 'contactlift' ),
			'mobile'  => __( 'Mobile only', 'contactlift' ),
			'desktop' => __( 'Desktop only', 'contactlift' ),
		);
		?>
		<div class="clift-a-segmented">
			<?php foreach ( $options as $value => $label ) : ?>
				<input type="radio" id="clift_device_<?php echo esc_attr( $value ); ?>" name="device" value="<?php echo esc_attr( $value ); ?>" <?php checked( $current, $value ); ?> />
				<label for="clift_device_<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></label>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_color_field( string $key, string $label, string $value ): void {
		?>
		<div class="clift-a-field">
			<label class="clift-a-label" for="clift_color_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label>
			<div class="clift-a-colorfield">
				<input type="color" id="clift_color_<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $key ); ?>"
					value="<?php echo esc_attr( $value ); ?>" data-preview-color="<?php echo esc_attr( $key ); ?>" />
				<input type="text" value="<?php echo esc_attr( $value ); ?>" data-color-text="<?php echo esc_attr( $key ); ?>" maxlength="7" />
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $action
	 */
	private function render_action_row( array $action, string $index, EditionLimits $limits ): void {
		$type = $action['type'] ?? 'call';
		?>
		<div class="clift-a-action" data-action-row>
			<div class="clift-a-action__bar">
				<span class="clift-a-action__handle" data-action-move-handle><?php echo Icons::markup( 'grip', 'clift-a-i clift-a-i--sm' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<span class="clift-a-action__icon">
					<?php foreach ( $this->channels->types() as $channel_type ) : ?>
						<span data-channel-fields="<?php echo esc_attr( $channel_type ); ?>"><?php echo FrontendIcons::get( $channel_type ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<?php endforeach; ?>
				</span>
				<select name="actions[<?php echo esc_attr( $index ); ?>][type]" class="clift-a-action__title" data-action-type>
					<?php foreach ( $this->channels->types() as $channel_type ) : ?>
						<option value="<?php echo esc_attr( $channel_type ); ?>" <?php selected( $type, $channel_type ); ?>>
							<?php echo esc_html( ucfirst( $channel_type ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php Fields::toggle( "actions[{$index}][enabled]", __( 'Enabled', 'contactlift' ), ! empty( $action['enabled'] ), true, 'data-preview-enabled' ); ?>
				<button type="button" class="clift-a-action__move" data-action-move="up" aria-label="<?php esc_attr_e( 'Move up', 'contactlift' ); ?>"><?php echo Icons::markup( 'chevron-up' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<button type="button" class="clift-a-action__move" data-action-move="down" aria-label="<?php esc_attr_e( 'Move down', 'contactlift' ); ?>"><?php echo Icons::markup( 'chevron-down' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<button type="button" class="clift-a-action__remove" data-action-remove aria-label="<?php esc_attr_e( 'Remove action', 'contactlift' ); ?>"><?php echo Icons::markup( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
			</div>
			<div class="clift-a-action__body">
				<input type="hidden" name="actions[<?php echo esc_attr( $index ); ?>][id]" value="<?php echo esc_attr( $action['id'] ?? '' ); ?>" />

				<div class="clift-a-field">
					<label class="clift-a-label"><?php esc_html_e( 'Label', 'contactlift' ); ?></label>
					<input type="text" name="actions[<?php echo esc_attr( $index ); ?>][label]" maxlength="80"
						placeholder="<?php esc_attr_e( 'e.g. Call us', 'contactlift' ); ?>"
						value="<?php echo esc_attr( $action['label'] ?? '' ); ?>" data-preview-label />
				</div>

				<div class="clift-a-field" data-channel-fields="call,whatsapp">
					<label class="clift-a-label"><?php esc_html_e( 'Phone number (international)', 'contactlift' ); ?></label>
					<input type="text" name="actions[<?php echo esc_attr( $index ); ?>][destination]" placeholder="+1 555 123 4567"
						value="<?php echo 'email' !== $type && 'link' !== $type ? esc_attr( $action['destination'] ?? '' ) : ''; ?>" />
				</div>

				<div class="clift-a-field" data-channel-fields="whatsapp">
					<label class="clift-a-label"><?php esc_html_e( 'Pre-filled message (optional)', 'contactlift' ); ?></label>
					<textarea name="actions[<?php echo esc_attr( $index ); ?>][message_template]" rows="2" maxlength="1000"><?php echo esc_textarea( $action['message_template'] ?? '' ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Tokens: {site_name} {page_title} {page_url}', 'contactlift' ); ?></p>
				</div>

				<div class="clift-a-field" data-channel-fields="email">
					<label class="clift-a-label"><?php esc_html_e( 'Email address', 'contactlift' ); ?></label>
					<input type="email" name="actions[<?php echo esc_attr( $index ); ?>][destination]"
						value="<?php echo 'email' === $type ? esc_attr( $action['destination'] ?? '' ) : ''; ?>" />
				</div>

				<div class="clift-a-field" data-channel-fields="email">
					<label class="clift-a-label"><?php esc_html_e( 'Subject (optional)', 'contactlift' ); ?></label>
					<input type="text" name="actions[<?php echo esc_attr( $index ); ?>][subject_template]" maxlength="200"
						value="<?php echo esc_attr( $action['subject_template'] ?? '' ); ?>" />
				</div>

				<div class="clift-a-field" data-channel-fields="email">
					<label class="clift-a-label"><?php esc_html_e( 'Body (optional)', 'contactlift' ); ?></label>
					<textarea name="actions[<?php echo esc_attr( $index ); ?>][body_template]" rows="2" maxlength="1000"><?php echo esc_textarea( $action['body_template'] ?? '' ); ?></textarea>
				</div>

				<div class="clift-a-field" data-channel-fields="link">
					<label class="clift-a-label"><?php esc_html_e( 'Link (https://)', 'contactlift' ); ?></label>
					<input type="url" name="actions[<?php echo esc_attr( $index ); ?>][destination]" placeholder="https://"
						value="<?php echo 'link' === $type ? esc_attr( $action['destination'] ?? '' ) : ''; ?>" />
				</div>

				<div class="clift-a-field" data-channel-fields="link">
					<?php Fields::toggle( "actions[{$index}][open_in_new_tab]", __( 'Open in a new tab', 'contactlift' ), ! empty( $action['open_in_new_tab'] ) ); ?>
				</div>

				<?php if ( $limits->allow_schedule ) : ?>
					<div class="clift-a-field">
						<label class="clift-a-label"><?php esc_html_e( 'While closed', 'contactlift' ); ?></label>
						<select name="actions[<?php echo esc_attr( $index ); ?>][closed_behavior]">
							<?php
							foreach ( array(
								'keep'    => __( 'Keep showing it', 'contactlift' ),
								'hide'    => __( 'Hide it', 'contactlift' ),
								'replace' => __( 'Replace it with another option', 'contactlift' ),
							) as $value => $label ) :
								?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $action['closed_behavior'] ?? 'keep', $value ); ?>>
									<?php echo esc_html( $label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Only matters if this bar has a schedule below.', 'contactlift' ); ?></p>
					</div>
					<div class="clift-a-field">
						<label class="clift-a-label"><?php esc_html_e( 'Replacement (used only when "Replace" is chosen above)', 'contactlift' ); ?></label>
						<div class="clift-a-colorgrid">
							<select name="actions[<?php echo esc_attr( $index ); ?>][replacement][type]">
								<?php foreach ( $this->channels->types() as $channel_type ) : ?>
									<option value="<?php echo esc_attr( $channel_type ); ?>" <?php selected( $action['replacement']['type'] ?? '', $channel_type ); ?>>
										<?php echo esc_html( ucfirst( $channel_type ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<input type="text" name="actions[<?php echo esc_attr( $index ); ?>][replacement][destination]"
								placeholder="<?php esc_attr_e( 'Destination (number, email or link)', 'contactlift' ); ?>"
								value="<?php echo esc_attr( $action['replacement']['destination'] ?? '' ); ?>" />
						</div>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @return array<int,array{id:int,title:string,type:string}>
	 */
	private function excludable_content(): array {
		$post_types = array( 'page', 'post' );

		if ( class_exists( 'WooCommerce' ) ) {
			$post_types[] = 'product';
		}

		$posts = get_posts(
			array(
				'post_type'     => $post_types,
				'post_status'   => 'publish',
				'numberposts'   => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- admin-only exclusion picker, not a frontend query; 200 matches the schema's own cap on exclude ID list length.
				'orderby'       => 'title',
				'order'         => 'ASC',
				'no_found_rows' => true,
			)
		);

		$items = array();
		foreach ( $posts as $post ) {
			$items[] = array(
				'id'    => $post->ID,
				'title' => get_the_title( $post ),
				'type'  => get_post_type_object( $post->post_type )->labels->singular_name ?? $post->post_type,
			);
		}

		return $items;
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'contactlift' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$limits = apply_filters( 'clift_edition_limits', EditionLimits::free() );
		$raw    = $this->collect_submission( $limits );

		$referer  = wp_get_referer();
		$redirect = false !== $referer ? $referer : admin_url( 'admin.php?page=contactlift' );

		$document                    = $this->settings->get_bars_document();
		$default_bar_id              = $document['default_bar_id'] ?? SettingsRepository::DEFAULT_BAR_ID;
		[ $merged_bars, $edited_at ] = $this->upsert_bar( $document['bars'] ?? array(), $raw );

		try {
			$this->settings->save_bars( $merged_bars, $limits, $default_bar_id );
			wp_safe_redirect( add_query_arg( 'clift_saved', '1', remove_query_arg( 'clift_saved', $redirect ) ) );
		} catch ( ValidationException $e ) {
			$this->store_feedback( $raw, $this->unprefix_errors( $e->errors(), $edited_at ) );
			wp_safe_redirect( $redirect );
		}

		exit;
	}

	/**
	 * Replaces the bar matching $edited's id, or appends it as a new bar —
	 * never replaces the whole stored document with just the one bar being
	 * edited, since save_bars() overwrites every bar it's given and this
	 * screen must never silently discard the other bars Pro has added.
	 *
	 * @param array<int,array<string,mixed>> $bars
	 * @param array<string,mixed>            $edited
	 * @return array{0:array<int,array<string,mixed>>,1:int} The merged list, and the edited bar's index in it.
	 */
	private function upsert_bar( array $bars, array $edited ): array {
		$id = (string) ( $edited['id'] ?? '' );

		if ( '' !== $id ) {
			foreach ( $bars as $index => $bar ) {
				if ( ( $bar['id'] ?? '' ) === $id ) {
					$bars[ $index ] = $edited;

					return array( $bars, $index );
				}
			}
		}

		$bars[] = $edited;

		return array( $bars, count( $bars ) - 1 );
	}

	private function find_bar( string $id ): ?array {
		foreach ( $this->settings->get_bars() as $bar ) {
			if ( ( $bar['id'] ?? '' ) === $id ) {
				return $bar;
			}
		}

		return null;
	}

	/**
	 * save_bars() prefixes every error with "bars.{index}." since it can
	 * validate several bars at once; this screen only ever submits one, so
	 * only errors at the edited bar's own index are surfaced here — an
	 * unrelated bar failing validation (e.g. after a plan downgrade) must
	 * never show up attached to a different bar's form.
	 *
	 * @param array<string,string> $errors
	 * @return array<string,string>
	 */
	private function unprefix_errors( array $errors, int $edited_at ): array {
		$unprefixed = array();

		foreach ( $errors as $field => $message ) {
			if ( 1 === preg_match( '/^bars\.(\d+)\.(.+)$/', $field, $matches ) && (int) $matches[1] === $edited_at ) {
				$unprefixed[ $matches[2] ] = $message;
			}
		}

		return $unprefixed;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function collect_submission( EditionLimits $limits ): array {
		$exclude_ids = array_map( 'intval', (array) ( $_POST['exclude_content_ids'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing -- intval()'d immediately; nonce verified in handle_save() before this is called.

		$actions = array();
		foreach ( (array) ( $_POST['actions'] ?? array() ) as $raw_action ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput,WordPress.Security.NonceVerification.Missing -- each field below is cast/unslashed individually; nonce verified in handle_save() before this is called.
			if ( ! is_array( $raw_action ) ) {
				continue;
			}

			$actions[] = array(
				'id'               => sanitize_text_field( wp_unslash( $raw_action['id'] ?? '' ) ),
				'type'             => sanitize_key( wp_unslash( $raw_action['type'] ?? '' ) ),
				'label'            => sanitize_text_field( wp_unslash( $raw_action['label'] ?? '' ) ),
				'enabled'          => ! empty( $raw_action['enabled'] ),
				'destination'      => sanitize_text_field( wp_unslash( $raw_action['destination'] ?? '' ) ),
				'message_template' => sanitize_textarea_field( wp_unslash( $raw_action['message_template'] ?? '' ) ),
				'subject_template' => sanitize_text_field( wp_unslash( $raw_action['subject_template'] ?? '' ) ),
				'body_template'    => sanitize_textarea_field( wp_unslash( $raw_action['body_template'] ?? '' ) ),
				'open_in_new_tab'  => ! empty( $raw_action['open_in_new_tab'] ),
				'closed_behavior'  => sanitize_key( wp_unslash( $raw_action['closed_behavior'] ?? 'keep' ) ),
				'replacement'      => $this->collect_replacement( $raw_action['replacement'] ?? null ),
			);
		}

		$raw = array(
			'id'         => sanitize_text_field( wp_unslash( $_POST['id'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer().
			'name'       => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'enabled'    => ! empty( $_POST['enabled'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'priority'   => absint( wp_unslash( $_POST['priority'] ?? 0 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'device'     => sanitize_key( wp_unslash( $_POST['device'] ?? 'all' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'exclude'    => array( // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude -- the bar schema's own "exclude" field (spec-defined name), not a WP_Query/get_posts() argument.
				'content_ids'     => $exclude_ids,
				'product_cat_ids' => array(),
			),
			'actions'    => $actions,
			'appearance' => array(
				'background'    => sanitize_text_field( wp_unslash( $_POST['background'] ?? '#ffffff' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'text'          => sanitize_text_field( wp_unslash( $_POST['text'] ?? '#0b1d33' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'accent'        => sanitize_text_field( wp_unslash( $_POST['accent'] ?? '#ff5c8a' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'desktop_side'  => sanitize_key( wp_unslash( $_POST['desktop_side'] ?? 'right' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'bottom_offset' => absint( wp_unslash( $_POST['bottom_offset'] ?? 16 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
				'show_labels'   => ! empty( $_POST['show_labels'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			),
		);

		/**
		 * Lets Pro merge its own submitted fields — include targeting and the
		 * weekly/exception schedule — into the raw bar array before
		 * validation. Free ignores this filter entirely when Pro isn't active.
		 *
		 * @param array<string,mixed> $raw    The free edition's own submission fields.
		 * @param EditionLimits         $limits
		 */
		return apply_filters( 'clift_bar_editor_submission', $raw, $limits );
	}

	/**
	 * @param mixed $raw
	 * @return array<string,mixed>|null
	 */
	private function collect_replacement( $raw ): ?array {
		if ( ! is_array( $raw ) || '' === trim( (string) ( $raw['type'] ?? '' ) ) ) {
			return null;
		}

		return array(
			'type'        => sanitize_key( wp_unslash( $raw['type'] ) ),
			'label'       => sanitize_text_field( wp_unslash( $raw['label'] ?? '' ) ),
			'enabled'     => true,
			'destination' => sanitize_text_field( wp_unslash( $raw['destination'] ?? '' ) ),
		);
	}

	/**
	 * @param array<string,mixed>  $values
	 * @param array<string,string> $errors
	 */
	private function store_feedback( array $values, array $errors ): void {
		set_transient(
			'clift_bar_feedback_' . get_current_user_id(),
			array(
				'values' => $values,
				'errors' => $errors,
			),
			MINUTE_IN_SECONDS
		);
	}

	/**
	 * @return array{values?:array<string,mixed>,errors?:array<string,string>}
	 */
	private function consume_feedback(): array {
		$key      = 'clift_bar_feedback_' . get_current_user_id();
		$feedback = get_transient( $key );

		if ( ! is_array( $feedback ) ) {
			return array();
		}

		delete_transient( $key );

		return $feedback;
	}
}
