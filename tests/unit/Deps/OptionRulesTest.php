<?php
/**
 * Which settings changes call for a full rebuild.
 *
 * @package Glaciate
 */

namespace Glaciate\Tests\Unit\Deps;

use PHPUnit\Framework\TestCase;
use Glaciate\Deps\OptionRules;
use Glaciate\Support\Settings;

final class OptionRulesTest extends TestCase {

	public function test_unchanged_settings_are_not_global(): void {
		$settings = Settings::defaults();
		$this->assertFalse( OptionRules::settings_change_is_global( $settings, $settings ) );
	}

	public function test_no_stored_settings_equal_the_defaults(): void {
		$this->assertFalse( OptionRules::settings_change_is_global( false, Settings::defaults() ) );
		$this->assertFalse( OptionRules::settings_change_is_global( array(), array( 'feeds' => 'main' ) ) );
	}

	public function test_settings_that_change_the_output_are_global(): void {
		$before = Settings::defaults();
		foreach ( array(
			'exclude'    => array( '/sample-page/' ),
			'feeds'      => 'none',
			'redirects'  => array(
				array(
					'from' => '/a/',
					'to'   => '/b/',
					'code' => 301,
				),
			),
			'public_url' => 'https://static.example.org',
			'search'     => 'off',
			'target'     => 'zip',
		) as $key => $value ) {
			$after         = $before;
			$after[ $key ] = $value;
			$this->assertTrue( OptionRules::settings_change_is_global( $before, $after ), $key );
		}
		$this->assertTrue( OptionRules::settings_change_is_global( false, array( 'exclude' => array( '/x/' ) ) ) );
	}

	public function test_schedule_only_changes_are_not_global(): void {
		$before = Settings::defaults();
		$after  = array_merge(
			$before,
			array(
				'publish_mode'      => 'manual',
				'publish_times'     => array( '12:00' ),
				'publish_days'      => array( 'mon' ),
				'full_rebuild'      => 'weekly',
				'full_rebuild_time' => '04:00',
				'full_rebuild_day'  => 'sat',
				'concurrency'       => 8,
				'connect_to'        => '127.0.0.1:8080',
			)
		);
		$this->assertFalse( OptionRules::settings_change_is_global( $before, $after ) );
	}

	public function test_unknown_keys_and_key_order_are_ignored(): void {
		$before = Settings::defaults();
		$after  = array_reverse( $before, true );

		$after['auto_publish'] = true;
		$this->assertFalse( OptionRules::settings_change_is_global( $before, $after ) );
	}
}
