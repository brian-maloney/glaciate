<?php
/**
 * Origin aliases: other hosts that serve the same site.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Unit\Rewrite;

use PHPUnit\Framework\TestCase;
use Glaciate\Rewrite\OriginSet;
use Glaciate\Rewrite\UrlRewriter;

final class OriginAliasTest extends TestCase {

	private function set( array $aliases = array( 'www.live.test' ), string $origin = 'https://edit.live.test' ): OriginSet {
		return new OriginSet( $origin, 'https://static.live.test', $aliases );
	}

	/**
	 * @dataProvider provide_aliases
	 */
	public function test_normalizes_aliases( string $input, ?string $expected ): void {
		$this->assertSame( $expected, OriginSet::normalize_alias( $input ) );
	}

	public function provide_aliases(): array {
		return array(
			'bare host'               => array( 'www.live.test', 'www.live.test' ),
			'uppercase, trailing dot' => array( 'WWW.Live.Test.', 'www.live.test' ),
			'url with path'           => array( 'https://www.live.test/some/page/', 'www.live.test' ),
			'protocol-relative'       => array( '//www.live.test', 'www.live.test' ),
			'explicit port'           => array( 'www.live.test:8080', 'www.live.test:8080' ),
			'default port dropped'    => array( 'https://www.live.test:443/', 'www.live.test' ),
			'http default port'       => array( 'http://www.live.test:80', 'www.live.test' ),
			'ipv6'                    => array( '[::1]:8080', '[::1]:8080' ),
			'empty'                   => array( '  ', null ),
			'other scheme'            => array( 'ftp://www.live.test', null ),
			'userinfo'                => array( 'https://user@www.live.test', null ),
			'not a host'              => array( 'www live test', null ),
			'underscore'              => array( 'www_live.test', null ),
		);
	}

	public function test_skips_the_origin_itself_and_duplicates(): void {
		$set = $this->set( array( 'edit.live.test', 'https://edit.live.test/', 'www.live.test', 'WWW.live.test', 'nope nope' ) );
		$this->assertSame( array( 'www.live.test' ), $set->aliases() );
		$this->assertSame( array( 'https://edit.live.test', 'http://edit.live.test' ), $set->origins(), 'Aliases are never allowed origins.' );
		$this->assertFalse( $set->is_identity() );
	}

	/**
	 * @dataProvider provide_to_origin
	 */
	public function test_moves_alias_urls_onto_the_origin( string $url, string $expected ): void {
		$this->assertSame( $expected, $this->set( array( 'www.live.test', 'cdn.live.test:8443' ) )->to_origin( $url ) );
	}

	public function provide_to_origin(): array {
		return array(
			'https'                 => array( 'https://www.live.test/wp-content/uploads/logo.png', 'https://edit.live.test/wp-content/uploads/logo.png' ),
			'http'                  => array( 'http://www.live.test/about/', 'https://edit.live.test/about/' ),
			'case'                  => array( 'HTTPS://WWW.Live.Test/About/', 'https://edit.live.test/About/' ),
			'protocol-relative'     => array( '//www.live.test/a.css', 'https://edit.live.test/a.css' ),
			'bare host'             => array( 'https://www.live.test', 'https://edit.live.test' ),
			'query and fragment'    => array( 'https://www.live.test?p=1#x', 'https://edit.live.test?p=1#x' ),
			'default port'          => array( 'https://www.live.test:443/x', 'https://edit.live.test/x' ),
			'alias port'            => array( 'https://cdn.live.test:8443/x', 'https://edit.live.test/x' ),
			'origin unchanged'      => array( 'https://edit.live.test/x', 'https://edit.live.test/x' ),
			'other port'            => array( 'https://www.live.test:8080/x', 'https://www.live.test:8080/x' ),
			'alias without port'    => array( 'https://cdn.live.test/x', 'https://cdn.live.test/x' ),
			'lookalike suffix'      => array( 'https://www.live.test.evil/x', 'https://www.live.test.evil/x' ),
			'lookalike prefix'      => array( 'https://xwww.live.test/x', 'https://xwww.live.test/x' ),
			'userinfo'              => array( 'https://www.live.test@evil.test/x', 'https://www.live.test@evil.test/x' ),
			'userinfo on the alias' => array( 'https://me@www.live.test/x', 'https://me@www.live.test/x' ),
			'other site'            => array( 'https://elsewhere.test/x', 'https://elsewhere.test/x' ),
			'relative'              => array( '/x/', '/x/' ),
			'other scheme'          => array( 'ftp://www.live.test/x', 'ftp://www.live.test/x' ),
		);
	}

	public function test_keeps_the_origin_port(): void {
		$set = $this->set( array( 'www.live.test' ), 'http://localhost:8888' );
		$this->assertSame( 'http://localhost:8888/a/', $set->to_origin( 'https://www.live.test/a/' ) );
	}

	/**
	 * @dataProvider provide_rewrites
	 */
	public function test_rewrites_alias_urls_to_the_public_address( string $input, string $expected ): void {
		$this->assertSame( $expected, ( new UrlRewriter( $this->set() ) )->rewrite( $input ) );
	}

	public function provide_rewrites(): array {
		return array(
			'alias'             => array( '<img src="https://www.live.test/logo.png">', '<img src="https://static.live.test/logo.png">' ),
			'origin still'      => array( '<a href="https://edit.live.test/a/">', '<a href="https://static.live.test/a/">' ),
			'both'              => array( 'https://www.live.test/a https://edit.live.test/b', 'https://static.live.test/a https://static.live.test/b' ),
			'json'              => array( '{"u":"https:\\/\\/www.live.test\\/a"}', '{"u":"https:\\/\\/static.live.test\\/a"}' ),
			'urlencoded'        => array( '?u=https%3A%2F%2Fwww.live.test%2Fa', '?u=https%3A%2F%2Fstatic.live.test%2Fa' ),
			'protocol-relative' => array( 'url(//www.live.test/a.png)', 'url(//static.live.test/a.png)' ),
			'lookalike'         => array( 'https://www.live.test.evil/a', 'https://www.live.test.evil/a' ),
			'other port'        => array( 'https://www.live.test:8080/a', 'https://www.live.test:8080/a' ),
			'only the alias'    => array( 'see https://www.live.test/', 'see https://static.live.test/' ),
		);
	}

	public function test_aliases_share_the_origin_home_path(): void {
		$set = new OriginSet( 'https://edit.live.test/blog/', 'https://static.live.test', array( 'www.live.test' ) );
		$out = ( new UrlRewriter( $set ) )->rewrite( '<a href="https://www.live.test/blog/post/"></a><img src="https://www.live.test/wp-includes/x.js">' );
		$this->assertSame( '<a href="https://static.live.test/post/"></a><img src="https://static.live.test/wp-includes/x.js">', $out );
	}
}
