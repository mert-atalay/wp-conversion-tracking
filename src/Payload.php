<?php
/**
 * Builds the JSON payload for the server-side forward.
 *
 * @package LCT
 */

namespace LCT;

defined( 'ABSPATH' ) || exit;

/**
 * Server payload builder.
 */
final class Payload {

	/**
	 * Builds the payload for one lead.
	 *
	 * @param array<string, mixed> $lead       Lead record created by LeadHandler.
	 * @param string               $email      Submitted email address, may be empty.
	 * @param bool                 $hash_email Whether the site owner enabled hashed email.
	 * @param string               $site_url   Home URL of the site.
	 * @return array<string, mixed>
	 */
	public static function build( array $lead, string $email, bool $hash_email, string $site_url ): array {
		$payload = array(
			'event_name'  => (string) $lead['event_name'],
			'event_id'    => (string) $lead['event_id'],
			'timestamp'   => (int) $lead['timestamp'],
			'form_id'     => (string) $lead['form_id'],
			'form_type'   => (string) $lead['form_type'],
			'site_url'    => $site_url,
			'attribution' => array(
				'first' => (object) $lead['attribution']['first'],
				'last'  => (object) $lead['attribution']['last'],
			),
		);

		if ( $hash_email ) {
			$hash = self::hash_email( $email );

			if ( '' !== $hash ) {
				$payload['user_data'] = array( 'sha256_email' => $hash );
			}
		}

		return $payload;
	}

	/**
	 * Returns the SHA-256 hash of a trimmed, lower-cased email address.
	 *
	 * @param string $email Raw email address.
	 * @return string 64 hex characters, or an empty string for an invalid address.
	 */
	public static function hash_email( string $email ): string {
		$email = strtolower( trim( $email ) );

		if ( '' === $email || ! is_email( $email ) ) {
			return '';
		}

		return hash( 'sha256', $email );
	}
}
