<?php
/**
 * Remembers what the web server answers anonymous visitors, so files are only copied from disk where it serves them.
 *
 * @package Glaciate
 */

namespace Glaciate\Crawl;

defined( 'ABSPATH' ) || exit;

/**
 * Verdicts per directory and file type, for one build (threat T3, DECISIONS.md D53).
 *
 * A file on disk may sit behind an access rule that only the web server knows about: an `.htaccess` deny, basic auth,
 * an nginx `location` block, a download plugin's rewrite to PHP. Before a file is copied from disk, the build asks the
 * server for one file of the same type in the same directory, as an anonymous visitor. The answer decides for every
 * file the pattern covers:
 *
 * - copy:  the server serves it (200, 206, or 416 for a range it can't satisfy), so copying from disk is equivalent.
 * - fetch: something else (a redirect, a 404 for a file that exists): fetch each file over HTTP instead.
 * - deny:  401 or 403. Files of that type in that directory and below are left out of the build altogether.
 *
 * Overload and transport errors (0, 429, 5xx) are not remembered; the file is fetched over HTTP, which backs off.
 *
 * Keys are glob patterns ("/wp-content/uploads/private/*.pdf"), the form the URL classifier's exclusions take, so a
 * denied pattern can be added to them as it is. A directory that is an asset root, or contains one, is never denied
 * as a whole: one refused file directly in uploads must not take every upload with it. That file is still left out.
 *
 * No WordPress calls, so it can be unit tested.
 */
final class AccessVerdicts {

	/** Copy from disk. */
	const COPY = 'copy';

	/** Fetch over HTTP. */
	const FETCH = 'fetch';

	/** Leave out of the build. */
	const DENY = 'deny';

	/**
	 * Pattern => verdict.
	 *
	 * @var array<string,string>
	 */
	private $verdicts = array();

	/**
	 * URL path prefixes (no trailing slash) that are never denied as a whole, with every directory above them.
	 *
	 * @var string[]
	 */
	private $roots = array();

	/**
	 * Whether a verdict changed since the last saved().
	 *
	 * @var bool
	 */
	private $changed = false;

	/**
	 * Constructor.
	 *
	 * @param string[] $roots URL path prefixes of the asset roots.
	 */
	public function __construct( array $roots = array() ) {
		foreach ( $roots as $root ) {
			$this->roots[] = rtrim( (string) $root, '/' );
		}
	}

	/**
	 * The pattern that covers a URL path: its directory and its file type.
	 *
	 * @param string $url_path URL path (percent-encoded).
	 * @return string|null Null when the path has no directory or no extension.
	 */
	public static function pattern( string $url_path ): ?string {
		$parts = self::split( $url_path );
		return null === $parts ? null : $parts[0] . '/*.' . $parts[1];
	}

	/**
	 * What an answer means.
	 *
	 * @param int $status HTTP status, or 0 when the request failed.
	 * @return string|null A verdict, or null for an answer that says nothing lasting (failure, overload).
	 */
	public static function verdict_for( int $status ): ?string {
		if ( 200 === $status || 206 === $status || 416 === $status ) {
			return self::COPY;
		}
		if ( self::is_denial( $status ) ) {
			return self::DENY;
		}
		if ( $status < 100 || 429 === $status || $status >= 500 ) {
			return null;
		}
		return self::FETCH;
	}

	/**
	 * Whether an answer refuses access.
	 *
	 * @param int $status HTTP status.
	 * @return bool
	 */
	public static function is_denial( int $status ): bool {
		return 401 === $status || 403 === $status;
	}

	/**
	 * The verdict for a URL path, or null when the server hasn't been asked yet.
	 *
	 * @param string $url_path URL path.
	 * @return string|null
	 */
	public function get( string $url_path ): ?string {
		if ( $this->is_denied( $url_path ) ) {
			return self::DENY;
		}
		$pattern = self::pattern( $url_path );
		return null === $pattern ? null : ( $this->verdicts[ $pattern ] ?? null );
	}

	/**
	 * Whether files like this one were refused in its directory or a directory above it.
	 *
	 * @param string $url_path URL path.
	 * @return bool
	 */
	public function is_denied( string $url_path ): bool {
		$parts = self::split( $url_path );
		if ( null === $parts ) {
			return false;
		}
		list( $dir, $ext ) = $parts;
		while ( '' !== $dir ) {
			if ( self::DENY === ( $this->verdicts[ $dir . '/*.' . $ext ] ?? null ) ) {
				return true;
			}
			$dir = substr( $dir, 0, (int) strrpos( $dir, '/' ) );
		}
		return false;
	}

	/**
	 * Records the server's answer for a URL path.
	 *
	 * @param string $url_path URL path that was requested.
	 * @param int    $status   HTTP status, or 0 when the request failed.
	 * @return string|null The verdict now held for the path's pattern, or null when nothing was recorded.
	 */
	public function record( string $url_path, int $status ): ?string {
		$verdict = self::verdict_for( $status );
		$parts   = self::split( $url_path );
		if ( null === $verdict || null === $parts ) {
			return null;
		}
		$pattern = $parts[0] . '/*.' . $parts[1];
		if ( self::DENY === $verdict && ! $this->may_deny( $parts[0] ) ) {
			$verdict = self::FETCH;
		}
		$known = $this->verdicts[ $pattern ] ?? null;
		if ( self::DENY === $known ) {
			return $known;
			// A refusal stands for the rest of the build.
		}
		if ( $known !== $verdict ) {
			$this->verdicts[ $pattern ] = $verdict;
			$this->changed              = true;
		}
		return $verdict;
	}

	/**
	 * Adds verdicts saved earlier in the build. Entries that aren't valid are ignored.
	 *
	 * @param array<mixed,mixed> $verdicts Pattern => verdict.
	 * @return void
	 */
	public function load( array $verdicts ): void {
		foreach ( $verdicts as $pattern => $verdict ) {
			if ( ! is_string( $pattern ) || ! in_array( $verdict, array( self::COPY, self::FETCH, self::DENY ), true ) ) {
				continue;
			}
			if ( ! preg_match( '#^(/.*)/\*\.[a-z0-9]{1,8}$#', $pattern, $m ) ) {
				continue;
			}
			if ( self::DENY === $verdict && ! $this->may_deny( $m[1] ) ) {
				continue;
			}
			if ( self::DENY !== ( $this->verdicts[ $pattern ] ?? null ) ) {
				$this->verdicts[ $pattern ] = $verdict;
			}
		}
	}

	/**
	 * All verdicts, for saving.
	 *
	 * @return array<string,string> Pattern => verdict.
	 */
	public function export(): array {
		return $this->verdicts;
	}

	/**
	 * The denied patterns.
	 *
	 * @return string[]
	 */
	public function denied(): array {
		return array_keys( $this->verdicts, self::DENY, true );
	}

	/**
	 * Whether a verdict changed since the last saved().
	 *
	 * @return bool
	 */
	public function changed(): bool {
		return $this->changed;
	}

	/**
	 * Notes that the current verdicts were saved.
	 *
	 * @return void
	 */
	public function saved(): void {
		$this->changed = false;
	}

	/**
	 * Whether a whole directory may be denied: not the site root, an asset root or a directory above one.
	 *
	 * @param string $dir Directory URL path, no trailing slash.
	 * @return bool
	 */
	private function may_deny( string $dir ): bool {
		if ( '' === trim( $dir, '/' ) ) {
			return false;
		}
		foreach ( $this->roots as $root ) {
			if ( 0 === strpos( $root . '/', $dir . '/' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Splits a URL path into its directory and lowercase extension.
	 *
	 * @param string $url_path URL path.
	 * @return array{0:string,1:string}|null
	 */
	private static function split( string $url_path ): ?array {
		$slash = strrpos( $url_path, '/' );
		if ( false === $slash ) {
			return null;
		}
		$name = substr( $url_path, $slash + 1 );
		$dot  = strrpos( $name, '.' );
		if ( false === $dot ) {
			return null;
		}
		$ext = strtolower( substr( $name, $dot + 1 ) );
		if ( ! preg_match( '/^[a-z0-9]{1,8}$/', $ext ) ) {
			return null;
		}
		return array( substr( $url_path, 0, $slash ), $ext );
	}
}
