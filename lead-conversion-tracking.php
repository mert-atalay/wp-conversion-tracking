<?php
/**
 * Plugin Name:       Lead Conversion Tracking
 * Description:       Captures campaign attribution after consent, attaches it to form submissions and sends one de-duplicated generate_lead event to the dataLayer and, optionally, to a server endpoint.
 * Version:           0.1.0
 * Requires at least: 6.3
 * Requires PHP:      8.1
 * Author:            Mert Atalay
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       lead-conversion-tracking
 *
 * @package LCT
 */

defined( 'ABSPATH' ) || exit;

define( 'LCT_VERSION', '0.1.0' );
define( 'LCT_FILE', __FILE__ );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'LCT\\' ) ) {
			return;
		}

		$path = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class_name, 4 ) ) . '.php';

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
);

add_action( 'plugins_loaded', array( LCT\Plugin::class, 'boot' ) );
