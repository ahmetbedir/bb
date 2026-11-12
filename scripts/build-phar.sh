#!/usr/bin/env sh
#
# Build the bb PHAR without dev dependencies.
#
# box.json ships the whole vendor/ directory, and Box only drops dev packages
# when it discovers files itself, so Pest, Pint, Laravel PAO etc. would end up
# inside the release (bigger binary; PAO would even rewrite bb's output for
# agents). Install without dev packages, build, then restore the dev install,
# even if the build fails.
#
#   scripts/build-phar.sh <output name> <version>
#
set -eu

NAME="${1:?usage: scripts/build-phar.sh <output name> <version>}"
VERSION="${2:?usage: scripts/build-phar.sh <output name> <version>}"

cd "$(dirname "$0")/.."

restore() { composer install --no-interaction --quiet; }
trap restore EXIT

# The native macOS notifier can only be built on macOS; elsewhere bb falls
# back to JoliNotif for notifications.
if [ "$(uname)" = Darwin ]; then
    scripts/build-notifier.sh >/dev/null
else
    echo "warning: not on macOS, building without bb Notifier.app" >&2
fi

composer install --no-dev --no-interaction --quiet
php bb app:build "$NAME" --build-version="$VERSION" --no-interaction >/dev/null
