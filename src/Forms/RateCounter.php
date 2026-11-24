<?php
/**
 * Atomic counters for the form rate limit.
 *
 * @package Glaciate
 */

namespace Glaciate\Forms;

defined( 'ABSPATH' ) || exit;

use Glaciate\Storage\FormRateRepository;

/**
 * Counts hits per key in a fixed window, so that parallel requests can't all slip under the limit (D60).
 *
 * With a persistent object cache (Redis, Memcached), wp_cache_add() starts the counter with the window as its expiry
 * and wp_cache_incr() counts; both are single atomic operations there. Without one, a row in the form_rate table is
 * counted with one upsert (FormRateRepository). WordPress's own in-memory cache lasts one request, so it is never used.
 */
final class RateCounter {

	/** Object cache group. */
	const GROUP = 'glaciate_form_rate';

	/**
	 * Counter rows, when there is no persistent object cache.
	 *
	 * @var FormRateRepository
	 */
	private $rows;

	/**
	 * Constructor.
	 *
	 * @param FormRateRepository|null $rows Counter rows; null for this site's.
	 */
	public function __construct( ?FormRateRepository $rows = null ) {
		$this->rows = $rows ?? new FormRateRepository();
	}

	/**
	 * Counts one hit and returns the hits in the current window, this one included.
	 *
	 * @param string $key    Counter key; already hashed, it is stored as given.
	 * @param int    $window Window, in seconds.
	 * @param int    $now    Current Unix time.
	 * @return int Hits so far; 0 when nothing could count it.
	 */
	public function hit( string $key, int $window, int $now ): int {
		$window = max( 1, $window );
		if ( ! wp_using_ext_object_cache() ) {
			return $this->rows->hit( $key, $window, $now );
		}
		// add() does nothing when the counter exists, so only the first hit sets the window.
		wp_cache_add( $key, 0, self::GROUP, $window );
		$hits = wp_cache_incr( $key, 1, self::GROUP );
		if ( false === $hits ) {
			// Evicted between the two calls: start again with this hit.
			wp_cache_set( $key, 1, self::GROUP, $window );
			return 1;
		}
		return (int) $hits;
	}
}
