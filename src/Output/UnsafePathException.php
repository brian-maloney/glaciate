<?php
/**
 * Raised when a URL or path can't be mapped safely to a file.
 *
 * @package Glaciate
 */

namespace Glaciate\Output;

defined( 'ABSPATH' ) || exit;

/**
 * The message is safe to log: it never contains file contents.
 */
class UnsafePathException extends \RuntimeException {
}
