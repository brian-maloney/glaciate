<?php
/**
 * How adapters set up `$_POST`, `$_REQUEST`, `$_SERVER` and the current user for a form plugin, and what they leave
 * behind.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Forms;

use Glaciate\Forms\Adapter\Request;
use Glaciate\Forms\Submission;
use WP_UnitTestCase;

// phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput -- The tests compare the superglobals as they are.

final class AdapterRequestTest extends WP_UnitTestCase {

	/** `$_SERVER` entries the adapter can replace. */
	const NAMES = array( 'REQUEST_METHOD', 'HTTP_USER_AGENT', 'REMOTE_ADDR', 'HTTP_REFERER' );

	/** @var array<string,mixed> */
	private $server = array();

	public function set_up(): void {
		parent::set_up();
		foreach ( self::NAMES as $name ) {
			if ( isset( $_SERVER[ $name ] ) ) {
				$this->server[ $name ] = $_SERVER[ $name ];
			}
		}
	}

	public function tear_down(): void {
		foreach ( self::NAMES as $name ) {
			unset( $_SERVER[ $name ] );
		}
		foreach ( $this->server as $name => $value ) {
			$_SERVER[ $name ] = $value;
		}
		unset( $_SERVER['HTTP_X_SOMETHING_ELSE'] );
		parent::tear_down();
	}

	private function submission( string $ip = '203.0.113.7', string $page = 'https://static.example.org/contact/' ): Submission {
		$pairs = array(
			array( 'glaciate_form', 'form:contact' ),
			array( 'name', 'Ada' ),
		);
		if ( '' !== $page ) {
			$pairs[] = array( 'glaciate_page', $page );
		}
		return Submission::from_pairs( $pairs, $ip, 'Visitor/1.0', true );
	}

	/**
	 * The parts of the request a form plugin reads.
	 *
	 * @return array<string,mixed>
	 */
	private static function seen(): array {
		$seen = array(
			'post'    => $_POST,
			'request' => $_REQUEST,
			'user'    => get_current_user_id(),
		);
		foreach ( self::NAMES as $name ) {
			$seen[ $name ] = $_SERVER[ $name ] ?? null;
		}
		return $seen;
	}

	public function test_the_callback_sees_the_submission(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$_SERVER['REQUEST_METHOD']  = 'GET';
		$_SERVER['HTTP_USER_AGENT'] = 'Receiver/2.0';
		$_SERVER['REMOTE_ADDR']     = '198.51.100.1';
		unset( $_SERVER['HTTP_REFERER'] );

		$seen = Request::run(
			$this->submission(),
			array( 'name' => 'Ada' ),
			static function () {
				return self::seen();
			}
		);

		$this->assertSame(
			array(
				'post'            => array( 'name' => 'Ada' ),
				'request'         => array( 'name' => 'Ada' ),
				'user'            => 0,
				'REQUEST_METHOD'  => 'POST',
				'HTTP_USER_AGENT' => 'Visitor/1.0',
				'REMOTE_ADDR'     => '203.0.113.7',
				'HTTP_REFERER'    => 'https://static.example.org/contact/',
			),
			$seen
		);
	}

	public function test_the_callback_sees_slashed_values_as_wordpress_sets_them(): void {
		$typed      = array(
			'name'    => 'Grace O\'Brien',
			'message' => 'It is in C:\\new\\site, she said "there".',
			'choices' => array( 'a\\b', 'plain' ),
		);
		$submission = Submission::from_pairs( array( array( 'glaciate_form', 'form:contact' ) ), '203.0.113.7', 'Visitor\\1.0 (O\'Brien)', true );

		$seen = Request::run(
			$submission,
			$typed,
			static function () {
				return self::seen();
			}
		);

		$this->assertSame( 'Grace O\\\'Brien', $seen['post']['name'], 'slashed, like a field WordPress received itself' );
		$this->assertSame( $typed, wp_unslash( $seen['post'] ), 'a plugin that removes the slashes gets what the visitor typed' );
		$this->assertSame( $typed, wp_unslash( $seen['request'] ) );
		$this->assertSame( 'Visitor\\1.0 (O\'Brien)', wp_unslash( $seen['HTTP_USER_AGENT'] ) );
		$this->assertNotSame( 'Visitor\\1.0 (O\'Brien)', $seen['HTTP_USER_AGENT'] );
	}

	public function test_the_request_is_put_back_afterwards(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$_SERVER['REQUEST_METHOD']        = 'GET';
		$_SERVER['HTTP_USER_AGENT']       = wp_slash( 'Receiver/2.0 (O\'Brien; C:\\tools)' );
		$_SERVER['REMOTE_ADDR']           = '198.51.100.1';
		$_SERVER['HTTP_X_SOMETHING_ELSE'] = 'untouched';
		unset( $_SERVER['HTTP_REFERER'] );

		Request::run( $this->submission(), array( 'name' => 'Ada' ), '__return_true' );

		$this->assertSame(
			array(
				'post'            => array(),
				'request'         => array(),
				'user'            => $admin,
				'REQUEST_METHOD'  => 'GET',
				'HTTP_USER_AGENT' => wp_slash( 'Receiver/2.0 (O\'Brien; C:\\tools)' ),
				'REMOTE_ADDR'     => '198.51.100.1',
				'HTTP_REFERER'    => null,
			),
			self::seen()
		);
		$this->assertArrayNotHasKey( 'HTTP_REFERER', $_SERVER, 'an entry that was not set is removed again, not left empty' );
		$this->assertSame( 'untouched', $_SERVER['HTTP_X_SOMETHING_ELSE'] );
	}

	public function test_the_request_is_put_back_when_the_form_plugin_throws(): void {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		$_SERVER['REMOTE_ADDR']  = '198.51.100.1';
		$_SERVER['HTTP_REFERER'] = 'https://example.org/a%20page/?q=1&r=2';

		try {
			Request::run(
				$this->submission(),
				array( 'name' => 'Ada' ),
				static function () {
					throw new \RuntimeException( 'The form plugin failed.' );
				}
			);
			$this->fail( 'The exception should reach the caller.' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'The form plugin failed.', $e->getMessage() );
		}

		$this->assertSame( array(), $_POST );
		$this->assertSame( array(), $_REQUEST );
		$this->assertSame( '198.51.100.1', $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( 'https://example.org/a%20page/?q=1&r=2', $_SERVER['HTTP_REFERER'], 'percent-encoded characters survive' );
		$this->assertSame( $admin, get_current_user_id() );
	}

	public function test_a_submission_without_an_address_or_page_keeps_the_requests_own(): void {
		$_SERVER['REMOTE_ADDR']  = '198.51.100.1';
		$_SERVER['HTTP_REFERER'] = 'https://example.org/here/';

		$seen = Request::run(
			$this->submission( '', '' ),
			array(),
			static function () {
				return self::seen();
			}
		);

		$this->assertSame( '198.51.100.1', $seen['REMOTE_ADDR'] );
		$this->assertSame( 'https://example.org/here/', $seen['HTTP_REFERER'] );
		$this->assertSame( '198.51.100.1', $_SERVER['REMOTE_ADDR'] );
		$this->assertSame( 'https://example.org/here/', $_SERVER['HTTP_REFERER'] );
	}
}
