<?php
/**
 * Remembers which relay signatures were accepted.
 *
 * @package Glaciate
 */

namespace Glaciate\Forms;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- The check must be one atomic insert; the options API reads first.

/**
 * The relay's once-only check (T22, D64). Claiming a signature and finding out whether it was already claimed is one
 * atomic step, so two copies of a request that arrive together can't both pass:
 *
 * - With a persistent object cache, `wp_cache_add()`, which the usual back ends (Redis, Memcached, APCu) implement as
 *   an atomic add-if-absent.
 * - Without one, an `INSERT IGNORE` of a row into the options table, whose unique key on `option_name` lets exactly
 *   one insert through. The row is never read through the options API, so no cache can hide it, and nothing is
 *   autoloaded. Rows whose signature can no longer verify are deleted on the next claim.
 *
 * A marker lives until its signature's window closes, which is all it needs: after that, Signature::verify() refuses
 * the request on time alone.
 */
final class ReplayGuard {

	/** Option name prefix of the markers, and their object cache key prefix. */
	const PREFIX = 'glaciate_form_seen_';

	/** Object cache group. */
	const GROUP = 'glaciate_forms';

	/**
	 * Claims a signature. True only for the first claim while the signature can still verify.
	 *
	 * @param int    $time The signature's time (Signature::parse()).
	 * @param string $mac  The signature's HMAC (Signature::parse()).
	 * @param int    $now  Current Unix time.
	 * @return bool
	 */
	public function claim( int $time, string $mac, int $now ): bool {
		$name    = self::PREFIX . substr( hash( 'sha256', $time . '.' . $mac ), 0, 40 );
		$expires = $time + Signature::TOLERANCE;

		if ( wp_using_ext_object_cache() ) {
			// At least one second: an expiry of zero means "never" to the object cache.
			return (bool) wp_cache_add( $name, 1, self::GROUP, max( 1, $expires - $now + 1 ) );
		}

		global $wpdb;
		$this->prune( $now );
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
				$wpdb->options,
				$name,
				(string) $expires
			)
		);
		// One row inserted: the first claim. Zero: someone was first. False: a database error, so refuse.
		return 1 === $inserted;
	}

	/**
	 * Deletes the markers of signatures that can no longer verify.
	 *
	 * @param int $now Current Unix time.
	 * @return void
	 */
	public function prune( int $now ): void {
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE option_name LIKE %s AND CAST(option_value AS UNSIGNED) < %d',
				$wpdb->options,
				$wpdb->esc_like( self::PREFIX ) . '%',
				$now
			)
		);
	}
}
