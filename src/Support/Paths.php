<?php
/**
 * Filesystem locations used by the plugin.
 *
 * @package Glaciate
 */

namespace Glaciate\Support;

/**
 * Resolves the data directory (build cache, ZIP archives, default local target).
 *
 * The data directory defaults to a glaciate folder in the uploads directory, as wp_upload_dir() resolves it at runtime,
 * and can be moved with the GLACIATE_DATA_DIR constant. Every site in a network gets its own subdirectories, named
 * after the blog ID.
 */
final class Paths {

	/**
	 * Base data directory.
	 *
	 * @return string
	 */
	public static function data_dir(): string {
		if ( defined( 'GLACIATE_DATA_DIR' ) ) {
			$dir = (string) GLACIATE_DATA_DIR;
		} else {
			$uploads = wp_upload_dir( null, false );
			$dir     = $uploads['basedir'] . '/glaciate';
		}

		/**
		 * Filters the base data directory.
		 *
		 * @since 0.1.0
		 *
		 * @param string $dir Absolute path.
		 */
		return untrailingslashit( wp_normalize_path( (string) apply_filters( 'glaciate_data_dir', $dir ) ) );
	}

	/**
	 * URL path of the data directory, when the web server serves it (it is inside the uploads directory).
	 *
	 * Builds never crawl or copy anything below it: it holds the build cache, ZIP archives and perhaps a local target.
	 *
	 * @return string|null Path without trailing slash, for example /wp-content/uploads/glaciate, or null.
	 */
	public static function data_url_path(): ?string {
		$uploads = wp_upload_dir( null, false );
		$base    = untrailingslashit( wp_normalize_path( (string) $uploads['basedir'] ) );
		$dir     = self::data_dir();
		if ( '' === $base || 0 !== strpos( $dir . '/', $base . '/' ) ) {
			return null;
		}
		$path = wp_parse_url( (string) $uploads['baseurl'], PHP_URL_PATH );
		return untrailingslashit( ( is_string( $path ) ? $path : '' ) . substr( $dir, strlen( $base ) ) );
	}

	/**
	 * Build cache for the current site: the latest rendered copy of every file.
	 *
	 * @return string
	 */
	public static function cache_dir(): string {
		return self::data_dir() . '/cache/' . get_current_blog_id();
	}

	/**
	 * Directory for ZIP archives of the current site.
	 *
	 * @return string
	 */
	public static function zip_dir(): string {
		return self::data_dir() . '/zips/' . get_current_blog_id();
	}

	/**
	 * Default local deploy target for the current site.
	 *
	 * @return string
	 */
	public static function default_local_target(): string {
		return self::data_dir() . '/public/' . get_current_blog_id();
	}

	/**
	 * Creates a private directory (cache, ZIPs) and blocks direct web access to it where the server allows.
	 *
	 * @param string $dir Absolute path.
	 * @return bool True when the directory exists and is writable.
	 */
	public static function ensure_private_dir( string $dir ): bool {
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		// The data directory itself stays open, so a local target under it can be served if the admin wants.
		self::protect( dirname( $dir ) );
		self::protect( $dir );
		return wp_is_writable( $dir );
	}

	/**
	 * Deletes a directory tree of our own without following symlinks (a link is removed, not its target).
	 *
	 * @param string $path Path.
	 * @return void
	 */
	public static function delete_tree( string $path ): void {
		if ( is_link( $path ) || is_file( $path ) ) {
			wp_delete_file( $path );
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		foreach ( (array) scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				self::delete_tree( $path . '/' . $entry );
			}
		}
		rmdir( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- Our own private directory.
	}

	/**
	 * Drops index.php and .htaccess deny files into a directory if they are missing.
	 *
	 * @param string $dir Directory.
	 * @return void
	 */
	private static function protect( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny guard file in our own directory.
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Tiny guard file in our own directory.
		}
	}
}
