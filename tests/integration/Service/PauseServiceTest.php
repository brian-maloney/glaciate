<?php
/**
 * Pausing publishing holds every build until it resumes (D67).
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Integration\Service;

use Glaciate\Build\BuildService;
use Glaciate\Build\PausedException;
use Glaciate\Build\Queue;
use Glaciate\Service\PauseService;
use Glaciate\Service\StatusService;
use Glaciate\Storage\BuildRepository;
use Glaciate\Storage\ChangeRepository;
use Glaciate\Support\Settings;

final class PauseServiceTest extends \WP_UnitTestCase {

	/**
	 * Pause service.
	 *
	 * @var PauseService
	 */
	private $pause;

	public function set_up(): void {
		parent::set_up();
		add_filter( 'glaciate_kick_builds', '__return_false' );
		$this->pause = new PauseService();
		update_option( 'glaciate_published_once', 1 );
		Settings::update(
			array(
				'publish_mode' => 'immediate',
				'full_rebuild' => 'daily',
			)
		);
		// The settings change above is a real site change: write it out, then start from no pending changes.
		$listener = \Glaciate\Plugin::instance()->changes();
		if ( null !== $listener ) {
			$listener->flush();
		}
		( new ChangeRepository() )->flush();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			Queue::unschedule_all();
		}
	}

	public function tear_down(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			Queue::unschedule_all();
		}
		parent::tear_down();
	}

	private function require_action_scheduler(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! \ActionScheduler::is_initialized() ) {
			$this->markTestSkipped( 'Action Scheduler is not loaded.' );
		}
	}

	private function next_scheduled( string $hook ): ?int {
		$next = as_next_scheduled_action( $hook, array(), Queue::GROUP );
		return is_int( $next ) ? $next : null;
	}

	public function test_pause_records_who_and_when_and_resume_clears_it(): void {
		$user = self::factory()->user->create(
			array(
				'role'         => 'editor',
				'display_name' => 'Edna Editor',
			)
		);
		$this->assertNull( $this->pause->state() );

		$this->assertTrue( $this->pause->pause( $user ) );
		$state = $this->pause->state();
		$this->assertSame( $user, $state['user_id'] );
		$this->assertSame( 'Edna Editor', $state['user'] );
		$this->assertEqualsWithDelta( time(), $state['since'], 5 );
		$this->assertFalse( $this->pause->pause( $user ), 'Pausing twice keeps the first pause.' );

		$this->assertTrue( $this->pause->resume( $user ) );
		$this->assertFalse( $this->pause->is_paused() );
		$this->assertFalse( $this->pause->resume( $user ), 'Resuming twice does nothing.' );
	}

	public function test_pause_and_resume_fire_their_actions(): void {
		$paused  = new \MockAction();
		$resumed = new \MockAction();
		add_action( 'glaciate_publishing_paused', array( $paused, 'action' ) );
		add_action( 'glaciate_publishing_resumed', array( $resumed, 'action' ) );

		$this->pause->pause( 7 );
		$this->pause->pause( 7 );
		$this->pause->resume( 8 );

		$this->assertSame( 1, $paused->get_call_count() );
		$this->assertSame( array( 7 ), $paused->get_args()[0] );
		$this->assertSame( 1, $resumed->get_call_count() );
		$this->assertSame( array( 8 ), $resumed->get_args()[0] );
	}

	public function test_paused_option_is_not_a_settings_change(): void {
		$this->pause->pause( 0 );
		$listener = \Glaciate\Plugin::instance()->changes();
		if ( null !== $listener ) {
			$listener->flush();
		}
		$this->assertSame( 0, ( new ChangeRepository() )->pending_count(), 'Pausing must not queue a rebuild of its own.' );
	}

	public function test_no_build_starts_while_paused(): void {
		$this->pause->pause( 0 );
		foreach ( array( 'full', 'incremental', 'urls' ) as $kind ) {
			try {
				( new BuildService() )->start( $kind, 'manual', 0, true, array( 'urls' => array( home_url( '/' ) ) ) );
				$this->fail( "A {$kind} build started while paused." );
			} catch ( PausedException $e ) {
				$this->assertStringContainsString( 'paused', $e->getMessage() );
			}
		}
		$this->assertNull( ( new BuildRepository() )->active() );
	}

	public function test_ignore_pause_builds_anyway(): void {
		$this->pause->pause( 0 );
		$id = ( new BuildService() )->start(
			'full',
			'cli',
			0,
			false,
			array(
				'ignore_pause' => true,
				'sync'         => true,
			)
		);
		$this->assertSame( $id, ( new BuildRepository() )->active()['id'] );
		$this->assertTrue( $this->pause->is_paused(), 'Building past the pause does not resume it.' );
	}

	public function test_automatic_and_scheduled_runs_are_skipped_while_paused(): void {
		$this->require_action_scheduler();
		$this->pause->pause( 0 );
		( new ChangeRepository() )->record( 'post', '1' );

		Queue::auto_publish();
		Queue::scheduled_rebuild();
		Settings::update( array( 'publish_mode' => 'scheduled' ) );
		Queue::publish_window();

		$this->assertNull( ( new BuildRepository() )->active() );
		$this->assertSame( 1, ( new ChangeRepository() )->pending_count(), 'The change waits for resume.' );
		$this->assertNotNull( $this->next_scheduled( Queue::SCHEDULED_HOOK ), 'The next full rebuild is still armed.' );
		$this->assertNotNull( $this->next_scheduled( Queue::WINDOW_HOOK ), 'The next publishing time is still armed.' );
	}

	public function test_changes_while_paused_schedule_nothing_and_resume_publishes_them(): void {
		$this->require_action_scheduler();
		( new ChangeRepository() )->record( 'post', '1' );
		Queue::changes_recorded();
		$this->assertNotNull( $this->next_scheduled( Queue::AUTO_HOOK ) );

		$this->pause->pause( 0 );
		$this->assertNull( $this->next_scheduled( Queue::AUTO_HOOK ), 'Pausing drops the debounced publish.' );
		( new ChangeRepository() )->record( 'post', '2' );
		Queue::changes_recorded();
		$this->assertNull( $this->next_scheduled( Queue::AUTO_HOOK ), 'No wake-up is scheduled while paused.' );

		$this->pause->resume( 0 );
		$this->assertNotNull( $this->next_scheduled( Queue::AUTO_HOOK ), 'Resuming publishes what waited.' );
	}

	public function test_resume_in_manual_mode_starts_nothing(): void {
		$this->require_action_scheduler();
		Settings::update( array( 'publish_mode' => 'manual' ) );
		$this->pause->pause( 0 );
		( new ChangeRepository() )->record( 'post', '1' );
		$this->pause->resume( 0 );
		$this->assertNull( $this->next_scheduled( Queue::AUTO_HOOK ) );
		$this->assertNull( ( new BuildRepository() )->active() );
	}

	public function test_status_reports_the_pause(): void {
		( new ChangeRepository() )->record( 'post', '1' );
		( new ChangeRepository() )->record( 'post', '2' );
		$this->pause->pause( 0 );

		$status = ( new StatusService() )->site();
		$this->assertSame( 'paused', $status['state'] );
		$this->assertSame( 'Publishing paused, 2 changes waiting', $status['label'] );
		$this->assertSame( 0, $status['paused']['user_id'] );

		$post = self::factory()->post->create();
		$this->assertSame( 'paused', ( new StatusService() )->post( $post )['state'] );

		$this->pause->resume( 0 );
		$status = ( new StatusService() )->site();
		$this->assertSame( 'pending', $status['state'] );
		$this->assertNull( $status['paused'] );
	}

	public function test_a_running_build_shows_as_building_while_paused(): void {
		( new BuildService() )->start( 'full', 'manual', 0, false, array( 'sync' => true ) );
		$this->pause->pause( 0 );
		$status = ( new StatusService() )->site();
		$this->assertSame( 'building', $status['state'] );
		$this->assertNotNull( $status['paused'] );
	}
}
