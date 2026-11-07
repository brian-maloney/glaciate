<?php
/**
 * Loading the search client on rendered pages.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Search;

use Glaciate\Search\Frontend;

final class FrontendTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		wp_dequeue_script( Frontend::HANDLE );
		wp_deregister_script( Frontend::HANDLE );
		remove_action( 'wp_enqueue_scripts', array( Frontend::class, 'enqueue' ) );
		parent::tear_down();
	}

	public function test_live_requests_keep_wordpress_search(): void {
		Frontend::register();
		$this->assertFalse( has_action( 'wp_enqueue_scripts', array( Frontend::class, 'enqueue' ) ) );
	}

	public function test_the_client_is_deferred_with_its_settings_before_it(): void {
		Frontend::enqueue();
		$scripts = wp_scripts();
		$this->assertTrue( wp_script_is( Frontend::HANDLE, 'enqueued' ) );
		$this->assertSame( 'defer', $scripts->get_data( Frontend::HANDLE, 'strategy' ) );

		$before = trim( implode( "\n", array_filter( (array) $scripts->get_data( Frontend::HANDLE, 'before' ) ) ) );
		$this->assertStringStartsWith( 'window.glaciateSearchSettings = {', $before );
		$this->assertStringContainsString( wp_json_encode( home_url( '/glaciate-search/' ) ), $before );
	}

	public function test_settings_can_be_filtered(): void {
		$filter = static function ( $settings ) {
			$settings['style'] = '';
			return $settings;
		};
		add_filter( 'glaciate_search_settings', $filter );
		$settings = Frontend::settings();
		remove_filter( 'glaciate_search_settings', $filter );
		$this->assertSame( '', $settings['style'] );
		$this->assertSame( Frontend::LIMIT, $settings['limit'] );
	}

	public function test_result_counts_carry_every_plural_form(): void {
		$this->assertSame( array( array( '%d results', '%d result' ), '010' ), Frontend::result_counts() );

		// A three-form language (Polish rules), the way a loaded translation answers _n().
		$polish = static function ( $translation, $single, $plural, $n ) {
			if ( 1 === (int) $n ) {
				return '%d wynik';
			}
			$few = $n % 10 >= 2 && $n % 10 <= 4 && ( $n % 100 < 10 || $n % 100 >= 20 );
			return $few ? '%d wyniki' : '%d wyników';
		};
		add_filter( 'ngettext_glaciate', $polish, 10, 4 );
		list( $forms, $rule ) = Frontend::result_counts();
		remove_filter( 'ngettext_glaciate', $polish, 10 );

		$this->assertSame( array( '%d wyników', '%d wynik', '%d wyniki' ), $forms );
		$this->assertSame( 196, strlen( $rule ), 'Up to 194 ("few"), then 195-199 cut to one digit.' );
		$this->assertSame( '0122200000', substr( $rule, 0, 10 ) );
		$this->assertSame( '0000000000', substr( $rule, 10, 10 ) );
		$this->assertSame( '0022200000', substr( $rule, 120, 10 ) );
	}
}
