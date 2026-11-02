<?php
/**
 * The set of URLs a build still has to process.
 *
 * @package Stillpress
 */

namespace Stillpress\Build;

use Stillpress\Storage\Schema;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table; work queue state must always be read fresh.

/**
 * A per-build work queue stored in the build_urls table. Because it lives in the database, a build can stop at
 * any point (timeout, crash, deploy) and resume exactly where it was.
 *
 * States: pending, running, done, failed, skipped.
 */
final class Frontier {

	/**
	 * Build ID.
	 *
	 * @var int
	 */
	private $build_id;

	/**
	 * URLs per state, read once and then kept up to date by this object's own writes (a slice holds the build lock, so
	 * no one else writes this build's queue meanwhile). Null until first needed.
	 *
	 * @var array<string,int>|null
	 */
	private $counts = null;

	/**
	 * Constructor.
	 *
	 * @param int $build_id Build ID.
	 */
	public function __construct( int $build_id ) {
		$this->build_id = $build_id;
	}

	/**
	 * Lowers the depth of URLs that are queued and still waiting.
	 *
	 * @param int[] $url_ids URL IDs.
	 * @param int   $depth   Link depth from the seeds.
	 * @return void
	 */
	public function lower( array $url_ids, int $depth ): void {
		global $wpdb;
		$url_ids = array_values( array_unique( array_filter( array_map( 'intval', $url_ids ) ) ) );
		foreach ( array_chunk( $url_ids, 500 ) as $chunk ) {
			$in = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds placeholders only.
				$wpdb->prepare( "UPDATE %i SET depth = %d WHERE build_id = %d AND state = 'pending' AND depth > %d AND url_id IN ($in)", array_merge( array( Schema::table( 'build_urls' ), $depth, $this->build_id, $depth ), $chunk ) )
			);
		}
	}

	/**
	 * Adds URLs to the queue. A URL already queued in this build keeps its place, but takes the lower depth while it
	 * waits, so the depth limit counts the shortest path to it.
	 *
	 * @param int[] $url_ids URL IDs.
	 * @param int   $depth   Link depth from the seeds.
	 * @return int Number of URLs newly queued.
	 */
	public function add( array $url_ids, int $depth = 0 ): int {
		global $wpdb;
		$url_ids = array_values( array_unique( array_filter( array_map( 'intval', $url_ids ) ) ) );
		$added   = 0;
		foreach ( array_chunk( $url_ids, 500 ) as $chunk ) {
			$placeholders = array();
			$values       = array( Schema::table( 'build_urls' ) );
			foreach ( $chunk as $id ) {
				$placeholders[] = '(%d, %d, %d)';
				$values[]       = $this->build_id;
				$values[]       = $id;
				$values[]       = $depth;
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
			$inserted = (int) $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (build_id, url_id, depth) VALUES ' . implode( ', ', $placeholders ), $values ) );
			$added   += $inserted;
			if ( $inserted < count( $chunk ) ) {
				$this->lower( $chunk, $depth );
			}
		}
		if ( null !== $this->counts ) {
			$this->counts['pending'] += $added;
		}
		return $added;
	}

	/**
	 * Claims the next pending URLs.
	 *
	 * @param int $limit Maximum number.
	 * @return array<int,int> URL ID => depth.
	 */
	public function claim( int $limit ): array {
		global $wpdb;
		$table = Schema::table( 'build_urls' );
		$rows  = $wpdb->get_results(
			$wpdb->prepare( "SELECT url_id, depth FROM %i WHERE build_id = %d AND state = 'pending' ORDER BY depth ASC, url_id ASC LIMIT %d", $table, $this->build_id, $limit ),
			ARRAY_A
		);
		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['url_id'] ] = (int) $row['depth'];
		}
		if ( $out ) {
			$in = implode( ', ', array_fill( 0, count( $out ), '%d' ) );
			$wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds placeholders only.
				$wpdb->prepare( "UPDATE %i SET state = 'running', attempts = attempts + 1 WHERE build_id = %d AND url_id IN ($in)", array_merge( array( $table, $this->build_id ), array_keys( $out ) ) )
			);
			if ( null !== $this->counts ) {
				$this->counts['pending'] -= count( $out );
				$this->counts['running'] += count( $out );
			}
		}
		return $out;
	}

	/**
	 * Sets the state of claimed (running) URLs.
	 *
	 * @param int[]  $url_ids URL IDs.
	 * @param string $state   done, failed or skipped.
	 * @return void
	 */
	public function mark( array $url_ids, string $state ): void {
		global $wpdb;
		$url_ids = array_values( array_unique( array_map( 'intval', $url_ids ) ) );
		foreach ( array_chunk( $url_ids, 500 ) as $chunk ) {
			$in      = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$changed = (int) $wpdb->query(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds placeholders only.
				$wpdb->prepare( "UPDATE %i SET state = %s WHERE build_id = %d AND state = 'running' AND url_id IN ($in)", array_merge( array( Schema::table( 'build_urls' ), $state, $this->build_id ), $chunk ) )
			);
			if ( null !== $this->counts ) {
				$this->counts['running'] -= $changed;
				$this->counts[ $state ]  += $changed;
			}
		}
	}

	/**
	 * Puts URLs left "running" by an interrupted slice back to pending, unless they failed too often.
	 *
	 * @param int $max_attempts Attempts before giving up on a URL.
	 * @return void
	 */
	public function recover( int $max_attempts = 3 ): void {
		global $wpdb;
		$table = Schema::table( 'build_urls' );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'failed' WHERE build_id = %d AND state = 'running' AND attempts >= %d", $table, $this->build_id, $max_attempts ) );
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET state = 'pending' WHERE build_id = %d AND state = 'running'", $table, $this->build_id ) );
		$this->counts = null;
	}

	/**
	 * True when the URL is already in this build's queue (in any state).
	 *
	 * @param int $url_id URL ID.
	 * @return bool
	 */
	public function has( int $url_id ): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i WHERE build_id = %d AND url_id = %d', Schema::table( 'build_urls' ), $this->build_id, $url_id ) );
	}

	/**
	 * The URLs not in this build's queue yet.
	 *
	 * @param int[] $url_ids URL IDs.
	 * @return int[]
	 */
	public function missing( array $url_ids ): array {
		global $wpdb;
		$url_ids = array_values( array_unique( array_map( 'intval', $url_ids ) ) );
		$queued  = array();
		foreach ( array_chunk( $url_ids, 500 ) as $chunk ) {
			$in     = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
			$queued = array_merge(
				$queued,
				(array) $wpdb->get_col(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $in holds placeholders only.
					$wpdb->prepare( "SELECT url_id FROM %i WHERE build_id = %d AND url_id IN ($in)", array_merge( array( Schema::table( 'build_urls' ), $this->build_id ), $chunk ) )
				)
			);
		}
		return array_values( array_diff( $url_ids, array_map( 'intval', $queued ) ) );
	}

	/**
	 * Number of URLs in each state.
	 *
	 * @return array<string,int>
	 */
	public function counts(): array {
		global $wpdb;
		if ( null !== $this->counts ) {
			return $this->counts;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT state, COUNT(*) AS n FROM %i WHERE build_id = %d GROUP BY state', Schema::table( 'build_urls' ), $this->build_id ),
			ARRAY_A
		);
		$out = array(
			'pending' => 0,
			'running' => 0,
			'done'    => 0,
			'failed'  => 0,
			'skipped' => 0,
		);
		foreach ( (array) $rows as $row ) {
			$out[ $row['state'] ] = (int) $row['n'];
		}
		$this->counts = $out;
		return $out;
	}

	/**
	 * Total URLs queued in this build.
	 *
	 * @return int
	 */
	public function total(): int {
		return array_sum( $this->counts() );
	}

	/**
	 * URL IDs processed in this build with the given state.
	 *
	 * @param string $state State.
	 * @return int[]
	 */
	public function ids_in_state( string $state ): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col( $wpdb->prepare( 'SELECT url_id FROM %i WHERE build_id = %d AND state = %s', Schema::table( 'build_urls' ), $this->build_id, $state ) )
		);
	}

	/**
	 * Deletes this build's queue (after the build finishes).
	 *
	 * @return void
	 */
	public function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE build_id = %d', Schema::table( 'build_urls' ), $this->build_id ) );
		$this->counts = null;
	}
}
