<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Admin;

use ContactLift\Admin\Icons;
use ContactLiftPro\Storage\DataRemover;

defined( 'ABSPATH' ) || exit;

/**
 * "Include an administrator Data removal tool to remove retained Pro data
 * while both plugins are active." (spec, Deactivation and uninstall.) A card
 * on free's Tools screen via clift_admin_tools_extra. Deletion needs the
 * capability, a nonce, at least one chosen category AND an explicit
 * confirmation — callback requests are visitors' personal data and this can't
 * be undone.
 */
final class DataRemovalExtension {

	private const NONCE_ACTION = 'clift_pro_remove_data';
	private const NONCE_FIELD  = 'clift_pro_remove_data_nonce';

	public function register(): void {
		add_action( 'clift_admin_tools_extra', array( $this, 'render' ) );
		add_action( 'admin_post_clift_pro_remove_data', array( $this, 'handle_remove' ) );
	}

	public function render(): void {
		$counts  = ( new DataRemover() )->counts();
		$removed = $this->removed_summary();
		?>
		<div class="clift-a-card">
			<div class="clift-a-card__head">
				<span class="clift-a-card__ic"><?php echo Icons::markup( 'trash' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG. ?></span>
				<div>
					<h2><?php esc_html_e( 'Remove retained data', 'contactlift-pro' ); ?></h2>
					<p><?php esc_html_e( 'Deactivating or deleting ContactLift Pro keeps your callback requests and statistics unless you opt in to deletion on uninstall. Use this to remove them now, while the plugin is active.', 'contactlift-pro' ); ?></p>
				</div>
			</div>
			<div class="clift-a-card__body">
				<?php if ( null !== $removed ) : ?>
					<div class="clift-a-notice <?php echo esc_attr( $removed['class'] ); ?>">
						<?php echo Icons::markup( $removed['icon'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG. ?>
						<p><?php echo esc_html( $removed['message'] ); ?></p>
					</div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="clift_pro_remove_data" />
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>

					<p>
						<label>
							<input type="checkbox" name="remove_callbacks" value="1" />
							<?php
							printf(
								/* translators: %d: number of stored callback requests. */
								esc_html__( 'Callback requests (%d)', 'contactlift-pro' ),
								(int) $counts['callbacks']
							);
							?>
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" name="remove_stats" value="1" />
							<?php
							printf(
								/* translators: %d: number of stored statistics rows. */
								esc_html__( 'Click statistics (%d rows)', 'contactlift-pro' ),
								(int) $counts['stats']
							);
							?>
						</label>
					</p>
					<p>
						<label>
							<input type="checkbox" name="confirm" value="1" required />
							<?php esc_html_e( 'I understand this permanently deletes the selected data and can\'t be undone.', 'contactlift-pro' ); ?>
						</label>
					</p>

					<?php submit_button( __( 'Delete selected data', 'contactlift-pro' ), 'delete', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
	}

	public function handle_remove(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'contactlift-pro' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );

		$confirmed = ! empty( $_POST['confirm'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer().
		$callbacks = ! empty( $_POST['remove_callbacks'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$stats     = ! empty( $_POST['remove_stats'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $confirmed && ( $callbacks || $stats ) ) {
			$result = ( new DataRemover() )->remove_records( $callbacks, $stats );
			$args   = array(
				'clift_pro_removed'           => '1',
				'clift_pro_removed_callbacks' => $result['callbacks'],
				'clift_pro_removed_stats'     => $result['stats'],
			);
		} else {
			$args = array( 'clift_pro_removed' => '0' );
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=contactlift-tools' ) ) );
		exit;
	}

	/**
	 * @return array{class:string,icon:string,message:string}|null
	 */
	private function removed_summary(): ?array {
		if ( ! isset( $_GET['clift_pro_removed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only result flags, no state change.
			return null;
		}

		if ( '1' !== sanitize_key( wp_unslash( $_GET['clift_pro_removed'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return array(
				'class'   => 'clift-a-notice--error',
				'icon'    => 'alert',
				'message' => __( 'Nothing was deleted. Choose what to remove and tick the confirmation box.', 'contactlift-pro' ),
			);
		}

		$callbacks = absint( wp_unslash( $_GET['clift_pro_removed_callbacks'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$stats     = absint( wp_unslash( $_GET['clift_pro_removed_stats'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return array(
			'class'   => 'clift-a-notice--success',
			'icon'    => 'check-circle',
			'message' => sprintf(
				/* translators: 1: number of callback requests deleted, 2: number of statistics rows deleted. */
				__( 'Deleted %1$d callback request(s) and %2$d statistics row(s).', 'contactlift-pro' ),
				$callbacks,
				$stats
			),
		);
	}
}
