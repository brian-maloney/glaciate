<?php
/**
 * Records what a render request read from WordPress.
 *
 * @package Glaciate
 */

namespace Glaciate\Render;

defined( 'ABSPATH' ) || exit;

use Glaciate\Deps\DependencyStore;
use Glaciate\Deps\OptionRules;
use Glaciate\Deps\QuerySignature;
use Glaciate\Storage\BuildRepository;
use Glaciate\Storage\Schema;
use Glaciate\Support\Settings;

/**
 * Runs inside a loopback render request (identified by a valid render token) and records the posts, terms,
 * users, options and queries the page used. At shutdown it stores them as the URL's dependencies.
 *
 * Timing: the shutdown callback stores the dependencies, the render's mark and any exclusion reason in the URL's row.
 * Shutdown callbacks run before PHP ends the response, so the row is written by the time the crawler has the whole
 * page; the crawler then reads it back and compares the mark with the token it sent, which proves this render (not a
 * page cache) produced the response. Nothing is buffered and no response header is needed (D45).
 *
 * A render request also gets a cleaner page: no admin bar, no page caching, and no head links that only make
 * sense on a live WordPress site (RSD, shortlink, REST API discovery, oEmbed discovery, pingback).
 */
final class Recorder {

	/**
	 * Hooks the recorder watches, and what each tells it. For "post", "user" and "option" the object is the hook's
	 * second argument (an ID, a WP_Post or an option name); for the queries it is the query object.
	 *
	 * @var array<string,string>
	 */
	const WATCHED = array(
		'pre_option'                  => 'option',
		'posts_pre_query'             => 'post_query',
		'terms_pre_query'             => 'term_query',
		'users_pre_query'             => 'user_query',
		'comments_pre_query'          => 'comment_query',
		'the_title'                   => 'post',
		'post_link'                   => 'post',
		'page_link'                   => 'post',
		'post_type_link'              => 'post',
		'attachment_link'             => 'post',
		'wp_get_attachment_url'       => 'post',
		'get_post_metadata'           => 'post',
		'render_block_data'           => 'block',
		'get_term'                    => 'term',
		'get_object_terms'            => 'terms',
		'term_link'                   => 'term_link',
		'get_user_metadata'           => 'user',
		'author_link'                 => 'user',
		'get_the_author_display_name' => 'user',
		'get_feed_build_date'         => 'feed_date',
	);

	/**
	 * URL ID.
	 *
	 * @var int
	 */
	private $url_id;

	/**
	 * Recorded keys by type, as keys of nested arrays for de-duplication.
	 *
	 * @var array<string,array<string,bool>>
	 */
	private $deps = array();

	/**
	 * Post queries seen during the request (inspected at shutdown, when their results are known).
	 *
	 * @var \WP_Query[]
	 */
	private $queries = array();

	/**
	 * Term queries seen during the request.
	 *
	 * @var \WP_Term_Query[]
	 */
	private $term_queries = array();

	/**
	 * Mark of this render: RenderToken::mark() of the token the request carried.
	 *
	 * @var string
	 */
	private $mark;

	/**
	 * Active recorder, if this request is a render.
	 *
	 * @var Recorder|null
	 */
	private static $active = null;

	/**
	 * Starts recording when the current request carries a valid render token for an active build.
	 *
	 * @return void
	 */
	public static function maybe_start(): void {
		if ( null !== self::$active || empty( $_SERVER['HTTP_X_GLACIATE_RENDER'] ) ) {
			return;
		}
		$header = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_GLACIATE_RENDER'] ) );
		$claims = RenderToken::for_site()->verify( $header );
		if ( null === $claims ) {
			return;
		}
		if ( ! ( new BuildRepository() )->is_active( $claims['build_id'] ) ) {
			return;
		}
		self::$active = new self( $claims['url_id'], RenderToken::mark( $header ) );
		self::$active->start();
	}

	/**
	 * Returns the active recorder (for tests and extensions), or null.
	 *
	 * @return Recorder|null
	 */
	public static function active(): ?Recorder {
		return self::$active;
	}

	/**
	 * Constructor.
	 *
	 * @param int    $url_id URL ID.
	 * @param string $mark   RenderToken::mark() of the request's token, or "" when there is none (tests).
	 */
	public function __construct( int $url_id, string $mark = '' ) {
		$this->url_id = $url_id;
		$this->mark   = $mark;
	}

	/**
	 * Adds a dependency manually. Extensions can call this through the glaciate_record_dependency action.
	 *
	 * @param string     $type Dependency type (see DependencyStore).
	 * @param string|int $key  Key.
	 * @return void
	 */
	public function add( string $type, $key ): void {
		$key = (string) $key;
		if ( '' !== $key && '0' !== $key ) {
			$this->deps[ $type ][ $key ] = true;
		}
	}

	/**
	 * Registers hooks.
	 *
	 * @return void
	 */
	private function start(): void {
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Shared page-cache convention.
		}
		add_action( 'shutdown', array( $this, 'finish' ), 0 );

		$this->clean_output();
		$this->observe();

		add_action(
			'glaciate_record_dependency',
			function ( $type, $key ) {
				$this->add( (string) $type, $key );
			},
			10,
			2
		);
	}

	/**
	 * Removes live-site-only output from the page.
	 *
	 * @return void
	 */
	private function clean_output(): void {
		add_filter( 'show_admin_bar', '__return_false', PHP_INT_MAX );

		/**
		 * Filters whether render requests drop head links that only work on a live WordPress site.
		 *
		 * @since 0.1.0
		 *
		 * @param bool $clean Default true.
		 */
		if ( ! apply_filters( 'glaciate_clean_head', true ) ) {
			return;
		}
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
		$this->hide_unpublished_feeds( (string) Settings::get( 'feeds' ) );
		add_filter(
			'wp_headers',
			static function ( $headers ) {
				unset( $headers['X-Pingback'] );
				return $headers;
			}
		);
	}

	/**
	 * Drops head links to feeds the "feeds" setting leaves out, so pages don't link to addresses that 404.
	 *
	 * "main" publishes only the site feed, so comment feeds and the extra feeds of single posts and archives go;
	 * "none" also drops the site feed. Feed links a theme prints itself aren't affected.
	 *
	 * @param string $feeds none, main or all.
	 * @return void
	 */
	private function hide_unpublished_feeds( string $feeds ): void {
		if ( 'all' === $feeds ) {
			return;
		}
		if ( 'none' === $feeds ) {
			add_filter( 'feed_links_show_posts_feed', '__return_false', PHP_INT_MAX );
		}
		add_filter( 'feed_links_show_comments_feed', '__return_false', PHP_INT_MAX );
		foreach ( array( 'post_comments', 'post_type_archive', 'category', 'tag', 'tax', 'author', 'search' ) as $kind ) {
			add_filter( 'feed_links_extra_show_' . $kind . '_feed', '__return_false', PHP_INT_MAX );
		}
	}

	/**
	 * Starts observing reads. Public so tests can record without a render request.
	 *
	 * The recorder watches through WordPress's "all" hook, which runs before every filter and action and whose
	 * callbacks return nothing. It registers no filter of its own on any of the hooks in WATCHED, so it can't change
	 * a title, a link or any other value on the page; core and themes escape those where they print them (D48).
	 *
	 * @return void
	 */
	public function observe(): void {
		add_action( 'all', array( $this, 'watch' ) );

		add_action(
			'the_post',
			function ( $post ) {
				if ( $post instanceof \WP_Post ) {
					$this->add( 'post', $post->ID );
				}
			},
			PHP_INT_MAX
		);
	}

	/**
	 * "all" hook callback: notes which post, term, user, option or query a watched hook is about. Returns nothing.
	 *
	 * @param mixed $hook    Name of the hook that is about to run.
	 * @param mixed ...$args The hook's arguments: for a filter, the value first.
	 * @return void
	 */
	public function watch( $hook = '', ...$args ): void {
		if ( ! is_string( $hook ) || ! isset( self::WATCHED[ $hook ] ) ) {
			return;
		}
		$what = self::WATCHED[ $hook ];
		// The query hooks run through apply_filters_ref_array(), which hands over its arguments as one array.
		if ( 'query' === substr( $what, -5 ) && 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = array_values( $args[0] );
		}
		$value = $args[0] ?? null;
		$about = $args[1] ?? null;

		switch ( $what ) {
			case 'option':
				if ( is_string( $about ) && ! OptionRules::is_ignored( $about ) && ! OptionRules::is_global( $about ) ) {
					$this->add( 'option', $about );
				}
				break;
			case 'post_query':
				if ( $about instanceof \WP_Query ) {
					$this->queries[ spl_object_hash( $about ) ] = $about;
				}
				break;
			case 'term_query':
				if ( $about instanceof \WP_Term_Query ) {
					$this->term_queries[ spl_object_hash( $about ) ] = $about;
				}
				break;
			case 'user_query':
				$this->add( 'uq', '*' );
				break;
			case 'comment_query':
				$this->add( 'cq', '*' );
				break;
			case 'post':
				$this->add( 'post', $about instanceof \WP_Post ? $about->ID : self::id( $about ) );
				break;
			case 'block':
				if ( is_array( $value ) && isset( $value['attrs']['ref'] ) ) {
					$this->add( 'post', self::id( $value['attrs']['ref'] ) );
				}
				break;
			case 'term':
				if ( $value instanceof \WP_Term ) {
					$this->add( 'term', $value->term_id );
				}
				break;
			case 'terms':
				foreach ( is_array( $value ) ? $value : array() as $term ) {
					if ( $term instanceof \WP_Term ) {
						$this->add( 'term', $term->term_id );
					}
				}
				break;
			case 'term_link':
				if ( $about instanceof \WP_Term ) {
					$this->add( 'term', $about->term_id );
				}
				break;
			case 'user':
				$this->add( 'user', self::id( $about ) );
				break;
			case 'feed_date':
				// A feed with no posts takes its build date from get_lastpostmodified(), the newest change to any post
				// type, which reads the database directly. The condition is have_posts() without its side effects.
				global $wp_query;
				if ( ! $wp_query instanceof \WP_Query || $wp_query->current_post + 1 >= $wp_query->post_count ) {
					$this->add( 'lastmod', '*' );
				}
				break;
		}//end switch
	}

	/**
	 * An object ID from a hook argument; 0 (which add() ignores) for anything that isn't a number.
	 *
	 * @param mixed $value Hook argument.
	 * @return int
	 */
	private static function id( $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Shutdown: turns observed queries into dependencies and stores them with the render's mark and exclusion reason.
	 *
	 * @return void
	 */
	public function finish(): void {
		$sigs = array();
		foreach ( $this->queries as $query ) {
			foreach ( (array) $query->posts as $post ) {
				$this->add( 'post', $post instanceof \WP_Post ? $post->ID : (int) $post );
			}
			$sig  = QuerySignature::from_query_vars( (array) $query->query_vars, self::tax_part( $query ) );
			$hash = QuerySignature::hash( $sig );

			$sigs[ $hash ] = $sig;
			$this->add( 'query', $hash );
		}
		foreach ( $this->term_queries as $tq ) {
			foreach ( (array) $tq->terms as $term ) {
				$this->add( 'term', $term instanceof \WP_Term ? $term->term_id : (int) $term );
			}
			// A post's own terms (get_the_terms) change only with that post's assignments, which are changes to the
			// post. Only term lists (category widgets, tag clouds) depend on every term in the taxonomy: on a large
			// site nearly every page shows its post's categories, so a "tq" dep there would rebuild every page.
			$objects = wp_parse_id_list( $tq->query_vars['object_ids'] ?? array() );
			if ( $objects ) {
				foreach ( $objects as $object_id ) {
					$this->add( 'post', $object_id );
				}
				continue;
			}
			foreach ( (array) ( $tq->query_vars['taxonomy'] ?? array() ) as $taxonomy ) {
				$this->add( 'tq', (string) $taxonomy );
			}
		}

		$deps = array();
		foreach ( $this->deps as $type => $keys ) {
			$deps[ $type ] = array_keys( $keys );
		}

		/**
		 * Filters the dependencies recorded for a URL before they are stored.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string,string[]> $deps   Keys by type (post, term, user, option, query, tq, uq, cq, lastmod).
		 * @param int                    $url_id URL ID.
		 */
		$deps = (array) apply_filters( 'glaciate_dependencies_for_url', $deps, $this->url_id );

		$stored = ( new DependencyStore() )->replace( $this->url_id, $deps, $sigs );
		$this->save_state( $stored ? 'recorded' : 'unknown', self::exclusion_reason() );
	}

	/**
	 * Decides whether the page just rendered must stay out of the static site.
	 *
	 * @return string Short reason, or "" to publish.
	 */
	private static function exclusion_reason(): string {
		$reason = '';
		if ( did_action( 'wp' ) && is_singular() && post_password_required( get_queried_object_id() ) ) {
			$reason = 'password-protected';
		}

		/**
		 * Filters whether the page rendered in this request is left out of the static site.
		 *
		 * Return a short reason (letters, digits and dashes) to exclude it, or an empty string to publish it.
		 * Runs inside the render request, so conditional tags such as is_singular() work.
		 *
		 * @since 0.1.0
		 *
		 * @param string $reason Default "password-protected" for posts behind a password, otherwise "".
		 */
		$reason = (string) apply_filters( 'glaciate_exclude_render', $reason );
		return (string) preg_replace( '/[^a-z0-9\-]/', '', strtolower( $reason ) );
	}

	/**
	 * Stores the URL's dependency state, with the render's mark and exclusion reason for the crawler to read back.
	 *
	 * @param string $state   recorded or unknown.
	 * @param string $exclude Exclusion reason, or "" to publish.
	 * @return void
	 */
	private function save_state( string $state, string $exclude ): void {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Custom table.
			Schema::table( 'urls' ),
			array(
				'deps_state'     => $state,
				'render_mark'    => '' === $this->mark ? null : $this->mark,
				'render_exclude' => $exclude,
			),
			array( 'id' => $this->url_id )
		);
	}

	/**
	 * Extracts the tax clauses of a query as term_taxonomy_id sets.
	 *
	 * By the time a query has run, WP_Tax_Query has expanded child terms and converted clauses to
	 * term_taxonomy_id, so usually no lookups are needed.
	 *
	 * @param \WP_Query $query Query.
	 * @return array<string,mixed>
	 */
	private static function tax_part( \WP_Query $query ): array {
		if ( ! $query->tax_query instanceof \WP_Tax_Query || empty( $query->tax_query->queries ) ) {
			return array();
		}
		$clauses = array();
		foreach ( $query->tax_query->queries as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) {
				continue;
			}
			if ( ! isset( $clause['taxonomy'] ) ) {
				$clauses[] = array(
					'op' => 'NESTED',
					'tt' => null,
				);
				continue;
			}
			$clauses[] = array(
				'op' => strtoupper( (string) ( $clause['operator'] ?? 'IN' ) ),
				'tt' => self::term_taxonomy_ids( $clause ),
			);
		}
		return array(
			'rel'     => (string) ( $query->tax_query->relation ?? 'AND' ),
			'clauses' => $clauses,
		);
	}

	/**
	 * Resolves a tax clause's terms to term_taxonomy_ids.
	 *
	 * @param array<string,mixed> $clause Clause.
	 * @return int[]|null Null when the terms can't be resolved.
	 */
	private static function term_taxonomy_ids( array $clause ): ?array {
		$terms = (array) ( $clause['terms'] ?? array() );
		$field = (string) ( $clause['field'] ?? 'term_id' );
		if ( 'term_taxonomy_id' === $field ) {
			return array_values( array_map( 'intval', $terms ) );
		}
		$ids = array();
		foreach ( $terms as $value ) {
			$term = 'term_id' === $field ? get_term( (int) $value, (string) $clause['taxonomy'] ) : get_term_by( $field, (string) $value, (string) $clause['taxonomy'] );
			if ( ! $term instanceof \WP_Term ) {
				return null;
			}
			$ids[] = (int) $term->term_taxonomy_id;
		}
		return $ids;
	}
}
