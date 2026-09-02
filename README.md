# Storify Platform

Storify is a multi-tenant commerce platform for Nigerian businesses. A business can operate multiple stores and warehouses, sell through hosted storefronts, run staff-scoped POS terminals, manage inventory, and collect Paystack or bank-transfer payments.

## Stack

- PHP 8.4+, Laravel 12, MySQL 8
- Blade, Tailwind CSS 4, Alpine.js, Vite 7
- Sanctum for POS API authentication
- Spatie Permission with business-scoped teams
- Pest 4 for automated tests

## Domain model

`User` is the only management authentication model. Business owners and staff belong to a `Business`; staff access stores and warehouses through explicit assignments. Storefront shoppers authenticate independently as `Customer` records scoped to a business.

The main operational flows are:

- Registration → email OTP → business setup → plan selection → dashboard
- Store creation → inventory → storefront and/or POS sales
- Cart → atomic order placement → payment selection → verified confirmation
- Subscription payment, coupon activation, or early-pass activation

## Local setup

Prerequisites: PHP 8.4+, Composer, Node.js 20+, and MySQL on port 3307.

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate --force
npm run build
php artisan serve
```

Run a queue worker in another terminal because receipts and operational email are queued:

```bash
php artisan queue:work
```

After route, controller, view, or configuration changes, clear Laravel caches:

```bash
php artisan optimize:clear
```

## Safe test setup

Tests must never point at a development or production database. Copy the test template and create a dedicated database whose name ends in `_test`:

```bash
cp .env.testing.example .env.testing
mysql -h 127.0.0.1 -P 3307 -u root -e 'CREATE DATABASE storify_test'
php artisan test
```

The test bootstrap refuses to run against an unsafe database name. Feature tests use `RefreshDatabase`, so the test schema is rebuilt and seeded as needed.

Useful checks:

```bash
php artisan test
vendor/bin/pint --test
npm run build
php artisan route:list
```

## Project layout

```text
app/Actions/                 Atomic domain operations
app/Http/Controllers/        Thin HTTP orchestration by feature
app/Http/Requests/           Scoped validation
app/Services/                Shared access, analytics, gateway, and ledger services
routes/v1/management.php     Business dashboard
routes/v1/staff.php          Browser-based staff POS
routes/api/v1/pos.php        Sanctum POS API
routes/v1/storefront.php     Hosted storefronts and checkout
routes/v1/admin_dashboard.php Platform administration
tests/Feature/               Behavior and integration coverage
tests/Unit/Architecture/     Route, naming, and size guardrails
```

## Integrity guarantees

- POS and storefront orders, transactions, balances, cart consumption, and stock movements commit atomically.
- POS orders, payment attempts, subscription payments, stock movements, coupons, and early passes have retry protection.
- Product, bank, order, customer, and store access is scoped to the active business/store.
- Paystack callbacks verify status, amount, and currency before activation.
- Only gateway, bank-review, or authenticated management flows may confirm payments.

Legacy `/vendor/*` URLs remain as permanent redirects, and old queued mail class names remain as compatibility shims. New application code uses business terminology exclusively.

See [dev-docs.md](dev-docs.md) for architecture and migration details.
