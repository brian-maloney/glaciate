<?php
/**
 * Keys of the forms on published pages.
 *
 * @package Glaciate
 */

namespace Glaciate\Storage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table; the receiver must always read it fresh.

/**
 * Rows in the form_keys table: which form keys (such as "cf7:12" or "form:contact") each page carried when it was last
 * written. The form receiver takes submissions only for keys a published page carries (D57).
 *
 * A key counts while at least one published page or 404 page carries it. Reads go through the urls table, so a key
 * disappears as soon as the last such page is unpublished, redirected or replaced, whichever code path did it, without
 * each of them having to remember this table. The rows of an address that left the site stay until it is written again.
 */
final class FormKeyRepository {

	/**
	 * Option set once the table describes every published page: on a fresh install, or after the first full build
	 * following the upgrade that added it. Until then every well-formed key is accepted, as before.
	 */
	const READY_OPTION = 'glaciate_form_keys_ready';

	/** Row kinds whose forms count. */
	const KINDS = array( 'page', '404' );

	/**
	 * Records the keys of the forms a page now carries, replacing what it carried before.
	 *
	 * @param int      $url_id URL ID.
	 * @param string[] $keys   Form keys; empty when the page has no rewritten form.
	 * @return void
	 */
	public function replace( int $url_id, array $keys ): void {
		global $wpdb;
		$table = Schema::table( 'form_keys' );
		$keys  = array_values( array_unique( array_map( 'strval', $keys ) ) );
		sort( $keys );
		$old = array_map( 'strval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT form_key FROM %i WHERE url_id = %d ORDER BY form_key', $table, $url_id ) ) );
		sort( $old );
		// The usual case: a page rebuilt with the same forms costs one read.
		if ( $old === $keys ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE url_id = %d', $table, $url_id ) );
		if ( ! $keys ) {
			return;
		}
		$rows   = array();
		$values = array( $table );
		foreach ( $keys as $key ) {
			$rows[] = '(%d, %s)';
			array_push( $values, $url_id, $key );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
		$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (url_id, form_key) VALUES ' . implode( ', ', $rows ), $values ) );
	}

	/**
	 * Forgets every recorded key (a full build with forms off rewrote no form).
	 *
	 * @return void
	 */
	public function clear(): void {
		global $wpdb;
		// DELETE rather than TRUNCATE, which commits any open transaction (the test suite runs in one).
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( 'form_keys' ) ) );
	}

	/**
	 * May the receiver take submissions for this key? True when a published page carries it, and for every key until
	 * the table is ready (see READY_OPTION).
	 *
	 * @param string $key Form key.
	 * @return bool
	 */
	public function is_published( string $key ): bool {
		if ( ! self::ready() ) {
			return true;
		}
		if ( '' === $key ) {
			return false;
		}
		global $wpdb;
		return null !== $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i f JOIN %i u ON u.id = f.url_id WHERE f.form_key = %s AND u.content_hash IS NOT NULL AND u.kind IN (%s, %s) LIMIT 1',
				Schema::table( 'form_keys' ),
				Schema::table( 'urls' ),
				$key,
				self::KINDS[0],
				self::KINDS[1]
			)
		);
	}

	/**
	 * Keys carried by published pages, sorted.
	 *
	 * @return string[]
	 */
	public function published(): array {
		global $wpdb;
		return array_map(
			'strval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					'SELECT DISTINCT f.form_key FROM %i f JOIN %i u ON u.id = f.url_id WHERE u.content_hash IS NOT NULL AND u.kind IN (%s, %s) ORDER BY f.form_key',
					Schema::table( 'form_keys' ),
					Schema::table( 'urls' ),
					self::KINDS[0],
					self::KINDS[1]
				)
			)
		);
	}

	/**
	 * Does the table describe every published page?
	 *
	 * @return bool
	 */
	public static function ready(): bool {
		return (bool) get_option( self::READY_OPTION );
	}

	/**
	 * Notes that the table describes every published page.
	 *
	 * @return void
	 */
	public static function mark_ready(): void {
		if ( ! self::ready() ) {
			update_option( self::READY_OPTION, 1, false );
		}
	}
}
