# Gamma Wallet for WooCommerce

Use [Gamma Wallet](https://www.gamma-wallet.com) in your WooCommerce shop. Once the plugin is installed:

- **Your customers earn a reward for every paid order.** After paying, they scan a QR code with the Gamma Wallet app and the reward is added to their wallet, under your business.
- **Your customers can use the store credits they hold at your shop.** At checkout they choose *Use Store Credits with Gamma*, scan a QR code, and the whole order is settled from their credits.

Credits are a promise of value at your business. They are not money and not crypto, and Gamma never handles any payment. Card payments, cash on delivery and the rest keep working exactly as they do today.

This guide is for shop owners. No coding is needed. If you want to connect your own software instead of WooCommerce, see [byCode](https://github.com/Gamma-Wallet/Integrations-samples-/tree/main/byCode).

---

## Contents

1. [What you need](#1-what-you-need)
2. [Install the plugin](#2-install-the-plugin)
3. [Create your integration token in Gamma Business](#3-create-your-integration-token-in-gamma-business)
4. [Connect your shop](#4-connect-your-shop)
5. [Choose which orders earn a reward](#5-choose-which-orders-earn-a-reward)
6. [Turn on store credits at checkout](#6-turn-on-store-credits-at-checkout)
7. [What your customers see](#7-what-your-customers-see)
8. [Your orders screen](#8-your-orders-screen)
9. [Renewing your token](#9-renewing-your-token)
10. [Questions and answers](#10-questions-and-answers)
11. [When something is wrong](#11-when-something-is-wrong)

---

## 1. What you need

| | |
|---|---|
| A Gamma Business account with a **Reward** service active | [Register](https://business.gamma-wallet.com) and start on the free tier, then activate a Reward service. The plugin works only with a Reward service: while another kind of service is active (a membership or a discount card, for example), customers get no reward QR code and store credits are not offered at checkout. |
| WooCommerce | Version 8.0 or newer, on WordPress 6.3 or newer |
| PHP | Version 8.1 or newer. Your hosting provider can tell you which version you have. |
| The same currency | Your shop must sell in the same currency as your Gamma business (for example EUR in both). |

Both the classic checkout and the newer block checkout work.

## 2. Install the plugin

1. Download **[gamma-wallet-for-woocommerce.zip](gamma-wallet-for-woocommerce.zip)** (on GitHub, open the file and click *Download raw file*).
2. In your WordPress admin, go to **Plugins → Add New Plugin → Upload Plugin**.
3. Choose the zip file, click **Install Now**, then **Activate**.

A new **Gamma Wallet** entry appears in the admin menu on the left.

![The Gamma Wallet entry in the WordPress admin menu](images/admin-menu.png)

## 3. Create your integration token in Gamma Business

The token is the key that lets your shop talk to your Gamma business. You never give the plugin your password.

1. Sign in to [Gamma Business](https://business.gamma-wallet.com) as the business owner.
2. Open **Integrations**.
3. Click **Create token** and choose how long it stays valid (up to one year).
4. **Copy the token now.** It starts with `GWINT_` and is shown only once.

Keep the token private, like a password. Anyone who has it can create rewards for your business. If you think it has leaked, disable it in **Integrations** and create a new one.

## 4. Connect your shop

1. In WordPress, open **Gamma Wallet**.
2. Paste the token into **Integration token**.
3. Click **Save and check the connection**.

Under **Status** you should now see **✓ Connected**, your business name, your currency and how many days the token has left. If a red line says your business has no Reward service active, activate one in Gamma Business, then click **Check again**.

![The Gamma Wallet settings page](images/settings.png)

The token field stays empty after saving; that is on purpose, so the token can't be read back from the page. Only its first and last characters are shown under **Status**. To change it, paste a new one. To disconnect the shop, tick **Remove the saved token**.

## 5. Choose which orders earn a reward

Under **Rewards for paid orders**:

- **Rewards**: turns rewards on or off for the whole shop.
- **Payment methods that earn a reward**: one tick box for each payment method your shop offers.
- **Email**: puts the reward QR code in the order confirmation email as well as on the order confirmation page.

The rule behind the tick boxes is simple: **a reward is given only for money you have actually received.**

| Payment method | When the reward is given | Where the customer finds the QR code |
|---|---|---|
| Paid at checkout (card, PayPal and the like) | As soon as the payment is confirmed | On the order confirmation page and in the order confirmation email |
| Paid later: cash on delivery, bank transfer, cheque | Only when **you** mark the order **Completed**, meaning you have received the money | In an email of its own, sent once when you mark the order Completed |
| Store credits (*Use Store Credits with Gamma*) | Never: the customer used their credits rather than paying | — |

Online payment methods are ticked by default. Cash on delivery, bank transfer and cheque are not; tick them if you want those orders to earn a reward once they are paid.

The reward the customer receives follows the Reward service you have active in Gamma Business. You don't set amounts in WooCommerce.

## 6. Turn on store credits at checkout

1. Go to **WooCommerce → Settings → Payments**. The **Store credits at checkout** section of the Gamma Wallet page links straight there.
2. Turn on **Use Store Credits with Gamma**.

Customers now see it as a payment option at checkout:

![The store credits option at checkout](images/checkout-option.png)

Good to know:

- Store credits always cover the **whole** order. A customer who doesn't hold enough credits at your shop can't complete it with their credits and chooses another payment method instead.
- The option is shown only when your shop is connected, your business has a Reward service active, the currency matches and the order total is above zero.
- An order settled with store credits doesn't earn a new reward.

## 7. What your customers see

### An order paid at checkout

The order confirmation page shows the reward. The customer opens the Gamma Wallet app, scans the code, and the reward is added to their wallet. The same code is in their order confirmation email, so they can scan it later from another device.

![The reward on the order confirmation page](images/thankyou-reward.png)

On a phone, *Open in Gamma Wallet* opens the same reward without scanning.

### An order paid on delivery

At checkout and in the order emails there is no reward yet, because nothing has been paid. When the parcel is delivered and paid, you mark the order **Completed**. The customer then receives an email with the subject *Your reward from (your shop) (order …)* containing the QR code.

### An order settled with store credits

After placing the order, the customer sees a QR code with a countdown. They scan it with the Gamma Wallet app and confirm. The page updates by itself and the order moves to **Processing**, just like a paid order.

![Settling an order with store credits](images/thankyou-credits.png)

Each code is valid for **60 seconds**. If time runs out, the customer gets a button to show a new code. Until the customer confirms, the order stays **Pending payment**.

### Guests

Customers don't need an account in your shop. The reward QR code is on the confirmation page and in the email that goes to the address they gave at checkout. They only need the free Gamma Wallet app.

## 8. Your orders screen

Every order has a **Gamma Wallet** box on its page in **WooCommerce → Orders**. It tells you where things stand:

- *Waiting for the customer to collect the reward*, with the QR code (you can show it to a customer standing in front of you)
- *Reward collected*
- *Paid later: when you mark the order Completed, the reward QR code is created…*
- *Waiting for the customer to settle it with store credits*
- an error message, if the reward could not be created (see [section 11](#11-when-something-is-wrong))

![The Gamma Wallet box on an order](images/order-box.png)

**Sending the reward again.** If a customer lost the email, open the order, choose **Send the Gamma reward QR code to the customer** under **Order actions**, and click the arrow button. A note is added to the order each time.

![Sending the reward QR code again](images/order-action.png)

Each order has exactly one reward, and it can be collected only once. Sending it again doesn't create a second reward.

## 9. Renewing your token

Every token has an end date. From **14 days before it expires**, WordPress shows a reminder at the top of your admin pages.

To renew:

1. In Gamma Business → **Integrations**, click **Replace token** and copy the new one.
2. Paste it on the **Gamma Wallet** page in WordPress and click **Save and check the connection**.

Do both steps together. **The old token stops working the moment you create the new one**, and you can create one token every 24 hours.

## 10. Questions and answers

**Does Gamma take a share of my sales or touch the payment?**
No. Customers pay you exactly as before, through the payment methods you already use. Gamma only records the reward contract for the order. A customer who uses store credits is using value you promised earlier, not paying Gamma.

**What does the plugin send to Gamma?**
For each order that earns a reward or uses store credits: the order number, the total, the currency and the payment date. No names, addresses, email addresses or products.

**My customer doesn't have the Gamma Wallet app yet.**
They install the free Gamma Wallet app, sign up, and scan the code from the confirmation page or the email.

**Can a customer collect the same reward twice, or collect someone else's?**
No. Each order's reward can be collected once, by the first person who scans it. Customers should treat the code like a voucher.

**An order is refunded or cancelled. What happens to the reward?**
The plugin doesn't take a reward back. If the order already had a reward, its QR code still works until the customer collects it, and a collected reward stays in their wallet. For pay-later orders, you avoid this by marking an order Completed only once you have the money.

**Can I give rewards for cash on delivery orders?**
Yes. Tick *Cash on delivery* under **Payment methods that earn a reward**. The reward is sent by email when you mark the order Completed, never before.

**What happens if I deactivate or delete the plugin?**
Deactivating it stops new rewards and hides the store credits option. Rewards already given stay in your customers' wallets. Deleting it also removes its settings, including the saved token.

## 11. When something is wrong

| What you see | What to do |
|---|---|
| **Status** says the token is not valid, expired or disabled | Create a new token in Gamma Business → Integrations and paste it in. |
| *… works only with a Reward service* | Your active service in Gamma is not a Reward service. Activate a Reward service in Gamma Business. The plugin checks again every hour; click **Check again** on the Gamma Wallet page to see the change at once. |
| *Your shop sells in … but your Gamma business uses …* | Your WooCommerce currency (*WooCommerce → Settings → General*) must be the same as your Gamma business currency. |
| *Use Store Credits with Gamma* is missing at checkout | Check that it is turned on under *WooCommerce → Settings → Payments*, that **Status** shows *Connected* with no red line about the Reward service, that the currencies match and that the total is above zero. |
| An order has no reward | Check that your business has a Reward service active, that the payment method is ticked, that **Rewards** is on, and, for cash on delivery, that the order is marked **Completed**. The order's Gamma Wallet box gives the reason. |
| An error in the order's Gamma Wallet box | If Gamma couldn't be reached, the plugin tries again on its own a few times over the next hour. If the box still shows an error, fix the cause it names (usually the token), then choose **Send the Gamma reward QR code to the customer** under **Order actions**: this creates the reward and emails it. |
| *Gamma could not be reached* | Your hosting must allow outgoing connections to `https://integration.gamma-wallet.com`. Ask your hosting provider if this message stays. |
| *Too many requests to Gamma* | Wait a minute and try again. |
| The reward email didn't arrive | Ask the customer to check their spam folder, then send it again from **Order actions**. If none of your shop's emails arrive, the problem is your WordPress email setup, not the plugin. |

The plugin also writes a log you can share with support: **WooCommerce → Status → Logs**, source `gamma-wallet`.

Still stuck? Contact us through [gamma-wallet.com](https://www.gamma-wallet.com).

---

The plugin's source code is in [gamma-wallet-for-woocommerce](gamma-wallet-for-woocommerce). It is released under the GPL-2.0-or-later licence, like WordPress itself.
