<?php
/**
 * Multisite behavior (threat T13).
 *
 * @group ms-required
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Multisite;

use Glaciate\Admin\SettingsPage;
use Glaciate\Deps\ChangeListener;
use Glaciate\Plugin;
use Glaciate\Render\Recorder;
use Glaciate\Render\RenderToken;
use Glaciate\Security\Capabilities;
use Glaciate\Storage\ChangeRepository;
use Glaciate\Storage\Schema;
use Glaciate\Storage\UrlRepository;
use Glaciate\Support\Paths;
use Glaciate\Support\Settings;

/**
 * @group ms-required
 */
final class MultisiteTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! is_multisite() ) {
			$this->markTestSkipped( 'Multisite only.' );
		}
	}

	public function test_each_site_has_its_own_tables_and_data_directories(): void {
		$site = self::factory()->blog->create();
		switch_to_blog( $site );
		Plugin::activate();
		$table = Schema::table( 'urls' );
		$cache = Paths::cache_dir();
		( new UrlRepository() )->ensure( 'https://site-two.test/' );
		restore_current_blog();

		$this->assertNotSame( $table, Schema::table( 'urls' ) );
		$this->assertNotSame( $cache, Paths::cache_dir() );
		$this->assertNull( ( new UrlRepository() )->find( 'https://site-two.test/' ), 'Rows must not leak between sites.' );
	}

	public function test_secret_copied_to_another_site_does_not_open(): void {
		\Glaciate\Security\Secrets::set( 'sftp', 'password', 'site-one-password' );
		$sealed = get_option( \Glaciate\Security\Secrets::OPTION );
		$site   = self::factory()->blog->create();
		switch_to_blog( $site );
		$this->assertNull( \Glaciate\Security\Secrets::get( 'sftp', 'password' ), 'Each site has its own secrets.' );
		update_option( \Glaciate\Security\Secrets::OPTION, $sealed, false );
		$this->assertNull( \Glaciate\Security\Secrets::get( 'sftp', 'password' ), 'A copied ciphertext is bound to its site.' );
		restore_current_blog();
		$this->assertSame( 'site-one-password', \Glaciate\Security\Secrets::get( 'sftp', 'password' ) );
	}

	public function test_site_admins_cannot_point_deploys_at_hosts(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( Capabilities::MANAGE );
		wp_set_current_user( $admin );
		$sftp = new \Glaciate\Deploy\Sftp();
		foreach ( array(
			'host'        => 'internal.example',
			'remote_path' => '/srv/other-site',
		) as $field => $value ) {
			try {
				\Glaciate\Deploy\TargetConfig::set( $sftp, $field, $value );
				$this->fail( "A site admin set {$field}." );
			} catch ( \InvalidArgumentException $e ) {
				$this->assertSame( '', \Glaciate\Deploy\TargetConfig::value( $sftp, $field ) );
			}
		}
		$fingerprint = 'SHA256:' . str_repeat( 'A', 43 );
		\Glaciate\Deploy\TargetConfig::set( $sftp, 'host_key', $fingerprint );
		$this->assertSame( $fingerprint, \Glaciate\Deploy\TargetConfig::value( $sftp, 'host_key' ), 'Unrestricted fields stay editable.' );
	}

	/**
	 * Fields that choose where a deploy writes, with a value a super admin stores and one a site admin tries.
	 *
	 * @return array<string,array<string,array{0:string|bool,1:string|bool}>>
	 */
	private function destination_fields(): array {
		return array(
			's3'         => array(
				'bucket'                  => array( 'site-one', 'site-two' ),
				'prefix'                  => array( 'one/', 'two/' ),
				'cloudfront_distribution' => array( 'E1SITEONE', 'E2SITETWO' ),
			),
			'git'        => array(
				'branch'           => array( 'site-one-pages', 'main' ),
				'replace_existing' => array( false, true ),
			),
			'cloudflare' => array(
				'account_id'       => array( str_repeat( 'a', 32 ), str_repeat( 'b', 32 ) ),
				'worker_name'      => array( 'site-one', 'production-app' ),
				'replace_existing' => array( false, true ),
			),
			'netlify'    => array(
				'site_id' => array( 'site-one', 'site-two' ),
			),
			'sftp'       => array(
				'username' => array( 'site-one', 'site-two' ),
			),
		);
	}

	/**
	 * Asserts that every destination field still holds the super admin's value.
	 *
	 * @param string $path The write path just tried.
	 */
	private function assert_destinations_kept( string $path ): void {
		$registry = \Glaciate\Deploy\Registry::instance();
		foreach ( $this->destination_fields() as $id => $fields ) {
			foreach ( $fields as $field => $values ) {
				$this->assertSame( $values[0], \Glaciate\Deploy\TargetConfig::value( $registry->get( $id ), $field ), "{$path} moved {$id} {$field}." );
			}
		}
	}

	/**
	 * A credential pinned in wp-config.php serves every site in the network, so the fields that choose where it writes
	 * belong to super admins too (D56). None of the write paths a site admin can reach may change them.
	 */
	public function test_site_admins_cannot_choose_where_a_shared_credential_writes(): void {
		$registry = \Glaciate\Deploy\Registry::instance();
		$super    = self::factory()->user->create();
		grant_super_admin( $super );
		wp_set_current_user( $super );
		$attempt = array();
		foreach ( $this->destination_fields() as $id => $fields ) {
			foreach ( $fields as $field => $values ) {
				\Glaciate\Deploy\TargetConfig::set( $registry->get( $id ), $field, $values[0] );
				$attempt[ $id ][ $field ] = $values[1];
			}
		}
		$this->assert_destinations_kept( 'Setup' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( Capabilities::MANAGE );
		wp_set_current_user( $admin );
		$this->assertFalse( SettingsPage::can_edit_paths() );

		// TargetConfig::set(), which the settings screen's helpers and extensions use.
		foreach ( $attempt as $id => $fields ) {
			foreach ( $fields as $field => $value ) {
				try {
					\Glaciate\Deploy\TargetConfig::set( $registry->get( $id ), $field, $value );
					$this->fail( "A site admin set {$id} {$field}." );
				} catch ( \InvalidArgumentException $e ) {
					$this->assertStringContainsString( 'network administrator', $e->getMessage() );
				}
			}
		}
		$this->assert_destinations_kept( 'TargetConfig::set()' );

		// The settings form (options.php runs the sanitize callback inside update_option()).
		$form = array();
		foreach ( $attempt as $id => $fields ) {
			foreach ( $fields as $field => $value ) {
				$form[ $id ][ $field ] = is_bool( $value ) ? ( $value ? '1' : '0' ) : $value;
			}
		}
		$_POST['option_page'] = SettingsPage::GROUP; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Simulated options.php submission.
		update_option( Settings::OPTION, SettingsPage::sanitize( array( 'targets' => $form ) ) );
		unset( $_POST['option_page'] );
		$this->assert_destinations_kept( 'The settings form' );

		// A direct Settings::update() during an admin request, where the setting is registered.
		register_setting(
			SettingsPage::GROUP,
			Settings::OPTION,
			array( 'sanitize_callback' => array( SettingsPage::class, 'sanitize' ) )
		);
		Settings::update( array( 'targets' => $attempt ) );
		unregister_setting( SettingsPage::GROUP, Settings::OPTION );
		$this->assert_destinations_kept( 'Settings::update()' );

		ob_start();
		SettingsPage::field_target_panel( array( 'deployer' => $registry->get( 'netlify' ) ) );
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '#id="glaciate-target-netlify-site-id"[^>]*disabled#', $html );
	}

	public function test_site_admin_form_cannot_move_restricted_target_fields(): void {
		$super = self::factory()->user->create();
		grant_super_admin( $super );
		wp_set_current_user( $super );
		$sftp = \Glaciate\Deploy\Registry::instance()->get( 'sftp' );
		\Glaciate\Deploy\TargetConfig::set( $sftp, 'host', 'deploy.example.org' );

		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( Capabilities::MANAGE );
		wp_set_current_user( $admin );
		$_POST['option_page'] = SettingsPage::GROUP; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Simulated options.php submission.
		$input                = array(
			'targets' => array(
				'sftp' => array(
					'host'     => 'internal.example',
					'username' => 'deploy',
					'host_key' => 'SHA256:' . str_repeat( 'A', 43 ),
				),
			),
		);
		update_option( Settings::OPTION, SettingsPage::sanitize( $input ) );
		unset( $_POST['option_page'] );
		$this->assertSame( 'deploy.example.org', \Glaciate\Deploy\TargetConfig::value( $sftp, 'host' ) );
		$this->assertSame( '', (string) \Glaciate\Deploy\TargetConfig::value( $sftp, 'username' ), 'The user name chooses whose files are written (D56).' );
		$this->assertSame( 'SHA256:' . str_repeat( 'A', 43 ), \Glaciate\Deploy\TargetConfig::value( $sftp, 'host_key' ), 'Unrestricted fields stay editable.' );

		ob_start();
		SettingsPage::field_target_panel( array( 'deployer' => $sftp ) );
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '#id="glaciate-target-sftp-host"[^>]*disabled#', $html );
		$this->assertStringContainsString( 'Only a network administrator can change this.', $html );
	}

	public function test_site_admins_cannot_change_server_paths(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		get_userdata( $admin )->add_cap( Capabilities::MANAGE );
		wp_set_current_user( $admin );
		$this->assertFalse( is_super_admin( $admin ) );
		$this->assertFalse( SettingsPage::can_edit_paths() );

		Settings::update(
			array(
				'local_path' => '/srv/site-one',
				'connect_to' => '',
			)
		);
		$_POST['option_page'] = SettingsPage::GROUP;
		$clean                = SettingsPage::sanitize(
			array(
				'local_path' => '/srv/other-site',
				'connect_to' => 'evil.internal:6379',
			)
		);
		unset( $_POST['option_page'] );
		$this->assertSame( '/srv/site-one', $clean['local_path'] );
		$this->assertSame( '', $clean['connect_to'] );
	}

	public function test_super_admin_can_change_server_paths(): void {
		$super = self::factory()->user->create();
		grant_super_admin( $super );
		wp_set_current_user( $super );
		$this->assertTrue( SettingsPage::can_edit_paths() );
	}

	public function test_author_archives_follow_each_sites_own_posts(): void {
		$user = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create( array( 'post_author' => $user ) );
		$site = self::factory()->blog->create();
		add_user_to_blog( $site, $user, 'author' );

		$urls_recorded = static function () use ( $user ): ?array {
			$listener = new ChangeListener();
			$listener->touch_user( $user );
			$listener->flush();
			foreach ( ( new ChangeRepository() )->peek() as $change ) {
				if ( 'user' === $change['object_type'] && (string) $user === $change['object_key'] ) {
					return (array) ( $change['payload']['urls'] ?? array() );
				}
			}
			return null;
		};

		switch_to_blog( $site );
		Plugin::activate();
		( new ChangeRepository() )->flush();
		$on_two = $urls_recorded();
		$this->assertSame( array(), $on_two, 'A member with no posts on this site gets no author archive here.' );
		$this->assertSame( 'author-without-posts', $this->render_exclusion( get_author_posts_url( $user ) ) );
		restore_current_blog();

		( new ChangeRepository() )->flush();
		$this->assertContains( get_author_posts_url( $user ), (array) $urls_recorded(), 'The site where the user has posts keeps the archive.' );
	}

	/**
	 * Exclusion reason a render of the address stores, on the current site.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private function render_exclusion( string $url ): string {
		$urls     = new UrlRepository();
		$id       = $urls->ensure( $url );
		$recorder = new Recorder( $id, RenderToken::mark( 'token' ) );
		$recorder->observe();
		$this->go_to( $url );
		$recorder->finish();
		return (string) $urls->get( $id )['render_exclude'];
	}
}
