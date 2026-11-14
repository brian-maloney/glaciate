<?php
/**
 * The public form routes.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Forms;

use Glaciate\Forms\Endpoint;
use Glaciate\Forms\Receiver;
use Glaciate\Forms\Signature;
use Glaciate\Security\Secrets;
use Glaciate\Support\Settings;
use WP_REST_Request;
use WP_UnitTestCase;

final class EndpointTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_filter(
			'wp_mail_from',
			static function () {
				return 'wordpress@example.org';
			}
		);
		Settings::update(
			array(
				'public_url' => 'https://static.example.org',
				'forms'      => 'site',
			)
		);
		do_action( 'rest_api_init', rest_get_server() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core hook.
	}

	public function tear_down(): void {
		Secrets::delete( 'forms', 'secret' );
		parent::tear_down();
	}

	private static function submit( string $accept, array $body ): \WP_REST_Response {
		$request = new WP_REST_Request( 'POST', '/glaciate/v1/forms/submit' );
		$request->set_header( 'accept', $accept );
		$request->set_header( 'origin', 'https://static.example.org' );
		$request->set_body_params( $body );
		return rest_get_server()->dispatch( $request );
	}

	private static function contact(): array {
		return array(
			'glaciate_form' => 'form:contact',
			'glaciate_page' => 'https://static.example.org/contact/',
			'glaciate_hp'   => '',
			'glaciate_t'    => '7',
			'message'       => 'Hi',
		);
	}

	public function test_json_answer_for_anonymous_visitors(): void {
		wp_set_current_user( 0 );
		$response = self::submit( 'application/json', self::contact() );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );
		$this->assertSame( 'sent', $response->get_data()['status'] );

		$spam = self::submit( 'application/json', array( 'glaciate_hp' => 'x' ) + self::contact() );
		$this->assertSame( 403, $spam->get_status() );
		$this->assertFalse( $spam->get_data()['ok'] );
	}

	public function test_html_answer_without_javascript(): void {
		$response = self::submit( 'text/html,application/xhtml+xml', self::contact() );
		$this->assertSame( Endpoint::HTML, $response->get_headers()['Content-Type'] );
		$request = new WP_REST_Request( 'POST', '/glaciate/v1/forms/submit' );
		ob_start();
		$served = Endpoint::serve_html( false, $response, $request, rest_get_server() );
		$html   = (string) ob_get_clean();
		$this->assertTrue( $served );
		$this->assertStringStartsWith( '<!doctype html>', $html );
		$this->assertStringContainsString( 'Your message was sent', $html );
		$this->assertStringContainsString( 'href="https://static.example.org/contact/"', $html );

		// Answers of other routes are left to the REST server.
		$other = new WP_REST_Request( 'GET', '/glaciate/v1/status' );
		$this->assertFalse( Endpoint::serve_html( false, $response, $other, rest_get_server() ) );
	}

	public function test_back_link_stays_on_the_public_site(): void {
		$response = self::submit( 'text/html', array( 'glaciate_page' => 'https://evil.example/' ) + self::contact() );
		$this->assertSame( 'https://static.example.org', $response->get_data()['back'] );
	}

	public function test_the_html_page_escapes_every_part_where_it_prints_it(): void {
		ob_start();
		Endpoint::page( 'Tom & <b>Jerry</b>', array( '<script>alert(1)</script>', array( 'not text' ) ), 'javascript:alert(1)' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '<p>Tom &amp; &lt;b&gt;Jerry&lt;/b&gt;</p>', $html );
		$this->assertStringContainsString( '<li>&lt;script&gt;alert(1)&lt;/script&gt;</li>', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
	}

	public function test_relay_route(): void {
		Settings::update( array( 'forms' => 'receiver' ) );
		Secrets::set( 'forms', 'secret', 'shared' );
		$pairs = array();
		foreach ( self::contact() as $name => $value ) {
			$pairs[] = array( $name, $value );
		}
		$body    = Receiver::payload( 'form:contact', '', $pairs, '192.0.2.9', 'UA' );
		$request = new WP_REST_Request( 'POST', '/glaciate/v1/forms/relay' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_header( 'x-glaciate-signature', Signature::sign( $body, 'shared', time() ) );
		$request->set_body( $body );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['ok'] );

		$request->set_header( 'x-glaciate-signature', Signature::sign( $body, 'other', time() ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
	}
}
