<?php
/**
 * Removes everything the plugin stored in the options table.
 *
 * @package LCT
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'lct_settings' );
delete_option( 'lct_lead_log' );
