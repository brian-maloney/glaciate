#!/bin/sh
# Builds the release ZIP from the committed tree: the files git archive keeps (see .gitattributes export-ignore),
# plus runtime Composer dependencies installed from composer.lock, with phpseclib and its dependencies moved under
# the Stillpress\Vendor\ namespace by Strauss (DECISIONS.md D29). Output: build/stillpress-<version>.zip
#
# Uncommitted changes are not included. Composer runs in the wp-env cli container (no PHP on the host).
set -e
cd "$(dirname "$0")/.."
rev="${1:-HEAD}"
version=$(git show "$rev:stillpress.php" | sed -n 's/^ \* Version: *//p' | tr -d '[:space:]')
[ -n "$version" ] || { echo "No Version header in stillpress.php at $rev" >&2; exit 1; }

rm -rf build/stillpress build/tools
mkdir -p build
git archive --format=tar --prefix=stillpress/ "$rev" | tar -x -C build
git show "$rev:composer.lock" > build/stillpress/composer.lock
# The prefixer is its own Composer project, never shipped; take it from the same commit (export-ignore keeps it out
# of git archive).
mkdir -p build/tools/strauss
for file in composer.json composer.lock strauss.php; do
	git show "$rev:tools/strauss/$file" > "build/tools/strauss/$file"
done
npx wp-env run cli --env-cwd=wp-content/plugins/stillpress/build/tools/strauss \
	composer install --no-interaction --no-progress --prefer-dist --quiet
npx wp-env run cli --env-cwd=wp-content/plugins/stillpress/build/stillpress \
	composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --quiet
npx wp-env run cli --env-cwd=wp-content/plugins/stillpress/build/stillpress php ../tools/strauss/strauss.php
npx wp-env run cli --env-cwd=wp-content/plugins/stillpress/build/stillpress \
	composer dump-autoload --no-dev --no-scripts --optimize --quiet
# Strauss's development-only aliases for the old names would bring the collision back; nothing loads them, but
# leave them out anyway.
rm -rf build/stillpress/composer.lock build/stillpress/vendor/composer/autoload_aliases.php build/tools
# Libraries' own build scripts and signing keys are of no use in a plugin (random_compat is empty on PHP 7+).
find build/stillpress/vendor \( -name '*.sh' -o -name '*.phar' -o -name '*.pubkey' -o -name '*.pubkey.asc' \) -delete

zip="build/stillpress-$version.zip"
rm -f "$zip"
(cd build && zip -qr -X "stillpress-$version.zip" stillpress)
echo "$zip ($(du -h "$zip" | cut -f1), $(unzip -l "$zip" | tail -1 | awk '{print $2}') files)"
