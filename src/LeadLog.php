<?php
/**
 * Small capped log of lead events, kept in one option.
 *
 * @package LCT
 */

namespace LCT;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the event id of recent submissions on the server.
 *
 * This is a debugging aid, not a lead database. Two submissions in the same
 * instant can overwrite each other because the option is read and written
 * without a lock.
 */
final class LeadLog {

	public const OPTION      = 'lct_lead_log';
	public const MAX_ENTRIES = 100;

	/**
	 * Adds a lead record and drops the oldest ones above the cap.
	 *
	 * @param array<string, mixed> $lead Lead record created by LeadHandler.
	 */
	public function add( array $lead ): void {
		$entries   = $this->all();
		$entries[] = $lead;

		update_option( self::OPTION, array_slice( $entries, -self::MAX_ENTRIES ), false );
	}

	/**
	 * Returns all stored records, oldest first.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function all(): array {
		$entries = get_option( self::OPTION, array() );

		return is_array( $entries ) ? array_values( array_filter( $entries, 'is_array' ) ) : array();
	}

	/**
	 * Deletes the log.
	 */
	public function clear(): void {
		delete_option( self::OPTION );
	}
}
