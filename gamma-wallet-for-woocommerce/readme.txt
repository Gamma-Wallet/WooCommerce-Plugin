=== Gamma Wallet for WooCommerce ===
Contributors: gammawallet
Tags: loyalty, rewards, store credit, qr code, woocommerce
Requires at least: 6.3
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Customers earn a reward for every paid order and can settle orders with their store credits, by scanning a QR code with Gamma Wallet.

== Description ==

Gamma Wallet is a cardless loyalty wallet for local businesses. This plugin connects your WooCommerce shop to it.

**A reward for every paid order.** When an order is paid at checkout, your customer sees a QR code on the order confirmation page and in their order email. They scan it with the Gamma Wallet app and the reward for that order lands in their wallet, as store credits they can spend back at your shop.

**Use Store Credits with Gamma.** At checkout, customers can choose to settle the whole order with the store credits they hold at your shop. After placing the order they scan a QR code, valid for 60 seconds, and the order is marked as paid.

Credits are a promise of value at your shop. They are not money, and Gamma never handles money.

= What you need =

* A Gamma Business account with a Reward service active. The plugin does nothing for customers while another kind of service is active.
* An integration token, created in Gamma Business → Integrations.
* Your shop must sell in the same currency as your Gamma business.

== External services ==

This plugin connects to the Gamma Wallet service at integration.gamma-wallet.com, run by Gamma Wallet. It is needed to give rewards and to accept store credits; without an integration token nothing is sent.

* When an order is paid (or marked Completed, for cash on delivery, bank transfer and cheque, if you turn that on), it sends the order number, the total, the currency and the date, to create the reward QR code. Later it asks whether the customer has collected the reward, sending only the reward's reference.
* When a customer chooses Use Store Credits with Gamma, it sends the order number, the total and the currency, to create the store-credit QR code and check whether it has been scanned.
* When you save your token, and about once an hour after that, it checks the connection and whether your business has a Reward service active.

It sends no customer names, addresses, emails or products.

* Terms and conditions: https://www.gamma-wallet.com/en/terms-and-conditions
* Privacy policy: https://www.gamma-wallet.com/en/privacy-policy

== Installation ==

1. Upload the plugin and activate it.
2. Open **Gamma Wallet** in the WordPress menu.
3. Paste your integration token and press **Save and check the connection**.
4. Choose which payment methods earn a reward. For cash on delivery, bank transfer and cheque you can choose to give the reward once you mark the order Completed; the customer then receives an email of its own with the QR code.
5. To offer store credits at checkout, turn on **Use Store Credits with Gamma** in WooCommerce → Settings → Payments.

== Frequently Asked Questions ==

= Which orders earn a reward? =

Orders paid at checkout, once the payment is confirmed. Cash on delivery, bank transfer and cheque earn one only if you turn it on, and only once you mark the order Completed (the money has been received).

= My customer bought as a guest. How do they get the reward? =

By email: every order has the customer's email address. For an order paid at checkout the QR code is in the order confirmation email; for cash on delivery it comes in its own email when you mark the order Completed. You can send it again from the order screen with the order action "Send the Gamma reward QR code to the customer".

= Does an order settled with store credits also earn a reward? =

No.

= Can credits cover part of an order? =

No. Store credits always settle the whole order.

= What if the customer does not scan the code on the confirmation page? =

The reward code is also in their order email and in My Account, and it does not expire.

= What if the store-credit code expires? =

The customer presses "Show a new code" on the same page. The order waits, unpaid, until they do.

= Does it work with the block-based checkout? =

Yes, with both the block-based and the classic checkout, and with High-Performance Order Storage.

== Changelog ==

= 1.0.4 =
* Declares WooCommerce as a required plugin.
* Connection and token reminders appear only on the Dashboard, the Plugins page and WooCommerce screens.

= 1.0.3 =
* Fix: order totals with cents (such as 12.80) are now sent to Gamma exactly. On servers whose PHP sets serialize_precision to 17, the total was sent as 12.800000000000001 and settling such an order with store credits failed.
* The "new code" button completes the order when the customer settled the previous code a moment before.
* The store-credit countdown follows the code's real validity.

= 1.0.2 =
* Works only while the business has a Reward service active in Gamma: otherwise no reward QR code is given and store credits are not offered at checkout. The settings page and an admin notice say so. The check is repeated every hour.

= 1.0.1 =
* Cash on delivery, bank transfer and cheque can earn a reward once the order is marked Completed, with an email of its own.

= 1.0.0 =
* First release: rewards for paid orders, and store credits at checkout.
