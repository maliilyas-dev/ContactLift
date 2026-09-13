<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Admin;

use ContactLift\Domain\BarState;
use ContactLift\Storage\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Landing screen. Free ships exactly one bar (the default), so this is
 * deliberately light — Pro's targeted bars extend the same row list via
 * the clift_admin_bars_list_extra hook rather than a different screen.
 */
final class BarsPage {

	private SettingsRepository $settings;

	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'contactlift' ) );
		}

		$bar     = $this->settings->get_default_bar();
		$is_live = LiveStatus::is_live( $this->settings );

		?>
		<div class="wrap clift-admin">
			<?php Header::render( 'bars', $is_live ); ?>

			<div class="clift-a-pagehead">
				<div>
					<h1><?php esc_html_e( 'Bars', 'contactlift' ); ?></h1>
					<p><?php esc_html_e( 'The free edition shows one contact bar, sitewide.', 'contactlift' ); ?></p>
				</div>
			</div>

			<div class="clift-a-card">
				<?php if ( $bar ) : ?>
					<div class="clift-a-barrow">
						<span class="clift-a-barrow__dot <?php echo $is_live ? 'is-live' : ''; ?>"></span>
						<div class="clift-a-barrow__body">
							<div class="clift-a-barrow__title"><?php echo esc_html( $bar['name'] ?? __( 'Main contact bar', 'contactlift' ) ); ?></div>
							<div class="clift-a-barrow__meta">
								<?php
								if ( ! empty( $bar['enabled'] ) && BarState::has_renderable_action( $bar ) ) {
									esc_html_e( 'Enabled &middot; sitewide', 'contactlift' );
								} elseif ( ! empty( $bar['enabled'] ) ) {
									esc_html_e( 'Enabled, but no action has a destination yet', 'contactlift' );
								} else {
									esc_html_e( 'Disabled', 'contactlift' );
								}
								?>
							</div>
						</div>
						<a class="clift-a-btn clift-a-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=contactlift-bar-editor' ) ); ?>">
							<?php esc_html_e( 'Edit', 'contactlift' ); ?>
						</a>
					</div>
				<?php else : ?>
					<div class="clift-a-empty">
						<?php echo Icons::markup( 'bars' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG. ?>
						<p><?php esc_html_e( 'No bar yet.', 'contactlift' ); ?></p>
					</div>
				<?php endif; ?>

				<?php do_action( 'clift_admin_bars_list_extra' ); ?>
			</div>

			<?php if ( $is_live ) : ?>
				<p>
					<a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'View it on your site', 'contactlift' ); ?> &rarr;
					</a>
				</p>
			<?php endif; ?>

			<?php Header::render_footer(); ?>
		</div>
		<?php
	}
}
