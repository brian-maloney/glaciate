<?php
/**
 * A build that stops before publishing hands its claimed changes back (D68).
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Build;

use Glaciate\Build\BuildService;
use Glaciate\Build\Queue;
use Glaciate\Deps\Invalidator;
use Glaciate\Storage\BuildRepository;
use Glaciate\Storage\ChangeRepository;
use Glaciate\Storage\Schema;
use Glaciate\Support\Settings;

final class ReleasedChangesTest extends \WP_UnitTestCase {

	/** @var ChangeRepository */
	private $changes;

	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		add_filter( 'glaciate_kick_builds', '__return_false' );
		$listener = \Glaciate\Plugin::instance()->changes();
		if ( null !== $listener ) {
			$listener->flush();
		}
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', Schema::table( 'changes' ) ) );
		$this->changes = new ChangeRepository();
	}

	public function tear_down(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			Queue::unschedule_all();
		}
		parent::tear_down();
	}

	/**
	 * Keys of the pending changes, oldest first.
	 *
	 * @return string[]
	 */
	private function pending_keys(): array {
		return wp_list_pluck( $this->changes->peek(), 'object_key' );
	}

	private function start_incremental(): int {
		return ( new BuildService() )->start( 'incremental', 'manual', 0, false, array( 'sync' => true ) );
	}

	public function test_a_cancelled_incremental_build_hands_its_changes_back(): void {
		$this->changes->record( 'option', 'blogname' );
		$this->changes->record( 'option', 'blogdescription' );
		$id = $this->start_incremental();

		$plan = ( new Invalidator() )->plan( $id );
		$this->assertSame( 2, $plan['changes'] );
		$this->assertSame( 0, $this->changes->pending_count() );

		$this->assertSame( $id, ( new BuildService() )->cancel() );
		$this->assertSame( array( 'blogname', 'blogdescription' ), $this->pending_keys() );
	}

	public function test_changes_recorded_during_the_build_keep_their_order_and_others_builds_keep_theirs(): void {
		$builds = new BuildRepository();
		$this->changes->record( 'option', 'published_earlier' );
		$done = $builds->create( 'incremental', 'manual', 0, false );
		$this->changes->claim( $done );
		$builds->transition( $done, 'queued', 'done' );

		$this->changes->record( 'option', 'first' );
		$id = $this->start_incremental();
		( new Invalidator() )->plan( $id );
		$this->changes->record( 'option', 'during' );

		( new BuildService() )->cancel();
		$this->assertSame( array( 'first', 'during' ), $this->pending_keys(), 'The published change stays with its build.' );
	}

	public function test_a_build_upgraded_to_full_hands_back_everything_it_claimed(): void {
		$this->changes->record( 'full', 'theme' );
		$this->changes->record( 'option', 'blogname' );
		$id = $this->start_incremental();

		$this->assertTrue( ( new Invalidator() )->plan( $id )['full'] );
		// The runner switches the build to full and plans again, which consumes what arrived meanwhile.
		( new BuildRepository() )->update( $id, array( 'kind' => 'full' ) );
		$this->changes->record( 'option', 'blogdescription' );
		( new Invalidator() )->consume_all( $id );
		$this->assertSame( 0, $this->changes->pending_count() );

		( new BuildService() )->cancel();
		$this->assertSame( array( 'theme', 'blogname', 'blogdescription' ), $this->pending_keys() );
		$this->assertTrue( ( new Invalidator() )->affected( $this->changes->peek() )['full'], 'The next build is full again.' );
	}

	public function test_a_claim_made_while_the_cancel_comes_is_handed_back_by_the_runner(): void {
		$this->changes->record( 'option', 'blogname' );
		$id     = $this->start_incremental();
		$builds = new BuildRepository();
		// Another process cancels after the slice checked the status and before it claims: cancel() finds nothing.
		$cancel = static function ( $ids ) use ( $builds, $id ) {
			$builds->transition( $id, 'running', 'cancelled' );
			return $ids;
		};
		add_filter( 'glaciate_affected_url_ids', $cancel );
		$build = ( new BuildService() )->run_sync( $id );
		remove_filter( 'glaciate_affected_url_ids', $cancel );

		$this->assertSame( 'cancelled', $build['status'] );
		$this->assertSame( array( 'blogname' ), $this->pending_keys() );
	}

	public function test_a_failed_build_hands_its_changes_back(): void {
		$this->changes->record( 'option', 'blogname' );
		$id   = $this->start_incremental();
		$fail = static function () {
			throw new \RuntimeException( 'Planning broke.' );
		};
		add_filter( 'glaciate_affected_url_ids', $fail );
		$build = ( new BuildService() )->run_sync( $id );
		remove_filter( 'glaciate_affected_url_ids', $fail );

		$this->assertSame( 'failed', $build['status'] );
		$this->assertSame( array( 'blogname' ), $this->pending_keys() );
	}

	public function test_a_finished_build_keeps_its_changes(): void {
		$this->changes->record( 'option', 'blogname' );
		$build = ( new BuildService() )->run_sync( $this->start_incremental() );

		$this->assertSame( 'done', $build['status'] );
		$this->assertSame( 0, $this->changes->pending_count() );
	}

	public function test_immediate_publishing_reschedules_after_a_cancel_but_not_after_a_failure(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! \ActionScheduler::is_initialized() ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
		Settings::update( array( 'publish_mode' => 'immediate' ) );
		update_option( 'glaciate_published_once', 1, false );
		Queue::unschedule_all();

		$this->changes->record( 'option', 'blogname' );
		$id = $this->start_incremental();
		( new Invalidator() )->plan( $id );
		( new BuildService() )->cancel();
		$this->assertIsInt( as_next_scheduled_action( Queue::AUTO_HOOK, array(), Queue::GROUP ) );

		Queue::unschedule_all();
		$fail = static function () {
			throw new \RuntimeException( 'Planning broke.' );
		};
		add_filter( 'glaciate_affected_url_ids', $fail );
		$build = ( new BuildService() )->run_sync( $this->start_incremental() );
		remove_filter( 'glaciate_affected_url_ids', $fail );

		$this->assertSame( 'failed', $build['status'] );
		$this->assertSame( 1, $this->changes->pending_count() );
		$this->assertFalse( as_next_scheduled_action( Queue::AUTO_HOOK, array(), Queue::GROUP ), 'A failing build is not retried every minute.' );
	}
}
