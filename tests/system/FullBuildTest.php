<?php
/**
 * End-to-end full builds over real HTTP.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\System;

final class FullBuildTest extends SystemTestCase {

	public function test_full_build_publishes_a_complete_rewritten_site(): void {
		$cat     = wp_insert_term( 'News', 'category', array( 'slug' => 'news' ) )['term_id'];
		$post    = wp_insert_post(
			array(
				'post_title'    => 'First story',
				'post_name'     => 'first-story',
				'post_content'  => '<!-- wp:paragraph --><p>Read <a href="' . home_url( '/about/' ) . '">about us</a>.</p><!-- /wp:paragraph -->',
				'post_status'   => 'publish',
				'post_category' => array( $cat ),
			)
		);
		$about   = wp_insert_post(
			array(
				'post_title'  => 'About',
				'post_name'   => 'about',
				'post_type'   => 'page',
				'post_status' => 'publish',
			)
		);
		$draft   = wp_insert_post(
			array(
				'post_title'  => 'Secret draft',
				'post_name'   => 'secret-draft',
				'post_status' => 'draft',
			)
		);
		$private = wp_insert_post(
			array(
				'post_title'  => 'Private notes',
				'post_name'   => 'private-notes',
				'post_status' => 'private',
			)
		);
		$locked  = wp_insert_post(
			array(
				'post_title'    => 'Locked',
				'post_name'     => 'locked',
				'post_status'   => 'publish',
				'post_password' => 'hunter2',
			)
		);
		$this->assertIsInt( $post );

		$build = $this->build();
		$files = $this->snapshot();

		foreach ( array( 'index.html', 'first-story/index.html', 'about/index.html', 'category/news/index.html', 'feed/index.html', 'robots.txt', 'wp-sitemap.xml', '404.html' ) as $expected ) {
			$this->assertArrayHasKey( $expected, $files, "Missing {$expected}. Log:\n" . $this->log( (int) $build['id'] ) );
		}
		$this->assertArrayNotHasKey( 'secret-draft/index.html', $files );
		$this->assertArrayNotHasKey( 'private-notes/index.html', $files );
		$this->assertArrayNotHasKey( 'locked/index.html', $files, 'Password-protected posts are excluded by default.' );

		foreach ( $files as $path => $contents ) {
			$this->assertStringNotContainsString( 'localhost:8889', $contents, "Origin URL left in {$path}" );
			$this->assertStringNotContainsString( 'Secret draft', $contents, "Draft title leaked into {$path}" );
		}
		$this->assertStringContainsString( self::PUBLIC_URL . '/about/', $files['first-story/index.html'] );
		$this->assertStringContainsString( '<link rel="canonical" href="' . self::PUBLIC_URL . '/first-story/"', $files['first-story/index.html'] );
		$this->assertStringContainsString( 'Sitemap: ' . self::PUBLIC_URL . '/wp-sitemap.xml', $files['robots.txt'] );
		$this->assertStringNotContainsString( 'rel="EditURI"', $files['index.html'] );
		$this->assertStringNotContainsString( 'https://api.w.org/', $files['index.html'] );
		$this->assertStringNotContainsString( 'id="wpadminbar"', $files['index.html'] );

		$assets = preg_grep( '#^wp-(content|includes)/.+\.(css|js|woff2)$#', array_keys( $files ) );
		$this->assertNotEmpty( $assets, 'Theme and core assets should be published.' );
		unset( $draft, $private, $locked, $about );
	}

	public function test_uploads_are_copied_and_referenced(): void {
		$upload = wp_upload_bits( 'pixel.png', null, base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 1x1 PNG fixture.
		$this->assertEmpty( $upload['error'] );
		wp_insert_post(
			array(
				'post_title'   => 'With image',
				'post_name'    => 'with-image',
				'post_status'  => 'publish',
				'post_content' => '<img src="' . $upload['url'] . '" alt="">',
			)
		);
		$this->build();
		$relative = ltrim( (string) wp_parse_url( $upload['url'], PHP_URL_PATH ), '/' );
		$files    = $this->snapshot();
		$this->assertArrayHasKey( $relative, $files );
		$this->assertSame( file_get_contents( $upload['file'] ), $files[ $relative ] );
		$this->assertStringContainsString( self::PUBLIC_URL . '/' . $relative, $files['with-image/index.html'] );
		wp_delete_file( $upload['file'] );
	}

	public function test_rebuild_without_changes_changes_nothing(): void {
		wp_insert_post(
			array(
				'post_title'  => 'Stable',
				'post_status' => 'publish',
			)
		);
		$this->build();
		$second = $this->build();
		$this->assertSame( 0, $second['stats']['deploy']['added'] + $second['stats']['deploy']['modified'] + $second['stats']['deploy']['deleted'], 'Builds must be deterministic.' );
	}

	public function test_address_limit_caps_only_what_links_lead_to(): void {
		$links = '';
		foreach ( array( 2024, 2025, 2026 ) as $year ) {
			wp_insert_post(
				array(
					'post_title'  => "Story from {$year}",
					'post_name'   => "story-{$year}",
					'post_status' => 'publish',
					'post_date'   => "{$year}-05-01 12:00:00",
				)
			);
			$links .= '<a href="' . home_url( "/{$year}/" ) . '">' . $year . '</a> ';
		}
		// Year archives are never listed from the database: only links lead to them.
		wp_insert_post(
			array(
				'post_title'   => 'Years',
				'post_name'    => 'years',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p>' . $links . '</p><!-- /wp:paragraph -->',
			)
		);
		\Stillpress\Support\Settings::update( array( 'max_urls' => 1 ) );
		$build = $this->build();
		$files = $this->snapshot();

		foreach ( array( 'story-2024', 'story-2025', 'story-2026', 'years' ) as $slug ) {
			$this->assertArrayHasKey( "{$slug}/index.html", $files, 'Posts listed from the database are never cut.' );
		}
		$this->assertSame( 1, $build['stats']['followed'], 'One page was queued by following links.' );
		$this->assertLessThanOrEqual( 1, count( preg_grep( '#^\d{4}/index\.html$#', array_keys( $files ) ) ) );
		$this->assertNotEmpty( preg_grep( '#\.css$#', array_keys( $files ) ), 'Files pages use are not limited.' );
		$this->assertStringContainsString( 'Reached the address limit', $this->log( (int) $build['id'] ) );
	}

	public function test_paginated_archives_are_listed_not_crawled(): void {
		$cat = wp_insert_term( 'Busy', 'category', array( 'slug' => 'busy' ) )['term_id'];
		for ( $n = 1; $n <= 10; $n++ ) {
			wp_insert_post(
				array(
					'post_title'    => "Busy {$n}",
					'post_status'   => 'publish',
					'post_category' => array( $cat ),
				)
			);
		}
		$build = $this->build();
		$files = $this->snapshot();
		// Three posts a page: four pages each for the blog and the category.
		foreach ( array( 'page/4/index.html', 'category/busy/page/4/index.html' ) as $path ) {
			$this->assertArrayHasKey( $path, $files );
		}
		$this->assertArrayNotHasKey( 'page/5/index.html', $files );
		foreach ( array( '/page/4/', '/category/busy/page/4/' ) as $path ) {
			$row = ( new \Stillpress\Storage\UrlRepository() )->find( home_url( $path ) );
			$this->assertSame( 'paged', $row['source'] ?? null, "{$path} is listed, not found one link at a time.\n" . $this->log( (int) $build['id'] ) );
		}
	}

	public function test_user_redirects_and_404(): void {
		\Stillpress\Support\Settings::update( array( 'redirects' => "/old-page/ /new-page/ 301\n/gone https://example.org/elsewhere 302" ) );
		$this->build();
		$files = $this->snapshot();
		$this->assertStringContainsString( '/old-page/ /new-page/ 301', $files['_redirects'] );
		$this->assertStringContainsString( '/gone https://example.org/elsewhere 302', $files['_redirects'] );
		$this->assertStringContainsString( 'url=' . self::PUBLIC_URL . '/new-page/', $files['old-page/index.html'] );
		$this->assertArrayHasKey( '404.html', $files );
	}

	public function test_skipped_query_string_addresses_are_reported(): void {
		wp_insert_post(
			array(
				'post_title'   => 'Filters',
				'post_name'    => 'filters',
				'post_status'  => 'publish',
				'post_content' => '<a href="' . home_url( '/filters/?colour=red' ) . '">Red</a> <a href="' . home_url( '/filters/?colour=blue' ) . '">Blue</a>',
			)
		);
		\Stillpress\Support\Settings::update( array( 'additional_urls' => '/shop/?sort=price' ) );
		$build = $this->build();
		$log   = $this->log( (int) $build['id'] );

		$this->assertStringContainsString( 'query string were skipped', $log );
		$this->assertStringContainsString( home_url( '/filters/?colour=red' ), $log );
		$this->assertStringContainsString( home_url( '/filters/?colour=blue' ), $log );
		$this->assertStringContainsString( 'Skipped ' . home_url( '/shop/?sort=price' ) . ' (query-string).', $log, 'A listed address is a warning of its own.' );
		$this->assertContains( home_url( '/filters/?colour=red' ), $build['stats']['query_skipped'] );
		$this->assertArrayHasKey( 'filters/index.html', $this->snapshot() );
	}

	public function test_redirects_from_file_addresses_get_no_html_page(): void {
		\Stillpress\Support\Settings::update( array( 'redirects' => "/old-brochure.pdf /about/ 301\n/old-page/ /about/ 301" ) );
		$this->build();
		$files = $this->snapshot();
		$this->assertStringContainsString( '/old-brochure.pdf /about/ 301', $files['_redirects'] );
		$this->assertArrayNotHasKey( 'old-brochure.pdf', $files, 'A static host would serve an HTML page there as a PDF.' );
		$this->assertArrayHasKey( 'old-page/index.html', $files );
	}

	public function test_favicon_is_the_file_wordpress_redirects_to(): void {
		// Without a site icon, WordPress redirects /favicon.ico to core's logo.
		$this->build();
		$files = $this->snapshot();
		$this->assertArrayHasKey( 'favicon.ico', $files );
		$this->assertSame( file_get_contents( ABSPATH . WPINC . '/images/w-logo-gray-white-bg.png' ), $files['favicon.ico'] );

		// With one, to the 32-pixel site icon.
		$png    = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- 1x1 PNG fixture.
		$upload = wp_upload_bits( 'site-icon.png', null, $png );
		$this->assertEmpty( $upload['error'] );
		$icon = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => 'Site icon',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		update_option( 'site_icon', $icon );
		$build = $this->build();
		$files = $this->snapshot();
		$this->assertSame( $png, $files['favicon.ico'] ?? null, "Log:\n" . $this->log( (int) $build['id'] ) );
		$this->assertArrayHasKey( ltrim( (string) wp_parse_url( $upload['url'], PHP_URL_PATH ), '/' ), $files );

		$again = $this->build();
		$this->assertSame( 0, $again['stats']['deploy']['added'] + $again['stats']['deploy']['modified'] + $again['stats']['deploy']['deleted'] );
		delete_option( 'site_icon' );
		wp_delete_file( $upload['file'] );
	}

	public function test_a_real_favicon_file_wins(): void {
		$this->build();
		$this->assertArrayHasKey( 'favicon.ico', $this->snapshot(), 'Starts as the copy of the redirect target.' );

		$file = ABSPATH . 'favicon.ico';
		if ( file_exists( $file ) || ! is_writable( ABSPATH ) ) {
			$this->markTestSkipped( 'Needs a writable WordPress root without a favicon.ico.' );
		}
		file_put_contents( $file, 'real icon bytes' );
		try {
			$build = $this->build();
			$this->assertSame( 'real icon bytes', $this->snapshot()['favicon.ico'] ?? null, "Log:\n" . $this->log( (int) $build['id'] ) );
		} finally {
			wp_delete_file( $file );
		}
	}

	public function test_feed_is_saved_as_index_html_with_its_type_in_headers(): void {
		// Static hosts map "/feed/" only to feed/index.html; the real type goes in _headers and in each upload.
		$this->build();
		$files = $this->snapshot();
		$this->assertArrayHasKey( 'feed/index.html', $files );
		$this->assertArrayNotHasKey( 'feed/index.xml', $files );
		$this->assertStringContainsString( '<rss', $files['feed/index.html'] );
		$this->assertMatchesRegularExpression( '#^/feed/\n  Content-Type: application/rss\+xml#m', $files['_headers'] ?? '' );
		$types = ( new \Stillpress\Storage\UrlRepository() )->directory_types();
		$this->assertStringStartsWith( 'application/rss+xml', $types['feed/index.html']['type'] );
		$this->assertStringStartsWith( 'application/rss+xml', \Stillpress\Deploy\S3::content_type( 'feed/index.html', $types ) );
		$this->assertSame( 'text/html; charset=utf-8', \Stillpress\Deploy\S3::content_type( 'index.html', $types ) );
	}

	public function test_redirect_that_starts_answering_404_leaves_redirects(): void {
		$this->build();
		// An attachment page redirects to its file when attachment pages are off (as the reset sets them).
		$upload = wp_upload_bits( 'stillpress-redirect-test.txt', null, 'file contents' );
		$this->assertEmpty( $upload['error'] );
		$id = wp_insert_attachment(
			array(
				'post_title'     => 'Stillpress redirect test',
				'post_name'      => 'stillpress-redirect-test',
				'post_mime_type' => 'text/plain',
				'post_status'    => 'inherit',
			),
			$upload['file']
		);
		$this->build( 'incremental' );
		$this->assertStringContainsString( '/stillpress-redirect-test/ ', $this->snapshot()['_redirects'] ?? '', 'The redirect is published.' );

		wp_delete_attachment( $id, true );
		$this->build( 'full' );
		$this->assertStringNotContainsString( '/stillpress-redirect-test/', $this->snapshot()['_redirects'] ?? '', 'A redirect that now answers 404 is gone from _redirects.' );
	}

	public function test_zip_target(): void {
		wp_insert_post(
			array(
				'post_title'  => 'Zipped',
				'post_name'   => 'zipped',
				'post_status' => 'publish',
			)
		);
		\Stillpress\Support\Settings::update( array( 'target' => 'zip' ) );
		$build = $this->build();
		$zip   = new \ZipArchive();
		$this->assertTrue( $zip->open( (string) $build['stats']['deploy']['location'] ) );
		$this->assertNotFalse( $zip->locateName( 'zipped/index.html' ) );
		$zip->close();
	}
}
