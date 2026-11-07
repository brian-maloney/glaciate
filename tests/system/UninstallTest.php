<?php
/**
 * Uninstall removes everything except deployed sites.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\System;

use Glaciate\Plugin;
use Glaciate\Security\Capabilities;
use Glaciate\Storage\Schema;
use Glaciate\Support\Paths;
use Glaciate\Uninstaller;

final class UninstallTest extends SystemTestCase {

	protected function tearDown(): void {
		Plugin::activate();
		parent::tearDown();
	}

	public function test_uninstall_removes_data_but_not_the_deployed_site(): void {
		global $wpdb;
		wp_insert_post(
			array(
				'post_title'  => 'Keep me deployed',
				'post_name'   => 'keep-me',
				'post_status' => 'publish',
			)
		);
		$this->build();
		$this->assertFileExists( $this->target . '/keep-me/index.html' );
		$this->assertDirectoryExists( Paths::cache_dir() );

		Uninstaller::run();
		wp_cache_flush();

		foreach ( Schema::TABLES as $table ) {
			$this->assertNull( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Schema::table( $table ) ) ), "Table {$table} survived." );
		}
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s", 'glaciate\_%' ) ) );
		$this->assertFalse( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
		$this->assertDirectoryDoesNotExist( Paths::cache_dir() );
		$this->assertFileExists( $this->target . '/keep-me/index.html', 'Uninstall must never delete a deployed site.' );
	}
}
