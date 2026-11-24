<?php

declare( strict_types=1 );

namespace LCT\Tests;

use Brain\Monkey\Functions;
use LCT\Settings;

final class SettingsTest extends TestCase {

	public function test_defaults_keep_forwarding_hashing_and_assumed_consent_off(): void {
		$defaults = Settings::defaults();

		$this->assertFalse( $defaults['forward_enabled'] );
		$this->assertFalse( $defaults['hash_email'] );
		$this->assertFalse( $defaults['assume_consent'] );
		$this->assertSame( '', $defaults['endpoint_url'] );
		$this->assertSame( 90, $defaults['cookie_days'] );
	}

	public function test_get_merges_stored_values_over_defaults(): void {
		$this->options[ Settings::OPTION ] = array( 'cookie_days' => 30 );

		$settings = Settings::get();

		$this->assertSame( 30, $settings['cookie_days'] );
		$this->assertFalse( $settings['forward_enabled'] );
	}

	public function test_get_survives_a_corrupt_option(): void {
		$this->options[ Settings::OPTION ] = 'broken';

		$this->assertSame( Settings::defaults(), Settings::get() );
	}

	public function test_sanitize_returns_safe_values_for_non_array_input(): void {
		$clean = Settings::sanitize( 'nonsense' );

		$this->assertSame( 90, $clean['cookie_days'] );
		$this->assertFalse( $clean['log_enabled'] );
		$this->assertFalse( $clean['forward_enabled'] );
		$this->assertSame( '', $clean['endpoint_url'] );
		$this->assertSame( '', $clean['shared_secret'] );
	}

	/**
	 * @dataProvider cookie_days_provider
	 */
	public function test_sanitize_clamps_cookie_days( $input, int $expected ): void {
		$this->assertSame( $expected, Settings::sanitize( array( 'cookie_days' => $input ) )['cookie_days'] );
	}

	public static function cookie_days_provider(): array {
		return array(
			'normal'       => array( '30', 30 ),
			'too large'    => array( '5000', 395 ),
			'zero'         => array( '0', 90 ),
			'negative'     => array( '-7', 7 ),
			'not a number' => array( 'abc', 90 ),
			'array'        => array( array( 1 ), 90 ),
		);
	}

	public function test_sanitize_casts_checkboxes_to_booleans(): void {
		$clean = Settings::sanitize(
			array(
				'assume_consent' => '1',
				'log_enabled'    => 'on',
				'hash_email'     => '',
			)
		);

		$this->assertTrue( $clean['assume_consent'] );
		$this->assertTrue( $clean['log_enabled'] );
		$this->assertFalse( $clean['hash_email'] );
	}

	public function test_sanitize_keeps_an_https_endpoint_and_strips_tags_from_the_secret(): void {
		$clean = Settings::sanitize(
			array(
				'forward_enabled' => '1',
				'endpoint_url'    => '  https://collect.example.com/lead  ',
				'shared_secret'   => ' <b>s3cret</b> ',
			)
		);

		$this->assertTrue( $clean['forward_enabled'] );
		$this->assertSame( 'https://collect.example.com/lead', $clean['endpoint_url'] );
		$this->assertSame( 's3cret', $clean['shared_secret'] );
	}

	/**
	 * @dataProvider bad_endpoint_provider
	 */
	public function test_sanitize_rejects_endpoints_that_are_not_https( $url ): void {
		Functions\expect( 'add_settings_error' )->once();

		$clean = Settings::sanitize(
			array(
				'forward_enabled' => '1',
				'endpoint_url'    => $url,
			)
		);

		$this->assertSame( '', $clean['endpoint_url'] );
		$this->assertFalse( $clean['forward_enabled'], 'Forwarding must be off without a valid endpoint.' );
	}

	public static function bad_endpoint_provider(): array {
		return array(
			'http'       => array( 'http://collect.example.com/lead' ),
			'javascript' => array( 'javascript:alert(1)' ),
			'no scheme'  => array( 'collect.example.com/lead' ),
			'empty'      => array( '' ),
			'array'      => array( array( 'https://collect.example.com' ) ),
		);
	}

	public function test_sanitize_output_has_exactly_the_known_keys(): void {
		$clean = Settings::sanitize( array( 'unknown' => 'x', 'cookie_days' => 10 ) );

		$this->assertEqualsCanonicalizing( array_keys( Settings::defaults() ), array_keys( $clean ) );
	}
}
