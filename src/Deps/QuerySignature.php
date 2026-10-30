<?php
/**
 * Normalized descriptions of post queries, and conservative matching against changed posts.
 *
 * @package Stillpress
 */

namespace Stillpress\Deps;

/**
 * A query signature records what a post query asked for, so that when a post changes we can tell whether that
 * post could appear in (or disappear from) the query's results.
 *
 * Matching is deliberately conservative: when a constraint can't be evaluated (meta queries, search, complex
 * date queries, NOT IN clauses, nested tax queries), it is treated as matching. A false positive costs one extra
 * page render; a false negative is a stale page.
 *
 * Signature shape (all keys optional):
 *
 *     pt   string[]  post types; absent or ["any"] = any type
 *     st   string[]  statuses; absent = publish/inherit
 *     ids  int[]     post__in / p / page_id / attachment_id
 *     name string[]  name / pagename (last path segment)
 *     au   int[]     authors (author, author__in)
 *     par  int[]     post_parent, post_parent__in
 *     tax  array{rel:string, clauses: array<int, array{op:string, tt:int[]|null}>}
 *     date array{y?:int, m?:int, d?:int}|"complex"
 *     fix  1         the query's results and their order follow only from the fields in FIELDS and "ob"
 *     ob   string[]  snapshot fields the results are ordered by (with "fix" only)
 *
 * A snapshot describes a changed post as sets of every value it had before or after the change:
 *
 *     ids, types, statuses, names, authors, parents, tt (term_taxonomy_ids), dates ("Y-m-d"), datetimes,
 *     titles, orders (menu_order), modified, protected (0 or 1)
 *     moved: the fields whose value changed, when both states are known; absent means anything may have
 *
 * A post whose edit moved none of the fields a "fix" query selects or orders by stays exactly where it was in that
 * query's results, so pages showing those results don't change through the query. They still depend on the post
 * itself, which the recorder notes for every post a query returned.
 *
 * Pure: no WordPress calls.
 */
final class QuerySignature {

	/** Snapshot fields that decide whether a post is in a "fix" query's results, whatever it is ordered by. */
	const FIELDS = array( 'types', 'statuses', 'authors', 'parents', 'names', 'datetimes', 'tt', 'protected', 'meta' );

	/** Orderby values => the snapshot field they sort on (null: nothing that changes). */
	const ORDER_FIELDS = array(
		''                => 'datetimes',
		'date'            => 'datetimes',
		'post_date'       => 'datetimes',
		'modified'        => 'modified',
		'post_modified'   => 'modified',
		'title'           => 'titles',
		'post_title'      => 'titles',
		'name'            => 'names',
		'post_name'       => 'names',
		'author'          => 'authors',
		'post_author'     => 'authors',
		'parent'          => 'parents',
		'post_parent'     => 'parents',
		'type'            => 'types',
		'post_type'       => 'types',
		'menu_order'      => 'orders',
		'id'              => null,
		'none'            => null,
		'post__in'        => null,
		'post_name__in'   => null,
		'post_parent__in' => null,
	);

	/**
	 * Query vars whose values the signature (or FIELDS) accounts for, or that only affect paging and output. A query
	 * with any other non-empty var (search, meta, custom vars a plugin filters on) can't be marked "fix".
	 */
	const FIXED_VARS = array( 'error', 'm', 'p', 'post_parent', 'attachment', 'attachment_id', 'name', 'pagename', 'page_id', 'second', 'minute', 'hour', 'day', 'monthnum', 'year', 'w', 'category_name', 'tag', 'cat', 'tag_id', 'author', 'author_name', 'feed', 'tb', 'paged', 'page', 'cpage', 'fields', 'embed', 'category__in', 'category__not_in', 'category__and', 'post__in', 'post__not_in', 'post_name__in', 'tag__in', 'tag__not_in', 'tag__and', 'tag_slug__in', 'tag_slug__and', 'post_parent__in', 'post_parent__not_in', 'author__in', 'author__not_in', 'post_type', 'post_status', 'posts_per_page', 'posts_per_archive_page', 'numberposts', 'nopaging', 'offset', 'orderby', 'order', 'ignore_sticky_posts', 'suppress_filters', 'cache_results', 'update_post_term_cache', 'update_post_meta_cache', 'update_menu_item_cache', 'lazy_load_term_meta', 'comments_per_page', 'no_found_rows', 'taxonomy', 'term', 'tax_query', 'date_query', 'has_password' );

	/**
	 * Builds a signature from WP_Query query vars and the resolved tax clauses.
	 *
	 * @param array<string,mixed> $qv  WP_Query::$query_vars after parsing.
	 * @param array<string,mixed> $tax array{rel:string, clauses: array<int,array{op:string,tt:int[]|null}>}, or empty.
	 * @return array<string,mixed>
	 */
	public static function from_query_vars( array $qv, array $tax = array() ): array {
		$sig = array();

		$pt = $qv['post_type'] ?? '';
		if ( is_string( $pt ) ) {
			$pt = '' === $pt ? array() : array( $pt );
		}
		$pt = array_values( array_filter( array_map( 'strval', (array) $pt ) ) );
		if ( $pt && ! in_array( 'any', $pt, true ) ) {
			sort( $pt );
			$sig['pt'] = $pt;
		}

		$st = $qv['post_status'] ?? '';
		if ( is_string( $st ) ) {
			$st = '' === $st ? array() : preg_split( '/[\s,]+/', $st );
		}
		$st = array_values( array_filter( array_map( 'strval', (array) $st ) ) );
		if ( $st && ! in_array( 'any', $st, true ) ) {
			sort( $st );
			$sig['st'] = $st;
		}

		$ids = array();
		foreach ( array( 'p', 'page_id', 'attachment_id' ) as $k ) {
			if ( ! empty( $qv[ $k ] ) ) {
				$ids[] = (int) $qv[ $k ];
			}
		}
		if ( ! empty( $qv['post__in'] ) ) {
			$ids = array_merge( $ids, array_map( 'intval', (array) $qv['post__in'] ) );
		}
		if ( $ids ) {
			$sig['ids'] = self::int_set( $ids );
		}

		$names = array();
		foreach ( array( 'name', 'pagename', 'attachment' ) as $k ) {
			if ( ! empty( $qv[ $k ] ) && is_string( $qv[ $k ] ) ) {
				$parts   = explode( '/', trim( $qv[ $k ], '/' ) );
				$names[] = strtolower( (string) end( $parts ) );
			}
		}
		if ( ! empty( $qv['post_name__in'] ) ) {
			$names = array_merge( $names, array_map( 'strtolower', array_map( 'strval', (array) $qv['post_name__in'] ) ) );
		}
		if ( $names ) {
			$names = array_values( array_unique( $names ) );
			sort( $names );
			$sig['name'] = $names;
		}

		$authors = array();
		if ( isset( $qv['author'] ) && '' !== $qv['author'] ) {
			foreach ( preg_split( '/[\s,]+/', (string) $qv['author'] ) as $a ) {
				if ( (int) $a > 0 ) {
					$authors[] = (int) $a;
				}
			}
		}
		if ( ! empty( $qv['author__in'] ) ) {
			$authors = array_merge( $authors, array_map( 'intval', (array) $qv['author__in'] ) );
		}
		if ( $authors ) {
			$sig['au'] = self::int_set( $authors );
		}

		$parents = array();
		if ( isset( $qv['post_parent'] ) && '' !== $qv['post_parent'] && null !== $qv['post_parent'] ) {
			$parents[] = (int) $qv['post_parent'];
		}
		if ( ! empty( $qv['post_parent__in'] ) ) {
			$parents = array_merge( $parents, array_map( 'intval', (array) $qv['post_parent__in'] ) );
		}
		if ( $parents ) {
			$sig['par'] = self::int_set( $parents );
		}

		if ( ! empty( $tax['clauses'] ) ) {
			$sig['tax'] = array(
				'rel'     => 'OR' === strtoupper( (string) ( $tax['rel'] ?? 'AND' ) ) ? 'OR' : 'AND',
				'clauses' => array_values( $tax['clauses'] ),
			);
		}

		if ( ! empty( $qv['date_query'] ) || ! empty( $qv['w'] ) || ! empty( $qv['hour'] ) || ! empty( $qv['minute'] ) || ! empty( $qv['second'] ) ) {
			$sig['date'] = 'complex';
		} else {
			$date = array();
			$m    = isset( $qv['m'] ) ? preg_replace( '/[^0-9]/', '', (string) $qv['m'] ) : '';
			if ( strlen( $m ) >= 4 ) {
				$date['y'] = (int) substr( $m, 0, 4 );
			}
			if ( strlen( $m ) >= 6 ) {
				$date['m'] = (int) substr( $m, 4, 2 );
			}
			if ( strlen( $m ) >= 8 ) {
				$date['d'] = (int) substr( $m, 6, 2 );
			}
			if ( ! empty( $qv['year'] ) ) {
				$date['y'] = (int) $qv['year'];
			}
			if ( ! empty( $qv['monthnum'] ) ) {
				$date['m'] = (int) $qv['monthnum'];
			}
			if ( ! empty( $qv['day'] ) ) {
				$date['d'] = (int) $qv['day'];
			}
			if ( $date ) {
				$sig['date'] = $date;
			}
		}//end if

		$order = self::order_fields( $qv );
		if ( null !== $order ) {
			$sig['fix'] = 1;
			if ( $order ) {
				$sig['ob'] = $order;
			}
		}

		return $sig;
	}

	/**
	 * The snapshot fields a query is ordered by, or null when its results may depend on anything else.
	 *
	 * @param array<string,mixed> $qv Query vars.
	 * @return string[]|null
	 */
	private static function order_fields( array $qv ): ?array {
		foreach ( $qv as $key => $value ) {
			if ( null === $value || '' === $value || false === $value || 0 === $value || '0' === $value || array() === $value ) {
				continue;
			}
			if ( ! in_array( (string) $key, self::FIXED_VARS, true ) ) {
				return null;
			}
		}
		$orderby = $qv['orderby'] ?? '';
		if ( is_array( $orderby ) ) {
			$orderby = array_keys( $orderby );
		} else {
			$orderby = preg_split( '/[\s,]+/', trim( (string) $orderby ) );
		}
		$fields = array();
		foreach ( (array) $orderby as $name ) {
			$name = strtolower( (string) $name );
			if ( ! array_key_exists( $name, self::ORDER_FIELDS ) ) {
				return null;
			}
			if ( null !== self::ORDER_FIELDS[ $name ] ) {
				$fields[] = self::ORDER_FIELDS[ $name ];
			}
		}
		$fields = array_values( array_unique( $fields ) );
		sort( $fields );
		return $fields;
	}

	/**
	 * Stable hash of a signature.
	 *
	 * @param array<string,mixed> $sig Signature.
	 * @return string 40 hex characters.
	 */
	public static function hash( array $sig ): string {
		ksort( $sig );
		return sha1( (string) json_encode( $sig ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure class; stable encoding for hashing only.
	}

	/**
	 * Could a post described by the snapshot appear in the results of the signature's query?
	 *
	 * @param array<string,mixed>            $sig      Signature.
	 * @param array<string,array<int,mixed>> $snapshot Snapshot sets.
	 * @return bool
	 */
	public static function matches( array $sig, array $snapshot ): bool {
		if ( ! empty( $sig['fix'] ) && isset( $snapshot['moved'] ) && is_array( $snapshot['moved'] ) && ! array_intersect( array_merge( self::FIELDS, (array) ( $sig['ob'] ?? array() ) ), $snapshot['moved'] ) ) {
			return false;
		}
		if ( isset( $sig['ids'] ) && ! self::intersects( $sig['ids'], $snapshot['ids'] ?? array() ) ) {
			return false;
		}
		if ( isset( $sig['name'] ) && ! self::intersects( $sig['name'], array_map( 'strtolower', $snapshot['names'] ?? array() ) ) ) {
			return false;
		}
		if ( isset( $sig['pt'] ) && ! self::intersects( $sig['pt'], $snapshot['types'] ?? array() ) ) {
			return false;
		}
		$statuses = $sig['st'] ?? array( 'publish', 'inherit' );
		if ( ! self::intersects( $statuses, $snapshot['statuses'] ?? array() ) ) {
			return false;
		}
		if ( isset( $sig['au'] ) && ! self::intersects( $sig['au'], $snapshot['authors'] ?? array() ) ) {
			return false;
		}
		if ( isset( $sig['par'] ) && ! self::intersects( $sig['par'], $snapshot['parents'] ?? array() ) ) {
			return false;
		}
		if ( isset( $sig['tax'] ) && ! self::tax_matches( $sig['tax'], $snapshot['tt'] ?? array() ) ) {
			return false;
		}
		if ( isset( $sig['date'] ) && is_array( $sig['date'] ) && ! self::date_matches( $sig['date'], $snapshot['dates'] ?? array() ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Evaluates the tax part conservatively.
	 *
	 * @param array<string,mixed> $tax Tax part of a signature.
	 * @param int[]               $tt  Term taxonomy IDs the post had before or after the change.
	 * @return bool
	 */
	private static function tax_matches( array $tax, array $tt ): bool {
		$results = array();
		foreach ( $tax['clauses'] as $clause ) {
			$op = strtoupper( (string) ( $clause['op'] ?? 'IN' ) );
			if ( ! in_array( $op, array( 'IN', 'AND' ), true ) || ! isset( $clause['tt'] ) || null === $clause['tt'] ) {
				$results[] = true;
				// Can't evaluate: assume it matches.
				continue;
			}
			$results[] = self::intersects( $clause['tt'], $tt );
		}
		if ( ! $results ) {
			return true;
		}
		return 'OR' === $tax['rel'] ? in_array( true, $results, true ) : ! in_array( false, $results, true );
	}

	/**
	 * Compares simple year/month/day constraints.
	 *
	 * @param array<string,int> $date  Constraint.
	 * @param string[]          $dates Post dates "Y-m-d".
	 * @return bool
	 */
	private static function date_matches( array $date, array $dates ): bool {
		if ( ! $dates ) {
			return true;
		}
		foreach ( $dates as $ymd ) {
			$parts = array_map( 'intval', explode( '-', (string) $ymd ) );
			if ( isset( $date['y'] ) && ( $parts[0] ?? 0 ) !== $date['y'] ) {
				continue;
			}
			if ( isset( $date['m'] ) && ( $parts[1] ?? 0 ) !== $date['m'] ) {
				continue;
			}
			if ( isset( $date['d'] ) && ( $parts[2] ?? 0 ) !== $date['d'] ) {
				continue;
			}
			return true;
		}
		return false;
	}

	/**
	 * True when two lists share a value (loose comparison of scalars as strings).
	 *
	 * @param array<int,mixed> $a List.
	 * @param array<int,mixed> $b List.
	 * @return bool
	 */
	private static function intersects( array $a, array $b ): bool {
		if ( ! $a || ! $b ) {
			return false;
		}
		$set = array_flip( array_map( 'strval', $a ) );
		foreach ( $b as $v ) {
			if ( isset( $set[ (string) $v ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Sorted unique positive integers.
	 *
	 * @param array<int,mixed> $values Values.
	 * @return int[]
	 */
	private static function int_set( array $values ): array {
		$values = array_values( array_unique( array_filter( array_map( 'intval', $values ) ) ) );
		sort( $values );
		return $values;
	}
}
