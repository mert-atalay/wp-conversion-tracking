<?php

declare( strict_types=1 );

namespace LCT\Tests;

use LCT\Payload;

final class PayloadTest extends TestCase {

	private function lead(): array {
		return array(
			'event_name'  => 'generate_lead',
			'event_id'    => 'browser-id-12345',
			'timestamp'   => 1790000000,
			'form_id'     => '42',
			'form_type'   => 'cf7',
			'attribution' => array(
				'first' => array( 'utm_source' => 'google', 'gclid' => 'abc123' ),
				'last'  => array(),
			),
		);
	}

	public function test_build_returns_the_documented_shape(): void {
		$payload = Payload::build( $this->lead(), 'person@example.com', false, 'https://example.com' );

		$this->assertSame(
			array( 'event_name', 'event_id', 'timestamp', 'form_id', 'form_type', 'site_url', 'attribution' ),
			array_keys( $payload )
		);
		$this->assertSame( 'generate_lead', $payload['event_name'] );
		$this->assertSame( 'browser-id-12345', $payload['event_id'] );
		$this->assertSame( 1790000000, $payload['timestamp'] );
		$this->assertSame( '42', $payload['form_id'] );
		$this->assertSame( 'cf7', $payload['form_type'] );
		$this->assertSame( 'https://example.com', $payload['site_url'] );
	}

	public function test_empty_touches_encode_as_json_objects(): void {
		$json = json_encode( Payload::build( $this->lead(), '', false, 'https://example.com' ) );

		$this->assertStringContainsString( '"first":{"utm_source":"google","gclid":"abc123"}', $json );
		$this->assertStringContainsString( '"last":{}', $json );
	}

	public function test_email_is_left_out_unless_hashing_is_enabled(): void {
		$payload = Payload::build( $this->lead(), 'person@example.com', false, 'https://example.com' );

		$this->assertArrayNotHasKey( 'user_data', $payload );
		$this->assertStringNotContainsString( 'person', json_encode( $payload ) );
	}

	public function test_enabled_hashing_adds_only_the_hash(): void {
		$payload = Payload::build( $this->lead(), 'person@example.com', true, 'https://example.com' );

		$this->assertSame(
			array( 'sha256_email' => hash( 'sha256', 'person@example.com' ) ),
			$payload['user_data']
		);
		$this->assertStringNotContainsString( 'person@example.com', json_encode( $payload ) );
	}

	public function test_enabled_hashing_without_a_valid_email_adds_nothing(): void {
		$this->assertArrayNotHasKey( 'user_data', Payload::build( $this->lead(), '', true, 'https://example.com' ) );
		$this->assertArrayNotHasKey( 'user_data', Payload::build( $this->lead(), 'not-an-email', true, 'https://example.com' ) );
	}

	public function test_hash_email_normalises_case_and_whitespace(): void {
		$expected = '973dfe463ec85785f5f95af5ba3906eedb2d931c24e69824a89ea65dba4e813b';

		$this->assertSame( $expected, hash( 'sha256', 'test@example.com' ), 'Known SHA-256 of test@example.com.' );
		$this->assertSame( $expected, Payload::hash_email( 'test@example.com' ) );
		$this->assertSame( $expected, Payload::hash_email( "  Test@Example.COM \n" ) );
	}

	public function test_hash_email_returns_64_hex_characters_or_an_empty_string(): void {
		$this->assertMatchesRegularExpression( '/^[0-9a-f]{64}$/', Payload::hash_email( 'a@b.co' ) );
		$this->assertSame( '', Payload::hash_email( '' ) );
		$this->assertSame( '', Payload::hash_email( 'nope' ) );
	}
}
