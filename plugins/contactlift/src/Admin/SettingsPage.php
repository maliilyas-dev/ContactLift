<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Admin;

use ContactLift\Domain\ValidationException;
use ContactLift\Storage\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Business name + lifecycle flags. Bars/actions get their own editor screen
 * once the frontend renderer lands (see docs/progress.md, Milestone 2).
 */
final class SettingsPage {

	private const NONCE_ACTION = 'clift_save_settings';
	private const NONCE_FIELD  = 'clift_settings_nonce';

	private SettingsRepository $settings;

	public function __construct( SettingsRepository $settings ) {
		$this->settings = $settings;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'contactlift' ) );
		}

		$feedback = $this->consume_feedback();
		$values   = $feedback['values'] ?? $this->settings->get_settings();
		$errors   = $feedback['errors'] ?? array();
		$saved    = isset( $_GET['clift_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success flag, no state change.

		?>
		<div class="wrap clift-admin">
			<?php Header::render( 'settings', LiveStatus::is_live( $this->settings ) ); ?>

			<div class="clift-a-pagehead">
				<div>
					<h1><?php esc_html_e( 'Settings', 'contactlift' ); ?></h1>
					<p><?php esc_html_e( 'Your business identity and what happens to ContactLift\'s data if you remove it.', 'contactlift' ); ?></p>
				</div>
			</div>

			<?php if ( $saved && ! $errors ) : ?>
				<div class="clift-a-notice clift-a-notice--success">
					<?php echo Icons::markup( 'check-circle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG. ?>
					<p><?php esc_html_e( 'Settings saved.', 'contactlift' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( $errors ) : ?>
				<div class="clift-a-notice clift-a-notice--error">
					<?php echo Icons::markup( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG. ?>
					<div>
						<p><?php esc_html_e( 'Some fields need attention:', 'contactlift' ); ?></p>
						<ul>
							<?php foreach ( $errors as $message ) : ?>
								<li><?php echo esc_html( $message ); ?></li>
							<?php endforeach; ?>
						</ul>
					</div>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="clift_save_settings" />
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

				<div class="clift-a-card">
					<div class="clift-a-card__head">
						<span class="clift-a-card__ic"><?php echo Icons::markup( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG. ?></span>
						<div>
							<h2><?php esc_html_e( 'Business identity', 'contactlift' ); ?></h2>
							<p><?php esc_html_e( 'Used in setup defaults and as a fallback token in your messages.', 'contactlift' ); ?></p>
						</div>
					</div>
					<div class="clift-a-card__body">
						<div class="clift-a-field <?php echo isset( $errors['business_name'] ) ? 'has-error' : ''; ?>">
							<label class="clift-a-label" for="clift_business_name"><?php esc_html_e( 'Business name', 'contactlift' ); ?></label>
							<input type="text" id="clift_business_name" name="business_name"
								value="<?php echo esc_attr( $values['business_name'] ?? '' ); ?>" maxlength="150" />
							<p class="description"><?php esc_html_e( 'Shown to visitors and available as the {site_name} token.', 'contactlift' ); ?></p>
							<?php if ( isset( $errors['business_name'] ) ) : ?>
								<p class="clift-a-field__error"><?php echo esc_html( $errors['business_name'] ); ?></p>
							<?php endif; ?>
						</div>

						<div class="clift-a-field">
							<?php Fields::toggle( 'enabled', __( 'Show the contact bar on the site', 'contactlift' ), ! empty( $values['enabled'] ) ); ?>
							<p class="description"><?php esc_html_e( 'Finish setting up destinations on the Bars screen before enabling.', 'contactlift' ); ?></p>
						</div>
					</div>
				</div>

				<div class="clift-a-card">
					<div class="clift-a-card__head">
						<span class="clift-a-card__ic"><?php echo Icons::markup( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG. ?></span>
						<div>
							<h2><?php esc_html_e( 'On uninstall', 'contactlift' ); ?></h2>
							<p><?php esc_html_e( 'By default, removing the plugin keeps your data in case you reinstall it.', 'contactlift' ); ?></p>
						</div>
					</div>
					<div class="clift-a-card__body">
						<?php Fields::toggle( 'delete_data_on_uninstall', __( 'Delete all ContactLift data when the plugin is deleted', 'contactlift' ), ! empty( $values['delete_data_on_uninstall'] ) ); ?>
					</div>
				</div>

				<?php submit_button( __( 'Save settings', 'contactlift' ), 'primary', 'submit', false ); ?>
			</form>

			<?php
			/**
			 * Lets Pro render its own settings card(s) — with their own
			 * form/nonce/save handler, since they persist to a different
			 * option (clift_pro_settings) than this page's form does — on
			 * this same screen. No-op when Pro isn't active.
			 */
			do_action( 'clift_settings_extra' );
			?>

			<?php Header::render_footer(); ?>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'contactlift' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$raw = array(
			'business_name'            => sanitize_text_field( wp_unslash( $_POST['business_name'] ?? '' ) ),
			'enabled'                  => ! empty( $_POST['enabled'] ),
			'delete_data_on_uninstall' => ! empty( $_POST['delete_data_on_uninstall'] ),
			'defaults'                 => $this->settings->get_settings()['defaults'] ?? array( 'preset' => 'general' ),
		);

		$referer  = wp_get_referer();
		$redirect = false !== $referer ? $referer : admin_url( 'admin.php?page=contactlift' );

		try {
			$this->settings->save_settings( $raw );
			wp_safe_redirect( add_query_arg( 'clift_saved', '1', remove_query_arg( 'clift_saved', $redirect ) ) );
		} catch ( ValidationException $e ) {
			$this->store_feedback( $raw, $e->errors() );
			wp_safe_redirect( $redirect );
		}

		exit;
	}

	/**
	 * @param array<string,mixed>  $values
	 * @param array<string,string> $errors
	 */
	private function store_feedback( array $values, array $errors ): void {
		set_transient(
			'clift_settings_feedback_' . get_current_user_id(),
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
		$key      = 'clift_settings_feedback_' . get_current_user_id();
		$feedback = get_transient( $key );

		if ( ! is_array( $feedback ) ) {
			return array();
		}

		delete_transient( $key );

		return $feedback;
	}
}
