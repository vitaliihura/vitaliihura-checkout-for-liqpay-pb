=== VitaliiHura Checkout for LiqPay ===
Contributors: vitaliihura
Tags: woocommerce, payment gateway, liqpay, payment, checkout
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept card, Apple Pay, Google Pay and Privat24 payments in WooCommerce through the LiqPay payment service.

== Description ==

A complete LiqPay integration for WooCommerce. Customers pay on the LiqPay page and come back to
your shop; the plugin verifies the notification LiqPay sends, updates the order and records the
transaction details a shop needs for accounting and support.

This plugin is not affiliated with, endorsed by or built by LiqPay or PrivatBank.

= What it does =

Customers are handed to the LiqPay page and pay there with whatever you have enabled: card, Apple
Pay, Google Pay or Privat24. You can narrow that list per shop or leave the choice to your LiqPay
account. The credit, QR code, cash and invoice methods LiqPay also offers are not available yet.

Refunds work from the WooCommerce order screen, in full or in part. A full refund made inside the
LiqPay account is picked up and recorded against the order; LiqPay does not report a partial refund
made there, so that one has to be recorded by hand. The order screen shows what was charged, what
LiqPay took as commission and how much of it went back.

Marketplaces can split a payment between several recipients through a filter.

Notifications from LiqPay are verified against your private key, deduplicated, and processed under
a per-order database lock, so two notifications arriving at once cannot both change the same
order. A confirmation is refused unless it names the currency and an amount that covers the order,
and a test-mode payment can never settle a live order. Every status LiqPay documents is
recognised, including the ones where the customer still has something to do.

If a notification never arrives, the shop asks LiqPay itself the moment the customer lands back on
the order received page, and an hourly check covers the customers who never came back.
There is also a button on the order screen to do it on demand.

Multilingual shops get per-language fields for everything a customer reads: the payment method
name, its description and the payment purpose. The language switch appears only when the site
actually has more than one language, and an empty translation falls back to the default one. The
language list is read from Polylang, WPML or TranslatePress, and the values are resolved from the
language of the order rather than the language of whoever is looking at the order, so a customer
who bought in English does not find a Ukrainian line on their card statement.

Test mode uses a separate key pair and can be limited to shop managers, so you can try it on a
live shop without customers seeing the method. The gateway works with High-Performance Order
Storage and with both the block based and the classic checkout.

The settings screen opens with whether the connection works, which mode the shop is in and when
LiqPay last called, and there is a button to check the keys without taking a payment. Settings are
grouped by subject with an index beside them, and the screen works on a phone.

= Requirements =

* WooCommerce 8.2 or newer.
* A LiqPay merchant account with a public and private key.
* Payments in UAH, USD or EUR, and the merchant account has to be enabled for the one you sell in. LiqPay accepts no other currency.

Split payments have to be switched on for your merchant account by LiqPay before they work. The
plugin surfaces the error LiqPay returns when a feature is not available.

= Development =

The source lives at https://github.com/vitaliihura/vitaliihura-checkout-for-liqpay-pb. Bug reports and
patches are welcome there.

== Third-Party Services ==

This plugin connects your shop to LiqPay, a payment service operated by PrivatBank, so that it can
process payments. Payments cannot be taken without contacting it.

Data is sent to LiqPay only after you enter your own LiqPay API keys and enable the payment
method, and only for orders a customer chooses to pay with this method.

When a customer pays, the plugin sends to `https://www.liqpay.ua/api/3/checkout` and
`https://www.liqpay.ua/api/request`: your public key, the order reference, the amount, the
currency, the payment purpose text, the language, and the return and notification addresses of
your site. Depending on the settings you enable, it may additionally send the billing name and
address, and the product names and the order link.

LiqPay sends back, and the plugin stores on the order, a payment status, its own payment
identifiers, the payment method used, a masked card number, the card brand and issuing bank, the
acquirer identifier, the authorisation code, the retrieval reference number and the commission.

Notifications from LiqPay are received at an address on your site and are accepted only when the
signature made with your private key matches.

Wording you can paste into your own privacy policy is added under Tools, Privacy, Policy Guide.
When a customer asks for their data to be removed, the masked card number and the issuing bank are
cleared from the order along with the rest of the personal data WooCommerce removes.

* Service: https://www.liqpay.ua/
* Terms of use: https://www.liqpay.ua/en/information/terms
* Privacy policy: https://privatbank.ua/personal-information
* API documentation: https://www.liqpay.ua/en/doc

== Installation ==

1. Install and activate the plugin.
2. Go to WooCommerce, Settings, Payments and open LiqPay.
3. Enter the public and private key from your LiqPay merchant account and enable the method.
4. To try it first, tick Test mode and enter the sandbox key pair. Sandbox keys always begin with `sandbox_`.
5. Place a test order. The notification address is sent with every payment, so there is nothing to configure on the LiqPay side.

If another LiqPay plugin is already configured on the site, an offer to copy its keys and settings
appears on the WooCommerce settings screen.

== Frequently Asked Questions ==

= Do I need to paste a callback URL into my LiqPay account? =

No. The address is sent with every payment. It is shown under the settings if you need it for
troubleshooting.

= An order stayed pending although the customer paid. =

Usually it does not get that far. When the customer lands back on the order received page the shop
asks LiqPay for the status straight away, so a lost notification is repaired within a second and
without anyone noticing. If the customer closed the tab before returning, status recovery picks the
order up on its hourly pass. You can also press Refresh from LiqPay on the order screen at any time.

= Which currencies are supported? =

LiqPay accepts UAH, USD and EUR, and the payment method hides itself when the shop currency is
anything else. Whether your own account can take dollars or euro is a matter of your agreement
with LiqPay: many accounts are opened for hryvnia alone. If the shop sells in a currency the
account does not hold, LiqPay refuses the payment and the reason appears on the order.

= Does it work with the block based checkout? =

Yes, and with the classic shortcode checkout.

= I run a multilingual shop. What do I have to do? =

Nothing beyond filling in the fields. When Polylang, WPML or TranslatePress is active, the title,
the description and the payment purpose gain a language switch, and each language gets its own
value. Leave a language empty and customers see the default one. The plugin does not register
these strings in the string translation screen of your multilingual plugin, so there is only ever
one place to edit them.

= Which multilingual plugins are supported? =

Polylang, WPML and TranslatePress are read directly for the language list. Weglot and other
translation layers that work on the finished page need nothing from the plugin. On a shop with one
language nothing changes and no switch appears.

= Where are the logs? =

WooCommerce, Status, Logs. Errors are always recorded. Everything else only while logging is
switched on in the settings. Card numbers, tokens and phone numbers are never written to the log.

= I want to join the Ukrainian national cashback programme. What does the plugin do for it? =

Nothing on its own, because the programme is settled between the seller, the tax service and the
acquiring bank rather than by the shop software. The plugin's part is the fiscal receipt: switch
fiscalisation on so every paid order reaches the LiqPay software cash register.

The rest happens outside WooCommerce. The seller has to be on the general taxation system, run a
cash register that reports to the tax service daily, and file an application for the sellers list
through the Diia portal, signed with a qualified electronic signature. That application asks for
the fiscal number of the cash register together with the Merchant ID and the Terminal ID of the
bank terminal behind it. LiqPay issues that pair for the virtual terminal of your shop: ask for it
in the "Help Online" chat of your LiqPay account, registration takes about a day. The fiscal
number of the cash register is in the LiqPay account under the cash register menu, trading points.

These identifiers belong in that application, not in the shop settings, which is why the plugin
does not ask for them.

= Why is the LiqPay logo not included? =

The plugin ships neutral payment marks rather than someone else's trademark. If you want the
LiqPay logo at checkout, download it from the LiqPay brand book, upload it to your media library
and point the icon setting at it.

== Screenshots ==

1. The settings screen: connection state, the name and description customers see, live and sandbox keys.
2. What LiqPay is asked to do: the methods on its page, the payment purpose in every site language, the link lifetime and the reference prefix.

== Changelog ==

= 1.0.0 =
* First release.

== Upgrade Notice ==

= 1.0.0 =
First release.
