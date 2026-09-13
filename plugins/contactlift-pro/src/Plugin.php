<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro;

use ContactLift\Domain\Validation\EditionLimits;
use ContactLiftPro\Domain\Callbacks\CallbackReadiness;
use ContactLiftPro\Domain\Channels\CallbackChannel;
use ContactLiftPro\Domain\Schedule\Clock;
use ContactLiftPro\Domain\Schedule\ClosedActionResolver;
use ContactLiftPro\Domain\Schedule\ScheduleEvaluator;
use ContactLiftPro\Domain\Schedule\ScheduleValidator;
use ContactLiftPro\Domain\Targeting\BarTargetingResolver;
use ContactLiftPro\Domain\Targeting\MatchContext;
use ContactLiftPro\Frontend\CallbackDialog;
use ContactLiftPro\Frontend\ProductTokens;
use ContactLiftPro\Rest\CallbackController;
use ContactLiftPro\Rest\RuntimeController;
use ContactLiftPro\Storage\CleanupRunner;
use ContactLiftPro\Storage\ProSettingsRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Service wiring for ContactLift Pro. Booted from the free plugin's
 * clift_loaded action once API compatibility is confirmed (see
 * contactlift-pro.php) — every Pro feature composes with free's own storage
 * and frontend rather than duplicating it, via the extension points free
 * exposes: clift_resolve_bar, clift_validate_schedule, clift_edition_limits,
 * clift_bar_editor_fields/submission and clift_admin_bars_list_extra.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	private BarTargetingResolver $targeting;
	private ScheduleEvaluator $schedule;
	private ClosedActionResolver $closed_actions;

	private function __construct() {
		$this->targeting      = new BarTargetingResolver();
		$this->schedule       = new ScheduleEvaluator();
		$this->closed_actions = new ClosedActionResolver();
	}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		add_filter( 'clift_resolve_bar', array( $this, 'resolve_bar' ), 10, 3 );
		add_filter( 'clift_validate_schedule', array( $this, 'validate_schedule' ), 10, 2 );
		add_filter( 'clift_edition_limits', static fn() => EditionLimits::pro() );
		add_filter( 'clift_register_channels', array( $this, 'register_channels' ) );
		add_filter( 'clift_token_values', array( $this, 'merge_product_tokens' ) );
		add_filter( 'clift_callback_dialog', array( new CallbackDialog(), 'render' ), 10, 3 );
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( Storage\ProInstaller::CLEANUP_HOOK, array( $this, 'run_daily_cleanup' ) );
		( new Privacy\PersonalData() )->register();

		if ( is_admin() ) {
			( new Admin\BarEditorExtension() )->register();
			( new Admin\SettingsExtension() )->register();
			( new Admin\CallbackInboxPage() )->register();
			( new Admin\ImportExportExtension() )->register();
			( new Admin\ReportsPage() )->register();
		} else {
			( new Frontend\Assets() )->register();
		}

		/**
		 * Fires once Pro has finished booting — free's ToolsPage diagnostics
		 * ("ContactLift Pro active") checks did_action() on this.
		 */
		do_action( 'clift_pro_loaded', CONTACTLIFT_PRO_VERSION );
	}

	/**
	 * @param \ContactLift\Domain\Channels\ChannelInterface[] $channels
	 * @return \ContactLift\Domain\Channels\ChannelInterface[]
	 */
	public function register_channels( array $channels ): array {
		$channels[] = new CallbackChannel();

		return $channels;
	}

	/**
	 * @param array<string,string> $tokens
	 * @return array<string,string>
	 */
	public function merge_product_tokens( array $tokens ): array {
		return array_merge( $tokens, ( new ProductTokens() )->resolve() );
	}

	public function register_rest_routes(): void {
		( new CallbackController() )->register_routes();
		( new RuntimeController() )->register_routes();
	}

	public function run_daily_cleanup(): void {
		( new CleanupRunner() )->run( Clock::system() );
	}

	/**
	 * Rule resolution steps 2-4 (device, exclude/include, priority) across
	 * every bar Pro has added, then the spec's "Closed actions" step applied
	 * to whichever bar wins. With no targeted bars yet, this defers entirely
	 * to free's own single-bar resolution — still passing it through the
	 * closed-action transform, since Pro's schedule engine can extend even
	 * the default bar's actions once BarEditorExtension lets it save one.
	 *
	 * @param array<string,mixed>|null           $default_bar Free's own resolution.
	 * @param \ContactLift\Frontend\PageContext $context
	 * @return array<string,mixed>|null
	 */
	public function resolve_bar( $default_bar, $context, ?string $device ) {
		$document = \ContactLift\Plugin::instance()->settings()->get_bars_document();
		$bars     = $document['bars'] ?? array();

		if ( count( $bars ) <= 1 ) {
			$winner = $default_bar;
		} else {
			$match_context  = new MatchContext( $context->current_content_id(), $this->product_cat_ids() );
			$default_bar_id = $document['default_bar_id'] ?? 'bar_default';

			$winner = $this->targeting->resolve( $bars, $default_bar_id, $match_context, $device ?? 'all' );
		}

		if ( ! is_array( $winner ) ) {
			return $winner;
		}

		$is_open           = $this->schedule->is_open( $winner['schedule'] ?? array(), wp_timezone(), Clock::system() );
		$winner['actions'] = $this->closed_actions->apply( $winner['actions'] ?? array(), $is_open );
		$winner['actions'] = $this->drop_unconfigured_callback_actions( $winner['actions'] );

		return $winner;
	}

	/**
	 * A callback action is only ever renderable once its sitewide
	 * prerequisites are actually met — "Before enabling this channel,
	 * require a notification recipient and privacy-policy URL" (spec). An
	 * admin can enable the action before finishing those settings, so this
	 * is checked at render time, the same way a closed+hidden action
	 * disappears: dropped from the list, which correctly makes the whole
	 * bar disappear too via BarState::has_renderable_action() if it was the
	 * only action.
	 *
	 * @param array<int,array<string,mixed>> $actions
	 * @return array<int,array<string,mixed>>
	 */
	private function drop_unconfigured_callback_actions( array $actions ): array {
		$has_callback = false;
		foreach ( $actions as $action ) {
			if ( 'callback' === ( $action['type'] ?? '' ) ) {
				$has_callback = true;
				break;
			}
		}

		if ( ! $has_callback ) {
			return $actions;
		}

		if ( CallbackReadiness::is_configured( ( new ProSettingsRepository() )->get_settings() ) ) {
			return $actions;
		}

		return array_values( array_filter( $actions, static fn( array $action ): bool => 'callback' !== ( $action['type'] ?? '' ) ) );
	}

	/**
	 * @param array<string,mixed> $disabled
	 * @param mixed                $raw
	 * @return array<string,mixed>
	 */
	public function validate_schedule( $disabled, $raw ) {
		return ( new ScheduleValidator() )->validate( $raw );
	}

	/**
	 * Product-category IDs for the current request: the categories a
	 * viewed product belongs to, or the single category of a product
	 * category archive itself — "cover category archives and product pages
	 * assigned to those terms" (spec). Empty (and therefore never matching
	 * a category-scoped rule) when WooCommerce isn't active or neither
	 * applies, rather than guessing.
	 *
	 * @return int[]
	 */
	private function product_cat_ids(): array {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}

		if ( function_exists( 'is_product' ) && is_product() ) {
			$terms = get_the_terms( get_queried_object_id(), 'product_cat' );

			return is_array( $terms ) ? array_map( static fn( \WP_Term $term ): int => (int) $term->term_id, $terms ) : array();
		}

		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();

			return $term instanceof \WP_Term ? array( (int) $term->term_id ) : array();
		}

		return array();
	}
}
