<?php
/**
 * The WordPress form receiver: checks, relay signatures, rate limit, captcha, webhook, and the marked-form and comment
 * adapters.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Forms;

use Glaciate\Forms\Adapters;
use Glaciate\Forms\Config;
use Glaciate\Forms\Receiver;
use Glaciate\Forms\Result;
use Glaciate\Forms\Signature;
use Glaciate\Security\Secrets;
use Glaciate\Storage\FormKeyRepository;
use Glaciate\Storage\UrlRepository;
use Glaciate\Support\Settings;
use Glaciate\Tests\Support\PublishedForms;
use WP_UnitTestCase;

require_once dirname( __DIR__, 2 ) . '/Support/PublishedForms.php';

final class ReceiverTest extends WP_UnitTestCase {

	const PUBLIC_URL = 'https://static.example.org';

	/** @var array<int,array<string,mixed>> */
	private $requests = array();

	/** @var array<string,mixed>|null */
	private $answer = null;

	/** @var int URL ID of the published page that carries the contact and comment forms. */
	private $page = 0;

	public function set_up(): void {
		parent::set_up();
		reset_phpmailer_instance();
		// The test site is "localhost", which PHPMailer refuses as a sender domain.
		add_filter(
			'wp_mail_from',
			static function () {
				return 'wordpress@example.org';
			}
		);
		Settings::update(
			array(
				'public_url'  => self::PUBLIC_URL,
				'forms'       => 'site',
				'forms_email' => 'forms@example.org',
			)
		);
		$this->requests = array();
		$this->answer   = null;
		$this->page     = PublishedForms::publish( array( 'form:contact', 'comments' ) );
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ) );
		Secrets::delete( 'forms', 'secret' );
		Secrets::delete( 'forms', 'captcha_secret' );
		parent::tear_down();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return $this->answer ?? array(
			'headers'  => array(),
			'body'     => '{"success":true}',
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	private function receiver(): Receiver {
		$config = new Config();
		return new Receiver( $config, Adapters::defaults( $config ) );
	}

	private static function contact( array $extra = array() ): array {
		return array_merge(
			array(
				array( 'glaciate_form', 'form:contact' ),
				array( 'glaciate_page', self::PUBLIC_URL . '/contact/' ),
				array( 'glaciate_hp', '' ),
				array( 'glaciate_t', '12.5' ),
				array( 'name', 'Ada' ),
				array( 'email', 'ada@example.com' ),
				array( 'message', "Hello\nthere" ),
			),
			$extra
		);
	}

	public function test_marked_form_is_emailed(): void {
		$result = $this->receiver()->direct( self::contact(), '203.0.113.5', 'Mozilla/5.0', self::PUBLIC_URL );
		$this->assertSame( Result::SENT, $result->status, $result->message );
		$mail = tests_retrieve_phpmailer_instance()->get_sent();
		$this->assertSame( 'forms@example.org', $mail->to[0][0] );
		$this->assertStringContainsString( 'contact form', $mail->subject );
		// PHPMailer's line endings differ between versions.
		$this->assertStringContainsString( "message: Hello\nthere", str_replace( "\r\n", "\n", $mail->body ) );
		$this->assertStringContainsString( 'name: Ada', $mail->body );
		$this->assertStringNotContainsString( 'glaciate_', $mail->body );
		$this->assertStringContainsString( 'Reply-To: ada@example.com', $mail->header );
	}

	public function test_refusals(): void {
		$cases = array(
			'other origin' => array( self::contact(), 'https://evil.example' ),
			'honeypot'     => array( self::contact( array( array( 'glaciate_hp', 'x' ) ) ), '' ),
			'too fast'     => array( array_merge( array( array( 'glaciate_t', '0.5' ) ), self::contact() ), '' ),
			'no form key'  => array( array( array( 'message', 'hi' ) ), '' ),
		);
		// The honeypot added at the end doesn't count (first value wins), so put it first.
		$cases['honeypot'][0] = array_merge( array( array( 'glaciate_hp', 'x' ) ), self::contact() );
		foreach ( $cases as $name => $case ) {
			$result = $this->receiver()->direct( $case[0], '203.0.113.6', 'UA', $case[1] );
			$this->assertSame( Result::SPAM, $result->status, $name );
		}
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'nothing was sent' );

		$unknown = $this->receiver()->direct( array( array( 'glaciate_form', 'nosuch:1' ) ), '203.0.113.6', 'UA', '' );
		$this->assertSame( Result::ERROR, $unknown->status );
	}

	public function test_forms_no_published_page_carries_are_refused(): void {
		$other = array_merge( array( array( 'glaciate_form', 'form:other' ) ), self::contact() );
		$this->assertSame( Result::ERROR, $this->receiver()->direct( $other, '203.0.113.20', 'UA', self::PUBLIC_URL )->status, 'never published' );
		$wpforms = array_merge( array( array( 'glaciate_form', 'wpforms:7' ) ), self::contact() );
		$this->assertSame( Result::ERROR, $this->receiver()->direct( $wpforms, '203.0.113.20', 'UA', '' )->status, 'a known kind' );

		// Forwarded submissions too: a separate receiver passes on any well-formed key.
		Settings::update( array( 'forms' => 'receiver' ) );
		Secrets::set( 'forms', 'secret', 'shared-secret' );
		$body = Receiver::payload( 'form:other', '', $other, '192.0.2.20', 'UA' );
		$this->assertSame( Result::ERROR, $this->receiver()->relay( $body, Signature::sign( $body, 'shared-secret', time() ) )->status, 'relayed' );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'nothing was sent' );
	}

	public function test_a_form_is_refused_once_its_last_page_leaves_the_site(): void {
		$this->assertTrue( $this->receiver()->direct( self::contact(), '203.0.113.21', 'UA', '' )->ok() );
		( new UrlRepository() )->unpublish( $this->page );
		$this->assertSame( Result::ERROR, $this->receiver()->direct( self::contact(), '203.0.113.21', 'UA', '' )->status );
	}

	public function test_every_form_is_accepted_until_a_full_build_has_recorded_them(): void {
		delete_option( FormKeyRepository::READY_OPTION );
		$other = array_merge( array( array( 'glaciate_form', 'form:other' ) ), self::contact() );
		$this->assertTrue( $this->receiver()->direct( $other, '203.0.113.22', 'UA', '' )->ok() );
	}

	public function test_direct_submissions_need_site_mode(): void {
		Settings::update( array( 'forms' => 'receiver' ) );
		$this->assertSame( Result::ERROR, $this->receiver()->direct( self::contact(), '203.0.113.5', 'UA', '' )->status );
	}

	public function test_rate_limit_per_address(): void {
		for ( $i = 0; $i < Receiver::RATE_LIMIT; $i++ ) {
			$this->assertTrue( $this->receiver()->direct( self::contact(), '198.51.100.7', 'UA', '' )->ok() );
		}
		$limited = $this->receiver()->direct( self::contact(), '198.51.100.7', 'UA', '' );
		$this->assertSame( Result::LIMITED, $limited->status );
		$this->assertSame( 429, $limited->http_status() );
		$this->assertTrue( $this->receiver()->direct( self::contact(), '198.51.100.8', 'UA', '' )->ok(), 'other address' );
	}

	public function test_ipv6_addresses_share_a_limit_per_64(): void {
		for ( $i = 1; $i <= Receiver::RATE_LIMIT; $i++ ) {
			$this->assertTrue( $this->receiver()->direct( self::contact(), '2001:db8:7:1::' . dechex( $i ), 'UA', '' )->ok() );
		}
		$this->assertSame( Result::LIMITED, $this->receiver()->direct( self::contact(), '2001:db8:7:1:ffff::99', 'UA', '' )->status, 'same /64' );
		$this->assertTrue( $this->receiver()->direct( self::contact(), '2001:db8:7:2::1', 'UA', '' )->ok(), 'other /64' );
	}

	public function test_refused_requests_count_as_attempts(): void {
		$attempts = Receiver::RATE_LIMIT * Receiver::ATTEMPT_FACTOR;
		$honeypot = array_merge( array( array( 'glaciate_hp', 'x' ) ), self::contact() );
		for ( $i = 0; $i < $attempts; $i++ ) {
			$this->assertSame( Result::SPAM, $this->receiver()->direct( $honeypot, '198.51.100.30', 'UA', '' )->status );
		}
		$this->assertSame( Result::LIMITED, $this->receiver()->direct( self::contact(), '198.51.100.30', 'UA', '' )->status );
		$this->assertFalse( tests_retrieve_phpmailer_instance()->get_sent(), 'nothing was sent' );
	}

	public function test_attempts_are_limited_before_the_captcha_is_checked(): void {
		Settings::update(
			array(
				'forms_captcha'          => 'turnstile',
				'forms_captcha_site_key' => 'site-key-1',
			)
		);
		Secrets::set( 'forms', 'captcha_secret', 'captcha-secret' );
		$this->answer = array(
			'headers'  => array(),
			'body'     => '{"success":false}',
			'response' => array( 'code' => 200 ),
		);
		$attempts     = Receiver::RATE_LIMIT * Receiver::ATTEMPT_FACTOR;
		$pairs        = self::contact( array( array( 'cf-turnstile-response', 'tok' ) ) );
		for ( $i = 0; $i < $attempts; $i++ ) {
			$this->assertSame( Result::SPAM, $this->receiver()->direct( $pairs, '198.51.100.31', 'UA', '' )->status );
		}
		$this->assertCount( $attempts, $this->requests );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertSame( Result::LIMITED, $this->receiver()->direct( $pairs, '198.51.100.31', 'UA', '' )->status );
		}
		$this->assertCount( $attempts, $this->requests, 'no captcha request once the attempts are used up' );
	}

	public function test_the_rate_limit_can_be_turned_off(): void {
		add_filter(
			'glaciate_form_rate_limit',
			static function () {
				return array( 0, 600 );
			}
		);
		for ( $i = 0; $i <= Receiver::RATE_LIMIT; $i++ ) {
			$this->assertTrue( $this->receiver()->direct( self::contact(), '198.51.100.32', 'UA', '' )->ok() );
		}
		remove_all_filters( 'glaciate_form_rate_limit' );
	}

	public function test_relay_applies_the_same_limits(): void {
		Settings::update( array( 'forms' => 'receiver' ) );
		Secrets::set( 'forms', 'secret', 'shared-secret' );
		$receiver = $this->receiver();
		$relay    = static function ( int $n, string $ip ) use ( $receiver ): Result {
			$body = Receiver::payload( 'form:contact', '', self::contact( array( array( 'n', (string) $n ) ) ), $ip, 'UA' );
			return $receiver->relay( $body, Signature::sign( $body, 'shared-secret', time() ) );
		};
		for ( $i = 0; $i < Receiver::RATE_LIMIT; $i++ ) {
			$this->assertTrue( $relay( $i, '2001:db8:9:9::' . ( $i + 1 ) )->ok() );
		}
		$this->assertSame( Result::LIMITED, $relay( 99, '2001:db8:9:9::abc' )->status, 'per /64, as for direct submissions' );
		$this->assertTrue( $relay( 100, '192.0.2.40' )->ok(), 'other address' );
	}

	public function test_captcha_is_verified_with_the_provider(): void {
		Settings::update(
			array(
				'forms_captcha'          => 'turnstile',
				'forms_captcha_site_key' => 'site-key-1',
			)
		);
		Secrets::set( 'forms', 'captcha_secret', 'captcha-secret' );

		$missing = $this->receiver()->direct( self::contact(), '203.0.113.9', 'UA', '' );
		$this->assertSame( Result::SPAM, $missing->status );
		$this->assertCount( 0, $this->requests, 'no token, no request' );

		$ok = $this->receiver()->direct( self::contact( array( array( 'cf-turnstile-response', 'tok' ) ) ), '203.0.113.9', 'UA', '' );
		$this->assertTrue( $ok->ok() );
		$this->assertSame( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', $this->requests[0]['url'] );
		$this->assertSame(
			array(
				'secret'   => 'captcha-secret',
				'response' => 'tok',
				'remoteip' => '203.0.113.9',
			),
			$this->requests[0]['args']['body']
		);

		$this->answer = array(
			'headers'  => array(),
			'body'     => '{"success":false}',
			'response' => array( 'code' => 200 ),
		);
		$this->assertSame( Result::SPAM, $this->receiver()->direct( self::contact( array( array( 'cf-turnstile-response', 'tok' ) ) ), '203.0.113.9', 'UA', '' )->status );
	}

	public function test_relay_accepts_signed_submissions_once(): void {
		Settings::update( array( 'forms' => 'receiver' ) );
		Secrets::set( 'forms', 'secret', 'shared-secret' );
		$body = Receiver::payload( 'form:contact', self::PUBLIC_URL . '/contact/', self::contact(), '192.0.2.4', 'UA' );

		$receiver      = $this->receiver();
		$receiver->now = 2000000000;
		$this->assertSame( Result::SPAM, $receiver->relay( $body, Signature::sign( $body, 'wrong', 2000000000 ) )->status, 'wrong secret' );
		$this->assertSame( Result::SPAM, $receiver->relay( $body, Signature::sign( $body, 'shared-secret', 2000000000 - 301 ) )->status, 'expired' );

		$signature = Signature::sign( $body, 'shared-secret', 2000000000 );
		$result    = $receiver->relay( $body, $signature );
		$this->assertTrue( $result->ok(), $result->message );
		$this->assertSame( Result::SPAM, $receiver->relay( $body, $signature )->status, 'replayed' );

		$honeypot = Receiver::payload( 'form:contact', '', array_merge( array( array( 'glaciate_hp', 'x' ) ), self::contact() ), '192.0.2.4', 'UA' );
		$this->assertSame( Result::SPAM, $receiver->relay( $honeypot, Signature::sign( $honeypot, 'shared-secret', 2000000000 ) )->status, 'checked again' );
	}

	public function test_relay_without_a_secret_refuses_everything(): void {
		$body = Receiver::payload( 'form:contact', '', self::contact(), '', '' );
		$this->assertSame( Result::SPAM, $this->receiver()->relay( $body, Signature::sign( $body, '', time() ) )->status );
	}

	public function test_webhook_gets_a_signed_copy(): void {
		Settings::update( array( 'forms_webhook' => 'https://hooks.example.net/in' ) );
		Secrets::set( 'forms', 'secret', 'shared-secret' );
		$fired = 0;
		add_action(
			'glaciate_form_submitted',
			static function () use ( &$fired ) {
				++$fired;
			}
		);
		$this->assertTrue( $this->receiver()->direct( self::contact(), '203.0.113.10', 'UA', '' )->ok() );
		$this->assertSame( 1, $fired );
		$this->assertCount( 1, $this->requests );
		$request = $this->requests[0];
		$this->assertSame( 'https://hooks.example.net/in', $request['url'] );
		$this->assertTrue( Signature::verify( $request['args']['headers'][ Signature::HEADER ], $request['args']['body'], 'shared-secret', time() ) );
		$payload = json_decode( $request['args']['body'], true );
		$this->assertSame( 'form:contact', $payload['form'] );
		$this->assertContains( array( 'name', 'Ada' ), $payload['fields'] );
		$this->assertNotContains( array( 'glaciate_hp', '' ), $payload['fields'] );
	}

	public function test_comments_are_added_anonymously(): void {
		$post  = self::factory()->post->create( array( 'comment_status' => 'open' ) );
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin );
		update_option( 'comment_moderation', 1 );
		$pairs  = array(
			array( 'glaciate_form', 'comments' ),
			array( 'comment_post_ID', (string) $post ),
			array( 'comment_parent', '0' ),
			array( 'author', 'Grace' ),
			array( 'email', 'grace@example.com' ),
			array( 'comment', 'A static comment: it\'s in C:\new\site.' ),
		);
		$result = $this->receiver()->direct( $pairs, '203.0.113.11', 'Mozilla/5.0', '' );
		$this->assertTrue( $result->ok(), $result->message );
		$this->assertStringContainsString( 'moderation', $result->message );
		$comments = get_comments( array( 'post_id' => $post ) );
		$this->assertCount( 1, $comments );
		$this->assertSame( '0', $comments[0]->user_id );
		$this->assertSame( '203.0.113.11', $comments[0]->comment_author_IP );
		$this->assertSame( '0', $comments[0]->comment_approved );
		$this->assertSame( 'A static comment: it\'s in C:\new\site.', $comments[0]->comment_content, 'quotes and backslashes are stored as typed' );
		$this->assertSame( $admin, get_current_user_id(), 'the current user is restored' );

		$missing = $this->receiver()->direct( array( array( 'glaciate_form', 'comments' ), array( 'comment_post_ID', (string) $post ), array( 'comment', 'x' ) ), '203.0.113.12', 'UA', '' );
		$this->assertSame( Result::INVALID, $missing->status );
		$this->assertArrayHasKey( 'author', $missing->errors );

		$closed = self::factory()->post->create( array( 'comment_status' => 'closed' ) );
		$this->assertFalse( $this->receiver()->direct( array( array( 'glaciate_form', 'comments' ), array( 'comment_post_ID', (string) $closed ), array( 'author', 'a' ), array( 'email', 'a@example.com' ), array( 'comment', 'x' ) ), '203.0.113.13', 'UA', '' )->ok() );
	}

	public function test_result_messages_are_text(): void {
		$result = Result::sent( '<p>Thanks for <b>writing</b> &amp; waiting.</p>' );
		$this->assertSame( 'Thanks for writing & waiting.', $result->message );
		$this->assertSame( Result::default_message( Result::ERROR ), Result::error( '  ' )->message );
		$this->assertNull( Result::from_array( array( 'ok' => true ) ) );
		$this->assertSame(
			'',
			Result::from_array(
				array(
					'status'   => 'sent',
					'redirect' => 'javascript:alert(1)',
				)
			)->redirect
		);
	}
}
