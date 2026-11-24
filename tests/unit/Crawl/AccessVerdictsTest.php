<?php
/**
 * What the web server's answers allow: copy from disk, fetch over HTTP, or leave out (threat T3, D53).
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Unit\Crawl;

use PHPUnit\Framework\TestCase;
use Glaciate\Crawl\AccessVerdicts;

final class AccessVerdictsTest extends TestCase {

	private function verdicts(): AccessVerdicts {
		return new AccessVerdicts( array( '/wp-content/uploads', '/wp-content/themes', '/wp-includes' ) );
	}

	/**
	 * @dataProvider provide_statuses
	 */
	public function test_maps_answers_to_verdicts( int $status, ?string $expected ): void {
		$this->assertSame( $expected, AccessVerdicts::verdict_for( $status ) );
	}

	public function provide_statuses(): array {
		return array(
			'served'            => array( 200, 'copy' ),
			'range served'      => array( 206, 'copy' ),
			'range unsatisfied' => array( 416, 'copy' ),
			'basic auth'        => array( 401, 'deny' ),
			'forbidden'         => array( 403, 'deny' ),
			'redirect to login' => array( 302, 'fetch' ),
			'hidden as missing' => array( 404, 'fetch' ),
			'transport failure' => array( 0, null ),
			'rate limited'      => array( 429, null ),
			'overloaded'        => array( 503, null ),
		);
	}

	public function test_pattern_is_the_directory_and_file_type(): void {
		$this->assertSame( '/wp-content/uploads/2026/10/*.jpg', AccessVerdicts::pattern( '/wp-content/uploads/2026/10/Photo.JPG' ) );
		$this->assertSame( '/wp-content/uploads/my%20dir/*.pdf', AccessVerdicts::pattern( '/wp-content/uploads/my%20dir/a%20b.pdf' ) );
		$this->assertNull( AccessVerdicts::pattern( '/wp-content/uploads/README' ) );
		$this->assertNull( AccessVerdicts::pattern( 'photo.jpg' ) );
	}

	public function test_unasked_paths_have_no_verdict(): void {
		$this->assertNull( $this->verdicts()->get( '/wp-content/uploads/2026/10/photo.jpg' ) );
	}

	public function test_one_answer_covers_the_directory_and_file_type(): void {
		$verdicts = $this->verdicts();
		$this->assertSame( 'copy', $verdicts->record( '/wp-content/uploads/2026/10/a.jpg', 200 ) );
		$this->assertSame( 'copy', $verdicts->get( '/wp-content/uploads/2026/10/b.jpg' ) );
		$this->assertNull( $verdicts->get( '/wp-content/uploads/2026/10/b.pdf' ), 'Rules can target one file type.' );
		$this->assertNull( $verdicts->get( '/wp-content/uploads/2026/11/b.jpg' ), 'Another directory is asked separately.' );
		$this->assertNull( $verdicts->get( '/wp-content/uploads/2026/10/sub/b.jpg' ), 'A subdirectory is asked separately.' );
	}

	public function test_a_refused_directory_is_denied_with_its_subdirectories(): void {
		$verdicts = $this->verdicts();
		$this->assertSame( 'deny', $verdicts->record( '/wp-content/uploads/woocommerce_uploads/a.zip', 403 ) );
		$this->assertTrue( $verdicts->is_denied( '/wp-content/uploads/woocommerce_uploads/b.zip' ) );
		$this->assertTrue( $verdicts->is_denied( '/wp-content/uploads/woocommerce_uploads/2026/10/c.zip' ) );
		$this->assertSame( 'deny', $verdicts->get( '/wp-content/uploads/woocommerce_uploads/2026/10/c.zip' ) );
		$this->assertFalse( $verdicts->is_denied( '/wp-content/uploads/woocommerce_uploads/logo.png' ) );
		$this->assertFalse( $verdicts->is_denied( '/wp-content/uploads/other/a.zip' ) );
		$this->assertSame( array( '/wp-content/uploads/woocommerce_uploads/*.zip' ), $verdicts->denied() );
	}

	public function test_a_refusal_stands_for_the_build(): void {
		$verdicts = $this->verdicts();
		$verdicts->record( '/wp-content/uploads/private/a.pdf', 401 );
		$this->assertSame( 'deny', $verdicts->record( '/wp-content/uploads/private/b.pdf', 200 ) );
		$this->assertTrue( $verdicts->is_denied( '/wp-content/uploads/private/b.pdf' ) );
	}

	public function test_a_later_refusal_overrides_fetch(): void {
		$verdicts = $this->verdicts();
		$verdicts->record( '/files/a.pdf', 404 );
		$this->assertSame( 'deny', $verdicts->record( '/files/b.pdf', 403 ) );
		$this->assertTrue( $verdicts->is_denied( '/files/c.pdf' ) );
	}

	/**
	 * @dataProvider provide_roots
	 */
	public function test_never_denies_a_root_or_a_directory_above_one( string $path ): void {
		$verdicts = $this->verdicts();
		$this->assertSame( 'fetch', $verdicts->record( $path, 403 ), 'The file is still left out by the caller; the rest are fetched one by one.' );
		$this->assertFalse( $verdicts->is_denied( $path ) );
		$this->assertSame( array(), $verdicts->denied() );
	}

	public function provide_roots(): array {
		return array(
			'uploads root'  => array( '/wp-content/uploads/backup.zip' ),
			'above roots'   => array( '/wp-content/backup.zip' ),
			'site root'     => array( '/backup.zip' ),
			'includes root' => array( '/wp-includes/a.js' ),
		);
	}

	public function test_a_root_lookalike_can_be_denied(): void {
		$verdicts = $this->verdicts();
		$this->assertSame( 'deny', $verdicts->record( '/wp-content/uploads-private/a.pdf', 403 ) );
	}

	public function test_transient_answers_are_not_remembered(): void {
		$verdicts = $this->verdicts();
		$this->assertNull( $verdicts->record( '/wp-content/uploads/2026/10/a.jpg', 503 ) );
		$this->assertNull( $verdicts->get( '/wp-content/uploads/2026/10/a.jpg' ) );
		$this->assertFalse( $verdicts->changed() );
	}

	public function test_tracks_changes_for_saving(): void {
		$verdicts = $this->verdicts();
		$this->assertFalse( $verdicts->changed() );
		$verdicts->record( '/wp-content/uploads/2026/10/a.jpg', 200 );
		$this->assertTrue( $verdicts->changed() );
		$verdicts->saved();
		$verdicts->record( '/wp-content/uploads/2026/10/b.jpg', 200 );
		$this->assertFalse( $verdicts->changed(), 'The same verdict again is no change.' );
	}

	public function test_loads_saved_verdicts_and_ignores_bad_entries(): void {
		$saved    = array(
			'/wp-content/uploads/2026/10/*.jpg' => 'copy',
			'/wp-content/uploads/private/*.pdf' => 'deny',
			'/wp-content/uploads/*.zip'         => 'deny',
			'/wp-content/uploads/2026/11/*.jpg' => 'maybe',
			'/wp-content/uploads/2026/12/x.jpg' => 'copy',
			0                                   => 'copy',
		);
		$verdicts = $this->verdicts();
		$verdicts->load( $saved );
		$this->assertFalse( $verdicts->changed() );
		$this->assertSame( 'copy', $verdicts->get( '/wp-content/uploads/2026/10/a.jpg' ) );
		$this->assertTrue( $verdicts->is_denied( '/wp-content/uploads/private/a.pdf' ) );
		$this->assertFalse( $verdicts->is_denied( '/wp-content/uploads/a.zip' ), 'A root is never denied, even from saved state.' );
		$this->assertNull( $verdicts->get( '/wp-content/uploads/2026/11/a.jpg' ) );
		$this->assertNull( $verdicts->get( '/wp-content/uploads/2026/12/a.jpg' ) );
		$this->assertSame( array( '/wp-content/uploads/private/*.pdf' ), $verdicts->denied() );
	}

	public function test_export_round_trips(): void {
		$verdicts = $this->verdicts();
		$verdicts->record( '/wp-content/uploads/2026/10/a.jpg', 200 );
		$verdicts->record( '/wp-content/uploads/private/a.pdf', 403 );
		$verdicts->record( '/wp-content/themes/t/a.css', 302 );
		$copy = $this->verdicts();
		$copy->load( $verdicts->export() );
		$this->assertSame( $verdicts->export(), $copy->export() );
	}
}
