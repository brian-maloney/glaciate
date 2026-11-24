<?php
/**
 * Ownership rules for the Git deployer's temporary upload branches.
 *
 * @package Glaciate
 */

namespace Glaciate\Deploy\Git;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which temporary upload branch a Git deploy may build on or delete (threat T16, DECISIONS.md D65).
 *
 * The deployer counts an upload branch as its own only while the server lists it at a commit ID the deployer recorded
 * for a push of its own. The server's listing alone proves nothing: anyone who may push to the repository can create a
 * branch of any name. A new upload goes to a fresh name with a random part that the server doesn't list, and its first
 * push creates it (old ID all zeros), which the server refuses if the name was taken in the meantime. Every later move
 * or delete names the recorded ID as the old one, so the server refuses it if the branch moved since.
 *
 * Pure logic: no WordPress calls, so it is unit tested.
 */
final class UploadBranch {

	/** Every upload branch's name starts with this. */
	const PREFIX = 'refs/heads/glaciate-upload-';

	/** Leftover upload branches remembered for deletion at most. */
	const MAX_STALE = 10;

	/**
	 * Picks the upload branch for a deploy: the recorded one when the server still holds a commit we recorded on it
	 * (an interrupted upload resumes), otherwise a fresh name the server doesn't list, with no head.
	 *
	 * The recorded upload is the branch, the last commit the server confirmed on it, and a commit pushed without an
	 * answer (it counts too: the server holds it only if our push reached it).
	 *
	 * @param array<string,mixed>  $state  Recorded upload: upload_ref, upload_head, upload_next.
	 * @param array<string,string> $refs   Ref name => commit ID, as the server lists them.
	 * @param string               $key    Target key (hex); its first eight characters go into a fresh name.
	 * @param callable             $random Returns a random hex string for a fresh name.
	 * @return array{upload_ref:string,upload_head:string,upload_next:string,resume:bool}
	 */
	public static function choose( array $state, array $refs, string $key, callable $random ): array {
		$ref    = (string) ( $state['upload_ref'] ?? '' );
		$remote = self::has_current_format( $ref ) ? (string) ( $refs[ $ref ] ?? '' ) : '';
		$ours   = array_filter( array( (string) ( $state['upload_head'] ?? '' ), (string) ( $state['upload_next'] ?? '' ) ) );
		if ( '' !== $remote && in_array( $remote, $ours, true ) ) {
			return array(
				'upload_ref'  => $ref,
				'upload_head' => $remote,
				'upload_next' => '',
				'resume'      => true,
			);
		}
		// Not ours, or gone: leave whatever the server lists alone and start on a name it doesn't list.
		$attempts = 0;
		do {
			$name = self::PREFIX . substr( $key, 0, 8 ) . '-' . preg_replace( '/[^0-9a-f]/', '', strtolower( (string) call_user_func( $random ) ) );
			++$attempts;
		} while ( isset( $refs[ $name ] ) && $attempts < 100 );
		return array(
			'upload_ref'  => $name,
			'upload_head' => '',
			'upload_next' => '',
			'resume'      => false,
		);
	}

	/**
	 * True for a name in the format this version picks: the prefix, eight hex characters of the target key, a dash and a random hex
	 * part. Earlier versions used no random part and took the head from the server's listing, so a record of
	 * such a name proves nothing.
	 *
	 * @param string $ref Ref name.
	 * @return bool
	 */
	public static function has_current_format( string $ref ): bool {
		return 1 === preg_match( '#^' . preg_quote( self::PREFIX, '#' ) . '[0-9a-f]{8}-[0-9a-f]{8,}$#', $ref );
	}

	/**
	 * Picks the leftover upload branches that may be deleted: recorded ones the server still lists at the recorded
	 * commit. Records of branches that are gone or have moved are dropped by the caller, since they are no longer ours.
	 *
	 * @param array<string,string> $stale Ref name => recorded commit ID.
	 * @param array<string,string> $refs  Ref name => commit ID, as the server lists them.
	 * @return array<string,string> Ref name => commit ID to name as the old ID in the delete.
	 */
	public static function deletable( array $stale, array $refs ): array {
		$out = array();
		foreach ( $stale as $ref => $head ) {
			$ref  = (string) $ref;
			$head = (string) $head;
			if ( self::has_current_format( $ref ) && 1 === preg_match( '/^[0-9a-f]{40}$/', $head ) && ( $refs[ $ref ] ?? '' ) === $head ) {
				$out[ $ref ] = $head;
			}
		}
		return $out;
	}

	/**
	 * Adds a branch the server refused to delete to the records, keeping the newest MAX_STALE.
	 *
	 * @param array<string,string> $stale Ref name => recorded commit ID.
	 * @param string               $ref   Ref name.
	 * @param string               $head  Commit ID it holds.
	 * @return array<string,string>
	 */
	public static function remember( array $stale, string $ref, string $head ): array {
		unset( $stale[ $ref ] );
		$stale[ $ref ] = $head;
		return array_slice( $stale, -self::MAX_STALE, null, true );
	}
}
