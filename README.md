# Confirmo Cryptocurrency Payment Gateway for WooCommerce

Accept cryptocurrency payments on your WooCommerce store with [Confirmo](https://confirmo.com) — a crypto payment gateway used by Forex brokers, prop trading companies, e‑commerce merchants, and luxury businesses worldwide.

| | |
| --- | --- |
| **Stable version** | 2.9.0 |
| **Requires WordPress** | 6.2 or higher |
| **Tested up to** | 6.7 |
| **Requires PHP** | 7.4 or higher |
| **Requires WooCommerce** | active and configured |
| **License** | GNU General Public License — see [LICENSE](LICENSE) |

> **Note:** This plugin is **not** distributed through the official WordPress.org plugin directory. It is available only from this repository's [**Releases**](https://github.com/confirmo/confirmo-woocommerce/releases) page and must be installed and updated **manually** using one of the methods below.

> This plugin connects your WooCommerce instance with the 3rd‑party service Confirmo. More information about the Confirmo crypto payment gateway can be found at [https://confirmo.com](https://confirmo.com). An integral part of the plugin is the API requests to Confirmo, which are described in more detail in [the Confirmo API documentation](https://confirmo.com/docs/api-reference).

## Why accept crypto?

By accepting crypto payments you open your business to a new revenue stream. Despite being commonly viewed as an investment tool, cryptocurrencies were created as an alternative to centralized, inflationary financial systems, and crypto holders are now looking for businesses which accept their funds. Using Confirmo's WooCommerce plugin is a simple way to do so. Installing it is quick and easy — choose your preferred method below.

## Download

Download the latest `confirmo-woocommerce.zip` (or the source `.zip`) from the [**Releases**](https://github.com/confirmo/confirmo-woocommerce/releases) page. Always use the newest release to receive the latest features and security fixes.

## Installation

### Method 1 — Upload via the WordPress dashboard (recommended)

1. Download the plugin `.zip` from the [Releases](https://github.com/confirmo/confirmo-woocommerce/releases) page.
2. In your WordPress dashboard, go to **Plugins → Add New → Upload Plugin**, choose the `.zip` file, and click **Install Now**.
3. Click **Activate Plugin**.
4. Go to **WooCommerce → Settings → Payments**, click **Confirmo**, and configure the plugin (see [Configuration](#configuration) below).

### Method 2 — FTP or File Manager

1. Download and extract the plugin `.zip`.
2. Upload the extracted folder into your WordPress installation under `wp-content/plugins/`.
3. In your WordPress dashboard, go to **Plugins → Installed Plugins**, find **Confirmo Cryptocurrency Payment Gateway**, and click **Activate**.
4. Go to **WooCommerce → Settings → Payments**, click **Confirmo**, and configure the plugin (see [Configuration](#configuration) below).

## Updating

Because the plugin is not in the WordPress.org directory, updates are **not** delivered automatically. To update:

1. Download the newer release `.zip` from the [Releases](https://github.com/confirmo/confirmo-woocommerce/releases) page.
2. In **Plugins → Add New → Upload Plugin**, upload the new `.zip`. WordPress will detect the existing installation and offer to **Replace current with uploaded**. Confirm to overwrite.
3. Alternatively, deactivate and delete the old version first, then install the new one. Your settings are stored in the database and are preserved across updates.

> **Tip:** Test updates on a staging site before applying them to production.

## Configuration

Create an account at [https://confirmo.com](https://confirmo.com), then:

1. **Generate an API key** — go to **Settings → API Keys → Create API key**. You will be asked to complete an e‑mail verification, after which you receive the API key.
2. **Enable Confirmo in WooCommerce** — go to **WooCommerce → Settings → Payments**, enable **Confirmo** as a payment method, and paste the API key into the corresponding field.
3. **Generate a callback password** — back in the Confirmo dashboard, go to **Settings → Callback password**. Complete a second e‑mail verification to receive the callback password, then paste it into the corresponding field in **WooCommerce → Settings → Payments**. Callback passwords increase the security of the API integration.
4. **Choose your settlement currency**, then click **Save changes**.

Once activated, Confirmo appears as a payment option at your WooCommerce checkout. **Congratulations — you can now start receiving cryptocurrency payments!**

> **Security:** Never share your API key or callback password with anyone.

Read more at [Confirmo.com](https://confirmo.com). If you run into any difficulty, [contact us](mailto:support@confirmo.com) at [support@confirmo.com](mailto:support@confirmo.com).

## Frequently Asked Questions

### How do I get started with Confirmo?

Simply register with your email and you're good to go! The setup will guide you through activating your account. To comply with applicable law, we require certain personal identification documents for verification purposes, as well as certain information about your business.

### How does the verification process work?

We require an iDenfy personal identity verification, along with certain company documents.

### How long does it take to verify an account?

Verification is usually completed within one business day. An account is typically ready for use the day after all the required documents have been provided.

### Which cryptocurrencies can I accept with Confirmo?

We currently support: BTC, BTC (Lightning), ETH, SOL, LTC, TRX, USDC, and USDT.

Would you like to see another cryptocurrency here? Contact us at [support@confirmo.com](mailto:support@confirmo.com).

### How does Confirmo guarantee the exchange rate when I accept crypto but receive fiat?

We guarantee the exchange rate at the time of your transaction, ensuring you receive the exact amount requested. Even with crypto volatility, if you request $100, you receive $100, minus our 0.8% fee.

### How can I withdraw my funds?

You can withdraw your funds through **Settlements** and **Payouts**:

- **Settlements** are daily, weekly, or monthly outgoing transactions to your linked bank account or crypto wallet. They can send fiat as well as crypto and stablecoins, and work on a set‑and‑forget basis like traditional standing orders.
- **Payouts** are one‑time, on‑demand transactions to a crypto wallet. They can send cryptocurrencies only, on an on‑demand basis like traditional payment orders.

### What are the fees for withdrawals?

Payouts (one‑time crypto withdrawals) cost 0.5%, and a standard network fee applies per payment method. For example, if you send $100 worth of BTC to your contractor, they receive $100 worth of BTC, and the transaction fee is deducted from your USD or EUR balance.

Settlements (recurrent withdrawals) are free, but bank fees apply.

### Where can I find the Terms & Conditions?

The most up‑to‑date Terms & Conditions are available on the Confirmo website in the [Terms & Conditions](https://confirmo.com/legal/terms-and-conditions) section.

## Support

- Documentation: [Confirmo API reference](https://confirmo.com/docs/api-reference)
- Email: [support@confirmo.com](mailto:support@confirmo.com)
- Issues: [GitHub Issues](https://github.com/confirmo/confirmo-woocommerce/issues)

## License

This plugin is released under the GNU General Public License. See the [LICENSE](LICENSE) file for details.
