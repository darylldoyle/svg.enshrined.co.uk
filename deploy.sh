#!/usr/bin/env bash
#
# Reference deploy for a plain (non-atomic) Forge site. For zero-downtime
# deployments use the script in the README instead — the shape is different.
#
# The only thing this builds is the vendor tree for each version git brought in.
# Nothing here talks to Packagist; discovery happens in CI.

set -euo pipefail

cd "$(dirname "$0")"

git pull origin "${FORGE_SITE_BRANCH:-main}"

COMPOSER_BIN="${FORGE_COMPOSER:-composer}" "${FORGE_PHP:-php}" bin/sync-versions.php --install

if [ -n "${FORGE_PHP_FPM:-}" ]; then
    sudo -S service "$FORGE_PHP_FPM" reload < /dev/null || true
fi
