<?php
/**
 * The nonces core prints for anonymous visitors are emptied by NonceStripper.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Integration\Render;

use Stillpress\Rewrite\NonceStripper;

final class CoreNoncesTest extends \WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		wp_set_current_user( 0 );
		$GLOBALS['wp_scripts'] = null;
	}

	public function tear_down(): void {
		$GLOBALS['wp_scripts'] = null;
		parent::tear_down();
	}

	/**
	 * @dataProvider provide_handles
	 */
	public function test_core_script_nonces_are_emptied( string $handle ): void {
		wp_enqueue_script( $handle );
		ob_start();
		wp_print_scripts();
		$html = (string) ob_get_clean();

		$nonce = wp_create_nonce( 'wp_rest' );
		$this->assertStringContainsString( $nonce, $html, 'Core no longer prints the nonce this test looks for.' );
		$this->assertStringNotContainsString( $nonce, NonceStripper::strip( $html ) );
	}

	public function provide_handles(): array {
		return array(
			'apiFetch middleware' => array( 'wp-api-fetch' ),
			'wpApiSettings'       => array( 'wp-api' ),
		);
	}
}
