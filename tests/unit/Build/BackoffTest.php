<?php
/**
 * Back-off after the origin says it is overloaded.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use Glaciate\Build\Backoff;

final class BackoffTest extends TestCase {

	public function test_delay_doubles_and_is_capped(): void {
		$this->assertSame( 5, Backoff::delay( 1, null ) );
		$this->assertSame( 10, Backoff::delay( 2, null ) );
		$this->assertSame( 20, Backoff::delay( 3, null ) );
		$this->assertSame( 120, Backoff::delay( 6, null ) );
		$this->assertSame( 120, Backoff::delay( 500, null ) );
	}

	public function test_retry_after_wins_within_the_cap(): void {
		$this->assertSame( 30, Backoff::delay( 4, 30 ) );
		$this->assertSame( 0, Backoff::delay( 1, 0 ) );
		$this->assertSame( 300, Backoff::delay( 1, 600 ), 'WordPress maintenance mode asks for 600 seconds.' );
	}

	public function test_concurrency_halves_down_to_one(): void {
		$this->assertSame( 2, Backoff::concurrency( 4 ) );
		$this->assertSame( 2, Backoff::concurrency( 5 ) );
		$this->assertSame( 1, Backoff::concurrency( 1 ) );
		$this->assertSame( 1, Backoff::concurrency( 0 ) );
	}

	public function test_overload_statuses(): void {
		foreach ( array( 429, 500, 502, 503, 504 ) as $status ) {
			$this->assertTrue( Backoff::is_overloaded( $status ), (string) $status );
		}
		foreach ( array( 0, 200, 301, 404, 410, 501, 505 ) as $status ) {
			$this->assertFalse( Backoff::is_overloaded( $status ), (string) $status );
		}
	}

	public function test_retry_after_parsing(): void {
		$now = 1767225600; // 2026-01-01 00:00:00 UTC.
		$this->assertSame( 120, Backoff::retry_after( '120', $now ) );
		$this->assertSame( 60, Backoff::retry_after( 'Thu, 01 Jan 2026 00:01:00 GMT', $now ) );
		$this->assertSame( 0, Backoff::retry_after( 'Wed, 31 Dec 2025 23:00:00 GMT', $now ) );
		$this->assertNull( Backoff::retry_after( '', $now ) );
		$this->assertNull( Backoff::retry_after( 'soon', $now ) );
	}
}
