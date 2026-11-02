<?php
/**
 * RFC 3986 resolution tests.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use Stillpress\Support\Url;

final class UrlTest extends TestCase {

	/**
	 * Examples from RFC 3986 section 5.4, base "http://a/b/c/d;p?q".
	 *
	 * @dataProvider provide_rfc_examples
	 */
	public function test_resolves_rfc_3986_examples( string $reference, string $expected ): void {
		$this->assertSame( $expected, Url::resolve( 'http://a/b/c/d;p?q', $reference ) );
	}

	public function provide_rfc_examples(): array {
		return array(
			array( 'g', 'http://a/b/c/g' ),
			array( './g', 'http://a/b/c/g' ),
			array( 'g/', 'http://a/b/c/g/' ),
			array( '/g', 'http://a/g' ),
			array( '//g', 'http://g' ),
			array( '?y', 'http://a/b/c/d;p?y' ),
			array( 'g?y', 'http://a/b/c/g?y' ),
			array( '#s', 'http://a/b/c/d;p?q#s' ),
			array( 'g#s', 'http://a/b/c/g#s' ),
			array( 'g?y#s', 'http://a/b/c/g?y#s' ),
			array( ';x', 'http://a/b/c/;x' ),
			array( 'g;x', 'http://a/b/c/g;x' ),
			array( '', 'http://a/b/c/d;p?q' ),
			array( '.', 'http://a/b/c/' ),
			array( './', 'http://a/b/c/' ),
			array( '..', 'http://a/b/' ),
			array( '../', 'http://a/b/' ),
			array( '../g', 'http://a/b/g' ),
			array( '../..', 'http://a/' ),
			array( '../../', 'http://a/' ),
			array( '../../g', 'http://a/g' ),
			array( '../../../g', 'http://a/g' ),
			array( '../../../../g', 'http://a/g' ),
			array( '/./g', 'http://a/g' ),
			array( '/../g', 'http://a/g' ),
			array( 'g.', 'http://a/b/c/g.' ),
			array( '.g', 'http://a/b/c/.g' ),
			array( 'g..', 'http://a/b/c/g..' ),
			array( '..g', 'http://a/b/c/..g' ),
			array( './../g', 'http://a/b/g' ),
			array( './g/.', 'http://a/b/c/g/' ),
			array( 'g/./h', 'http://a/b/c/g/h' ),
			array( 'g/../h', 'http://a/b/c/h' ),
			array( 'g;x=1/./y', 'http://a/b/c/g;x=1/y' ),
			array( 'g;x=1/../y', 'http://a/b/c/y' ),
			array( 'https://other/x/../y', 'https://other/y' ),
		);
	}

	public function test_returns_null_for_unparseable_base(): void {
		$this->assertNull( Url::resolve( 'not a url', 'x' ) );
	}

	/**
	 * @dataProvider provide_hosts
	 */
	public function test_private_hosts( string $host, bool $expected ): void {
		$this->assertSame( $expected, Url::is_private_host( $host ) );
	}

	public function provide_hosts(): array {
		return array(
			'localhost'          => array( 'localhost', true ),
			'subdomain of it'    => array( 'site.localhost', true ),
			'mDNS name'          => array( 'mysite.local', true ),
			'test TLD'           => array( 'example.test', true ),
			'loopback'           => array( '127.0.0.1', true ),
			'private IPv4'       => array( '192.168.1.20', true ),
			'IPv6 loopback'      => array( '[::1]', true ),
			'unique local IPv6'  => array( 'fd00::1', true ),
			'public name'        => array( 'www.example.org', false ),
			'public IPv4'        => array( '93.184.216.34', false ),
			'local in the label' => array( 'local.example.org', false ),
			'dev TLD is public'  => array( 'mysite.dev', false ),
		);
	}
}
