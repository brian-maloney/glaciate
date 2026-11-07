<?php
/**
 * Times full and incremental builds of the seeded benchmark site, and reports where the database time goes.
 *
 * Run with: npm run perf -- bench [full|incremental|rebuild|all]
 *
 * Prints one JSON report. Each build lists wall time per phase, the build process's peak memory, its query count
 * and the ten most expensive query shapes (numbers and strings replaced with ?). Render requests run in the web
 * container and aren't in the query figures.
 *
 * @package Glaciate
 */

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.WP.AlternativeFunctions -- Benchmark on a disposable site.

require __DIR__ . '/guard.php';

use Glaciate\Build\BuildService;
use Glaciate\Storage\Schema;
use Glaciate\Support\Paths;
use Glaciate\Support\Settings;

/**
 * Collapses a query to its shape.
 *
 * @param string $sql Query.
 * @return string
 */
function glaciate_perf_digest( string $sql ): string {
	$sql = preg_replace( "/'(?:[^'\\\\]|\\\\.)*'/s", '?', $sql );
	$sql = preg_replace( '/\b\d+\b/', '?', (string) $sql );
	$sql = preg_replace( '/\(\s*\?(?:\s*,\s*\?)+\s*\)/', '(?+)', (string) $sql );
	$sql = preg_replace( '/(?:\(\?\+\)\s*,\s*)+\(\?\+\)/', '(?+), …', (string) $sql );
	return substr( (string) preg_replace( '/\s+/', ' ', (string) $sql ), 0, 200 );
}

/**
 * Moves the saved queries into the per-shape totals, so SAVEQUERIES doesn't grow without bound.
 *
 * @param array<string,array{n:int,s:float}> $digests Totals.
 * @return void
 */
function glaciate_perf_drain( array &$digests ): void {
	global $wpdb;
	foreach ( (array) $wpdb->queries as $q ) {
		$key = glaciate_perf_digest( (string) $q[0] );
		if ( ! isset( $digests[ $key ] ) ) {
			$digests[ $key ] = array(
				'n' => 0,
				's' => 0.0,
			);
		}
		++$digests[ $key ]['n'];
		$digests[ $key ]['s'] += (float) $q[1];
	}
	$wpdb->queries = array();
}

/**
 * Runs one build in this process and measures it.
 *
 * @param string $kind full or incremental.
 * @return array<string,mixed>
 */
function glaciate_perf_build( string $kind ): array {
	global $wpdb;
	$digests       = array();
	$phases        = array();
	$phase         = 'queued';
	$phase_started = microtime( true );
	$queries       = $wpdb->num_queries;

	wp_cache_flush();
	glaciate_perf_drain( $digests );
	$digests = array();
	// A slice can run hundreds of thousands of queries (a local deploy is one slice); drain as they pile up, or the
	// saved queries themselves become the peak memory.
	$drain = static function ( $query ) use ( &$digests ) {
		global $wpdb;
		if ( count( $wpdb->queries ) >= 5000 ) {
			glaciate_perf_drain( $digests );
		}
		return $query;
	};
	add_filter( 'query', $drain );
	if ( function_exists( 'memory_reset_peak_usage' ) ) {
		memory_reset_peak_usage();
	}
	$started          = microtime( true );
	$service          = new BuildService();
	$id               = $service->start( $kind, 'cli', 0, true, array( 'sync' => true ) );
	$build            = $service->run_sync(
		$id,
		static function ( $build ) use ( &$digests, &$phases, &$phase, &$phase_started ) {
			glaciate_perf_drain( $digests );
			$now = $build['phase'] ?? '';
			if ( $now !== $phase ) {
				$phases[ $phase ] = round( ( $phases[ $phase ] ?? 0 ) + microtime( true ) - $phase_started, 1 );
				$phase            = $now;
				$phase_started    = microtime( true );
				WP_CLI::log( sprintf( '%s  %s: %s', gmdate( 'H:i:s' ), $now, wp_json_encode( $build['stats']['urls'] ?? array() ) ) );
			}
		}
	);
	$phases[ $phase ] = round( ( $phases[ $phase ] ?? 0 ) + microtime( true ) - $phase_started, 1 );
	remove_filter( 'query', $drain );
	glaciate_perf_drain( $digests );

	uasort(
		$digests,
		static function ( $a, $b ) {
			return $b['s'] <=> $a['s'];
		}
	);
	$top = array();
	foreach ( array_slice( $digests, 0, 10, true ) as $sql => $d ) {
		$top[] = sprintf( '%7.1f s %8d × %s', $d['s'], $d['n'], $sql );
	}
	return array(
		'kind'      => $kind,
		'status'    => $build['status'] ?? '',
		'error'     => $build['error'] ?? null,
		'seconds'   => round( microtime( true ) - $started, 1 ),
		'phases'    => $phases,
		'urls'      => $build['stats']['urls'] ?? null,
		'published' => $build['stats']['published'] ?? null,
		'deploy'    => $build['stats']['deploy'] ?? null,
		'peak_mb'   => round( memory_get_peak_usage() / 1048576, 1 ),
		'queries'   => $wpdb->num_queries - $queries,
		'db_top'    => $top,
	);
}

/**
 * Times a callable.
 *
 * @param callable $work Work.
 * @return float Seconds.
 */
function glaciate_perf_time( callable $work ): float {
	$t = microtime( true );
	$work();
	return round( microtime( true ) - $t, 3 );
}

/**
 * Runs the benchmark and prints the report.
 *
 * @param string[] $args Command arguments.
 * @return void
 */
function glaciate_perf_bench( array $args ): void {
	global $wpdb;
	if ( ! defined( 'SAVEQUERIES' ) || ! SAVEQUERIES ) {
		WP_CLI::error( 'Run through npm run perf, which defines SAVEQUERIES.' );
	}

	$what   = $args[0] ?? 'all';
	$target = '/tmp/glaciate-perf';
	$report = array(
		'posts' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'" ),
	);

	if ( 'rebuild' === $what ) {
		// A full build over a site that is already published: every published page is queued again first.
		$report['rebuild'] = glaciate_perf_build( 'full' );
	}

	if ( ! in_array( $what, array( 'incremental', 'rebuild' ), true ) ) {
		foreach ( Schema::TABLES as $table ) {
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', Schema::table( $table ) ) );
		}
		delete_option( 'glaciate_published_once' );
		exec( 'rm -rf ' . escapeshellarg( Paths::cache_dir() ) . ' ' . escapeshellarg( $target ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions -- Benchmark reset of two known folders.
		delete_option( Settings::OPTION );
		Settings::update(
			array(
				'public_url'   => 'https://static.example.org',
				'target'       => 'local',
				'local_path'   => $target,
				'publish_mode' => 'manual',
				'concurrency'  => (int) ( getenv( 'GLACIATE_PERF_CONCURRENCY' ) ? getenv( 'GLACIATE_PERF_CONCURRENCY' ) : 8 ),
				'max_urls'     => 1000000,
			)
		);
		$report['full'] = glaciate_perf_build( 'full' );
	}

	if ( ! in_array( $what, array( 'full', 'rebuild' ), true ) ) {
		$report['incremental_nothing'] = glaciate_perf_build( 'incremental' );

		$post_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID LIMIT 1 OFFSET 1000" );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => '<!-- wp:paragraph --><p>Edited for the benchmark at ' . gmdate( 'c' ) . '.</p><!-- /wp:paragraph -->',
			)
		);
		$report['incremental_edit_content'] = glaciate_perf_build( 'incremental' );

		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => 'Benchmark post renamed ' . time(),
			)
		);
		$report['incremental_edit_title'] = glaciate_perf_build( 'incremental' );

		wp_insert_post(
			array(
				'post_title'    => 'Benchmark new post ' . time(),
				'post_content'  => 'New.',
				'post_status'   => 'publish',
				'post_category' => array( (int) get_cat_ID( 'Category 7' ) ),
			)
		);
		$report['incremental_new_post'] = glaciate_perf_build( 'incremental' );
	}

	// What an admin waits for on each screen load.
	$report['screens'] = array(
		'status_service' => glaciate_perf_time(
			static function () {
				( new \Glaciate\Service\StatusService() )->site();
			}
		),
		'site_health'    => glaciate_perf_time(
			static function () {
				( new \Glaciate\Service\HealthChecks() )->all();
			}
		),
	);
	if ( class_exists( \Glaciate\Service\ChangesetService::class ) ) {
		// The Publish screen's "Waiting to be published", with one edited post pending.
		$post_id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' ORDER BY ID LIMIT 1 OFFSET 2000" );
		( new \Glaciate\Storage\ChangeRepository() )->record( 'post', (string) $post_id, array( 'urls' => array( get_permalink( $post_id ) ) ) );
		$report['screens']['changeset_preview'] = glaciate_perf_time(
			static function () {
				( new \Glaciate\Service\ChangesetService() )->preview();
			}
		);
		( new \Glaciate\Storage\ChangeRepository() )->flush();
	}

	echo wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), "\n";
}

glaciate_perf_bench( $args );
