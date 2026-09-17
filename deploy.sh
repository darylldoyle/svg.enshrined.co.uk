#!/usr/bin/env bash
#
# Forge deploy script. Point the site's web directory at /public and paste this
# into the deployment script box, or call it from there.
#
# The only thing it builds is vendor/ for each version that git brought in;
# nothing here talks to Packagist.

set -euo pipefail

cd "$(dirname "$0")"

git pull origin "${FORGE_SITE_BRANCH:-main}"

# Installs any version whose vendor/ is missing, then probes every version on
# this host's PHP and rewrites storage/versions.json.
"${FORGE_PHP:-php}" bin/sync-versions.php --install

if command -v sudo >/dev/null 2>&1 && [ -n "${FORGE_PHP_FPM:-}" ]; then
    sudo -S service "$FORGE_PHP_FPM" reload < /dev/null || true
fi
