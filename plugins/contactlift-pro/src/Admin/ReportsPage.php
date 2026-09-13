<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Admin;

use ContactLift\Admin\Header;
use ContactLift\Admin\Icons;
use ContactLift\Admin\LiveStatus;
use ContactLiftPro\Storage\DailyStatsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * "Provide totals by date, bar, action/channel, public source object and
 * device bucket... Show a simple daily chart and the matching table. CSV
 * exports must reflect the visible filters." (spec, Reporting scope). A
 * plain CSS bar chart, not a charting library — keeps the performance
 * budget and avoids an external script dependency for something this small.
 */
final class ReportsPage {

	private const SLUG = 'contactlift-pro-reports';

	private DailyStatsRepository $stats;

	public function __construct( ?DailyStatsRepository $stats = null ) {
		$this->stats = $stats ?? new DailyStatsRepository();
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'clift_admin_tabs', array( $this, 'add_tab' ) );
		add_action( 'admin_post_clift_pro_reports_csv', array( $this, 'handle_csv_export' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'contactlift',
			__( 'Reports', 'contactlift-pro' ),
			__( 'Reports', 'contactlift-pro' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * @param array<string,array{0:string,1:string,2:string}> $tabs
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function add_tab( array $tabs ): array {
		$tabs['reports'] = array( __( 'Reports', 'contactlift-pro' ), 'admin.php?page=' . self::SLUG, 'monitor' );

		return $tabs;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'contactlift-pro' ) );
		}

		$filters = $this->filters_from_request();
		$rows    = $this->stats->query( $filters );

		echo '<div class="wrap clift-admin">';
		Header::render( 'reports', LiveStatus::is_live( \ContactLift\Plugin::instance()->settings() ) );
		?>
		<div class="clift-a-pagehead">
			<div>
				<h1><?php esc_html_e( 'Reports', 'contactlift-pro' ); ?></h1>
				<p><?php esc_html_e( 'Local, approximate counts of contact-action clicks. Not proof a call connected, a message was sent, or revenue was earned.', 'contactlift-pro' ); ?></p>
			</div>
		</div>

		<div class="clift-a-card">
			<div class="clift-a-card__body">
				<form method="get">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::SLUG ); ?>" />
					<div class="clift-a-colorgrid">
						<div class="clift-a-field">
							<label class="clift-a-label" for="clift_pro_range"><?php esc_html_e( 'Range', 'contactlift-pro' ); ?></label>
							<select id="clift_pro_range" name="range" onchange="this.form.submit()">
								<?php
								foreach ( array(
									'7'      => __( 'Last 7 days', 'contactlift-pro' ),
									'30'     => __( 'Last 30 days', 'contactlift-pro' ),
									'custom' => __( 'Custom (up to 90 days)', 'contactlift-pro' ),
								) as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['range'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<?php if ( 'custom' === $filters['range'] ) : ?>
							<div class="clift-a-field">
								<label class="clift-a-label" for="clift_pro_from"><?php esc_html_e( 'From', 'contactlift-pro' ); ?></label>
								<input type="date" id="clift_pro_from" name="from" value="<?php echo esc_attr( $filters['from'] ); ?>" />
							</div>
							<div class="clift-a-field">
								<label class="clift-a-label" for="clift_pro_to"><?php esc_html_e( 'To', 'contactlift-pro' ); ?></label>
								<input type="date" id="clift_pro_to" name="to" value="<?php echo esc_attr( $filters['to'] ); ?>" />
							</div>
						<?php endif; ?>
						<div class="clift-a-field">
							<label class="clift-a-label" for="clift_pro_bar"><?php esc_html_e( 'Bar', 'contactlift-pro' ); ?></label>
							<select id="clift_pro_bar" name="bar_id" onchange="this.form.submit()">
								<option value=""><?php esc_html_e( 'All bars', 'contactlift-pro' ); ?></option>
								<?php foreach ( \ContactLift\Plugin::instance()->settings()->get_bars() as $bar ) : ?>
									<option value="<?php echo esc_attr( $bar['id'] ); ?>" <?php selected( $filters['bar_id'], $bar['id'] ); ?>><?php echo esc_html( $bar['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="clift-a-field">
							<label class="clift-a-label" for="clift_pro_channel"><?php esc_html_e( 'Channel', 'contactlift-pro' ); ?></label>
							<select id="clift_pro_channel" name="channel" onchange="this.form.submit()">
								<option value=""><?php esc_html_e( 'All channels', 'contactlift-pro' ); ?></option>
								<?php foreach ( array( 'call', 'whatsapp', 'email', 'link', 'callback' ) as $channel ) : ?>
									<option value="<?php echo esc_attr( $channel ); ?>" <?php selected( $filters['channel'], $channel ); ?>><?php echo esc_html( ucfirst( $channel ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</div>
						<div class="clift-a-field">
							<label class="clift-a-label" for="clift_pro_device"><?php esc_html_e( 'Device', 'contactlift-pro' ); ?></label>
							<select id="clift_pro_device" name="device" onchange="this.form.submit()">
								<option value=""><?php esc_html_e( 'All devices', 'contactlift-pro' ); ?></option>
								<option value="mobile" <?php selected( $filters['device'], 'mobile' ); ?>><?php esc_html_e( 'Mobile', 'contactlift-pro' ); ?></option>
								<option value="desktop" <?php selected( $filters['device'], 'desktop' ); ?>><?php esc_html_e( 'Desktop', 'contactlift-pro' ); ?></option>
							</select>
						</div>
					</div>
					<noscript><?php submit_button( __( 'Apply', 'contactlift-pro' ), 'secondary', 'submit', false ); ?></noscript>
				</form>
			</div>
		</div>

		<div class="clift-a-card">
			<div class="clift-a-card__body">
				<?php $this->render_chart( $rows ); ?>
			</div>
		</div>

		<div class="clift-a-card">
			<div class="clift-a-card__body">
				<?php if ( ! $rows ) : ?>
					<div class="clift-a-empty">
						<?php echo Icons::markup( 'monitor' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<p><?php esc_html_e( 'No activity recorded for this range.', 'contactlift-pro' ); ?></p>
					</div>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'contactlift-pro' ); ?></th>
								<th><?php esc_html_e( 'Bar', 'contactlift-pro' ); ?></th>
								<th><?php esc_html_e( 'Channel', 'contactlift-pro' ); ?></th>
								<th><?php esc_html_e( 'Source', 'contactlift-pro' ); ?></th>
								<th><?php esc_html_e( 'Device', 'contactlift-pro' ); ?></th>
								<th><?php esc_html_e( 'Clicks', 'contactlift-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $rows as $row ) : ?>
								<tr>
									<td><?php echo esc_html( $row['stat_date'] ); ?></td>
									<td><?php echo esc_html( $this->bar_name( $row['bar_id'] ) ); ?></td>
									<td><?php echo esc_html( ucfirst( $row['channel'] ) ); ?></td>
									<td><?php echo esc_html( $this->source_label( $row ) ); ?></td>
									<td><?php echo esc_html( ucfirst( $row['device'] ) ); ?></td>
									<td><?php echo (int) $row['click_count']; ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px;">
					<input type="hidden" name="action" value="clift_pro_reports_csv" />
					<?php foreach ( $filters as $key => $value ) : ?>
						<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( (string) $value ); ?>" />
					<?php endforeach; ?>
					<?php wp_nonce_field( 'clift_pro_reports_csv', 'clift_pro_reports_csv_nonce' ); ?>
					<?php submit_button( __( 'Export CSV', 'contactlift-pro' ), 'secondary', 'submit', false ); ?>
				</form>
			</div>
		</div>
		<?php
		Header::render_footer();
		echo '</div>';
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function render_chart( array $rows ): void {
		$by_date = array();
		foreach ( $rows as $row ) {
			$by_date[ $row['stat_date'] ] = ( $by_date[ $row['stat_date'] ] ?? 0 ) + (int) $row['click_count'];
		}
		krsort( $by_date );
		$by_date = array_reverse( array_slice( $by_date, 0, 30, true ), true );
		$max     = $by_date ? max( $by_date ) : 0;

		if ( ! $by_date ) {
			esc_html_e( 'No activity to chart for this range.', 'contactlift-pro' );

			return;
		}
		?>
		<div style="display:flex;align-items:flex-end;gap:4px;height:120px;">
			<?php foreach ( $by_date as $date => $count ) : ?>
				<?php $height = $max > 0 ? max( 2, (int) round( ( $count / $max ) * 100 ) ) : 2; ?>
				<div style="flex:1;background:var(--clift-a-primary,#2563eb);height:<?php echo (int) $height; ?>%;border-radius:2px 2px 0 0;" title="<?php echo esc_attr( $date . ': ' . $count ); ?>"></div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	public function handle_csv_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'contactlift-pro' ) );
		}

		check_admin_referer( 'clift_pro_reports_csv', 'clift_pro_reports_csv_nonce' );

		$filters = $this->filters_from_array( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above via check_admin_referer(); every field is sanitized in filters_from_array().
		$rows    = $this->stats->query( $filters );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="contactlift-report-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_fopen -- streaming a CSV response body, not reading a plugin file.
		fputcsv( $out, array( 'date', 'bar', 'channel', 'source', 'device', 'clicks' ) );

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$this->csv_safe( $row['stat_date'] ),
					$this->csv_safe( $this->bar_name( $row['bar_id'] ) ),
					$this->csv_safe( ucfirst( $row['channel'] ) ),
					$this->csv_safe( $this->source_label( $row ) ),
					$this->csv_safe( ucfirst( $row['device'] ) ),
					(int) $row['click_count'],
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- streaming a CSV response body via php://output, not reading/writing a plugin file; WP_Filesystem has no relevant API for this.
		exit;
	}

	/**
	 * "For CSV, quote correctly and neutralise spreadsheet formulas for
	 * values beginning with =, +, -, @, tab or carriage return. Keep
	 * readable originals in the database." (spec) — fputcsv() already
	 * quotes correctly; only the formula-injection prefix needs handling
	 * here, and only in the exported file, never in the stored value.
	 */
	private function csv_safe( string $value ): string {
		if ( '' !== $value && 1 === preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * @return array{range:string,from:string,to:string,bar_id:string,channel:string,device:string}
	 */
	private function filters_from_request(): array {
		return $this->filters_from_array( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only report filters, not a state change.
	}

	/**
	 * @param array<string,mixed> $source
	 * @return array{range:string,from:string,to:string,bar_id:string,channel:string,device:string}
	 */
	private function filters_from_array( array $source ): array {
		$range = sanitize_key( wp_unslash( $source['range'] ?? '7' ) );
		if ( ! in_array( $range, array( '7', '30', 'custom' ), true ) ) {
			$range = '7';
		}

		$today = current_time( 'Y-m-d' );

		if ( 'custom' === $range ) {
			$from = sanitize_text_field( wp_unslash( $source['from'] ?? '' ) );
			$to   = sanitize_text_field( wp_unslash( $source['to'] ?? '' ) );

			if ( ! $this->is_valid_date( $from ) || ! $this->is_valid_date( $to ) || $from > $to ) {
				$from = gmdate( 'Y-m-d', strtotime( '-7 days', strtotime( $today ) ) );
				$to   = $today;
			}

			// Cap the custom range at 90 days, per spec, measured from $to.
			$earliest = gmdate( 'Y-m-d', strtotime( '-90 days', strtotime( $to ) ) );
			if ( $from < $earliest ) {
				$from = $earliest;
			}
		} else {
			$days = '30' === $range ? 30 : 7;
			$from = gmdate( 'Y-m-d', strtotime( "-{$days} days", strtotime( $today ) ) );
			$to   = $today;
		}

		return array(
			'range'   => $range,
			'from'    => $from,
			'to'      => $to,
			'bar_id'  => sanitize_text_field( wp_unslash( $source['bar_id'] ?? '' ) ),
			'channel' => sanitize_key( wp_unslash( $source['channel'] ?? '' ) ),
			'device'  => sanitize_key( wp_unslash( $source['device'] ?? '' ) ),
		);
	}

	private function is_valid_date( string $value ): bool {
		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) && false !== strtotime( $value );
	}

	private function bar_name( string $bar_id ): string {
		foreach ( \ContactLift\Plugin::instance()->settings()->get_bars() as $bar ) {
			if ( ( $bar['id'] ?? '' ) === $bar_id ) {
				return (string) $bar['name'];
			}
		}

		return $bar_id;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function source_label( array $row ): string {
		$source_id = $row['source_id'] ?? null;

		if ( null === $source_id ) {
			return ucfirst( (string) $row['source_type'] );
		}

		$title = get_the_title( (int) $source_id );

		return '' !== $title ? $title : ( ucfirst( (string) $row['source_type'] ) . ' #' . (int) $source_id );
	}
}
