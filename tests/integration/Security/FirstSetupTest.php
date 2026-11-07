<?php
/**
 * Setting a site up when the activation hook never ran.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Security;

use Glaciate\Plugin;
use Glaciate\Security\Capabilities;
use Glaciate\Storage\Schema;

final class FirstSetupTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		update_option( Schema::OPTION, Schema::VERSION, false );
		Capabilities::grant_defaults();
		parent::tear_down();
	}

	public function test_a_site_never_activated_gets_tables_and_default_capabilities(): void {
		// As after activating without vendor/: no schema version, no capabilities.
		delete_option( Schema::OPTION );
		Capabilities::revoke_all();
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$this->assertFalse( user_can( $admin, Capabilities::MANAGE ) );

		Plugin::maybe_set_up();

		$this->assertSame( Schema::VERSION, (int) get_option( Schema::OPTION ) );
		$this->assertTrue( user_can( $admin, Capabilities::MANAGE ) );
		$this->assertTrue( user_can( $admin, Capabilities::PUBLISH ) );
		$this->assertTrue( get_role( 'editor' )->has_cap( Capabilities::PUBLISH ) );
	}

	public function test_a_site_whose_tables_were_already_created_still_gets_capabilities(): void {
		// Before this was fixed, the tables were created on init but the capabilities never were.
		Capabilities::revoke_all();

		Plugin::maybe_set_up();

		$this->assertTrue( get_role( 'administrator' )->has_cap( Capabilities::MANAGE ) );
	}

	public function test_capabilities_an_administrator_removed_stay_removed(): void {
		get_role( 'editor' )->remove_cap( Capabilities::PUBLISH );

		Plugin::maybe_set_up();

		$this->assertFalse( get_role( 'editor' )->has_cap( Capabilities::PUBLISH ) );
	}

	public function test_set_up_runs_early_on_init(): void {
		$this->assertSame( 1, has_action( 'init', array( Plugin::class, 'maybe_set_up' ) ) );
	}
}
