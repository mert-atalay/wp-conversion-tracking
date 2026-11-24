<?php

declare( strict_types=1 );

namespace LCT\Tests;

use LCT\EventId;

final class EventIdTest extends TestCase {

	public function test_a_uuid_is_valid(): void {
		$this->assertTrue( EventId::is_valid( '3f2b8c1e-9a4d-4c7b-8e21-0a1b2c3d4e5f' ) );
	}

	/**
	 * @dataProvider invalid_provider
	 */
	public function test_unsafe_or_malformed_values_are_invalid( $value ): void {
		$this->assertFalse( EventId::is_valid( $value ) );
	}

	public static function invalid_provider(): array {
		return array(
			'empty'     => array( '' ),
			'too short' => array( 'abc123' ),
			'too long'  => array( str_repeat( 'a', 65 ) ),
			'html'      => array( '<script>alert(1)</script>' ),
			'spaces'    => array( 'abcd efgh ijkl' ),
			'newline'   => array( "abcdefgh\n" ),
			'null'      => array( null ),
			'integer'   => array( 1234567890 ),
			'array'     => array( array( 'abcdefgh' ) ),
		);
	}

	public function test_resolve_keeps_a_valid_browser_id(): void {
		$this->assertSame( 'browser-id-12345', EventId::resolve( 'browser-id-12345' ) );
	}

	public function test_resolve_replaces_an_invalid_id_with_a_new_uuid(): void {
		$resolved = EventId::resolve( '"><img src=x>' );

		$this->assertSame( '11111111-2222-4333-8444-555555555555', $resolved );
		$this->assertTrue( EventId::is_valid( $resolved ) );
	}

	public function test_resolve_generates_an_id_when_none_was_sent(): void {
		$this->assertTrue( EventId::is_valid( EventId::resolve( null ) ) );
	}
}
