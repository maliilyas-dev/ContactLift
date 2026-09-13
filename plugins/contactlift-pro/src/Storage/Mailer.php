<?php
/**
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Storage;

defined( 'ABSPATH' ) || exit;

/**
 * "Send one plain-text admin notification through wp_mail()... Set From
 * using the site's configured mail setup. Use a validated optional email
 * only as Reply-To. Reject header injection... Do not email the visitor
 * automatically." (spec). From is left to wp_mail()'s own default/filtered
 * behaviour — "the site's configured mail setup" already is that — rather
 * than overridden here.
 */
final class Mailer {

	/**
	 * @param string[]             $recipients
	 * @param array<string,mixed> $callback
	 */
	public function send_callback_notification( array $recipients, array $callback ): bool {
		$recipients = array_values( array_filter( $recipients, static fn( $email ) => is_string( $email ) && '' !== $email ) );

		if ( ! $recipients ) {
			return false;
		}

		$name = (string) ( $callback['name'] ?? '' );

		$subject = sprintf(
			/* translators: %s: the visitor's name. */
			__( 'New callback request from %s', 'contactlift-pro' ),
			$name
		);

		$headers = array();
		$email   = trim( (string) ( $callback['email'] ?? '' ) );

		// Belt-and-suspenders: CallbackValidator already rejects control
		// characters in every field before a record ever reaches here, but
		// a Reply-To header is exactly the kind of place a header-injection
		// regression would matter most, so it's checked again at the point
		// it's actually used to build a header.
		if (
			'' !== $email
			&& false !== filter_var( $email, FILTER_VALIDATE_EMAIL )
			&& 1 !== preg_match( '/[\r\n]/', $email )
			&& 1 !== preg_match( '/[\r\n]/', $name )
		) {
			$headers[] = sprintf( 'Reply-To: %1$s <%2$s>', $name, $email );
		}

		return (bool) wp_mail( $recipients, $subject, $this->build_body( $callback ), $headers );
	}

	/**
	 * @param array<string,mixed> $callback
	 */
	private function build_body( array $callback ): string {
		$lines = array(
			__( 'A visitor requested a callback.', 'contactlift-pro' ),
			'',
			sprintf(
				/* translators: %s: visitor name. */
				__( 'Name: %s', 'contactlift-pro' ),
				(string) ( $callback['name'] ?? '' )
			),
			sprintf(
				/* translators: %s: visitor phone number. */
				__( 'Phone: %s', 'contactlift-pro' ),
				(string) ( $callback['phone'] ?? '' )
			),
		);

		if ( '' !== (string) ( $callback['email'] ?? '' ) ) {
			$lines[] = sprintf(
				/* translators: %s: visitor email address. */
				__( 'Email: %s', 'contactlift-pro' ),
				(string) $callback['email']
			);
		}

		if ( '' !== (string) ( $callback['message'] ?? '' ) ) {
			$lines[] = '';
			$lines[] = __( 'Message:', 'contactlift-pro' );
			$lines[] = (string) $callback['message'];
		}

		return implode( "\n", $lines );
	}
}
