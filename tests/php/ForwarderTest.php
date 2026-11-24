<?php

declare( strict_types=1 );

namespace LCT\Tests;

use Brain\Monkey\Functions;
use LCT\Forwarder;

final class ForwarderTest extends TestCase {

	private const PAYLOAD = array(
		'event_name' => 'generate_lead',
		'event_id'   => 'browser-id-12345',
	);

	public function test_nothing_is_sent_when_forwarding_is_off(): void {
		Functions\expect( 'wp_remote_post' )->never();

		$sent = ( new Forwarder() )->send(
			self::PAYLOAD,
			array(
				'forward_enabled' => false,
				'endpoint_url'    => 'https://collect.example.com/lead',
			)
		);

		$this->assertFalse( $sent );
	}

	public function test_nothing_is_sent_to_a_plain_http_endpoint(): void {
		Functions\expect( 'wp_remote_post' )->never();

		$sent = ( new Forwarder() )->send(
			self::PAYLOAD,
			array(
				'forward_enabled' => true,
				'endpoint_url'    => 'http://collect.example.com/lead',
			)
		);

		$this->assertFalse( $sent );
	}

	public function test_the_payload_is_posted_as_json_with_the_secret_header_and_without_blocking(): void {
		$captured = array();

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$captured ) {
					$captured = array( $url, $args );
					return array();
				}
			);

		$sent = ( new Forwarder() )->send(
			self::PAYLOAD,
			array(
				'forward_enabled' => true,
				'endpoint_url'    => 'https://collect.example.com/lead',
				'shared_secret'   => 's3cret',
			)
		);

		$this->assertTrue( $sent );
		$this->assertSame( 'https://collect.example.com/lead', $captured[0] );
		$this->assertFalse( $captured[1]['blocking'] );
		$this->assertSame( 's3cret', $captured[1]['headers']['X-LCT-Secret'] );
		$this->assertStringStartsWith( 'application/json', $captured[1]['headers']['Content-Type'] );
		$this->assertSame( self::PAYLOAD, json_decode( $captured[1]['body'], true ) );
	}
}
