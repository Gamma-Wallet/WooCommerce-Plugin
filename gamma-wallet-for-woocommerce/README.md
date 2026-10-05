# Gamma Wallet for WooCommerce — developer notes

The WordPress.org description is in `readme.txt`. This file is for whoever works on the code.

## What it does

| Flow | When | What happens |
|---|---|---|
| Reward for a paid order | The payment is confirmed at checkout (`payment_complete()`, which sets the date paid), with a payment method that earns rewards. Cash on delivery, bank transfer and cheque never do | `Bill/Create`; the QR code goes on the thank-you page, in the customer's order emails, in My Account and in the admin order screen. The thank-you page asks every 5 s until the reward is collected |
| Use Store Credits with Gamma | The customer picks it at checkout and places the order | The order stays **pending**; `Credit/Start`; the thank-you page shows the QR with a 60 s countdown and asks every 5 s. `Paid` → `payment_complete()` and the order confirmation. `Expired` → "Show a new code" |

An order settled with store credits is never declared as a bill (no reward). Refunds and cancellations are not sent to Gamma (out of scope for now).

## Files

| File | |
|---|---|
| `gamma-wallet-for-woocommerce.php` | Bootstrap: HPOS and blocks compatibility, loading, gateway registration |
| `includes/class-gamma-wallet-api.php` | The only code that calls Gamma (`wp_remote_request`). The token never leaves it |
| `includes/class-gamma-wallet-settings.php` | The **Gamma Wallet** admin menu: token, connection check, reward settings, reminders |
| `includes/class-gamma-wallet-rewards.php` | Flow 1: declaring the bill, email, My Account, admin meta box |
| `includes/class-gamma-wallet-credits-gateway.php` | Flow 2: the checkout option (a `WC_Payment_Gateway`) |
| `includes/class-gamma-wallet-blocks-support.php` + `assets/js/blocks-credits.js` | The checkout option in the block checkout (no build step) |
| `includes/class-gamma-wallet-rest.php` | The routes the customer's page polls: `gamma-wallet/v1/orders/{id}/status` and `/new-code`, protected by the order key |
| `includes/class-gamma-wallet-frontend.php` + `assets/` | The two boxes the customer sees, and the polling script |
| `uninstall.php` | Deletes the settings and the token when the plugin is deleted |

## Rules kept in the code

- The browser never talks to Gamma and never sees the token; it asks the shop's own REST routes.
- The REST routes answer only with the order key from the thank-you address, and keep each answer for 4 s.
- A new store-credit code is refused while the previous one could still be settled (until 15 s after it expired), so an order can never be settled twice.
- The bill is declared from the status-specific action (`woocommerce_order_status_processing` / `_completed`), which runs before WooCommerce sends that status's emails, so the QR code is in the email. It is also created on demand when the email or thank-you page needs it.
- Failures are written as one order note per reason and to WooCommerce → Status → Logs (source `gamma-wallet`), and retried up to 5 times through Action Scheduler.

## Settings

Stored in the option `gamma_wallet_settings` (not autoloaded): `token`, `rewards_enabled`, `no_reward_methods` (payment method ids that earn no reward; `cod`, `bacs` and `cheque` always), `reward_email`. The last connection check is in `gamma_wallet_connection`. The gateway's own settings are WooCommerce's usual `woocommerce_gamma_wallet_credits_settings`.

The API address can be changed for testing in `wp-config.php`:

```php
define( 'GAMMA_WALLET_API_URL', 'https://…' );
```

## Not done yet

- Translations (all strings are English, in the `gamma-wallet` text domain).
- Refunds and cancellations.
- Tested against a local WooCommerce shop running in Docker, with the block checkout.
