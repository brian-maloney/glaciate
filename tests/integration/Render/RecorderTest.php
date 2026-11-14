<?php
/**
 * Dependency recording inside a render.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Render;

use Glaciate\Deps\DependencyStore;
use Glaciate\Deps\QuerySignature;
use Glaciate\Render\Recorder;
use Glaciate\Render\RenderToken;
use Glaciate\Storage\UrlRepository;
use Glaciate\Support\Settings;

final class RecorderTest extends \WP_UnitTestCase {

	private function record( callable $render ): array {
		$url_id   = ( new UrlRepository() )->ensure( 'https://example.test/x/' );
		$recorder = new Recorder( $url_id );
		$recorder->observe();
		$render();
		$recorder->finish();
		return ( new DependencyStore() )->for_url( $url_id );
	}

	public function test_records_queried_posts_terms_options_and_queries(): void {
		$cat   = self::factory()->category->create( array( 'name' => 'News' ) );
		$posts = self::factory()->post->create_many( 2, array( 'post_category' => array( $cat ) ) );
		$other = self::factory()->post->create();

		$deps = $this->record(
			static function () use ( $cat ) {
				get_option( 'blogname' );
				get_transient( 'some_cache' );
				get_option( 'cron' );
				$q = new \WP_Query( array( 'cat' => $cat ) );
				while ( $q->have_posts() ) {
					$q->the_post();
					the_title( '', '', false );
				}
				wp_reset_postdata();
				get_terms( array( 'taxonomy' => 'category' ) );
			}
		);

		foreach ( $posts as $id ) {
			$this->assertContains( (string) $id, $deps['post'] );
		}
		$this->assertNotContains( (string) $other, $deps['post'] );
		$this->assertContains( 'blogname', $deps['option'] );
		$this->assertNotContains( 'cron', $deps['option'] ?? array() );
		$this->assertEmpty( preg_grep( '/transient/', $deps['option'] ) );
		$this->assertContains( 'category', $deps['tq'] );
		$this->assertContains( (string) $cat, $deps['term'] );
		$this->assertNotEmpty( $deps['query'] );
	}

	public function test_a_posts_own_terms_depend_on_the_post_not_the_taxonomy(): void {
		$cat  = self::factory()->category->create();
		$id   = self::factory()->post->create( array( 'post_category' => array( $cat ) ) );
		$deps = $this->record(
			static function () use ( $id ) {
				clean_object_term_cache( $id, 'post' );
				get_the_category( $id );
			}
		);
		$this->assertContains( (string) $id, $deps['post'] );
		$this->assertContains( (string) $cat, $deps['term'] );
		$this->assertArrayNotHasKey( 'tq', $deps, 'A post\'s own terms are not a term list.' );
	}

	public function test_query_signature_resolves_category_to_term_taxonomy_id(): void {
		$cat  = self::factory()->category->create();
		$term = get_term( $cat, 'category' );
		$this->record(
			static function () use ( $cat ) {
				new \WP_Query( array( 'cat' => $cat ) );
			}
		);
		$found = false;
		foreach ( ( new DependencyStore() )->signatures() as $sig ) {
			if ( isset( $sig['tax']['clauses'][0]['tt'] ) && in_array( (int) $term->term_taxonomy_id, (array) $sig['tax']['clauses'][0]['tt'], true ) ) {
				$found = true;
			}
		}
		$this->assertTrue( $found, 'Expected a signature with the category term_taxonomy_id.' );
	}

	public function test_get_posts_with_suppressed_filters_is_still_recorded(): void {
		$id   = self::factory()->post->create();
		$deps = $this->record(
			static function () {
				get_posts( array( 'numberposts' => 5 ) );
			}
		);
		$this->assertContains( (string) $id, $deps['post'] );
	}

	public function test_meta_reads_and_permalinks_record_posts(): void {
		$a    = self::factory()->post->create();
		$b    = self::factory()->post->create();
		$deps = $this->record(
			static function () use ( $a, $b ) {
				get_post_meta( $a, 'anything', true );
				get_permalink( $b );
			}
		);
		$this->assertContains( (string) $a, $deps['post'] );
		$this->assertContains( (string) $b, $deps['post'] );
	}

	public function test_users_and_comment_queries(): void {
		$user = self::factory()->user->create();
		$deps = $this->record(
			static function () use ( $user ) {
				get_author_posts_url( $user );
				get_comments( array( 'number' => 1 ) );
			}
		);
		$this->assertContains( (string) $user, $deps['user'] );
		$this->assertSame( array( '*' ), $deps['cq'] );
	}

	public function test_titles_author_names_terms_and_blocks_read_by_id_are_recorded(): void {
		$user  = self::factory()->user->create( array( 'display_name' => 'Ada' ) );
		$post  = self::factory()->post->create();
		$block = self::factory()->post->create( array( 'post_type' => 'wp_block' ) );
		$cat   = self::factory()->category->create();
		$deps  = $this->record(
			static function () use ( $user, $post, $block, $cat ) {
				get_the_title( $post );
				get_the_author_meta( 'display_name', $user );
				get_term_link( $cat, 'category' );
				render_block(
					array(
						'blockName'    => 'core/block',
						'attrs'        => array( 'ref' => $block ),
						'innerBlocks'  => array(),
						'innerHTML'    => '',
						'innerContent' => array(),
					)
				);
			}
		);
		$this->assertContains( (string) $post, $deps['post'] );
		$this->assertContains( (string) $block, $deps['post'] );
		$this->assertContains( (string) $user, $deps['user'] );
		$this->assertContains( (string) $cat, $deps['term'] );
	}

	public function test_watching_adds_no_filter_and_leaves_every_value_as_it_was(): void {
		$count  = static function (): array {
			$out = array();
			foreach ( array_keys( Recorder::WATCHED ) as $hook ) {
				$out[ $hook ] = isset( $GLOBALS['wp_filter'][ $hook ] ) ? count( $GLOBALS['wp_filter'][ $hook ]->callbacks, COUNT_RECURSIVE ) : 0;
			}
			return $out;
		};
		$id     = self::factory()->post->create( array( 'post_title' => 'Tom & Jerry <em>again</em>' ) );
		$title  = get_the_title( $id );
		$link   = get_permalink( $id );
		$before = $count();
		$during = array();
		$deps   = $this->record(
			static function () use ( $id, $count, &$during ) {
				$during = array( $count(), get_the_title( $id ), get_permalink( $id ) );
			}
		);
		$this->assertSame( array( $before, $title, $link ), $during );
		$this->assertContains( (string) $id, $deps['post'] );
	}

	public function test_a_hook_called_with_unexpected_arguments_is_ignored(): void {
		$deps = $this->record(
			static function () {
				// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hooks, called the way a careless plugin might.
				apply_filters( 'the_title', 'No ID' );
				apply_filters( 'the_title', 'An object', new \stdClass() );
				apply_filters( 'author_link', 'https://example.test/', array( 'x' ) );
				apply_filters( 'get_object_terms', 'not a list' );
				apply_filters( 'posts_pre_query', null );
				// phpcs:enable
			}
		);
		$this->assertArrayNotHasKey( 'post', $deps );
		$this->assertArrayNotHasKey( 'user', $deps );
		$this->assertArrayNotHasKey( 'term', $deps );
	}

	public function test_a_feed_without_posts_depends_on_the_newest_change_to_any_post(): void {
		self::factory()->post->create( array( 'post_type' => 'page' ) );
		$empty = $this->record(
			static function () {
				self::feed_build_date( array( 'p' => PHP_INT_MAX ) );
			}
		);
		$this->assertSame( array( '*' ), $empty['lastmod'], 'The date comes from get_lastpostmodified(), which pages change too.' );

		self::factory()->post->create();
		$with_posts = $this->record(
			static function () {
				self::feed_build_date( array( 'post_type' => 'post' ) );
			}
		);
		$this->assertArrayNotHasKey( 'lastmod', $with_posts, 'With posts, the date is the newest of those posts.' );
	}

	/**
	 * Calls get_feed_build_date() the way a feed template does, before its loop.
	 *
	 * @param array<string,mixed> $query_vars Main query.
	 * @return void
	 */
	private static function feed_build_date( array $query_vars ): void {
		global $wp_query;
		$saved    = $wp_query;
		$wp_query = new \WP_Query( $query_vars ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Feed templates read the main query.
		try {
			get_feed_build_date( 'r' );
		} finally {
			$wp_query = $saved; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	public function test_extensions_can_add_dependencies(): void {
		$deps = $this->record(
			static function () {
				// Before observe() registered its action this would be lost; the filter path always works.
				add_filter(
					'glaciate_dependencies_for_url',
					static function ( $deps ) {
						$deps['option'][] = 'my_plugin_setting';
						return $deps;
					}
				);
			}
		);
		$this->assertContains( 'my_plugin_setting', $deps['option'] );
	}

	public function test_dependency_state_is_recorded(): void {
		$urls = new UrlRepository();
		$id   = $urls->ensure( 'https://example.test/state/' );
		$r    = new Recorder( $id );
		$r->observe();
		$r->finish();
		$this->assertSame( 'recorded', $urls->get( $id )['deps_state'] );
	}

	public function test_stores_the_render_mark_and_buffers_nothing(): void {
		$urls     = new UrlRepository();
		$id       = $urls->ensure( 'https://example.test/marked/' );
		$mark     = RenderToken::mark( '1.' . $id . '.9999999999.' . str_repeat( 'b', 64 ) );
		$level    = ob_get_level();
		$recorder = new Recorder( $id, $mark );
		$start    = new \ReflectionMethod( Recorder::class, 'start' );
		$start->setAccessible( true );
		$start->invoke( $recorder );
		$this->assertSame( $level, ob_get_level(), 'The render opens no output buffer.' );

		$recorder->finish();

		$row = $urls->get( $id );
		$this->assertSame( $mark, $row['render_mark'] );
		$this->assertSame( '', $row['render_exclude'] );
	}

	public function test_stores_the_exclusion_reason(): void {
		$urls = new UrlRepository();
		$id   = $urls->ensure( 'https://example.test/left-out/' );
		add_filter(
			'glaciate_exclude_render',
			static function () {
				return 'Members-Only!';
			}
		);
		$recorder = new Recorder( $id, RenderToken::mark( 'token' ) );
		$recorder->observe();
		$recorder->finish();

		$this->assertSame( 'members-only', $urls->get( $id )['render_exclude'] );
	}

	public function test_render_header_without_valid_token_does_not_start_recording(): void {
		$_SERVER['HTTP_X_GLACIATE_RENDER'] = '1.1.9999999999.' . str_repeat( 'a', 64 );
		Recorder::maybe_start();
		unset( $_SERVER['HTTP_X_GLACIATE_RENDER'] );
		$this->assertNull( Recorder::active() );
	}

	/**
	 * Head feed links printed during a render, with the clean-up a render applies.
	 *
	 * @param string $feeds Feeds setting.
	 * @param string $url   Page to render.
	 * @return string
	 */
	private function feed_links_for( string $feeds, string $url ): string {
		Settings::update( array( 'feeds' => $feeds ) );
		$clean = new \ReflectionMethod( Recorder::class, 'clean_output' );
		$clean->setAccessible( true );
		$clean->invoke( new Recorder( 1 ) );
		$this->go_to( $url );
		$supported = current_theme_supports( 'automatic-feed-links' );
		add_theme_support( 'automatic-feed-links' );
		ob_start();
		feed_links();
		feed_links_extra();
		$out = (string) ob_get_clean();
		if ( ! $supported ) {
			remove_theme_support( 'automatic-feed-links' );
		}
		return $out;
	}

	/**
	 * Does the markup contain a link to the URL?
	 *
	 * @param string $html Markup.
	 * @param string $url  URL.
	 * @return bool
	 */
	private static function links_to( string $html, string $url ): bool {
		return false !== strpos( $html, 'href="' . esc_url( $url ) . '"' );
	}

	/**
	 * @dataProvider provide_feed_modes
	 */
	public function test_head_links_only_to_published_feeds( string $feeds, bool $posts, bool $others ): void {
		$this->set_permalink_structure( '/%postname%/' );
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$post   = self::factory()->post->create(
			array(
				'post_author'    => $author,
				'comment_status' => 'open',
			)
		);

		$single = $this->feed_links_for( $feeds, get_permalink( $post ) );
		$this->assertSame( $posts, self::links_to( $single, home_url( '/feed/' ) ) );
		$this->assertSame( $others, self::links_to( $single, home_url( '/comments/feed/' ) ) );
		$this->assertSame( $others, self::links_to( $single, get_post_comments_feed_link( $post ) ) );

		$this->go_to( get_author_posts_url( $author ) );
		ob_start();
		feed_links_extra();
		$this->assertSame( $others, self::links_to( (string) ob_get_clean(), get_author_feed_link( $author ) ) );
	}

	public function provide_feed_modes(): array {
		return array(
			'all'  => array( 'all', true, true ),
			'main' => array( 'main', true, false ),
			'none' => array( 'none', false, false ),
		);
	}

	public function test_signature_hash_matches_stored(): void {
		$sig = QuerySignature::from_query_vars( array( 'post_type' => 'post' ) );
		$this->assertSame( 40, strlen( QuerySignature::hash( $sig ) ) );
	}
}
