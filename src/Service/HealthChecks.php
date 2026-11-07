<?php
/**
 * Environment checks shared by Site Health and `wp glaciate doctor`.
 *
 * @package Glaciate
 */

namespace Glaciate\Service;

use Glaciate\Build\Pipeline;
use Glaciate\Build\Queue;
use Glaciate\Deploy\Registry;
use Glaciate\Render\LoopbackClient;
use Glaciate\Security\Secrets;
use Glaciate\Storage\Schema;
use Glaciate\Support\Paths;
use Glaciate\Support\Settings;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Reads our own tables and Action Scheduler's for diagnostics.

/**
 * Each check returns array{status:string,label:string,description:string,action?:string}, where status is good,
 * recommended or critical (the Site Health vocabulary).
 */
final class HealthChecks {

	/** Checks that run without network requests, by method name. Site Health and `doctor` both use this list. */
	const DIRECT = array( 'permalinks', 'addresses', 'target', 'cache', 'extensions', 'page_cache', 'queue', 'secrets', 'search', 'forms' );

	/**
	 * Checks that run without network requests.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function direct(): array {
		$out = array();
		foreach ( self::DIRECT as $id ) {
			$out[ $id ] = $this->$id();
		}
		return $out;
	}

	/**
	 * All checks, including the loopback request.
	 *
	 * @return array<string,array<string,string>>
	 */
	public function all(): array {
		return $this->direct() + array( 'loopback' => $this->loopback() );
	}

	/**
	 * Pretty permalinks are required.
	 *
	 * @return array<string,string>
	 */
	public function permalinks(): array {
		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'Static publishing needs pretty permalinks', 'glaciate' ),
				'description' => __( 'Plain addresses like ?p=123 can\'t be served by a static host. Choose any other structure under Settings → Permalinks.', 'glaciate' ),
				'action'      => admin_url( 'options-permalink.php' ),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'Permalinks work for static publishing', 'glaciate' ),
			'description' => __( 'Every page has an address a static host can serve.', 'glaciate' ),
		);
	}

	/**
	 * The origin and the public address make sense together.
	 *
	 * @return array<string,string>
	 */
	public function addresses(): array {
		$settings  = Settings::all();
		$home      = (array) wp_parse_url( home_url( '/' ) );
		$public    = (array) wp_parse_url( Pipeline::public_url( $settings ) );
		$wp        = (array) wp_parse_url( site_url( '/' ) );
		$authority = static function ( array $parts ): string {
			return strtolower( (string) ( $parts['host'] ?? '' ) ) . ( isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '' );
		};
		$aliases   = array_map( 'strtolower', (array) ( $settings['origin_aliases'] ?? array() ) );
		$problems  = array();

		if ( \Glaciate\Support\Url::is_private_host( (string) ( $public['host'] ?? '' ) ) ) {
			$problems[] = '' === (string) $settings['public_url']
				/* translators: %s: host name, such as "localhost:8888". */
				? sprintf( __( 'No public address is set, so the static site links to this site\'s own address, %s, which visitors can\'t reach. Enter the address the static site will be served from.', 'glaciate' ), $authority( $public ) )
				/* translators: %s: host name, such as "localhost:8888". */
				: sprintf( __( 'The public address points at %s, which visitors can\'t reach. Links on the static site would lead there.', 'glaciate' ), $authority( $public ) );
		}
		if ( 'https' === ( $home['scheme'] ?? '' ) && 'http' === ( $public['scheme'] ?? '' ) ) {
			$problems[] = __( 'The site uses https but the public address uses plain http, so the static site would load without encryption and browsers may block parts of it. Use an https public address.', 'glaciate' );
		}
		if ( $authority( $wp ) !== $authority( $home ) && ! in_array( $authority( $wp ), $aliases, true ) ) {
			$problems[] = sprintf(
				/* translators: 1: WordPress address host, 2: site address host. */
				__( 'The WordPress address (%1$s) is on a different host than the site address (%2$s). Files under it aren\'t published or rewritten. If %1$s serves the same files, add it under "Other addresses of this site".', 'glaciate' ),
				$authority( $wp ),
				$authority( $home )
			);
		}

		if ( $problems ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'Check the static site\'s public address', 'glaciate' ),
				'description' => implode( ' ', $problems ),
				'action'      => admin_url( 'admin.php?page=glaciate-settings' ),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'The static site\'s public address looks right', 'glaciate' ),
			/* translators: %s: public address. */
			'description' => sprintf( __( 'Links on the static site point to %s.', 'glaciate' ), Pipeline::public_url( $settings ) ),
		);
	}

	/**
	 * PHP extensions that publishing relies on.
	 *
	 * @return array<string,string>
	 */
	public function extensions(): array {
		$missing  = array();
		$critical = false;
		if ( ! function_exists( 'curl_multi_init' ) ) {
			if ( '' !== Pipeline::connect_to( Settings::all() ) ) {
				$critical  = true;
				$missing[] = __( 'The curl extension is missing, and builds need it to reach the site at the "connect to" address that is set, so they can\'t render any page.', 'glaciate' );
			} else {
				$missing[] = __( 'The curl extension is missing, so pages are rendered one at a time instead of several at once, and uploads to remote destinations are slower.', 'glaciate' );
			}
		}
		if ( ! class_exists( \ZipArchive::class ) ) {
			$missing[] = __( 'The zip extension is missing, so the static site can\'t be downloaded as a ZIP file.', 'glaciate' );
		}
		if ( $missing ) {
			return array(
				'status'      => $critical ? 'critical' : 'recommended',
				'label'       => __( 'Static publishing is missing PHP extensions', 'glaciate' ),
				'description' => implode( ' ', $missing ) . ' ' . __( 'Ask your host to enable them.', 'glaciate' ),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'PHP has the extensions static publishing uses', 'glaciate' ),
			'description' => __( 'The curl and zip extensions are available.', 'glaciate' ),
		);
	}

	/**
	 * The deploy target is usable.
	 *
	 * @return array<string,string>
	 */
	public function target(): array {
		$id       = (string) Settings::get( 'target' );
		$deployer = Registry::instance()->get( $id );
		$problem  = null === $deployer ? __( 'The configured deploy target is not available. Was the plugin that provides it deactivated?', 'glaciate' ) : $deployer->problem();
		if ( null !== $problem ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'The static site can\'t be deployed', 'glaciate' ),
				'description' => $problem,
				'action'      => admin_url( 'admin.php?page=glaciate-settings' ),
			);
		}
		return array(
			'status'      => 'good',
			/* translators: %s: deploy target name. */
			'label'       => sprintf( __( 'Static site deploy target is ready (%s)', 'glaciate' ), $deployer->label() ),
			'description' => __( 'The deploy target is configured and reachable.', 'glaciate' ),
		);
	}

	/**
	 * The build cache is writable.
	 *
	 * @return array<string,string>
	 */
	public function cache(): array {
		if ( ! Paths::ensure_private_dir( Paths::cache_dir() ) ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'The static build folder is not writable', 'glaciate' ),
				/* translators: %s: directory path. */
				'description' => sprintf( __( 'Make %s writable by the web server, or set GLACIATE_DATA_DIR in wp-config.php to a writable folder.', 'glaciate' ), Paths::cache_dir() ),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'The static build folder is writable', 'glaciate' ),
			'description' => __( 'Rendered pages can be stored between builds.', 'glaciate' ),
		);
	}

	/**
	 * Page caches that answer render requests hide dependency information.
	 *
	 * @return array<string,string>
	 */
	public function page_cache(): array {
		global $wpdb;
		$unknown = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE deps_state = 'unknown' AND content_hash IS NOT NULL AND kind = 'page'", Schema::table( 'urls' ) )
		);
		if ( $unknown > 0 ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'A page cache is answering static builds', 'glaciate' ),
				'description' => sprintf(
					/* translators: %d: number of pages. */
					_n( '%d page was served from a cache during the last build, so the plugin can\'t tell what it depends on and rebuilds it after every change. Exclude requests with the X-Glaciate-Render header from your page cache.', '%d pages were served from a cache during the last build, so the plugin can\'t tell what they depend on and rebuilds them after every change. Exclude requests with the X-Glaciate-Render header from your page cache.', $unknown, 'glaciate' ),
					$unknown
				),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'Static builds see live pages', 'glaciate' ),
			'description' => __( 'Pages are rendered by WordPress, so changes rebuild only the pages they affect.', 'glaciate' ),
		);
	}

	/**
	 * Background actions are running.
	 *
	 * @return array<string,string>
	 */
	public function queue(): array {
		global $wpdb;
		$table = $wpdb->prefix . 'actionscheduler_actions';
		$late  = 0;
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$late = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM %i a JOIN %i g ON a.group_id = g.group_id WHERE g.slug = %s AND a.status = 'pending' AND a.scheduled_date_gmt < %s",
					$table,
					$wpdb->prefix . 'actionscheduler_groups',
					Queue::GROUP,
					gmdate( 'Y-m-d H:i:s', time() - 15 * MINUTE_IN_SECONDS )
				)
			);
		}
		if ( $late > 0 ) {
			return array(
				'status'      => 'recommended',
				'label'       => __( 'Background publishing is falling behind', 'glaciate' ),
				'description' => __( 'Scheduled static builds are more than 15 minutes late. WP-Cron may be disabled; run "wp action-scheduler run" from a system cron job, or enable WP-Cron.', 'glaciate' ),
				'action'      => admin_url( 'tools.php?page=action-scheduler&status=past-due' ),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'Background publishing is running', 'glaciate' ),
			'description' => __( 'Scheduled and automatic builds run on time.', 'glaciate' ),
		);
	}

	/**
	 * Stored deploy credentials still decrypt.
	 *
	 * @return array<string,string>
	 */
	public function secrets(): array {
		$lost = array();
		foreach ( Secrets::stored_slots() as $slot ) {
			if ( Secrets::SOURCE_UNREADABLE === Secrets::source( $slot['deployer'], $slot['field'] ) ) {
				$lost[] = $slot['deployer'] . ' ' . $slot['field'];
			}
		}
		if ( $lost ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'Saved deploy credentials can no longer be read', 'glaciate' ),
				/* translators: %s: comma-separated list of deploy target fields. */
				'description' => sprintf( __( 'Saved credentials are encrypted with the security keys in wp-config.php, and those keys have changed. Enter these again: %s.', 'glaciate' ), implode( ', ', $lost ) ),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'Saved deploy credentials are readable', 'glaciate' ),
			'description' => __( 'Credentials saved in the database decrypt with this site\'s security keys.', 'glaciate' ),
		);
	}

	/**
	 * What the search mode needs. None of the modes needs anything from this server.
	 *
	 * @return array<string,string>
	 */
	public function search(): array {
		$descriptions = array(
			'builtin'       => __( 'An index of every published page is built with the site and searched in the browser.', 'glaciate' ),
			'pagefind_host' => __( 'Search forms use Pagefind files that your host\'s build step creates in /pagefind/. Static search shows an error if they are missing.', 'glaciate' ),
			'off'           => __( 'Search is off, so search forms on the static site do nothing.', 'glaciate' ),
		);
		return array(
			'status'      => 'good',
			'label'       => __( 'Static search needs nothing from this server', 'glaciate' ),
			'description' => $descriptions[ (string) Settings::get( 'search' ) ] ?? '',
		);
	}

	/**
	 * Forms on the static site can reach a receiver, and the secrets they need are set.
	 *
	 * @return array<string,string>
	 */
	public function forms(): array {
		$config   = new \Glaciate\Forms\Config();
		$settings = admin_url( 'admin.php?page=glaciate-settings' );
		$mode     = $config->mode();
		if ( 'off' === $mode ) {
			return array(
				'status'      => 'good',
				'label'       => __( 'Forms on the static site are off', 'glaciate' ),
				'description' => __( 'Forms are published as they are and can\'t be sent from the static site. Turn on Forms in the settings to receive them.', 'glaciate' ),
			);
		}
		$problems = array();
		$critical = false;
		if ( 'receiver' === $mode && '' === $config->endpoint() ) {
			$critical   = true;
			$problems[] = __( 'Enter the address of your receiver; until then forms are published unchanged.', 'glaciate' );
		}
		if ( 'site' === $mode && \Glaciate\Forms\Config::origin_of( home_url( '/' ) ) === $config->allowed_origin() ) {
			$problems[] = __( 'The static site uses this site\'s own address, so forms would post to the static host. Send them to a receiver elsewhere, or publish the static site at another address.', 'glaciate' );
		}
		if ( null === $config->secret() && ( 'receiver' === $mode || '' !== $config->webhook() ) ) {
			$problems[] = 'receiver' === $mode
				? __( 'Set the shared secret, here and where the receiver runs: without it this site refuses every submission the receiver forwards.', 'glaciate' )
				: __( 'Set the shared secret: the webhook gets nothing without it.', 'glaciate' );
		}
		if ( 'none' !== $config->captcha() && 'site' === $mode && null === $config->captcha_secret() ) {
			$critical   = true;
			$problems[] = __( 'Enter the captcha\'s secret key: without it every submission is refused.', 'glaciate' );
		}
		if ( $problems ) {
			return array(
				'status'      => $critical ? 'critical' : 'recommended',
				'label'       => __( 'Forms on the static site need attention', 'glaciate' ),
				'description' => implode( ' ', $problems ),
				'action'      => $settings,
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'Forms on the static site are received', 'glaciate' ),
			/* translators: %s: receiver address. */
			'description' => sprintf( __( 'Forms on the static site post to %s.', 'glaciate' ), $config->endpoint() ),
		);
	}

	/**
	 * The site can request its own pages.
	 *
	 * @return array<string,string>
	 */
	public function loopback(): array {
		$pipeline = Pipeline::for_site();
		$client   = new LoopbackClient( $pipeline->policy, Pipeline::connect_to( $pipeline->settings ), 1, 15.0 );
		$result   = $client->fetch( array( array( 'url' => home_url( '/' ) ) ) )[0];
		if ( null !== $result->error || $result->status >= 500 || 0 === $result->status ) {
			return array(
				'status'      => 'critical',
				'label'       => __( 'The site can\'t load its own pages', 'glaciate' ),
				'description' => sprintf(
					/* translators: %s: error message. */
					__( 'Static builds request each page from this server. The request failed: %s. If the site address doesn\'t resolve from inside the server (common with containers and proxies), set GLACIATE_CONNECT_TO in wp-config.php to an address that reaches this web server, such as "127.0.0.1:80".', 'glaciate' ),
					null !== $result->error ? $result->error : 'HTTP ' . $result->status
				),
			);
		}
		return array(
			'status'      => 'good',
			'label'       => __( 'The site can load its own pages', 'glaciate' ),
			'description' => __( 'Static builds can render every page the way a visitor sees it.', 'glaciate' ),
		);
	}
}
