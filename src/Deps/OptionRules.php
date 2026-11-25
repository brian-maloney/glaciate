<?php
/**
 * Which options count as dependencies.
 *
 * @package Glaciate
 */

namespace Glaciate\Deps;

defined( 'ABSPATH' ) || exit;

use Glaciate\Support\Settings;

/**
 * Classifies options for dependency tracking.
 *
 * - Ignored options are never recorded and never trigger rebuilds (caches, cron, transients, our own state).
 * - Global options change how every URL renders or where it lives; changing one triggers a full rebuild.
 * - Everything else is recorded per URL and rebuilds exactly the URLs that read it.
 *
 * The plugin's own settings (glaciate_settings) fall under the ignored "glaciate_" prefix, because the option is
 * read on every request and would otherwise be recorded as a per-URL dependency. ChangeListener watches it on its
 * own hooks and asks settings_change_is_global() whether a save calls for a full build.
 */
final class OptionRules {

	/**
	 * Settings that only decide when builds run and how fast, not what they publish or where. Changing only these
	 * starts no build.
	 *
	 * @var string[]
	 */
	const SCHEDULE_SETTINGS = array( 'publish_mode', 'publish_times', 'publish_days', 'full_rebuild', 'full_rebuild_time', 'full_rebuild_day', 'concurrency', 'connect_to' );

	/**
	 * Is this option ignored?
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_ignored( string $name ): bool {
		static $exact = null;
		if ( null === $exact ) {
			$list = array( 'cron', 'doing_cron', 'rewrite_rules', 'hack_file', 'db_version', 'initial_db_version', 'db_upgraded', 'recently_activated', 'recently_edited', 'auto_updater.lock', 'core_updater.lock', 'can_compress_scripts', 'theme_switched', 'theme_switched_via_customizer', 'fresh_site', 'wp_user_roles', 'user_count', 'finished_splitting_shared_terms', 'auto_core_update_notified', 'upload_space_check_disabled', 'active_sitewide_plugins', 'https_detection_errors', 'wp_attachment_pages_enabled' );

			/**
			 * Filters option names that are never recorded as dependencies and never trigger rebuilds.
			 *
			 * @since 0.1.0
			 *
			 * @param string[] $list Exact option names.
			 */
			$exact = array_flip( (array) apply_filters( 'glaciate_ignored_options', $list ) );
		}
		if ( isset( $exact[ $name ] ) ) {
			return true;
		}
		return (bool) preg_match( '/^(_transient_|_site_transient_|glaciate_|action_scheduler|schema-ActionScheduler|as_|_wp_session_)/', $name );
	}

	/**
	 * Does changing this option require a full rebuild?
	 *
	 * @param string $name Option name.
	 * @return bool
	 */
	public static function is_global( string $name ): bool {
		static $global = null;
		if ( null === $global ) {
			$list = array( 'siteurl', 'home', 'permalink_structure', 'category_base', 'tag_base', 'template', 'stylesheet', 'active_plugins', 'WPLANG', 'blog_charset', 'timezone_string', 'gmt_offset', 'date_format', 'time_format' );

			/**
			 * Filters option names whose change triggers a full rebuild.
			 *
			 * @since 0.1.0
			 *
			 * @param string[] $list Exact option names.
			 */
			$global = array_flip( (array) apply_filters( 'glaciate_global_options', $list ) );
		}
		return isset( $global[ $name ] );
	}

	/**
	 * Does a change to the plugin's settings require a full rebuild? True when any setting other than the schedule
	 * ones (SCHEDULE_SETTINGS) differs: exclusions, feeds, redirects, the public address, search, forms and the
	 * target all change which files are published, what they contain or where they go.
	 *
	 * @param mixed $old_value Stored value before the change (false or a non-array when there was none).
	 * @param mixed $new_value Stored value after the change.
	 * @return bool
	 */
	public static function settings_change_is_global( $old_value, $new_value ): bool {
		return self::comparable_settings( $old_value ) !== self::comparable_settings( $new_value );
	}

	/**
	 * Settings as compared by settings_change_is_global(): known keys only, defaults filled in, schedule keys dropped.
	 *
	 * @param mixed $value Stored value.
	 * @return array<string,mixed>
	 */
	private static function comparable_settings( $value ): array {
		$defaults = Settings::defaults();
		$settings = array_merge( $defaults, array_intersect_key( is_array( $value ) ? $value : array(), $defaults ) );
		$settings = array_diff_key( $settings, array_flip( self::SCHEDULE_SETTINGS ) );
		ksort( $settings );
		return $settings;
	}
}
