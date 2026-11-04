<?php
/**
 * Removing a Pagefind bundle published by the server-run mode that 1.1.0 and earlier had.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Search;

use Stillpress\Build\Generated;
use Stillpress\Build\Pipeline;
use Stillpress\Search\PagefindBundle;
use Stillpress\Storage\UrlRepository;

final class PagefindBundleTest extends \WP_UnitTestCase {

	/**
	 * Components.
	 *
	 * @var Pipeline
	 */
	private $pipeline;

	public function set_up(): void {
		parent::set_up();
		$this->pipeline = Pipeline::for_site();
	}

	private function published(): array {
		global $wpdb;
		return $wpdb->get_col( $wpdb->prepare( 'SELECT path FROM %i WHERE url LIKE %s AND content_hash IS NOT NULL ORDER BY path', \Stillpress\Storage\Schema::table( 'urls' ), $wpdb->esc_like( PagefindBundle::PSEUDO ) . '%' ) );
	}

	public function test_a_bundle_left_by_an_earlier_version_is_unpublished_and_deleted(): void {
		$generated = new Generated( $this->pipeline, new UrlRepository(), 1 );
		$generated->write_file( PagefindBundle::PSEUDO . 'pagefind.js', 'pagefind/pagefind.js', 'export const old = true;', 'text/javascript' );
		$generated->write_file( PagefindBundle::PSEUDO . 'fragment/en_1.pf_fragment', 'pagefind/fragment/en_1.pf_fragment', 'x', 'application/octet-stream' );
		$this->assertSame( array( 'pagefind/fragment/en_1.pf_fragment', 'pagefind/pagefind.js' ), $this->published() );

		( new PagefindBundle( $this->pipeline, new UrlRepository() ) )->remove_published();
		$this->assertSame( array(), $this->published() );
		$this->assertFileDoesNotExist( $this->pipeline->cache->path( 'pagefind/pagefind.js' ) );
	}

	public function test_nothing_to_remove_is_fine(): void {
		( new PagefindBundle( $this->pipeline, new UrlRepository() ) )->remove_published();
		$this->assertSame( array(), $this->published() );
	}
}
