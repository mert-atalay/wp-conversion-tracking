<?php

declare( strict_types=1 );

namespace LCT\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case with simple stand-ins for the WordPress functions the plugin calls.
 */
abstract class TestCase extends BaseTestCase {

	use MockeryPHPUnitIntegration;

	/** Value returned by get_option(), keyed by option name. */
	protected array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options = array();

		Functions\stubTranslationFunctions();
		Functions\stubEscapeFunctions();

		Functions\stubs(
			array(
				'absint'              => static fn( $value ) => abs( (int) $value ),
				'sanitize_text_field' => static fn( $value ) => trim( (string) preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ),
				'sanitize_key'        => static fn( $value ) => (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ),
				'is_email'            => static fn( $value ) => false !== filter_var( $value, FILTER_VALIDATE_EMAIL ) ? $value : false,
				'wp_json_encode'      => static fn( $value ) => json_encode( $value ),
				'wp_generate_uuid4'   => static fn() => '11111111-2222-4333-8444-555555555555',
				'wp_unslash'          => static fn( $value ) => $value,
				'home_url'            => static fn() => 'https://example.com',
				'esc_url_raw'         => static function ( $url, $protocols = null ) {
					$url = (string) $url;

					if ( '' === $url ) {
						return '';
					}

					if ( ! preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $url, $match ) ) {
						return 'http://' . $url;
					}

					return in_array( strtolower( $match[1] ), $protocols ?? array( 'http', 'https' ), true ) ? $url : '';
				},
			)
		);

		Functions\when( 'get_option' )->alias(
			fn( $name, $fallback = false ) => $this->options[ $name ] ?? $fallback
		);
		Functions\when( 'update_option' )->alias(
			function ( $name, $value ) {
				$this->options[ $name ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $name ) {
				unset( $this->options[ $name ] );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}
}
