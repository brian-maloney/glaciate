<?php
/**
 * Form rate-limit counters in the database.
 *
 * @package Glaciate
 */

namespace Glaciate\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table; a counter must be read and written in one statement.

/**
 * Rows in the form_rate table: a counter key (a salted hash, never the address), the hits in the current window and
 * when the window ends (Unix time). Used when there is no persistent object cache (D60).
 *
 * Each hit() counts and reads in one statement, so parallel requests each get their own number. The read-compare-write of
 * a transient let them all read the same count.
 */
final class FormRateRepository {

	/** Expired rows removed each time a new counter starts. */
	const PRUNE_BATCH = 100;

	/**
	 * Counts one hit and returns the hits in the current window, this one included. A window starts with the first
	 * hit after the previous one ended.
	 *
	 * @param string $key    Counter key.
	 * @param int    $window Window, in seconds.
	 * @param int    $now    Current Unix time.
	 * @return int Hits so far; 0 when the table can't be written (the caller then doesn't limit).
	 */
	public function hit( string $key, int $window, int $now ): int {
		global $wpdb;
		$table   = Schema::table( 'form_rate' );
		$expires = $now + max( 1, $window );
		// Both IF()s read the old expires_at (hits is assigned first and doesn't change it). LAST_INSERT_ID(expr)
		// hands this connection the new count without a second read another request could slip in front of.
		$affected = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i (counter_key, hits, expires_at) VALUES (%s, 1, %d)
				ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(IF(expires_at <= %d, 1, hits + 1)), expires_at = IF(expires_at <= %d, %d, expires_at)',
				$table,
				$key,
				$expires,
				$now,
				$now,
				$expires
			)
		);
		if ( false === $affected ) {
			return 0;
		}
		if ( 1 === (int) $affected ) {
			// A new row: a new visitor, or the first since this one's row was pruned. Old rows go now, so the table
			// holds about one window's worth of visitors without a scheduled job.
			$this->prune( $now );
			return 1;
		}
		$hits = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
		if ( $hits < 1 ) {
			$hits = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT hits FROM %i WHERE counter_key = %s', $table, $key ) );
		}
		return $hits;
	}

	/**
	 * Removes counters whose window has ended.
	 *
	 * @param int $now   Current Unix time.
	 * @param int $limit At most this many rows.
	 * @return void
	 */
	public function prune( int $now, int $limit = self::PRUNE_BATCH ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE expires_at <= %d LIMIT %d', Schema::table( 'form_rate' ), $now, max( 1, $limit ) ) );
	}

	/**
	 * Number of counters stored, for tests and diagnostics.
	 *
	 * @return int
	 */
	public function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', Schema::table( 'form_rate' ) ) );
	}
}
