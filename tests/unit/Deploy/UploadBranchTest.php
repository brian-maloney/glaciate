<?php
/**
 * Which temporary upload branches the Git deployer may build on or delete (threat T16, D65).
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Unit\Deploy;

use PHPUnit\Framework\TestCase;
use Glaciate\Deploy\Git\UploadBranch;

final class UploadBranchTest extends TestCase {

	const KEY = '0123abcd0123abcd0123abcd0123abcd';

	const OURS = 'refs/heads/glaciate-upload-0123abcd-aaaaaaaaaaaa';

	/**
	 * @param string ...$suffixes Values the random source returns, in order.
	 * @return callable
	 */
	private function random( string ...$suffixes ): callable {
		return static function () use ( &$suffixes ) {
			return array_shift( $suffixes );
		};
	}

	private function state( string $ref, string $head, string $next = '' ): array {
		return array(
			'head'        => '',
			'upload_ref'  => $ref,
			'upload_head' => $head,
			'upload_next' => $next,
			'stale'       => array(),
		);
	}

	public function test_a_recorded_upload_the_server_still_holds_resumes(): void {
		$head   = str_repeat( 'a', 40 );
		$choice = UploadBranch::choose( $this->state( self::OURS, $head ), array( self::OURS => $head ), self::KEY, $this->random( 'bbbbbbbbbbbb' ) );
		$this->assertTrue( $choice['resume'] );
		$this->assertSame( self::OURS, $choice['upload_ref'] );
		$this->assertSame( $head, $choice['upload_head'] );
	}

	public function test_a_push_whose_answer_was_lost_still_counts_as_ours(): void {
		$head   = str_repeat( 'a', 40 );
		$next   = str_repeat( 'c', 40 );
		$choice = UploadBranch::choose( $this->state( self::OURS, $head, $next ), array( self::OURS => $next ), self::KEY, $this->random( 'bbbbbbbbbbbb' ) );
		$this->assertTrue( $choice['resume'] );
		$this->assertSame( $next, $choice['upload_head'] );
		$this->assertSame( '', $choice['upload_next'] );
	}

	public function test_a_branch_we_never_pushed_to_is_never_adopted(): void {
		// Someone created a branch of the name we picked before our first push reached the server.
		$theirs = str_repeat( 'f', 40 );
		$choice = UploadBranch::choose( $this->state( self::OURS, '' ), array( self::OURS => $theirs ), self::KEY, $this->random( 'bbbbbbbbbbbb' ) );
		$this->assertFalse( $choice['resume'] );
		$this->assertSame( 'refs/heads/glaciate-upload-0123abcd-bbbbbbbbbbbb', $choice['upload_ref'] );
		$this->assertSame( '', $choice['upload_head'], 'A fresh branch has no head: its first push creates it.' );
	}

	public function test_a_recorded_branch_that_moved_is_left_alone(): void {
		$choice = UploadBranch::choose( $this->state( self::OURS, str_repeat( 'a', 40 ) ), array( self::OURS => str_repeat( 'f', 40 ) ), self::KEY, $this->random( 'bbbbbbbbbbbb' ) );
		$this->assertFalse( $choice['resume'] );
		$this->assertNotSame( self::OURS, $choice['upload_ref'] );
		$this->assertSame( '', $choice['upload_head'] );
	}

	public function test_a_fresh_name_avoids_branches_the_server_lists(): void {
		$taken  = 'refs/heads/glaciate-upload-0123abcd-bbbbbbbbbbbb';
		$choice = UploadBranch::choose( $this->state( '', '' ), array( $taken => str_repeat( 'f', 40 ) ), self::KEY, $this->random( 'bbbbbbbbbbbb', 'dddddddddddd' ) );
		$this->assertSame( 'refs/heads/glaciate-upload-0123abcd-dddddddddddd', $choice['upload_ref'] );
	}

	public function test_the_old_fixed_name_is_never_trusted(): void {
		// Earlier versions recorded whatever head the server listed for refs/heads/glaciate-upload-<key>.
		$old    = 'refs/heads/glaciate-upload-0123abcd';
		$head   = str_repeat( 'a', 40 );
		$choice = UploadBranch::choose( $this->state( $old, $head ), array( $old => $head ), self::KEY, $this->random( 'bbbbbbbbbbbb' ) );
		$this->assertFalse( $choice['resume'] );
		$this->assertSame( array(), UploadBranch::deletable( array( $old => $head ), array( $old => $head ) ) );
	}

	public function test_random_part_is_reduced_to_hex(): void {
		$choice = UploadBranch::choose( $this->state( '', '' ), array(), self::KEY, $this->random( "AB/../c\nd" ) );
		$this->assertSame( 'refs/heads/glaciate-upload-0123abcd-abcd', $choice['upload_ref'] );
	}

	public function test_only_stale_branches_still_at_the_recorded_commit_are_deletable(): void {
		$a     = 'refs/heads/glaciate-upload-0123abcd-aaaaaaaaaaaa';
		$b     = 'refs/heads/glaciate-upload-0123abcd-bbbbbbbbbbbb';
		$c     = 'refs/heads/glaciate-upload-0123abcd-cccccccccccc';
		$stale = array(
			$a                => str_repeat( '1', 40 ),
			$b                => str_repeat( '2', 40 ),
			$c                => str_repeat( '3', 40 ),
			'refs/heads/main' => str_repeat( '4', 40 ),
		);
		$refs  = array(
			$a                => str_repeat( '1', 40 ), // Unchanged: ours.
			$b                => str_repeat( '9', 40 ), // Moved: not ours any more.
			'refs/heads/main' => str_repeat( '4', 40 ), // Never an upload branch.
		);
		// $c is gone.
		$this->assertSame( array( $a => str_repeat( '1', 40 ) ), UploadBranch::deletable( $stale, $refs ) );
	}

	public function test_remember_keeps_the_newest_records(): void {
		$stale = array();
		for ( $i = 0; $i < UploadBranch::MAX_STALE + 3; $i++ ) {
			$stale = UploadBranch::remember( $stale, sprintf( 'refs/heads/glaciate-upload-0123abcd-%012x', $i ), str_repeat( 'a', 40 ) );
		}
		$this->assertCount( UploadBranch::MAX_STALE, $stale );
		$this->assertArrayHasKey( sprintf( 'refs/heads/glaciate-upload-0123abcd-%012x', UploadBranch::MAX_STALE + 2 ), $stale );
		$this->assertArrayNotHasKey( sprintf( 'refs/heads/glaciate-upload-0123abcd-%012x', 0 ), $stale );
	}
}
