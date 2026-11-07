<?php
/**
 * Capabilities.
 *
 * @package Glaciate
 */

namespace Glaciate\Security;

/**
 * Defines the plugin's capabilities.
 *
 * - `glaciate_manage`  (primitive): change settings and deploy targets.
 * - `glaciate_publish` (primitive): start, cancel and download builds.
 * - `glaciate_publish_post` (meta, takes a post ID): publish one post's pages; requires `glaciate_publish`
 *   and the ability to edit that post.
 *
 * Primitive capabilities are granted to roles once per site (administrator: both; editor: publish), on activation
 * or, if activation never ran, on the first request after it; role editors can move them around after that.
 */
final class Capabilities {

	const MANAGE       = 'glaciate_manage';
	const PUBLISH      = 'glaciate_publish';
	const PUBLISH_POST = 'glaciate_publish_post';

	/**
	 * Option set once the default capabilities have been granted on a site.
	 */
	const GRANTED = 'glaciate_capabilities_granted';

	/**
	 * Registers the meta capability mapping.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'map_meta_cap', array( self::class, 'map_meta_cap' ), 10, 4 );
	}

	/**
	 * Maps `glaciate_publish_post` to primitive capabilities.
	 *
	 * @param string[] $caps    Primitive caps required.
	 * @param string   $cap     Capability being checked.
	 * @param int      $user_id User ID.
	 * @param mixed[]  $args    Extra arguments; the first is the post ID.
	 * @return string[]
	 */
	public static function map_meta_cap( $caps, $cap, $user_id, $args ) {
		if ( self::PUBLISH_POST !== $cap ) {
			return $caps;
		}
		$post_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $post_id <= 0 || ! get_post( $post_id ) ) {
			return array( 'do_not_allow' );
		}
		return array_merge( array( self::PUBLISH ), map_meta_cap( 'edit_post', $user_id, $post_id ) );
	}

	/**
	 * Grants primitive capabilities to the default roles on the current site.
	 *
	 * @return void
	 */
	public static function grant_defaults(): void {
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::MANAGE );
			$admin->add_cap( self::PUBLISH );
		}
		$editor = get_role( 'editor' );
		if ( $editor ) {
			$editor->add_cap( self::PUBLISH );
		}
		update_option( self::GRANTED, 1, true );
	}

	/**
	 * Grants the default capabilities if this site has never had them.
	 *
	 * Activation doesn't run when the plugin was switched on before its dependencies were installed (glaciate.php
	 * stops early without vendor/, so the hook isn't registered). The tables are then created on the next request, but
	 * administrators would be left without the capabilities the admin screens need. Granting only once means ones an
	 * administrator removed later stay removed.
	 *
	 * @return void
	 */
	public static function maybe_grant_defaults(): void {
		if ( ! get_option( self::GRANTED ) ) {
			self::grant_defaults();
		}
	}

	/**
	 * Removes the primitive capabilities from every role on the current site.
	 *
	 * @return void
	 */
	public static function revoke_all(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			$role->remove_cap( self::MANAGE );
			$role->remove_cap( self::PUBLISH );
		}
		delete_option( self::GRANTED );
	}
}
