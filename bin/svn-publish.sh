#!/bin/sh
# Publishes to the WordPress.org plugin directory's Subversion repository (DECISIONS.md D50).
#
#   bin/svn-publish.sh release <svn-url> <version> <plugin-dir> [<assets-dir>]
#       Mirrors <plugin-dir> (the release ZIP's unzipped glaciate/ folder) into trunk/, copies trunk/ to
#       tags/<version>/ and mirrors <assets-dir> into assets/, all in one commit. Refuses when tags/<version> already
#       exists, or when the plugin's Version header or readme.txt's Stable tag isn't <version>.
#
#   bin/svn-publish.sh meta <svn-url> <readme> [<assets-dir>]
#       Replaces readme.txt in trunk/ and in tags/<Stable tag>/ and mirrors <assets-dir> into assets/, without
#       touching code: for a "Tested up to" bump or new screenshots between releases. Refuses unless tags/<Stable tag>
#       exists and trunk's readme.txt names the same Stable tag.
#
# A missing or empty <assets-dir> leaves assets/ alone. SVN_USERNAME and SVN_PASSWORD authenticate the commit; the
# password reaches svn on stdin, never in its arguments. SVN_DRY_RUN=1 prints what would be committed instead.
set -eu

usage() {
	sed -n '3,15p' "$0" | sed 's/^# \{0,1\}//' >&2
	exit 2
}

fail() {
	echo "svn-publish: $*" >&2
	exit 1
}

# Value of readme.txt's Stable tag.
stable_tag() {
	sed -n 's/^Stable tag:[[:space:]]*//p' "$1" | head -n 1 | tr -d '[:space:]'
}

# Makes the versioned folder $2 hold exactly what $1 holds, and schedules the additions and deletions.
mirror() {
	rsync -a --delete --exclude=.svn "$1"/ "$2"/
	# --no-ignore: svn's default global-ignores would silently skip some names a plugin may ship.
	svn add -q --force --no-ignore --depth infinity "$2"
	# Deepest paths first, so a removed folder's contents are removed before the folder itself.
	svn status "$2" | sed -n 's/^!       //p' | sort -r | while IFS= read -r path; do
		svn rm -q --force "$path@"
	done
}

# Gives directory images the MIME type browsers need to show them instead of downloading them.
set_mime_types() {
	find "$1" -type f ! -path '*/.svn/*' | while IFS= read -r file; do
		case "$file" in
			*.png) type=image/png ;;
			*.jpg | *.jpeg) type=image/jpeg ;;
			*.gif) type=image/gif ;;
			*.svg) type=image/svg+xml ;;
			*) continue ;;
		esac
		svn propset -q svn:mime-type "$type" "$file@"
	done
}

commit() {
	if [ "${SVN_DRY_RUN:-}" = 1 ]; then
		echo "Dry run; would commit \"$1\":"
		svn status "$wc"
		return
	fi
	if [ -z "$(svn status "$wc")" ]; then
		echo "Nothing to commit."
		return
	fi
	if [ -n "${SVN_USERNAME:-}" ]; then
		printf '%s' "${SVN_PASSWORD:-}" | svn commit -q --non-interactive --no-auth-cache -m "$1" \
			--username "$SVN_USERNAME" --password-from-stdin "$wc"
	else
		svn commit -q --non-interactive -m "$1" "$wc"
	fi
	echo "Committed \"$1\": r$(svn info --show-item last-changed-revision "$url")"
}

[ $# -ge 3 ] || usage
mode=$1
url=${2%/}

wc=$(mktemp -d "${TMPDIR:-/tmp}/glaciate-svn.XXXXXX")
trap 'rm -rf "$wc"' EXIT

case "$mode" in
	release)
		[ $# -ge 4 ] || usage
		version=$3
		plugin=$4
		assets=${5:-}
		echo "$version" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$' || fail "\"$version\" isn't a release version (x.y.z)."
		for file in glaciate.php readme.txt; do
			[ -f "$plugin/$file" ] || fail "$plugin has no $file; pass the plugin folder."
		done
		header=$(sed -n 's/^ \* Version:[[:space:]]*//p' "$plugin/glaciate.php" | head -n 1 | tr -d '[:space:]')
		[ "$header" = "$version" ] || fail "glaciate.php says Version $header, not $version."
		[ "$(stable_tag "$plugin/readme.txt")" = "$version" ] || fail "readme.txt's Stable tag isn't $version."
		if svn info "$url/tags/$version" > /dev/null 2>&1; then
			fail "tags/$version already exists; a released version is never replaced."
		fi

		svn checkout -q --depth immediates "$url" "$wc"
		svn update -q --set-depth infinity "$wc/trunk" "$wc/assets"
		mirror "$plugin" "$wc/trunk"
		svn copy -q "$wc/trunk" "$wc/tags/$version"
		message="Release $version"
		;;
	meta)
		readme=$3
		assets=${4:-}
		[ -f "$readme" ] || fail "$readme doesn't exist."
		stable=$(stable_tag "$readme")
		[ -n "$stable" ] || fail "$readme has no Stable tag."
		svn info "$url/tags/$stable" > /dev/null 2>&1 || fail "tags/$stable doesn't exist; release it first."

		svn checkout -q --depth immediates "$url" "$wc"
		svn update -q --set-depth files "$wc/trunk"
		svn update -q --set-depth infinity "$wc/assets"
		svn update -q --parents --set-depth files "$wc/tags/$stable"
		[ "$(stable_tag "$wc/trunk/readme.txt")" = "$stable" ] ||
			fail "trunk's readme.txt names a different Stable tag; publish code with a release instead."
		cp "$readme" "$wc/trunk/readme.txt"
		cp "$readme" "$wc/tags/$stable/readme.txt"
		message="Update readme and assets for $stable"
		;;
	*)
		usage
		;;
esac

if [ -n "$assets" ] && [ -d "$assets" ] && [ -n "$(ls -A "$assets")" ]; then
	mirror "$assets" "$wc/assets"
	set_mime_types "$wc/assets"
fi

commit "$message"
