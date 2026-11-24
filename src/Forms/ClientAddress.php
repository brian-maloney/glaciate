<?php
/**
 * What the form receiver counts a visitor's address as.
 *
 * @package Glaciate
 */

namespace Glaciate\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Pure helpers for the form rate limit (D60), without WordPress calls so they can be unit tested.
 *
 * - bucket(): the part of an address the limit counts. One IPv6 subscriber usually gets a whole /64, so IPv6
 *   addresses count by their /64; an IPv4-mapped IPv6 address counts as the IPv4 address.
 * - proxied(): whether a request looks like it came through a proxy or CDN that WordPress hasn't been told about. Only
 *   the Site Health check uses it; the limit itself never trusts forwarding headers, which anyone can send.
 */
final class ClientAddress {

	/** Request headers ($_SERVER names) that proxies and CDNs use to report the visitor's address. */
	const HEADERS = array(
		'HTTP_X_FORWARDED_FOR',
		'HTTP_FORWARDED',
		'HTTP_X_REAL_IP',
		'HTTP_CF_CONNECTING_IP',
		'HTTP_TRUE_CLIENT_IP',
		'HTTP_FASTLY_CLIENT_IP',
		'HTTP_X_CLIENT_IP',
		'HTTP_X_CLUSTER_CLIENT_IP',
		'HTTP_X_AZURE_CLIENTIP',
	);

	/**
	 * The address, or the network, that the rate limit counts.
	 *
	 * @param string $ip Visitor IP address.
	 * @return string The IPv4 address, or the IPv6 /64 as "2001:db8:1:2::/64"; empty when it isn't an IP address.
	 */
	public static function bucket( string $ip ): string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return '';
		}
		if ( 16 === strlen( $packed ) ) {
			if ( str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
				return (string) inet_ntop( substr( $packed, 12 ) );
			}
			return inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) ) . '/64';
		}
		return (string) inet_ntop( $packed );
	}

	/**
	 * Does the request carry forwarding headers while REMOTE_ADDR is none of the addresses they report? That is a
	 * proxy or CDN in front of WordPress, and REMOTE_ADDR is the proxy's address, shared by every visitor. When
	 * REMOTE_ADDR is one of the reported addresses, something (often a line in wp-config.php) has already put the
	 * visitor's address there.
	 *
	 * @param array<string,string> $server REMOTE_ADDR and any of self::HEADERS, as in $_SERVER.
	 * @return bool
	 */
	public static function proxied( array $server ): bool {
		$present  = false;
		$reported = array();
		foreach ( self::HEADERS as $name ) {
			$value = trim( (string) ( $server[ $name ] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			$present  = true;
			$reported = array_merge( $reported, self::reported( $name, $value ) );
		}
		if ( ! $present ) {
			return false;
		}
		$remote = self::canonical( (string) ( $server['REMOTE_ADDR'] ?? '' ) );
		return '' === $remote || ! in_array( $remote, $reported, true );
	}

	/**
	 * Addresses in one forwarding header.
	 *
	 * @param string $name  Header ($_SERVER name).
	 * @param string $value Header value.
	 * @return string[] Canonical addresses.
	 */
	private static function reported( string $name, string $value ): array {
		$out = array();
		foreach ( explode( ',', $value ) as $part ) {
			$part = trim( $part );
			// The standard Forwarded header lists "for" parameters, quoted with brackets and a port for IPv6.
			if ( 'HTTP_FORWARDED' === $name ) {
				if ( ! preg_match( '/(?:^|;)\s*for\s*=\s*("?)([^;"]*)\1/i', $part, $match ) ) {
					continue;
				}
				$part = $match[2];
			}
			$address = self::canonical( self::without_port( $part ) );
			if ( '' !== $address ) {
				$out[] = $address;
			}
		}
		return $out;
	}

	/**
	 * An address without brackets or a port ("[2001:db8::1]:80", "192.0.2.1:80").
	 *
	 * @param string $address Address.
	 * @return string
	 */
	private static function without_port( string $address ): string {
		if ( preg_match( '/^\[([^\]]+)\](?::\d+)?$/', $address, $match ) ) {
			return $match[1];
		}
		if ( 1 === substr_count( $address, ':' ) ) {
			return (string) strstr( $address, ':', true );
		}
		return $address;
	}

	/**
	 * One spelling per address, so "2001:DB8::0:1" and "2001:db8::1" compare equal.
	 *
	 * @param string $ip Address.
	 * @return string Canonical address, or empty when it isn't one.
	 */
	private static function canonical( string $ip ): string {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return '';
		}
		$packed = inet_pton( $ip );
		return false === $packed ? '' : (string) inet_ntop( $packed );
	}
}
