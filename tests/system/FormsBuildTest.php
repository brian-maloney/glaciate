<?php
/**
 * Forms in real builds: rewritten after URL rewriting, stable from build to build, and gone when forms are off.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\System;

use Glaciate\Forms\FormMarkup;
use Glaciate\Support\Settings;

final class FormsBuildTest extends SystemTestCase {

	public function test_forms_post_to_the_receiver_on_the_origin(): void {
		wp_set_current_user( 1 );
		wp_insert_post(
			array(
				'post_title'     => 'Open for comments',
				'post_name'      => 'open-for-comments',
				'post_status'    => 'publish',
				'comment_status' => 'open',
				'post_content'   => '<!-- wp:paragraph --><p>Say something.</p><!-- /wp:paragraph -->',
			)
		);
		wp_insert_post(
			array(
				'post_title'   => 'Write to us',
				'post_name'    => 'write-to-us',
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_content' => '<!-- wp:html --><form data-glaciate-form="contact" action="/thanks/"><label>Email <input type="email" name="email"></label><button>Send</button></form><!-- /wp:html -->',
			)
		);
		wp_set_current_user( 0 );
		Settings::update( array( 'forms' => 'site' ) );
		$this->build( 'full' );

		$endpoint = rest_url( 'glaciate/v1/forms/submit' );
		$post     = (string) $this->page( '/open-for-comments/' );
		$forms    = FormMarkup::scan( $post );
		$comments = array_values(
			array_filter(
				$forms,
				static function ( $form ) {
					return 'comments' === ( $form['attrs']['data-glaciate-form'] ?? '' );
				}
			)
		);
		$this->assertCount( 1, $comments, 'The comment form is rewritten.' );
		$this->assertSame( $endpoint, $comments[0]['attrs']['action'], 'The receiver keeps its origin address.' );
		$this->assertSame( self::PUBLIC_URL . '/open-for-comments/', $comments[0]['hidden']['glaciate_page'] );
		$this->assertStringContainsString( 'window.glaciateFormSettings', $post );
		$this->assertFileExists( $this->target . '/wp-content/plugins/glaciate/assets/js/forms.js' );

		$page  = (string) $this->page( '/write-to-us/' );
		$forms = FormMarkup::scan( $page );
		$this->assertSame( 'form:contact', $forms[0]['hidden']['glaciate_form'] ?? null );
		$this->assertSame( $endpoint, $forms[0]['attrs']['action'] );

		// Nothing in the rewritten forms changes between builds.
		$before = $this->snapshot();
		$this->build( 'full' );
		$this->assertSame( $before['open-for-comments/index.html'], $this->snapshot()['open-for-comments/index.html'] );
		$this->assertSame( $before['write-to-us/index.html'], $this->snapshot()['write-to-us/index.html'] );

		Settings::update( array( 'forms' => 'off' ) );
		$this->build( 'full' );
		$this->assertStringNotContainsString( 'data-glaciate-form="comments"', (string) $this->page( '/open-for-comments/' ) );
		$this->assertStringNotContainsString( 'glaciateFormSettings', (string) $this->page( '/open-for-comments/' ) );
	}
}
