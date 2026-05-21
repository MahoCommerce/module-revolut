# Maho NAME

![Maho Commerce](https://img.shields.io/badge/Maho_Commerce-module-orange)
![License](https://img.shields.io/badge/license-OSL--3.0-blue)
![PHP](https://img.shields.io/badge/php-%3E%3D8.3-8892BF)
![PHPStan Level](https://img.shields.io/badge/PHPStan-level%208-brightgreen)

**TODO: one-line pitch.** Longer description of what this module does, who it's for, and the integration it provides for [Maho Commerce](https://mahocommerce.com).

## Requirements

- PHP >= 8.3
- Maho Commerce

## Installation

```bash
composer require mahocommerce/module-NAME
```

## Configuration

TODO: how to enable and configure the module from the Maho admin (System → Configuration → ...).

## Development

This module ships with the standard Maho CI gates:

- **PHPStan** (level 8) — `vendor/bin/phpstan analyze`
- **Rector** (dry-run) — `vendor/bin/rector -c .rector.php --dry-run`
- **PHP CS Fixer** (dry-run) — `vendor/bin/php-cs-fixer fix --dry-run`
- **PHP / XML syntax checks** — automatic on CI

Run `composer install` and you can execute any of the above locally before pushing.

---

## Using this template

When you create a new repo from this template:

1. **Rename placeholders** — find/replace `NAME` and `module-NAME` across the repo:
   - `composer.json` → `name` and `description`
   - `README.md` → title, pitch, install command
2. **Update the badge URLs** if you want CI status badges (they're not included by default — the 4 static badges above are the org standard).
3. **Bootstrap the module code** under `app/code/community/Vendor/Module/` (or `app/code/core/Maho/Module/` for first-party Maho modules) and declare it in `app/etc/modules/Vendor_Module.xml`.
4. **Add `.github/FUNDING.yml`** (already included — leave as-is for org-owned repos, edit/remove for forks).
5. **Update this section** of the README with the real module docs once you've shipped something.

See an existing module for reference: [module-mollie](https://github.com/MahoCommerce/module-mollie), [module-przelewy24](https://github.com/MahoCommerce/module-przelewy24).
