<?php
/**
 * The relay's once-only check: one claim per signature, atomic, with or without a persistent object cache, and
 * markers removed once their signature can no longer verify.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Forms;

use Glaciate\Forms\Adapters;
use Glaciate\Forms\Config;
use Glaciate\Forms\Receiver;
use Glaciate\Forms\ReplayGuard;
use Glaciate\Forms\Result;
use Glaciate\Forms\Signature;
use Glaciate\Security\Secrets;
use Glaciate\Support\Settings;
use Glaciate\Tests\Support\PublishedForms;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/PublishedForms.php';

final class ReplayGuardTest extends WP_UnitTestCase {

	const NOW = 2000000000;

	/** @var bool Object cache flag before the test. */
	private $ext_cache = false;

	public function set_up(): void {
		parent::set_up();
		$this->ext_cache = wp_using_ext_object_cache();
		wp_using_ext_object_cache( false );
	}

	public function tear_down(): void {
		wp_using_ext_object_cache( $this->ext_cache );
		Secrets::delete( 'forms', 'secret' );
		parent::tear_down();
	}

	/**
	 * Markers in the options table.
	 *
	 * @return array<string,string> Option name => expiry.
	 */
	private static function markers(): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( ReplayGuard::PREFIX ) . '%' ), ARRAY_A );
		return array_column( (array) $rows, 'option_value', 'option_name' );
	}

	public function test_a_signature_is_claimed_once(): void {
		$guard = new ReplayGuard();
		$mac   = str_repeat( 'a', 64 );
		$this->assertTrue( $guard->claim( self::NOW, $mac, self::NOW ), 'first' );
		$this->assertFalse( $guard->claim( self::NOW, $mac, self::NOW + 10 ), 'again' );
		$this->assertFalse( ( new ReplayGuard() )->claim( self::NOW, $mac, self::NOW + 300 ), 'again, last second of the window' );
		$this->assertTrue( $guard->claim( self::NOW, str_repeat( 'b', 64 ), self::NOW ), 'another signature' );
		$this->assertTrue( $guard->claim( self::NOW + 1, $mac, self::NOW ), 'same HMAC, another time' );

		$markers = self::markers();
		$this->assertCount( 3, $markers );
		$this->assertContains( (string) ( self::NOW + Signature::TOLERANCE ), $markers, 'kept until the window closes' );
		global $wpdb;
		$autoload = $wpdb->get_col( $wpdb->prepare( 'SELECT autoload FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( ReplayGuard::PREFIX ) . '%' ) );
		$this->assertSame( array( 'off', 'off', 'off' ), $autoload, 'never autoloaded' );
	}

	public function test_the_claim_is_the_insert_itself(): void {
		// A marker another request wrote straight to the table, after this request's caches were filled: the claim
		// mustn't depend on a read that could have happened before that write.
		global $wpdb;
		$mac  = str_repeat( 'c', 64 );
		$name = ReplayGuard::PREFIX . substr( hash( 'sha256', self::NOW . '.' . $mac ), 0, 40 );
		wp_load_alloptions();
		$this->assertFalse( get_option( $name ) );
		$wpdb->insert(
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => (string) ( self::NOW + Signature::TOLERANCE ),
				'autoload'     => 'off',
			)
		);
		$this->assertFalse( ( new ReplayGuard() )->claim( self::NOW, $mac, self::NOW ) );
	}

	public function test_markers_whose_signature_expired_are_pruned(): void {
		$guard = new ReplayGuard();
		$guard->claim( self::NOW, str_repeat( 'a', 64 ), self::NOW );
		$guard->claim( self::NOW + 100, str_repeat( 'b', 64 ), self::NOW + 100 );
		$this->assertCount( 2, self::markers() );

		$guard->prune( self::NOW + Signature::TOLERANCE );
		$this->assertCount( 2, self::markers(), 'the window is still open at its last second' );

		// The next claim prunes on its way.
		$guard->claim( self::NOW + 301, str_repeat( 'd', 64 ), self::NOW + 301 );
		$markers = self::markers();
		$this->assertCount( 2, $markers );
		$this->assertNotContains( (string) ( self::NOW + Signature::TOLERANCE ), $markers, 'expired marker removed' );
		$this->assertContains( (string) ( self::NOW + 100 + Signature::TOLERANCE ), $markers, 'live marker kept' );
	}

	public function test_a_persistent_object_cache_is_used_when_present(): void {
		wp_using_ext_object_cache( true );
		$guard = new ReplayGuard();
		$mac   = str_repeat( 'e', 64 );
		$this->assertTrue( $guard->claim( self::NOW, $mac, self::NOW ) );
		$this->assertFalse( $guard->claim( self::NOW, $mac, self::NOW ) );
		$this->assertSame( array(), self::markers(), 'nothing written to the options table' );
		wp_cache_delete( ReplayGuard::PREFIX . substr( hash( 'sha256', self::NOW . '.' . $mac ), 0, 40 ), ReplayGuard::GROUP );
	}

	public function test_the_relay_refuses_a_replay_however_it_is_spelled(): void {
		add_filter(
			'wp_mail_from',
			static function () {
				return 'wordpress@example.org';
			}
		);
		reset_phpmailer_instance();
		Settings::update(
			array(
				'public_url'  => 'https://static.example.org',
				'forms'       => 'receiver',
				'forms_email' => 'forms@example.org',
			)
		);
		Secrets::set( 'forms', 'secret', 'shared-secret' );
		PublishedForms::publish( array( 'form:contact' ) );
		$fields        = array(
			array( 'glaciate_form', 'form:contact' ),
			array( 'glaciate_page', 'https://static.example.org/contact/' ),
			array( 'glaciate_hp', '' ),
			array( 'glaciate_t', '12.5' ),
			array( 'name', 'Ada' ),
			array( 'email', 'ada@example.com' ),
			array( 'message', 'Hello' ),
		);
		$body          = Receiver::payload( 'form:contact', 'https://static.example.org/contact/', $fields, '192.0.2.40', 'UA' );
		$config        = new Config();
		$receiver      = new Receiver( $config, Adapters::defaults( $config ) );
		$receiver->now = self::NOW;
		$signature     = Signature::sign( $body, 'shared-secret', self::NOW );

		$result = $receiver->relay( $body, $signature );
		$this->assertSame( Result::SENT, $result->status, $result->message );
		foreach ( array( $signature, $signature . ' ', ' ' . $signature, $signature . "\t", $signature . "\n", str_replace( 't=', 't=0', $signature ) ) as $replay ) {
			$this->assertSame( Result::SPAM, $receiver->relay( $body, $replay )->status, (string) wp_json_encode( $replay ) );
		}
		$this->assertCount( 1, self::markers() );
	}
}
