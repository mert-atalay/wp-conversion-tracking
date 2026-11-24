<?php

declare( strict_types=1 );

namespace LCT\Tests;

use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use LCT\Consent;
use LCT\Forwarder;
use LCT\LeadHandler;
use LCT\LeadLog;
use LCT\Settings;

final class LeadHandlerTest extends TestCase {

	/** Cookies written through the injected cookie setter. */
	private array $cookies = array();

	private function handler( bool $consent ): LeadHandler {
		$this->cookies = array();

		return new LeadHandler(
			new Consent( static fn() => $consent ),
			new Forwarder(),
			new LeadLog(),
			function ( string $name, string $value, int $ttl ): void {
				$this->cookies[ $name ] = array( $value, $ttl );
			}
		);
	}

	private function enable_forwarding( bool $hash_email = false ): void {
		$this->options[ Settings::OPTION ] = array(
			'forward_enabled' => true,
			'endpoint_url'    => 'https://collect.example.com/lead',
			'shared_secret'   => 's3cret',
			'hash_email'      => $hash_email,
		);
	}

	public function test_without_consent_nothing_is_logged_sent_or_announced(): void {
		$this->enable_forwarding( true );

		Functions\expect( 'wp_remote_post' )->never();
		Actions\expectDone( 'lct_lead_captured' )->never();

		$lead = $this->handler( false )->process(
			'42',
			'cf7',
			'person@example.com',
			array(
				'lct_event_id'        => 'browser-id-12345',
				'lct_last_utm_source' => 'google',
			)
		);

		$this->assertNull( $lead );
		$this->assertSame( array(), ( new LeadLog() )->all() );
		$this->assertSame( array(), $this->cookies );
	}

	public function test_the_browser_event_id_is_kept_and_stored_with_the_lead(): void {
		Functions\expect( 'wp_remote_post' )->never();
		Actions\expectDone( 'lct_lead_captured' )->once();

		$lead = $this->handler( true )->process(
			'42',
			'cf7',
			'',
			array(
				'lct_event_id'        => 'browser-id-12345',
				'lct_last_utm_source' => 'google',
				'your-message'        => 'Hello',
			)
		);

		$this->assertSame( 'browser-id-12345', $lead['event_id'] );
		$this->assertSame( 'generate_lead', $lead['event_name'] );
		$this->assertSame( '42', $lead['form_id'] );
		$this->assertSame( 'cf7', $lead['form_type'] );
		$this->assertSame( array( 'utm_source' => 'google' ), $lead['attribution']['last'] );
		$this->assertEqualsWithDelta( time(), $lead['timestamp'], 5 );

		$logged = ( new LeadLog() )->all();
		$this->assertCount( 1, $logged );
		$this->assertSame( 'browser-id-12345', $logged[0]['event_id'] );
		$this->assertStringNotContainsString( 'Hello', json_encode( $logged ) );
	}

	public function test_an_invalid_or_missing_event_id_is_replaced_on_the_server(): void {
		$handler = $this->handler( true );

		$invalid = $handler->process( '42', 'cf7', '', array( 'lct_event_id' => '<script>' ) );
		$missing = $handler->process( '42', 'cf7', '', array() );

		$this->assertSame( '11111111-2222-4333-8444-555555555555', $invalid['event_id'] );
		$this->assertSame( '11111111-2222-4333-8444-555555555555', $missing['event_id'] );
	}

	public function test_the_pending_cookie_carries_the_same_event_id_for_the_browser(): void {
		$this->handler( true )->process( '7', 'wpforms', '', array( 'lct_event_id' => 'browser-id-12345' ) );

		$this->assertArrayHasKey( LeadHandler::PENDING_COOKIE, $this->cookies );

		list( $value, $ttl ) = $this->cookies[ LeadHandler::PENDING_COOKIE ];

		$this->assertSame(
			array(
				'event_id'  => 'browser-id-12345',
				'form_id'   => '7',
				'form_type' => 'wpforms',
			),
			json_decode( $value, true )
		);
		$this->assertSame( LeadHandler::PENDING_TTL, $ttl );
	}

	public function test_the_forwarded_payload_uses_the_same_event_id_and_no_email_by_default(): void {
		$this->enable_forwarding();
		$body = null;

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$body ) {
					$body = $args['body'];
					return array();
				}
			);

		$this->handler( true )->process( '42', 'cf7', 'person@example.com', array( 'lct_event_id' => 'browser-id-12345' ) );

		$payload = json_decode( $body, true );

		$this->assertSame( 'browser-id-12345', $payload['event_id'] );
		$this->assertSame( 'https://example.com', $payload['site_url'] );
		$this->assertArrayNotHasKey( 'user_data', $payload );
		$this->assertStringNotContainsString( 'person', $body );
	}

	public function test_the_forwarded_payload_has_the_hashed_email_when_enabled(): void {
		$this->enable_forwarding( true );
		$body = null;

		Functions\expect( 'wp_remote_post' )
			->once()
			->andReturnUsing(
				static function ( $url, $args ) use ( &$body ) {
					$body = $args['body'];
					return array();
				}
			);

		$this->handler( true )->process( '42', 'cf7', 'Person@Example.com', array() );

		$payload = json_decode( $body, true );

		$this->assertSame( hash( 'sha256', 'person@example.com' ), $payload['user_data']['sha256_email'] );
		$this->assertStringNotContainsStringIgnoringCase( 'person@example.com', $body );
	}

	public function test_the_log_can_be_turned_off(): void {
		$this->options[ Settings::OPTION ] = array( 'log_enabled' => false );

		$this->handler( true )->process( '42', 'cf7', '', array() );

		$this->assertSame( array(), ( new LeadLog() )->all() );
	}

	public function test_form_id_and_type_are_reduced_to_key_characters(): void {
		$lead = $this->handler( true )->process( '42"><b>', 'My Plugin!', '', array() );

		$this->assertSame( '42b', $lead['form_id'] );
		$this->assertSame( 'myplugin', $lead['form_type'] );
	}

	public function test_the_payload_filter_can_cancel_the_forward(): void {
		$this->enable_forwarding();

		Filters\expectApplied( 'lct_server_payload' )->once()->andReturn( false );
		Functions\expect( 'wp_remote_post' )->never();

		$this->assertIsArray( $this->handler( true )->process( '42', 'cf7', '', array() ) );
	}
}
