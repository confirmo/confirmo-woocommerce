#!/usr/bin/env bash
#
# Runs the suite inside a WordPress container that has WooCommerce and
# WooCommerce Subscriptions active.
#
#   tests/run.sh                          # everything
#   tests/run.sh --testsuite invoicing    # one suite
#   tests/run.sh --filter Webhook         # one test
#
# The container name defaults to confirmo-wp; override it with
# CONFIRMO_WP_CONTAINER. The plugin path inside the container is derived from
# WordPress itself, so a differently named plugin directory still works.
#
# To run against a WordPress you host yourself instead of a container, call
# PHPUnit directly with WP_ROOT pointing at it:
#
#   WP_ROOT=/srv/www/wordpress phpunit -c phpunit.xml.dist

set -euo pipefail

CONTAINER="${CONFIRMO_WP_CONTAINER:-confirmo-wp}"
PHPUNIT_VERSION="9.6.36"
PHAR_IN_CONTAINER="/tmp/phpunit.phar"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    cat >&2 <<EOF
Container '$CONTAINER' is not running.

The suite needs a WordPress with WooCommerce and WooCommerce Subscriptions
active. Start yours, then either name it CONFIRMO_WP_CONTAINER=<name>, or run
PHPUnit directly against a non-containerised install:

  WP_ROOT=/path/to/wordpress phpunit -c phpunit.xml.dist
EOF
    exit 1
fi

if ! docker exec "$CONTAINER" test -f "$PHAR_IN_CONTAINER"; then
    echo "Fetching PHPUnit ${PHPUNIT_VERSION} into $CONTAINER…"
    docker exec "$CONTAINER" sh -c \
        "curl -sSL -o $PHAR_IN_CONTAINER https://phar.phpunit.de/phpunit-${PHPUNIT_VERSION}.phar" \
        || { echo "Could not download PHPUnit into the container." >&2; exit 1; }
fi

# Ask WordPress where its plugins live rather than assuming the layout.
PLUGIN_DIR="$(docker exec "$CONTAINER" sh -c \
    'php -r "define(\"WP_USE_THEMES\", false); require \"/var/www/html/wp-load.php\"; echo WP_PLUGIN_DIR;" 2>/dev/null' \
    | tail -1)/$(basename "$ROOT")"

if [ -z "${PLUGIN_DIR%/*}" ]; then
    echo "Could not determine the plugin directory inside '$CONTAINER'." >&2
    exit 1
fi

# The plugin under test is the working tree, not whatever the container last had.
# Docker Desktop bind mounts do not propagate in-place edits, so it is copied.
docker exec "$CONTAINER" mkdir -p "$PLUGIN_DIR"
for path in confirmo-payment-gateway.php includes public languages phpunit.xml.dist; do
    [ -e "$ROOT/$path" ] && docker cp "$ROOT/$path" "$CONTAINER:$PLUGIN_DIR/" >/dev/null
done

# Cleared first: docker cp overwrites but never deletes, so a renamed or removed
# test would keep running from the container's copy.
docker exec "$CONTAINER" rm -rf "$PLUGIN_DIR/tests"
docker cp "$ROOT/tests" "$CONTAINER:$PLUGIN_DIR/" >/dev/null
docker exec "$CONTAINER" chown -R www-data:www-data "$PLUGIN_DIR"

exec docker exec -w "$PLUGIN_DIR" "$CONTAINER" \
    php "$PHAR_IN_CONTAINER" --configuration "$PLUGIN_DIR/phpunit.xml.dist" "$@"
