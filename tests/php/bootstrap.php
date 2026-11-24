<?php
/**
 * PHPUnit bootstrap. No WordPress is loaded; Brain Monkey stubs the functions.
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
