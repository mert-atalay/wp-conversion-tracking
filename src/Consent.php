<?php
/**
 * Consent check used before anything is stored, logged or sent.
 *
 * @package LCT
 */

namespace LCT;

defined( 'ABSPATH' ) || exit;

/**
 * Consent gate.
 */
final class Consent {

	public const CATEGORY = 'marketing';

	/**
	 * Callable with the signature of wp_has_consent(), or null to look the function up when needed.
	 *
	 * @var callable|null
	 */
	private $api;

	/**
	 * Whether to look for wp_has_consent() when no callable was given.
	 *
	 * @var bool
	 */
	private bool $detect;

	/**
	 * Constructor.
	 *
	 * @param callable|null $api    Consent lookup. Defaults to wp_has_consent() when that function exists.
	 * @param bool          $detect Set to false to skip the automatic lookup (used in tests).
	 */
	public function __construct( ?callable $api = null, bool $detect = true ) {
		$this->api    = $api;
		$this->detect = $detect;
	}

	/**
	 * Returns the consent lookup, or null when the WP Consent API is not active.
	 *
	 * Resolved late because the WP Consent API may load after this plugin.
	 */
	private function api(): ?callable {
		if ( null === $this->api && $this->detect && function_exists( 'wp_has_consent' ) ) {
			$this->api = 'wp_has_consent';
		}

		return $this->api;
	}

	/**
	 * Whether the WP Consent API is in use.
	 */
	public function uses_api(): bool {
		return null !== $this->api();
	}

	/**
	 * Whether the current visitor has given marketing consent.
	 *
	 * With the WP Consent API active its answer is used. Without it the
	 * "assume consent" setting applies, which is off by default.
	 */
	public function has_consent(): bool {
		$api = $this->api();

		if ( null !== $api ) {
			$granted = (bool) call_user_func( $api, self::CATEGORY );
		} else {
			$granted = ! empty( Settings::get()['assume_consent'] );
		}

		/**
		 * Filters the consent decision. Use it to connect a consent tool
		 * that does not support the WP Consent API.
		 *
		 * @param bool   $granted  Whether consent was given.
		 * @param string $category Consent category, always "marketing".
		 */
		return (bool) apply_filters( 'lct_has_consent', $granted, self::CATEGORY );
	}
}
