<?php
/**
 * What the next "Publish changes" would do, shown in the admin, REST API and WP-CLI.
 *
 * @package Stillpress
 */

namespace Stillpress\Service;

use Stillpress\Build\Pipeline;
use Stillpress\Deps\Invalidator;
use Stillpress\Storage\ChangeRepository;
use Stillpress\Storage\UrlRepository;

/**
 * Previews the pending changeset without claiming it: the changed content, whether it forces a full rebuild, and the
 * addresses an incremental build would render.
 */
final class ChangesetService {

	/** Pending changes read for one preview. Beyond this the preview says it is incomplete. */
	const MAX_CHANGES = 5000;

	/**
	 * The preview.
	 *
	 * @param int $limit Most changed items and addresses to list.
	 * @return array{pending:int,complete:bool,full:bool,items:array<int,array{type:string,key:string,label:string,link:string}>,item_count:int,pages:int,urls:string[]}
	 */
	public function preview( int $limit = 50 ): array {
		$repo    = new ChangeRepository();
		$pending = $repo->pending_count();
		$changes = $pending > 0 ? $repo->peek( self::MAX_CHANGES ) : array();
		$plan    = ( new Invalidator() )->affected( $changes );

		$items = array();
		foreach ( array_reverse( $changes ) as $change ) {
			$id = $change['object_type'] . ':' . $change['object_key'];
			if ( ! isset( $items[ $id ] ) ) {
				$items[ $id ] = $change;
			}
		}
		$count = count( $items );
		$list  = array();
		foreach ( array_slice( $items, 0, $limit ) as $change ) {
			$list[] = self::describe( (string) $change['object_type'], (string) $change['object_key'] );
		}

		$urls = array();
		if ( ! $plan['full'] ) {
			$rows = ( new UrlRepository() )->get_many( $plan['url_ids'] );
			foreach ( $rows as $row ) {
				$urls[ (string) $row['url'] ] = true;
			}
			foreach ( $plan['urls'] as $url ) {
				$urls[ $url ] = true;
			}
		}
		$rewriter = $urls ? Pipeline::for_site()->rewriter : null;
		$public   = array();
		foreach ( array_slice( array_keys( $urls ), 0, $limit ) as $url ) {
			$public[] = $rewriter->rewrite_url( (string) $url );
		}

		return array(
			'pending'    => $pending,
			'complete'   => $pending <= self::MAX_CHANGES,
			'full'       => $plan['full'],
			'items'      => $list,
			'item_count' => $count,
			'pages'      => count( $urls ),
			'urls'       => $public,
		);
	}

	/**
	 * A readable name for one changed object, and where to edit it.
	 *
	 * @param string $type Change type.
	 * @param string $key  Change key.
	 * @return array{type:string,key:string,label:string,link:string}
	 */
	public static function describe( string $type, string $key ): array {
		$label = $key;
		$link  = '';
		switch ( $type ) {
			case 'post':
				$post = get_post( (int) $key );
				if ( $post instanceof \WP_Post ) {
					$object = get_post_type_object( $post->post_type );
					$title  = '' === $post->post_title ? __( '(no title)', 'stillpress' ) : $post->post_title;
					/* translators: 1: post type name, such as "Page", 2: post title. */
					$label = sprintf( _x( '%1$s: %2$s', 'changed post', 'stillpress' ), $object ? $object->labels->singular_name : $post->post_type, $title );
					$link  = (string) get_edit_post_link( $post->ID, 'raw' );
				} else {
					/* translators: %s: post ID. */
					$label = sprintf( __( 'Deleted content (ID %s)', 'stillpress' ), $key );
				}
				break;
			case 'term':
				$term = get_term( (int) $key );
				if ( $term instanceof \WP_Term ) {
					$taxonomy = get_taxonomy( $term->taxonomy );
					/* translators: 1: taxonomy name, such as "Category", 2: term name. */
					$label = sprintf( _x( '%1$s: %2$s', 'changed term', 'stillpress' ), $taxonomy ? $taxonomy->labels->singular_name : $term->taxonomy, $term->name );
					$link  = (string) get_edit_term_link( $term );
				} else {
					/* translators: %s: term ID. */
					$label = sprintf( __( 'Deleted term (ID %s)', 'stillpress' ), $key );
				}
				break;
			case 'term_count':
				$taxonomy = get_taxonomy( $key );
				/* translators: %s: taxonomy name, such as "Categories". */
				$label = sprintf( __( 'Post counts of %s', 'stillpress' ), $taxonomy ? $taxonomy->labels->name : $key );
				break;
			case 'user':
				$user = get_userdata( (int) $key );
				/* translators: %s: user's display name, or their ID if the user was deleted. */
				$label = sprintf( __( 'Author: %s', 'stillpress' ), $user ? $user->display_name : $key );
				$link  = $user ? (string) get_edit_user_link( $user->ID ) : '';
				break;
			case 'comment':
				$comment = get_comment( (int) $key );
				$post    = $comment ? get_post( (int) $comment->comment_post_ID ) : null;
				/* translators: %s: title of the post the comment belongs to. */
				$label = $post ? sprintf( __( 'Comment on %s', 'stillpress' ), $post->post_title ) : __( 'Comment', 'stillpress' );
				break;
			case 'option':
				/* translators: %s: option name, such as "blogname". */
				$label = sprintf( __( 'Site setting: %s', 'stillpress' ), $key );
				break;
			case 'full':
				$label = self::full_reason( $key );
				break;
		}//end switch
		return array(
			'type'  => $type,
			'key'   => $key,
			'label' => $label,
			'link'  => $link,
		);
	}

	/**
	 * Why a change rebuilds every page.
	 *
	 * @param string $key Change key, such as "code" or "option:permalink_structure".
	 * @return string
	 */
	private static function full_reason( string $key ): string {
		if ( 'code' === $key ) {
			return __( 'Theme or plugins changed: every page is rebuilt', 'stillpress' );
		}
		if ( 0 === strpos( $key, 'option:' ) ) {
			/* translators: %s: option name, such as "permalink_structure". */
			return sprintf( __( 'Site setting %s changed: every page is rebuilt', 'stillpress' ), substr( $key, 7 ) );
		}
		if ( 0 === strpos( $key, 'term-in-permalinks:' ) ) {
			return __( 'A term in the permalink structure was renamed: every page is rebuilt', 'stillpress' );
		}
		if ( 0 === strpos( $key, 'author-in-permalinks:' ) ) {
			return __( 'An author in the permalink structure was renamed: every page is rebuilt', 'stillpress' );
		}
		return __( 'Every page is rebuilt', 'stillpress' );
	}
}
