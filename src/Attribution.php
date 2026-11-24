<?php
/**
 * Reads and sanitises attribution data sent with a form submission.
 *
 * @package LCT
 */

namespace LCT;

defined( 'ABSPATH' ) || exit;

/**
 * Attribution fields.
 */
final class Attribution {

	public const COOKIE     = 'lct_attr';
	public const MAX_LENGTH = 200;

	public const TOUCHES = array( 'first', 'last' );

	public const KEYS = array(
		'utm_source',
		'utm_medium',
		'utm_campaign',
		'utm_term',
		'utm_content',
		'gclid',
		'gbraid',
		'wbraid',
		'fbclid',
		'msclkid',
		'landing_page',
		'referrer',
		'ts',
	);

	/**
	 * Builds the attribution array for a submission.
	 *
	 * Hidden fields named lct_first_* and lct_last_* win. If the form carried
	 * none, the attribution cookie is used instead.
	 *
	 * @param array<string, mixed> $post        Unslashed request fields.
	 * @param string               $cookie_json Unslashed value of the attribution cookie.
	 * @return array{first: array<string, string|int>, last: array<string, string|int>}
	 */
	public static function from_request( array $post, string $cookie_json = '' ): array {
		$raw = array();

		foreach ( self::TOUCHES as $touch ) {
			foreach ( self::KEYS as $key ) {
				$field = 'lct_' . $touch . '_' . $key;

				if ( isset( $post[ $field ] ) ) {
					$raw[ $touch ][ $key ] = $post[ $field ];
				}
			}
		}

		$attribution = self::sanitize( $raw );

		if ( array() === $attribution['first'] && array() === $attribution['last'] && '' !== $cookie_json ) {
			$decoded = json_decode( $cookie_json, true );

			if ( is_array( $decoded ) ) {
				$attribution = self::sanitize( $decoded );
			}
		}

		return $attribution;
	}

	/**
	 * Keeps known keys only, as short plain strings.
	 *
	 * @param array<mixed> $raw Untrusted data shaped like [ 'first' => [...], 'last' => [...] ].
	 * @return array{first: array<string, string|int>, last: array<string, string|int>}
	 */
	public static function sanitize( array $raw ): array {
		$clean = array(
			'first' => array(),
			'last'  => array(),
		);

		foreach ( self::TOUCHES as $touch ) {
			if ( ! isset( $raw[ $touch ] ) || ! is_array( $raw[ $touch ] ) ) {
				continue;
			}

			foreach ( self::KEYS as $key ) {
				$value = $raw[ $touch ][ $key ] ?? '';

				if ( ! is_scalar( $value ) ) {
					continue;
				}

				if ( 'ts' === $key ) {
					$value = absint( $value );

					if ( $value > 0 ) {
						$clean[ $touch ][ $key ] = $value;
					}

					continue;
				}

				$value = mb_substr( sanitize_text_field( (string) $value ), 0, self::MAX_LENGTH );

				if ( '' !== $value ) {
					$clean[ $touch ][ $key ] = $value;
				}
			}
		}

		return $clean;
	}
}
