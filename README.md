# VitaliiHura Checkout for LiqPay

Accept card, Apple Pay, Google Pay and Privat24 payments in WooCommerce through
[LiqPay](https://www.liqpay.ua/), the payment service operated by PrivatBank.

On the WordPress plugin directory:
[wordpress.org/plugins/vitaliihura-checkout-for-liqpay](https://wordpress.org/plugins/vitaliihura-checkout-for-liqpay/)

This plugin is not affiliated with, endorsed by or built by LiqPay or PrivatBank.

## What it does

Customers are handed to the LiqPay page and pay there with whatever the merchant account
offers. The plugin verifies the notification LiqPay sends back, updates the order and records
the transaction details a shop needs for accounting and support.

- Card, Apple Pay, Google Pay and Privat24, chosen per shop or left to the LiqPay account.
- Refunds from the order screen, in full or in part. A refund made inside LiqPay is picked up.
- Notifications verified against the private key, deduplicated and applied under a per-order
  database lock, so two arriving at once cannot both change the same order.
- A confirmation is refused unless it names the currency and an amount that covers the order,
  and a test payment can never settle a live order.
- If a notification never arrives, the shop asks LiqPay itself when the customer returns, and
  again on an hourly sweep.
- Works with High-Performance Order Storage and with both the block and the classic checkout.
- Payment purpose and the method title in every language the site runs, resolved from the
  language of the order rather than of whoever is looking at it.

## Requirements

- WordPress 6.5 or newer
- PHP 7.4 or newer
- WooCommerce 8.2 or newer
- A LiqPay merchant account, payments in UAH, USD or EUR, with the account enabled for the currency the shop sells in

## Installation

Install it from the
[WordPress plugin directory](https://wordpress.org/plugins/vitaliihura-checkout-for-liqpay/),
or download a release archive from this repository and upload it on the Plugins screen. Then open WooCommerce, Settings, Payments,
LiqPay, enter the key pair and enable the method. The notification address is sent with every
payment, so there is nothing to configure on the LiqPay side.

## About this repository

What you see here is the plugin as it ships: the same files a shop receives from the
WordPress directory, plus `.wordpress-org` with the images for the plugin page. Build
tooling and the test suite are kept outside it, so nothing that a shop does not need ends
up on its disk.

Bug reports and patches are welcome in the issues.

## Privacy

Card numbers, expiry dates and security codes are entered on the LiqPay page and never reach
the site. What the plugin sends and what it keeps on the order is spelled out in the
Third-Party Services section of `readme.txt`, and the same wording is offered to the site's
privacy policy through the WordPress privacy tools.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
