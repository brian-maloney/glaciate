<?php
/**
 * Blanks WordPress nonces in inline script settings.
 *
 * @package Glaciate
 */

namespace Glaciate\Rewrite;

/**
 * Anonymous renders still print nonces: core's `wp.apiFetch.createNonceMiddleware( "…" )`, `wpApiSettings.nonce`,
 * and the `*_nonce` keys plugins pass through `wp_localize_script()`. A nonce changes every twelve hours, so a page
 * that carries one hashes differently on every full rebuild and is deployed again although nothing changed. On a
 * static site the nonce has no use (there is no REST API or admin-ajax.php to send it to), so it is emptied.
 *
 * Only `<script>` contents are touched, and only a value of exactly ten lowercase hex characters (the shape of
 * `wp_create_nonce()`) under a key or variable whose name contains "nonce". Form fields are left alone: forms are
 * handled by their adapters (D22).
 *
 * Pure; no WordPress calls.
 */
final class NonceStripper {

	/**
	 * Empties nonce values in the inline scripts of an HTML document.
	 *
	 * @param string $html Document.
	 * @return string
	 */
	public static function strip( string $html ): string {
		if ( false === stripos( $html, 'nonce' ) ) {
			return $html;
		}
		$out = preg_replace_callback(
			'#(<script\b[^>]*>)(.*?)(</script\s*>)#is',
			static function ( array $m ): string {
				return $m[1] . self::strip_script( $m[2] ) . $m[3];
			},
			$html
		);
		return null === $out ? $html : $out;
	}

	/**
	 * Empties nonce values in one script's text.
	 *
	 * @param string $script Script text.
	 * @return string
	 */
	public static function strip_script( string $script ): string {
		if ( false === stripos( $script, 'nonce' ) ) {
			return $script;
		}
		$patterns = array(
			// wp.apiFetch.createNonceMiddleware( "0123456789" ).
			'#(createNonceMiddleware\(\s*)(\\\\?["\'])[0-9a-f]{10}\2#',
			// "nonce":"…", 'rest_nonce': '…', my_nonce = "…", and the same inside a JSON string (\"nonce\":\"…\").
			'#((?:\\\\?["\'])?[A-Za-z0-9_$\-]*(?i:nonce)[A-Za-z0-9_$\-]*(?:\\\\?["\'])?\s*[:=]\s*)(\\\\?["\'])[0-9a-f]{10}\2#',
		);
		$out      = preg_replace( $patterns, '$1$2$2', $script );
		return null === $out ? $script : $out;
	}
}
