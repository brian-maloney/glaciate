<?php
/**
 * Settings that narrow what is published take already published files away.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\System;

use Glaciate\Plugin;
use Glaciate\Storage\ChangeRepository;
use Glaciate\Support\Paths;
use Glaciate\Support\Settings;

final class ExclusionChangeTest extends SystemTestCase {

	/**
	 * Pending change keys ("type:key").
	 *
	 * @return string[]
	 */
	private function pending_changes(): array {
		Plugin::instance()->changes()->flush();
		$keys = array();
		foreach ( ( new ChangeRepository() )->peek() as $change ) {
			$keys[] = $change['object_type'] . ':' . $change['object_key'];
		}
		return $keys;
	}

	/**
	 * Published files under a cache-relative path pattern.
	 *
	 * @param string $regex Pattern matched against relative paths.
	 * @return string[]
	 */
	private function cached( string $regex ): array {
		$out = array();
		if ( ! is_dir( Paths::cache_dir() ) ) {
			return $out;
		}
		$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( Paths::cache_dir(), \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			$relative = substr( $file->getPathname(), strlen( Paths::cache_dir() ) + 1 );
			if ( preg_match( $regex, $relative ) ) {
				$out[] = $relative;
			}
		}
		return $out;
	}

	public function test_excluding_a_published_page_removes_it_from_cache_and_target(): void {
		wp_insert_post(
			array(
				'post_title'   => 'Hidden page',
				'post_name'    => 'hidden-page',
				'post_type'    => 'page',
				'post_content' => '<!-- wp:paragraph --><p>Quokkaesque secret wording.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
		$this->build();
		$this->assertArrayHasKey( 'hidden-page/index.html', $this->snapshot() );
		$this->assertFileExists( Paths::cache_dir() . '/hidden-page/index.html' );

		Settings::update( array( 'exclude' => array( '/hidden-page/' ) ) );
		$this->assertContains( 'full:option:' . Settings::OPTION, $this->pending_changes(), 'Saving settings records a full change.' );

		$build = $this->build( 'incremental' );
		$this->assertSame( 'full', $build['kind'], 'The settings change turns the build into a full one.' );
		$files = $this->snapshot();
		$this->assertArrayNotHasKey( 'hidden-page/index.html', $files, "Log:\n" . $this->log( (int) $build['id'] ) );
		$this->assertFileDoesNotExist( Paths::cache_dir() . '/hidden-page/index.html' );
		$this->assertStringContainsString( 'Left out', $this->log( (int) $build['id'] ) );
		foreach ( $files as $path => $contents ) {
			if ( 0 === strpos( $path, 'glaciate-search/' ) ) {
				$this->assertStringNotContainsString( 'hidden-page', $contents, "Search index still lists the page in {$path}" );
				$this->assertStringNotContainsString( 'quokkaesque', strtolower( $contents ), "Search index still holds the page's words in {$path}" );
			}
		}
		$this->assertArrayHasKey( 'index.html', $files, 'Everything else is still published.' );
	}

	public function test_switching_feeds_off_removes_every_feed(): void {
		$post = wp_insert_post(
			array(
				'post_title'  => 'Fed post',
				'post_name'   => 'fed-post',
				'post_status' => 'publish',
			)
		);
		Settings::update(
			array(
				'feeds'           => 'all',
				'additional_urls' => array( get_post_comments_feed_link( $post ) ),
			)
		);
		$this->build();
		$files = $this->snapshot();
		$this->assertArrayHasKey( 'feed/index.html', $files );
		$this->assertArrayHasKey( 'fed-post/feed/index.html', $files );

		Settings::update( array( 'feeds' => 'none' ) );
		$build = $this->build( 'incremental' );
		$this->assertSame( 'full', $build['kind'] );
		$this->assertSame( array(), preg_grep( '#(^|/)feed/index\.html$#', array_keys( $this->snapshot() ) ), "Log:\n" . $this->log( (int) $build['id'] ) );
		$this->assertSame( array(), $this->cached( '#(^|/)feed/index\.html$#' ) );
	}

	public function test_incremental_build_removes_a_changed_page_that_is_now_excluded(): void {
		$id = wp_insert_post(
			array(
				'post_title'  => 'Soon excluded',
				'post_name'   => 'soon-excluded',
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$this->build();
		$this->assertArrayHasKey( 'soon-excluded/index.html', $this->snapshot() );

		// An exclusion added in code, which records no change of its own; the next edit of the page publishes it.
		$exclude = static function ( $patterns ) {
			$patterns[] = '/soon-excluded/';
			return $patterns;
		};
		add_filter( 'glaciate_exclude_patterns', $exclude );
		try {
			wp_update_post(
				array(
					'ID'           => $id,
					'post_content' => 'Edited',
				)
			);
			$build = $this->build( 'incremental' );
			$this->assertSame( 'incremental', $build['kind'] );
			$this->assertArrayNotHasKey( 'soon-excluded/index.html', $this->snapshot(), "Log:\n" . $this->log( (int) $build['id'] ) );
			$this->assertFileDoesNotExist( Paths::cache_dir() . '/soon-excluded/index.html' );
		} finally {
			remove_filter( 'glaciate_exclude_patterns', $exclude );
		}
	}
}
