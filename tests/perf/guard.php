<?php
/**
 * Stops the benchmark scripts anywhere but the wp-env tests site, whose content they erase.
 *
 * @package Stillpress
 */

if ( 'http://localhost:8889' !== untrailingslashit( home_url() ) ) {
	WP_CLI::error( 'Refusing to run against ' . home_url() . '; the benchmark only runs on the wp-env tests site.' );
}
