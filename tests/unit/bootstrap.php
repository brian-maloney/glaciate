<?php
/**
 * Unit test bootstrap. Units under test must not need WordPress loaded.
 *
 * @package Glaciate
 */

// Every file under src/ stops unless WordPress loaded it (ABSPATH). The unit suite stands in for WordPress here.
defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- WordPress's own constant.

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';
