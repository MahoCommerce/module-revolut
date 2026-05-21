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

1. In your Revolut Merchant dashboard, generate a **Merchant API key** (sandbox or production).
2. In Maho admin, go to **System → Configuration → Sales → Payment Methods**.
3. Under **Revolut — General Settings**, set:
   - **Sandbox Mode** — on while testing, off in production
   - **Merchant API Key** — paste the secret key from Revolut
   - **Webhook Signing Secret** — see step 4
4. Create a webhook in the Revolut dashboard pointing at:
   ```
   https://<your-store>/revolut/webhook
   ```
   Paste the resulting signing secret into the field above. Subscribe to at least: `ORDER_COMPLETED`, `ORDER_AUTHORISED`, `ORDER_CANCELLED`, `PAYMENT_FAILED`.
5. Under **Revolut Pay**, set the method to **Enabled** and pick a Title/Sort Order.

## Development

This module ships with the standard Maho CI gates:

- **PHPStan** (level 8) — `vendor/bin/phpstan analyze`
- **Rector** (dry-run) — `vendor/bin/rector -c .rector.php --dry-run`
- **PHP CS Fixer** (dry-run) — `vendor/bin/php-cs-fixer fix --dry-run`
- **PHP / XML syntax checks** — automatic on CI

Run `composer install` and you can execute any of the above locally before pushing.
