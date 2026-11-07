<?php
/**
 * Removes the plugin's data when it is deleted from the Plugins screen.
 *
 * Deployed static sites are never touched: they belong to the site owner now.
 *
 * @package Glaciate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
	require_once __DIR__ . '/vendor/autoload.php';
	\Glaciate\Uninstaller::run();
}
