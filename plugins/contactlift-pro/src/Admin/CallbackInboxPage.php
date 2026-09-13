<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Admin;

use ContactLift\Admin\Header;
use ContactLift\Admin\Icons;
use ContactLift\Admin\LiveStatus;
use ContactLiftPro\Storage\CallbackRepository;
use ContactLiftPro\Storage\Mailer;
use ContactLiftPro\Storage\ProSettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * "Show New, Contacted and Closed statuses, private notes, created time,
 * source and a telephone link... Display failures in the inbox and allow
 * an authenticated manual retry" (spec, Inbox and notifications). Lives
 * under the same top-level ContactLift menu as free's own screens, added
 * here since free has no submenu of this shape to extend.
 */
final class CallbackInboxPage {

	private const SLUG         = 'contactlift-pro-callbacks';
	private const NONCE_ACTION = 'clift_pro_callback_action';
	private const NONCE_FIELD  = 'clift_pro_callback_nonce';
	private const PER_PAGE     = 20;

	private CallbackRepository $repository;

	public function __construct( ?CallbackRepository $repository = null ) {
		$this->repository = $repository ?? new CallbackRepository();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'clift_admin_tabs', array( $this, 'add_tab' ), 10, 2 );
		add_action( 'admin_post_clift_pro_callback_status', array( $this, 'handle_status_change' ) );
		add_action( 'admin_post_clift_pro_callback_notes', array( $this, 'handle_notes_save' ) );
		add_action( 'admin_post_clift_pro_callback_retry', array( $this, 'handle_retry' ) );
		add_action( 'admin_post_clift_pro_callback_delete', array( $this, 'handle_delete' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'contactlift',
			__( 'Callback requests', 'contactlift-pro' ),
			__( 'Callback requests', 'contactlift-pro' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @param array<string,array{0:string,1:string,2:string}> $tabs
	 */
	public function add_tab( array $tabs, string $active ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $active is part of the clift_admin_tabs filter's fixed signature; this listener doesn't need it, only adds an entry.
		$tabs['callbacks'] = array( __( 'Callback requests', 'contactlift-pro' ), 'admin.php?page=' . self::SLUG, 'plug' );

		return $tabs;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'contactlift-pro' ) );
		}

		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing, not a state change.

		echo '<div class="wrap clift-admin">';
		Header::render( 'callbacks', LiveStatus::is_live( \ContactLift\Plugin::instance()->settings() ) );

		if ( $id > 0 ) {
			$this->render_detail( $id );
		} else {
			$this->render_list();
		}

		Header::render_footer();
		echo '</div>';
	}

	private function render_list(): void {
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only filter, not a state change.
		$status = in_array( $status, array( 'new', 'contacted', 'closed' ), true ) ? $status : null;
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$phone  = isset( $_GET['phone'] ) ? sanitize_text_field( wp_unslash( $_GET['phone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search, not a state change.

		$rows = '' !== $phone ? $this->repository->search_by_phone( $phone ) : $this->repository->list( $status, $page, self::PER_PAGE );
		?>
		<div class="clift-a-pagehead">
			<div>
				<h1><?php esc_html_e( 'Callback requests', 'contactlift-pro' ); ?></h1>
				<p><?php esc_html_e( 'Requests visitors have asked to be contacted about.', 'contactlift-pro' ); ?></p>
			</div>
		</div>

		<div class="clift-a-card">
			<div class="clift-a-card__body">
				<form method="get" style="margin-bottom:12px;">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
					<label class="clift-a-label" for="clift_pro_phone_search"><?php esc_html_e( 'Search by phone number', 'contactlift-pro' ); ?></label>
					<input type="search" id="clift_pro_phone_search" name="phone" value="<?php echo esc_attr( $phone ); ?>"
						placeholder="<?php esc_attr_e( 'Verify the requester\'s identity before deleting a match.', 'contactlift-pro' ); ?>" style="max-width:320px;" />
					<?php submit_button( __( 'Search', 'contactlift-pro' ), 'secondary', 'submit', false ); ?>
					<?php if ( '' !== $phone ) : ?>
						<a class="clift-a-btn clift-a-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>"><?php esc_html_e( 'Clear', 'contactlift-pro' ); ?></a>
					<?php endif; ?>
				</form>

				<p>
					<?php
					foreach ( array(
						''          => __( 'All', 'contactlift-pro' ),
						'new'       => __( 'New', 'contactlift-pro' ),
						'contacted' => __( 'Contacted', 'contactlift-pro' ),
						'closed'    => __( 'Closed', 'contactlift-pro' ),
					) as $key => $label ) :
						?>
						<a class="clift-a-btn clift-a-btn--ghost" href="<?php echo esc_url( add_query_arg( 'status', $key, admin_url( 'admin.php?page=' . self::SLUG ) ) ); ?>">
							<?php echo esc_html( $label ); ?>
						</a>
					<?php endforeach; ?>
				</p>

				<?php if ( ! $rows ) : ?>
					<div class="clift-a-empty">
						<?php echo Icons::markup( 'plug' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<p><?php esc_html_e( 'No callback requests yet.', 'contactlift-pro' ); ?></p>
					</div>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Name', 'contactlift-pro' ); ?></th>
								<th><?php esc_html_e( 'Phone', 'contactlift-pro' ); ?></th>
								<th><?php esc_html_e( 'Status', 'contactlift-pro' ); ?></th>
								<th><?php esc_html_e( 'Notification', 'contactlift-pro' ); ?></th>
								<th><?php esc_html_e( 'Received', 'contactlift-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><a href="<?php echo esc_url( add_query_arg( 'id', (int) $row['id'], admin_url( 'admin.php?page=' . self::SLUG ) ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
									<td><a href="<?php echo esc_url( 'tel:' . rawurlencode( $row['phone'] ) ); ?>"><?php echo esc_html( $row['phone'] ); ?></a></td>
									<td><?php echo esc_html( ucfirst( $row['status'] ) ); ?></td>
									<td><?php echo esc_html( $this->notification_label( $row['notification_status'] ) ); ?></td>
									<td><?php echo esc_html( $this->relative_or_date( $row['created_at_utc'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	private function render_detail( int $id ): void {
		$row = $this->repository->get( $id );

		if ( null === $row ) {
			echo '<div class="clift-a-pagehead"><h1>' . esc_html__( 'Request not found', 'contactlift-pro' ) . '</h1></div>';

			return;
		}
		?>
		<div class="clift-a-pagehead">
			<div>
				<h1><?php echo esc_html( $row['name'] ); ?></h1>
				<p><?php echo esc_html( $this->relative_or_date( $row['created_at_utc'] ) ); ?> &middot; <?php echo esc_html( $this->source_label( $row ) ); ?></p>
			</div>
			<a class="clift-a-btn clift-a-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ); ?>">
				<?php esc_html_e( 'Back to requests', 'contactlift-pro' ); ?>
			</a>
		</div>

		<div class="clift-a-card">
			<div class="clift-a-card__body">
				<p><strong><?php esc_html_e( 'Phone:', 'contactlift-pro' ); ?></strong> <a href="<?php echo esc_url( 'tel:' . rawurlencode( $row['phone'] ) ); ?>"><?php echo esc_html( $row['phone'] ); ?></a></p>
				<?php if ( ! empty( $row['email'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Email:', 'contactlift-pro' ); ?></strong> <?php echo esc_html( $row['email'] ); ?></p>
				<?php endif; ?>
				<?php if ( '' !== trim( (string) $row['message'] ) ) : ?>
					<p><strong><?php esc_html_e( 'Message:', 'contactlift-pro' ); ?></strong><br /><?php echo nl2br( esc_html( $row['message'] ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- nl2br() wraps the already-esc_html()'d string; no unescaped input reaches output. ?></p>
				<?php endif; ?>
				<p><strong><?php esc_html_e( 'Notification:', 'contactlift-pro' ); ?></strong> <?php echo esc_html( $this->notification_label( $row['notification_status'] ) ); ?></p>

				<?php if ( CallbackRepository::NOTIF_FAILED === $row['notification_status'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
						<input type="hidden" name="action" value="clift_pro_callback_retry" />
						<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>" />
						<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
						<?php submit_button( __( 'Retry notification', 'contactlift-pro' ), 'secondary', 'submit', false ); ?>
					</form>
				<?php endif; ?>
			</div>
		</div>

		<div class="clift-a-card">
			<div class="clift-a-card__body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="clift_pro_callback_status" />
					<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>" />
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<div class="clift-a-field">
						<label class="clift-a-label" for="clift_pro_status"><?php esc_html_e( 'Status', 'contactlift-pro' ); ?></label>
						<select id="clift_pro_status" name="status">
							<?php
							foreach ( array(
								'new'       => __( 'New', 'contactlift-pro' ),
								'contacted' => __( 'Contacted', 'contactlift-pro' ),
								'closed'    => __( 'Closed', 'contactlift-pro' ),
							) as $value => $label ) :
								?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $row['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<?php submit_button( __( 'Update status', 'contactlift-pro' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>

		<div class="clift-a-card">
			<div class="clift-a-card__body">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="clift_pro_callback_notes" />
					<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>" />
					<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
					<div class="clift-a-field">
						<label class="clift-a-label" for="clift_pro_notes"><?php esc_html_e( 'Private notes', 'contactlift-pro' ); ?></label>
						<textarea id="clift_pro_notes" name="notes" rows="4"><?php echo esc_textarea( $row['private_notes'] ); ?></textarea>
					</div>
					<?php submit_button( __( 'Save notes', 'contactlift-pro' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
			onsubmit="return confirm('<?php echo esc_js( __( 'Delete this request? This cannot be undone.', 'contactlift-pro' ) ); ?>');">
			<input type="hidden" name="action" value="clift_pro_callback_delete" />
			<input type="hidden" name="id" value="<?php echo (int) $row['id']; ?>" />
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD ); ?>
			<?php submit_button( __( 'Delete request', 'contactlift-pro' ), 'delete', 'submit', false ); ?>
		</form>
		<?php
	}

	public function handle_status_change(): void {
		$this->require_capability_and_nonce();

		$id     = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in require_capability_and_nonce().
		$status = sanitize_key( wp_unslash( $_POST['status'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $id > 0 && in_array( $status, array( 'new', 'contacted', 'closed' ), true ) ) {
			$this->repository->update_status( $id, $status );
		}

		$this->redirect_to_detail( $id );
	}

	public function handle_notes_save(): void {
		$this->require_capability_and_nonce();

		$id    = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in require_capability_and_nonce().
		$notes = sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		if ( $id > 0 ) {
			$this->repository->update_notes( $id, $notes );
		}

		$this->redirect_to_detail( $id );
	}

	public function handle_retry(): void {
		$this->require_capability_and_nonce();

		$id  = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in require_capability_and_nonce().
		$row = $id > 0 ? $this->repository->get( $id ) : null;

		if ( null !== $row && $this->repository->claim_for_notification( $id ) ) {
			$settings = ( new ProSettingsRepository() )->get_settings();
			$sent     = ( new Mailer() )->send_callback_notification( $settings['callback_recipients'] ?? array(), $row );
			$this->repository->mark_notification_result( $id, $sent );
		}

		$this->redirect_to_detail( $id );
	}

	public function handle_delete(): void {
		$this->require_capability_and_nonce();

		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in require_capability_and_nonce().

		if ( $id > 0 ) {
			$this->repository->delete( $id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	private function require_capability_and_nonce(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'contactlift-pro' ) );
		}

		check_admin_referer( self::NONCE_ACTION, self::NONCE_FIELD );
	}

	private function redirect_to_detail( int $id ): void {
		wp_safe_redirect( add_query_arg( 'id', $id, admin_url( 'admin.php?page=' . self::SLUG ) ) );
		exit;
	}

	private function notification_label( string $status ): string {
		$labels = array(
			CallbackRepository::NOTIF_PENDING => __( 'Pending', 'contactlift-pro' ),
			CallbackRepository::NOTIF_HANDED  => __( 'Sent to mail server', 'contactlift-pro' ),
			CallbackRepository::NOTIF_FAILED  => __( 'Failed', 'contactlift-pro' ),
		);

		return $labels[ $status ] ?? $status;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function source_label( array $row ): string {
		$type = (string) ( $row['source_type'] ?? '' );
		$id   = $row['source_id'] ?? null;

		if ( null === $id ) {
			return ucfirst( $type );
		}

		$title = get_the_title( (int) $id );

		return '' !== $title ? $title : ucfirst( $type ) . ' #' . (int) $id;
	}

	private function relative_or_date( string $utc_datetime ): string {
		$timestamp = strtotime( $utc_datetime . ' UTC' );

		if ( false === $timestamp ) {
			return $utc_datetime;
		}

		return date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp + ( (int) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) );
	}
}
