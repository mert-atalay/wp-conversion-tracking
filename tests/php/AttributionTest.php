<?php

declare( strict_types=1 );

namespace LCT\Tests;

use LCT\Attribution;

final class AttributionTest extends TestCase {

	public function test_reads_first_and_last_touch_from_hidden_fields(): void {
		$attribution = Attribution::from_request(
			array(
				'lct_first_utm_source'  => 'google',
				'lct_first_gclid'       => 'abc123',
				'lct_first_ts'          => '1790000000',
				'lct_last_utm_source'   => 'newsletter',
				'lct_last_landing_page' => '/offer/',
				'your-email'            => 'person@example.com',
			)
		);

		$this->assertSame(
			array(
				'first' => array(
					'utm_source' => 'google',
					'gclid'      => 'abc123',
					'ts'         => 1790000000,
				),
				'last'  => array(
					'utm_source'   => 'newsletter',
					'landing_page' => '/offer/',
				),
			),
			$attribution
		);
	}

	public function test_ignores_unknown_fields_and_non_scalar_values(): void {
		$attribution = Attribution::from_request(
			array(
				'lct_first_email'      => 'person@example.com',
				'lct_last_utm_source'  => array( 'google' ),
				'lct_third_utm_source' => 'x',
				'lct_last_utm_medium'  => 'cpc',
			)
		);

		$this->assertSame( array(), $attribution['first'] );
		$this->assertSame( array( 'utm_medium' => 'cpc' ), $attribution['last'] );
	}

	public function test_strips_markup_and_caps_the_length(): void {
		$attribution = Attribution::from_request(
			array(
				'lct_last_utm_source'   => '<script>alert(1)</script>google',
				'lct_last_utm_campaign' => str_repeat( 'a', 500 ),
				'lct_last_ts'           => 'not-a-number',
			)
		);

		$this->assertSame( 'alert(1)google', $attribution['last']['utm_source'] );
		$this->assertSame( Attribution::MAX_LENGTH, strlen( $attribution['last']['utm_campaign'] ) );
		$this->assertArrayNotHasKey( 'ts', $attribution['last'] );
	}

	public function test_falls_back_to_the_cookie_when_the_form_has_no_fields(): void {
		$cookie = json_encode(
			array(
				'first' => array( 'utm_source' => 'bing', 'msclkid' => 'ms1', 'evil' => 'x' ),
				'last'  => array( 'utm_source' => '<b>bing</b>' ),
				'exp'   => 1890000000000,
			)
		);

		$attribution = Attribution::from_request( array( 'your-name' => 'A' ), $cookie );

		$this->assertSame( array( 'utm_source' => 'bing', 'msclkid' => 'ms1' ), $attribution['first'] );
		$this->assertSame( array( 'utm_source' => 'bing' ), $attribution['last'] );
	}

	public function test_hidden_fields_win_over_the_cookie(): void {
		$cookie = json_encode( array( 'first' => array( 'utm_source' => 'cookie' ) ) );

		$attribution = Attribution::from_request( array( 'lct_first_utm_source' => 'field' ), $cookie );

		$this->assertSame( 'field', $attribution['first']['utm_source'] );
	}

	/**
	 * @dataProvider bad_cookie_provider
	 */
	public function test_a_broken_cookie_gives_empty_attribution( string $cookie ): void {
		$this->assertSame(
			array(
				'first' => array(),
				'last'  => array(),
			),
			Attribution::from_request( array(), $cookie )
		);
	}

	public static function bad_cookie_provider(): array {
		return array(
			'not json'     => array( '{broken' ),
			'json string'  => array( '"hello"' ),
			'wrong shape'  => array( '{"first":"x","last":[1,2]}' ),
			'empty string' => array( '' ),
		);
	}
}
