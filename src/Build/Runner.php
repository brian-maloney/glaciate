<?php
/**
 * Advances a build through its phases.
 *
 * @package Glaciate
 */

namespace Glaciate\Build;

use Glaciate\Crawl\Seeds;
use Glaciate\Deploy\Registry;
use Glaciate\Deps\DependencyStore;
use Glaciate\Deps\Invalidator;
use Glaciate\Render\RenderToken;
use Glaciate\Search\Index;
use Glaciate\Search\IndexWriter;
use Glaciate\Search\PageIndexer;
use Glaciate\Search\PagefindBundle;
use Glaciate\Search\TextExtractor;
use Glaciate\Search\Tokenizer;
use Glaciate\Storage\BuildRepository;
use Glaciate\Storage\UrlRepository;
use Glaciate\Support\Logger;

/**
 * Runs a build in resumable slices. Each call to run_slice() does as much work as fits in its time budget and
 * returns true when more remains. All state lives in the database, so any slice can be the last one in a PHP
 * process and the next slice (possibly in another process) picks up where it stopped.
 *
 * Phases:
 *
 *     planning   – full builds: seed URLs and every published URL; incremental: URLs affected by changes;
 *                  urls: the URLs the caller listed.
 *     rendering  – fetch or copy queued URLs, write files, queue newly discovered URLs.
 *     finalizing – write the search index and generated files (_redirects, redirect pages).
 *     deploying  – make the deploy target match the cache.
 */
final class Runner {

	/** The build finished, failed or was cancelled. */
	const DONE = 'done';

	/** The slice ran out of time; more work remains. */
	const MORE = 'more';

	/** Another slice holds the lock; try again shortly. */
	const BUSY = 'busy';

	/** The build is backing off; run the next slice after wait_seconds(). */
	const WAIT = 'wait';

	/**
	 * Builds.
	 *
	 * @var BuildRepository
	 */
	private $builds;

	/**
	 * URLs.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->builds = new BuildRepository();
		$this->urls   = new UrlRepository();
	}

	/**
	 * Runs one slice of a build.
	 *
	 * @param int   $build_id Build ID.
	 * @param float $budget   Seconds to work before yielding (0 = until done).
	 * @return string self::DONE, self::MORE, self::BUSY or self::WAIT.
	 */
	public function run_slice( int $build_id, float $budget ): string {
		$lock = new Lock();
		if ( ! $lock->acquire( (int) max( 120, $budget * 3 ) ) ) {
			return self::BUSY;
		}
		try {
			return $this->work( $build_id, $budget );
		} catch ( \Throwable $e ) {
			$logger = new Logger( $build_id );
			/* translators: %s: error message. */
			$logger->error( sprintf( __( 'Build failed: %s', 'glaciate' ), $e->getMessage() ) );
			$this->builds->update( $build_id, array( 'error' => substr( \Glaciate\Security\Redactor::scrub( $e->getMessage() ), 0, 1000 ) ) );
			$this->builds->transition( $build_id, 'running', 'failed' );
			$this->finished( $build_id );
			return self::DONE;
		} finally {
			$lock->release();
		}
	}

	/**
	 * The slice loop.
	 *
	 * @param int   $build_id Build ID.
	 * @param float $budget   Seconds.
	 * @return string self::MORE, self::DONE or self::WAIT.
	 */
	private function work( int $build_id, float $budget ): string {
		$start = microtime( true );
		$build = $this->builds->get( $build_id );
		if ( null === $build ) {
			return self::DONE;
		}
		if ( 'queued' === $build['status'] ) {
			if ( ! $this->builds->transition( $build_id, 'queued', 'running' ) ) {
				return self::DONE;
			}
			$this->builds->update( $build_id, array( 'phase' => 'planning' ) );
			$names = array(
				'full'        => __( 'Started a full build.', 'glaciate' ),
				'incremental' => __( 'Started an incremental build.', 'glaciate' ),
				'urls'        => __( 'Started a build of selected pages.', 'glaciate' ),
			);
			( new Logger( $build_id ) )->info( $names[ $build['kind'] ] ?? __( 'Started a build.', 'glaciate' ) );
			$build = $this->builds->get( $build_id );
		}
		if ( 'running' !== $build['status'] ) {
			return self::DONE;
		}

		$pipeline = Pipeline::for_site();
		$logger   = new Logger( $build_id );
		$frontier = new Frontier( $build_id );
		$frontier->recover();

		$out_of_time = static function () use ( $start, $budget ) {
			return $budget > 0 && ( microtime( true ) - $start ) >= $budget;
		};

		while ( true ) {
			$build = $this->builds->get( $build_id );
			if ( null === $build || 'running' !== $build['status'] ) {
				$this->finished( $build_id );
				return self::DONE; // Cancelled or finished elsewhere.
			}
			if ( $out_of_time() ) {
				return self::MORE;
			}

			switch ( $build['phase'] ) {
				case 'planning':
					if ( $this->plan( $build, $pipeline, $frontier, $logger ) ) {
						$this->builds->update( $build_id, array( 'phase' => 'rendering' ) );
					}
					break;

				case 'rendering':
					if ( self::wait_seconds( $build ) > 0 ) {
						return self::WAIT; // Backing off: the site said it was overloaded.
					}
					if ( ! $this->render_batch( $build, $pipeline, $frontier, $logger ) ) {
						$this->builds->update( $build_id, array( 'phase' => 'finalizing' ) );
					}
					break;

				case 'finalizing':
					if ( ! ( new IndexWriter( $pipeline, $this->urls, new Index(), $build ) )->write( $budget > 0 ? $start + $budget : 0.0 ) ) {
						break; // Out of time; the index continues in the next slice.
					}
					( new PagefindBundle( $pipeline, $this->urls ) )->remove_published();
					( new Generated( $pipeline, $this->urls, $build_id ) )->write();
					self::report_skipped_queries( $build, $logger );
					( new DependencyStore() )->prune_signatures();
					$this->builds->merge_stats( $build_id, array( 'published' => $this->urls->published_counts() ) );
					$this->builds->update( $build_id, array( 'phase' => $build['deploy'] ? 'deploying' : 'done' ) );
					break;

				case 'deploying':
					if ( $this->deploy( $build, $pipeline, $logger, $budget > 0 ? $start + $budget : 0.0 ) ) {
						$this->builds->update( $build_id, array( 'phase' => 'done' ) );
					}
					break;

				default:
					$counts = $frontier->counts();
					$this->builds->merge_stats( $build_id, array( 'urls' => $counts ) );
					$this->builds->transition( $build_id, 'running', 'done' );
					/* translators: 1: number of addresses processed, 2: number that failed. */
					$logger->info( sprintf( __( 'Build finished. Addresses processed: %1$d, failed: %2$d.', 'glaciate' ), $counts['done'] + $counts['failed'], $counts['failed'] ) );
					$this->finished( $build_id );
					return self::DONE;
			}
		}
	}

	/**
	 * Logs the linked addresses that were left out because of their query string. A static host serves one file per
	 * path, so "?replytocom=5" or "?filter=red" can't be published; the log says so instead of dropping them quietly.
	 *
	 * @param array<string,mixed> $build  Build.
	 * @param Logger              $logger Log.
	 * @return void
	 */
	private static function report_skipped_queries( array $build, Logger $logger ): void {
		$examples = (array) ( $build['stats']['query_skipped'] ?? array() );
		if ( ! $examples ) {
			return;
		}
		$list = implode( ', ', $examples ) . ( empty( $build['stats']['query_skipped_more'] ) ? '' : ', …' );
		/* translators: %s: comma-separated list of addresses. */
		$logger->info( sprintf( __( 'Links to addresses with a query string were skipped, because a static host can\'t serve them: %s', 'glaciate' ), $list ) );
	}

	/**
	 * Planning step. Returns true when planning is complete.
	 *
	 * @param array<string,mixed> $build    Build.
	 * @param Pipeline            $pipeline Components.
	 * @param Frontier            $frontier Queue.
	 * @param Logger              $logger   Log.
	 * @return bool
	 */
	private function plan( array $build, Pipeline $pipeline, Frontier $frontier, Logger $logger ): bool {
		$stats = $build['stats'];

		if ( 'incremental' === $build['kind'] ) {
			// Changes made earlier in this same process (WP-CLI, tests) are normally written at shutdown.
			$listener = \Glaciate\Plugin::instance()->changes();
			if ( null !== $listener ) {
				$listener->flush();
			}
			$plan = ( new Invalidator() )->plan( (int) $build['id'] );
			if ( $plan['full'] ) {
				$logger->info( __( 'A change affects every page (theme, plugins or site settings); switching to a full build.', 'glaciate' ) );
				$this->builds->update( (int) $build['id'], array( 'kind' => 'full' ) );
				return false; // Plan again, now as a full build.
			}
			$ids = array();
			foreach ( $plan['urls'] as $url ) {
				$classified = $pipeline->classifier->classify( $url );
				if ( null !== $classified ) {
					$ids[] = $this->urls->ensure( $classified['url'], 'change', $classified['kind'] );
				}
			}
			// URLs the caller asked for explicitly ("Publish to static" in the editor) are always included.
			foreach ( (array) ( $stats['request_urls'] ?? array() ) as $url ) {
				$classified = $pipeline->classifier->classify( (string) $url );
				if ( null !== $classified ) {
					$ids[] = $this->urls->ensure( $classified['url'], 'request', $classified['kind'] );
				}
			}
			$frontier->add( array_merge( $plan['url_ids'], $ids ) );
			/* translators: 1: number of changes, 2: number of addresses. */
			$logger->info( sprintf( __( 'Planned an incremental build. Changes: %1$d, addresses affected: %2$d.', 'glaciate' ), $plan['changes'], $frontier->total() ) );
			$this->builds->merge_stats( (int) $build['id'], array( 'changes' => $plan['changes'] ) );
			return true;
		}

		if ( 'urls' === $build['kind'] ) {
			$ids = array();
			foreach ( (array) ( $stats['request_urls'] ?? array() ) as $url ) {
				$classified = $pipeline->classifier->classify( (string) $url );
				if ( null === $classified ) {
					/* translators: 1: address, 2: reason. */
					$logger->warning( sprintf( __( 'Skipped %1$s (%2$s).', 'glaciate' ), $url, $pipeline->classifier->reason() ) );
					continue;
				}
				$ids[] = $this->urls->ensure( $classified['url'], 'request', $classified['kind'] );
			}
			$frontier->add( $ids );
			return true;
		}

		// Full build: every published URL first (so pages that are no longer linked are re-checked), then seeds.
		if ( empty( $stats['seed_cursor'] ) ) {
			// At the depth limit, so a page that was published only because links led to it is re-checked without
			// following its links further, unless a shorter path reaches it in this build and lowers its depth.
			// Otherwise a crawler trap would grow by the limit on every full build.
			$frontier->add( $this->urls->published_ids(), max( 0, (int) $pipeline->settings['max_depth'] ) );
			$stats['seed_cursor'] = array( 'stage' => 'core' );
			( new Invalidator() )->consume_all( (int) $build['id'] );
		}
		$batch  = ( new Seeds() )->next( (array) $stats['seed_cursor'] );
		$by_src = array();
		foreach ( $batch['seeds'] as $seed ) {
			$classified = $pipeline->classifier->classify( $seed['url'] );
			if ( null === $classified ) {
				if ( '404' === $seed['source'] ) {
					$classified = array(
						'url'  => (string) $pipeline->policy->normalize( $seed['url'] ),
						'kind' => '404',
					);
				} else {
					if ( 'additional' === $seed['source'] ) {
						/* translators: 1: address, 2: reason. */
						$logger->warning( sprintf( __( 'Skipped %1$s (%2$s).', 'glaciate' ), $seed['url'], $pipeline->classifier->reason() ) );
					}
					continue;
				}
			}
			$by_src[ $seed['source'] ][ $classified['url'] ] = $classified['kind'];
		}
		foreach ( $by_src as $source => $urls ) {
			$frontier->add( wp_list_pluck( $this->urls->ensure_many( $urls, (string) $source ), 'id' ) );
		}
		// Seeding loads every post and term into the in-memory object cache, which nothing empties during a long run.
		if ( wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}
		$this->builds->merge_stats( (int) $build['id'], array( 'seed_cursor' => $batch['cursor'] ) );
		return 'done' === $batch['cursor']['stage'];
	}

	/**
	 * Processes one batch of queued URLs. Returns false when the queue is empty.
	 *
	 * @param array<string,mixed> $build    Build.
	 * @param Pipeline            $pipeline Components.
	 * @param Frontier            $frontier Queue.
	 * @param Logger              $logger   Log.
	 * @return bool
	 */
	private function render_batch( array $build, Pipeline $pipeline, Frontier $frontier, Logger $logger ): bool {
		$build_id    = (int) $build['id'];
		$configured  = max( 1, (int) $pipeline->settings['concurrency'] );
		$concurrency = max( 1, min( $configured, (int) ( $build['stats']['concurrency'] ?? $configured ) ) );
		$claimed     = $frontier->claim( $concurrency * 2 );
		if ( ! $claimed ) {
			return false;
		}
		$client = $concurrency < $configured ? new \Glaciate\Render\LoopbackClient( $pipeline->policy, Pipeline::connect_to( $pipeline->settings ), $concurrency ) : $pipeline->client;
		$rows      = $this->urls->get_many( array_keys( $claimed ) );
		$search    = 'builtin' === $pipeline->settings['search'] ? new PageIndexer( new TextExtractor(), new Tokenizer(), new Index() ) : null;
		$forms     = new \Glaciate\Forms\Config( $pipeline->settings );
		$markup    = $forms->enabled() ? new \Glaciate\Forms\FormMarkup( \Glaciate\Forms\Adapters::defaults( $forms ), $forms->endpoint() ) : null;
		$processor = new Processor( $pipeline->origins, $pipeline->rewriter, $pipeline->extractor, $pipeline->classifier, $pipeline->cache, $this->urls, $logger, $build_id, $search, $markup );
		$token     = RenderToken::for_site();
		$requests  = array();
		$results   = array();
		$marks     = array();

		foreach ( $rows as $id => $row ) {
			$source = 'asset' === $row['kind'] ? $pipeline->copier->source_for( (string) wp_parse_url( $row['url'], PHP_URL_PATH ) ) : null;
			if ( null !== $source ) {
				$results[ $id ] = $processor->process_file( $row, $source );
				continue;
			}
			$headers = array();
			if ( 'asset' !== $row['kind'] ) {
				$headers[ RenderToken::HEADER ] = $token->issue( $build_id, $id );
				$marks[ $id ]                   = RenderToken::mark( $headers[ RenderToken::HEADER ] );
			}
			$requests[ $id ] = array(
				'url'     => $row['url'],
				'headers' => $headers,
			);
		}

		$attempts   = $requests ? $frontier->attempts( array_keys( $requests ) ) : array();
		$overloaded = array();
		$retry      = null;
		foreach ( $client->fetch( $requests ) as $id => $result ) {
			if ( Backoff::is_overloaded( $result->status ) ) {
				$overloaded[ $result->status ] = ( $overloaded[ $result->status ] ?? 0 ) + 1;
				$after                         = Backoff::retry_after( $result->headers['retry-after'] ?? '', time() );
				$retry                         = null === $after ? $retry : max( (int) $retry, $after );
			}
			// The recorder wrote to the row during the render (dependency state, its mark, exclusion); reload it. A mark
			// that matches the token sent proves the render ran rather than a page cache answering (D45).
			$row = $this->urls->get( (int) $id );
			if ( null !== $row && isset( $marks[ $id ] ) ) {
				$result->recorded = hash_equals( $marks[ $id ], (string) $row['render_mark'] );
				$result->exclude  = $result->recorded ? (string) $row['render_exclude'] : '';
			}
			$results[ $id ] = null === $row ? array(
				'outcome'    => 'failed',
				'discovered' => array(),
			) : $processor->process( $row, $result, null, ( $attempts[ $id ] ?? Backoff::MAX_ATTEMPTS ) < Backoff::MAX_ATTEMPTS );
		}

		$states = array();
		$found  = array();
		foreach ( $results as $id => $outcome ) {
			$state                = array(
				'failed' => 'failed',
				'retry'  => 'pending',
			);
			$states[ $state[ $outcome['outcome'] ] ?? 'done' ][] = (int) $id;
			foreach ( $outcome['discovered'] as $link ) {
				$found[ $link['url'] ] = $link['kind'];
			}
		}
		foreach ( $states as $state => $ids ) {
			$frontier->mark( $ids, $state );
		}
		$followed = (int) ( $build['stats']['followed'] ?? 0 );
		if ( $found ) {
			$followed = $this->queue_discovered( $build, $results, $claimed, $found, $frontier, $logger, (int) $pipeline->settings['max_urls'], (int) $pipeline->settings['max_depth'], $followed );
		}
		$stats = array(
			'urls'     => $frontier->counts(),
			'followed' => $followed,
		);
		if ( $overloaded ) {
			$stats += $this->back_off( $build, $overloaded, $retry, $concurrency, $logger );
		} elseif ( ! empty( $build['stats']['backoff_count'] ) ) {
			$stats['backoff_count'] = 0;
		}
		$queries = $processor->skipped_queries();
		if ( $queries ) {
			$known                    = (array) ( $build['stats']['query_skipped'] ?? array() );
			$all                      = array_values( array_unique( array_merge( $known, $queries ) ) );
			$stats['query_skipped']   = array_slice( $all, 0, Processor::QUERY_EXAMPLES );
			$stats['query_skipped_more'] = ! empty( $build['stats']['query_skipped_more'] ) || count( $all ) > Processor::QUERY_EXAMPLES || count( $queries ) >= Processor::QUERY_EXAMPLES;
		}
		$this->builds->merge_stats( $build_id, $stats );

		// The HTTP library leaves reference cycles (transport <-> hooks) holding whole response bodies. PHP only
		// collects cycles when its root buffer fills, which a long build can outrun, so collect after each batch.
		gc_collect_cycles();
		return true;
	}

	/**
	 * Pauses the build after the site answered 429 or 5xx, and halves the requests sent at once for the rest of it.
	 * The addresses that got those answers go back in the queue (up to Backoff::MAX_ATTEMPTS tries each).
	 *
	 * @param array<string,mixed> $build       Build.
	 * @param array<int,int>      $overloaded  HTTP status => number of answers in this batch.
	 * @param int|null            $retry_after Longest Retry-After, in seconds, or null.
	 * @param int                 $concurrency Requests at once in this batch.
	 * @param Logger              $logger      Log.
	 * @return array<string,int> Stats to merge.
	 */
	private function back_off( array $build, array $overloaded, ?int $retry_after, int $concurrency, Logger $logger ): array {
		$count = (int) ( $build['stats']['backoff_count'] ?? 0 ) + 1;

		/**
		 * Filters how long a build waits after the site answers 429 or 5xx.
		 *
		 * @since 1.1.0
		 *
		 * @param int      $seconds     Seconds: Retry-After (at most 300), else 5, 10, 20 … up to 120.
		 * @param int      $count       Back-offs in a row, including this one.
		 * @param int|null $retry_after The Retry-After the site sent, in seconds, or null.
		 */
		$delay = max( 0, (int) apply_filters( 'glaciate_backoff_seconds', Backoff::delay( $count, $retry_after ), $count, $retry_after ) );
		$next  = Backoff::concurrency( $concurrency );

		$codes = array();
		foreach ( $overloaded as $status => $n ) {
			$codes[] = sprintf( '%d × HTTP %d', $n, $status );
		}
		$logger->warning(
			/* translators: %s: answers such as "3 × HTTP 503". */
			sprintf( __( 'The site is overloaded (%s).', 'glaciate' ), implode( ', ', $codes ) )
			/* translators: %d: seconds. */
			. ' ' . sprintf( _n( 'Waiting %d second before continuing,', 'Waiting %d seconds before continuing,', $delay, 'glaciate' ), $delay )
			/* translators: %d: number of requests. */
			. ' ' . sprintf( _n( 'then sending %d request at a time.', 'then sending %d requests at a time.', $next, 'glaciate' ), $next )
		);

		return array(
			'backoff_count' => $count,
			'backoff_until' => time() + $delay,
			'concurrency'   => $next,
		);
	}

	/**
	 * Seconds until a backing-off build may continue (0 when it may continue now).
	 *
	 * @param array<string,mixed>|int $build Build row or ID.
	 * @return int
	 */
	public static function wait_seconds( $build ): int {
		if ( ! is_array( $build ) ) {
			$build = ( new BuildRepository() )->get( (int) $build );
		}
		return null === $build ? 0 : max( 0, (int) ( $build['stats']['backoff_until'] ?? 0 ) - time() );
	}

	/**
	 * Queues the links found in a batch of pages, one lookup for the whole batch.
	 *
	 * Two limits stop crawler traps (calendars, endless archives), which only links to pages can make. The depth limit
	 * stops following links on pages that many links away from the listed ones; the address limit counts pages a build
	 * queued by following links, so the posts, terms and archives listed from the database, and the files pages use,
	 * never use it up. Neither applies to files.
	 *
	 * @param array<string,mixed>                                                                     $build     Build row.
	 * @param array<int,array{outcome:string,discovered:array<int,array{url:string,kind:string}>}> $results   Outcomes by URL ID.
	 * @param array<int,int>                                                                          $claimed   URL ID => depth.
	 * @param array<string,string>                                                                    $found     Every discovered URL => kind.
	 * @param Frontier                                                                                $frontier  Queue.
	 * @param Logger                                                                                  $logger    Build log.
	 * @param int                                                                                     $max_pages The address limit.
	 * @param int                                                                                     $max_depth The link depth limit (0 = none).
	 * @param int                                                                                     $followed  Pages queued through links so far.
	 * @return int Pages queued through links, including this batch's.
	 */
	private function queue_discovered( array $build, array $results, array $claimed, array $found, Frontier $frontier, Logger $logger, int $max_pages, int $max_depth, int $followed ): int {
		$known = $this->urls->lookup( array_keys( $found ) );
		// Incremental builds only follow links to URLs that aren't published right now: new URLs, and URLs that were
		// gone (404) or redirecting before, which the change may have brought back.
		if ( 'full' !== $build['kind'] ) {
			foreach ( $known as $url => $row ) {
				if ( $row['published'] ) {
					unset( $found[ $url ], $known[ $url ] );
				}
			}
		}
		$ids = $known + $this->urls->ensure_many( array_diff_key( $found, $known ) );

		$by_depth = array();
		foreach ( $results as $id => $outcome ) {
			foreach ( $outcome['discovered'] as $link ) {
				if ( isset( $ids[ $link['url'] ] ) ) {
					$by_depth[ $claimed[ $id ] + 1 ][ 'page' === $link['kind'] ? 'pages' : 'files' ][ $ids[ $link['url'] ]['id'] ] = true;
				}
			}
		}
		ksort( $by_depth );
		foreach ( $by_depth as $depth => $sets ) {
			if ( ! empty( $sets['files'] ) ) {
				$frontier->add( array_keys( $sets['files'] ), $depth );
			}
			$linked = empty( $sets['pages'] ) ? array() : array_keys( $sets['pages'] );
			$pages  = $frontier->missing( $linked );
			if ( $max_depth > 0 && $depth > $max_depth ) {
				if ( $pages && empty( $build['stats']['depth_limited'] ) ) {
					/* translators: %d: the link depth limit. */
					$logger->warning( sprintf( __( 'Reached the link depth limit of %d (Settings → Advanced); links on pages that deep were not followed.', 'glaciate' ), $max_depth ) );
					$this->builds->merge_stats( (int) $build['id'], array( 'depth_limited' => true ) );
					$build['stats']['depth_limited'] = true;
				}
				continue;
			}
			// Pages already waiting in the queue may have been reached by a longer path first.
			$frontier->lower( array_diff( $linked, $pages ), $depth );
			if ( count( $pages ) > $max_pages - $followed ) {
				$logger->warning( __( 'Reached the address limit (Settings → Advanced); some links were not followed.', 'glaciate' ) );
				$pages = array_slice( $pages, 0, max( 0, $max_pages - $followed ) );
			}
			$followed += $frontier->add( $pages, $depth );
		}
		return $followed;
	}

	/**
	 * Runs the configured deployer.
	 *
	 * @param array<string,mixed> $build    Build.
	 * @param Pipeline            $pipeline Components.
	 * @param Logger              $logger   Log.
	 * @param float               $deadline When a resumable deployer should stop (0 = no limit).
	 * @return bool True when the deploy finished; false when a resumable deployer stopped at the deadline.
	 * @throws \RuntimeException When the target is missing or misconfigured.
	 */
	private function deploy( array $build, Pipeline $pipeline, Logger $logger, float $deadline ): bool {
		$target   = (string) ( $build['stats']['target'] ?? $pipeline->settings['target'] );
		$deployer = Registry::instance()->get( $target );
		if ( null === $deployer ) {
			/* translators: %s: deployer ID. */
			throw new \RuntimeException( sprintf( __( 'Unknown deploy target "%s".', 'glaciate' ), $target ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain text.
		}
		$manifest = iterator_to_array( $this->urls->published_files() );

		/**
		 * Filters the manifest (relative path => SHA-256) before it is deployed.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,string> $manifest Files that make up the site.
		 * @param string               $target   Deployer ID.
		 */
		$manifest = (array) apply_filters( 'glaciate_manifest', $manifest, $target );
		if ( $deployer instanceof \Glaciate\Deploy\ResumableDeployerInterface ) {
			$deployer->set_deadline( $deadline );
		}
		$result = $deployer->deploy( $manifest, $pipeline->cache->mapper()->root(), $logger );

		// A resumable deploy spans slices: add this slice's counts to the earlier ones. Files uploaded by earlier
		// slices look unchanged now, so "unchanged" is worked out from the totals at the end.
		$totals = $build['stats']['deploy_progress'] ?? array();
		foreach ( array( 'added', 'modified', 'deleted', 'failed' ) as $count ) {
			$totals[ $count ] = (int) ( $totals[ $count ] ?? 0 ) + $result->{$count};
		}
		if ( ! $result->done ) {
			$this->builds->merge_stats( (int) $build['id'], array( 'deploy_progress' => $totals ) );
			/* translators: 1: deploy target, 2: number of files. */
			$logger->info( sprintf( __( 'Deploying to %1$s. Files sent so far: %2$d; continuing.', 'glaciate' ), $deployer->label(), $totals['added'] + $totals['modified'] ) );
			return false;
		}
		$summary = array(
			'added'     => $totals['added'],
			'modified'  => $totals['modified'],
			'deleted'   => $totals['deleted'],
			'unchanged' => max( 0, count( $manifest ) - $totals['added'] - $totals['modified'] - $totals['failed'] ),
			'failed'    => $totals['failed'],
			'location'  => $result->location,
			'target'    => $target,
		);
		$this->builds->merge_stats( (int) $build['id'], array( 'deploy' => $summary ) );
		/* translators: 1: deploy target, 2-5: numbers of files. */
		$logger->info( sprintf( __( 'Deployed to %1$s. Added: %2$d, changed: %3$d, deleted: %4$d, unchanged: %5$d.', 'glaciate' ), $deployer->label(), $summary['added'], $summary['modified'], $summary['deleted'], $summary['unchanged'] ) );
		if ( $summary['failed'] > 0 ) {
			/* translators: %d: number of files. */
			$logger->warning( sprintf( _n( '%d file could not be deployed; the next deploy retries it.', '%d files could not be deployed; the next deploy retries them.', $summary['failed'], 'glaciate' ), $summary['failed'] ) );
		}
		return true;
	}

	/**
	 * Clean-up after a build stops for any reason.
	 *
	 * @param int $build_id Build ID.
	 * @return void
	 */
	private function finished( int $build_id ): void {
		( new Frontier( $build_id ) )->clear();
		$build = $this->builds->get( $build_id );

		/**
		 * Fires when a build stops: done, failed or cancelled.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,mixed> $build Build row, including status and stats.
		 */
		do_action( 'glaciate_build_finished', $build );
	}
}
