<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Admin;

use ContactLift\Admin\Fields;
use ContactLift\Admin\Icons;
use ContactLift\Domain\BarState;
use ContactLift\Domain\Validation\EditionLimits;
use ContactLift\Storage\SettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Everything Pro adds to free's own Bars list and bar editor screens: extra
 * bar rows, the include-targeting and business-hours cards, merging their
 * submitted fields in, and deleting a targeted bar. Free's screens carry no
 * knowledge of any of this — see the clift_admin_bars_list_extra,
 * clift_bar_editor_fields and clift_bar_editor_submission hooks they expose.
 */
final class BarEditorExtension {

	private const MAX_NEW_EXCEPTION_ROWS = 5;

	public function register(): void {
		add_action( 'clift_admin_bars_list_extra', array( $this, 'render_bars_list_extra' ) );
		add_action( 'clift_bar_editor_fields', array( $this, 'render_fields' ), 10, 4 );
		add_filter( 'clift_bar_editor_submission', array( $this, 'merge_submission' ), 10, 2 );
		add_action( 'admin_post_clift_pro_delete_bar', array( $this, 'handle_delete_bar' ) );
	}

	public function render_bars_list_extra(): void {
		$settings       = \ContactLift\Plugin::instance()->settings();
		$document       = $settings->get_bars_document();
		$default_bar_id = $document['default_bar_id'] ?? SettingsRepository::DEFAULT_BAR_ID;

		foreach ( $document['bars'] ?? array() as $bar ) {
			if ( ( $bar['id'] ?? '' ) === $default_bar_id ) {
				continue;
			}

			$this->render_bar_row( $bar );
		}
		?>
		<div class="clift-a-barrow" style="border-style:dashed;justify-content:center;">
			<a class="clift-a-btn clift-a-btn--primary" href="<?php echo esc_url( admin_url( 'admin.php?page=contactlift-bar-editor&bar_id=new' ) ); ?>">
				<?php echo Icons::markup( 'plus' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- hardcoded SVG. ?>
				<?php esc_html_e( 'Add targeted bar', 'contactlift-pro' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $bar
	 */
	private function render_bar_row( array $bar ): void {
		$is_live = ! empty( $bar['enabled'] ) && BarState::has_renderable_action( $bar );
		$id      = (string) ( $bar['id'] ?? '' );
		?>
		<div class="clift-a-barrow">
			<span class="clift-a-barrow__dot <?php echo $is_live ? 'is-live' : ''; ?>"></span>
			<div class="clift-a-barrow__body">
				<div class="clift-a-barrow__title"><?php echo esc_html( $bar['name'] ?? '' ); ?></div>
				<div class="clift-a-barrow__meta">
					<?php
					printf(
						/* translators: %d: bar priority, higher wins ties. */
						esc_html__( 'Priority %d', 'contactlift-pro' ),
						(int) ( $bar['priority'] ?? 0 )
					);
					?>
				</div>
			</div>
			<a class="clift-a-btn clift-a-btn--ghost" href="<?php echo esc_url( admin_url( 'admin.php?page=contactlift-bar-editor&bar_id=' . rawurlencode( $id ) ) ); ?>">
				<?php esc_html_e( 'Edit', 'contactlift-pro' ); ?>
			</a>
			<a class="clift-a-btn clift-a-btn--ghost"
				href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=clift_pro_delete_bar&bar_id=' . rawurlencode( $id ) ), 'clift_pro_delete_bar_' . $id ) ); ?>"
				onclick="return confirm('<?php echo esc_js( __( 'Delete this bar? This cannot be undone.', 'contactlift-pro' ) ); ?>');">
				<?php esc_html_e( 'Delete', 'contactlift-pro' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed>  $bar
	 * @param array<string,string> $errors
	 */
	public function render_fields( array $bar, EditionLimits $limits, bool $is_default, array $errors ): void {
		if ( $limits->allow_include && ! $is_default ) {
			$this->render_include_card( $bar, $errors );
		}

		if ( $limits->allow_schedule ) {
			$this->render_schedule_card( $bar, $errors );
		}
	}

	/**
	 * @param array<string,mixed>  $bar
	 * @param array<string,string> $errors
	 */
	private function render_include_card( array $bar, array $errors ): void {
		$included = $bar['include']['content_ids'] ?? array();
		?>
		<div class="clift-a-card">
			<div class="clift-a-card__head">
				<span class="clift-a-card__ic"><?php echo Icons::markup( 'eye' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div>
					<h2><?php esc_html_e( 'Show only on', 'contactlift-pro' ); ?></h2>
					<p><?php esc_html_e( 'Leave empty to show on every page (subject to the exclusions above). Otherwise this bar shows only on the pages picked here.', 'contactlift-pro' ); ?></p>
				</div>
			</div>
			<div class="clift-a-card__body">
				<div class="clift-a-field <?php echo isset( $errors['include.content_ids'] ) ? 'has-error' : ''; ?>">
					<?php
					Fields::picker(
						'include_content_ids[]',
						$this->excludable_content(),
						$included,
						__( 'Search pages, posts…', 'contactlift-pro' ),
						__( 'Nothing to pick from yet — publish a page or post first.', 'contactlift-pro' )
					);
					?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed>  $bar
	 * @param array<string,string> $errors
	 */
	private function render_schedule_card( array $bar, array $errors ): void {
		$schedule   = $bar['schedule'] ?? array();
		$weekly     = $schedule['weekly'] ?? array();
		$exceptions = array_values( $schedule['exceptions'] ?? array() );
		$days       = array(
			1 => __( 'Monday', 'contactlift-pro' ),
			2 => __( 'Tuesday', 'contactlift-pro' ),
			3 => __( 'Wednesday', 'contactlift-pro' ),
			4 => __( 'Thursday', 'contactlift-pro' ),
			5 => __( 'Friday', 'contactlift-pro' ),
			6 => __( 'Saturday', 'contactlift-pro' ),
			7 => __( 'Sunday', 'contactlift-pro' ),
		);
		?>
		<div class="clift-a-card">
			<div class="clift-a-card__head">
				<span class="clift-a-card__ic"><?php echo Icons::markup( 'settings' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<div>
					<h2><?php esc_html_e( 'Business hours', 'contactlift-pro' ); ?></h2>
					<p><?php esc_html_e( 'Up to two time ranges per day. A range ending earlier than it starts carries into the next day. Each action above chooses what happens to it while closed.', 'contactlift-pro' ); ?></p>
				</div>
			</div>
			<div class="clift-a-card__body">
				<div class="clift-a-field">
					<?php Fields::toggle( 'schedule[enabled]', __( 'Use a schedule for this bar', 'contactlift-pro' ), ! empty( $schedule['enabled'] ) ); ?>
				</div>

				<table class="clift-a-schedule">
					<tbody>
					<?php foreach ( $days as $weekday => $label ) : ?>
						<?php $intervals = $weekly[ $weekday ] ?? array(); ?>
						<tr>
							<th scope="row"><?php echo esc_html( $label ); ?></th>
							<td>
								<?php for ( $slot = 0; $slot < 2; $slot++ ) : ?>
									<?php $interval = $intervals[ $slot ] ?? array(); ?>
									<span class="clift-a-schedule__slot">
										<input type="time" name="schedule[weekly][<?php echo (int) $weekday; ?>][<?php echo (int) $slot; ?>][start]" value="<?php echo esc_attr( $interval['start'] ?? '' ); ?>" />
										<span aria-hidden="true">&ndash;</span>
										<input type="time" name="schedule[weekly][<?php echo (int) $weekday; ?>][<?php echo (int) $slot; ?>][end]" value="<?php echo esc_attr( $interval['end'] ?? '' ); ?>" />
										<label>
											<input type="checkbox" name="schedule[weekly][<?php echo (int) $weekday; ?>][<?php echo (int) $slot; ?>][all_day]" value="1" <?php checked( ! empty( $interval['all_day'] ) ); ?> />
											<?php esc_html_e( 'All day', 'contactlift-pro' ); ?>
										</label>
									</span>
								<?php endfor; ?>
								<?php if ( isset( $errors[ "schedule.weekly.{$weekday}" ] ) ) : ?>
									<p class="clift-a-field__error"><?php echo esc_html( $errors[ "schedule.weekly.{$weekday}" ] ); ?></p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<h3><?php esc_html_e( 'Date exceptions', 'contactlift-pro' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Overrides the whole day above, including any overnight carry-over from the day before. Leave the date blank to skip a row; save and come back to add more.', 'contactlift-pro' ); ?></p>
				<?php
				$rows = $exceptions;
				for ( $i = 0; $i < self::MAX_NEW_EXCEPTION_ROWS; $i++ ) {
					$rows[] = array();
				}
				foreach ( $rows as $index => $exception ) :
					$interval = $exception['intervals'][0] ?? array();
					?>
					<div class="clift-a-schedule__exception">
						<input type="date" name="schedule[exceptions][<?php echo (int) $index; ?>][date]" value="<?php echo esc_attr( $exception['date'] ?? '' ); ?>" />
						<label>
							<input type="checkbox" name="schedule[exceptions][<?php echo (int) $index; ?>][closed]" value="1" <?php checked( ! empty( $exception['closed'] ) ); ?> />
							<?php esc_html_e( 'Closed all day', 'contactlift-pro' ); ?>
						</label>
						<input type="time" name="schedule[exceptions][<?php echo (int) $index; ?>][intervals][0][start]" value="<?php echo esc_attr( $interval['start'] ?? '' ); ?>" />
						<span aria-hidden="true">&ndash;</span>
						<input type="time" name="schedule[exceptions][<?php echo (int) $index; ?>][intervals][0][end]" value="<?php echo esc_attr( $interval['end'] ?? '' ); ?>" />
					</div>
				<?php endforeach; ?>
				<?php if ( isset( $errors['schedule.exceptions'] ) ) : ?>
					<p class="clift-a-field__error"><?php echo esc_html( $errors['schedule.exceptions'] ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	public function merge_submission( array $raw, EditionLimits $limits ): array {
		if ( $limits->allow_include ) {
			$raw['include'] = array(
				'content_ids'     => array_map( 'intval', (array) ( $_POST['include_content_ids'] ?? array() ) ), // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by BarEditorPage::handle_save() before this filter runs.
				'product_cat_ids' => array(),
			);
		}

		if ( $limits->allow_schedule ) {
			$raw['schedule'] = $this->collect_schedule();
		}

		return $raw;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function collect_schedule(): array {
		$raw = (array) ( $_POST['schedule'] ?? array() ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified by BarEditorPage::handle_save() before this filter runs; every leaf value is individually wp_unslash()'d and sanitized in collect_intervals() below, this outer cast just reaches into the nested structure.

		$weekly = array();
		for ( $weekday = 1; $weekday <= 7; $weekday++ ) {
			$weekly[ $weekday ] = $this->collect_intervals( (array) ( $raw['weekly'][ $weekday ] ?? array() ) );
		}

		$exceptions = array();
		foreach ( (array) ( $raw['exceptions'] ?? array() ) as $raw_exception ) {
			if ( ! is_array( $raw_exception ) ) {
				continue;
			}

			$date = sanitize_text_field( wp_unslash( $raw_exception['date'] ?? '' ) );

			if ( '' === $date ) {
				continue;
			}

			$exceptions[] = array(
				'date'      => $date,
				'closed'    => ! empty( $raw_exception['closed'] ),
				'intervals' => $this->collect_intervals( (array) ( $raw_exception['intervals'] ?? array() ) ),
			);
		}

		return array(
			'enabled'    => ! empty( $raw['enabled'] ),
			'weekly'     => $weekly,
			'exceptions' => $exceptions,
		);
	}

	/**
	 * @param array<int,mixed> $raw_slots
	 * @return array<int,array<string,mixed>>
	 */
	private function collect_intervals( array $raw_slots ): array {
		$intervals = array();

		foreach ( $raw_slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}

			$start = sanitize_text_field( wp_unslash( $slot['start'] ?? '' ) );
			$end   = sanitize_text_field( wp_unslash( $slot['end'] ?? '' ) );

			if ( '' === $start && '' === $end && empty( $slot['all_day'] ) ) {
				continue;
			}

			$intervals[] = array(
				'all_day' => ! empty( $slot['all_day'] ),
				'start'   => $start,
				'end'     => $end,
			);
		}

		return $intervals;
	}

	public function handle_delete_bar(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'contactlift-pro' ) );
		}

		$bar_id = isset( $_GET['bar_id'] ) ? sanitize_text_field( wp_unslash( $_GET['bar_id'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified immediately below via check_admin_referer().

		check_admin_referer( 'clift_pro_delete_bar_' . $bar_id );

		$settings       = \ContactLift\Plugin::instance()->settings();
		$document       = $settings->get_bars_document();
		$default_bar_id = $document['default_bar_id'] ?? SettingsRepository::DEFAULT_BAR_ID;
		$bars           = $document['bars'] ?? array();

		if ( '' !== $bar_id && $bar_id !== $default_bar_id ) {
			$remaining = array_values(
				array_filter( $bars, static fn( array $bar ): bool => ( $bar['id'] ?? '' ) !== $bar_id )
			);

			if ( count( $remaining ) !== count( $bars ) ) {
				$settings->save_bars( $remaining, EditionLimits::pro(), $default_bar_id );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=contactlift' ) );
		exit;
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
				'numberposts'   => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_numberposts -- admin-only targeting picker, not a frontend query; 200 matches the schema's own cap on include/exclude ID list length.
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
}
