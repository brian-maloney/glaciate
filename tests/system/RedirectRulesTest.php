<?php
/**
 * Crawled links can't put pattern rules into `_redirects`.
 *
 * WordPress answers a 404 by redirecting to the first post whose slug starts with the sanitized request, so links to
 * addresses such as /:x or /x* come back as redirects to a real post. Their paths must not reach `_redirects` as
 * written, where static hosts would read them as a placeholder and a splat.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\System;

use Glaciate\Storage\Schema;

final class RedirectRulesTest extends SystemTestCase {

	public function test_crawled_pattern_paths_stay_out_of_redirects(): void {
		global $wpdb;
		wp_insert_post(
			array(
				'post_title'   => 'Xylophone',
				'post_name'    => 'xylophone',
				'post_status'  => 'publish',
				'post_content' => 'A post to be guessed.',
			)
		);
		$links = '';
		foreach ( array( '/:x', '/x*', '/:x/x*', '/xyl/' ) as $path ) {
			$links .= '<a href="' . esc_url( home_url( $path ) ) . '">link</a> ';
		}
		wp_insert_post(
			array(
				'post_title'   => 'Links',
				'post_name'    => 'links',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => $links,
			)
		);
		$build = $this->build( 'full' );

		// The links were crawled and the origin redirected them, so without the check they would become rules.
		$recorded = $wpdb->get_col( $wpdb->prepare( "SELECT url FROM %i WHERE kind = 'redirect' AND (url LIKE %s OR url LIKE %s)", Schema::table( 'urls' ), '%/:x%', '%/x*%' ) );
		$this->assertNotEmpty( $recorded, 'The pattern addresses were not crawled as redirects; the test proves nothing.' . "\n" . $this->log( (int) $build['id'] ) );

		$file = $this->target . '/_redirects';
		$this->assertFileExists( $file );
		$lines = array_filter( explode( "\n", (string) file_get_contents( $file ) ) );
		foreach ( $lines as $line ) {
			$from = explode( ' ', $line )[0];
			$this->assertDoesNotMatchRegularExpression( '/[*:]/', $from, 'Pattern syntax in a crawled rule: ' . $line );
		}
		$this->assertContains( '/xyl/ /xylophone/ 301', $lines, 'Plain origin redirects are still written.' );
	}

	public function test_admin_redirects_keep_their_wildcards(): void {
		\Glaciate\Support\Settings::update(
			array(
				'redirects' => array(
					array(
						'from' => '/old/*',
						'to'   => '/new/:splat',
						'code' => 301,
					),
				),
			)
		);
		$this->build( 'full' );
		$this->assertStringContainsString( "/old/* /new/:splat 301\n", (string) file_get_contents( $this->target . '/_redirects' ) );
	}
}
