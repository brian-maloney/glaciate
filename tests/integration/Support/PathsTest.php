<?php
/**
 * Where the plugin keeps its files.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Support;

use Glaciate\Support\Paths;

final class PathsTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		remove_all_filters( 'glaciate_data_dir' );
		parent::tear_down();
	}

	public function test_data_lives_in_a_glaciate_folder_in_the_uploads_directory(): void {
		$uploads = wp_upload_dir( null, false );
		$base    = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
		$this->assertSame( $base . '/glaciate', Paths::data_dir() );
		$this->assertSame( $base . '/glaciate/cache/' . get_current_blog_id(), Paths::cache_dir() );
		$this->assertSame( $base . '/glaciate/zips/' . get_current_blog_id(), Paths::zip_dir() );
		$this->assertSame( $base . '/glaciate/public/' . get_current_blog_id(), Paths::default_local_target() );
	}

	public function test_follows_the_uploads_directory_wherever_it_is(): void {
		$moved = static function ( $dirs ) {
			$dirs['basedir'] = '/srv/media';
			$dirs['baseurl'] = 'https://cdn.example.org/media';
			return $dirs;
		};
		add_filter( 'upload_dir', $moved );
		try {
			$this->assertSame( '/srv/media/glaciate', Paths::data_dir() );
			$this->assertSame( '/media/glaciate', Paths::data_url_path() );
		} finally {
			remove_filter( 'upload_dir', $moved );
		}
	}

	public function test_data_url_path_is_the_uploads_address_of_the_data_directory(): void {
		$uploads = wp_upload_dir( null, false );
		$this->assertSame( untrailingslashit( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) ) . '/glaciate', Paths::data_url_path() );
	}

	public function test_data_directory_outside_uploads_has_no_address(): void {
		add_filter(
			'glaciate_data_dir',
			static function () {
				return '/srv/glaciate-data';
			}
		);
		$this->assertSame( '/srv/glaciate-data', Paths::data_dir() );
		$this->assertNull( Paths::data_url_path() );
	}

	public function test_private_directories_block_web_access_but_the_data_directory_stays_open(): void {
		$this->assertTrue( Paths::ensure_private_dir( Paths::zip_dir() ) );
		$this->assertFileExists( Paths::zip_dir() . '/.htaccess' );
		$this->assertFileExists( dirname( Paths::zip_dir() ) . '/.htaccess' );
		$this->assertFileDoesNotExist( Paths::data_dir() . '/.htaccess', 'A local target below it may be served.' );
	}

	public function test_builds_never_crawl_the_data_directory(): void {
		$classifier = \Glaciate\Build\Pipeline::for_site()->classifier;
		$this->assertNull( $classifier->classify( home_url( Paths::data_url_path() . '/public/1/index.html' ) ) );
		$this->assertSame( 'excluded', $classifier->reason() );
		$this->assertNotNull( $classifier->classify( home_url( '/about/' ) ) );
	}
}
