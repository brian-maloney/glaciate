<?php
/**
 * Files the plugin generates rather than renders.
 *
 * @package Glaciate
 */

namespace Glaciate\Build;

defined( 'ABSPATH' ) || exit;

use Glaciate\Output\UnsafePathException;
use Glaciate\Rewrite\OriginSet;
use Glaciate\Storage\Schema;
use Glaciate\Storage\UrlRepository;
use Glaciate\Support\Logger;
use Glaciate\Support\Url;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Custom table.

/**
 * Writes the `_redirects` file (understood by several static hosts) from origin redirects and user-defined
 * redirects, plus a meta-refresh page for each user-defined redirect whose path is free, `_headers`, and
 * favicon.ico when WordPress answers it with a redirect.
 *
 * Generated files are stored as URL rows with a "glaciate:" pseudo-URL, so they flow through the same
 * manifest and deploy logic as everything else.
 */
final class Generated {

	/** Most rules Cloudflare reads from `_headers`. */
	const MAX_HEADER_RULES = 100;

	/** Pseudo-URL of favicon.ico when it is copied from the file WordPress redirects it to. */
	const FAVICON = 'glaciate:generated/favicon.ico';

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
	 * Build ID.
	 *
	 * @var int
	 */
	private $build_id;

	/**
	 * Constructor.
	 *
	 * @param Pipeline      $pipeline Components.
	 * @param UrlRepository $urls     URLs.
	 * @param int           $build_id Build ID.
	 */
	public function __construct( Pipeline $pipeline, UrlRepository $urls, int $build_id ) {
		$this->pipeline = $pipeline;
		$this->urls     = $urls;
		$this->build_id = $build_id;
	}

	/**
	 * Writes all generated files.
	 *
	 * @return void
	 */
	public function write(): void {
		$this->write_favicon();

		$rules = array();
		$pages = array();

		foreach ( (array) $this->pipeline->settings['redirects'] as $redirect ) {
			$from    = $this->pipeline->origins->public_path_for( (string) $redirect['from'] );
			$to      = '/' === $redirect['to'][0] ? $this->pipeline->origins->public_path_for( (string) $redirect['to'] ) : (string) $redirect['to'];
			$rules[] = array( $from, $to, (int) $redirect['code'] );
			$pages[] = $this->write_redirect_page( (string) $redirect['from'], $to );
		}
		$this->remove_stale_redirect_pages( array_filter( $pages ) );

		$rules = array_merge( $rules, self::origin_redirect_rules( $this->pipeline->origins, $this->origin_redirects() ) );

		/**
		 * Filters redirect rules written to the `_redirects` file.
		 *
		 * Origin redirects whose crawled path a static host would read as a pattern (`*`, `:name`) are already left
		 * out. Rules added here are written as given, patterns included, so never add paths taken from a crawl.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int,array{0:string,1:string,2:int}> $rules From path, target, status code.
		 */
		$rules = (array) apply_filters( 'glaciate_redirect_rules', $rules );

		$lines = array();
		foreach ( $rules as $rule ) {
			if ( isset( $rule[0], $rule[1] ) && ! preg_match( '/\s/', $rule[0] . $rule[1] ) ) {
				$lines[ $rule[0] ] = $rule[0] . ' ' . $rule[1] . ' ' . (int) ( $rule[2] ?? 301 );
			}
		}
		$this->write_file( 'glaciate:generated/_redirects', '_redirects', $lines ? implode( "\n", $lines ) . "\n" : '' );
		$this->write_headers();
	}

	/**
	 * Writes `_headers` (read by Netlify and Cloudflare) so directory indexes that aren't HTML, such as feeds saved as
	 * feed/index.html, are served with their real Content-Type. Cloudflare reads at most 100 rules, so the file stops
	 * there, shortest paths (the main feeds) first; Cloudflare also gets the type with each upload.
	 *
	 * @return void
	 */
	private function write_headers(): void {
		$rules = self::directory_header_rules( $this->pipeline->origins, $this->urls->directory_types() );

		/**
		 * Filters the rules written to the `_headers` file, which Netlify and Cloudflare read.
		 *
		 * Crawled paths a static host would read as a pattern are already left out. Rules added here are written as
		 * given, patterns included, so never add paths taken from a crawl.
		 *
		 * @since 0.2.0
		 *
		 * @param array<int,array{0:string,1:array<string,string>}> $rules URL path and the headers to send for it.
		 */
		$rules = (array) apply_filters( 'glaciate_header_rules', $rules );

		$blocks = array();
		foreach ( array_slice( $rules, 0, self::MAX_HEADER_RULES ) as $rule ) {
			if ( ! isset( $rule[0], $rule[1] ) || ! is_array( $rule[1] ) || preg_match( '/[\s]/', (string) $rule[0] ) ) {
				continue;
			}
			$block = (string) $rule[0] . "\n";
			foreach ( $rule[1] as $name => $value ) {
				if ( preg_match( '/^[A-Za-z0-9\-]+$/', (string) $name ) && ! preg_match( '/[\r\n]/', (string) $value ) ) {
					$block .= '  ' . $name . ': ' . $value . "\n";
				}
			}
			$blocks[] = $block;
		}
		$this->write_file( 'glaciate:generated/_headers', '_headers', implode( "\n", $blocks ) );
	}

	/**
	 * Publishes favicon.ico when WordPress answers it with a redirect (to the site icon, or core's logo when none is
	 * set). Browsers request /favicon.ico whatever a page links to, and a redirect from a file address only works on
	 * hosts that read `_redirects`, so the file the redirect points to is copied there. A real favicon.ico, served by
	 * the web server, is published like any other file and this copy steps aside.
	 *
	 * @return void
	 */
	private function write_favicon(): void {
		$path     = (string) wp_parse_url( home_url( '/favicon.ico' ), PHP_URL_PATH );
		$output   = $this->pipeline->origins->output_path_for( $path );
		$relative = null;
		$source   = null;
		try {
			$relative = null === $output ? null : $this->pipeline->cache->mapper()->relative_path( $output );
		} catch ( UnsafePathException $e ) {
			$relative = null;
		}
		$classified = $this->pipeline->classifier->classify( home_url( '/favicon.ico' ) );
		$row        = null === $classified ? null : $this->urls->find( $classified['url'] );
		if ( null !== $relative && null !== $row && 'redirect' === $row['kind'] && null !== $row['redirect_to'] ) {
			$base = $this->pipeline->origins->public_base();
			$to   = (string) $row['redirect_to'];
			if ( 0 === strpos( $to, $base . '/' ) ) {
				try {
					$source = $this->urls->find_by_path( $this->pipeline->cache->mapper()->relative_path( substr( $to, strlen( $base ) ) ) );
				} catch ( UnsafePathException $e ) {
					$source = null;
				}
			}
		}

		$owner = null === $relative ? null : $this->urls->find_by_path( $relative );
		if ( null !== $source && 'asset' === $source['kind'] && ( null === $owner || self::FAVICON === $owner['url'] ) ) {
			$contents = file_get_contents( $this->pipeline->cache->path( (string) $source['path'] ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Our own build cache.
			if ( false !== $contents && '' !== $contents ) {
				$this->write_file( self::FAVICON, (string) $relative, $contents, (string) $source['content_type'] );
				return;
			}
		}

		// No redirect to copy any more. Remove our copy, unless a real favicon.ico has taken its path.
		$copy = $this->urls->find( self::FAVICON );
		if ( null !== $copy && null !== $copy['path'] ) {
			$taken = $this->urls->find_by_path( (string) $copy['path'] );
			if ( null === $taken || (int) $taken['id'] === (int) $copy['id'] ) {
				$this->pipeline->cache->delete( (string) $copy['path'] );
			}
			$this->urls->unpublish( (int) $copy['id'] );
		}
	}

	/**
	 * Recorded origin redirects that have a public target and still redirect (a URL that has since started to answer
	 * 404 keeps its row, but not its rule).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private function origin_redirects(): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare( "SELECT url, redirect_to, status_code FROM %i WHERE kind = 'redirect' AND redirect_to IS NOT NULL AND status_code IN (301, 302, 307, 308) ORDER BY id", Schema::table( 'urls' ) ),
			ARRAY_A
		);
	}

	/**
	 * Turns recorded origin redirects into `_redirects` rules.
	 *
	 * The source paths come from crawled links, so anyone who can put a link on the site chooses them. A source that
	 * carries pattern syntax is left out (see is_literal_path()): a static host would read it as a rule matching many
	 * addresses, not the one the origin redirected. Such a path can never be a file on the static site either.
	 *
	 * @param OriginSet                      $origins Origins.
	 * @param array<int,array<string,mixed>> $rows    Rows with url, redirect_to and status_code.
	 * @return array<int,array{0:string,1:string,2:int}> From path, target, status code.
	 */
	public static function origin_redirect_rules( OriginSet $origins, array $rows ): array {
		$rules = array();
		foreach ( $rows as $row ) {
			$path = self::url_path( (string) $row['url'] );
			if ( ! self::is_literal_path( $path ) ) {
				continue;
			}
			$rules[] = array(
				$origins->public_path_for( $path ),
				self::relative_if_public( $origins, (string) $row['redirect_to'] ),
				in_array( (int) $row['status_code'], array( 301, 302, 307, 308 ), true ) ? (int) $row['status_code'] : 301,
			);
		}
		return $rules;
	}

	/**
	 * Turns published directory indexes that aren't HTML into `_headers` rules that send their real Content-Type.
	 * Paths with pattern syntax are left out, as for redirects.
	 *
	 * @param OriginSet                                   $origins Origins.
	 * @param array<string,array{url:string,type:string}> $files   Directory indexes, from UrlRepository::directory_types().
	 * @return array<int,array{0:string,1:array<string,string>}> URL path and the headers to send for it.
	 */
	public static function directory_header_rules( OriginSet $origins, array $files ): array {
		$rules = array();
		foreach ( $files as $file ) {
			$path = self::url_path( (string) $file['url'] );
			if ( self::is_literal_path( $path ) ) {
				$rules[] = array( $origins->public_path_for( $path ), array( 'Content-Type' => (string) $file['type'] ) );
			}
		}
		return $rules;
	}

	/**
	 * Whether a crawled URL path can go into `_redirects` or `_headers` as itself.
	 *
	 * Netlify and Cloudflare Pages read those paths as patterns: `*` is a splat, `:name` a placeholder, `#` starts a
	 * comment, whitespace separates fields and a line break ends the rule; `?` never belongs in a source path. A path
	 * with any of these, or with `*` or `:` percent-encoded (PathMapper decodes and refuses those, so the path can't be
	 * a file either, and a host might decode them before matching), isn't literal. The admin's own redirects setting
	 * and the rule filters may still use patterns on purpose; this only applies to paths that come from a crawl.
	 *
	 * @param string $path URL path.
	 * @return bool
	 */
	public static function is_literal_path( string $path ): bool {
		return '' !== $path && '/' === $path[0] && ! preg_match( '/[\s\x00-\x1F\x7F*:#?]|%(?:2A|3A)/i', $path );
	}

	/**
	 * Path of a URL, "/" when it has none.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function url_path( string $url ): string {
		$parts = Url::parse( $url );
		$path  = null === $parts ? '' : (string) ( $parts['path'] ?? '' );
		return '' === $path ? '/' : $path;
	}

	/**
	 * Turns a public absolute URL into a path when it points at the static site itself.
	 *
	 * @param OriginSet $origins Origins.
	 * @param string    $url     URL.
	 * @return string
	 */
	private static function relative_if_public( OriginSet $origins, string $url ): string {
		$base = $origins->public_authority();
		if ( 0 === strpos( $url, $base . '/' ) ) {
			return substr( $url, strlen( $base ) );
		}
		return $url === $base ? '/' : $url;
	}

	/**
	 * Writes a meta-refresh page for a user-defined redirect when no page owns its path.
	 *
	 * @param string $from Origin path.
	 * @param string $to   Target path or URL (public).
	 * @return string|null Pseudo-URL of the page written, or null.
	 */
	private function write_redirect_page( string $from, string $to ): ?string {
		$output = $this->pipeline->origins->output_path_for( $from );
		if ( null === $output ) {
			return null;
		}
		try {
			$relative = $this->pipeline->cache->mapper()->relative_path( $output );
		} catch ( UnsafePathException $e ) {
			/* translators: 1: address, 2: error message. */
			( new Logger( $this->build_id ) )->warning( sprintf( __( 'Skipped the redirect from %1$s: %2$s', 'glaciate' ), $from, $e->getMessage() ) );
			return null;
		}
		if ( ! Processor::can_hold_redirect_page( $relative ) ) {
			return null;
		}
		$target = '/' === $to[0] ? $this->pipeline->origins->public_authority() . $to : $to;
		$owner  = $this->urls->find_by_path( $relative );
		$pseudo = 'glaciate:redirect' . $from;
		if ( null !== $owner && $owner['url'] !== $pseudo ) {
			return null;
		}
		$this->write_file( $pseudo, $relative, Processor::redirect_page( $target ) );
		return $pseudo;
	}

	/**
	 * Removes redirect pages for redirects that are no longer configured.
	 *
	 * @param string[] $keep Pseudo-URLs to keep.
	 * @return void
	 */
	private function remove_stale_redirect_pages( array $keep ): void {
		global $wpdb;
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT id, url, path FROM %i WHERE kind = %s AND url LIKE %s AND content_hash IS NOT NULL', Schema::table( 'urls' ), 'generated', $wpdb->esc_like( 'glaciate:redirect' ) . '%' ),
			ARRAY_A
		);
		foreach ( $rows as $row ) {
			if ( ! in_array( $row['url'], $keep, true ) ) {
				$this->pipeline->cache->delete( (string) $row['path'] );
				$this->urls->unpublish( (int) $row['id'] );
			}
		}
	}

	/**
	 * Writes (or removes, when empty) a generated file and records it.
	 *
	 * @param string $pseudo   Pseudo-URL identifying the file.
	 * @param string $relative Output path.
	 * @param string $contents Contents; empty removes the file.
	 * @param string $type     Content-Type.
	 * @return void
	 */
	public function write_file( string $pseudo, string $relative, string $contents, string $type = 'text/plain' ): void {
		$id = $this->urls->ensure( $pseudo, 'generated', 'generated' );
		if ( '' === $contents ) {
			$this->pipeline->cache->delete( $relative );
			$this->urls->unpublish( $id );
			return;
		}
		$hash = $this->pipeline->cache->write( $relative, $contents );
		$this->urls->update(
			$id,
			array(
				'kind'          => 'generated',
				'status_code'   => 200,
				'path'          => $relative,
				'content_hash'  => $hash,
				'size'          => strlen( $contents ),
				'content_type'  => $type,
				'deps_state'    => 'static',
				'last_build_id' => $this->build_id,
				'rendered_at'   => current_time( 'mysql', true ),
			)
		);
	}
}
