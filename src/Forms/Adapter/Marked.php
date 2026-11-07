<?php
/**
 * Forms a theme or plugin marks for Glaciate.
 *
 * @package Glaciate
 */

namespace Glaciate\Forms\Adapter;

use Glaciate\Forms\Config;
use Glaciate\Forms\Result;
use Glaciate\Forms\Submission;

/**
 * Any form with `data-glaciate-form="<name>"` (lowercase letters, digits, "-" and "_"): its submissions are emailed
 * to the forms address (the `forms_email` setting, else the site's admin email). A field named `email` with a valid
 * address becomes the Reply-To.
 */
final class Marked implements AdapterInterface {

	/**
	 * Forms configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Forms configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Kind.
	 *
	 * @return string
	 */
	public function kind(): string {
		return 'form';
	}

	/**
	 * Recognizes `data-glaciate-form`.
	 *
	 * @param array{attrs:array<string,string>,hidden:array<string,string>,names:string[]} $form Form.
	 * @return string|null
	 */
	public function detect( array $form ): ?string {
		$name = $form['attrs']['data-glaciate-form'] ?? '';
		return preg_match( '/^[a-z0-9_-]{1,40}$/', $name ) ? 'form:' . $name : null;
	}

	/**
	 * Emails the submission.
	 *
	 * @param Submission $submission Submission.
	 * @return Result
	 */
	public function handle( Submission $submission ): Result {
		$pairs = $submission->visible_pairs();
		if ( ! $pairs ) {
			return new Result( Result::INVALID, __( 'Please fill in the form before sending it.', 'glaciate' ) );
		}
		$lines = array();
		foreach ( $pairs as $pair ) {
			$lines[] = $pair[0] . ': ' . $pair[1];
		}
		$body  = implode( "\n", $lines ) . "\n\n";
		$body .= sprintf(
			/* translators: 1: form name, 2: page address. */
			__( 'Sent with the "%1$s" form on %2$s', 'glaciate' ),
			$submission->id,
			esc_url_raw( $submission->page )
		) . "\n";

		$headers = array();
		$reply   = isset( $submission->fields['email'] ) && is_string( $submission->fields['email'] ) ? sanitize_email( $submission->fields['email'] ) : '';
		if ( '' !== $reply && is_email( $reply ) ) {
			$headers[] = 'Reply-To: ' . $reply;
		}
		$subject = sprintf(
			/* translators: 1: site name, 2: form name. */
			__( '[%1$s] New message from the %2$s form', 'glaciate' ),
			wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ),
			$submission->id
		);
		if ( ! wp_mail( $this->config->email(), $subject, $body, $headers ) ) {
			return Result::error();
		}
		return Result::sent();
	}
}
