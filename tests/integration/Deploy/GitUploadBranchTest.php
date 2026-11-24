<?php
/**
 * The Git deployer's temporary upload branches, against an in-memory receive-pack (threat T16, D65).
 *
 * Unlike GitTest, these tests don't need the git.test server: they check which refs the deployer moves or deletes,
 * not what the server stores.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Deploy;

use Glaciate\Deploy\Git;
use Glaciate\Deploy\Git\RemoteClient;
use Glaciate\Deploy\TargetConfig;
use Glaciate\Security\Secrets;
use Glaciate\Support\Logger;
use Glaciate\Tests\Support\FakeGit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Test fixtures.

final class GitUploadBranchTest extends \WP_UnitTestCase {

	const URL = 'https://git.example/owner/site.git';

	/** @var string */
	private $cache;

	/** @var FakeGit */
	private $server;

	/** @var Git */
	private $deployer;

	public function set_up(): void {
		parent::set_up();
		$this->cache = sys_get_temp_dir() . '/sp-gitu-' . wp_generate_password( 6, false );
		mkdir( $this->cache, 0777, true );
		file_put_contents( $this->cache . '/index.html', 'home v1' );
		// Over one 16 MB batch, so blobs go to a temporary branch first.
		for ( $i = 1; $i <= 3; $i++ ) {
			file_put_contents( $this->cache . "/big{$i}.bin", str_repeat( "file {$i} ", 1000000 ) );
		}

		// The repository address and branch are restricted fields: an administrator sets them.
		\Glaciate\Security\Capabilities::grant_defaults();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );

		delete_option( Git::STATE_OPTION );
		$this->server   = new FakeGit();
		$this->deployer = new Git( $this->server );
		TargetConfig::set( $this->deployer, 'repository_url', self::URL );
		TargetConfig::set( $this->deployer, 'username', 'deploy' );
		TargetConfig::set( $this->deployer, 'branch', 'gh-pages' );
		TargetConfig::set( $this->deployer, 'replace_existing', false );
		Secrets::set( 'git', 'token', 'git-test-token' );
	}

	public function tear_down(): void {
		if ( $this->cache ) {
			\Glaciate\Tests\Support\Fs::rmrf( $this->cache );
		}
		parent::tear_down();
	}

	private function manifest(): array {
		$out = array();
		foreach ( glob( $this->cache . '/*' ) as $file ) {
			$out[ basename( $file ) ] = hash_file( 'sha256', $file );
		}
		ksort( $out );
		return $out;
	}

	private function deploy(): \Glaciate\Deploy\DeployResult {
		return $this->deployer->deploy( $this->manifest(), $this->cache, new Logger() );
	}

	/**
	 * Stops the deploy after its first upload batch, as the end of a background slice would.
	 */
	private function stop_after_first_batch(): void {
		$deployer            = $this->deployer;
		$this->server->after = static function ( $request ) use ( $deployer ) {
			if ( 'POST' === $request['method'] && false !== strpos( $request['body'], 'refs/heads/glaciate-upload-' ) ) {
				$deployer->set_deadline( microtime( true ) - 1 );
			}
		};
	}

	/**
	 * Upload branches the server lists, other than those in $except.
	 *
	 * @param string[] $except Ref names.
	 * @return array<string,string>
	 */
	private function upload_refs( array $except = array() ): array {
		$out = array();
		foreach ( $this->server->refs as $ref => $id ) {
			if ( 0 === strpos( $ref, 'refs/heads/glaciate-upload-' ) && ! in_array( $ref, $except, true ) ) {
				$out[ $ref ] = $id;
			}
		}
		return $out;
	}

	public function test_a_branch_of_an_upload_name_someone_else_made_is_neither_built_on_nor_deleted(): void {
		$key = substr( sha1( self::URL . '|gh-pages' ), 0, 8 );
		// The name earlier versions always used, and one in the current format.
		$fixed              = 'refs/heads/glaciate-upload-' . $key;
		$alike              = 'refs/heads/glaciate-upload-' . $key . '-ffffffffffff';
		$this->server->refs = array(
			'refs/heads/main' => str_repeat( '1', 40 ),
			$fixed            => str_repeat( '2', 40 ),
			$alike            => str_repeat( '3', 40 ),
		);

		$this->assertTrue( $this->deploy()->done );

		$this->assertSame( str_repeat( '2', 40 ), $this->server->refs[ $fixed ] );
		$this->assertSame( str_repeat( '3', 40 ), $this->server->refs[ $alike ] );
		$this->assertSame( array(), $this->server->commands_for( $fixed ), 'No command named the other branch.' );
		$this->assertSame( array(), $this->server->commands_for( $alike ) );
		$this->assertArrayHasKey( 'refs/heads/gh-pages', $this->server->refs );
		$this->assertSame( array(), $this->upload_refs( array( $fixed, $alike ) ), 'Our own temporary branch is gone.' );

		$ours = $this->server->commands_for( 'glaciate-upload-' );
		$this->assertSame( RemoteClient::ZERO, $ours[0]['old'], 'The first batch creates a branch of a new name.' );
		$this->assertSame( RemoteClient::ZERO, end( $ours )['new'], 'The last command deletes it.' );
	}

	public function test_an_interrupted_upload_resumes_on_its_branch_and_deletes_it(): void {
		$this->stop_after_first_batch();
		$this->assertFalse( $this->deploy()->done );
		$refs = $this->upload_refs();
		$this->assertCount( 1, $refs );
		$ref  = key( $refs );
		$head = current( $refs );

		$this->server->after = null;
		$this->deployer->set_deadline( 0 );
		$this->assertTrue( $this->deploy()->done );

		$commands = $this->server->commands_for( 'glaciate-upload-' );
		foreach ( $commands as $command ) {
			$this->assertSame( $ref, $command['ref'], 'The resumed deploy uses the same temporary branch.' );
			$this->assertSame( 'ok', $command['result'] );
		}
		$this->assertSame( $head, $commands[1]['old'], 'The delete names the commit the first slice recorded.' );
		$this->assertSame( RemoteClient::ZERO, end( $commands )['new'] );
		$this->assertSame( array(), $this->upload_refs() );
	}

	public function test_an_upload_branch_someone_moved_is_left_alone(): void {
		$this->stop_after_first_batch();
		$this->deploy();
		$ref                        = key( $this->upload_refs() );
		$this->server->refs[ $ref ] = str_repeat( '9', 40 );
		$before                     = count( $this->server->commands_for( $ref ) );

		$this->server->after = null;
		$this->deployer->set_deadline( 0 );
		$this->assertTrue( $this->deploy()->done );

		$this->assertSame( str_repeat( '9', 40 ), $this->server->refs[ $ref ], 'The moved branch is not deleted.' );
		$this->assertCount( $before, $this->server->commands_for( $ref ), 'Nor built on.' );
		$this->assertSame( array(), $this->upload_refs( array( $ref ) ), 'The deploy used a new temporary branch and deleted it.' );
		$this->assertArrayHasKey( 'refs/heads/gh-pages', $this->server->refs );
	}

	public function test_a_batch_whose_answer_was_lost_still_counts_as_ours(): void {
		$this->server->lose_answer = static function ( $request ) {
			return false !== strpos( $request['body'], 'refs/heads/glaciate-upload-' );
		};
		try {
			$this->deploy();
			$this->fail( 'Expected the lost answer to stop the deploy.' );
		} catch ( \RuntimeException $e ) {
			$this->assertStringContainsString( 'Could not reach', $e->getMessage() );
		}
		$refs = $this->upload_refs();
		$this->assertCount( 1, $refs, 'The server stored the batch.' );

		$this->server->lose_answer = null;
		$this->assertTrue( $this->deploy()->done );
		$this->assertSame( array(), $this->upload_refs(), 'The branch whose push went unanswered was ours, and is deleted.' );
		$this->assertSame( current( $refs ), $this->server->commands_for( key( $refs ) )[1]['old'] );
	}

	public function test_a_temporary_branch_the_server_kept_is_deleted_after_the_next_publish(): void {
		$this->server->refuse_delete = static function ( $ref ) {
			return 0 === strpos( $ref, 'refs/heads/glaciate-upload-' ) ? 'refusing to delete the current branch' : null;
		};
		$this->assertTrue( $this->deploy()->done, 'The site is published even though the temporary branch stays.' );
		$refs = $this->upload_refs();
		$this->assertCount( 1, $refs );

		$this->server->refuse_delete = null;
		file_put_contents( $this->cache . '/index.html', 'home v2' );
		$this->assertTrue( $this->deploy()->done );
		$this->assertSame( array(), $this->upload_refs(), 'The next publish deletes it.' );
		$last = end( $this->server->commands );
		$this->assertSame( key( $refs ), $last['ref'] );
		$this->assertSame( current( $refs ), $last['old'], 'The delete names the commit we left on it.' );
	}

	public function test_a_kept_temporary_branch_that_moved_since_is_not_deleted(): void {
		$this->server->refuse_delete = static function ( $ref ) {
			return 0 === strpos( $ref, 'refs/heads/glaciate-upload-' ) ? 'refusing to delete the current branch' : null;
		};
		$this->deploy();
		$ref                        = key( $this->upload_refs() );
		$this->server->refs[ $ref ] = str_repeat( '9', 40 );
		$before                     = count( $this->server->commands_for( $ref ) );

		$this->server->refuse_delete = null;
		file_put_contents( $this->cache . '/index.html', 'home v2' );
		$this->assertTrue( $this->deploy()->done );
		$this->assertSame( str_repeat( '9', 40 ), $this->server->refs[ $ref ] );
		$this->assertCount( $before, $this->server->commands_for( $ref ) );

		// The record is dropped, so later publishes don't try either.
		file_put_contents( $this->cache . '/index.html', 'home v3' );
		$this->deploy();
		$this->assertCount( $before, $this->server->commands_for( $ref ) );
	}
}
