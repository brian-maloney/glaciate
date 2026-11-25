<?php
/**
 * Local directory deploys (threats T5 and T27).
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Deploy;

use Glaciate\Deploy\LocalDirectory;
use Glaciate\Deploy\TargetFiles;
use Glaciate\Security\TargetPolicy;
use Glaciate\Support\Logger;
use Glaciate\Support\Paths;
use Glaciate\Support\Settings;

final class LocalDirectoryTest extends \WP_UnitTestCase {

	/** @var string */
	private $cache;

	/** @var string */
	private $target;

	public function set_up(): void {
		parent::set_up();
		$base         = sys_get_temp_dir() . '/sp-deploy-' . wp_generate_password( 6, false );
		$this->cache  = $base . '/cache';
		$this->target = $base . '/site';
		mkdir( $this->cache . '/about', 0777, true );
		file_put_contents( $this->cache . '/index.html', 'home v1' );
		file_put_contents( $this->cache . '/about/index.html', 'about v1' );
		Settings::update( array( 'local_path' => $this->target ) );
	}

	public function tear_down(): void {
		\Glaciate\Tests\Support\Fs::rmrf( dirname( $this->cache ) );
		parent::tear_down();
	}

	private function manifest(): array {
		$out = array();
		foreach ( array( 'index.html', 'about/index.html' ) as $p ) {
			if ( is_file( $this->cache . '/' . $p ) ) {
				$out[ $p ] = hash_file( 'sha256', $this->cache . '/' . $p );
			}
		}
		return $out;
	}

	public function test_first_deploy_creates_files_and_marker(): void {
		$result = ( new LocalDirectory() )->deploy( $this->manifest(), $this->cache, new Logger() );
		$this->assertSame( 2, $result->added );
		$this->assertSame( 'home v1', file_get_contents( $this->target . '/index.html' ) );
		$this->assertFileExists( $this->target . '/' . TargetPolicy::MARKER );
	}

	public function test_only_changes_are_written_and_removed_files_deleted(): void {
		$deployer = new LocalDirectory();
		$deployer->deploy( $this->manifest(), $this->cache, new Logger() );

		file_put_contents( $this->target . '/keep-me.txt', 'not ours' );
		file_put_contents( $this->cache . '/index.html', 'home v2' );
		unlink( $this->cache . '/about/index.html' );

		$result = $deployer->deploy( $this->manifest(), $this->cache, new Logger() );
		$this->assertSame( 0, $result->added );
		$this->assertSame( 1, $result->modified );
		$this->assertSame( 1, $result->deleted );
		$this->assertSame( 'home v2', file_get_contents( $this->target . '/index.html' ) );
		$this->assertFileDoesNotExist( $this->target . '/about/index.html' );
		$this->assertDirectoryDoesNotExist( $this->target . '/about' );
		$this->assertFileExists( $this->target . '/keep-me.txt', 'Files the deployer did not write must survive.' );
	}

	public function test_unchanged_files_are_skipped(): void {
		$deployer = new LocalDirectory();
		$deployer->deploy( $this->manifest(), $this->cache, new Logger() );
		$result = $deployer->deploy( $this->manifest(), $this->cache, new Logger() );
		$this->assertSame( 2, $result->unchanged );
		$this->assertSame( 0, $result->added + $result->modified + $result->deleted );
	}

	public function test_missing_target_file_is_restored(): void {
		$deployer = new LocalDirectory();
		$deployer->deploy( $this->manifest(), $this->cache, new Logger() );
		unlink( $this->target . '/index.html' );
		$result = $deployer->deploy( $this->manifest(), $this->cache, new Logger() );
		$this->assertSame( 1, $result->modified );
		$this->assertFileExists( $this->target . '/index.html' );
	}

	public function test_non_empty_folder_without_marker_is_refused(): void {
		mkdir( $this->target );
		file_put_contents( $this->target . '/existing.html', 'precious' );
		$deployer = new LocalDirectory();
		$this->assertNotNull( $deployer->problem() );
		$this->expectException( \RuntimeException::class );
		$deployer->deploy( $this->manifest(), $this->cache, new Logger() );
	}

	public function test_dangerous_target_is_refused(): void {
		Settings::update( array( 'local_path' => WP_CONTENT_DIR ) );
		$this->expectException( \RuntimeException::class );
		( new LocalDirectory() )->deploy( $this->manifest(), $this->cache, new Logger() );
	}

	public function test_hostile_manifest_paths_are_skipped(): void {
		$manifest                        = $this->manifest();
		$manifest['../escape.html']      = str_repeat( 'a', 64 );
		$manifest['a/../../escape.html'] = str_repeat( 'a', 64 );
		$result                          = ( new LocalDirectory() )->deploy( $manifest, $this->cache, new Logger() );
		$this->assertSame( 2, $result->added );
		$this->assertFileDoesNotExist( dirname( $this->target ) . '/escape.html' );
	}

	public function test_manifest_paths_a_server_might_run_are_skipped(): void {
		file_put_contents( $this->cache . '/shell.phtml', '<?php echo 1;' );
		file_put_contents( $this->cache . '/.htaccess', 'SetHandler application/x-httpd-php' );
		$manifest                = $this->manifest();
		$manifest['shell.phtml'] = hash_file( 'sha256', $this->cache . '/shell.phtml' );
		$manifest['.htaccess']   = hash_file( 'sha256', $this->cache . '/.htaccess' );

		$result = ( new LocalDirectory() )->deploy( $manifest, $this->cache, new Logger() );
		$this->assertSame( 2, $result->added );
		$this->assertFileDoesNotExist( $this->target . '/shell.phtml' );
		$this->assertFileDoesNotExist( $this->target . '/.htaccess' );
	}

	public function test_files_an_earlier_version_wrote_under_refused_names_are_deleted(): void {
		$deployer = new LocalDirectory();
		$deployer->deploy( $this->manifest(), $this->cache, new Logger() );
		// As a version before D58 left it: the file in the target and the deploy record that says we wrote it.
		file_put_contents( $this->target . '/shell.phtml', '<?php echo 1;' );
		( new TargetFiles( 'local-' . substr( sha1( $this->target ), 0, 32 ) ) )->set( 'shell.phtml', str_repeat( 'a', 64 ) );

		$result = $deployer->deploy( $this->manifest(), $this->cache, new Logger() );
		$this->assertSame( 1, $result->deleted );
		$this->assertFileDoesNotExist( $this->target . '/shell.phtml' );
	}

	public function test_a_chosen_folder_gets_no_htaccess(): void {
		( new LocalDirectory() )->deploy( $this->manifest(), $this->cache, new Logger() );
		$this->assertFileDoesNotExist( $this->target . '/.htaccess' );
	}

	public function test_default_target_gets_an_htaccess_that_turns_off_scripts(): void {
		Settings::update( array( 'local_path' => '' ) );
		$dir = Paths::default_local_target();
		\Glaciate\Tests\Support\Fs::rmrf( $dir );
		try {
			$deployer = new LocalDirectory();
			$this->assertSame( $dir, $deployer->directory() );
			$deployer->deploy( $this->manifest(), $this->cache, new Logger() );

			$this->assertFileExists( $dir . '/.htaccess' );
			$rules = (string) file_get_contents( $dir . '/.htaccess' );
			$this->assertStringContainsString( 'SetHandler none', $rules );
			$this->assertStringContainsString( 'RemoveHandler .php', $rules );
			$this->assertStringContainsString( 'X-Content-Type-Options "nosniff"', $rules );
			$this->assertStringNotContainsString( 'php_flag', $rules, 'Directives AllowOverride FileInfo does not cover would break the folder.' );

			// A later deploy that deletes files leaves it alone, and the folder still counts as ours.
			unlink( $this->cache . '/about/index.html' );
			$result = $deployer->deploy( $this->manifest(), $this->cache, new Logger() );
			$this->assertSame( 1, $result->deleted );
			$this->assertSame( $rules, file_get_contents( $dir . '/.htaccess' ) );
			$this->assertNull( $deployer->problem() );
		} finally {
			\Glaciate\Tests\Support\Fs::rmrf( $dir );
		}
	}

	public function test_default_target_replaces_an_htaccess_an_earlier_version_published(): void {
		Settings::update( array( 'local_path' => '' ) );
		$dir = Paths::default_local_target();
		\Glaciate\Tests\Support\Fs::rmrf( $dir );
		try {
			$deployer = new LocalDirectory();
			$deployer->deploy( $this->manifest(), $this->cache, new Logger() );
			// A crawled .htaccess that a version before D58 deployed and recorded.
			file_put_contents( $dir . '/.htaccess', 'AddHandler application/x-httpd-php .html' );
			( new TargetFiles( 'local-' . substr( sha1( $dir ), 0, 32 ) ) )->set( '.htaccess', str_repeat( 'a', 64 ) );

			$deployer->deploy( $this->manifest(), $this->cache, new Logger() );
			$this->assertSame( Paths::PUBLIC_HTACCESS, file_get_contents( $dir . '/.htaccess' ) );
		} finally {
			\Glaciate\Tests\Support\Fs::rmrf( $dir );
		}
	}
}
