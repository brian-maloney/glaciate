<?php
/**
 * Admin-post actions need capability and nonce (threat T6).
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Admin;

use Glaciate\Admin\Admin;
use Glaciate\Admin\SettingsPage;
use Glaciate\Security\Capabilities;
use Glaciate\Storage\BuildRepository;
use Glaciate\Support\Settings;

final class AdminActionsTest extends \WP_UnitTestCase {

	public static function wpSetUpBeforeClass(): void {
		Capabilities::grant_defaults();
	}

	public function set_up(): void {
		parent::set_up();
		add_filter( 'glaciate_kick_builds', '__return_false' );
	}

	public function tear_down(): void {
		unset( $_REQUEST['_wpnonce'], $_GET['kind'], $_POST['option_page'] );
		parent::tear_down();
	}

	/**
	 * @dataProvider provide_actions
	 */
	public function test_actions_refuse_users_without_capability( string $handler, string $action ): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$_REQUEST['_wpnonce'] = wp_create_nonce( 'glaciate_' . $action );
		$this->expectException( \WPDieException::class );
		$this->expectExceptionMessage( 'not allowed' );
		Admin::$handler();
	}

	/**
	 * @dataProvider provide_actions
	 */
	public function test_actions_refuse_missing_nonce( string $handler, string $action ): void {
		unset( $action );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'bogus';
		$this->expectException( \WPDieException::class );
		Admin::$handler();
	}

	public function provide_actions(): array {
		return array(
			array( 'handle_build', 'build' ),
			array( 'handle_cancel', 'cancel' ),
			array( 'handle_onboard', 'onboard' ),
			array( 'handle_publish_post', 'publish_post' ),
			array( 'handle_download', 'download_zip' ),
			array( 'handle_form_receiver', 'form_receiver' ),
		);
	}

	public function test_no_build_starts_without_a_valid_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_REQUEST['_wpnonce'] = 'bogus';
		try {
			Admin::handle_build();
		} catch ( \WPDieException $e ) {
			unset( $e );
		}
		$this->assertNull( ( new BuildRepository() )->active() );
	}

	public function test_forged_background_kick_is_refused(): void {
		$build          = ( new \Glaciate\Build\BuildService() )->start( 'full', 'manual', 0, false, array( 'sync' => true ) );
		$_POST['build'] = (string) $build;
		$_POST['token'] = str_repeat( 'a', 64 );
		try {
			\Glaciate\Build\Queue::handle_kick();
			$this->fail( 'A forged kick must be refused.' );
		} catch ( \WPDieException $e ) {
			$this->assertSame( 'queued', ( new BuildRepository() )->get( $build )['status'], 'No work may run.' );
		} finally {
			unset( $_POST['build'], $_POST['token'] );
		}
	}

	public function test_settings_form_keeps_paths_for_users_who_cannot_edit_them(): void {
		Settings::update( array( 'local_path' => '/srv/static-site' ) );
		$editor = self::factory()->user->create_and_get( array( 'role' => 'editor' ) );
		$editor->add_cap( Capabilities::MANAGE );
		wp_set_current_user( $editor->ID );
		if ( is_multisite() ) {
			$_POST['option_page'] = SettingsPage::GROUP;
			$clean                = SettingsPage::sanitize( array( 'local_path' => '/var/www/html' ) );
			$this->assertSame( '/srv/static-site', $clean['local_path'] );
		} else {
			$this->assertTrue( SettingsPage::can_edit_paths() );
		}
	}

	public function test_settings_form_rejects_dangerous_folder(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		Settings::update( array( 'local_path' => '/srv/static-site' ) );
		$_POST['option_page'] = SettingsPage::GROUP;
		$clean                = SettingsPage::sanitize( array( 'local_path' => WP_CONTENT_DIR ) );
		$this->assertSame( '/srv/static-site', $clean['local_path'] );
		$this->assertNotEmpty( get_settings_errors( Settings::OPTION ) );
	}

	public function test_schedule_form_needs_times_and_days(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Settings::update( array( 'publish_days' => array( 'mon', 'fri' ) ) );
		$_POST['option_page'] = SettingsPage::GROUP;

		// No day boxes ticked: nothing is submitted for publish_days.
		$clean = SettingsPage::sanitize(
			array(
				'publish_mode'  => 'scheduled',
				'publish_times' => '9:00, 17:30',
			)
		);
		$this->assertSame( 'scheduled', $clean['publish_mode'] );
		$this->assertSame( array( '09:00', '17:30' ), $clean['publish_times'] );
		$this->assertSame( array( 'mon', 'fri' ), $clean['publish_days'], 'The stored days are kept.' );
		$this->assertContains( 'glaciate_publish_days', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' ) );

		$clean = SettingsPage::sanitize(
			array(
				'publish_mode'  => 'scheduled',
				'publish_times' => 'noon',
				'publish_days'  => array( 'sat', 'sun' ),
			)
		);
		$this->assertSame( array( 'sat', 'sun' ), $clean['publish_days'] );
		$this->assertContains( 'glaciate_publish_times', wp_list_pluck( get_settings_errors( Settings::OPTION ), 'code' ) );
	}

	public function test_settings_errors_are_escaped_before_wordpress_prints_them(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		if ( is_multisite() ) {
			grant_super_admin( $admin );
		}
		wp_set_current_user( $admin );
		$GLOBALS['wp_settings_errors'] = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Errors from earlier tests.
		$_POST['option_page']          = SettingsPage::GROUP;

		// settings_errors() prints a message as it is, so markup in a translation or a label would reach the page.
		$markup = static function ( $translation, $text, $domain ) {
			return 'glaciate' === $domain && 'Choose at least one day to publish on.' === $text ? 'Choose <b>a day</b> & "save"' : $translation;
		};
		add_filter( 'gettext', $markup, 10, 3 );
		SettingsPage::sanitize(
			array(
				'target'        => 'local',
				'local_path'    => WP_CONTENT_DIR,
				'publish_mode'  => 'scheduled',
				'publish_times' => '9:00',
			)
		);
		remove_filter( 'gettext', $markup, 10 );

		$messages = wp_list_pluck( get_settings_errors( Settings::OPTION ), 'message', 'code' );
		$this->assertSame( 'Choose &lt;b&gt;a day&lt;/b&gt; &amp; &quot;save&quot;', $messages['glaciate_publish_days'] );
		$this->assertArrayHasKey( 'glaciate_local_path', $messages, 'the folder problem is still reported' );
	}
}
