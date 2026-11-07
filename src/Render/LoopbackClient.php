<?php
/**
 * Renders pages by requesting them from the site itself.
 *
 * @package Glaciate
 */

namespace Glaciate\Render;

use Glaciate\Security\UrlPolicy;
use WpOrg\Requests\Exception as RequestsException;
use WpOrg\Requests\Exception\Transport\Curl as CurlException;
use WpOrg\Requests\Hooks;
use WpOrg\Requests\Requests;
use WpOrg\Requests\Response;
use WpOrg\Requests\Transport\Curl;

/**
 * Fetches URLs from the origin as an anonymous visitor would, several at a time.
 *
 * - Every URL is checked by the UrlPolicy before it is requested (threat T1).
 * - No cookies are sent, so the page renders logged out (threat T4).
 * - Redirects are returned, not followed, so each Location can be checked and recorded.
 * - TLS certificates aren't checked unless the core https_local_ssl_verify filter turns checks on (DECISIONS.md D30).
 * - An optional connect-to pin ("host:port") sends the request to another address while keeping the URL,
 *   Host header and TLS SNI. This is needed when the site can't reach its own public address (DECISIONS.md D7).
 */
final class LoopbackClient {

	/**
	 * URL policy.
	 *
	 * @var UrlPolicy
	 */
	private $policy;

	/**
	 * Connect-to target "host:port", or "".
	 *
	 * @var string
	 */
	private $connect_to;

	/**
	 * Maximum parallel requests.
	 *
	 * @var int
	 */
	private $concurrency;

	/**
	 * Per-request timeout in seconds.
	 *
	 * @var float
	 */
	private $timeout;

	/**
	 * Maximum body size in bytes.
	 *
	 * @var int
	 */
	private $max_bytes;

	/**
	 * Constructor.
	 *
	 * @param UrlPolicy $policy      URL policy.
	 * @param string    $connect_to  Connect-to pin ("host" or "host:port"), or "" for none.
	 * @param int       $concurrency Parallel requests (1–16).
	 * @param float     $timeout     Timeout per request in seconds.
	 * @param int       $max_bytes   Maximum body size.
	 */
	public function __construct( UrlPolicy $policy, string $connect_to = '', int $concurrency = 4, float $timeout = 30.0, int $max_bytes = 20971520 ) {
		$this->policy      = $policy;
		$this->connect_to  = $connect_to;
		$this->concurrency = max( 1, min( 16, $concurrency ) );
		$this->timeout     = $timeout;
		$this->max_bytes   = $max_bytes;
	}

	/**
	 * Fetches URLs.
	 *
	 * @param array<int|string,array{url:string,headers?:array<string,string>}> $requests Requests keyed by caller ID.
	 * @return array<int|string,RenderResult> Results with the same keys.
	 */
	public function fetch( array $requests ): array {
		$results = array();
		$pending = array();
		foreach ( $requests as $key => $request ) {
			$url = $this->policy->normalize( $request['url'] );
			if ( null === $url ) {
				$result          = new RenderResult( (string) $request['url'] );
				$result->error   = 'Refused by URL policy.';
				$results[ $key ] = $result;
				continue;
			}
			$pending[ $key ] = array(
				'url'     => $url,
				'headers' => $request['headers'] ?? array(),
			);
		}

		foreach ( array_chunk( $pending, $this->concurrency, true ) as $chunk ) {
			foreach ( $this->fetch_chunk( $chunk ) as $key => $result ) {
				$results[ $key ] = $result;
			}
		}
		return $results;
	}

	/**
	 * Sends a fire-and-forget POST to the site itself (used to start background work right away).
	 *
	 * @param string               $url  Origin URL.
	 * @param array<string,string> $body Form fields.
	 * @return bool False when the URL is refused or the request couldn't be sent.
	 */
	public function dispatch( string $url, array $body ): bool {
		$url = $this->policy->normalize( $url );
		if ( null === $url ) {
			return false;
		}
		$pin     = $this->pin_option(
			array(
				array(
					'url'     => $url,
					'headers' => array(),
				),
			)
		);
		$verify  = self::verify( $url );
		$options = array(
			'blocking'        => false,
			'timeout'         => 1,
			'connect_timeout' => 1,
			'hooks'           => self::handle_hooks( $pin, $verify ),
			'verify'          => $verify,
		);
		if ( null !== $pin ) {
			$options['transport'] = Curl::class;
		}
		try {
			Requests::request( $url, array(), $body, Requests::POST, $options );
		} catch ( RequestsException $e ) {
			return false;
		}
		return true;
	}

	/**
	 * Fetches one batch in parallel.
	 *
	 * @param array<int|string,array{url:string,headers:array<string,string>}> $chunk Requests.
	 * @return array<int|string,RenderResult>
	 */
	private function fetch_chunk( array $chunk ): array {
		$pin = $this->pin_option( $chunk );

		$options = array(
			'timeout'          => $this->timeout,
			'connect_timeout'  => min( 10.0, $this->timeout ),
			'follow_redirects' => false,
			'max_bytes'        => $this->max_bytes,
			'hooks'            => new Hooks(),
			'useragent'        => 'Glaciate/' . ( defined( 'GLACIATE_VERSION' ) ? GLACIATE_VERSION : 'dev' ) . ' (static render)',
		);
		if ( null !== $pin ) {
			$options['transport'] = Curl::class;
		}

		// Each request gets its own hooks object, so the certificate choice can differ by URL.
		$requests = array();
		foreach ( $chunk as $key => $request ) {
			$verify           = self::verify( $request['url'] );
			$requests[ $key ] = array(
				'url'     => $request['url'],
				'type'    => Requests::GET,
				'headers' => array_merge( array( 'Accept' => '*/*' ), $request['headers'] ),
				'options' => array_merge(
					$options,
					array(
						'hooks'  => self::handle_hooks( $pin, $verify ),
						'verify' => $verify,
					)
				),
			);
		}

		/**
		 * Fires before a batch of loopback render requests is sent.
		 *
		 * @since 0.1.0
		 *
		 * @param array<int|string,array<string,mixed>> $requests Requests keyed by caller ID.
		 */
		do_action( 'glaciate_before_loopback_batch', $requests );

		$responses = Requests::request_multiple( $requests, $options );

		$results = array();
		foreach ( $chunk as $key => $request ) {
			$result   = new RenderResult( $request['url'] );
			$response = $responses[ $key ] ?? null;
			if ( $response instanceof Response ) {
				$result->status = (int) $response->status_code;
				foreach ( $response->headers as $name => $value ) {
					$result->headers[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', $value ) : (string) $value;
				}
				$result->body = (string) $response->body;
			} elseif ( $response instanceof RequestsException ) {
				$result->error = self::describe( $response, false !== $requests[ $key ]['options']['verify'] );
			} else {
				$result->error = 'No response.';
			}
			$results[ $key ] = $result;
		}
		return $results;
	}

	/**
	 * Whether to check the TLS certificate of a request to the site itself.
	 *
	 * Off by default, as for WordPress's own loopback requests. The URL is passed so a filter can decide per host.
	 *
	 * @param string $url Request URL.
	 * @return bool|string False, true, or a CA bundle path.
	 */
	private static function verify( string $url ) {
		/** This filter is documented in wp-includes/class-wp-http-streams.php */
		$verify = apply_filters( 'https_local_ssl_verify', false, $url ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for loopback TLS verification.
		return is_string( $verify ) && '' !== $verify ? $verify : (bool) $verify;
	}

	/**
	 * Hooks that set up each curl handle: the connect-to pin and the certificate choice.
	 *
	 * The curl transport applies the `verify` option to single requests only. Handles made by request_multiple() skip
	 * it and check certificates against the system's CA store, so a self-signed origin failed every render even though
	 * `verify` was false. Setting it on the handle makes the choice hold for both.
	 *
	 * @param string[]|null $pin    CURLOPT_CONNECT_TO entries, or null.
	 * @param bool|string   $verify False, true, or a CA bundle path.
	 * @return Hooks
	 */
	private static function handle_hooks( ?array $pin, $verify ): Hooks {
		$ca    = is_string( $verify ) ? $verify : ABSPATH . WPINC . '/certificates/ca-bundle.crt';
		$hooks = new Hooks();
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt -- Requests hook hands us the raw handle; no WP API exists for these options.
		$hooks->register(
			'curl.before_request',
			static function ( &$handle ) use ( $pin, $verify, $ca ) {
				if ( null !== $pin ) {
					curl_setopt( $handle, CURLOPT_CONNECT_TO, $pin );
				}
				if ( false === $verify ) {
					curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, false );
					curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 0 );
				} else {
					curl_setopt( $handle, CURLOPT_CAINFO, $ca );
					curl_setopt( $handle, CURLOPT_SSL_VERIFYPEER, true );
					curl_setopt( $handle, CURLOPT_SSL_VERIFYHOST, 2 );
				}
			}
		);
		// phpcs:enable
		return $hooks;
	}

	/**
	 * Turns a transport failure into a message, explaining the case where a filter turned certificate checks on.
	 *
	 * @param RequestsException $e      Failure.
	 * @param bool              $verify Whether certificates were checked.
	 * @return string
	 */
	private static function describe( RequestsException $e, bool $verify ): string {
		$message = $e->getMessage();
		// 60 is CURLE_PEER_FAILED_VERIFICATION: the certificate is self-signed, expired or from an unknown authority.
		if ( $verify && $e instanceof CurlException && 60 === $e->getCode() ) {
			$message .= '. ' . __( 'Certificate checks are on for this site\'s requests to itself because a plugin, theme or mu-plugin makes the https_local_ssl_verify filter return true. Remove that filter, or give this server a certificate it trusts', 'glaciate' );
		}
		return $message;
	}

	/**
	 * Builds the CURLOPT_CONNECT_TO entries for the hosts in a batch.
	 *
	 * @param array<int|string,array{url:string,headers:array<string,string>}> $chunk Requests.
	 * @return string[]|null
	 */
	private function pin_option( array $chunk ): ?array {
		if ( '' === $this->connect_to ) {
			return null;
		}
		// Accepted forms: a host name or bracketed IPv6 address, optionally followed by a port.
		if ( ! preg_match( '/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.\-]+)(?::([0-9]{1,5}))?$/', $this->connect_to, $m ) ) {
			return null;
		}
		$pin_host = $m[1];
		$pin_port = isset( $m[2] ) ? (int) $m[2] : 0;

		$entries = array();
		foreach ( $chunk as $request ) {
			$parts = wp_parse_url( $request['url'] );
			$port  = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === $parts['scheme'] ? 443 : 80 );
			$from  = $parts['host'] . ':' . $port;

			$entries[ $from ] = $from . ':' . $pin_host . ':' . ( $pin_port > 0 ? $pin_port : $port );
		}
		return array_values( $entries );
	}
}
