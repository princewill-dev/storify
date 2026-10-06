# WS-08 — Paystack Billing & Activation — repair pass

Verified against the actual files (not the completion report). Workstream files:

- API: `app/Http/Controllers/Api/V1/Management/Subscription/PaymentController.php`,
  `routes/api/v1/management/ws08-paystack-billing.php`,
  `tests/Feature/Api/ws08paystackbillingTest.php`
- SPA (management): `src/views/subscription/SubscriptionPaymentView.vue`,
  `src/views/subscription/SubscriptionPaymentCallbackView.vue`,
  `src/views/subscription/SubscriptionPaymentsView.vue`,
  `src/api/modules/ws08-paystack-billing.ts`,
  `src/router/modules/ws08-paystack-billing.ts`,
  `src/nav/modules/ws08-paystack-billing.ts`

## Verdict

Passes all seven checklist items after four in-scope repairs (below). Two
cross-cutting items — the SPA onboarding redirect and the generic-transactions
Confirm/Refund actions on subscription rows — live in files this workstream
does not own; recorded under Unfixable with the exact orchestration required.
The WS-09 gate concern raised in the completion report is resolved: WS-09 landed
with namespace exemptions (`subscription.` prefix on both API and SPA) that keep
every WS-08 route reachable.

## Fixed

1. **Route module carried its own `prefix()`/`name()` wrapper.**
   `ws08-paystack-billing.php` wrapped the four routes in
   `Route::middleware('permission:settings subscription')->prefix('subscription')->name('subscription.')->group(...)`.
   Paths and names are now written out in full inside a single permission
   middleware group — the convention the sibling repaired modules state
   (`ws07-subscription-plans.php`, `ws32-coupons-earlyaccess.php`,
   `ws12-order-fulfilment.php`): the file no longer declares a nested
   prefix/name wrapper of its own, and the permission group is kept because the
   parent group in `routes/api/v1/management.php` carries only
   auth/audience/team and this is the house-rule route gate. Registration is
   byte-for-byte identical afterwards:

   | Method | URI | Name | Middleware (tail) |
   |---|---|---|---|
   | GET | api/v1/management/subscription/payment | api.management.subscription.payment | `permission:settings subscription` |
   | POST | api/v1/management/subscription/process-payment | api.management.subscription.process-payment | `permission:settings subscription` |
   | GET | api/v1/management/subscription/callback | api.management.subscription.callback | `permission:settings subscription` |
   | GET | api/v1/management/subscription/payments | api.management.subscription.payments | `permission:settings subscription` |

   All four still inherit `auth:sanctum`, `token.audience:management` and
   `team.context` from the parent group.

2. **Callback notifications and ledger attribution followed whoever opened the
   callback, not the payer.** `PaymentController@callback` used
   `$this->user($request)` for `StoreActivationNotifier::send(...)` and
   `postSubscriptionPayment($payment, $user->id)`. Any teammate holding
   `settings subscription` (CFO, Manager — the seeder grants both) can complete
   the Paystack return leg; for a staff member `User::stores()` is empty, so
   `StoreActivationNotifier::send()` returned early and **no `StoreActivated` /
   `AdminStoreCreated` mail went out at all**. The callback now resolves the
   payment's user (`User::query()->find($payment->user_id)`, falling back to the
   request user), so mail and the journal `user_id` are attributed to the payer
   regardless of who lands on the callback.

3. **Checkout blocked zero-amount coupon activation when Paystack is
   unconfigured.** `SubscriptionPaymentView.vue` disabled the Pay button on
   `!summary.gateway_available` and showed "Card payments are not available…".
   That also blocked the fully-covering-coupon path, which activates with **no
   gateway call at all** (`initialize()` branches to `activateWithCoupon()`
   before any Paystack request). The condition is now
   `!summary.gateway_available && !isInstantActivation` on both the button and
   the warning; paid checkout still requires a configured gateway.

4. **Test fixture silently dropped the tenant scope.** In
   `ws08paystackbillingTest.php` the cross-tenant coupon was created with
   `Coupon::create(['business_id' => $otherBusiness->id, ...])`, but
   `business_id` is not in `Coupon::$fillable` (and the app does not enable
   `preventSilentlyDiscardingAttributes`), so it was silently discarded and the
   coupon became platform-wide — the "must not leak across tenants" assertion
   could never fail. The test now sets it via `forceFill([...])->save()`.

## Unfixable (file owned by another workstream)

1. **SPA guard does not honour `next: "plans"`.** `src/router/index.ts` (shell
   workstream, on the do-not-edit list) has an `ONBOARDING_ROUTES` map missing
   `plans: 'plans'` ("`plans` is deliberately absent until that workstream
   lands" — still true at this pass). The API returns `next: "plans"` for an
   owner without an active subscription or live trial
   (`BuildsAuthResponses::nextStep`), so without the mapping a fresh owner lands
   on the dashboard instead of `/plans` (WS-07's screen, which exists).
   Orchestrator must add `plans: 'plans'` to that map (WS-07 and WS-09 repair
   passes document the same wiring).

2. **Generic transactions Confirm/Refund are unsafe for subscription rows.**
   WS-08 writes a `transactions` row (`business_id` set, no
   `order_id`/`invoice_id`) so subscription payments surface in
   `GET /management/transactions`, matching legacy. `Api\V1\Management\TransactionController@confirm`
   then throws `RuntimeException('Transaction has no associated store.')` →
   HTTP 500, and `refund` dies on `$transaction->order->store` (409 with a PHP
   null message). Both files (`app/Http/Controllers/Api/V1/Management/TransactionController.php`,
   `src/views/TransactionsView.vue`) are shared and owned by other workstreams;
   the current `TransactionsView.vue` exposes no Confirm/Reject/Refund actions
   yet, so the exposure is latent until the transactions workstream wires them.
   The fix belongs there: gate those actions by `order`/`invoice` presence (the
   audit verify §2.4 note).

## Verified clean (no change needed)

- **Routes → controller methods**: `subscription.payment → show`,
  `subscription.process-payment → initialize`, `subscription.callback → callback`,
  `subscription.payments → payments`; all four public methods exist on
  `App\Http\Controllers\Api\V1\Management\Subscription\PaymentController`
  (checked by reflection; autoload resolves the class and every imported
  dependency). No `prefix`/`name` re-declaration in the module; all four
  inherit the parent group's middleware plus the permission gate.
- **Envelope + tenancy**: every method returns `$this->ok(...)`/`$this->error(...)`
  (no raw `response()->json`); `Payment`, `Subscription` and `Transaction` rows
  are all written with `$user->business_id`, `findByKey()` and the callback's
  payment lookup are scoped by `business_id` (cross-tenant reference returns 404
  with the row untouched — covered by test), and the callback looks up the
  coupon by globally-unique `coupons.code` (unique index confirmed). No request
  id is trusted without a business-scoped lookup.
- **SPA ↔ API contract**: `subscriptionBillingApi.payment/processPayment/verify/payments`
  call exactly the four registered URIs (`/management/subscription/payment`,
  `/process-payment`, `/callback`, `/payments`);
  `src/api/modules/ws08-paystack-billing.ts` exports every symbol the three
  views import (`formatKobo`, `intervalLabel`, `subscriptionBillingApi`, the
  payload types); `auth.fetchMe()` → `api/v1/management/auth/me` (registered).
- **No duplicate route names**: full-app `route:list --json` scan — 1048 named
  routes, 0 duplicates; the module's four names are unique and do not collide
  with WS-07 (`subscription.show/plans/select-plan/change-plan`), WS-09
  (`subscription.status`) or WS-32 (`plans.*`, `subscription.check-early-pass`).
  SPA router names are unique across `src/router/modules/*.ts`.
- **Router/nav shapes**: router module default-exports `RouteRecordRaw[]`
  children of `/` (`subscription/payment`, `subscription/callback`,
  `subscription/payments`, all with `meta.title`, no leading slash); nav module
  default-exports `NavGroup[]` matching `src/nav/types.ts` and the shape
  `AppLayout.vue` merges from `../nav/modules/*.ts` (`label`/`icon`/`items`
  with `permission`), gated on the seeded `settings subscription` permission.
- **Imports resolve**: every `@/...` import in the six SPA files resolves to an
  existing file (script-checked); the three SFCs compile with
  `@vue/compiler-sfc` (script *and* template) and the three TS modules
  transpile clean with `tsc.transpileModule`.
- **PHP syntax**: `php -l` clean on controller, route module and test; the three
  files pass `vendor/bin/pint --test`.
- **Money**: all arithmetic is integer kobo (`toKobo`/`toNaira` string math,
  `intdiv` for percentage coupons); the gateway comparison is exact kobo and
  exact currency. No float arithmetic on money anywhere in the controller.
- **WS-09 gate exemptions**: `App\Services\SubscriptionGate::EXEMPT_PREFIXES`
  includes `subscription.` (so payment/process-payment/callback/payments are
  reachable while gated), and the SPA's `isSubscriptionGateExempt` exempts the
  `subscription.` name prefix covering all three WS-08 SPA routes.
- **WS-07 billing history**: `PlanController::billingHistory()` reads the
  `Payment` rows this workstream writes, so WS-08's payment rows render both on
  `/subscription` (WS-07's table) and on the dedicated `/subscription/payments`
  view.
- **Idempotency + DB contract**: `payments` has a
  `unique(business_id, idempotency_key)` index, so the controller's
  `UniqueConstraintViolationException` race handling is real; `transactions`
  and `payments` columns used (`gateway_response`, `gateway_reference`,
  `paid_at`, `failure_reason`, nullable `order_id`/`payment_method_id`) all
  exist.
- **Tests**: `ws08paystackbillingTest.php` — 19 tests with uniquely-prefixed
  helpers (`ws08Token`/`ws08Plan`/`ws08Store`/`ws08FakeGateway`, no collisions
  across the suite); every referenced model/mailable/service/helper exists
  (`createBusinessOwner`, `setPermissionsTeamId`, `LedgerSetupService::ensureForBusiness`,
  `Business::hasActiveSubscription`, mailables, `User::ROLE_SUPERADMIN`), the
  permission fixtures match the seeder (owner role holds `settings subscription`,
  Store Associate does not), and the ledger idempotency key
  (`subscription_payment:{id}`) matches `LedgerPostingService`. Authored but not
  executed here, per fleet rules.

Commands run on this pass: `php -l` (controller, route module, test),
`php artisan route:list --path=api/v1/management` plus a full-app `--json`
duplicate-name scan (1048 named routes, 0 duplicates) and middleware dump for
the four WS-08 routes, PHP autoload/reflection existence checks on the
controller and all imports, `vendor/bin/pint --test` on the three files,
`@vue/compiler-sfc` parse/compileScript/compileTemplate on the three SFCs,
`tsc.transpileModule` on the three TS modules, and a resolution script for
every `@/` import in the six SPA files. `php artisan test` and
`npm run typecheck` were not run, per instructions.
