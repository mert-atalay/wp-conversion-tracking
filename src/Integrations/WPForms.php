<?php
/**
 * WPForms adapter.
 *
 * @package LCT
 */

namespace LCT\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Reports a lead when WPForms has finished processing an entry.
 */
final class WPForms implements Integration {

	public const TYPE = 'wpforms';

	/**
	 * Registers hooks. Harmless when WPForms is not active.
	 */
	public function register(): void {
		add_action( 'wpforms_process_complete', array( $this, 'on_complete' ), 10, 3 );
	}

	/**
	 * Callback for `wpforms_process_complete`.
	 *
	 * @param mixed $fields    Sanitised field data.
	 * @param mixed $entry     Original posted entry. Not used.
	 * @param mixed $form_data Form settings, including the form id.
	 */
	public function on_complete( $fields, $entry = null, $form_data = null ): void {
		if ( ! is_array( $form_data ) || empty( $form_data['id'] ) ) {
			return;
		}

		do_action( 'lct_form_submitted', (string) absint( $form_data['id'] ), self::TYPE, $this->find_email( $fields ) );
	}

	/**
	 * Returns the value of the first email field of the entry.
	 *
	 * @param mixed $fields Sanitised field data.
	 */
	private function find_email( $fields ): string {
		foreach ( (array) $fields as $field ) {
			if ( is_array( $field ) && 'email' === ( $field['type'] ?? '' ) && is_string( $field['value'] ?? null ) && '' !== $field['value'] ) {
				return $field['value'];
			}
		}

		return '';
	}
}
