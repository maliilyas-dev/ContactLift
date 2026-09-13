<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Admin;

use ContactLift\Admin\Icons;
use ContactLift\Domain\Validation\EditionLimits;
use ContactLift\Storage\SettingsRepository;
use ContactLiftPro\Domain\ImportExport\ConfigDocument;
use ContactLiftPro\Domain\ImportExport\ConfigImporter;

defined( 'ABSPATH' ) || exit;

/**
 * "Tools: ... Pro configuration import/export" (spec, screens table) — a
 * card on free's Tools screen via clift_admin_tools_extra. Import is a
 * two-step dry-run-then-confirm flow: the first submit only validates and
 * stores the normalized, not-yet-saved result in a transient; nothing is
 * written to clift_settings/clift_bars until the admin explicitly confirms
 * the summary — "An import must preserve the prior configuration until the
 * whole new document validates."
 */
final class ImportExportExtension {

	private const NONCE_ACTION     = 'clift_pro_import_export';
	private const NONCE_FIELD      = 'clift_pro_import_export_nonce';
	private const MAX_UPLOAD_BYTES = 256 * 1024;

	private SettingsRepository $settings;

	public function __construct( ?SettingsRepository $settings = null ) {
		$this->settings = $settings ?? new SettingsRepository();
	}

	public function register(): void {
		add_action( 'clift_admin_tools_extra', array( $this, 'render' ) );
		add_action( 'admin_post_clift_pro_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_clift_pro_import_dry_run', array( $this, 'handle_dry_run' ) );
		add_action( 'admin_post_clift_pro_import_confirm', array( $this, 'handle_confirm' ) );
		add_action( 'admin_post_clift_pro_import_cancel', array( $this, 'handle_cancel' ) );
	}

	public function render(): void {
		$pending = $this->pending_import();
		?>
		<div class="clift-a-card">
			<div class="clift-a-card__head">
				<span class="clift-a-card__ic"><?php echo Icons::markup( 'external-link' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div>
					<h2><?php esc_html_e( 'Export configuration', 'contactlift-pro' ); ?></h2>
					<p><?php esc_html_e( 'Downloads your business settings and bars as JSON. Never includes callback requests, statistics, recipients or any credentials.', 'contactlift-pro' ); ?></p>
				</div>
			</div>
			<div class="clift-a-card__body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="clift_pro_export" />
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<?php submit_button( __( 'Download JSON', 'contactlift-pro' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>

		<div class="clift-a-card">
			<div class="clift-a-card__head">
				<span class="clift-a-card__ic"><?php echo Icons::markup( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div>
					<h2><?php esc_html_e( 'Import configuration', 'contactlift-pro' ); ?></h2>
					<p><?php esc_html_e( 'Replaces your current business settings and bars. Review the summary before confirming — nothing changes until you do.', 'contactlift-pro' ); ?></p>
				</div>
			</div>
			<div class="clift-a-card__body">
				<?php if ( null === $pending ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="clift_pro_import_dry_run" />
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
						<div class="clift-a-field">
							<input type="file" name="import_file" accept="application/json" required />
						</div>
						<?php submit_button( __( 'Review import', 'contactlift-pro' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php elseif ( isset( $pending['errors'] ) ) : ?>
					<div class="clift-a-notice clift-a-notice--error">
						<?php echo Icons::markup( 'alert' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div>
							<p><?php esc_html_e( 'This file couldn\'t be imported:', 'contactlift-pro' ); ?></p>
							<ul>
								<?php foreach ( $pending['errors'] as $message ) : ?>
									<li><?php echo esc_html( $message ); ?></li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>
					<?php $this->render_cancel_button(); ?>
				<?php else : ?>
					<div class="clift-a-notice clift-a-notice--success">
						<?php echo Icons::markup( 'check-circle' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<div>
							<p>
								<?php
								printf(
									/* translators: %d: number of bars found in the file. */
									esc_html__( 'This file validated: %d bar(s) will replace your current configuration.', 'contactlift-pro' ),
									count( $pending['summary'] )
								);
								?>
							</p>
							<ul>
								<?php foreach ( $pending['summary'] as $row ) : ?>
									<li>
										<?php
										printf(
											/* translators: 1: bar name, 2: action count. */
											esc_html__( '%1$s — %2$d action(s)', 'contactlift-pro' ),
											esc_html( $row['name'] ),
											(int) $row['actions']
										);
										if ( $row['dropped_ids'] > 0 ) {
											echo ' ';
											printf(
												/* translators: %d: number of dropped IDs. */
												esc_html__( '(%d exclude/include ID(s) no longer exist locally and were dropped)', 'contactlift-pro' ),
												(int) $row['dropped_ids']
											);
										}
										?>
									</li>
								<?php endforeach; ?>
							</ul>
						</div>
					</div>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
						<input type="hidden" name="action" value="clift_pro_import_confirm" />
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
						<?php submit_button( __( 'Replace configuration now', 'contactlift-pro' ), 'primary', 'submit', false ); ?>
					</form>
					<?php $this->render_cancel_button(); ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function render_cancel_button(): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;">
			<input type="hidden" name="action" value="clift_pro_import_cancel" />
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<?php submit_button( __( 'Cancel', 'contactlift-pro' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'contactlift-pro' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$document = ConfigDocument::build( $this->settings->get_settings(), $this->settings->get_bars_document() );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="contactlift-export-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $document, JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a JSON file download, not HTML; wp_json_encode() already produces safe, well-formed output for this content type.
		exit;
	}

	public function handle_dry_run(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'contactlift-pro' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$redirect = $this->tools_url();
		$file     = $_FILES['import_file'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified above via check_admin_referer(); only PHP's own upload metadata (error/size/tmp_name, never the user-supplied original filename) is read below, and the file's JSON content is fully validated by ConfigImporter before anything is trusted.

		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$this->store_pending( array( 'errors' => array( __( 'Choose a file to upload.', 'contactlift-pro' ) ) ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( ( $file['size'] ?? 0 ) > self::MAX_UPLOAD_BYTES ) {
			$this->store_pending( array( 'errors' => array( __( 'That file is too large (256 KB max).', 'contactlift-pro' ) ) ) );
			wp_safe_redirect( $redirect );
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a short-lived admin-uploaded file, read once for JSON parsing; never executed or included.
		$raw = file_get_contents( $file['tmp_name'] );

		$limits = apply_filters( 'clift_edition_limits', EditionLimits::free() );
		$result = ( new ConfigImporter() )->validate( false !== $raw ? $raw : '', $limits );

		$this->store_pending( $result );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function handle_confirm(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'contactlift-pro' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$pending = $this->pending_import();

		if ( null !== $pending && ( $pending['ok'] ?? false ) ) {
			$limits = apply_filters( 'clift_edition_limits', EditionLimits::free() );
			$this->settings->save_settings( $pending['settings'] );
			$this->settings->save_bars( $pending['bars'], $limits, $pending['default_bar_id'] );
		}

		$this->clear_pending();
		wp_safe_redirect( $this->tools_url() );
		exit;
	}

	public function handle_cancel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'contactlift-pro' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$this->clear_pending();
		wp_safe_redirect( $this->tools_url() );
		exit;
	}

	private function tools_url(): string {
		return admin_url( 'admin.php?page=contactlift-tools' );
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function store_pending( array $result ): void {
		set_transient( 'clift_pro_import_' . get_current_user_id(), $result, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function pending_import(): ?array {
		$value = get_transient( 'clift_pro_import_' . get_current_user_id() );

		return is_array( $value ) ? $value : null;
	}

	private function clear_pending(): void {
		delete_transient( 'clift_pro_import_' . get_current_user_id() );
	}
}
