# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

`mahocommerce/module-revolut` — a Revolut payment gateway for [Maho Commerce](https://mahocommerce.com), shipped as a Maho **community-pool module** (`app/code/community/Maho/Revolut/`). v1 implements **Revolut Pay** as a redirect checkout: the customer is sent to Revolut's hosted page, and Maho's order state is driven by Revolut's webhooks.

Maho is a Magento 1-lineage codebase, so all the usual conventions apply: `Mage::getModel('maho_revolut/...')`, `Mage::helper('maho_revolut')`, XML-driven config under `etc/`, controllers as `Maho_Revolut_*Controller`, etc. The module declares `<depends>` on `Mage_Payment`, `Mage_Sales`, `Mage_Checkout`.

## Commands

Run any of these from the repo root after `composer install`:

```bash
vendor/bin/phpstan analyze                          # PHPStan level 8 (CI gate, matrix: PHP 8.3/8.4/8.5)
vendor/bin/php-cs-fixer fix --diff --dry-run        # CS check (CI gate). Drop --dry-run to apply.
vendor/bin/rector -c .rector.php --dry-run          # Rector check (CI gate). Drop --dry-run to apply.
```

There is no test suite — CI is purely static analysis + style + PHP/XML syntax checks (see `.github/workflows/`).

The `./maho` script and `public/`, `var/`, `vendor/mahocommerce/maho/` exist because `composer require mahocommerce/maho` pulls a runnable Maho host into this repo so the module can be loaded for static analysis. Don't commit anything under `public/` or `var/` — they're host artifacts, not module code.

## Architecture

### Two payment lifecycle paths, three reconciliation paths

The **only** payment method is `revolut_pay` (`Maho_Revolut_Model_Method_RevolutPay`). It is a redirect gateway: `getOrderPlaceRedirectUrl()` returns `/revolut/payment/redirect`, so Maho hands control off after order creation. The order lives in `STATE_PENDING_PAYMENT` until something reconciles it.

Three things can reconcile a pending order, in order of authority:

1. **`WebhookController::indexAction`** (primary) — Revolut POSTs to `/revolut/webhook` on state changes. The controller verifies the HMAC signature, then **re-fetches** the order from the Revolut API rather than trusting the webhook body, and applies state via `registerCaptureNotification` / `registerAuthorizationNotification` / `cancel()`.
2. **`PaymentController::successAction`** (fallback when the customer returns faster than the webhook) — runs the same retrieve-and-apply logic inline via `_syncOrderState`.
3. **`Maho_Revolut_Model_Cron::checkPendingPayments`** (safety net, every 5 min via `etc/config.xml` crontab) — scans `pending_payment` orders younger than 24h paid via `revolut_pay`, retrieves each from Revolut, reconciles.

All three paths only transition orders that are still in `STATE_PENDING_PAYMENT`, which makes them naturally idempotent — whichever fires first wins, the others no-op.

### Key invariants when touching payment code

- **Never trust the webhook body for amount/state.** Always re-fetch via `Maho_Revolut_Model_Api::retrieveOrder()` before mutating the Maho order. This is intentional — see `WebhookController::_applyEvent` and replicate the pattern if you add new event handling.
- **`merchant_order_ext_ref` is Maho's `increment_id`.** That's the join key between the two systems, plus `revolut_order_id` is stored in `payment->additional_information` for verification (see `WebhookController::_findOrder`).
- **Amounts cross the boundary in minor units** (e.g. cents). Use `Helper_Data::toMinorUnits()` / `fromMinorUnits()` — they handle zero-decimal (JPY) and three-decimal (BHD, KWD, …) currencies. Don't do `* 100` inline.
- **`capture_mode`** is decided at order-create time from the admin "Payment Action" setting: `authorize` → `manual`, anything else → `automatic`. It's stashed in `additional_information.revolut_capture_mode` so `capture()` knows whether to call Revolut's capture endpoint or just close the local transaction.
- **CSRF is bypassed on the webhook controller** via `FLAG_NO_PRE_DISPATCH`; the HMAC signature in `Revolut-Signature` + `Revolut-Request-Timestamp` is the auth (5-minute replay window, see `Helper_Data::verifyWebhookSignature`).

### API client

`Maho_Revolut_Model_Api` is a thin HTTP wrapper around the Merchant API (`https://merchant.revolut.com` / `https://sandbox-merchant.revolut.com`, switched by the `sandbox` flag). Uses `symfony/http-client`, pins `Revolut-Api-Version` to the constant in `Helper_Data::API_VERSION`. Errors log to `var/log/revolut.log` and throw `Mage_Core_Exception`.

API key and webhook secret are stored encrypted (`backend_model="adminhtml/system_config_backend_encrypted"`) and accessed only through `Helper_Data::getApiKey()` / `getWebhookSecret()`, which decrypt on read.

### Config layout

- `etc/config.xml` — module registration, cron schedule, frontend route (`revolut/*`), default payment config (`payment/revolut_pay/*`).
- `etc/system.xml` — admin UI under **System → Configuration → Sales → Payment Methods**, two groups: credentials (`maho_revolut_credentials`) and the payment method (`revolut_pay`).
- `etc/adminhtml.xml` — ACL.
- `app/etc/modules/Maho_Revolut.xml` — community-pool activation.
