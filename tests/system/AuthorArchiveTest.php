<?php
/**
 * Author archives are published only for users with published posts (D62).
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\System;

use Glaciate\Support\Paths;

final class AuthorArchiveTest extends SystemTestCase {

	/**
	 * Deployed search index files that mention a string (case-insensitive).
	 *
	 * @param string $needle Text.
	 * @return string[] Relative paths.
	 */
	private function search_files_with( string $needle ): array {
		$out = array();
		foreach ( $this->snapshot() as $path => $contents ) {
			if ( 0 === strpos( $path, 'glaciate-search/' ) && false !== stripos( $contents, $needle ) ) {
				$out[] = $path;
			}
		}
		return $out;
	}

	/**
	 * Creates a user.
	 *
	 * @param string $login        Login, also the nicename.
	 * @param string $display_name Display name.
	 * @param string $role         Role.
	 * @return int
	 */
	private function user( string $login, string $display_name, string $role = 'author' ): int {
		return (int) wp_insert_user(
			array(
				'user_login'   => $login,
				'user_pass'    => wp_generate_password(),
				'user_email'   => $login . '@example.org',
				'display_name' => $display_name,
				'role'         => $role,
			)
		);
	}

	/**
	 * Publishes a post.
	 *
	 * @param int    $author Author.
	 * @param string $slug   Slug.
	 * @return int
	 */
	private function post( int $author, string $slug ): int {
		return (int) wp_insert_post(
			array(
				'post_title'   => ucfirst( $slug ),
				'post_name'    => $slug,
				'post_author'  => $author,
				'post_content' => '<!-- wp:paragraph --><p>Body.</p><!-- /wp:paragraph -->',
				'post_status'  => 'publish',
			)
		);
	}

	public function test_accounts_without_posts_get_no_author_page(): void {
		$writer = $this->user( 'writer', 'Wendeline Writer' );
		$this->post( $writer, 'written' );
		$member = $this->user( 'quiet-member', 'Quillon Membership', 'subscriber' );
		$this->build();

		$this->assertNotNull( $this->page( '/author/writer/' ), 'An author with posts has an archive.' );
		$this->assertNull( $this->page( '/author/quiet-member/' ) );

		// A new account and a profile edit are recorded changes; neither may publish an archive.
		$this->user( 'fresh-member', 'Fennimore Freshly', 'subscriber' );
		wp_update_user(
			array(
				'ID'           => $member,
				'display_name' => 'Quillon Renamed',
				'description'  => 'About me',
			)
		);
		$build = $this->build( 'incremental' );
		$this->assertSame( 'incremental', $build['kind'] );
		$log = $this->log( (int) $build['id'] );
		$this->assertNull( $this->page( '/author/quiet-member/' ), "Log:\n" . $log );
		$this->assertNull( $this->page( '/author/fresh-member/' ), "Log:\n" . $log );
		$this->assertSame( array(), preg_grep( '#^author/(quiet|fresh)-member/#', array_keys( $this->snapshot() ) ) );
		foreach ( array( 'quiet-member', 'fresh-member', 'Quillon', 'Fennimore' ) as $needle ) {
			$this->assertSame( array(), $this->search_files_with( $needle ), "The search index mentions {$needle}." );
		}
	}

	public function test_losing_the_last_post_unpublishes_the_archive_and_a_new_post_brings_it_back(): void {
		$keeper = $this->user( 'keeper', 'Kestrel Keeper' );
		$this->post( $keeper, 'kept' );
		$author = $this->user( 'fleeting', 'Philomena Fleeting' );
		$post   = $this->post( $author, 'fleeting-post' );
		$this->build();
		$this->assertNotNull( $this->page( '/author/fleeting/' ) );
		$this->assertNotEmpty( $this->search_files_with( 'Philomena' ) );

		wp_trash_post( $post );
		$build = $this->build( 'incremental' );
		$log   = $this->log( (int) $build['id'] );
		$this->assertNull( $this->page( '/author/fleeting/' ), "Log:\n" . $log );
		$this->assertFileDoesNotExist( Paths::cache_dir() . '/author/fleeting/index.html' );
		$this->assertSame( array(), $this->search_files_with( 'Philomena' ), "Log:\n" . $log );
		$this->assertNotNull( $this->page( '/author/keeper/' ), 'Other archives stay.' );

		// The archive has no dependencies left once it is gone; the post change itself leads back to it.
		$this->post( $author, 'second-thoughts' );
		$build = $this->build( 'incremental' );
		$this->assertNotNull( $this->page( '/author/fleeting/' ), "Log:\n" . $this->log( (int) $build['id'] ) );
	}

	public function test_a_full_build_removes_an_empty_archive_published_before(): void {
		global $wpdb;
		$author = $this->user( 'emptied', 'Ottoline Emptied' );
		$post   = $this->post( $author, 'emptied-post' );
		$heir   = $this->user( 'heir', 'Hortensia Heir' );
		$this->build();
		$this->assertNotNull( $this->page( '/author/emptied/' ) );
		$this->assertNotEmpty( $this->search_files_with( 'Ottoline' ) );

		// Leave the archive published while its user has no posts, as an earlier version did for any account it saw
		// change: the post moves without hooks, so no change is recorded.
		$wpdb->update( $wpdb->posts, array( 'post_author' => $heir ), array( 'ID' => $post ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		clean_post_cache( $post );

		$build = $this->build();
		$log   = $this->log( (int) $build['id'] );
		$this->assertNull( $this->page( '/author/emptied/' ), "Log:\n" . $log );
		$this->assertFileDoesNotExist( Paths::cache_dir() . '/author/emptied/index.html' );
		$this->assertStringContainsString( 'author-without-posts', $log );
		$this->assertSame( array(), $this->search_files_with( 'Ottoline' ), "Log:\n" . $log );
		$this->assertNotNull( $this->page( '/author/heir/' ), 'The new author has an archive.' );
	}
}
