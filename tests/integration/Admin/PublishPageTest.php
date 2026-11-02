<?php
/**
 * The first-run form on the Publish screen.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Admin;

use Stillpress\Admin\PublishPage;
use Stillpress\Security\Capabilities;
use Stillpress\Support\Settings;

final class PublishPageTest extends \WP_UnitTestCase {

	public static function wpSetUpBeforeClass(): void {
		Capabilities::grant_defaults();
	}

	public function set_up(): void {
		parent::set_up();
		delete_option( 'stillpress_published_once' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', \Stillpress\Storage\Schema::table( 'changes' ) ) );
	}

	private function render(): string {
		ob_start();
		PublishPage::render();
		return (string) ob_get_clean();
	}

	public function test_first_run_defaults_to_the_local_folder(): void {
		Settings::update( array( 'target' => 'local' ) );
		$html = $this->render();
		$this->assertMatchesRegularExpression( '/value="local"\s+checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="zip"\s+checked/', $html );
	}

	public function test_first_run_keeps_a_target_chosen_in_settings(): void {
		Settings::update( array( 'target' => 'cloudflare' ) );
		$html = $this->render();
		$this->assertMatchesRegularExpression( '/value="cloudflare"\s+checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="local"\s+checked/', $html );
	}

	public function test_pending_changes_are_previewed(): void {
		update_option( 'stillpress_published_once', 1 );
		$post = self::factory()->post->create( array( 'post_title' => 'Edited story' ) );
		( new \Stillpress\Storage\ChangeRepository() )->record( 'post', (string) $post, array( 'urls' => array( get_permalink( $post ) ) ) );

		$html = $this->render();
		$this->assertStringContainsString( 'Waiting to be published', $html );
		$this->assertStringContainsString( 'Post: Edited story', $html );
		$this->assertStringContainsString( 'Publishing these changes rebuilds 1 address', $html );
		$this->assertStringContainsString( '<code>' . esc_html( get_permalink( $post ) ) . '</code>', $html );
	}

	public function test_no_preview_without_pending_changes(): void {
		update_option( 'stillpress_published_once', 1 );
		$this->assertStringNotContainsString( 'Waiting to be published', $this->render() );
	}

	public function test_first_run_keeps_zip_selected(): void {
		Settings::update( array( 'target' => 'zip' ) );
		$html = $this->render();
		$this->assertMatchesRegularExpression( '/value="zip"\s+checked/', $html );
		$this->assertDoesNotMatchRegularExpression( '/value="local"\s+checked/', $html );
	}
}
