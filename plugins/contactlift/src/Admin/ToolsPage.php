<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Admin;

use ContactLift\Storage\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Preview + safe diagnostics. Pro's configuration import/export joins this
 * screen via the clift_admin_tools_extra hook once it's active.
 */
final class ToolsPage {

	private SettingsRepository $settings;

	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'contactlift' ) );
		}

		?>
		<div class="wrap clift-admin">
			<?php Header::render( 'tools', LiveStatus::is_live( $this->settings ) ); ?>

			<div class="clift-a-pagehead">
				<div>
					<h1><?php esc_html_e( 'Tools', 'contactlift' ); ?></h1>
					<p><?php esc_html_e( 'Preview your bar and check for anything that might be stopping it from showing.', 'contactlift' ); ?></p>
				</div>
			</div>

			<div class="clift-a-card">
				<div class="clift-a-card__head">
					<span class="clift-a-card__ic"><?php echo Icons::markup( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<div>
						<h2><?php esc_html_e( 'Preview', 'contactlift' ); ?></h2>
						<p><?php esc_html_e( 'Opens your site in a new tab. Every link is real, but nothing here sends a message on its own.', 'contactlift' ); ?></p>
					</div>
				</div>
				<div class="clift-a-card__body">
					<a class="clift-a-btn clift-a-btn--ghost" href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo Icons::markup( 'external-link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php esc_html_e( 'Open site', 'contactlift' ); ?>
					</a>
				</div>
			</div>

			<div class="clift-a-card">
				<div class="clift-a-card__head">
					<span class="clift-a-card__ic"><?php echo Icons::markup( 'check-circle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<div>
						<h2><?php esc_html_e( 'Diagnostics', 'contactlift' ); ?></h2>
						<p><?php esc_html_e( 'Safe to share with support — contains no personal data or credentials.', 'contactlift' ); ?></p>
					</div>
				</div>
				<div class="clift-a-card__body">
					<?php $this->render_diagnostics(); ?>
				</div>
			</div>

			<?php do_action( 'clift_admin_tools_extra' ); ?>

			<?php Header::render_footer(); ?>
		</div>
		<?php
	}

	private function render_diagnostics(): void {
		global $wp_version;

		$bar          = $this->settings->get_default_bar();
		$settings     = $this->settings->get_settings();
		$has_woo      = class_exists( 'WooCommerce' );
		$has_pro      = did_action( 'clift_pro_loaded' ) > 0;
		$live         = LiveStatus::is_live( $this->settings );
		$action_count = count( $bar['actions'] ?? array() );

		$rows = array(
			__( 'ContactLift version', 'contactlift' )    => CONTACTLIFT_VERSION,
			__( 'WordPress version', 'contactlift' )      => $wp_version,
			__( 'PHP version', 'contactlift' )            => PHP_VERSION,
			__( 'WooCommerce detected', 'contactlift' )   => $has_woo ? __( 'Yes', 'contactlift' ) : __( 'No', 'contactlift' ),
			__( 'ContactLift Pro active', 'contactlift' ) => $has_pro ? __( 'Yes', 'contactlift' ) : __( 'No', 'contactlift' ),
			__( 'Bar enabled sitewide', 'contactlift' )   => ! empty( $settings['enabled'] ) ? __( 'Yes', 'contactlift' ) : __( 'No', 'contactlift' ),
			__( 'Bar currently live', 'contactlift' )     => $live ? __( 'Yes', 'contactlift' ) : __( 'No — check that an action has a destination', 'contactlift' ),
			__( 'Configured actions', 'contactlift' )     => (string) $action_count,
		);
		?>
		<table class="widefat striped" style="max-width:560px;">
			<tbody>
				<?php foreach ( $rows as $label => $value ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $label ); ?></strong></td>
						<td><?php echo esc_html( $value ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
