<?php
/**
 * Builds the objects a build needs from the site's settings.
 *
 * @package Glaciate
 */

namespace Glaciate\Build;

use Glaciate\Crawl\AssetCopier;
use Glaciate\Crawl\LinkExtractor;
use Glaciate\Crawl\UrlClassifier;
use Glaciate\Output\Cache;
use Glaciate\Output\PathMapper;
use Glaciate\Render\LoopbackClient;
use Glaciate\Rewrite\OriginSet;
use Glaciate\Rewrite\UrlRewriter;
use Glaciate\Security\UrlPolicy;
use Glaciate\Support\Paths;
use Glaciate\Support\Settings;

/**
 * Factory for the per-site build components.
 */
final class Pipeline {

	/**
	 * Origin and public addresses.
	 *
	 * @var OriginSet
	 */
	public $origins;

	/**
	 * URL policy.
	 *
	 * @var UrlPolicy
	 */
	public $policy;

	/**
	 * URL rewriter.
	 *
	 * @var UrlRewriter
	 */
	public $rewriter;

	/**
	 * Link extractor.
	 *
	 * @var LinkExtractor
	 */
	public $extractor;

	/**
	 * URL classifier.
	 *
	 * @var UrlClassifier
	 */
	public $classifier;

	/**
	 * Loopback client.
	 *
	 * @var LoopbackClient
	 */
	public $client;

	/**
	 * Asset copier.
	 *
	 * @var AssetCopier
	 */
	public $copier;

	/**
	 * Build cache.
	 *
	 * @var Cache
	 */
	public $cache;

	/**
	 * Effective settings.
	 *
	 * @var array<string,mixed>
	 */
	public $settings;

	/**
	 * Creates the components for the current site.
	 *
	 * @return Pipeline
	 */
	public static function for_site(): Pipeline {
		$p           = new self();
		$p->settings = Settings::all();
		$p->origins  = self::origin_set( $p->settings );
		$p->policy   = new UrlPolicy( $p->origins->origins() );
		$p->rewriter = new UrlRewriter( $p->origins );

		$p->extractor  = new LinkExtractor( $p->origins );
		$p->classifier = new UrlClassifier( $p->policy, $p->origins, (array) $p->settings['exclude'], (string) $p->settings['feeds'] );
		$p->client     = new LoopbackClient( $p->policy, self::connect_to( $p->settings ), (int) $p->settings['concurrency'] );
		$p->copier     = AssetCopier::for_wordpress();

		Paths::ensure_private_dir( Paths::cache_dir() );
		$p->cache = new Cache( new PathMapper( Paths::cache_dir() ) );
		return $p;
	}

	/**
	 * The site's origin, public address and origin aliases.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return OriginSet
	 */
	public static function origin_set( array $settings ): OriginSet {
		return new OriginSet( home_url( '/' ), self::public_url( $settings ), (array) ( $settings['origin_aliases'] ?? array() ) );
	}

	/**
	 * The public base URL: the setting, or the site address when unset.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string
	 */
	public static function public_url( array $settings ): string {
		$url = trim( (string) ( $settings['public_url'] ?? '' ) );
		return '' === $url ? home_url( '/' ) : $url;
	}

	/**
	 * The loopback connect-to pin.
	 *
	 * @param array<string,mixed> $settings Settings.
	 * @return string
	 */
	public static function connect_to( array $settings ): string {
		/**
		 * Filters where loopback render requests connect ("host" or "host:port"; empty for the URL's own host).
		 *
		 * @since 0.1.0
		 *
		 * @param string $connect_to Pin from the settings or the GLACIATE_CONNECT_TO constant.
		 */
		return (string) apply_filters( 'glaciate_connect_to', (string) ( $settings['connect_to'] ?? '' ) );
	}
}
