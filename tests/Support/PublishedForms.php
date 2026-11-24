<?php
/**
 * Publishes a page carrying forms, for receiver tests.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Support;

use Glaciate\Storage\FormKeyRepository;
use Glaciate\Storage\UrlRepository;

/**
 * The receiver accepts only keys of forms on published pages (D57). This records a published page as a build would,
 * with the keys of the forms on it, and marks the record complete.
 */
final class PublishedForms {

	/**
	 * Records a published page that carries the given forms.
	 *
	 * @param string[] $keys Form keys.
	 * @param string   $path Page path, ending in a slash.
	 * @return int URL ID.
	 */
	public static function publish( array $keys, string $path = '/contact/' ): int {
		$urls = new UrlRepository();
		$id   = $urls->ensure( home_url( $path ) );
		$urls->update(
			$id,
			array(
				'kind'         => 'page',
				'status_code'  => 200,
				'content_type' => 'text/html; charset=UTF-8',
				'content_hash' => str_repeat( 'f', 64 ),
				'path'         => ltrim( $path, '/' ) . 'index.html',
			)
		);
		( new FormKeyRepository() )->replace( $id, $keys );
		FormKeyRepository::mark_ready();
		return $id;
	}
}
