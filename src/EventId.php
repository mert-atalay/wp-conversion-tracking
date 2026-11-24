<?php
/**
 * Event id validation and generation.
 *
 * @package LCT
 */

namespace LCT;

defined( 'ABSPATH' ) || exit;

/**
 * The event id links the browser event and the server event for one submission.
 */
final class EventId {

	public const FIELD   = 'lct_event_id';
	public const PATTERN = '/^[A-Za-z0-9_-]{8,64}\z/';

	/**
	 * Whether a value is an acceptable event id.
	 *
	 * @param mixed $value Candidate value.
	 */
	public static function is_valid( $value ): bool {
		return is_string( $value ) && 1 === preg_match( self::PATTERN, $value );
	}

	/**
	 * Returns the submitted id when it is valid, otherwise a new UUID.
	 *
	 * @param mixed $candidate Value sent by the browser.
	 */
	public static function resolve( $candidate ): string {
		return self::is_valid( $candidate ) ? $candidate : wp_generate_uuid4();
	}
}
