<?php
/**
 * TLS certificate checks on loopback requests.
 *
 * Uses the Git test server's self-signed certificate. Start it with `npm run git:start`; these tests skip themselves
 * when git.test isn't reachable.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Render;

use Stillpress\Render\LoopbackClient;
use Stillpress\Security\UrlPolicy;

final class LoopbackClientTest extends \WP_UnitTestCase {

	const URL = 'https://git.test/stillpress-loopback-probe';

	public function set_up(): void {
		parent::set_up();
		$socket = @fsockopen( 'git.test', 443, $errno, $errstr, 2 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Probe.
		if ( false === $socket ) {
			$this->markTestSkipped( 'The Git test server is not running (npm run git:start).' );
		}
		fclose( $socket );
	}

	private function fetch(): \Stillpress\Render\RenderResult {
		$client = new LoopbackClient( new UrlPolicy( array( 'https://git.test' ) ), '', 1, 15.0 );
		return $client->fetch( array( array( 'url' => self::URL ) ) )[0];
	}

	public function test_a_self_signed_certificate_is_accepted_by_default(): void {
		$result = $this->fetch();
		$this->assertNull( $result->error );
		$this->assertGreaterThan( 0, $result->status );
	}

	public function test_the_filter_gets_the_request_url_and_a_failure_names_it(): void {
		$seen = array();
		add_filter(
			'https_local_ssl_verify',
			static function ( $verify, $url ) use ( &$seen ) {
				$seen[] = $url;
				return true;
			},
			10,
			2
		);

		$result = $this->fetch();

		$this->assertSame( array( self::URL ), $seen );
		$this->assertStringStartsWith( '60 ', (string) $result->error );
		$this->assertStringContainsString( 'https_local_ssl_verify', (string) $result->error );
	}

	public function test_a_filter_that_turns_checks_off_for_local_urls_still_works(): void {
		// A common shape: check certificates everywhere except on the site's own host.
		add_filter(
			'https_local_ssl_verify',
			static function ( $verify, $url ) {
				return 'git.test' !== wp_parse_url( $url, PHP_URL_HOST );
			},
			10,
			2
		);
		$this->assertNull( $this->fetch()->error );
	}
}
