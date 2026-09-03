# Tests

Integration tests against a real WordPress with real WooCommerce objects. The
things worth being sure about — the amount on an invoice, a callback moving an
order, the amount Confirmo bills every cycle, a webhook moving a subscription —
only exist when WooCommerce is actually running, so there is no mocked-out
alternative that would mean anything.

## Running them

Docker is the only prerequisite.

```bash
tests/run.sh                          # everything
tests/run.sh --testsuite invoicing    # Checkout only
tests/run.sh --testsuite subscribe    # Subscribe only
tests/run.sh --filter WebhookTest     # one test class
```

The first run builds a throwaway WordPress (`tests/env/up.sh`): WordPress,
MariaDB and WooCommerce, on port 8089, with orders in WooCommerce's own tables
because that is what a new store gets. Provisioning runs through WordPress' own
`wordpress:cli` image, so building the environment pulls nothing but official
images and WooCommerce itself. Later runs reuse it. Remove it with:

```bash
docker compose -p confirmo-tests down -v
```

## WooCommerce Subscriptions

The Subscribe module is built on WooCommerce Subscriptions, which is a **paid
extension**. The suite does not depend on it: without it the Checkout tests run
and the Subscribe tests report as skipped, so a clean checkout still gets a
working suite — just not the whole one.

This repository carries no copy, and nothing here will download one. Publishing
a paid extension from a public repository is not ours to do, and there is no
credential-free official source: no WooCommerce or Automattic repository
publishes the installable plugin, and `Automattic/woocommerce-subscriptions-core`
is a library rather than the plugin. Third-party mirrors exist and are best
avoided — a copy nobody at WooCommerce stands behind is not something a test
pipeline should rest on.

### Getting a copy

Download it from the woocommerce.com account that holds the subscription for it:
**My account → Downloads → WooCommerce Subscriptions**. Keep the zip; you need
it once per test environment, not once per run.

```bash
WCS_ZIP=~/Downloads/woocommerce-subscriptions.zip tests/env/up.sh
tests/run.sh
```

`WCS_ZIP_URL` takes a URL instead, which is how CI should get it: put the same
zip somewhere Confirmo controls — an S3 bucket or the GitLab package registry —
and point the variable at it. Then the Subscribe tests run everywhere with no
manual step and nothing external in the path.

Version matters. Keep the zip current, because these are security-relevant
releases: 9.1.0 was itself a critical patch, and the plugin declares a minimum
supported version.

## Using a WordPress you already have

```bash
CONFIRMO_WP_CONTAINER=my-wp-container tests/run.sh
```

or, without Docker at all, PHPUnit directly:

```bash
WP_ROOT=/srv/www/wordpress phpunit -c phpunit.xml.dist
```

Either needs WooCommerce active and this plugin present.

## What the harness does

Every test runs inside a database transaction that is rolled back, and the two
plugin option rows are saved and restored, so a run leaves the store as it found
it. Every outbound HTTP request is stubbed; an unstubbed one fails the test
rather than reaching the Confirmo API.

`tests/Invoicing` covers the Checkout gateway, `tests/Subscribe` the Subscribe
module. Anything under `tests/Subscribe` extends `SubscribeTestCase`, which is
what makes it skip when Subscriptions is absent.
