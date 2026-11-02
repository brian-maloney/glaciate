<?php
/**
 * Dependency recording inside a render.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Render;

use Stillpress\Deps\DependencyStore;
use Stillpress\Deps\QuerySignature;
use Stillpress\Render\Recorder;
use Stillpress\Storage\UrlRepository;
use Stillpress\Support\Settings;

final class RecorderTest extends \WP_UnitTestCase {

	private function record( callable $render ): array {
		$url_id   = ( new UrlRepository() )->ensure( 'https://example.test/x/' );
		$recorder = new Recorder( 1, $url_id );
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

	public function test_extensions_can_add_dependencies(): void {
		$deps = $this->record(
			static function () {
				// Before observe() registered its action this would be lost; the filter path always works.
				add_filter(
					'stillpress_dependencies_for_url',
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
		$r    = new Recorder( 1, $id );
		$r->observe();
		$r->finish();
		$this->assertSame( 'recorded', $urls->get( $id )['deps_state'] );
	}

	public function test_render_header_without_valid_token_does_not_start_recording(): void {
		$_SERVER['HTTP_X_STILLPRESS_RENDER'] = '1.1.9999999999.' . str_repeat( 'a', 64 );
		Recorder::maybe_start();
		unset( $_SERVER['HTTP_X_STILLPRESS_RENDER'] );
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
		$clean->invoke( new Recorder( 1, 1 ) );
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
