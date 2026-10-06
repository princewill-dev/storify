# WS-12 repair — Order Fulfilment Actions, Activity & Emails

**Verdict:** repaired — every check in the repair brief passes. This pass fixed one latent test
defect (two `Customer::create` calls omitted NOT NULL columns, so two tests would have errored
against the live schema) and re-verified, line by line, the two code fixes and the regression
test that arrived with the previous pass. The three roadmap/integration items that cannot be
completed without editing files owned by other workstreams are unchanged and re-documented with
their exact blockers.

## Files verified

| Piece | Path |
| --- | --- |
| API controller | `app/Http/Controllers/Api/V1/Management/OrderFulfilmentController.php` |
| Route module | `routes/api/v1/management/ws12-order-fulfilment.php` |
| Migration | `database/migrations/2026_10_06_000001_add_delivery_agent_id_to_order_deliveries_table.php` |
| API test | `tests/Feature/Api/ws12orderfulfilmentTest.php` (19 tests) |
| SPA console | `storify-management/src/views/OrderFulfilmentView.vue` |
| SPA action bar | `storify-management/src/components/orders/OrderFulfilmentActions.vue` |
| SPA API client | `storify-management/src/api/modules/ws12-order-fulfilment.ts` |
| SPA router module | `storify-management/src/router/modules/ws12-order-fulfilment.ts` |
| SPA nav module | `storify-management/src/nav/modules/ws12-order-fulfilment.ts` |

## Checks

1. **Routes → controller methods.** All 10 registered routes resolve to public methods with the
   exact expected `(Illuminate\Http\Request, App\Models\Order)` signature, confirmed by
   `route:list` and reflection: `show`, `deliveryAgents`, `accept`, `process`, `dispatch`,
   `deliver`, `complete`, `cancel`, `returnOrder`, `updatePaymentStatus`. `{order}` binds through
   `Order::getRouteKeyName()` = `order_number`, which is what the SPA and tests pass.
2. **Envelope + tenant scoping.** Every public method returns through `$this->ok(...)` /
   `$this->error(...)` (409 refusals, the 422 invalid-agent errors keyed by
   `delivery_agent_id`, everything else 200). Each of the 10 opens with `authorizeOrder()`, which
   requires both `business_id` match and `accessibleStores()->whereKey($order->store_id)`. The
   dispatch agent is re-checked inside the order's business (`findDeliveryAgent`), the
   transactions touched by `updatePaymentStatus` are reached only through the authorised
   `$order`, and `returnOrder` resolves stock locations by the order's `store_id`. Every mutation
   runs inside `DB::transaction`; the status mail is queued after the commit.
3. **SPA calls ↔ routes ↔ api module.** All calls come from `orderFulfilmentApi`
   (`show`, `deliveryAgents`, `accept`, `process`, `dispatch`, `deliver`, `complete`, `cancel`,
   `returnOrder`, `updatePaymentStatus`) and map 1:1 to the 10 registered URIs. No raw axios call
   bypasses the module. `API_BASE_URL` already ends in `/api/v1`, so `/management/orders/...`
   resolves to the registered `api/v1/management/...` routes.
4. **Route-module shape / duplicate names.** The file contains only `Route::` lines (plus the
   explanatory docblock); no `prefix()`, no name group, no re-wrapping of the auth/audience/team
   chain — it inherits the management prefix, `api.management.` name and middleware. An app-wide
   `route:list --json` scan over 1107 routes shows **zero duplicate names**; the legacy web
   routes are `management.orders.accept` etc., distinct from `api.management.orders.*`. The
   `PUT|PATCH orders/{order}/payment` route is the only route on that method+URI (not shadowed).
5. **Router / nav modules.** The router module default-exports `RouteRecordRaw[]` with one child
   of `"/"`, path `orders/:orderNumber/fulfilment` (no leading slash), name `orders.fulfilment`
   (unique against the shell's `orders` / `orders.show` and WS-13's `orders.edit`), and
   `meta: { title: 'Order fulfilment' }`. The nav module default-exports `NavGroup[]` (empty,
   documented) in the shape `@/nav/types` defines and AppLayout's `../nav/modules/*.ts` glob
   consumes — identical to the WS-13 sibling module.
6. **Imports resolve.** PHP: `ApiController`, `ResolvesManagementContext`, `OrderStatus`,
   `TransactionStatus`, `OrderStatusUpdatedMail` (`__construct(Order, string, string)`),
   `ActivityLog` (fillable covers every written column), `DeliveryRoute`, `Order`, `OrderDelivery`
   (incl. `deliveryRoute`), `PaymentMethod` (`code` column exists via
   `2026_07_12_150444_add_type_to_payment_methods.php`), `StockLocation`, `Store` (`user`
   relation), `Transaction` (`storeBank`, `metadata` cast, `store_bank_id` fillable),
   `User` (`accessibleStores`, `status`, Spatie `role` scope), `StockLedgerService::recordAddition`
   (5-arg signature matches). SPA: `@/api/client` (`api`, `apiErrorMessage`), `@/stores/auth`
   (`can`), `@/stores/ui` (`success`/`error`), `@/components/AppModal.vue` (`modelValue`, `title`,
   `maxWidth`, `#footer`), `@/components/StatCard.vue` (`label`/`value`/`hint`/`accent`/`icon`),
   `@/components/StatusBadge.vue` (fallback for unmapped statuses such as `assigned`), and
   `@/nav/types` all exist with compatible shapes. `@vue/compiler-sfc` parse + `compileScript` +
   `compileTemplate` and a strict `typescript.transpileModule` pass are clean on all five files.
7. **PHP syntax.** `php -l` clean on the controller, route module, migration and test;
   `php artisan route:list` clean.

## Fix applied in this pass

1. `tests/Feature/Api/ws12orderfulfilmentTest.php` — the `Customer::create` calls in the
   "accepting a pending order…" and "status e-mails are deduplicated…" tests omitted the
   `phone` and `password` attributes. Both columns are `NOT NULL` with no default in the live
   test schema (verified through `information_schema` on `storify_test`; the restructure
   migration `2025_11_06_000001` adds a non-nullable `password` and never relaxes `phone`), so
   both tests would have failed with a MySQL integrity error before reaching their assertions.
   Both rows now carry `'phone' => '08011112222'` and `'password' => bcrypt('secret-pass-123')`,
   matching the helper idiom in `ws13ordersparityTest.php` / `ws19customersTest.php`. Every other
   insert in the file was cross-checked against the live schema and provides all NOT NULL
   columns (Store/Product auto-generation hooks cover `store_id`, `slug`, `product_code`).

## Fixes already present in the tree (re-verified, not re-made in this pass)

1. `OrderFulfilmentController::returnOrder` sums quantities per `product_id` before calling
   `StockLedgerService::recordAddition`, whose idempotency key is
   `hash(class:id:location:action)` — a naive per-line loop would silently restore only the first
   line's quantity when two lines share a product. Duplicate lines now restore the full quantity
   in one movement; distinct products still produce one movement each.
2. `OrderFulfilmentView.vue` treats `route.params.orderNumber` as a computed and watches it
   (resetting and reloading), so navigating between two orders' consoles no longer shows the
   previous order.
3. The regression test "returning sums duplicate product lines so the whole quantity is
   restored" (two lines of one product, 2 + 3 units; asserts +5 stock and exactly one
   `StockMovement`) is the 19th test in the file.

## Unfixable within this workstream's ownership

- **Roadmap item "tightens 2.8 (existing free-form status PUT)" is not implemented.** The route
  `PUT orders/{order}/status` and `OrderController@updateStatus` live in
  `routes/api/v1/management.php` and
  `app/Http/Controllers/Api/V1/Management/OrderController.php` — both explicitly off-limits
  shared/existing files. Re-registering the same URI from the WS-12 module cannot work: the
  shared file is required before the module glob and Laravel matches the first-registered URI, so
  the new route would be shadowed. The console and action bar expose only the guarded actions
  (no raw status dropdown), which demotes the free-form setter on this screen, but the endpoint
  itself remains unguarded and unlogged until the orchestrator (or a later workstream owning
  `OrderController`) applies the demotion.
- **The extended payment-status handler could not replace `PUT orders/{order}/payment-status`.**
  Same blocker (shared route file + existing `OrderController@updatePaymentStatus`) and the same
  shadowing behaviour. The extended five-status handler (`unpaid` voids instead of deleting,
  `payment_method_id`/`currency` on manual transactions, `paid_at` aligned to the mapped status)
  is served on the legacy `PUT|PATCH orders/{order}/payment` URI, documented at the top of the
  route module, and that is the URI the SPA calls. Clients still using `payment-status` get the
  old four-status behaviour until the orchestrator retires it.
- **The action bar is not mounted in the order detail screen, and nothing links to the
  standalone console.** Mounting `<OrderFulfilmentActions :order-number="…" @updated="load" />`
  or adding a link to `/orders/:orderNumber/fulfilment` means editing
  `storify-management/src/views/OrderDetailView.vue` (which currently renders the raw status
  select with the comment "WS-12 replaces this raw control with the guarded action bar") and/or
  `OrdersView.vue` — WS-13's existing views, not named as WS-12's. WS-12's side of the contract is
  complete: the component's header comment states the exact mount point, it loads its own
  fulfilment context and emits `updated`, and the standalone console route works when reached
  directly. The orchestrator/WS-13 must wire the mount and any link.

## Related risk outside this workstream (not fixed — other owners' files)

The same missing-column pattern found above also exists in other workstreams' test files, none of
which is WS-12's to edit: `tests/Feature/Api/ad07dashboardparityTest.php` (`ad07Customer`,
line 75), `tests/Feature/Api/ws27storetabsTest.php` (`ws27Customer`, line 72) and
`tests/Feature/Api/ws21invoicesTest.php` (three inline `Customer::create` calls, lines 171 / 546 /
554) all insert customers without `phone`/`password` and none of their call sites compensate.
On `storify_test` (MySQL, `strict => true`, no TestCase override) those inserts raise a NOT NULL
violation. Flagged here so the orchestrator's serial run is not misread as a WS-12 regression.

## Notes (no change made)

- `OrderDelivery::$fillable` (shared model) does not include the new `delivery_agent_id` column;
  the controller assigns the attribute directly on the instance and saves, so dispatch and every
  read path work. A future mass-assignment write (e.g. WS-26's dispatches board) must add the
  column to `$fillable`; deliberately not changed here to avoid editing a shared model another
  workstream may be touching.
- The action bar's payment select is initialised from the derived `payment_status`; for a
  derived value that is not manually settable (e.g. `partial`) the select shows its disabled
  placeholder, and clicking Apply without choosing would 422 on `Rule::in`. The current derived
  status is always rendered by the console header chip, and every settable value matches an
  option, so this is cosmetic.
- Migration not applied to the shared dev DB and the 19 tests not executed — both are
  orchestrator-side, per the brief.

## Verification performed this pass

- `php -l` on the controller, route module, migration and test file — clean.
- `php artisan route:list --path=api/v1/management` and a full `route:list --json` scan over
  1107 routes — all 10 WS-12 routes register with the inherited chain
  (`api → auth:sanctum → token.audience:management → SetPermissionsTeamId →
  permission:orders view|orders status_update`); zero duplicate route names app-wide.
- Reflection over the controller confirming all 10 route methods are public with
  `(Request, Order)` signatures.
- Cross-checked every model/enum/schema dependency: `OrderStatus` cases, `TransactionStatus`
  cases and value strings, `orders`/`order_deliveries`/`order_items`/`transactions`/`store_banks`
  columns and fillables (`customer_id` nullable since `2026_06_05_154858`; `driver_phone` exists
  so the migration's `after()` is valid), `stores.balance`, `PaymentMethod::code`, Spatie
  `scopeRole` + seeded `Delivery Agent` role with `orders status_update`, and the
  `Store Associate` role lacking it (the 403 test is correct). No duplicate `ws12*` test-helper
  names exist in the suite.
- Read-only `information_schema` query against `storify_test` listing every NOT NULL column with
  no default for `customers`, `stores`, `products`, `stock_locations`, `order_items`,
  `transactions`, `store_banks`, `payment_methods`, `orders`, `order_deliveries`, `activity_logs`
  and `users`, then matched each insert in the test file against it (this is how the missing
  `phone`/`password` on `Customer::create` was found).
- `@vue/compiler-sfc` parse + `compileScript` + `compileTemplate` and strict
  `typescript.transpileModule` on the two `.vue` and three `.ts` files — clean. (The fleet's
  `php artisan test` and `npm run typecheck` were not run, per the brief.)
