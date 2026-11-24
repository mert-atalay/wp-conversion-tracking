<?php
/**
 * Wires the plugin together.
 *
 * @package LCT
 */

namespace LCT;

use LCT\Integrations\ContactForm7;
use LCT\Integrations\Integration;
use LCT\Integrations\WPForms;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class Plugin {

	/**
	 * Registers all hooks. Runs on `plugins_loaded`.
	 */
	public static function boot(): void {
		$consent = new Consent();
		$log     = new LeadLog();
		$handler = new LeadHandler( $consent, new Forwarder(), $log );

		// Any form plugin can report a successful submission through this action.
		add_action( 'lct_form_submitted', array( $handler, 'handle' ), 10, 3 );

		/**
		 * Filters the list of form plugin integrations.
		 *
		 * @param Integration[] $integrations Objects implementing LCT\Integrations\Integration.
		 */
		$integrations = apply_filters( 'lct_form_integrations', array( new ContactForm7(), new WPForms() ) );

		foreach ( (array) $integrations as $integration ) {
			if ( $integration instanceof Integration ) {
				$integration->register();
			}
		}

		( new Frontend( $consent ) )->register();

		if ( is_admin() ) {
			( new SettingsPage( $log ) )->register();
		}
	}
}
