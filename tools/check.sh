#!/usr/bin/env bash
#
# All quality checks of the plugin. PHP tools run in the official GLPI 12 image (PHP 8.5),
# so the host only needs docker, node/npm, curl and python3.
#
#   tools/check.sh            # unit tests, PHPStan, code style, ESLint, bundle up to date
#   tools/check.sh --e2e      # additionally the Docker end-to-end suite (tests/e2e/run.sh)
#
# GLPI_CORE_DIR: a GLPI checkout with Composer dev dependencies (PHPUnit, PHPStan,
# PHP-CS-Fixer); defaults to ../glpi-core next to the plugin.

set -euo pipefail

PLUGIN_DIR=$(cd "$(dirname "$0")/.." && pwd)
GLPI_CORE_DIR=${GLPI_CORE_DIR:-$PLUGIN_DIR/../glpi-core}
GLPI_IMAGE=${GLPI_IMAGE:-glpi/glpi:12.0.0}
cd "$PLUGIN_DIR"

[ -x "$GLPI_CORE_DIR/vendor/bin/phpstan" ] || {
    echo "No GLPI dev tools in $GLPI_CORE_DIR (set GLPI_CORE_DIR)." >&2
    exit 1
}
GLPI_CORE_DIR=$(cd "$GLPI_CORE_DIR" && pwd)

in_glpi() { # in_glpi <workdir> <command...>
    local workdir=$1
    shift
    docker run --rm --entrypoint php -u "$(id -u):$(id -g)" \
        -v "$GLPI_CORE_DIR:/plugins/glpi-core:ro" \
        -v "$PLUGIN_DIR:/var/www/glpi/plugins/jamfsync" \
        -w "$workdir" "$GLPI_IMAGE" "$@"
}

echo "== PHPUnit"
in_glpi /var/www/glpi/plugins/jamfsync /plugins/glpi-core/vendor/bin/phpunit -c phpunit.xml.dist

echo "== PHPStan"
in_glpi /var/www/glpi/plugins/jamfsync /plugins/glpi-core/vendor/bin/phpstan analyse --memory-limit=1G --no-progress

echo "== PHP-CS-Fixer"
in_glpi /var/www/glpi/plugins/jamfsync /plugins/glpi-core/vendor/bin/php-cs-fixer check --show-progress=none

echo "== ESLint and bundle"
[ -d node_modules ] || npm ci --no-audit --no-fund
npm run lint
cp public/js/dist/jamfsync-vue.js "${TMPDIR:-/tmp}/jamfsync-vue.js.committed"
npm run build >/dev/null
if ! cmp -s public/js/dist/jamfsync-vue.js "${TMPDIR:-/tmp}/jamfsync-vue.js.committed"; then
    echo "public/js/dist was out of date with src/vue (now rebuilt): commit the new bundle." >&2
    exit 1
fi

if [ "${1:-}" = "--e2e" ]; then
    echo "== End-to-end"
    tests/e2e/run.sh
fi

echo "All checks passed."
