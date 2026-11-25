<?php
/**
 * Path traversal and mapping tests (threats T2 and T27).
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Glaciate\Output\PathMapper;
use Glaciate\Output\UnsafePathException;

final class PathMapperTest extends TestCase {

	/** @var string */
	private $tmp;

	protected function setUp(): void {
		$this->tmp = sys_get_temp_dir() . '/glaciate-pm-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tmp . '/root', 0777, true );
		mkdir( $this->tmp . '/outside', 0777, true );
	}

	protected function tearDown(): void {
		\Glaciate\Tests\Support\Fs::rmrf( $this->tmp );
	}

	private function mapper(): PathMapper {
		return new PathMapper( $this->tmp . '/root' );
	}

	/**
	 * @dataProvider provide_valid_mappings
	 */
	public function test_maps_url_paths_to_relative_files( string $url_path, string $type, string $expected ): void {
		$this->assertSame( $expected, $this->mapper()->relative_path( $url_path ) );
	}

	public function provide_valid_mappings(): array {
		return array(
			'root'                     => array( '/', 'text/html; charset=UTF-8', 'index.html' ),
			'empty is root'            => array( '', 'text/html', 'index.html' ),
			'pretty permalink'         => array( '/2026/10/25/hello-world/', 'text/html', '2026/10/25/hello-world/index.html' ),
			'no trailing slash'        => array( '/about', 'text/html', 'about/index.html' ),
			'file with extension'      => array( '/robots.txt', 'text/plain', 'robots.txt' ),
			'asset'                    => array( '/wp-content/uploads/2026/10/a.jpg', 'image/jpeg', 'wp-content/uploads/2026/10/a.jpg' ),
			'feed directory'           => array( '/feed/', 'application/rss+xml; charset=UTF-8', 'feed/index.html' ),
			'atom feed'                => array( '/feed/atom/', 'application/atom+xml', 'feed/atom/index.html' ),
			'json directory'           => array( '/data/', 'application/ld+json', 'data/index.html' ),
			'text directory'           => array( '/notes/', 'text/plain', 'notes/index.html' ),
			'sitemap file'             => array( '/wp-sitemap.xml', 'application/xml', 'wp-sitemap.xml' ),
			'percent-encoded space'    => array( '/wp-content/uploads/my%20file.pdf', 'application/pdf', 'wp-content/uploads/my file.pdf' ),
			'unicode slug'             => array( '/%E6%97%A5%E6%9C%AC/', 'text/html', '日本/index.html' ),
			'dot inside name'          => array( '/v1.2/release/', 'text/html', 'v1.2/release/index.html' ),
			'extension then slash'     => array( '/file.html/', 'text/html', 'file.html/index.html' ),
			'unknown type is html'     => array( '/x/', '', 'x/index.html' ),
			'well-known file'          => array( '/.well-known/security.txt', 'text/plain', '.well-known/security.txt' ),
			'well-known nested'        => array( '/.well-known/acme-challenge/tok.txt', 'text/plain', '.well-known/acme-challenge/tok.txt' ),
			'well-known directory'     => array( '/.well-known/openid-configuration/', 'application/json', '.well-known/openid-configuration/index.html' ),
			'stem named like an ext'   => array( '/a/php.js', 'text/javascript', 'a/php.js' ),
			'polish locale file'       => array( '/i18n/pl.js', 'text/javascript', 'i18n/pl.js' ),
			'inner harmless extension' => array( '/a/jquery.min.js', 'text/javascript', 'a/jquery.min.js' ),
			'source map'               => array( '/a/style.css.map', 'application/json', 'a/style.css.map' ),
			'tarball'                  => array( '/f/archive.tar.gz', 'application/gzip', 'f/archive.tar.gz' ),
			'php inside a word'        => array( '/graphql-and-phpunit/', 'text/html', 'graphql-and-phpunit/index.html' ),
		);
	}

	/**
	 * @dataProvider provide_hostile_paths
	 */
	public function test_rejects_hostile_url_paths( string $url_path ): void {
		$this->expectException( UnsafePathException::class );
		$this->mapper()->relative_path( $url_path );
	}

	public function provide_hostile_paths(): array {
		return array(
			'parent segment'               => array( '/../wp-config.php' ),
			'nested parent'                => array( '/a/b/../../../etc/passwd' ),
			'parent at end'                => array( '/a/..' ),
			'dot segment'                  => array( '/a/./b' ),
			'encoded parent'               => array( '/%2e%2e/wp-config.php' ),
			'mixed-case encoded parent'    => array( '/%2E%2e/x' ),
			'half-encoded parent'          => array( '/.%2e/x' ),
			'encoded slash traversal'      => array( '/..%2f..%2fetc/passwd' ),
			'encoded slash inside segment' => array( '/a%2Fb/' ),
			'encoded backslash'            => array( '/..%5c..%5cwin.ini' ),
			'raw backslash'                => array( '/a\\..\\b' ),
			'NUL byte raw'                 => array( "/a\0.html" ),
			'NUL byte encoded'             => array( '/index.php%00.html' ),
			'control char encoded'         => array( '/a%0A/' ),
			'DEL encoded'                  => array( '/a%7F/' ),
			'double slash'                 => array( '//etc/passwd' ),
			'double slash only'            => array( '//' ),
			'empty middle segment'         => array( '/a//b/' ),
			'no leading slash'             => array( 'etc/passwd' ),
			'query string'                 => array( '/a?b=c' ),
			'fragment'                     => array( '/a#b' ),
			'drive letter'                 => array( '/C:/Windows/' ),
			'encoded colon (NTFS ADS)'     => array( '/file.txt%3A%3A%24DATA' ),
			'windows device name'          => array( '/CON/' ),
			'windows device with ext'      => array( '/aux.txt' ),
			'windows device COM1'          => array( '/com1/' ),
			'trailing dot'                 => array( '/a./' ),
			'trailing space'               => array( '/a%20/' ),
			'encoded double-dot only'      => array( '/%2e%2e' ),
			'reserved wildcard'            => array( '/a%2A/' ),
			'pipe'                         => array( '/a%7Cb/' ),
			'invalid utf-8'                => array( '/%C3%28/' ),
			'overlong segment'             => array( '/' . str_repeat( 'a', 256 ) . '/' ),
			'too deep'                     => array( str_repeat( '/a', 40 ) . '/' ),
			// Names a web server might run or read as configuration (threat T27).
			'htaccess'                     => array( '/.htaccess' ),
			'encoded htaccess'             => array( '/%2Ehtaccess' ),
			'user.ini'                     => array( '/a/.user.ini' ),
			'git directory'                => array( '/.git/config' ),
			'dotfile with extension'       => array( '/a/.well-known.txt' ),
			'dotfile inside well-known'    => array( '/.well-known/.htaccess' ),
			'well-known in other case'     => array( '/.Well-Known/x.txt' ),
			'phtml'                        => array( '/shell.phtml' ),
			'phtml upper case'             => array( '/shell.PHTML' ),
			'php5'                         => array( '/shell.php5' ),
			'php7'                         => array( '/shell.php7' ),
			'phar'                         => array( '/shell.phar' ),
			'pht'                          => array( '/shell.pht' ),
			'phps'                         => array( '/shell.phps' ),
			'shtml'                        => array( '/page.shtml' ),
			'cgi'                          => array( '/run.cgi' ),
			'perl'                         => array( '/run.pl' ),
			'python'                       => array( '/run.py' ),
			'asp'                          => array( '/page.asp' ),
			'aspx'                         => array( '/page.aspx' ),
			'jsp'                          => array( '/page.jsp' ),
			'ini'                          => array( '/php.ini' ),
			'php as inner extension'       => array( '/wp-content/uploads/shell.php.jpg' ),
			'phtml as inner extension'     => array( '/shell.phtml.html' ),
			'iis semicolon'                => array( '/shell.asp%3B.jpg' ),
			'web.config'                   => array( '/web.config' ),
			'web.config any case'          => array( '/Web.Config' ),
			'active directory segment'     => array( '/x.php5/' ),
			'active name mid-path'         => array( '/a/run.cgi/b.html' ),
		);
	}

	/**
	 * @dataProvider provide_inert_names
	 */
	public function test_is_inert_name( string $name, bool $expected ): void {
		$this->assertSame( $expected, PathMapper::is_inert_name( $name ) );
	}

	public function provide_inert_names(): array {
		return array(
			'page index'      => array( 'index.html', true ),
			'image'           => array( 'photo.JPG', true ),
			'well-known'      => array( '.well-known', true ),
			'redirects file'  => array( '_redirects', true ),
			'headers file'    => array( '_headers', true ),
			'search shard'    => array( '6162.json', true ),
			'stem php'        => array( 'php.js', true ),
			'stem only'       => array( 'php', true ),
			'phpx is not php' => array( 'notes.phpx', true ),
			'empty'           => array( '', false ),
			'dotfile'         => array( '.env', false ),
			'htaccess'        => array( '.htaccess', false ),
			'htpasswd'        => array( '.htpasswd', false ),
			'php'             => array( 'a.php', false ),
			'php8 mixed case' => array( 'a.PhP8', false ),
			'inner php'       => array( 'a.php.png', false ),
			'shtm'            => array( 'a.shtm', false ),
			'fcgi'            => array( 'a.fcgi', false ),
			'cfm'             => array( 'a.cfm', false ),
			'ashx'            => array( 'a.ashx', false ),
			'cshtml'          => array( 'a.cshtml', false ),
			'web.config'      => array( 'web.config', false ),
			'semicolon trick' => array( 'a.asp;.jpg', false ),
		);
	}

	/**
	 * Generated files the plugin writes itself must stay writable.
	 *
	 * @dataProvider provide_generated_paths
	 */
	public function test_generated_paths_are_accepted( string $relative ): void {
		PathMapper::assert_safe_relative( $relative );
		$this->assertSame( $this->tmp . '/root/' . $relative, $this->mapper()->absolute_path( $relative ) );
	}

	public function provide_generated_paths(): array {
		return array(
			'redirects'       => array( '_redirects' ),
			'headers'         => array( '_headers' ),
			'search meta'     => array( 'glaciate-search/meta.json' ),
			'search shard'    => array( 'glaciate-search/terms/6162.json' ),
			'search docs'     => array( 'glaciate-search/docs/0.json' ),
			'redirect page'   => array( 'old-page/index.html' ),
			'well-known file' => array( '.well-known/security.txt' ),
		);
	}

	/**
	 * @dataProvider provide_active_relative
	 */
	public function test_absolute_path_refuses_active_names( string $relative ): void {
		$this->expectException( UnsafePathException::class );
		$this->mapper()->absolute_path( $relative );
	}

	/**
	 * Covers manifest entries, ZIP entry names and the glaciate_output_path filter, which all use this check.
	 *
	 * @dataProvider provide_active_relative
	 */
	public function test_assert_safe_relative_refuses_active_names( string $relative ): void {
		$this->expectException( UnsafePathException::class );
		PathMapper::assert_safe_relative( $relative );
	}

	public function provide_active_relative(): array {
		return array(
			'htaccess'      => array( '.htaccess' ),
			'user.ini'      => array( 'blog/.user.ini' ),
			'phtml'         => array( 'shell.phtml' ),
			'inner php'     => array( 'uploads/a.php.jpg' ),
			'web.config'    => array( 'web.config' ),
			'git directory' => array( '.git/config' ),
			'github flow'   => array( '.github/workflows/x.yml' ),
		);
	}

	public function test_removable_path_allows_active_names_a_past_version_wrote(): void {
		$mapper = $this->mapper();
		$this->assertSame( $this->tmp . '/root/shell.phtml', $mapper->removable_path( 'shell.phtml' ) );
		$this->assertSame( $this->tmp . '/root/.htaccess', $mapper->removable_path( '.htaccess' ) );
	}

	/**
	 * @dataProvider provide_hostile_relative
	 */
	public function test_removable_path_keeps_traversal_checks( string $relative ): void {
		$this->expectException( UnsafePathException::class );
		$this->mapper()->removable_path( $relative );
	}

	public function test_removable_path_refuses_symlinks(): void {
		symlink( $this->tmp . '/outside', $this->tmp . '/root/link' );
		$this->expectException( UnsafePathException::class );
		$this->mapper()->removable_path( 'link/shell.phtml' );
	}

	public function test_double_encoding_is_decoded_only_once(): void {
		// "%252e%252e" decodes once to the literal name "%2e%2e", which is a harmless file name.
		$this->assertSame( '%2e%2e/index.html', $this->mapper()->relative_path( '/%252e%252e/' ) );
	}

	public function test_absolute_path_stays_in_root(): void {
		$mapper = $this->mapper();
		$this->assertSame( $this->tmp . '/root/a/b/index.html', $mapper->absolute_path( 'a/b/index.html' ) );
	}

	/**
	 * @dataProvider provide_hostile_relative
	 */
	public function test_absolute_path_rejects_hostile_relative_paths( string $relative ): void {
		$this->expectException( UnsafePathException::class );
		$this->mapper()->absolute_path( $relative );
	}

	public function provide_hostile_relative(): array {
		return array(
			'empty'            => array( '' ),
			'absolute'         => array( '/etc/passwd' ),
			'parent'           => array( '../outside/x.html' ),
			'nested parent'    => array( 'a/../../outside/x.html' ),
			'backslash'        => array( 'a\\b' ),
			'nul'              => array( "a\0b" ),
			'dot'              => array( './a' ),
			'trailing slash'   => array( 'a/' ),
			'zip slip windows' => array( 'C:/x' ),
		);
	}

	public function test_refuses_symlinked_directory_inside_root(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'symlink() unavailable.' );
		}
		symlink( $this->tmp . '/outside', $this->tmp . '/root/link' );
		$this->expectException( UnsafePathException::class );
		$this->mapper()->absolute_path( 'link/index.html' );
	}

	public function test_refuses_symlinked_file_inside_root(): void {
		file_put_contents( $this->tmp . '/outside/secret.txt', 'x' );
		symlink( $this->tmp . '/outside/secret.txt', $this->tmp . '/root/secret.txt' );
		$this->expectException( UnsafePathException::class );
		$this->mapper()->absolute_path( 'secret.txt' );
	}

	public function test_refuses_path_through_a_file(): void {
		file_put_contents( $this->tmp . '/root/a', 'x' );
		$this->expectException( UnsafePathException::class );
		$this->mapper()->absolute_path( 'a/index.html' );
	}

	public function test_root_that_is_itself_a_symlink_is_allowed(): void {
		// Hosting setups often symlink the document root; only links *inside* the root are refused.
		symlink( $this->tmp . '/root', $this->tmp . '/root-link' );
		$mapper = new PathMapper( $this->tmp . '/root-link' );
		$this->assertSame( $this->tmp . '/root-link/a.html', $mapper->absolute_path( 'a.html' ) );
	}

	public function test_root_must_be_absolute(): void {
		$this->expectException( UnsafePathException::class );
		new PathMapper( 'relative/dir' );
	}

	public function test_root_must_not_contain_dot_segments(): void {
		$this->expectException( UnsafePathException::class );
		new PathMapper( '/var/www/../etc' );
	}

	public function test_fuzzed_paths_never_escape_root(): void {
		$alphabet = array( '/', '.', '..', '%2e', '%2f', '%5c', 'a', '%00', '%252e', ':', '~', ' ', '%20' );
		$mapper   = $this->mapper();
		mt_srand( 1234 );
		for ( $i = 0; $i < 5000; $i++ ) {
			$path = '/';
			$len  = mt_rand( 1, 8 );
			for ( $j = 0; $j < $len; $j++ ) {
				$path .= $alphabet[ mt_rand( 0, count( $alphabet ) - 1 ) ];
			}
			try {
				$relative = $mapper->relative_path( $path );
				$absolute = $mapper->absolute_path( $relative );
			} catch ( UnsafePathException $e ) {
				continue;
			}
			$this->assertStringStartsWith( $this->tmp . '/root/', $absolute, "Escaped for input {$path}" );
			$this->assertStringNotContainsString( '/../', '/' . $relative . '/', "Dot-dot in output for {$path}" );
			$this->assertNotSame( '..', basename( $relative ) );
		}
	}
}
