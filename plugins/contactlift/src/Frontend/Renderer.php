<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Frontend;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the actual markup. wp_footer and [contactlift] both call this
 * through one shared instance (see Plugin::boot()) so "only one instance
 * per page" and "the first valid shortcode suppresses the floating one" —
 * both spec requirements — fall out of a single `$rendered` flag rather
 * than being reimplemented per entry point.
 */
final class Renderer {

	private BarResolver $resolver;
	private ActionLinkBuilder $links;
	private PageContext $context;
	private bool $rendered = false;

	public function __construct( BarResolver $resolver, ?ActionLinkBuilder $links = null, ?PageContext $context = null ) {
		$this->resolver = $resolver;
		$this->links    = $links ?? new ActionLinkBuilder();
		$this->context  = $context ?? new PageContext();
	}

	public function has_rendered(): bool {
		return $this->rendered;
	}

	/**
	 * [contactlift]. Static positioning, no launcher toggle — it's already
	 * deliberately placed in the page by whoever added the shortcode.
	 */
	public function render_inline(): string {
		if ( $this->rendered ) {
			return '';
		}

		$prepared = $this->prepare();

		if ( null === $prepared ) {
			return '';
		}

		$this->rendered = true;

		return $this->markup_inline( $prepared['links'], $prepared['bar'] );
	}

	/**
	 * wp_footer auto-output: mobile bottom bar + desktop floating launcher.
	 * Each is resolved independently (see BarResolver::resolve_for_device())
	 * since Pro's targeting can pick a different bar — or none — per device.
	 */
	public function render_footer(): void {
		if ( $this->rendered ) {
			return;
		}

		$mobile  = $this->prepare_for_device( 'mobile' );
		$desktop = $this->prepare_for_device( 'desktop' );

		if ( null === $mobile && null === $desktop ) {
			return;
		}

		$this->rendered = true;

		echo $this->markup_floating( $mobile, $desktop ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup_floating() escapes every dynamic value itself as it builds the string.
	}

	/**
	 * @return array{bar:array<string,mixed>,links:array<int,array<string,mixed>>}|null
	 */
	private function prepare(): ?array {
		return $this->prepare_bar( $this->resolver->resolve() );
	}

	/**
	 * @return array{bar:array<string,mixed>,links:array<int,array<string,mixed>>}|null
	 */
	private function prepare_for_device( string $device ): ?array {
		return $this->prepare_bar( $this->resolver->resolve_for_device( $device ) );
	}

	/**
	 * @param array<string,mixed>|null $bar
	 * @return array{bar:array<string,mixed>,links:array<int,array<string,mixed>>}|null
	 */
	private function prepare_bar( ?array $bar ): ?array {
		if ( null === $bar ) {
			return null;
		}

		$links = $this->links->build( $bar, $this->context->free_tokens() );

		if ( ! $links ) {
			return null;
		}

		return array(
			'bar'   => $bar,
			'links' => $links,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $links
	 * @param array<string,mixed>            $bar
	 */
	private function markup_inline( array $links, array $bar ): string {
		$html  = '<div class="clift clift-inline" style="' . esc_attr( $this->style_vars( $bar ) ) . '">';
		$html .= $this->render_action_list( $links, false, (string) ( $bar['id'] ?? '' ) );
		$html .= '</div>';
		$html .= $this->maybe_render_callback_dialog( $links );

		return $html;
	}

	/**
	 * @param array{bar:array<string,mixed>,links:array<int,array<string,mixed>>}|null $mobile
	 * @param array{bar:array<string,mixed>,links:array<int,array<string,mixed>>}|null $desktop
	 */
	private function markup_floating( ?array $mobile, ?array $desktop ): string {
		$wrapper_style = esc_attr( $this->style_vars( $mobile['bar'] ?? $desktop['bar'] ?? array() ) );

		$html = '<div class="clift" style="' . $wrapper_style . '">';

		$all_links = array();

		if ( null !== $mobile ) {
			$html     .= '<nav class="clift-mobile" aria-label="' . esc_attr__( 'Contact options', 'contactlift' ) . '" style="' . esc_attr( $this->style_vars( $mobile['bar'] ) ) . '">';
			$html     .= $this->render_action_list( array_slice( $mobile['links'], 0, 4 ), false, (string) ( $mobile['bar']['id'] ?? '' ) );
			$html     .= '</nav>';
			$all_links = array_merge( $all_links, $mobile['links'] );
		}

		if ( null !== $desktop ) {
			$appearance = $desktop['bar']['appearance'] ?? array();
			$side       = 'left' === ( $appearance['desktop_side'] ?? 'right' ) ? 'left' : 'right';
			$panel_id   = 'clift-panel-' . wp_unique_id();

			$html     .= '<div class="clift-desktop clift-desktop--' . esc_attr( $side ) . '" style="' . esc_attr( $this->style_vars( $desktop['bar'] ) ) . '">';
			$html     .= '<div class="clift-panel" id="' . esc_attr( $panel_id ) . '" hidden>';
			$html     .= $this->render_action_list( array_slice( $desktop['links'], 0, 4 ), true, (string) ( $desktop['bar']['id'] ?? '' ) );
			$html     .= '</div>';
			$html     .= '<button type="button" class="clift-launcher" aria-expanded="false" aria-controls="' . esc_attr( $panel_id ) . '" aria-label="' . esc_attr__( 'Contact us', 'contactlift' ) . '">';
			$html     .= Icons::get( 'chat' );
			$html     .= '</button>';
			$html     .= '</div>';
			$all_links = array_merge( $all_links, $desktop['links'] );
		}

		$html .= '</div>';
		$html .= $this->maybe_render_callback_dialog( $all_links );

		return $html;
	}

	/**
	 * @param array<int,array<string,mixed>> $links
	 */
	private function render_action_list( array $links, bool $primary_first, string $bar_id ): string {
		$html = '';

		foreach ( $links as $index => $link ) {
			$is_primary = $primary_first && 0 === $index;
			$class      = 'clift-action' . ( $is_primary ? ' clift-action--primary' : '' );

			if ( 'callback' === ( $link['type'] ?? '' ) ) {
				$html .= '<button type="button" class="' . esc_attr( $class ) . '" data-clift-callback data-bar-id="' . esc_attr( $bar_id ) . '" data-action-id="' . esc_attr( $link['id'] ) . '">';
				$html .= '<span class="clift-action__icon">' . Icons::get( 'callback' ) . '</span>';
				$html .= '<span class="clift-action__label">' . esc_html( $link['label'] ) . '</span>';
				$html .= '</button>';
				continue;
			}

			$target = ! empty( $link['new_tab'] ) ? ' target="_blank" rel="noopener noreferrer"' : '';

			$html .= '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $link['href'] ) . '" data-action-id="' . esc_attr( $link['id'] ) . '"' . $target . '>';
			$html .= '<span class="clift-action__icon">' . Icons::get( $link['type'] ) . '</span>';
			$html .= '<span class="clift-action__label">' . esc_html( $link['label'] ) . '</span>';
			$html .= '</a>';
		}

		return $html;
	}

	/**
	 * The dialog markup is shared by every callback trigger on the page —
	 * rendered once, opened by whichever button was actually clicked. Empty
	 * string (nothing printed, no JS/CSS enqueue reason) when no callback
	 * action is present anywhere in what's rendering.
	 *
	 * @param array<int,array<string,mixed>> $links
	 */
	private function maybe_render_callback_dialog( array $links ): string {
		$has_callback = false;
		foreach ( $links as $link ) {
			if ( 'callback' === ( $link['type'] ?? '' ) ) {
				$has_callback = true;
				break;
			}
		}

		/**
		 * Lets Pro render its callback request dialog once per page, only
		 * when a callback action is actually present. Free ignores this
		 * filter entirely when Pro isn't active.
		 *
		 * @param string $markup
		 * @param bool   $has_callback
		 * @param PageContext $context
		 */
		return apply_filters( 'clift_callback_dialog', '', $has_callback, $this->context );
	}

	/**
	 * @param array<string,mixed> $bar
	 */
	private function style_vars( array $bar ): string {
		$appearance = $bar['appearance'] ?? array();

		$vars = array(
			'--clift-bg'     => $appearance['background'] ?? '#ffffff',
			'--clift-text'   => $appearance['text'] ?? '#0b1d33',
			'--clift-accent' => $appearance['accent'] ?? '#ff5c8a',
			'--clift-offset' => ( (int) ( $appearance['bottom_offset'] ?? 16 ) ) . 'px',
		);

		$pairs = array();
		foreach ( $vars as $name => $value ) {
			$pairs[] = $name . ':' . $value;
		}

		return implode( ';', $pairs );
	}
}
