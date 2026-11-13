<?php
/**
 * How long a build waits, and how many requests it sends at once, after the site says it is overloaded.
 *
 * @package Glaciate
 */

namespace Glaciate\Build;

defined( 'ABSPATH' ) || exit;

/**
 * When the origin answers 429 or 5xx, the build stops sending requests for a while and continues with half as many
 * at once. The wait honors `Retry-After` (up to MAX_WAIT seconds); without it, it doubles from FIRST_WAIT seconds
 * with each back-off in a row, up to MAX_STEP.
 *
 * Pure; no WordPress calls.
 */
final class Backoff {

	/** Attempts per address before it counts as failed. */
	const MAX_ATTEMPTS = 3;

	/** First wait without Retry-After, in seconds. */
	const FIRST_WAIT = 5;

	/** Longest wait without Retry-After, in seconds. */
	const MAX_STEP = 120;

	/** Longest wait at all, in seconds, however long Retry-After asks for. */
	const MAX_WAIT = 300;

	/**
	 * Seconds to wait.
	 *
	 * @param int      $count       Back-offs in a row, including this one (1 for the first).
	 * @param int|null $retry_after Longest Retry-After the site sent, in seconds, or null.
	 * @return int
	 */
	public static function delay( int $count, ?int $retry_after ): int {
		if ( null !== $retry_after ) {
			return max( 0, min( self::MAX_WAIT, $retry_after ) );
		}
		$exponent = max( 0, min( 10, $count - 1 ) );
		return min( self::MAX_STEP, self::FIRST_WAIT * ( 2 ** $exponent ) );
	}

	/**
	 * Requests at once after a back-off.
	 *
	 * @param int $current Requests at once now.
	 * @return int
	 */
	public static function concurrency( int $current ): int {
		return max( 1, intdiv( $current, 2 ) );
	}

	/**
	 * Is this answer the site saying it is overloaded or briefly unavailable?
	 *
	 * @param int $status HTTP status.
	 * @return bool
	 */
	public static function is_overloaded( int $status ): bool {
		return in_array( $status, array( 429, 500, 502, 503, 504 ), true );
	}

	/**
	 * Parses a Retry-After header: seconds, or an HTTP date.
	 *
	 * @param string $value Header value.
	 * @param int    $now   Current Unix time.
	 * @return int|null Seconds from now, or null when absent or unreadable.
	 */
	public static function retry_after( string $value, int $now ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}
		$time = strtotime( $value );
		return false === $time ? null : max( 0, $time - $now );
	}
}
