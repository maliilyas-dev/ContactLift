<?php
/**
 * @package ContactLift
 */

namespace ContactLift\Frontend;

use ContactLift\Domain\Channels\ChannelRegistry;
use ContactLift\Domain\Tokens\TokenResolver;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a bar's validated actions into render-ready link data: resolved
 * label, icon key, href, and whether it should open in a new tab. Token
 * resolution happens once per field, then each channel encodes exactly once
 * when it builds the URL — never resolved twice, never encoded twice.
 */
final class ActionLinkBuilder {

	private ChannelRegistry $channels;
	private TokenResolver $tokens;

	public function __construct( ?ChannelRegistry $channels = null, ?TokenResolver $tokens = null ) {
		$this->channels = $channels ?? new ChannelRegistry();
		$this->tokens   = $tokens ?? new TokenResolver();
	}

	/**
	 * @param array<string,mixed>  $bar
	 * @param array<string,string> $token_values
	 * @return array<int,array{id:string,type:string,label:string,href:string,new_tab:bool}>
	 */
	public function build( array $bar, array $token_values ): array {
		$links = array();

		foreach ( $bar['actions'] ?? array() as $action ) {
			$link = $this->build_one( $action, $token_values );

			if ( null !== $link ) {
				$links[] = $link;
			}
		}

		return $links;
	}

	/**
	 * @param array<string,mixed>  $action
	 * @param array<string,string> $token_values
	 * @return array{id:string,type:string,label:string,href:string,new_tab:bool}|null
	 */
	private function build_one( array $action, array $token_values ): ?array {
		if ( empty( $action['enabled'] ) || '' === trim( (string) ( $action['destination'] ?? '' ) ) ) {
			return null;
		}

		$channel = $this->channels->get( (string) ( $action['type'] ?? '' ) );

		if ( null === $channel ) {
			return null;
		}

		$action = $this->resolve_action_text( $action, $token_values );

		$resolved_message = '' !== (string) ( $action['message_template'] ?? '' )
			? $this->tokens->resolve( (string) $action['message_template'], $token_values )
			: '';

		$href = $channel->build_url( $action, $resolved_message );

		return array(
			'id'      => (string) ( $action['id'] ?? '' ),
			'type'    => (string) ( $action['type'] ?? '' ),
			'label'   => $this->label_for( $action ),
			'href'    => $href,
			'new_tab' => ! empty( $action['open_in_new_tab'] ),
		);
	}

	/**
	 * Resolves the email channel's subject/body templates up front, since
	 * EmailChannel::build_url() looks for resolved_subject/resolved_body.
	 *
	 * @param array<string,mixed>  $action
	 * @param array<string,string> $token_values
	 * @return array<string,mixed>
	 */
	private function resolve_action_text( array $action, array $token_values ): array {
		if ( 'email' !== ( $action['type'] ?? '' ) ) {
			return $action;
		}

		if ( '' !== (string) ( $action['subject_template'] ?? '' ) ) {
			$action['resolved_subject'] = $this->tokens->resolve( (string) $action['subject_template'], $token_values );
		}

		if ( '' !== (string) ( $action['body_template'] ?? '' ) ) {
			$action['resolved_body'] = $this->tokens->resolve( (string) $action['body_template'], $token_values );
		}

		return $action;
	}

	private function label_for( array $action ): string {
		$label = trim( (string) ( $action['label'] ?? '' ) );

		if ( '' !== $label ) {
			return $label;
		}

		$defaults = array(
			'call'     => __( 'Call us', 'contactlift' ),
			'whatsapp' => __( 'WhatsApp', 'contactlift' ),
			'email'    => __( 'Email us', 'contactlift' ),
			'link'     => __( 'Visit link', 'contactlift' ),
			'callback' => __( 'Request a callback', 'contactlift' ),
		);

		return $defaults[ $action['type'] ?? '' ] ?? __( 'Contact us', 'contactlift' );
	}
}
