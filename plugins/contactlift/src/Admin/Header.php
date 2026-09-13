<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Branded topbar + tab nav shared by every ContactLift admin screen. Call
 * as the first thing inside a screen's `.wrap.clift-admin`, and close the
 * `.clift-a-app`/`.clift-a-shell` wrapper this opens with render_footer().
 */
final class Header {

	/**
	 * @param string $active One of bars|settings|tools.
	 * @param bool   $is_live Whether a bar is actually enabled and has a usable action.
	 */
	public static function render( string $active, bool $is_live ): void {
		$tabs = array(
			'bars'     => array( __( 'Bars', 'contactlift' ), 'admin.php?page=contactlift', 'bars' ),
			'settings' => array( __( 'Settings', 'contactlift' ), 'admin.php?page=contactlift-settings', 'settings' ),
			'tools'    => array( __( 'Tools', 'contactlift' ), 'admin.php?page=contactlift-tools', 'tools' ),
		);

		/**
		 * Extension point for Pro-only tabs (Callback requests, Reports, Account).
		 *
		 * @param array<string,array{0:string,1:string,2:string}> $tabs   Tab key => [label, path, icon].
		 * @param string                                          $active The active tab key.
		 */
		$tabs = apply_filters( 'clift_admin_tabs', $tabs, $active );
		?>
		<div class="clift-a-app">
			<div class="clift-a-shell">
				<header class="clift-a-topbar">
					<div class="clift-a-brand">
						<span class="clift-a-brand__mark" aria-hidden="true">
							<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.362 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.338 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
						</span>
						<span class="clift-a-brand__title"><?php esc_html_e( 'ContactLift', 'contactlift' ); ?></span>
					</div>
					<div class="clift-a-topbar__meta">
						<span class="clift-a-status <?php echo $is_live ? 'clift-a-status--on' : ''; ?>">
							<?php echo Icons::markup( $is_live ? 'check-circle' : 'alert', 'clift-a-i' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::markup() escapes its own inputs and returns hardcoded SVG. ?>
							<?php echo $is_live ? esc_html__( 'Bar is live', 'contactlift' ) : esc_html__( 'Not live yet', 'contactlift' ); ?>
						</span>
						<span class="clift-a-ver">v<?php echo esc_html( CONTACTLIFT_VERSION ); ?></span>
					</div>
				</header>

				<nav class="clift-a-tabs">
					<?php foreach ( $tabs as $key => $tab ) : ?>
						<a class="<?php echo $key === $active ? 'is-active' : ''; ?>" href="<?php echo esc_url( admin_url( $tab[1] ) ); ?>">
							<?php echo Icons::markup( $tab[2], 'clift-a-i' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Icons::markup() escapes its own inputs and returns hardcoded SVG. ?>
							<?php echo esc_html( $tab[0] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>
		<?php
	}

	public static function render_footer(): void {
		?>
			</div>
		</div>
		<?php
	}
}
