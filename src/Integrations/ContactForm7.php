<?php
/**
 * Contact Form 7 adapter.
 *
 * @package LCT
 */

namespace LCT\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * Reports a lead when Contact Form 7 has sent its mail.
 */
final class ContactForm7 implements Integration {

	public const TYPE = 'cf7';

	/**
	 * Registers hooks. Harmless when Contact Form 7 is not active.
	 */
	public function register(): void {
		add_action( 'wpcf7_mail_sent', array( $this, 'on_mail_sent' ) );
	}

	/**
	 * Callback for `wpcf7_mail_sent`.
	 *
	 * @param mixed $contact_form WPCF7_ContactForm instance.
	 */
	public function on_mail_sent( $contact_form ): void {
		if ( ! is_object( $contact_form ) || ! method_exists( $contact_form, 'id' ) ) {
			return;
		}

		do_action( 'lct_form_submitted', (string) $contact_form->id(), self::TYPE, $this->find_email( $contact_form ) );
	}

	/**
	 * Returns the value of the first email field of the submission.
	 *
	 * @param object $contact_form WPCF7_ContactForm instance.
	 */
	private function find_email( object $contact_form ): string {
		if ( ! class_exists( '\WPCF7_Submission' ) || ! method_exists( $contact_form, 'scan_form_tags' ) ) {
			return '';
		}

		$submission = \WPCF7_Submission::get_instance();

		if ( ! $submission ) {
			return '';
		}

		foreach ( (array) $contact_form->scan_form_tags( array( 'basetype' => 'email' ) ) as $tag ) {
			$value = isset( $tag->name ) ? $submission->get_posted_data( $tag->name ) : '';

			if ( is_string( $value ) && '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}
