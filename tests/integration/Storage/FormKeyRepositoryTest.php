<?php
/**
 * The record of forms on published pages.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Storage;

use Glaciate\Storage\FormKeyRepository;
use Glaciate\Storage\UrlRepository;
use Glaciate\Tests\Support\PublishedForms;

require_once dirname( __DIR__, 2 ) . '/Support/PublishedForms.php';

final class FormKeyRepositoryTest extends \WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var FormKeyRepository
	 */
	private $keys;

	public function set_up(): void {
		parent::set_up();
		$this->keys = new FormKeyRepository();
		$this->keys->clear();
		FormKeyRepository::mark_ready();
	}

	public function test_a_key_counts_while_a_published_page_carries_it(): void {
		$contact = PublishedForms::publish( array( 'form:contact', 'cf7:12' ), '/contact/' );
		$about   = PublishedForms::publish( array( 'form:contact' ), '/about/' );

		$this->assertTrue( $this->keys->is_published( 'form:contact' ) );
		$this->assertTrue( $this->keys->is_published( 'cf7:12' ) );
		$this->assertFalse( $this->keys->is_published( 'form:other' ) );
		$this->assertFalse( $this->keys->is_published( 'cf7:13' ) );
		$this->assertFalse( $this->keys->is_published( 'Form:contact' ), 'Keys compare byte for byte.' );
		$this->assertFalse( $this->keys->is_published( '' ) );
		$this->assertSame( array( 'cf7:12', 'form:contact' ), $this->keys->published() );

		( new UrlRepository() )->unpublish( $contact );
		$this->assertFalse( $this->keys->is_published( 'cf7:12' ), 'Its only page left the site.' );
		$this->assertTrue( $this->keys->is_published( 'form:contact' ), 'Another page still carries it.' );

		( new UrlRepository() )->update( $about, array( 'kind' => 'redirect' ) );
		$this->assertFalse( $this->keys->is_published( 'form:contact' ), 'A redirect carries no form, even with a file.' );
		$this->assertSame( array(), $this->keys->published() );
	}

	public function test_a_rebuilt_page_replaces_its_keys(): void {
		$id = PublishedForms::publish( array( 'form:contact', 'comments' ) );
		$this->keys->replace( $id, array( 'comments', 'comments' ) );
		$this->assertSame( array( 'comments' ), $this->keys->published() );
		$this->keys->replace( $id, array() );
		$this->assertSame( array(), $this->keys->published() );
		$this->assertFalse( $this->keys->is_published( 'comments' ) );
	}

	public function test_a_404_page_counts(): void {
		$id = PublishedForms::publish( array( 'form:search-help' ), '/glaciate-404/' );
		( new UrlRepository() )->update( $id, array( 'kind' => '404' ) );
		$this->assertTrue( $this->keys->is_published( 'form:search-help' ) );
	}

	public function test_every_key_is_accepted_until_the_record_is_ready(): void {
		delete_option( FormKeyRepository::READY_OPTION );
		$this->assertFalse( FormKeyRepository::ready() );
		$this->assertTrue( $this->keys->is_published( 'form:anything' ) );
		FormKeyRepository::mark_ready();
		$this->assertFalse( $this->keys->is_published( 'form:anything' ) );
	}

	public function test_clear_forgets_everything(): void {
		PublishedForms::publish( array( 'form:contact' ) );
		$this->keys->clear();
		$this->assertFalse( $this->keys->is_published( 'form:contact' ) );
	}
}
