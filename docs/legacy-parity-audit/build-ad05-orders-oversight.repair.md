# AD-05 — Platform orders oversight (WS5) — repair pass

Verified against the actual files (not the completion report). Workstream files:

- API: `app/Http/Controllers/Api/V1/Admin/OrderController.php`,
  `app/Http/Controllers/Api/V1/Admin/Shop4meOrderController.php`,
  `app/Http/Controllers/Api/V1/Admin/TransactionStatusController.php`,
  `app/Http/Controllers/Api/V1/Admin/Concerns/EnsuresPlatformAdmin.php`,
  `app/Http/Controllers/Api/V1/Admin/Concerns/SerializesAdminOrders.php`,
  `routes/api/v1/admin/ad05-orders-oversight.php`,
  `tests/Feature/Api/ad05ordersoversightTest.php`
- SPA (admin): `src/views/OrdersView.vue`, `src/views/Shop4meOrdersView.vue`,
  `src/views/OrderDetailView.vue`, `src/components/TransactionStatusOverride.vue`,
  `src/api/modules/ad05-orders-oversight.ts`, `src/router/modules/ad05-orders-oversight.ts`,
  `src/nav/modules/ad05-orders-oversight.ts`
- Management SPA: no AD-05 surface (this is an admin-only workstream).

## Verdict

All seven checklist items pass after two in-scope repairs in the shared serializer. The repairs fix
a dead `pending` badge branch and the filter/badge drift the file's own docblock promised could not
happen. Every route resolves to an existing public method, every method returns the house envelope
behind the platform-role guard, every SPA call matches a registered route and an exported module
function, the route module inherits its wrappers, names are unique app-wide, both SPA module shapes
match their consumers, imports resolve, and every PHP file passes `php -l` + `pint --test`. The two
remaining items are the documented component-mount hand-offs to orchestrator-owned shared files.

## Fixed

1. **The `pending` badge branch in `derivedPaymentStatus()` was dead code.** It read
   `$live->contains(TransactionStatus::PENDING->value)` — a string compared against a collection of
   `Transaction` models, which `Collection::contains()` evaluates with `in_array` and can never
   match (verified: `in_array('pending', [$model]) === false`). An order whose only live transaction
   is pending therefore rendered the **Unpaid** badge while the `payment_status=pending` filter
   selected it — exactly the class of filter/badge drift the workstream was meant to remove. It now
   uses a closure (`$transaction->status === TransactionStatus::PENDING`); a pending-only order
   derives `pending` (smoke-tested through `artisan tinker`).

2. **The payment facet SQL did not fully mirror the accessor's decision tree.** The old
   paid/partial/pending cases ignored the accessor's refund-first precedence and its paid-sum
   short-circuits, so an order with a refunded leg plus a full confirmed leg was returned under
   `payment_status=paid` while badging **Refunded**, and a paid order carrying an extra pending leg
   was returned under `payment_status=pending`. The fix adds a shared
   `LIVE_TRANSACTION_STATUSES` const (used by the accessor and the unpaid/paid facets so the two
   cannot drift), an `excludeRefundedLegs()` helper applied to the paid/partial/pending cases, a
   `paidSum <= 0` + `paidSum < total` condition on pending, and a live-leg requirement on paid
   (mirrors the accessor's unpaid short-circuit at zero totals). The 13 implementation tests were
   re-checked by hand against the new SQL: every asserted count, facet and combination still holds
   (test 2's six orders given the accessor's precedence all classify as asserted, including
   `payment_status=unpaid` still matching the cancelled-only order).

## Unfixable / not mine to land

1. **Mounting `TransactionStatusOverride.vue` in the existing `TransactionsView.vue` drawer.** The
   roadmap asks the transactions drawer to gain an "Update status" action; `TransactionsView.vue`
   is a pre-existing shared view this workstream must not edit. The component is created and its
   top-of-file MOUNT ME comment carries the exact wiring
   (`<TransactionStatusOverride v-model="overrideOpen" :transaction="detail" @updated="…" />`).
   Orchestrator wires it.
2. **The live `orders_pending` sidebar badge.** `AdminLayout.vue` is shared and its nav node type
   allows `badge`; the nav module's comment documents giving the "All Orders" child
   `badge: stats.orders_pending` from the same dashboard response the layout already fetches for
   the users badge (`GET /admin/dashboard` returns `stats.orders_pending` — confirmed in both
   `DashboardController` and `DashboardParityController`). Orchestrator wires it.
3. **`StatusBadge.vue` has no `partial` key** (shared component): the derived `partial` badge
   falls back to the neutral grey style instead of a distinct accent. Cosmetic only — the label
   still renders as "partial" — and the component is shared by every workstream, so it is left for
   the orchestrator rather than edited from here.

## Verified clean (no change needed)

1. **Routes → controller methods and signatures.** Reflection over the booted `RouteCollection`
   confirms all eight AD-05 actions resolve to existing classes and public methods
   (`OrderController@{index,show,update,updateStatus,updatePaymentStatus,destroy}`,
   `Shop4meOrderController@index`, `TransactionStatusController@update`). `{order}` binds by
   `Order::getRouteKeyName()` = `order_number`, `{transaction}` by `Transaction::getRouteKeyName()`
   = `reference` — exactly what the SPA and the tests pass. `php artisan route:list
   --path=api/v1/admin` shows all eight, and the management route file still loads (261 routes
   under `api/v1/management`).
2. **Envelope + platform scoping.** Every one of the eight actions returns `$this->ok($data,
   $message, $meta)` (validation failures are standard Laravel 422s, which `apiErrorMessage()`
   reads). Platform-wide reads are the deliberate WS5 semantics; the tenancy control is
   `EnsuresPlatformAdmin::authorizePlatformAdmin()` called at the top of every method — a
   business-scoped account whose in-business "Super Admin" role bundles the `admin.*` permissions
   and holds an admin-audience token gets 403, as the four refusal paths in the test file assert.
   The guard matches the fleet pattern (`AdminController`, `AccountingController`, WS-1) and
   `User::isAdmin()`'s role set. All mutations run in `DB::transaction` and write `ActivityRecorder`
   rows inside it.
3. **SPA calls ↔ routes ↔ api module.** All nine calls in `ordersApi` match registered URIs:
   list/get/put/patch/patch/delete on `/admin/orders(/{orderNumber})`, `/admin/shop4me-orders`,
   `/admin/transactions/{reference}/status`, and `storeOptions` → `GET /admin/stores` (parent
   admin group; shape `{data: [...stores]}` confirmed against the effective handler's
   `listPayload`, which carries `id`/`store_id`/`name`/`status`; the view degrades to a name input
   on 403). Every symbol the four SFCs import is exported by the api module.
4. **Route module shape / names.** `ad05-orders-oversight.php` contains only `Route::` lines with
   `permission:` gates — no prefix/name/auth wrapper; it inherits them from
   `routes/api/v1/admin.php`. A full-app scan of 1107 routes (1048 named) via the
   `RouteCollection` found **zero** duplicate names; the eight AD-05 names are unique, and the new
   `transactions.update-status` URI does not collide with the parent's `transactions.index/show`.
5. **Router / nav modules.** The router module default-exports `RouteRecordRaw[]` with relative
   child paths (`orders`, `orders/shop4me`, `orders/:orderNumber`), each with `meta.title`, and
   names unique against `src/router/index.ts` and every other module. Static `orders/shop4me`
   ranks above `orders/:orderNumber`. The nav module default-exports `Array<{label, nodes:
   NavNode[]}>` — the exact shape `AdminLayout.vue` globs and filters by `permission` — and the
   mount notes accurately describe the layout (its hardcoded Commerce section; the dashboard
   `stats.orders_pending` source).
6. **Imports resolve.** `@vue/compiler-sfc` parse + `compileScript` + `compileTemplate` pass on the
   four SFCs (re-run after this pass; no SPA edits were required), `typescript` `transpileModule`
   passes on the three TS modules, and every `@/` specifier resolves to a real file. No relative
   imports.
7. **PHP syntax / style.** `php -l` clean on all seven PHP files; `vendor/bin/pint --test` clean on
   the same seven.

**Test-file review (13 Pest tests, never executed — the fleet shares one test database and the
orchestrator runs it serially).** Helper names are unique across `tests/` (all `ad05`-prefixed);
`createBusinessOwner`/`setPermissionsTeamId` exist; the `Platform Admin` role is seeded with
`admin.orders`/`admin.transactions`; the superadmin path rides the `Gate::before` bypass; the
mail assertions match the controller (the mailable implements `ShouldQueue`, so `Mail::send()`
queues it and `Mail::fake()`/`assertQueued` capture it; the walk-in path asserts zero mail). Numeric
`assertJsonPath` values are safe: PHP's `serialize_precision=-1` encodes whole floats as integers,
so `assertSame(2000, …)` still passes against `(float)` casts. The payment-override test's four
audit rows, the soft-delete child-retention assertions, and the four refusal paths all line up with
the controller code read here.

## Observations (not defects, no change made)

- **`payment_status=failed` selects orders carrying a CANCELED transaction, but the derived badge
  never renders "failed".** The accessor's vocabulary has no failed branch, so those rows badge as
  Unpaid (when nothing else is live). This is the deliberate legacy mapping (legacy's Failed option
  mapped to CANCELED), it is documented in the facet's code comment, and the tests assert exactly
  this: `payment_status=failed` → the cancelled-only order, `payment_status=unpaid` → that order
  plus the no-transaction one.
- **The payment override never syncs `orders.amount_paid`.** Legacy did not either (only the
  transaction rows move), and every badge/stat the admin console renders derives from
  transactions, so parity is intentional; `amount_paid`/`remaining` on the detail payload can lag
  a manual override.

## Commands run

`php -l` on all seven PHP files; `vendor/bin/pint --test` on the same seven;
`php artisan route:list` (`--path=api/v1/admin`, `--path=api/v1/admin/orders`,
`--path=api/v1/management`) plus an all-route duplicate-name scan (1107 routes / 1048 named) and a
reflection check of every AD-05 route action; `php artisan tinker` smoke tests of the badge
derivation (pending-only → pending, refund+confirmed → refunded, cancelled-only → unpaid) and of
the generated SQL for all six payment facets; `@vue/compiler-sfc` parse+compile on the four SFCs;
`typescript` `transpileModule` on the three TS modules; import-resolution and route-name greps
across both SPAs and all route files. `php artisan test` and `npm run typecheck` were **not** run,
per the fleet instruction.
