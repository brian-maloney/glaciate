<?php
/**
 * Reading published files back from the urls table.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Storage;

use Glaciate\Storage\UrlRepository;

final class UrlRepositoryTest extends \WP_UnitTestCase {

	/**
	 * Marks a URL row as published at a path.
	 *
	 * @param UrlRepository $urls URLs.
	 * @param string        $url  URL.
	 * @param string        $path Output path.
	 * @return void
	 */
	private function publish( UrlRepository $urls, string $url, string $path ): void {
		$urls->update(
			$urls->ensure( $url ),
			array(
				'path'         => $path,
				'content_hash' => hash( 'sha256', $path ),
			)
		);
	}

	public function test_published_files_leave_out_paths_the_mapper_refuses_now(): void {
		$urls = new UrlRepository();
		$this->publish( $urls, 'https://origin.test/about/', 'about/index.html' );
		$this->publish( $urls, 'https://origin.test/.well-known/security.txt', '.well-known/security.txt' );
		// Rows a version before D58 could have published.
		$this->publish( $urls, 'https://origin.test/shell.phtml', 'shell.phtml' );
		$this->publish( $urls, 'https://origin.test/.htaccess', '.htaccess' );

		$files = iterator_to_array( $urls->published_files() );
		$this->assertArrayHasKey( 'about/index.html', $files );
		$this->assertArrayHasKey( '.well-known/security.txt', $files );
		$this->assertArrayNotHasKey( 'shell.phtml', $files );
		$this->assertArrayNotHasKey( '.htaccess', $files );
	}
}
