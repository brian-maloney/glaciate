<?php
/**
 * Thrown when a build is asked for while publishing is paused.
 *
 * @package Glaciate
 */

namespace Glaciate\Build;

defined( 'ABSPATH' ) || exit;

/**
 * Publishing is paused (Service\PauseService), so no build was started. The message is plain text for the person
 * who asked.
 */
final class PausedException extends \RuntimeException {
}
