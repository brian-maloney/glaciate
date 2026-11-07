#!/bin/sh
# Builds the release ZIP from the committed tree: the files git archive keeps (see .gitattributes export-ignore),
# plus runtime Composer dependencies installed from composer.lock, with phpseclib and its dependencies moved under
# the Glaciate\Vendor\ namespace by Strauss (DECISIONS.md D29). Output: build/glaciate-<version>.zip
#
# Uncommitted changes are not included. Composer runs in the wp-env cli container (no PHP on the host).
set -e
cd "$(dirname "$0")/.."
rev="${1:-HEAD}"
version=$(git show "$rev:glaciate.php" | sed -n 's/^ \* Version: *//p' | tr -d '[:space:]')
[ -n "$version" ] || { echo "No Version header in glaciate.php at $rev" >&2; exit 1; }

rm -rf build/glaciate build/tools
mkdir -p build
git archive --format=tar --prefix=glaciate/ "$rev" | tar -x -C build
git show "$rev:composer.lock" > build/glaciate/composer.lock
# The prefixer is its own Composer project, never shipped; take it from the same commit (export-ignore keeps it out
# of git archive).
mkdir -p build/tools/strauss
for file in composer.json composer.lock strauss.php; do
	git show "$rev:tools/strauss/$file" > "build/tools/strauss/$file"
done
npx wp-env run cli --env-cwd=wp-content/plugins/glaciate/build/tools/strauss \
	composer install --no-interaction --no-progress --prefer-dist --quiet
npx wp-env run cli --env-cwd=wp-content/plugins/glaciate/build/glaciate \
	composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist --quiet
npx wp-env run cli --env-cwd=wp-content/plugins/glaciate/build/glaciate php ../tools/strauss/strauss.php
npx wp-env run cli --env-cwd=wp-content/plugins/glaciate/build/glaciate \
	composer dump-autoload --no-dev --no-scripts --optimize --quiet
# Strauss's development-only aliases for the old names would bring the collision back; nothing loads them, but
# leave them out anyway.
rm -rf build/glaciate/composer.lock build/glaciate/vendor/composer/autoload_aliases.php build/tools
# Libraries' own build scripts and signing keys are of no use in a plugin (random_compat is empty on PHP 7+).
find build/glaciate/vendor \( -name '*.sh' -o -name '*.phar' -o -name '*.pubkey' -o -name '*.pubkey.asc' \) -delete

zip="build/glaciate-$version.zip"
rm -f "$zip"
(cd build && zip -qr -X "glaciate-$version.zip" glaciate)
echo "$zip ($(du -h "$zip" | cut -f1), $(unzip -l "$zip" | tail -1 | awk '{print $2}') files)"
