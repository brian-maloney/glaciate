<?php
/**
 * Runs form plugin code as if the visitor had posted to this site.
 *
 * @package Glaciate
 */

namespace Glaciate\Forms\Adapter;

use Glaciate\Forms\Submission;

/**
 * Form plugins read `$_POST` and `$_SERVER` directly. This sets them from a submission for the length of a callback:
 * the submitted fields, the visitor's IP address and User-Agent (which matter when a separate receiver forwarded the
 * submission), and no logged-in user, so nothing is attributed to an account.
 *
 * WordPress adds slashes to these arrays when it starts (wp_magic_quotes()) and plugins remove them from what they
 * read, so the values are set slashed too. Without that a plugin would strip the backslashes a visitor typed.
 *
 * Afterwards it puts back the `$_SERVER` entries it changed and the current user, and empties `$_POST` and
 * `$_REQUEST`. It never copies a whole superglobal: see DECISIONS.md D46 and D47.
 */
final class Request {

	/**
	 * Calls a function with the request set up from a submission.
	 *
	 * @param Submission          $submission Submission.
	 * @param array<string,mixed> $post       Fields for `$_POST`, as the visitor sent them (without slashes).
	 * @param callable            $callback   Function to run.
	 * @return mixed What the callback returned.
	 */
	public static function run( Submission $submission, array $post, callable $callback ) {
		$server = array(
			'REQUEST_METHOD'  => 'POST',
			'HTTP_USER_AGENT' => $submission->user_agent,
		);
		if ( '' !== $submission->ip ) {
			$server['REMOTE_ADDR'] = $submission->ip;
		}
		if ( '' !== $submission->page ) {
			$server['HTTP_REFERER'] = $submission->page;
		}
		$saved = array_intersect_key( self::server_values(), $server );
		$user  = get_current_user_id();

		foreach ( $server as $name => $value ) {
			$_SERVER[ $name ] = wp_slash( $value );
		}
		$_POST    = wp_slash( $post );
		$_REQUEST = wp_slash( $post );
		wp_set_current_user( 0 );
		try {
			return $callback();
		} finally {
			$_POST    = array();
			$_REQUEST = array();
			foreach ( $saved as $name => $value ) {
				if ( null === $value ) {
					unset( $_SERVER[ $name ] );
				} else {
					$_SERVER[ $name ] = wp_slash( $value );
				}
			}
			wp_set_current_user( $user );
		}
	}

	/**
	 * The current values of the `$_SERVER` entries a submission can replace, unslashed and sanitized; null for an
	 * entry that isn't set.
	 *
	 * @return array<string,string|null>
	 */
	private static function server_values(): array {
		return array(
			'REQUEST_METHOD'  => isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : null,
			'HTTP_USER_AGENT' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : null,
			'REMOTE_ADDR'     => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : null,
			// Not sanitize_text_field(), which removes percent-encoded characters.
			'HTTP_REFERER'    => isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_url( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : null,
		);
	}
}
