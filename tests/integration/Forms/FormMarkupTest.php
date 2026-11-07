<?php
/**
 * Recognizing and rewriting forms in published pages.
 *
 * The Contact Form 7, WPForms and comment fixtures are those plugins' real output (CF7 6.2, WPForms Lite 2.0,
 * WordPress 7.1); the Gravity Forms fixture is a stand-in written from its documented markup.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Forms;

use Glaciate\Forms\Adapters;
use Glaciate\Forms\Config;
use Glaciate\Forms\FormMarkup;
use WP_UnitTestCase;

final class FormMarkupTest extends WP_UnitTestCase {

	const ENDPOINT = 'https://forms.example.net/submit';
	const PAGE     = 'https://static.example.org/contact/';

	private function markup(): FormMarkup {
		return new FormMarkup( Adapters::defaults( new Config( array( 'forms' => 'receiver' ) ) ), self::ENDPOINT );
	}

	private static function fixture( string $name ): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/fixtures/forms/' . $name . '.html' );
	}

	public function provide_fixtures(): array {
		return array(
			'contact form 7' => array( 'cf7', 'cf7:107' ),
			'wpforms'        => array( 'wpforms', 'wpforms:110' ),
			'gravity forms'  => array( 'gravityforms', 'gravityforms:2' ),
			'comments'       => array( 'comments', 'comments' ),
		);
	}

	/**
	 * @dataProvider provide_fixtures
	 */
	public function test_recognizes_and_rewrites( string $fixture, string $key ): void {
		$html   = '<main><p>Before</p>' . self::fixture( $fixture ) . '<p>After</p></main>';
		$result = $this->markup()->transform( $html, self::PAGE );
		$this->assertSame( array( $key ), $result['keys'] );

		$forms = FormMarkup::scan( $result['html'] );
		$this->assertCount( 1, $forms );
		$form = $forms[0];
		$this->assertSame( self::ENDPOINT, $form['attrs']['action'] );
		$this->assertSame( 'post', $form['attrs']['method'] );
		$this->assertSame( $key, $form['attrs']['data-glaciate-form'] );
		$this->assertSame( $key, $form['hidden']['glaciate_form'] );
		$this->assertSame( self::PAGE, $form['hidden']['glaciate_page'] );
		$this->assertSame( '', $form['hidden']['glaciate_t'] );
		$this->assertContains( 'glaciate_hp', $form['names'] );
		$this->assertStringNotContainsString( FormMarkup::SLOT, $result['html'] );
		$this->assertStringNotContainsString( 'target=', $result['html'] );
		$this->assertStringContainsString( '<p>After</p></main>', $result['html'] );

		// The plugin's own fields are untouched.
		foreach ( FormMarkup::scan( $html )[0]['names'] as $name ) {
			$this->assertContains( $name, $form['names'] );
		}
		// Rewriting again changes nothing: the output is stable from build to build.
		$this->assertSame( $result['html'], $this->markup()->transform( $html, self::PAGE )['html'] );
	}

	public function test_removes_wpforms_render_tokens(): void {
		$html = self::fixture( 'wpforms' );
		$this->assertStringContainsString( 'data-token=', $html );
		$out  = $this->markup()->transform( $html, self::PAGE )['html'];
		$form = FormMarkup::scan( $out )[0];
		$this->assertStringNotContainsString( 'data-token', $out );
		$this->assertSame( '', $form['hidden']['wpforms[time_token]'] );
		$this->assertSame( '110', $form['hidden']['wpforms[id]'] );
	}

	public function test_marked_forms_and_unknown_forms(): void {
		$html = '<form id="search" action="/"><input name="s"></form>'
			. '<form data-glaciate-form="newsletter" action="/subscribe" class="x"><input type="email" name="email"><button>Go</button></form>'
			. '<form data-glaciate-form="Bad Name!"><input name="q"></form>';
		$out  = $this->markup()->transform( $html, self::PAGE );
		$this->assertSame( array( 'form:newsletter' ), $out['keys'] );
		$forms = FormMarkup::scan( $out['html'] );
		$this->assertSame( '/', $forms[0]['attrs']['action'], 'search form untouched' );
		$this->assertArrayNotHasKey( 'glaciate_form', $forms[0]['hidden'] );
		$this->assertSame( self::ENDPOINT, $forms[1]['attrs']['action'] );
		$this->assertArrayNotHasKey( 'action', $forms[2]['attrs'] );
	}

	public function test_attribute_values_with_angle_brackets(): void {
		$html = '<form data-glaciate-form="x" title="a > b" data-note=\'1>0\'><input name="m"></form>';
		$out  = $this->markup()->transform( $html, self::PAGE )['html'];
		$this->assertMatchesRegularExpression( '#title="a > b" data-note=\'1>0\'[^>]*><input type="hidden" name="glaciate_form"#', $out );
	}

	public function test_page_without_forms_is_returned_as_is(): void {
		$html = '<p>No forms here.</p>';
		$this->assertSame(
			array(
				'html' => $html,
				'keys' => array(),
			),
			$this->markup()->transform( $html, self::PAGE )
		);
	}

	public function test_escapes_the_page_address(): void {
		$out = $this->markup()->transform( '<form data-glaciate-form="x"></form>', 'https://static.example.org/a"b/' )['html'];
		$this->assertStringContainsString( 'value="https://static.example.org/a&quot;b/"', $out );
	}
}
