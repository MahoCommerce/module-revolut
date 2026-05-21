# Maho Revolut

![Maho Commerce](https://img.shields.io/badge/Maho_Commerce-module-orange)
![License](https://img.shields.io/badge/license-OSL--3.0-blue)
![PHP](https://img.shields.io/badge/php-%3E%3D8.3-8892BF)
![PHPStan Level](https://img.shields.io/badge/PHPStan-level%208-brightgreen)

Revolut payment gateway for [Maho Commerce](https://mahocommerce.com). The v1 ships **Revolut Pay** as a redirect checkout: the customer is sent to Revolut's hosted payment page, and the order is captured back in Maho via webhook.

## Requirements

- PHP >= 8.3
- Maho Commerce
- A Revolut Business / Merchant account with API access

## Installation

```bash
composer require mahocommerce/module-revolut
```

## Configuration

### 1. Get the Merchant API key

In your Revolut Business dashboard — production at <https://business.revolut.com>, sandbox at <https://sandbox-business.revolut.com> — go to:

**Merchant → APIs → Merchant API** tab → **API Keys → Sandbox API Secret key** (or *Production API Secret key* when going live).

Click the eye icon and copy the full `sk_...` secret.

> [!IMPORTANT]
> Make sure you are on the **Merchant API** tab, **not** *Business API*. The Business API needs OAuth + JWT + certificates; this module does not use it. The **Public key** (`pk_...`) shown on the same screen is unused by v1 (redirect checkout, no JS widget) — ignore it.

### 2. Create a webhook

In the same dashboard, go to:

**Merchant → APIs → Merchant API** tab → scroll to **Webhooks** → **Add webhook** (or **Set up webhook**).

- **URL** — `https://<your-store>/revolut/webhook` (must be HTTPS and publicly reachable; for local dev use ngrok / Cloudflare Tunnel).
- **Events** — at minimum: `ORDER_COMPLETED`, `ORDER_AUTHORISED`, `ORDER_CANCELLED`, `ORDER_PAYMENT_FAILED`. Selecting all order events is also safe — the module re-fetches the order and only acts on states it recognises.

After saving, Revolut shows the **signing secret once** — copy it.

### 3. Configure Maho

In Maho admin → **System → Configuration → Sales → Payment Methods**:

Under **Revolut — General Settings**:
- **Sandbox Mode** — on while testing, off in production
- **Merchant API Key** — the `sk_...` from step 1
- **Webhook Signing Secret** — the secret from step 2

Under **Revolut — Revolut Pay**:
- **Enabled** — Yes
- **Title / Sort Order / Payment Action** — to taste (Authorize Only vs. Authorize and Capture)

## Development

This module ships with the standard Maho CI gates:

- **PHPStan** (level 8) — `vendor/bin/phpstan analyze`
- **Rector** (dry-run) — `vendor/bin/rector -c .rector.php --dry-run`
- **PHP CS Fixer** (dry-run) — `vendor/bin/php-cs-fixer fix --dry-run`
- **PHP / XML syntax checks** — automatic on CI

Run `composer install` and you can execute any of the above locally before pushing.
