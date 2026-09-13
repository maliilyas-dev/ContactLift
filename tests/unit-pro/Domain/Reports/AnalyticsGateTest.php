<?php
/**
 * AT19 from the spec's acceptance suite.
 *
 * @package ContactLiftPro
 */

namespace ContactLiftPro\Tests\Unit\Domain\Reports;

use ContactLiftPro\Domain\Reports\AnalyticsGate;
use PHPUnit\Framework\TestCase;

final class AnalyticsGateTest extends TestCase {

	public function test_off_mode_never_records(): void {
		self::assertFalse( AnalyticsGate::is_recording_allowed( 'off', false, false ) );
	}

	public function test_at19_a_global_privacy_control_signal_disables_analytics_even_in_local_aggregate_mode(): void {
		self::assertFalse( AnalyticsGate::is_recording_allowed( 'local_aggregate', true, false ) );
	}

	public function test_at19_administrators_are_excluded_by_default(): void {
		self::assertFalse( AnalyticsGate::is_recording_allowed( 'local_aggregate', false, true ) );
	}

	public function test_local_aggregate_mode_records_for_an_ordinary_visitor(): void {
		self::assertTrue( AnalyticsGate::is_recording_allowed( 'local_aggregate', false, false ) );
	}

	public function test_consent_required_mode_records_at_the_server_layer(): void {
		// The client-side consent gate (window.ContactLift.setAnalyticsConsent)
		// decides whether an event is ever sent at all; the server side of
		// this mode only needs to confirm reporting itself is on.
		self::assertTrue( AnalyticsGate::is_recording_allowed( 'consent_required', false, false ) );
	}

	public function test_an_unknown_mode_does_not_record(): void {
		self::assertFalse( AnalyticsGate::is_recording_allowed( 'not_a_real_mode', false, false ) );
	}
}
