=== Confirmo Cryptocurrency Payment Gateway for WooCommerce ===

Contributors: confirmoadm
Tags: Confirmo, Cryptocurrency, Crypto, Crypto Payments, Payment Gateway
Requires at least: 6.2
Tested up to: 6.7
Stable tag: 2.10.0-alpha
Requires PHP: 7.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Crypto payments made easy with industry leaders. Confirmo.com

== Description ==

Start accepting cryptocurrency payments with Confirmo, one of the fastest growing companies in crypto payments! We provide a payment gateway used by Forex brokers, prop trading companies, e-commerce merchants, and luxury businesses worldwide. Our clients include FTMO, My Forex Funds, Alza and many more. All rely on our easily integrated solutions, low fees, and top-class customer support.

By accepting crypto payments you open your business to a new revenue stream. Despite being commonly viewed as an investment tool, cryptocurrencies were created as an alternative to centralized, inflationary financial systems, and crypto holders are now looking for businesses which accept their funds. Using Confirmo's WooCommerce plugin is a simple way to do so. Installing it is quick and easy, so choose your preferred method below.

From version 2.10.0-alpha the plugin also carries Confirmo Subscribe, an optional module for billing customers on a recurring schedule through WooCommerce Subscriptions. It is a separate payment method from the one-off Confirmo Checkout gateway, it is off by default, and it requires subscriptions to be enabled for your Confirmo account. See Setting up Confirmo Subscribe in the Installation section.

Note: This plugin is not distributed through the official WordPress.org plugin directory. It is available only from the GitHub Releases page (https://github.com/confirmo/confirmo-woocommerce/releases) and must be installed and updated manually.

This plugin connects your WooCommerce instance with the 3rd-party service Confirmo. More information about the Confirmo crypto payment gateway can be found at https://confirmo.com. An integral part of the plugin is the API requests to Confirmo, which are described in more detail in the Confirmo API documentation (https://confirmo.com/docs/api-reference).

== Installation ==

The plugin is not available in the WordPress.org directory. Download the latest release from https://github.com/confirmo/confirmo-woocommerce/releases and install it using one of the methods below.

= Upload via the WordPress dashboard (recommended) =

1. Download the plugin .zip from the GitHub Releases page.
2. In your WordPress dashboard, go to Plugins - Add New - Upload Plugin, choose the .zip file, and click Install Now.
3. Click Activate Plugin.
4. Go to WooCommerce - Settings - Payments, click Confirmo, and configure the plugin with information generated in your Confirmo account.

= FTP or File Manager =

1. Download and extract the plugin .zip file.
2. Upload the extracted folder into your WordPress installation under wp-content/plugins.
3. In your WordPress dashboard, go to Plugins - Installed Plugins, find Confirmo Cryptocurrency Payment Gateway, and click Activate.
4. Go to WooCommerce - Settings - Payments, click Confirmo, and configure the plugin with information generated in your Confirmo account.

= Updating =

Updates are not delivered automatically. Download the newer release from the GitHub Releases page and upload it via Plugins - Add New - Upload Plugin; WordPress will offer to replace the current version. Your settings are stored in the database and are preserved across updates.

= Connecting the plugin to your Confirmo account =

Create an account at https://confirmo.com, sign in to your Confirmo dashboard at https://dashboard.confirmo.com and then go to Settings - API Keys - Create API key. You will be required to complete an e-mail verification, after which you will receive the API key. Once you have it, go to WooCommerce - Settings - Payments, and enable Confirmo as a payment method. Paste the API key into the respective field.

To generate a callback password, return to the Confirmo dashboard and go to Settings - Callback password. You will be prompted to complete a second e-mail verification and then provided with the callback password. Again, paste it into the respective field in WooCommerce - Settings - Payments. Callback passwords help increase the security of the API integration. Never share your API key or callback password with anyone!

Finally, choose your desired Settlement currency. Make sure to save your changes by clicking the button at the bottom. When the plugin is activated, Confirmo will appear as a payment option in your website's WooCommerce checkout. Congratulations, you can now start receiving cryptocurrency payments!

Read more at https://confirmo.com. Should you encounter any difficulties, contact us at support@confirmo.com.

= Setting up Confirmo Subscribe (Alpha) =

Confirmo Subscribe bills your customers on a recurring schedule. It is a separate payment method from the Confirmo Checkout gateway described above, both can run on the same store, and the module is off by default, so a Checkout-only store is unaffected until you turn it on. Confirmo Subscribe is still in development and its behaviour may be adjusted in future releases.

Three things must be in place before you start, and none of them is optional:

1. Plugin version 2.10.0-alpha or newer. Confirmo Subscribe does not exist in earlier releases.
2. Subscriptions enabled for your Confirmo account. You cannot enable this yourself - a Confirmo administrator enables it for your merchant account in the Confirmo portal. Write to support@confirmo.com to have it done, and wait for confirmation before configuring anything below.
3. WooCommerce Subscriptions, installed and active. It is a paid extension you buy and install yourself from https://woocommerce.com/products/woocommerce-subscriptions/ - Confirmo cannot supply it. This plugin builds on its subscription products and billing schedules, so without it the Subscribe module stays inert and says so in your dashboard.

Step 1 - create a plan in the Confirmo subscriptions portal.

Subscription plans live in the Confirmo subscriptions portal at https://dashboard.confirmo.com/v2/ - a separate section from the dashboard where you generated your API key. Create a plan there with a billing currency and no fixed price - a variable-price plan, where each WooCommerce product supplies its own amount. Set the billing interval you want to charge on, for example monthly, and a grace period, which is how long a failed payment keeps being retried before the subscription stops.

The plan's currency must match your WooCommerce store currency, or order totals would be recorded in one currency for charges made in another. Fixed-price plans are not sold by this plugin: a product mapped to one is refused at checkout.

Settlement currency for Subscribe is configured in the Confirmo subscriptions portal at https://dashboard.confirmo.com/v2/, not in WooCommerce. The Settlement Currency field in the plugin's settings applies to Confirmo Checkout only.

Step 2 - enable the module in WordPress.

There are two switches, both off by default, and you need both. First go to Confirmo Payment - Settings, tick Enable the Subscribe module, and save; this loads the subscription gateway. Then go to WooCommerce - Settings - Payments and enable Confirmo Subscribe to offer it to subscribers.

Switching the module off later does not cancel anything. Confirmo carries on billing existing subscriptions, and while the module is off your store will neither record those payments nor pass on a cancellation, so cancel them in Confirmo first.

Step 3 - create the product and link the plan.

Add a product and set its product type to Simple subscription, a type provided by WooCommerce Subscriptions. Set the product's price, which is the amount Confirmo charges every cycle. Then, on the General tab, choose your plan from the Confirmo Subscribe plan dropdown and publish the product.

The billing interval comes from the Confirmo plan, so WooCommerce's own interval fields are locked to match it. The product owns the price, and the order total at checkout is what Confirmo bills every cycle. If the dropdown reports that no plans were found, check that step 1 produced a variable-price plan and that your API key is correct.

Your store must show prices to no more than 2 decimal places, set in WooCommerce - Settings - General. Confirmo cannot represent more, and checkout is refused for a total it cannot express.

Customers now see Confirmo Subscribe at checkout for that product. Cancelling in Confirmo cancels the subscription in WooCommerce, and cancelling in WooCommerce is passed on to Confirmo.

== Frequently Asked Questions ==

= How do I get started with Confirmo? =

Simply register with your email and you're good to go! The setup will guide you how to activate your account. In order to comply with applicable law, we will require certain personal identification documents for verification purposes, and certain information about your business.

= How does the verification process work? =

We require an iDenfy personal identity verification, along with certain company documents.

= How long does it take to verify an account? =

Verification is usually done within one business day. This means that an account is typically ready for use the day after all the required documents have been provided.

= Which cryptocurrencies can I accept with Confirmo? =

We currently support the following cryptocurrencies: BTC, BTC (Lightning), ETH, SOL, LTC, TRX, USDC and USDT.

Would you like to see another cryptocurrency here? Contact us at support@confirmo.com

= How does Confirmo guarantee the exchange rate when I accept crypto but receive fiat? =

We guarantee the exchange rate at the time of your transaction, ensuring you receive the exact amount requested. Even with crypto volatility, if you request $100, you will receive $100, minus our 0.8% fee.

= How can I withdraw my funds? =

You can withdraw your funds through Settlements and Payouts:

Settlements are daily, weekly or monthly outgoing transactions to your linked bank account or crypto wallet. Settlements can be used to send Fiat, but also crypto and stablecoins, and work on a set-and-forget basis like traditional standing orders.

Payouts are one-time, on-demand transactions to a crypto wallet. This means they can be only used to send cryptocurrencies, but on an on-demand basis like traditional payment orders.

= What are the fees for withdrawals? =

The fee you will pay for Payouts (one-time crypto withdrawals) is 0.5%. For each payment method a standard network fee applies.

This means that if you send $100 worth of BTC to your contractor, they will receive $100 worth of BTC. You will be charged a transaction fee which will be deducted from your USD or EUR balance.

Settlements (recurrent withdrawals) are free, but bank fees apply.

= Can I sell subscriptions with Confirmo? =

Yes, using the Confirmo Subscribe module added in version 2.10.0-alpha. It needs two things beyond the plugin itself: subscriptions enabled for your Confirmo account, which a Confirmo administrator does for you in the Confirmo portal, and the paid WooCommerce Subscriptions extension. See Setting up Confirmo Subscribe in the Installation section for the full walkthrough.

= Do I need WooCommerce Subscriptions for Confirmo Subscribe? =

Yes. Confirmo Subscribe builds on the subscription products and billing schedules that WooCommerce Subscriptions provides, so it cannot work without it. It is a paid extension sold by WooCommerce at https://woocommerce.com/products/woocommerce-subscriptions/ and Confirmo cannot supply it. The Confirmo Checkout gateway for one-off crypto payments does not need it.

= Where can I find the Terms & Conditions? =

The most up-to-date Terms & Conditions are available on the Confirmo website in the Terms & Conditions section (https://confirmo.com/legal/terms-and-conditions).

== Changelog ==

= 2.10.0-alpha =
* Adds Confirmo Subscribe, an optional module for selling recurring plans through WooCommerce Subscriptions. Off by default; the Confirmo Checkout gateway is unaffected.
* Declares support for WooCommerce's High-Performance Order Storage.

= 2.9.0 =
* See the full release notes at https://github.com/confirmo/confirmo-woocommerce/releases

== Upgrade Notice ==

= 2.10.0-alpha =
Download the latest release from https://github.com/confirmo/confirmo-woocommerce/releases and upload it via Plugins - Add New - Upload Plugin. Settings are preserved. Confirmo Subscribe stays off until you enable it in Confirmo Payment settings.
