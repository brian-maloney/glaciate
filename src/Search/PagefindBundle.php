<?php
/**
 * Where a Pagefind bundle lives, and removal of one Stillpress published itself.
 *
 * @package Stillpress
 */

namespace Stillpress\Search;

use Stillpress\Build\Pipeline;
use Stillpress\Storage\Schema;
use Stillpress\Storage\UrlRepository;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.

/**
 * In the "pagefind_host" search mode the host's build step writes the bundle into pagefind/ (D21); Stillpress
 * publishes nothing there. Up to 1.1.0 a "pagefind" mode ran the binary on this server and published the bundle as
 * generated files; that mode is gone (D39), so every build removes any such files that are still published.
 */
final class PagefindBundle {

	/** Output folder. */
	const DIR = 'pagefind';

	/** Pseudo-URL prefix of the generated rows the removed "pagefind" mode wrote. */
	const PSEUDO = 'stillpress:pagefind/';

	/**
	 * Components.
	 *
	 * @var Pipeline
	 */
	private $pipeline;

	/**
	 * URLs.
	 *
	 * @var UrlRepository
	 */
	private $urls;

	/**
	 * Constructor.
	 *
	 * @param Pipeline      $pipeline Components.
	 * @param UrlRepository $urls     URLs.
	 */
	public function __construct( Pipeline $pipeline, UrlRepository $urls ) {
		$this->pipeline = $pipeline;
		$this->urls     = $urls;
	}

	/**
	 * Removes a bundle published by an earlier version, so the next deploy deletes it from the target.
	 *
	 * @return void
	 */
	public function remove_published(): void {
		foreach ( $this->published_rows() as $row ) {
			$this->pipeline->cache->delete( (string) $row['path'] );
			$this->urls->unpublish( (int) $row['id'] );
		}
	}

	/**
	 * Published Pagefind rows.
	 *
	 * @return array<int,array{id:string,url:string,path:string}>
	 */
	private function published_rows(): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT id, url, path FROM %i WHERE kind = 'generated' AND url LIKE %s AND content_hash IS NOT NULL ORDER BY id", Schema::table( 'urls' ), $wpdb->esc_like( self::PSEUDO ) . '%' ),
			ARRAY_A
		);
	}
}
