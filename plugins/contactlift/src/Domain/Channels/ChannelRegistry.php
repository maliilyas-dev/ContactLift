<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Domain\Channels;

defined( 'ABSPATH' ) || exit;

/**
 * The allowlist of channel types users can pick per action. Pro registers
 * its own "callback" channel through the clift_register_channels filter —
 * see the clift_loaded contract in Plugin.php.
 */
final class ChannelRegistry {

	/** @var array<string,ChannelInterface> */
	private array $channels = array();

	private bool $extensions_loaded = false;

	public function __construct() {
		$this->register( new CallChannel() );
		$this->register( new WhatsAppChannel() );
		$this->register( new EmailChannel() );
		$this->register( new LinkChannel() );
	}

	public function register( ChannelInterface $channel ): void {
		$this->channels[ $channel->type() ] = $channel;
	}

	public function has( string $type ): bool {
		$this->load_extensions_once();

		return isset( $this->channels[ $type ] );
	}

	public function get( string $type ): ?ChannelInterface {
		$this->load_extensions_once();

		return $this->channels[ $type ] ?? null;
	}

	/**
	 * @return string[]
	 */
	public function types(): array {
		$this->load_extensions_once();

		return array_keys( $this->channels );
	}

	/**
	 * Deferred to first actual use rather than run from the constructor:
	 * every one of this class's three call sites (BarValidator,
	 * ActionLinkBuilder, BarEditorPage) is itself constructed synchronously
	 * during Plugin::boot(), which completes — and constructs its
	 * SettingsRepository/Menu/Renderer tree — before boot() fires
	 * clift_loaded at its very end. Querying the filter in the constructor
	 * would therefore always run too early to see Pro's listener, even on a
	 * request where Pro registers it later in that same request. Deferring
	 * to has()/get()/types() is safe because nothing calls those during that
	 * same early construction — only later, while actually handling a
	 * request, by which point clift_loaded has already fired.
	 */
	private function load_extensions_once(): void {
		if ( $this->extensions_loaded ) {
			return;
		}

		$this->extensions_loaded = true;

		/**
		 * Lets Pro register its own channels (e.g. "callback") into every
		 * ChannelRegistry constructed anywhere in the codebase. Free ignores
		 * this filter entirely when Pro isn't active.
		 *
		 * @param ChannelInterface[] $channels Extra channels to register.
		 */
		foreach ( apply_filters( 'clift_register_channels', array() ) as $channel ) {
			if ( $channel instanceof ChannelInterface ) {
				$this->register( $channel );
			}
		}
	}
}
