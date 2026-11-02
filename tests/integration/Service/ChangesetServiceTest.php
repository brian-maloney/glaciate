<?php
/**
 * Previewing what the next "Publish changes" would rebuild.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Service;

use Stillpress\Deps\DependencyStore;
use Stillpress\Service\ChangesetService;
use Stillpress\Storage\ChangeRepository;
use Stillpress\Storage\Schema;
use Stillpress\Storage\UrlRepository;

final class ChangesetServiceTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( 'changes' ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function published( string $path, array $deps ): int {
		$urls = new UrlRepository();
		$id   = $urls->ensure( home_url( $path ) );
		$urls->update(
			$id,
			array(
				'path'         => ltrim( $path, '/' ) . 'index.html',
				'content_hash' => str_repeat( 'a', 64 ),
				'deps_state'   => 'recorded',
			)
		);
		( new DependencyStore() )->replace( $id, $deps, array() );
		return $id;
	}

	public function test_nothing_pending(): void {
		$preview = ( new ChangesetService() )->preview();
		$this->assertSame( 0, $preview['pending'] );
		$this->assertFalse( $preview['full'] );
		$this->assertSame( array(), $preview['items'] );
		$this->assertSame( 0, $preview['pages'] );
	}

	public function test_lists_changes_and_the_pages_they_rebuild_without_claiming_them(): void {
		$this->published( '/uses-title/', array( 'option' => array( 'blogname' ) ) );
		$this->published( '/unrelated/', array( 'option' => array( 'blogdescription' ) ) );
		$post = self::factory()->post->create( array( 'post_title' => 'Hello there' ) );
		$repo = new ChangeRepository();
		$repo->record( 'option', 'blogname' );
		$repo->record( 'post', (string) $post, array( 'urls' => array( get_permalink( $post ) ) ) );
		$repo->record( 'option', 'blogname' );

		$preview = ( new ChangesetService() )->preview();

		$this->assertSame( 3, $preview['pending'] );
		$this->assertSame( 2, $preview['item_count'], 'Repeated changes to one object are listed once.' );
		$labels = wp_list_pluck( $preview['items'], 'label' );
		$this->assertContains( 'Site setting: blogname', $labels );
		$this->assertContains( 'Post: Hello there', $labels );
		$this->assertContains( get_edit_post_link( $post, 'raw' ), wp_list_pluck( $preview['items'], 'link' ) );
		$this->assertFalse( $preview['full'] );
		$this->assertContains( home_url( '/uses-title/' ), $preview['urls'] );
		$this->assertContains( get_permalink( $post ), $preview['urls'] );
		$this->assertNotContains( home_url( '/unrelated/' ), $preview['urls'] );
		$this->assertSame( 3, $repo->pending_count(), 'Previewing leaves the changes for the next build.' );
	}

	public function test_addresses_are_shown_at_the_public_address(): void {
		\Stillpress\Support\Settings::update( array( 'public_url' => 'https://static.example.org' ) );
		$this->published( '/uses-title/', array( 'option' => array( 'blogname' ) ) );
		( new ChangeRepository() )->record( 'option', 'blogname' );
		$this->assertSame( array( 'https://static.example.org/uses-title/' ), ( new ChangesetService() )->preview()['urls'] );
		\Stillpress\Support\Settings::update( array( 'public_url' => '' ) );
	}

	public function test_a_full_change_says_every_page_is_rebuilt(): void {
		$this->published( '/uses-title/', array( 'option' => array( 'blogname' ) ) );
		$repo = new ChangeRepository();
		$repo->record( 'option', 'blogname' );
		$repo->record( 'full', 'code' );

		$preview = ( new ChangesetService() )->preview();
		$this->assertTrue( $preview['full'] );
		$this->assertSame( 0, $preview['pages'] );
		$this->assertContains( 'Theme or plugins changed: every page is rebuilt', wp_list_pluck( $preview['items'], 'label' ) );
	}

	public function test_deleted_objects_still_get_a_label(): void {
		$this->assertSame( 'Deleted content (ID 999999)', ChangesetService::describe( 'post', '999999' )['label'] );
		$this->assertSame( 'Site setting permalink_structure changed: every page is rebuilt', ChangesetService::describe( 'full', 'option:permalink_structure' )['label'] );
	}
}
