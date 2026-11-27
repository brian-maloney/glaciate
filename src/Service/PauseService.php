<?php
/**
 * Pausing and resuming publishing.
 *
 * @package Glaciate
 */

namespace Glaciate\Service;

defined( 'ABSPATH' ) || exit;

use Glaciate\Build\Queue;
use Glaciate\Storage\ChangeRepository;

/**
 * Holds every build while someone reworks the site, so half-finished changes never reach the static copy (D67).
 *
 * While paused, BuildService refuses to start builds (automatic, scheduled and manual alike) unless the caller passes
 * `ignore_pause`, which only WP-CLI offers. Changes are still recorded; resuming hands them back to the publishing
 * mode. A build that was already under way when publishing was paused finishes.
 *
 * The state is its own option, not a key of `glaciate_settings`: saving the settings records a full change (D55), and
 * editors, who may pause, can't save the settings.
 */
final class PauseService {

	/** Option holding `{since, user_id}` while paused and an empty array otherwise. */
	const OPTION = 'glaciate_paused';

	/**
	 * Is publishing paused?
	 *
	 * @return bool
	 */
	public function is_paused(): bool {
		return null !== $this->state();
	}

	/**
	 * Who paused publishing and when, or null when it isn't paused.
	 *
	 * @return array{since:int,user_id:int,user:string}|null
	 */
	public function state(): ?array {
		$stored = get_option( self::OPTION );
		if ( ! is_array( $stored ) || empty( $stored['since'] ) ) {
			return null;
		}
		$user_id = (int) ( $stored['user_id'] ?? 0 );
		$user    = $user_id > 0 ? get_userdata( $user_id ) : false;
		return array(
			'since'   => (int) $stored['since'],
			'user_id' => $user_id,
			'user'    => $user instanceof \WP_User ? $user->display_name : '',
		);
	}

	/**
	 * Pauses publishing.
	 *
	 * @param int $user_id User pausing it (0 for WP-CLI without --user).
	 * @return bool False when it was already paused.
	 */
	public function pause( int $user_id ): bool {
		if ( $this->is_paused() ) {
			return false;
		}
		update_option(
			self::OPTION,
			array(
				'since'   => time(),
				'user_id' => $user_id,
			),
			true
		);
		// A debounced publish may be waiting; it would be refused anyway, so it needn't wake the site.
		Queue::drop_auto_publish();

		/**
		 * Fires when publishing has been paused.
		 *
		 * @since 2.2.0
		 *
		 * @param int $user_id User who paused it (0 when unknown).
		 */
		do_action( 'glaciate_publishing_paused', $user_id );
		return true;
	}

	/**
	 * Resumes publishing. Changes recorded meanwhile go out the way the publishing mode says: a minute later
	 * (immediate), at the next publishing time (scheduled) or on the next Publish (manual).
	 *
	 * @param int $user_id User resuming it (0 for WP-CLI without --user).
	 * @return bool False when it wasn't paused.
	 */
	public function resume( int $user_id ): bool {
		if ( ! $this->is_paused() ) {
			return false;
		}
		// Kept as an empty array rather than deleted, so the autoloaded row saves a query on every request.
		update_option( self::OPTION, array(), true );
		if ( ( new ChangeRepository() )->pending_count() > 0 ) {
			Queue::changes_recorded();
		}

		/**
		 * Fires when publishing has been resumed.
		 *
		 * @since 2.2.0
		 *
		 * @param int $user_id User who resumed it (0 when unknown).
		 */
		do_action( 'glaciate_publishing_resumed', $user_id );
		return true;
	}
}
