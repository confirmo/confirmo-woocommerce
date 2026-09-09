#!/usr/bin/env bash
#
# Runs the suite against a WordPress with WooCommerce active.
#
#   tests/run.sh                          # everything
#   tests/run.sh --testsuite invoicing    # one suite
#   tests/run.sh --filter Webhook         # one test
#
# From a clean checkout this needs nothing but Docker: it builds its own
# throwaway WordPress the first time (tests/env/up.sh) and reuses it after.
#
# WooCommerce Subscriptions is a paid extension and cannot be installed for you,
# so the Subscribe tests skip unless you supply a copy — see tests/README.md.
#
# CONFIRMO_WP_CONTAINER points at a WordPress container you already have. To run
# against a WordPress you host yourself, call PHPUnit directly instead:
#
#   WP_ROOT=/srv/www/wordpress phpunit -c phpunit.xml.dist

set -euo pipefail

CONTAINER="${CONFIRMO_WP_CONTAINER:-confirmo-tests-wp}"
PHPUNIT_VERSION="9.6.36"
PHAR_IN_CONTAINER="/tmp/phpunit.phar"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    if [ -n "${CONFIRMO_WP_CONTAINER:-}" ]; then
        echo "Container '$CONTAINER' is not running." >&2
        exit 1
    fi

    echo "No test environment yet — building one."
    "$ROOT/tests/env/up.sh"
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

# The bootstrap loads the plugin through WordPress, so it has to be active.
# Provisioning uses the wordpress:cli service; a container someone brought
# themselves may have wp-cli inside it instead.
PLUGIN_SLUG="$(basename "$ROOT")"
if docker ps --format '{{.Names}}' | grep -qx confirmo-tests-cli; then
    docker exec confirmo-tests-cli \
        wp --path=/var/www/html plugin activate "$PLUGIN_SLUG" >/dev/null 2>&1 || true
elif docker exec "$CONTAINER" test -f /usr/local/bin/wp 2>/dev/null; then
    docker exec -u www-data "$CONTAINER" \
        wp --path=/var/www/html plugin activate "$PLUGIN_SLUG" >/dev/null 2>&1 || true
fi

exec docker exec -w "$PLUGIN_DIR" "$CONTAINER" \
    php "$PHAR_IN_CONTAINER" --configuration "$PLUGIN_DIR/phpunit.xml.dist" "$@"
