# Storify developer guide

## Tenancy and authentication

Management users live in `users`. `business_id` is the tenant boundary; `role = business_owner` identifies an owner and `role = staff` identifies staff. Spatie roles and permissions are team-scoped with the business ID. Staff access to stores and warehouses is explicit through `staff_assignments`.

Customers are separate storefront identities. Customer email uniqueness is scoped by `(business_id, email)`, allowing one person to shop at unrelated businesses without sharing tenant data.

Any query accepting a route-bound store, product, order, bank account, customer, or payment must constrain it to the current business and/or store. The POS API applies `EnsurePosStoreAccess` to the entire nested store route group.

## HTTP boundaries

Controllers are separated by responsibility:

- Checkout: page display, address storage, atomic order placement, and payment selection
- POS: atomic checkout and order history/receipt/refunds
- Subscription: plans, payments, coupons, and early passes
- Stores: creation, dashboards, lifecycle, settings, storefront activation, and tab data

Input rules live in Form Requests where the request has a stable schema. Stateful work lives under `app/Actions`; shared read/access behavior lives under `app/Services`.

Architecture tests enforce callable/unique routes, prevent duplicate API mounting, guard terminology boundaries, and cap controller size.

## Mutation invariants

### Inventory

`StockLedgerService` is the canonical stock ledger. It:

- locks a freshly queried `StockLocation` row;
- rejects zero, negative, or insufficient movements;
- writes before/after balances;
- returns the original movement when the same reference/action is retried;
- locks transfer locations in ID order to avoid deadlocks.

Do not decrement a stock location directly. Product summary quantity and the location ledger must change inside the same outer transaction.

### Orders and POS

`PlaceStorefrontOrder` locks the active cart and product/location rows, creates the order and address, removes stock, and completes the cart in one transaction. A completed cart cannot produce a second order.

`ProcessPosSale` validates the complete tender before persistence and commits the order, line items, transactions, store balance, and stock together. Clients should send a stable `idempotency_key` for each sale attempt.

### Payments and subscriptions

Payment forms include per-render idempotency keys. A Paystack transaction record is created before the external initialization call, preventing duplicate gateway attempts for one key. Network calls are not made while holding database locks.

Callbacks must verify gateway success, the expected amount in kobo, and currency before confirming a transaction. Coupon and early-pass activation lock the tenant and code row, then create at most one active subscription/use.

## Database changes and compatibility

The 2026-09-01 migrations:

- rename `payments.vendor_subscription_id` to `subscription_id`;
- normalize support reply types to `business`;
- add POS order, transaction, and subscription-payment idempotency keys;
- scope customer email uniqueness to a business;
- make early-pass use unique per user.

Deploy application code and run migrations together:

```bash
php artisan migrate --force
php artisan optimize:clear
php artisan queue:restart
```

Keep legacy mail classes until queues created by the previous release have drained. They render current business email templates. Legacy `/vendor/register`, `/vendor/login`, and old admin bookmarks redirect to their current routes; they are compatibility boundaries, not places for new features.

## Testing

`tests/TestCase.php` terminates immediately unless the environment is `testing` and the database is clearly disposable (`*_test` or safe in-memory SQLite). Never weaken this check to make a local test run convenient.

```bash
cp .env.testing.example .env.testing
php artisan test
php artisan test --filter=PosCheckout
vendor/bin/pint --test
npm run build
```

Feature tests use `RefreshDatabase`. Test helpers create businesses, owners, roles, and permission team context; tests should not manually delete shared development records.

## Change checklist

1. Add or update a behavior test for the affected tenant boundary or mutation.
2. Put validation at the request boundary and mutations in a transaction/action.
3. Add an idempotency strategy for any retriable money, inventory, or provisioning request.
4. Run Pint, the full Pest suite, and the Vite production build.
5. Clear caches after route, controller, view, or configuration changes.
