<?php
/**
 * Sends the lead payload to the configured HTTPS endpoint.
 *
 * @package LCT
 */

namespace LCT;

defined( 'ABSPATH' ) || exit;

/**
 * Optional server-side forward.
 */
final class Forwarder {

	public const SECRET_HEADER = 'X-LCT-Secret';

	/**
	 * Posts the payload without waiting for the response.
	 *
	 * @param array<string, mixed> $payload  Payload from Payload::build().
	 * @param array<string, mixed> $settings Plugin settings.
	 * @return bool True when a request was dispatched.
	 */
	public function send( array $payload, array $settings ): bool {
		$url = Settings::sanitize_endpoint( $settings['endpoint_url'] ?? '' );

		if ( empty( $settings['forward_enabled'] ) || '' === $url ) {
			return false;
		}

		$args = array(
			'blocking'    => false,
			'timeout'     => 3,
			'redirection' => 0,
			'headers'     => array(
				'Content-Type'      => 'application/json; charset=utf-8',
				self::SECRET_HEADER => (string) ( $settings['shared_secret'] ?? '' ),
			),
			'body'        => wp_json_encode( $payload ),
			'data_format' => 'body',
		);

		/**
		 * Filters the request arguments of the server-side forward.
		 *
		 * @param array<string, mixed> $args    Arguments for wp_remote_post().
		 * @param array<string, mixed> $payload Payload being sent.
		 */
		$args = apply_filters( 'lct_forward_request_args', $args, $payload );

		wp_remote_post( $url, $args );

		return true;
	}
}
