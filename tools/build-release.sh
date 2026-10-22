#!/usr/bin/env bash
#
# Build the release archive build/jamfsync-<version>.tar.gz from the working tree.
# It contains only what GLPI needs at runtime (plus README, CHANGELOG and LICENSE):
# no node_modules, Vue sources, tests or development tooling.
#
#   tools/build-release.sh            # rebuilds the JS bundle first (npm ci && npm run build)
#   SKIP_BUILD=1 tools/build-release.sh

set -euo pipefail

PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)
cd "$PLUGIN_DIR"

VERSION=$(sed -n "s/^define('PLUGIN_JAMFSYNC_VERSION', '\([^']*\)');/\1/p" setup.php)
[ -n "$VERSION" ] || { echo "Cannot read PLUGIN_JAMFSYNC_VERSION from setup.php" >&2; exit 1; }

if [ "${SKIP_BUILD:-0}" != "1" ]; then
    npm ci --no-audit --no-fund
    npm run build
fi

# Compiled translations must match their sources.
for po in locales/*.po; do
    php tools/po2mo.php "$po" "${po%.po}.mo" >/dev/null
done

RUNTIME=(setup.php hook.php composer.json README.md CHANGELOG.md LICENSE front install locales public src templates)
for entry in "${RUNTIME[@]}"; do
    [ -e "$entry" ] || { echo "Missing $entry" >&2; exit 1; }
done

STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT
mkdir -p "$STAGE/jamfsync" build
tar -cf - --exclude='src/vue' "${RUNTIME[@]}" | tar -xf - -C "$STAGE/jamfsync"

ARCHIVE="build/jamfsync-$VERSION.tar.gz"
tar -czf "$ARCHIVE" -C "$STAGE" --owner=0 --group=0 jamfsync
(cd build && sha256sum "jamfsync-$VERSION.tar.gz" > "jamfsync-$VERSION.tar.gz.sha256")
echo "Built $ARCHIVE ($(du -h "$ARCHIVE" | cut -f1))"
