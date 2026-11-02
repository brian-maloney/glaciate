<?php
/**
 * Nonces in inline scripts are emptied so unchanged pages hash the same from build to build.
 *
 * @package Stillpress
 */

namespace Stillpress\Tests\Unit\Rewrite;

use PHPUnit\Framework\TestCase;
use Stillpress\Rewrite\NonceStripper;

final class NonceStripperTest extends TestCase {

	/**
	 * @dataProvider provide_cases
	 */
	public function test_strips( string $input, string $expected ): void {
		$this->assertSame( $expected, NonceStripper::strip( $input ) );
	}

	public function provide_cases(): array {
		return array(
			'core apiFetch middleware'    => array(
				'<script id="wp-api-fetch-js-after">wp.apiFetch.nonceMiddleware = wp.apiFetch.createNonceMiddleware( "a1b2c3d4e5" );</script>',
				'<script id="wp-api-fetch-js-after">wp.apiFetch.nonceMiddleware = wp.apiFetch.createNonceMiddleware( "" );</script>',
			),
			'wpApiSettings'               => array(
				'<script>var wpApiSettings = {"root":"https:\/\/example.org\/wp-json\/","nonce":"0123456789","versionString":"wp\/v2\/"};</script>',
				'<script>var wpApiSettings = {"root":"https:\/\/example.org\/wp-json\/","nonce":"","versionString":"wp\/v2\/"};</script>',
			),
			'plugin key with nonce in it' => array(
				"<script>var shop = {'update_cart_nonce': 'abcdef0123', ajax: 1};</script>",
				"<script>var shop = {'update_cart_nonce': '', ajax: 1};</script>",
			),
			'unquoted key'                => array(
				'<script>x = { restNonce: "abcdef0123" };</script>',
				'<script>x = { restNonce: "" };</script>',
			),
			'variable assignment'         => array(
				'<script>var my_nonce = "abcdef0123";</script>',
				'<script>var my_nonce = "";</script>',
			),
			'JSON inside a JS string'     => array(
				'<script>var s = JSON.parse("{\"nonce\":\"abcdef0123\"}");</script>',
				'<script>var s = JSON.parse("{\"nonce\":\"\"}");</script>',
			),
			'several scripts'             => array(
				'<script>a={"nonce":"aaaaaaaaaa"}</script><p>nonce</p><script type="text/javascript">b={"nonce":"bbbbbbbbbb"}</script>',
				'<script>a={"nonce":""}</script><p>nonce</p><script type="text/javascript">b={"nonce":""}</script>',
			),
		);
	}

	/**
	 * @dataProvider provide_untouched
	 */
	public function test_leaves_alone( string $input ): void {
		$this->assertSame( $input, NonceStripper::strip( $input ) );
	}

	public function provide_untouched(): array {
		return array(
			'form field outside scripts' => array( '<input type="hidden" name="_wpnonce" value="abcdef0123">' ),
			'data attribute'             => array( '<div data-nonce="abcdef0123"></div>' ),
			'not ten hex characters'     => array( '<script>a={"nonce":"abcdef01234"}</script>' ),
			'uppercase hex'              => array( '<script>a={"nonce":"ABCDEF0123"}</script>' ),
			'non-hex value'              => array( '<script>a={"nonce":"ghijklmnop"}</script>' ),
			'key without nonce'          => array( '<script>a={"token":"abcdef0123"}</script>' ),
			'CSP nonce attribute'        => array( '<script nonce="abcdef0123">a=1</script>' ),
			'already empty'              => array( '<script>a={"nonce":""}</script>' ),
		);
	}
}
