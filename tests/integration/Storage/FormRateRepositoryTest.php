<?php
/**
 * Form rate-limit counters: one upsert counts and reads, windows restart, old rows are pruned.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Storage;

use Glaciate\Forms\RateCounter;
use Glaciate\Storage\FormRateRepository;
use Glaciate\Storage\Schema;

final class FormRateRepositoryTest extends \WP_UnitTestCase {

	const NOW = 2000000000;

	public function test_hits_count_up_within_the_window(): void {
		$rows = new FormRateRepository();
		$this->assertSame( 1, $rows->hit( 'a', 600, self::NOW ) );
		$this->assertSame( 2, $rows->hit( 'a', 600, self::NOW + 1 ) );
		$this->assertSame( 3, $rows->hit( 'a', 600, self::NOW + 599 ) );
	}

	public function test_each_key_has_its_own_count(): void {
		$rows = new FormRateRepository();
		$rows->hit( 'a', 600, self::NOW );
		$rows->hit( 'a', 600, self::NOW );
		// A new row in between must not leak into the next count read for another key.
		$this->assertSame( 1, $rows->hit( 'b', 600, self::NOW ) );
		$this->assertSame( 3, $rows->hit( 'a', 600, self::NOW ) );
		$this->assertSame( 2, $rows->hit( 'b', 600, self::NOW ) );
	}

	public function test_a_new_window_starts_after_the_last_one_ended(): void {
		$rows = new FormRateRepository();
		$rows->hit( 'a', 600, self::NOW );
		$rows->hit( 'a', 600, self::NOW + 10 );
		$this->assertSame( 1, $rows->hit( 'a', 600, self::NOW + 600 ), 'the window ends 600 seconds after its first hit' );
		$this->assertSame( 2, $rows->hit( 'a', 600, self::NOW + 1199 ) );
		$this->assertSame( 1, $rows->hit( 'a', 600, self::NOW + 1200 ) );
	}

	public function test_the_count_is_what_is_stored(): void {
		global $wpdb;
		$rows = new FormRateRepository();
		for ( $i = 0; $i < 7; $i++ ) {
			$last = $rows->hit( 'a', 600, self::NOW );
		}
		$this->assertSame( 7, $last );
		$this->assertSame( 7, (int) $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE counter_key = %s', Schema::table( 'form_rate' ), 'a' ) ) );
	}

	public function test_starting_a_counter_prunes_ended_windows(): void {
		$rows = new FormRateRepository();
		$rows->hit( 'old-1', 60, self::NOW );
		$rows->hit( 'old-2', 60, self::NOW );
		$rows->hit( 'live', 600, self::NOW );
		$this->assertSame( 3, $rows->count() );

		$rows->hit( 'live', 600, self::NOW + 120 );
		$this->assertSame( 3, $rows->count(), 'counting an existing row prunes nothing' );

		$rows->hit( 'new', 600, self::NOW + 120 );
		$this->assertSame( 2, $rows->count(), 'the two ended rows are gone' );
		$this->assertSame( 3, $rows->hit( 'live', 600, self::NOW + 121 ), 'live counters stay' );
	}

	public function test_the_counter_uses_the_table_without_a_persistent_object_cache(): void {
		$rows    = new FormRateRepository();
		$counter = new RateCounter( $rows );
		$this->assertSame( 1, $counter->hit( 'c', 600, self::NOW ) );
		$this->assertSame( 2, $counter->hit( 'c', 600, self::NOW ) );
		$this->assertSame( 1, $rows->count() );
	}

	public function test_the_counter_uses_a_persistent_object_cache_when_there_is_one(): void {
		$rows     = new FormRateRepository();
		$counter  = new RateCounter( $rows );
		$previous = wp_using_ext_object_cache( true );
		try {
			$this->assertSame( 1, $counter->hit( 'cache-key', 600, self::NOW ) );
			$this->assertSame( 2, $counter->hit( 'cache-key', 600, self::NOW ) );
			$this->assertSame( 2, wp_cache_get( 'cache-key', RateCounter::GROUP ) );

			wp_cache_delete( 'cache-key', RateCounter::GROUP );
			$this->assertSame( 1, $counter->hit( 'cache-key', 600, self::NOW ), 'an evicted counter starts again' );
		} finally {
			wp_using_ext_object_cache( $previous );
			wp_cache_delete( 'cache-key', RateCounter::GROUP );
		}
		$this->assertSame( 0, $rows->count(), 'nothing went to the table' );
	}
}
