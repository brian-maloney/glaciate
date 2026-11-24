<?php
/**
 * Rules for `_redirects` and `_headers` built from crawled paths.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Unit\Build;

use PHPUnit\Framework\TestCase;
use Glaciate\Build\Generated;
use Glaciate\Rewrite\OriginSet;

final class GeneratedTest extends TestCase {

	private function origins( string $origin = 'https://live.test', string $public_base = 'https://static.test' ): OriginSet {
		return new OriginSet( $origin, $public_base );
	}

	private function row( string $url, string $to = 'https://static.test/hello-world/', int $code = 301 ): array {
		return array(
			'url'         => $url,
			'redirect_to' => $to,
			'status_code' => $code,
		);
	}

	public function test_plain_redirects_become_rules(): void {
		$rules = Generated::origin_redirect_rules(
			$this->origins(),
			array(
				$this->row( 'https://live.test/old-name/' ),
				$this->row( 'https://live.test', 'https://elsewhere.test/', 302 ),
				$this->row( 'https://live.test/a/b%20c/', 'https://static.test/', 418 ),
			)
		);
		$this->assertSame(
			array(
				array( '/old-name/', '/hello-world/', 301 ),
				array( '/', 'https://elsewhere.test/', 302 ),
				array( '/a/b%20c/', '/', 301 ),
			),
			$rules
		);
	}

	/**
	 * @dataProvider provide_pattern_paths
	 */
	public function test_crawled_paths_with_pattern_syntax_produce_no_redirect_rule( string $url ): void {
		$this->assertSame( array(), Generated::origin_redirect_rules( $this->origins(), array( $this->row( $url ) ) ) );
	}

	/**
	 * @dataProvider provide_pattern_paths
	 */
	public function test_crawled_paths_with_pattern_syntax_produce_no_header_rule( string $url ): void {
		$files = array(
			'x/index.html' => array(
				'url'  => $url,
				'type' => 'application/rss+xml',
			),
		);
		$this->assertSame( array(), Generated::directory_header_rules( $this->origins(), $files ) );
	}

	public function provide_pattern_paths(): array {
		return array(
			'splat'                 => array( 'https://live.test/h*' ),
			'splat in a segment'    => array( 'https://live.test/a/*h/' ),
			'placeholder'           => array( 'https://live.test/:h' ),
			'placeholder mid-path'  => array( 'https://live.test/a/:splat/b/' ),
			'placeholder and splat' => array( 'https://live.test/:h/x*' ),
			'colon inside segment'  => array( 'https://live.test/a:b/' ),
			'encoded splat'         => array( 'https://live.test/h%2a' ),
			'encoded colon'         => array( 'https://live.test/%3Ah' ),
		);
	}

	public function test_header_rules_keep_literal_paths_under_the_public_base(): void {
		$files = array(
			'feed/index.html'     => array(
				'url'  => 'https://live.test/wp/feed/',
				'type' => 'application/rss+xml; charset=UTF-8',
			),
			'bad/index.html'      => array(
				'url'  => 'https://live.test/wp/:x/feed/',
				'type' => 'application/rss+xml',
			),
			'cat/feed/index.html' => array(
				'url'  => 'https://live.test/wp/cat/feed/',
				'type' => 'application/atom+xml',
			),
		);
		$this->assertSame(
			array(
				array( '/blog/feed/', array( 'Content-Type' => 'application/rss+xml; charset=UTF-8' ) ),
				array( '/blog/cat/feed/', array( 'Content-Type' => 'application/atom+xml' ) ),
			),
			Generated::directory_header_rules( $this->origins( 'https://live.test/wp', 'https://static.test/blog' ), $files )
		);
	}

	/**
	 * @dataProvider provide_literal_checks
	 */
	public function test_is_literal_path( string $path, bool $expected ): void {
		$this->assertSame( $expected, Generated::is_literal_path( $path ) );
	}

	public function provide_literal_checks(): array {
		return array(
			'root'                => array( '/', true ),
			'page'                => array( '/about/team/', true ),
			'sub-delims and @'    => array( "/a!b\$c&d'e(f)g+h,i;j=k@l~m/", true ),
			'percent-encoded'     => array( '/caf%C3%A9/%20/', true ),
			'empty'               => array( '', false ),
			'relative'            => array( 'about/', false ),
			'splat'               => array( '/*', false ),
			'placeholder'         => array( '/:slug/', false ),
			'comment marker'      => array( '/a#b', false ),
			'query'               => array( '/a?id=1', false ),
			'space'               => array( '/a b', false ),
			'NUL'                 => array( "/a\0b", false ),
			'encoded splat, case' => array( '/a%2Ab', false ),
			'encoded colon, case' => array( '/a%3ab', false ),
		);
	}
}
