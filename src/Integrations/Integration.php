<?php
/**
 * Contract for form plugin integrations.
 *
 * @package LCT
 */

namespace LCT\Integrations;

defined( 'ABSPATH' ) || exit;

/**
 * An integration listens to one form plugin and reports successful
 * submissions with: do_action( 'lct_form_submitted', $form_id, $form_type, $email ).
 */
interface Integration {

	/**
	 * Registers the hooks of the integration.
	 */
	public function register(): void;
}
