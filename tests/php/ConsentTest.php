<?php

declare( strict_types=1 );

namespace LCT\Tests;

use Brain\Monkey\Filters;
use LCT\Consent;
use LCT\Settings;

final class ConsentTest extends TestCase {

	public function test_without_the_consent_api_the_default_is_no_consent(): void {
		$consent = new Consent( null, false );

		$this->assertFalse( $consent->uses_api() );
		$this->assertFalse( $consent->has_consent() );
	}

	public function test_without_the_consent_api_the_assume_consent_setting_applies(): void {
		$this->options[ Settings::OPTION ] = array( 'assume_consent' => true );

		$this->assertTrue( ( new Consent( null, false ) )->has_consent() );
	}

	public function test_the_consent_api_is_asked_for_the_marketing_category(): void {
		$asked   = array();
		$consent = new Consent(
			static function ( $category ) use ( &$asked ) {
				$asked[] = $category;
				return true;
			}
		);

		$this->assertTrue( $consent->uses_api() );
		$this->assertTrue( $consent->has_consent() );
		$this->assertSame( array( 'marketing' ), $asked );
	}

	public function test_a_denial_from_the_consent_api_beats_the_assume_consent_setting(): void {
		$this->options[ Settings::OPTION ] = array( 'assume_consent' => true );

		$this->assertFalse( ( new Consent( static fn() => false ) )->has_consent() );
	}

	public function test_the_filter_can_grant_consent(): void {
		Filters\expectApplied( 'lct_has_consent' )->once()->with( false, 'marketing' )->andReturn( true );

		$this->assertTrue( ( new Consent( null, false ) )->has_consent() );
	}

	public function test_the_filter_can_deny_consent(): void {
		Filters\expectApplied( 'lct_has_consent' )->once()->with( true, 'marketing' )->andReturn( false );

		$this->assertFalse( ( new Consent( static fn() => true ) )->has_consent() );
	}
}
