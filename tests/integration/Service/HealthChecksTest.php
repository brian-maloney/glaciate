<?php
/**
 * The address and extension checks, and Site Health registering every check `doctor` runs.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Service;

use Stillpress\Admin\SiteHealth;
use Stillpress\Service\HealthChecks;
use Stillpress\Support\Settings;

final class HealthChecksTest extends \WP_UnitTestCase {

	public function tear_down(): void {
		Settings::update(
			array(
				'public_url'     => '',
				'origin_aliases' => array(),
			)
		);
		remove_all_filters( 'home_url' );
		remove_all_filters( 'site_url' );
		parent::tear_down();
	}

	private function live_site( string $home, string $wp = '' ): void {
		add_filter(
			'home_url',
			static function ( $url, $path ) use ( $home ) {
				return rtrim( $home, '/' ) . '/' . ltrim( (string) $path, '/' );
			},
			10,
			2
		);
		$wp = '' === $wp ? $home : $wp;
		add_filter(
			'site_url',
			static function ( $url, $path ) use ( $wp ) {
				return rtrim( $wp, '/' ) . '/' . ltrim( (string) $path, '/' );
			},
			10,
			2
		);
	}

	public function test_site_health_runs_every_direct_check(): void {
		$tests = SiteHealth::tests( array() );
		foreach ( HealthChecks::DIRECT as $id ) {
			$this->assertArrayHasKey( 'stillpress_' . $id, $tests['direct'], $id );
		}
		$this->assertSame( HealthChecks::DIRECT, array_keys( ( new HealthChecks() )->direct() ) );
	}

	public function test_a_public_address_on_the_internet_is_good(): void {
		$this->live_site( 'https://edit.example.org' );
		Settings::update( array( 'public_url' => 'https://www.example.org' ) );
		$this->assertSame( 'good', ( new HealthChecks() )->addresses()['status'] );
	}

	public function test_links_to_this_computer_are_flagged(): void {
		$this->live_site( 'http://localhost:8889' );
		$check = ( new HealthChecks() )->addresses();
		$this->assertSame( 'recommended', $check['status'] );
		$this->assertStringContainsString( 'No public address is set', $check['description'] );
		$this->assertStringContainsString( 'localhost:8889', $check['description'] );

		Settings::update( array( 'public_url' => 'http://192.168.1.20' ) );
		$this->assertStringContainsString( 'The public address points at 192.168.1.20', ( new HealthChecks() )->addresses()['description'] );
	}

	public function test_https_site_published_over_http_is_flagged(): void {
		$this->live_site( 'https://edit.example.org' );
		Settings::update( array( 'public_url' => 'http://www.example.org' ) );
		$check = ( new HealthChecks() )->addresses();
		$this->assertSame( 'recommended', $check['status'] );
		$this->assertStringContainsString( 'plain http', $check['description'] );
	}

	public function test_wordpress_on_another_host_is_flagged_until_it_is_an_alias(): void {
		$this->live_site( 'https://www.example.org', 'https://wp.example.org' );
		Settings::update( array( 'public_url' => 'https://static.example.org' ) );
		$check = ( new HealthChecks() )->addresses();
		$this->assertSame( 'recommended', $check['status'] );
		$this->assertStringContainsString( 'wp.example.org', $check['description'] );

		Settings::update( array( 'origin_aliases' => array( 'wp.example.org' ) ) );
		$this->assertSame( 'good', ( new HealthChecks() )->addresses()['status'] );
	}

	public function test_extensions_are_reported(): void {
		$check = ( new HealthChecks() )->extensions();
		$ok    = function_exists( 'curl_multi_init' ) && class_exists( \ZipArchive::class );
		$this->assertSame( $ok ? 'good' : 'recommended', $check['status'], $check['description'] );
	}
}
