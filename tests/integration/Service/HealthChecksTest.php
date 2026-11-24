<?php
/**
 * The address and extension checks, and Site Health registering every check `doctor` runs.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Service;

use Glaciate\Admin\SiteHealth;
use Glaciate\Service\HealthChecks;
use Glaciate\Support\Settings;

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
			$this->assertArrayHasKey( 'glaciate_' . $id, $tests['direct'], $id );
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

	/**
	 * Runs a callback with forwarding headers set on the current request.
	 *
	 * @param array<string,string> $server   $_SERVER entries.
	 * @param callable             $callback Callback.
	 * @return void
	 */
	private function with_server( array $server, callable $callback ): void {
		$saved = $_SERVER;
		foreach ( \Glaciate\Forms\ClientAddress::HEADERS as $name ) {
			unset( $_SERVER[ $name ] );
		}
		$_SERVER = $server + $_SERVER;
		try {
			$callback();
		} finally {
			$_SERVER = $saved;
		}
	}

	public function test_forms_behind_a_proxy_without_the_client_ip_filter_are_flagged(): void {
		Settings::update(
			array(
				'public_url' => 'https://static.example.org',
				'forms'      => 'site',
			)
		);
		delete_option( \Glaciate\Forms\Endpoint::PROXY_OPTION );
		$this->with_server(
			array( 'REMOTE_ADDR' => '127.0.0.1' ),
			function () {
				$this->assertSame( 'good', ( new HealthChecks() )->forms()['status'], 'no forwarding headers' );
			}
		);
		$this->with_server(
			array(
				'REMOTE_ADDR'          => '10.0.0.1',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
			),
			function () {
				$check = ( new HealthChecks() )->forms();
				$this->assertSame( 'recommended', $check['status'] );
				$this->assertStringContainsString( 'glaciate_form_client_ip', $check['description'] );

				Settings::update( array( 'forms' => 'off' ) );
				$this->assertSame( 'good', ( new HealthChecks() )->forms()['status'], 'not with forms off' );
				Settings::update( array( 'forms' => 'site' ) );

				add_filter(
					'glaciate_form_client_ip',
					static function ( $ip ) {
						return $ip;
					}
				);
				$this->assertSame( 'good', ( new HealthChecks() )->forms()['status'], 'the owner set the filter' );
				remove_all_filters( 'glaciate_form_client_ip' );
			}
		);
		$this->with_server(
			array(
				'REMOTE_ADDR'          => '198.51.100.9',
				'HTTP_X_FORWARDED_FOR' => '198.51.100.9',
			),
			function () {
				$this->assertSame( 'good', ( new HealthChecks() )->forms()['status'], 'REMOTE_ADDR already holds the visitor\'s address' );
			}
		);
		Settings::update( array( 'forms' => 'off' ) );
	}

	public function test_a_submission_through_a_proxy_is_remembered_for_the_check(): void {
		Settings::update(
			array(
				'public_url' => 'https://static.example.org',
				'forms'      => 'site',
			)
		);
		$this->with_server(
			array( 'REMOTE_ADDR' => '127.0.0.1' ),
			function () {
				update_option( \Glaciate\Forms\Endpoint::PROXY_OPTION, time() - DAY_IN_SECONDS, false );
				$this->assertSame( 'recommended', ( new HealthChecks() )->forms()['status'] );
				update_option( \Glaciate\Forms\Endpoint::PROXY_OPTION, time() - 31 * DAY_IN_SECONDS, false );
				$this->assertSame( 'good', ( new HealthChecks() )->forms()['status'], 'forgotten after 30 days' );
			}
		);
		delete_option( \Glaciate\Forms\Endpoint::PROXY_OPTION );
		Settings::update( array( 'forms' => 'off' ) );
	}

	public function test_extensions_are_reported(): void {
		$check = ( new HealthChecks() )->extensions();
		$ok    = function_exists( 'curl_multi_init' ) && class_exists( \ZipArchive::class );
		$this->assertSame( $ok ? 'good' : 'recommended', $check['status'], $check['description'] );
	}
}
