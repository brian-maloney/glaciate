<?php
/**
 * Files the web server refuses anonymous visitors never reach the static site (threat T3, D53).
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\System;

use Glaciate\Build\Pipeline;
use Glaciate\Support\Paths;
use Glaciate\Tests\Support\Fs;

final class ProtectedFilesTest extends SystemTestCase {

	/**
	 * Folders this test made under uploads.
	 *
	 * @var string[]
	 */
	private $folders = array();

	protected function tearDown(): void {
		foreach ( $this->folders as $folder ) {
			Fs::rmrf( $folder );
		}
		parent::tearDown();
	}

	public function test_refused_files_are_left_out_however_they_are_reached(): void {
		$protected = $this->folder( 'protected' );
		$open      = $this->folder( 'open' );
		foreach ( array( 'linked', 'comment', 'additional' ) as $name ) {
			file_put_contents( $protected['dir'] . "/secret-{$name}.pdf", "%PDF-1.4 CONFIDENTIAL {$name}\n" );
		}
		file_put_contents( $protected['dir'] . '/logo.png', 'not really a png' );
		file_put_contents( $open['dir'] . '/brochure.pdf', "%PDF-1.4 public\n" );
		$this->deny( $protected['dir'] );

		$post = wp_insert_post(
			array(
				'post_title'   => 'Downloads',
				'post_name'    => 'downloads',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p><a href="' . $protected['url'] . '/secret-linked.pdf">Report</a>'
					. ' <img src="' . $protected['url'] . '/logo.png" alt=""> <a href="' . $open['url'] . '/brochure.pdf">Brochure</a></p><!-- /wp:paragraph -->',
			)
		);
		$this->assertIsInt( $post );
		wp_insert_comment(
			array(
				'comment_post_ID'      => $post,
				'comment_author'       => 'visitor',
				'comment_author_email' => 'visitor@example.net',
				'comment_content'      => 'nice post ' . $protected['url'] . '/secret-comment.pdf',
				'comment_approved'     => 1,
			)
		);
		\Glaciate\Support\Settings::update( array( 'additional_urls' => wp_parse_url( $protected['url'], PHP_URL_PATH ) . '/secret-additional.pdf' ) );

		$build = $this->build();
		$files = $this->snapshot();
		$log   = $this->log( (int) $build['id'] );

		$this->assertNotNull( $this->page( '/downloads/' ), "The page itself is published. Log:\n" . $log );
		$this->assertArrayHasKey( $open['relative'] . '/brochure.pdf', $files, "A file the site serves is still published. Log:\n" . $log );
		foreach ( array( 'secret-linked.pdf', 'secret-comment.pdf', 'secret-additional.pdf', 'logo.png' ) as $name ) {
			$this->assertArrayNotHasKey( $protected['relative'] . '/' . $name, $files, "{$name} was published. Log:\n" . $log );
			$this->assertFileDoesNotExist( Paths::cache_dir() . '/' . $protected['relative'] . '/' . $name );
		}
		foreach ( $files as $path => $contents ) {
			$this->assertStringNotContainsString( 'CONFIDENTIAL', $contents, "Protected content leaked into {$path}" );
		}
	}

	public function test_a_file_published_before_its_folder_was_protected_is_removed(): void {
		$folder = $this->folder( 'later' );
		file_put_contents( $folder['dir'] . '/report.pdf', "%PDF-1.4 CONFIDENTIAL\n" );
		wp_insert_post(
			array(
				'post_title'   => 'Report',
				'post_name'    => 'report',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:paragraph --><p><a href="' . $folder['url'] . '/report.pdf">Report</a></p><!-- /wp:paragraph -->',
			)
		);

		$this->build();
		$this->assertArrayHasKey( $folder['relative'] . '/report.pdf', $this->snapshot(), 'Served, so published.' );

		$this->deny( $folder['dir'] );
		$build = $this->build();
		$this->assertArrayNotHasKey( $folder['relative'] . '/report.pdf', $this->snapshot(), "Still published after the folder was protected. Log:\n" . $this->log( (int) $build['id'] ) );
		$this->assertFileDoesNotExist( Paths::cache_dir() . '/' . $folder['relative'] . '/report.pdf' );
	}

	/**
	 * Makes a folder under uploads.
	 *
	 * @param string $name Short name.
	 * @return array{dir:string,url:string,relative:string} Directory, origin URL and path relative to the output root.
	 */
	private function folder( string $name ): array {
		$uploads = wp_upload_dir( null, false );
		$leaf    = 'glaciate-system-' . $name . '-' . getmypid();
		$dir     = $uploads['basedir'] . '/' . $leaf;
		Fs::rmrf( $dir );
		mkdir( $dir, 0777, true );
		$this->folders[] = $dir;
		$url             = $uploads['baseurl'] . '/' . $leaf;
		return array(
			'dir'      => $dir,
			'url'      => $url,
			'relative' => ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ),
		);
	}

	/**
	 * Protects a folder with an .htaccess deny and checks that the web server applies it.
	 *
	 * @param string $dir Directory.
	 */
	private function deny( string $dir ): void {
		file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
		$probe = $dir . '/probe-' . getmypid() . '.pdf';
		file_put_contents( $probe, 'probe' );
		$uploads = wp_upload_dir( null, false );
		$url     = $uploads['baseurl'] . substr( $probe, strlen( $uploads['basedir'] ) );
		$result  = Pipeline::for_site()->client->fetch( array( 'probe' => array( 'url' => $url ) ) )['probe'];
		unlink( $probe );
		if ( 403 !== $result->status ) {
			$this->markTestSkipped( 'The web server does not apply .htaccess access rules (HTTP ' . $result->status . ').' );
		}
	}
}
