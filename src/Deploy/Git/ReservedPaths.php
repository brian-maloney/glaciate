<?php
/**
 * Paths the Git deployer never commits.
 *
 * @package Glaciate
 */

namespace Glaciate\Deploy\Git;

defined( 'ABSPATH' ) || exit;

/**
 * Recognises paths that Git or a Git host treats as repository configuration rather than as site content (threat T27).
 *
 * A file under `.github/workflows/` on the published branch is a GitHub Actions workflow that runs on push with the
 * repository's token; `.gitlab-ci.yml`, `.forgejo/workflows/` and `.gitea/workflows/` do the same on other hosts;
 * `.gitattributes` and `.gitmodules` change how the branch is checked out or cloned. A path with a `.git` segment is
 * refused by servers that check pushes with `git fsck` (GitHub does), so every later deploy would fail.
 *
 * `PathMapper` already refuses names that start with a dot (except `.well-known`), so these normally never reach a
 * manifest. This list is the Git deployer's own guard, which also holds for paths a `glaciate_manifest` filter adds
 * and for names `PathMapper` allows but Git reads as `.git`: Windows short names such as `git~1`. It matches what
 * Git itself treats as the same name (`is_hfs_dotgit()` and `is_ntfs_dotgit()` in Git's path.c): any case, with
 * trailing dots or spaces, and with the code points macOS ignores in file names.
 *
 * Pure: no WordPress calls.
 */
final class ReservedPaths {

	/** Code points HFS+ ignores when it compares names, so ".g\u{200C}it" is ".git" on macOS (Git strips them too). */
	const HFS_IGNORABLE = '/[\x{200C}-\x{200F}\x{202A}-\x{202E}\x{206A}-\x{206F}\x{FEFF}]/u';

	/**
	 * Returns true when any segment of a path is a reserved name.
	 *
	 * @param string $path Relative path, with "/" separators ("\" counts as one too, as on Windows).
	 * @return bool
	 */
	public static function is_reserved( string $path ): bool {
		foreach ( (array) preg_split( '#[/\\\\]#', $path ) as $segment ) {
			if ( self::is_reserved_name( (string) $segment ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns true for a file or folder name that Git or a Git host reads as repository configuration.
	 *
	 * Reserved: every name starting with `.git` (`.git`, `.github`, `.gitlab`, `.gitlab-ci.yml`, `.gitattributes`,
	 * `.gitmodules`, `.gitea` and the rest: none is site content); `.forgejo`; and Windows 8.3 short names of such
	 * names (`git~1`, `gitmod~1`, `gi7eba~1`), which Git on Windows and `git fsck` treat as the long name.
	 *
	 * @param string $name One path segment.
	 * @return bool
	 */
	public static function is_reserved_name( string $name ): bool {
		$plain = preg_replace( self::HFS_IGNORABLE, '', $name );
		// Not valid UTF-8: compare the bytes as they are.
		$plain = strtolower( rtrim( null === $plain ? $name : $plain, '. ' ) );
		if ( 0 === strpos( $plain, '.git' ) || '.forgejo' === $plain ) {
			return true;
		}
		// Short names: up to six characters, "~" and a number. Git's own aliases start with "gi" (git~1, gitmod~1,
		// gitatt~1, and hash forms such as gi7eba~1 for .gitmodules).
		return 1 === preg_match( '/^gi[0-9a-z]{1,4}~[0-9]+$/', $plain );
	}
}
