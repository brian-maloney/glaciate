<?php
/**
 * Asset versions for the plugin's real files.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Support;

use Glaciate\Support\Assets;

final class AssetsTest extends \WP_UnitTestCase {

	public function test_version_and_url_of_a_real_file(): void {
		$file = dirname( GLACIATE_FILE ) . '/assets/js/settings.js';
		$this->assertSame( Assets::compose( GLACIATE_VERSION, (int) filemtime( $file ) ), Assets::version( 'assets/js/settings.js' ) );
		$this->assertStringEndsWith( '/assets/js/settings.js', Assets::url( 'assets/js/settings.js' ) );
	}

	public function test_missing_file_gets_the_plain_version(): void {
		$this->assertSame( GLACIATE_VERSION, Assets::version( 'assets/js/not-a-file.js' ) );
	}
}
