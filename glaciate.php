<?php
/**
 * Plugin Name:       Glaciate
 * Plugin URI:        https://github.com/brian-maloney/glaciate
 * Description:       Publish your WordPress site as fast, secure static files. Every feature is free.
 * Version:           1.1.1
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Author:            Glaciate contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       glaciate
 *
 * @package Glaciate
 */

defined( 'ABSPATH' ) || exit;

define( 'GLACIATE_VERSION', '1.1.1' );
define( 'GLACIATE_FILE', __FILE__ );
define( 'GLACIATE_DIR', __DIR__ );

if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			esc_html_e( 'Glaciate is missing its dependencies. Run "composer install" in the plugin directory, or install a release build.', 'glaciate' );
			echo '</p></div>';
		}
	);
	return;
}

require_once __DIR__ . '/vendor/autoload.php';

// Action Scheduler picks the newest bundled copy across all plugins, so loading it here is safe.
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

register_activation_hook( __FILE__, array( \Glaciate\Plugin::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Glaciate\Plugin::class, 'deactivate' ) );

\Glaciate\Plugin::instance()->boot();
