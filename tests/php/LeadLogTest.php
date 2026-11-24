<?php

declare( strict_types=1 );

namespace LCT\Tests;

use LCT\LeadLog;

final class LeadLogTest extends TestCase {

	public function test_entries_are_stored_oldest_first(): void {
		$log = new LeadLog();
		$log->add( array( 'event_id' => 'first-id-0001' ) );
		$log->add( array( 'event_id' => 'second-id-0002' ) );

		$this->assertSame( array( 'first-id-0001', 'second-id-0002' ), array_column( $log->all(), 'event_id' ) );
	}

	public function test_the_log_is_capped(): void {
		$log = new LeadLog();

		for ( $i = 1; $i <= LeadLog::MAX_ENTRIES + 5; $i++ ) {
			$log->add( array( 'event_id' => 'event-id-' . $i ) );
		}

		$entries = $log->all();

		$this->assertCount( LeadLog::MAX_ENTRIES, $entries );
		$this->assertSame( 'event-id-6', $entries[0]['event_id'] );
		$this->assertSame( 'event-id-105', $entries[ LeadLog::MAX_ENTRIES - 1 ]['event_id'] );
	}

	public function test_a_corrupt_option_reads_as_an_empty_log_and_clear_removes_it(): void {
		$this->options[ LeadLog::OPTION ] = 'broken';

		$log = new LeadLog();
		$this->assertSame( array(), $log->all() );

		$log->add( array( 'event_id' => 'event-id-1' ) );
		$log->clear();

		$this->assertArrayNotHasKey( LeadLog::OPTION, $this->options );
	}
}
