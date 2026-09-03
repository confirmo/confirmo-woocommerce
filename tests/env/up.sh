#!/usr/bin/env bash
#
# Builds the WordPress the test suite runs against: WordPress, WooCommerce, and
# WooCommerce Subscriptions if you supply it.
#
# WooCommerce Subscriptions is a paid extension, so it cannot be fetched for you.
# Point one of these at a copy and it gets installed:
#
#   WCS_ZIP=/path/to/woocommerce-subscriptions.zip tests/env/up.sh
#   WCS_ZIP_URL=https://…/woocommerce-subscriptions.zip tests/env/up.sh
#
# Without one, the Checkout tests still run and the Subscribe tests skip.
#
# Idempotent: re-running is how you rebuild after a plugin update.
# Remove it completely with: docker compose -p confirmo-tests down -v

set -euo pipefail

PROJECT="confirmo-tests"
CONTAINER="confirmo-tests-wp"
ENV_DIR="$(cd "$(dirname "$0")" && pwd)"
PORT="${CONFIRMO_TESTS_PORT:-8089}"

if ! docker info >/dev/null 2>&1; then
    echo "Docker is not running." >&2
    exit 1
fi

echo "==> Starting WordPress and MariaDB"
CONFIRMO_TESTS_PORT="$PORT" docker compose -p "$PROJECT" -f "$ENV_DIR/docker-compose.yml" up -d

echo "==> Waiting for WordPress to answer on :$PORT"
for _ in $(seq 1 60); do
    curl -sf -o /dev/null "http://localhost:$PORT/" && break
    sleep 2
done

wp() { docker exec confirmo-tests-cli wp --path=/var/www/html "$@"; }

if ! wp core is-installed 2>/dev/null; then
    echo "==> Installing WordPress"
    wp core install \
        --url="http://localhost:$PORT" \
        --title="Confirmo test site" \
        --admin_user=admin \
        --admin_password=admin \
        --admin_email=tests@confirmo.test \
        --skip-email
fi

echo "==> Installing WooCommerce"
wp plugin is-installed woocommerce 2>/dev/null || wp plugin install woocommerce
wp plugin activate woocommerce

# Orders in WooCommerce's own tables, which is the default for new stores and so
# what the plugin has to work against.
wp plugin is-active woocommerce >/dev/null && \
    wp option update woocommerce_feature_custom_order_tables_enabled yes >/dev/null

echo "==> WooCommerce Subscriptions"
if wp plugin is-installed woocommerce-subscriptions 2>/dev/null; then
    echo "    already installed"
    wp plugin activate woocommerce-subscriptions
elif [ -n "${WCS_ZIP:-}" ]; then
    [ -f "$WCS_ZIP" ] || { echo "    WCS_ZIP is set but $WCS_ZIP does not exist" >&2; exit 1; }
    docker cp "$WCS_ZIP" "confirmo-tests-cli:/tmp/wcs.zip"
    wp plugin install /tmp/wcs.zip --activate
elif [ -n "${WCS_ZIP_URL:-}" ]; then
    wp plugin install "$WCS_ZIP_URL" --activate
else
    cat <<'EOF'
    Not installed: WooCommerce Subscriptions is a paid extension, so it cannot be
    downloaded for you. The Checkout tests will run; the Subscribe tests will
    skip. To include them, re-run with a copy:

      WCS_ZIP=/path/to/woocommerce-subscriptions.zip tests/env/up.sh
EOF
fi

# Loads before regular plugins, which is the only moment early enough to answer
# the module's toggle before it reads it. Dormant unless the probe asks for it.
echo "==> Test helpers"
docker exec confirmo-tests-cli mkdir -p /var/www/html/wp-content/mu-plugins
docker cp "$ENV_DIR/mu-plugins/." "confirmo-tests-cli:/var/www/html/wp-content/mu-plugins/"

echo "==> Store settings"
# The Subscribe module loads its classes at boot, so it has to be on before the
# tests run — flipping the option from a test would be too late.
wp option update confirmo_subscribe_config_options '{"enabled":"yes"}' --format=json >/dev/null
wp option update woocommerce_currency USD >/dev/null
wp option update woocommerce_default_country US:CA >/dev/null
wp option update woocommerce_enable_coupons yes >/dev/null

echo
echo "Ready. Run the suite with:"
echo "  tests/run.sh"
