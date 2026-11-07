<?php
/**
 * WP-CLI commands.
 *
 * @package Glaciate
 */

namespace Glaciate\Cli;

use Glaciate\Build\BuildService;
use Glaciate\Deps\DependencyStore;
use Glaciate\Storage\BuildRepository;
use Glaciate\Storage\UrlRepository;
use Glaciate\Support\Logger;
use WP_CLI;

/**
 * Publish this WordPress site as static files.
 *
 * ## EXAMPLES
 *
 *     # Build and deploy the whole site, waiting until it is done.
 *     $ wp glaciate build --sync
 *
 *     # Show what a page depended on when it was last rendered.
 *     $ wp glaciate why /about/
 */
final class Command {

	/**
	 * Builds the static site and deploys it.
	 *
	 * Without --sync, the build runs in the background and this command returns right away.
	 *
	 * ## OPTIONS
	 *
	 * [--full]
	 * : Render every page, not only pages affected by changes since the last build.
	 *
	 * [--url=<url>]
	 * : Render only these URLs or paths (comma-separated).
	 *
	 * [--sync]
	 * : Run the build in this process and wait for it to finish.
	 *
	 * [--[no-]deploy]
	 * : Deploy when the build finishes. Use --no-deploy to only update the build cache.
	 * ---
	 * default: true
	 * ---
	 *
	 * [--target=<id>]
	 * : Deploy to this target instead of the configured one (local, zip, or a registered deployer).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp glaciate build --full --sync
	 *     $ wp glaciate build --url=/about/,/contact/ --sync
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function build( $args, $assoc ): void {
		$kind = 'incremental';
		if ( ! empty( $assoc['url'] ) ) {
			$kind = 'urls';
		} elseif ( WP_CLI\Utils\get_flag_value( $assoc, 'full', false ) || ! get_option( 'glaciate_published_once' ) ) {
			$kind = 'full';
		}
		$urls = array();
		if ( 'urls' === $kind ) {
			foreach ( explode( ',', (string) $assoc['url'] ) as $url ) {
				$url    = trim( $url );
				$urls[] = preg_match( '#^https?://#i', $url ) ? $url : home_url( '/' . ltrim( $url, '/' ) );
			}
		}
		$sync    = (bool) WP_CLI\Utils\get_flag_value( $assoc, 'sync', false );
		$deploy  = (bool) WP_CLI\Utils\get_flag_value( $assoc, 'deploy', true );
		$service = new BuildService();
		$id      = $service->start(
			$kind,
			'cli',
			get_current_user_id(),
			$deploy,
			array(
				'urls'   => $urls,
				'target' => $assoc['target'] ?? '',
				'sync'   => $sync,
			)
		);

		if ( ! $sync ) {
			WP_CLI::success( sprintf( 'Build #%d queued. Follow it with "wp glaciate status".', $id ) );
			return;
		}

		Logger::echo_to_cli( true );
		$build = $service->run_sync(
			$id,
			static function ( $build ) {
				$urls = $build['stats']['urls'] ?? array();
				WP_CLI::debug( sprintf( '%s: %d done, %d pending', $build['phase'], (int) ( $urls['done'] ?? 0 ), (int) ( $urls['pending'] ?? 0 ) ), 'glaciate' );
			}
		);
		Logger::echo_to_cli( false );

		if ( null === $build || 'done' !== $build['status'] ) {
			WP_CLI::error( sprintf( 'Build #%d %s: %s', $id, $build['status'] ?? 'vanished', $build['error'] ?? '' ) );
		}
		$deploy_stats = $build['stats']['deploy'] ?? array();
		WP_CLI::success(
			sprintf(
				'Build #%d done. %d files published. Deploy: %d added, %d changed, %d deleted.%s',
				$id,
				array_sum( (array) ( $build['stats']['published'] ?? array() ) ),
				(int) ( $deploy_stats['added'] ?? 0 ),
				(int) ( $deploy_stats['modified'] ?? 0 ),
				(int) ( $deploy_stats['deleted'] ?? 0 ),
				empty( $deploy_stats['location'] ) ? '' : ' Output: ' . $deploy_stats['location']
			)
		);
	}

	/**
	 * Shows the active or most recent builds.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : How many builds to list.
	 * ---
	 * default: 5
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function status( $args, $assoc ): void {
		$rows = array();
		foreach ( ( new BuildRepository() )->recent( (int) ( $assoc['limit'] ?? 5 ) ) as $build ) {
			$urls   = $build['stats']['urls'] ?? array();
			$rows[] = array(
				'id'       => $build['id'],
				'kind'     => $build['kind'],
				'status'   => $build['status'],
				'phase'    => $build['phase'],
				'trigger'  => $build['trigger_source'],
				'done'     => (int) ( $urls['done'] ?? 0 ),
				'failed'   => (int) ( $urls['failed'] ?? 0 ),
				'pending'  => (int) ( $urls['pending'] ?? 0 ),
				'created'  => $build['created_at'],
				'finished' => $build['finished_at'],
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'kind', 'status', 'phase', 'trigger', 'done', 'failed', 'pending', 'created', 'finished' ) );
	}

	/**
	 * Cancels the active build.
	 *
	 * @return void
	 */
	public function cancel(): void {
		$id = ( new BuildService() )->cancel();
		if ( null === $id ) {
			WP_CLI::warning( 'No build is running.' );
			return;
		}
		WP_CLI::success( sprintf( 'Cancelled build #%d.', $id ) );
	}

	/**
	 * Shows what a URL depended on when it was last rendered.
	 *
	 * ## OPTIONS
	 *
	 * <url>
	 * : URL or path, such as /about/.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function why( $args, $assoc ): void {
		$row = self::find_url( (string) $args[0] );
		if ( null === $row ) {
			WP_CLI::error( 'That URL is not known. Has the site been built?' );
		}
		WP_CLI::log( sprintf( '%s  (dependencies: %s, last build #%d)', $row['url'], $row['deps_state'], $row['last_build_id'] ) );
		$items = array();
		foreach ( ( new DependencyStore() )->for_url( $row['id'] ) as $type => $keys ) {
			foreach ( $keys as $key ) {
				$items[] = array(
					'type' => $type,
					'key'  => $key,
				);
			}
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $items, array( 'type', 'key' ) );
	}

	/**
	 * Deploys the current build cache without rebuilding.
	 *
	 * ## OPTIONS
	 *
	 * [--target=<id>]
	 * : Deploy target (local, zip, or a registered deployer). Defaults to the configured one.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function deploy( $args, $assoc ): void {
		$target   = (string) ( $assoc['target'] ?? \Glaciate\Support\Settings::get( 'target' ) );
		$deployer = \Glaciate\Deploy\Registry::instance()->get( $target );
		if ( null === $deployer ) {
			WP_CLI::error( sprintf( 'Unknown target "%s". Known: %s', $target, implode( ', ', array_keys( \Glaciate\Deploy\Registry::instance()->all() ) ) ) );
		}
		$problem = $deployer->problem();
		if ( null !== $problem ) {
			WP_CLI::error( $problem );
		}
		$manifest = iterator_to_array( ( new UrlRepository() )->published_files() );
		if ( ! $manifest ) {
			WP_CLI::error( 'Nothing has been built yet. Run "wp glaciate build" first.' );
		}
		// Show the deployer's warnings and per-file errors here, as a build does.
		Logger::echo_to_cli( true );
		try {
			$result = $deployer->deploy( $manifest, \Glaciate\Support\Paths::cache_dir(), new Logger() );
		} catch ( \RuntimeException $e ) {
			// A refusal or a remote failure is an answer for the operator, not a crash.
			WP_CLI::error( \Glaciate\Security\Redactor::scrub( $e->getMessage() ) );
		} finally {
			Logger::echo_to_cli( false );
		}
		WP_CLI::success( sprintf( 'Deployed to %s: %d added, %d changed, %d deleted, %d unchanged.%s', $deployer->label(), $result->added, $result->modified, $result->deleted, $result->unchanged, '' === $result->location ? '' : ' ' . $result->location ) );
	}

	/**
	 * Writes the current build as a ZIP file in the plugin's data directory and prints its path.
	 *
	 * ## OPTIONS
	 *
	 * [--porcelain]
	 * : Print only the archive's path.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp glaciate zip
	 *     $ cp "$(wp glaciate zip --porcelain)" site.zip
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function zip( $args, $assoc ): void {
		$manifest = iterator_to_array( ( new UrlRepository() )->published_files() );
		if ( ! $manifest ) {
			WP_CLI::error( 'Nothing has been built yet. Run "wp glaciate build --no-deploy" first.' );
		}
		$porcelain = (bool) WP_CLI\Utils\get_flag_value( $assoc, 'porcelain', false );
		Logger::echo_to_cli( ! $porcelain );
		try {
			$result = ( new \Glaciate\Deploy\Zip() )->deploy( $manifest, \Glaciate\Support\Paths::cache_dir(), new Logger() );
		} catch ( \RuntimeException $e ) {
			WP_CLI::error( \Glaciate\Security\Redactor::scrub( $e->getMessage() ) );
		} finally {
			Logger::echo_to_cli( false );
		}
		if ( $porcelain ) {
			WP_CLI::line( $result->location );
			return;
		}
		WP_CLI::success( sprintf( 'Wrote %d files to %s', $result->added, $result->location ) );
	}

	/**
	 * Lists known URLs, or manages the extra URLs the crawler always visits.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, add or remove.
	 * ---
	 * options:
	 *   - list
	 *   - add
	 *   - remove
	 * ---
	 *
	 * [<url>...]
	 * : For add/remove: URLs or paths.
	 *
	 * [--status=<status>]
	 * : For list: published, error or all.
	 * ---
	 * default: published
	 * ---
	 *
	 * [--search=<text>]
	 * : For list: only URLs containing this text.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - count
	 * ---
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function urls( $args, $assoc ): void {
		$action = array_shift( $args );
		if ( 'list' === $action ) {
			$rows = ( new UrlRepository() )->list(
				array(
					'status' => $assoc['status'] ?? 'published',
					'search' => $assoc['search'] ?? '',
					'limit'  => 1000,
				)
			);
			WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'url', 'kind', 'status_code', 'path', 'deps_state', 'error' ) );
			return;
		}
		$extra = (array) \Glaciate\Support\Settings::get( 'additional_urls' );
		$extra = 'add' === $action ? array_merge( $extra, $args ) : array_values( array_diff( $extra, $args ) );
		$saved = \Glaciate\Support\Settings::update( array( 'additional_urls' => $extra ) );
		WP_CLI::success( sprintf( '%d extra URLs configured.', count( $saved['additional_urls'] ) ) );
	}

	/**
	 * Shows or discards content changes waiting for the next incremental build.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list (raw change records), preview (what the next publish rebuilds) or flush.
	 * ---
	 * options:
	 *   - list
	 *   - preview
	 *   - flush
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format for list and preview (table, json, csv, yaml).
	 * ---
	 * default: table
	 * ---
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function changes( $args, $assoc ): void {
		$repo = new \Glaciate\Storage\ChangeRepository();
		if ( 'flush' === ( $args[0] ?? '' ) ) {
			WP_CLI::success( sprintf( 'Discarded %d pending changes.', $repo->flush() ) );
			return;
		}
		if ( 'preview' === ( $args[0] ?? '' ) ) {
			$preview = ( new \Glaciate\Service\ChangesetService() )->preview( 1000 );
			if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
				WP_CLI::line( (string) wp_json_encode( $preview, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}
			if ( 0 === $preview['pending'] ) {
				WP_CLI::success( 'Nothing to publish.' );
				return;
			}
			WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $preview['items'], array( 'type', 'key', 'label' ) );
			if ( $preview['full'] ) {
				WP_CLI::line( 'The next publish rebuilds every page.' );
				return;
			}
			WP_CLI::line( sprintf( 'The next publish renders %d addresses:', $preview['pages'] ) );
			foreach ( $preview['urls'] as $url ) {
				WP_CLI::line( '  ' . $url );
			}
			if ( $preview['pages'] > count( $preview['urls'] ) ) {
				WP_CLI::line( sprintf( '  … and %d more.', $preview['pages'] - count( $preview['urls'] ) ) );
			}
			return;
		}//end if
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $repo->pending( 1000 ), array( 'id', 'object_type', 'object_key', 'created_at' ) );
	}

	/**
	 * Reads or changes settings.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, get or set.
	 * ---
	 * options:
	 *   - list
	 *   - get
	 *   - set
	 * ---
	 *
	 * [<key>]
	 * : Setting name.
	 *
	 * [<value>]
	 * : New value. Lists (additional_urls, origin_aliases, exclude, redirects) take one entry per line or a JSON array.
	 *
	 * [--format=<format>]
	 * : Output format for list.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp glaciate settings set public_url https://www.example.org
	 *     $ wp glaciate settings set target zip
	 *     $ wp glaciate settings get full_rebuild
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function settings( $args, $assoc ): void {
		$action   = $args[0] ?? 'list';
		$settings = \Glaciate\Support\Settings::all();
		if ( 'list' === $action ) {
			$rows = array();
			foreach ( $settings as $key => $value ) {
				$rows[] = array(
					'key'    => $key,
					'value'  => is_scalar( $value ) ? var_export( $value, true ) : wp_json_encode( $value ), // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Display formatting.
					'pinned' => \Glaciate\Support\Settings::is_pinned( $key ) ? 'wp-config.php' : '',
				);
			}
			WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'key', 'value', 'pinned' ) );
			return;
		}
		$key = (string) ( $args[1] ?? '' );
		if ( ! array_key_exists( $key, \Glaciate\Support\Settings::defaults() ) ) {
			WP_CLI::error( sprintf( 'Unknown setting "%s". Known: %s', $key, implode( ', ', array_keys( \Glaciate\Support\Settings::defaults() ) ) ) );
		}
		if ( 'get' === $action ) {
			$value = $settings[ $key ];
			WP_CLI::log( is_scalar( $value ) ? (string) var_export( $value, true ) : (string) wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Display formatting.
			return;
		}
		if ( \Glaciate\Support\Settings::is_pinned( $key ) ) {
			WP_CLI::error( sprintf( '%s is set in wp-config.php (%s).', $key, \Glaciate\Support\Settings::CONSTANTS[ $key ] ) );
		}
		$raw     = (string) ( $args[2] ?? '' );
		$decoded = json_decode( $raw, true );
		$value   = is_array( $decoded ) ? $decoded : $raw;
		if ( is_bool( \Glaciate\Support\Settings::defaults()[ $key ] ) ) {
			$value = in_array( strtolower( $raw ), array( '1', 'true', 'yes', 'on' ), true );
		}
		$saved = \Glaciate\Support\Settings::update( array( $key => $value ) );
		if ( 'local_path' === $key && '' !== $saved['local_path'] ) {
			$problem = \Glaciate\Security\TargetPolicy::for_wordpress()->problem( $saved['local_path'] );
			if ( null !== $problem ) {
				WP_CLI::warning( $problem );
			}
		}
		WP_CLI::success( sprintf( '%s = %s', $key, is_scalar( $saved[ $key ] ) ? var_export( $saved[ $key ], true ) : wp_json_encode( $saved[ $key ] ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- Display formatting.
	}

	/**
	 * Shows when changes are published and when the next full rebuild runs, in the site's timezone.
	 *
	 * Change the schedule with `wp glaciate settings set`: publish_mode (immediate, scheduled or manual),
	 * publish_times, publish_days, full_rebuild (off, daily or weekly), full_rebuild_time and full_rebuild_day.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp glaciate settings set publish_mode scheduled
	 *     $ wp glaciate settings set publish_times "09:00, 17:30"
	 *     $ wp glaciate settings set publish_days "mon,tue,wed,thu,fri"
	 *     $ wp glaciate settings set full_rebuild_time 02:30
	 *     $ wp glaciate schedule
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function schedule( $args, $assoc ): void {
		$settings = \Glaciate\Support\Settings::all();
		$next     = \Glaciate\Build\Queue::next_runs();
		$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		$when     = static function ( $time ) use ( $format ) {
			return null === $time ? '' : wp_date( $format, (int) $time );
		};
		$modes    = array(
			'immediate' => 'a minute after changes are saved',
			'scheduled' => 'at ' . implode( ', ', $settings['publish_times'] ) . ' on ' . implode( ', ', $settings['publish_days'] ),
			'manual'    => 'only when started by hand',
		);
		$rebuild  = 'off' === $settings['full_rebuild'] ? 'off' : $settings['full_rebuild'] . ' at ' . $settings['full_rebuild_time'] . ( 'weekly' === $settings['full_rebuild'] ? ' on ' . $settings['full_rebuild_day'] : '' );
		$rows     = array(
			array(
				'job'      => 'publish changes',
				'schedule' => $modes[ $settings['publish_mode'] ] ?? $settings['publish_mode'],
				'next'     => $when( $next[ \Glaciate\Build\Queue::WINDOW_HOOK ] ),
			),
			array(
				'job'      => 'full rebuild',
				'schedule' => $rebuild,
				'next'     => $when( $next[ \Glaciate\Build\Queue::SCHEDULED_HOOK ] ),
			),
		);
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'job', 'schedule', 'next' ) );
		if ( 'table' === ( $assoc['format'] ?? 'table' ) ) {
			WP_CLI::log( sprintf( 'Times are in the site\'s timezone (%s).', wp_timezone_string() ) );
		}
	}

	/**
	 * Shows and changes the settings of deploy targets.
	 *
	 * Every field can also be pinned with a constant or environment variable named GLACIATE_<TARGET>_<FIELD>.
	 * Secret fields are set with "wp glaciate secret set" and never printed.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, show, set or check.
	 * ---
	 * options:
	 *   - list
	 *   - show
	 *   - set
	 *   - check
	 * ---
	 *
	 * [<target>]
	 * : Target ID, for example s3.
	 *
	 * [<field>]
	 * : For set: field name.
	 *
	 * [<value>]
	 * : For set: new value. An empty string restores the default.
	 *
	 * [--format=<format>]
	 * : Output format for list and show.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp glaciate target show s3
	 *     $ wp glaciate target set s3 bucket www.example.org
	 *     $ wp glaciate target set s3 region eu-west-1
	 *     $ printf %s "$AWS_SECRET_ACCESS_KEY" | wp glaciate secret set s3 secret_access_key
	 *     $ wp glaciate target check s3
	 *     $ wp glaciate settings set target s3
	 *
	 *     # SFTP: check shows the server's host key fingerprint until one is saved.
	 *     $ wp glaciate target set sftp host deploy.example.org
	 *     $ wp glaciate target set sftp username deploy
	 *     $ wp glaciate target set sftp remote_path /var/www/static
	 *     $ wp glaciate secret set sftp private_key < ~/.ssh/glaciate_deploy
	 *     $ wp glaciate target check sftp
	 *     $ wp glaciate target set sftp host_key SHA256:...
	 *
	 *     # Git: publish to the gh-pages branch of a repository.
	 *     $ wp glaciate target set git repository_url https://github.com/example/site.git
	 *     $ printf %s "$GITHUB_TOKEN" | wp glaciate secret set git token
	 *     $ wp glaciate target check git
	 *
	 *     # Netlify: the site's API ID and a personal access token.
	 *     $ wp glaciate target set netlify site_id 0f1e2d3c-4b5a-6978-8a9b-0c1d2e3f4a5b
	 *     $ printf %s "$NETLIFY_TOKEN" | wp glaciate secret set netlify token
	 *
	 *     # Cloudflare: an assets-only Worker in your account.
	 *     $ wp glaciate target set cloudflare account_id 0123456789abcdef0123456789abcdef
	 *     $ wp glaciate target set cloudflare worker_name my-site
	 *     $ printf %s "$CLOUDFLARE_API_TOKEN" | wp glaciate secret set cloudflare api_token
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function target( $args, $assoc ): void {
		$action   = $args[0] ?? 'list';
		$registry = \Glaciate\Deploy\Registry::instance();
		if ( 'list' === $action ) {
			$rows = array();
			foreach ( $registry->all() as $id => $deployer ) {
				$rows[] = array(
					'target'  => $id,
					'label'   => $deployer->label(),
					'active'  => \Glaciate\Support\Settings::get( 'target' ) === $id ? 'yes' : '',
					'problem' => (string) $deployer->problem(),
				);
			}
			WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'target', 'label', 'active', 'problem' ) );
			return;
		}
		$id       = (string) ( $args[1] ?? '' );
		$deployer = $registry->get( $id );
		if ( null === $deployer ) {
			WP_CLI::error( sprintf( 'Unknown target "%s". Known: %s', $id, implode( ', ', array_keys( $registry->all() ) ) ) );
		}
		$fields = \Glaciate\Deploy\TargetConfig::fields( $deployer );
		if ( 'show' === $action ) {
			$rows = array();
			foreach ( $fields as $name => $def ) {
				$value = \Glaciate\Deploy\TargetConfig::value( $deployer, $name );
				if ( 'secret' === $def['type'] ) {
					$value = '' === $value ? '' : '(hidden)';
				} elseif ( is_bool( $value ) ) {
					$value = $value ? 'true' : 'false';
				}
				$rows[] = array(
					'field'    => $name,
					'value'    => $value,
					'source'   => \Glaciate\Deploy\TargetConfig::source( $deployer, $name ),
					'required' => $def['required'] ? 'yes' : '',
					'constant' => \Glaciate\Security\Secrets::name( $id, $name ),
				);
			}
			if ( ! $rows ) {
				WP_CLI::log( sprintf( '%s has no settings of its own.', $deployer->label() ) );
				return;
			}
			WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'field', 'value', 'source', 'required', 'constant' ) );
			return;
		}//end if
		if ( 'check' === $action ) {
			$problem = $deployer instanceof \Glaciate\Deploy\ConfigurableDeployerInterface ? $deployer->check_connection() : $deployer->problem();
			if ( null !== $problem ) {
				WP_CLI::error( $problem );
			}
			WP_CLI::success( sprintf( 'Target "%s" is ready to publish.', $id ) );
			return;
		}
		$field = (string) ( $args[2] ?? '' );
		if ( isset( $fields[ $field ] ) && 'secret' === $fields[ $field ]['type'] ) {
			WP_CLI::error( sprintf( '%s is a secret. Use: wp glaciate secret set %s %s', $field, $id, $field ) );
		}
		try {
			\Glaciate\Deploy\TargetConfig::set( $deployer, $field, (string) ( $args[3] ?? '' ) );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		} catch ( \LogicException $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		$value = \Glaciate\Deploy\TargetConfig::value( $deployer, $field );
		WP_CLI::success( sprintf( '%s %s = %s', $id, $field, is_bool( $value ) ? ( $value ? 'true' : 'false' ) : $value ) );
	}

	/**
	 * Manages deploy credentials. Values are never printed.
	 *
	 * Each secret can also come from a wp-config.php constant or an environment variable named
	 * GLACIATE_<DEPLOYER>_<FIELD>, which takes precedence over a stored value.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, set or delete.
	 * ---
	 * options:
	 *   - list
	 *   - set
	 *   - delete
	 * ---
	 *
	 * [<deployer>]
	 * : Deployer ID, for example sftp.
	 *
	 * [<field>]
	 * : Field name, for example password.
	 *
	 * [--format=<format>]
	 * : Output format for list.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Prompts for the value without echoing it.
	 *     $ wp glaciate secret set sftp password
	 *
	 *     # Reads the value from standard input.
	 *     $ printf %s "$TOKEN" | wp glaciate secret set netlify token
	 *
	 *     $ wp glaciate secret list
	 *     $ wp glaciate secret delete sftp password
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function secret( $args, $assoc ): void {
		$action = $args[0] ?? 'list';
		if ( 'list' === $action ) {
			$rows = array();
			foreach ( \Glaciate\Security\Secrets::stored_slots() as $slot ) {
				$rows[] = array(
					'deployer' => $slot['deployer'],
					'field'    => $slot['field'],
					'source'   => \Glaciate\Security\Secrets::source( $slot['deployer'], $slot['field'] ),
					'name'     => \Glaciate\Security\Secrets::name( $slot['deployer'], $slot['field'] ),
				);
			}
			WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'deployer', 'field', 'source', 'name' ) );
			return;
		}
		$deployer = (string) ( $args[1] ?? '' );
		$field    = (string) ( $args[2] ?? '' );
		try {
			if ( 'delete' === $action ) {
				\Glaciate\Security\Secrets::delete( $deployer, $field );
				WP_CLI::success( sprintf( 'Deleted the stored %s %s.', $deployer, $field ) );
				return;
			}
			$name = \Glaciate\Security\Secrets::name( $deployer, $field );
			if ( stream_isatty( STDIN ) ) {
				$value = (string) \cli\prompt( sprintf( 'Value for %s', $name ), false, ': ', true );
			} else {
				$value = (string) stream_get_contents( STDIN );
				// Trailing newlines come from `echo` and here-docs, never from a credential.
				$value = rtrim( $value, "\r\n" );
			}
			if ( '' === $value ) {
				WP_CLI::error( 'No value given. Use "secret delete" to remove a secret.' );
			}
			\Glaciate\Security\Secrets::set( $deployer, $field, $value );
		} catch ( \InvalidArgumentException $e ) {
			WP_CLI::error( $e->getMessage() );
		} catch ( \LogicException $e ) {
			WP_CLI::error( $e->getMessage() );
		}//end try
		WP_CLI::success( sprintf( 'Stored %s %s (encrypted).', $deployer, $field ) );
	}

	/**
	 * Shows how forms on the static site are received, or prints a standalone receiver.
	 *
	 * Turn forms on with `wp glaciate settings set forms site` (this site's REST API receives them) or `forms
	 * receiver` with `forms_endpoint` set to a receiver you run. The shared secret is `wp glaciate secret set forms
	 * secret`; the captcha secret is `wp glaciate secret set forms captcha_secret`. Changing these settings rebuilds
	 * the site at the next publish.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : status, or receiver to print a standalone receiver with this site's settings filled in. Its secrets never go
	 * into it: set GLACIATE_FORMS_SECRET where it runs to the same value as here.
	 * ---
	 * default: status
	 * options:
	 *   - status
	 *   - receiver
	 * ---
	 *
	 * [<kind>]
	 * : For receiver: php (any PHP host), cloudflare (a Worker) or netlify (a Netlify Function).
	 *
	 * [--format=<format>]
	 * : Output format for status.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp glaciate forms
	 *     $ wp glaciate forms receiver php > glaciate-form.php
	 *     $ wp glaciate forms receiver cloudflare > worker.mjs
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function forms( $args, $assoc ): void {
		$config = new \Glaciate\Forms\Config();
		if ( 'receiver' === ( $args[0] ?? 'status' ) ) {
			try {
				$code = \Glaciate\Forms\ReceiverExport::build( (string) ( $args[1] ?? 'php' ), $config );
			} catch ( \InvalidArgumentException $e ) {
				WP_CLI::error( $e->getMessage() );
			}
			WP_CLI::line( rtrim( $code, "\n" ) );
			return;
		}
		$source = static function ( string $field ): string {
			$where = \Glaciate\Security\Secrets::source( \Glaciate\Forms\Config::SECRETS, $field );
			return '' === $where ? 'not set' : $where;
		};
		$rows   = array(
			array( 'mode', $config->mode() ),
			array( 'forms post to', '' === $config->endpoint() ? '(nowhere: forms are published unchanged)' : $config->endpoint() ),
			array( 'relay address', $config->relay_url() ),
			array( 'pages allowed to post', $config->allowed_origin() ),
			array( 'marked forms email', $config->email() ),
			array( 'webhook', '' === $config->webhook() ? '(none)' : $config->webhook() ),
			array( 'captcha', $config->captcha() ),
			array( 'secret', $source( 'secret' ) ),
			array( 'captcha secret', $source( 'captcha_secret' ) ),
		);
		WP_CLI\Utils\format_items(
			$assoc['format'] ?? 'table',
			array_map(
				static function ( $row ) {
					return array(
						'setting' => $row[0],
						'value'   => $row[1],
					);
				},
				$rows
			),
			array( 'setting', 'value' )
		);
	}

	/**
	 * Checks that this site can be published (the same checks as Site Health).
	 *
	 * @return void
	 */
	public function doctor(): void {
		$failed = 0;
		foreach ( ( new \Glaciate\Service\HealthChecks() )->all() as $id => $check ) {
			$line = sprintf( '%-11s %s', $id, $check['label'] );
			if ( 'good' === $check['status'] ) {
				WP_CLI::log( WP_CLI::colorize( '%G✔%n ' ) . $line );
				continue;
			}
			WP_CLI::log( WP_CLI::colorize( 'critical' === $check['status'] ? '%R✘%n ' : '%Y!%n ' ) . $line );
			WP_CLI::log( '            ' . $check['description'] );
			if ( 'critical' === $check['status'] ) {
				++$failed;
			}
		}
		if ( $failed > 0 ) {
			WP_CLI::error( sprintf( '%d critical problem(s).', $failed ) );
		}
		WP_CLI::success( 'Ready to publish.' );
	}

	/**
	 * Prints a build's log.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Build ID. Defaults to the most recent build.
	 *
	 * @param string[] $args Positional arguments.
	 * @return void
	 */
	public function log( $args ): void {
		$id = (int) ( $args[0] ?? 0 );
		if ( 0 === $id ) {
			$recent = ( new BuildRepository() )->recent( 1 );
			$id     = (int) ( $recent[0]['id'] ?? 0 );
		}
		foreach ( Logger::lines( $id ) as $line ) {
			WP_CLI::log( sprintf( '%s [%s] %s', $line['created_at'], $line['level'], $line['message'] ) );
		}
	}

	/**
	 * Forgets everything built so far: the build cache, URL records, dependencies and history.
	 *
	 * Deploy targets are not touched. The next build is a full build.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Don't ask for confirmation.
	 *
	 * @param string[]             $args  Positional arguments.
	 * @param array<string,string> $assoc Options.
	 * @return void
	 */
	public function clean( $args, $assoc ): void {
		global $wpdb;
		WP_CLI::confirm( 'Delete the build cache and all build records? Deployed sites are not touched.', $assoc );
		if ( null !== ( new BuildRepository() )->active() ) {
			WP_CLI::error( 'A build is running. Cancel it first with "wp glaciate cancel".' );
		}
		foreach ( \Glaciate\Storage\Schema::TABLES as $table ) {
			if ( 'target_files' === $table ) {
				continue;
				// Keep deploy records so files we put in a target can still be cleaned up.
			}
			$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', \Glaciate\Storage\Schema::table( $table ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own tables.
		}
		\Glaciate\Support\Paths::delete_tree( \Glaciate\Support\Paths::cache_dir() );
		delete_option( 'glaciate_published_once' );
		WP_CLI::success( 'Build state cleared.' );
	}


	/**
	 * Finds a URL row from a URL or path.
	 *
	 * @param string $input URL or path.
	 * @return array<string,mixed>|null
	 */
	private static function find_url( string $input ): ?array {
		$url = preg_match( '#^https?://#i', $input ) ? $input : home_url( '/' . ltrim( $input, '/' ) );
		$set = new \Glaciate\Rewrite\OriginSet( home_url( '/' ), home_url( '/' ) );
		$url = ( new \Glaciate\Security\UrlPolicy( $set->origins() ) )->normalize( $url );
		return null === $url ? null : ( new UrlRepository() )->find( $url );
	}
}
