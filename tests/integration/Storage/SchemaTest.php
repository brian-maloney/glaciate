<?php
/**
 * Schema installation.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Storage;

use Glaciate\Deps\Invalidator;
use Glaciate\Storage\ChangeRepository;
use Glaciate\Storage\FormKeyRepository;
use Glaciate\Storage\Schema;
use Glaciate\Support\Settings;

final class SchemaTest extends \WP_UnitTestCase {

	public function test_all_tables_exist(): void {
		global $wpdb;
		foreach ( Schema::TABLES as $suffix ) {
			$table = Schema::table( $suffix );
			$this->assertSame( $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ), "Missing {$table}" );
		}
	}

	public function test_version_option_is_stored_without_autoload(): void {
		$this->assertSame( Schema::VERSION, (int) get_option( Schema::OPTION ) );
	}

	public function test_upgrading_a_site_that_publishes_forms_queues_a_full_rebuild(): void {
		$changes = new ChangeRepository();
		$before  = $changes->pending_count();
		Settings::update( array( 'forms' => 'site' ) );
		update_option( Schema::OPTION, 4, false );
		delete_option( FormKeyRepository::READY_OPTION );

		Schema::install();
		$this->assertFalse( FormKeyRepository::ready(), 'Every key is still accepted until a full build has recorded the forms.' );
		$this->assertSame( $before + 1, $changes->pending_count() );
		$this->assertTrue( ( new Invalidator() )->affected( $changes->peek() )['full'], 'The next build is a full one.' );

		Schema::install();
		$this->assertSame( $before + 1, $changes->pending_count(), 'Only once.' );
	}

	public function test_upgrading_a_site_without_forms_or_installing_afresh_is_ready_at_once(): void {
		Settings::update( array( 'forms' => 'off' ) );
		foreach ( array( 4, 0 ) as $previous ) {
			update_option( Schema::OPTION, $previous, false );
			delete_option( FormKeyRepository::READY_OPTION );
			Schema::install();
			$this->assertTrue( FormKeyRepository::ready(), "From version {$previous}" );
		}
		Settings::update( array( 'forms' => 'site' ) );
		delete_option( Schema::OPTION );
		delete_option( FormKeyRepository::READY_OPTION );
		Schema::install();
		$this->assertTrue( FormKeyRepository::ready(), 'A fresh install has published nothing yet.' );
	}

	public function test_install_is_idempotent(): void {
		global $wpdb;
		Schema::install();
		Schema::install();
		$columns = $wpdb->get_col( 'DESCRIBE ' . Schema::table( 'urls' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertContains( 'deps_state', $columns );
		$this->assertSame( count( array_unique( $columns ) ), count( $columns ) );
	}
}
