<?php
/**
 * Paths the Git deployer never commits (threat T27).
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Glaciate\Deploy\Git\ReservedPaths;

final class GitReservedPathsTest extends TestCase {

	/**
	 * @return array<string,array{0:string}>
	 */
	public function reserved_provider(): array {
		return array(
			'workflow'                 => array( '.github/workflows/deploy.yml' ),
			'workflow, upper case'     => array( '.GitHub/workflows/deploy.yml' ),
			'github folder only'       => array( '.github' ),
			'git folder'               => array( '.git/config' ),
			'git folder, nested'       => array( 'docs/.git/hooks/post-checkout' ),
			'git, mixed case'          => array( 'a/.GiT/HEAD' ),
			'git, trailing dot'        => array( '.git./config' ),
			'git, trailing spaces'     => array( '.git  /config' ),
			'git, HFS ignorable'       => array( ".g\u{200C}it/config" ),
			'git, leading ignorable'   => array( "\u{FEFF}.git/config" ),
			'git, backslash separator' => array( 'a\\.git\\config' ),
			'git short name'           => array( 'git~1/config' ),
			'git short name, upper'    => array( 'GIT~1/config' ),
			'git short name, dot'      => array( 'git~1./config' ),
			'gitmodules short name'    => array( 'gitmod~1' ),
			'gitmodules hash name'     => array( 'gi7eba~1' ),
			'gitattributes short name' => array( 'gitatt~2' ),
			'gitlab folder'            => array( '.gitlab/issue_templates/x.md' ),
			'gitlab ci'                => array( '.gitlab-ci.yml' ),
			'gitlab ci, nested'        => array( 'sub/.GITLAB-CI.YML' ),
			'gitattributes'            => array( '.gitattributes' ),
			'gitmodules'               => array( '.gitmodules' ),
			'gitea workflow'           => array( '.gitea/workflows/x.yml' ),
			'forgejo workflow'         => array( '.forgejo/workflows/x.yml' ),
		);
	}

	/**
	 * @dataProvider reserved_provider
	 */
	public function test_reserved_paths( string $path ): void {
		$this->assertTrue( ReservedPaths::is_reserved( $path ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function allowed_provider(): array {
		return array(
			'page'                   => array( 'index.html' ),
			'nested page'            => array( 'blog/hello/index.html' ),
			'well-known'             => array( '.well-known/security.txt' ),
			'marker'                 => array( '.glaciate-target' ),
			'nojekyll'               => array( '.nojekyll' ),
			'redirects'              => array( '_redirects' ),
			'git without dot'        => array( 'git/index.html' ),
			'github without dot'     => array( 'github/index.html' ),
			'gitlab-ci without dot'  => array( 'gitlab-ci.yml' ),
			'dot inside a name'      => array( 'my.git/index.html' ),
			'workflows elsewhere'    => array( 'workflows/deploy.yml' ),
			'tilde name'             => array( 'about~1/index.html' ),
			'longer short-name stem' => array( 'gitmodu~1' ),
			'forgejo prefix'         => array( '.forgejoish' ),
		);
	}

	/**
	 * @dataProvider allowed_provider
	 */
	public function test_allowed_paths( string $path ): void {
		$this->assertFalse( ReservedPaths::is_reserved( $path ) );
	}

	public function test_invalid_utf8_is_compared_as_bytes(): void {
		$this->assertTrue( ReservedPaths::is_reserved( ".git\xFF/x" ) );
		$this->assertFalse( ReservedPaths::is_reserved( "a\xFF/index.html" ) );
	}
}
