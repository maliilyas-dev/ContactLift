<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Admin;

use ContactLift\Admin\Fields;
use ContactLift\Admin\Icons;
use ContactLift\Domain\ValidationException;
use ContactLiftPro\Domain\Schedule\Clock;
use ContactLiftPro\Storage\CleanupRunner;
use ContactLiftPro\Storage\ProSettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Pro's own card on free's Settings screen (clift_settings_extra) — a
 * separate form/nonce/save handler since it persists to clift_pro_settings,
 * not the option free's own settings form saves.
 */
final class SettingsExtension {

	private const NONCE_ACTION = 'clift_pro_save_settings';
	private const NONCE_FIELD  = 'clift_pro_settings_nonce';

	private ProSettingsRepository $settings;

	public function __construct( ?ProSettingsRepository $settings = null ) {
		$this->settings = $settings ?? new ProSettingsRepository();
	}

	public function register(): void {
		add_action( 'clift_settings_extra', array( $this, 'render' ) );
		add_action( 'admin_post_clift_pro_save_settings', array( $this, 'handle_save' ) );
		add_action( 'admin_post_clift_pro_run_cleanup', array( $this, 'handle_run_cleanup' ) );
	}

	public function render(): void {
		$feedback = $this->consume_feedback();
		$values   = $feedback['values'] ?? $this->settings->get_settings();
		$errors   = $feedback['errors'] ?? array();
		$saved    = isset( $_GET['clift_pro_saved'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only success flag, no state change.

		?>
		<?php if ( $saved && ! $errors ) : ?>
			<div class="clift-a-notice clift-a-notice--success">
				<?php echo Icons::markup( 'check-circle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<p><?php esc_html_e( 'Callback settings saved.', 'contactlift-pro' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $errors ) : ?>
			<div class="clift-a-notice clift-a-notice--error">
				<?php echo Icons::markup( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<div>
					<p><?php esc_html_e( 'Some fields need attention:', 'contactlift-pro' ); ?></p>
					<ul>
						<?php foreach ( $errors as $message ) : ?>
							<li><?php echo esc_html( $message ); ?></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</div>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="clift_pro_save_settings" />
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

			<div class="clift-a-card">
				<div class="clift-a-card__head">
					<span class="clift-a-card__ic"><?php echo Icons::markup( 'monitor' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<div>
						<h2><?php esc_html_e( 'Click reports', 'contactlift-pro' ); ?></h2>
						<p><?php esc_html_e( 'Off by default. Local, approximate counts only — never visitor profiles or cross-site tracking.', 'contactlift-pro' ); ?></p>
					</div>
				</div>
				<div class="clift-a-card__body">
					<div class="clift-a-field">
						<span class="clift-a-label"><?php esc_html_e( 'Reporting', 'contactlift-pro' ); ?></span>
						<div class="clift-a-segmented">
							<?php
							foreach ( array(
								'off'              => __( 'Off', 'contactlift-pro' ),
								'consent_required' => __( 'Consent required', 'contactlift-pro' ),
								'local_aggregate'  => __( 'Local aggregate', 'contactlift-pro' ),
							) as $value => $label ) :
								?>
								<input type="radio" id="clift_pro_analytics_<?php echo esc_attr( $value ); ?>" name="analytics_mode" value="<?php echo esc_attr( $value ); ?>"
									<?php checked( $values['analytics_mode'] ?? 'off', $value ); ?> />
								<label for="clift_pro_analytics_<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( '"Consent required" only counts clicks after your site calls window.ContactLift.setAnalyticsConsent(true). A visitor\'s Global Privacy Control signal always disables reporting for them, in either mode.', 'contactlift-pro' ); ?></p>
					</div>
				</div>
			</div>

			<div class="clift-a-card">
				<div class="clift-a-card__head">
					<span class="clift-a-card__ic"><?php echo Icons::markup( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<div>
						<h2><?php esc_html_e( 'Callback requests', 'contactlift-pro' ); ?></h2>
						<p><?php esc_html_e( 'Required before a callback action can go live.', 'contactlift-pro' ); ?></p>
					</div>
				</div>
				<div class="clift-a-card__body">
					<div class="clift-a-field <?php echo isset( $errors['callback_recipients'] ) ? 'has-error' : ''; ?>">
						<label class="clift-a-label" for="clift_pro_recipients"><?php esc_html_e( 'Notify these email addresses (one per line)', 'contactlift-pro' ); ?></label>
						<textarea id="clift_pro_recipients" name="callback_recipients" rows="3"><?php echo esc_textarea( implode( "\n", $values['callback_recipients'] ?? array() ) ); ?></textarea>
					</div>

					<div class="clift-a-field <?php echo isset( $errors['privacy_url'] ) ? 'has-error' : ''; ?>">
						<label class="clift-a-label" for="clift_pro_privacy_url"><?php esc_html_e( 'Privacy policy URL', 'contactlift-pro' ); ?></label>
						<input type="url" id="clift_pro_privacy_url" name="privacy_url" placeholder="https://"
							value="<?php echo esc_attr( $values['privacy_url'] ?? '' ); ?>" />
					</div>

					<div class="clift-a-field">
						<label class="clift-a-label" for="clift_pro_consent_copy"><?php esc_html_e( 'Consent acknowledgment text', 'contactlift-pro' ); ?></label>
						<textarea id="clift_pro_consent_copy" name="consent_copy" rows="2"
							placeholder="<?php esc_attr_e( 'I agree to be contacted about this request.', 'contactlift-pro' ); ?>"><?php echo esc_textarea( $values['consent_copy'] ?? '' ); ?></textarea>
					</div>
				</div>
			</div>

			<div class="clift-a-card">
				<div class="clift-a-card__head">
					<span class="clift-a-card__ic"><?php echo Icons::markup( 'settings' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
					<div>
						<h2><?php esc_html_e( 'Retention and abuse limits', 'contactlift-pro' ); ?></h2>
					</div>
				</div>
				<div class="clift-a-card__body">
					<div class="clift-a-field">
						<label class="clift-a-label" for="clift_pro_callback_retention"><?php esc_html_e( 'Delete callback requests after', 'contactlift-pro' ); ?></label>
						<select id="clift_pro_callback_retention" name="callback_retention_days">
							<?php foreach ( array( 30, 90, 180 ) as $days ) : ?>
								<option value="<?php echo (int) $days; ?>" <?php selected( (int) ( $values['callback_retention_days'] ?? 90 ), $days ); ?>>
									<?php
									printf(
										/* translators: %d: number of days. */
										esc_html__( '%d days', 'contactlift-pro' ),
										(int) $days
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="clift-a-field">
						<label class="clift-a-label" for="clift_pro_stats_retention"><?php esc_html_e( 'Delete click reports after', 'contactlift-pro' ); ?></label>
						<select id="clift_pro_stats_retention" name="stats_retention_days">
							<?php foreach ( array( 30, 90 ) as $days ) : ?>
								<option value="<?php echo (int) $days; ?>" <?php selected( (int) ( $values['stats_retention_days'] ?? 90 ), $days ); ?>>
									<?php
									printf(
										/* translators: %d: number of days. */
										esc_html__( '%d days', 'contactlift-pro' ),
										(int) $days
									);
									?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="clift-a-field">
						<label class="clift-a-label" for="clift_pro_rl_10min"><?php esc_html_e( 'Max attempts per visitor, per 10 minutes', 'contactlift-pro' ); ?></label>
						<input type="number" id="clift_pro_rl_10min" name="rate_limit_per_10min" min="1" max="1000" style="max-width:100px;"
							value="<?php echo esc_attr( (string) ( $values['rate_limit_per_10min'] ?? 5 ) ); ?>" />
					</div>
					<div class="clift-a-field">
						<label class="clift-a-label" for="clift_pro_rl_hour"><?php esc_html_e( 'Max attempts sitewide, per hour', 'contactlift-pro' ); ?></label>
						<input type="number" id="clift_pro_rl_hour" name="rate_limit_per_hour" min="1" max="100000" style="max-width:100px;"
							value="<?php echo esc_attr( (string) ( $values['rate_limit_per_hour'] ?? 100 ) ); ?>" />
					</div>
				</div>
			</div>

			<div class="clift-a-card">
				<div class="clift-a-card__head">
					<span class="clift-a-card__ic"><?php echo Icons::markup( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG. ?></span>
					<div>
						<h2><?php esc_html_e( 'On uninstall', 'contactlift-pro' ); ?></h2>
						<p><?php esc_html_e( 'By default, deleting ContactLift Pro keeps your callback requests, statistics and settings — they are never removed just because a licence expires or the plugin is switched off.', 'contactlift-pro' ); ?></p>
					</div>
				</div>
				<div class="clift-a-card__body">
					<?php Fields::toggle( 'delete_data_on_uninstall', __( 'Delete all ContactLift Pro data when the plugin is deleted', 'contactlift-pro' ), ! empty( $values['delete_data_on_uninstall'] ) ); ?>
					<p class="description"><?php esc_html_e( 'Removes the callback requests, statistics and Pro settings. Your free ContactLift bar is never touched. To remove the data without deleting the plugin, use the Tools screen.', 'contactlift-pro' ); ?></p>
				</div>
			</div>

			<?php submit_button( __( 'Save callback settings', 'contactlift-pro' ), 'secondary', 'submit', false ); ?>
		</form>

		<div class="clift-a-card">
			<div class="clift-a-card__body">
				<p>
					<?php
					$last_run = ( new CleanupRunner() )->last_run_at();
					if ( null !== $last_run ) {
						printf(
							/* translators: %s: relative or absolute last-cleanup time. */
							esc_html__( 'Last cleanup: %s', 'contactlift-pro' ),
							esc_html( human_time_diff( strtotime( $last_run ) ) . ' ' . __( 'ago', 'contactlift-pro' ) )
						);
					} else {
						esc_html_e( 'Cleanup has not run yet.', 'contactlift-pro' );
					}
					?>
				</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="clift_pro_run_cleanup" />
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<?php submit_button( __( 'Run cleanup now', 'contactlift-pro' ), 'secondary', 'submit', false ); ?>
				</form>
				<p class="description"><?php esc_html_e( 'On low-traffic sites, WP-Cron only runs when someone visits the site — a real server cron calling wp-cron.php keeps this on schedule.', 'contactlift-pro' ); ?></p>
			</div>
		</div>
		<?php
	}

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'contactlift-pro' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$current = $this->settings->get_settings();

		$raw = array(
			'analytics_mode'           => sanitize_key( wp_unslash( $_POST['analytics_mode'] ?? 'off' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer().
			'callback_recipients'      => preg_split( '/[\r\n]+/', (string) wp_unslash( $_POST['callback_recipients'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY ), // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above via check_admin_referer(); preg_split() only breaks the unslashed text into lines, each one sanitized via sanitize_email() a few lines below before this array is used for anything.
			'privacy_url'              => sanitize_text_field( wp_unslash( $_POST['privacy_url'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'consent_copy'             => sanitize_textarea_field( wp_unslash( $_POST['consent_copy'] ?? '' ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'consent_version'          => $current['consent_version'] ?? 1,
			'callback_retention_days'  => absint( wp_unslash( $_POST['callback_retention_days'] ?? 90 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'stats_retention_days'     => absint( wp_unslash( $_POST['stats_retention_days'] ?? 90 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'rate_limit_per_10min'     => absint( wp_unslash( $_POST['rate_limit_per_10min'] ?? 5 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'rate_limit_per_hour'      => absint( wp_unslash( $_POST['rate_limit_per_hour'] ?? 100 ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'delete_data_on_uninstall' => ! empty( $_POST['delete_data_on_uninstall'] ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer().
		);

		$raw['callback_recipients'] = array_map( 'sanitize_email', $raw['callback_recipients'] );

		$referer  = wp_get_referer();
		$redirect = false !== $referer ? $referer : admin_url( 'admin.php?page=contactlift-settings' );

		try {
			$this->settings->save_settings( $raw );
			wp_safe_redirect( add_query_arg( 'clift_pro_saved', '1', remove_query_arg( array( 'clift_pro_saved', 'clift_saved' ), $redirect ) ) );
		} catch ( ValidationException $e ) {
			$this->store_feedback( $raw, $e->errors() );
			wp_safe_redirect( $redirect );
		}

		exit;
	}

	public function handle_run_cleanup(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'contactlift-pro' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		( new CleanupRunner() )->run( Clock::system() );

		$referer = wp_get_referer();
		wp_safe_redirect( false !== $referer ? $referer : admin_url( 'admin.php?page=contactlift-settings' ) );
		exit;
	}

	/**
	 * @param array<string,mixed>  $values
	 * @param array<string,string> $errors
	 */
	private function store_feedback( array $values, array $errors ): void {
		set_transient(
			'clift_pro_settings_feedback_' . get_current_user_id(),
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
		$key      = 'clift_pro_settings_feedback_' . get_current_user_id();
		$feedback = get_transient( $key );

		if ( ! is_array( $feedback ) ) {
			return array();
		}

		delete_transient( $key );

		return $feedback;
	}
}
