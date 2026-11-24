<?php
/**
 * Plugin settings: defaults, reading and sanitisation.
 *
 * @package LCT
 */

namespace LCT;

defined( 'ABSPATH' ) || exit;

/**
 * Settings storage.
 */
final class Settings {

	public const OPTION = 'lct_settings';

	public const MIN_COOKIE_DAYS = 1;
	public const MAX_COOKIE_DAYS = 395;

	/**
	 * Default settings. Everything that sends or keeps data is off or minimal.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'cookie_days'     => 90,
			'assume_consent'  => false,
			'log_enabled'     => true,
			'forward_enabled' => false,
			'endpoint_url'    => '',
			'shared_secret'   => '',
			'hash_email'      => false,
		);
	}

	/**
	 * Returns the stored settings merged over the defaults.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Sanitises raw input from the settings form.
	 *
	 * @param mixed $input Raw value posted to options.php.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$input    = is_array( $input ) ? $input : array();
		$defaults = self::defaults();
		$output   = array();

		$days = isset( $input['cookie_days'] ) && is_scalar( $input['cookie_days'] ) ? absint( $input['cookie_days'] ) : 0;
		if ( 0 === $days ) {
			$days = $defaults['cookie_days'];
		}
		$output['cookie_days'] = max( self::MIN_COOKIE_DAYS, min( self::MAX_COOKIE_DAYS, $days ) );

		foreach ( array( 'assume_consent', 'log_enabled', 'forward_enabled', 'hash_email' ) as $flag ) {
			$output[ $flag ] = ! empty( $input[ $flag ] );
		}

		$output['endpoint_url']  = self::sanitize_endpoint( $input['endpoint_url'] ?? '' );
		$output['shared_secret'] = isset( $input['shared_secret'] ) && is_scalar( $input['shared_secret'] )
			? sanitize_text_field( (string) $input['shared_secret'] )
			: '';

		if ( $output['forward_enabled'] && '' === $output['endpoint_url'] ) {
			$output['forward_enabled'] = false;

			add_settings_error(
				self::OPTION,
				'lct_endpoint_required',
				__( 'Server-side forwarding was turned off because it needs a valid https:// endpoint URL.', 'lead-conversion-tracking' )
			);
		}

		return $output;
	}

	/**
	 * Accepts HTTPS URLs only. Anything else becomes an empty string.
	 *
	 * @param mixed $url Raw URL.
	 */
	public static function sanitize_endpoint( $url ): string {
		if ( ! is_scalar( $url ) ) {
			return '';
		}

		$url = esc_url_raw( trim( (string) $url ), array( 'https' ) );

		return str_starts_with( $url, 'https://' ) ? $url : '';
	}
}
