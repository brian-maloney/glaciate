<?php
/**
 * Signatures on forwarded submissions and webhooks.
 *
 * @package Glaciate
 */

namespace Glaciate\Forms;

defined( 'ABSPATH' ) || exit;

/**
 * Signs a request body with the forms secret: header `X-Glaciate-Signature: t=<unix time>,v1=<hex>`, where v1 is
 * HMAC-SHA256 over "<t>.<body>". The time binds the signature to a five-minute window; the relay also remembers
 * signatures it has accepted (ReplayGuard), so a captured request can't be replayed inside that window. Only the exact
 * spelling sign() writes verifies, so a captured header can't be respelled into a second, unseen one (D64).
 *
 * The standalone receivers sign the same way (tests/fixtures/forms-cases.json has shared vectors).
 */
final class Signature {

	/** Request header. */
	const HEADER = 'X-Glaciate-Signature';

	/** Seconds a signature stays valid either side of its time. */
	const TOLERANCE = 300;

	/**
	 * Signs a body.
	 *
	 * @param string $body   Request body.
	 * @param string $secret Shared secret.
	 * @param int    $time   Unix time.
	 * @return string Header value.
	 */
	public static function sign( string $body, string $secret, int $time ): string {
		return 't=' . $time . ',v1=' . hash_hmac( 'sha256', $time . '.' . $body, $secret );
	}

	/**
	 * Reads a header in exactly the form sign() writes: no surrounding whitespace, no leading zeros in the time, lower
	 * case hex. Every other spelling is refused, so one signature has one spelling, and the parsed values name it for
	 * the relay's once-only check.
	 *
	 * @param string $header Header value.
	 * @return array{0:int,1:string}|null Time and HMAC, or null when the header isn't in that form.
	 */
	public static function parse( string $header ): ?array {
		// \z, not $: $ also matches before a trailing newline.
		if ( ! preg_match( '/^t=([1-9][0-9]{0,11}),v1=([0-9a-f]{64})\z/', $header, $m ) ) {
			return null;
		}
		return array( (int) $m[1], $m[2] );
	}

	/**
	 * Checks a signature.
	 *
	 * @param string $header Header value, in the form parse() accepts.
	 * @param string $body   Request body.
	 * @param string $secret Shared secret.
	 * @param int    $now    Current Unix time.
	 * @return bool
	 */
	public static function verify( string $header, string $body, string $secret, int $now ): bool {
		$parsed = self::parse( $header );
		if ( '' === $secret || null === $parsed ) {
			return false;
		}
		list( $time, $mac ) = $parsed;
		if ( abs( $now - $time ) > self::TOLERANCE ) {
			return false;
		}
		return hash_equals( hash_hmac( 'sha256', $time . '.' . $body, $secret ), $mac );
	}
}
