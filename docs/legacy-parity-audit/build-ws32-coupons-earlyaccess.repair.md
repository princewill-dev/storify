# WS-32 — Coupons & Early-Access Redemption — repair pass

**Status:** verified, no defects found in workstream-owned files, no in-place
repairs required. This pass re-read every reported file against its actual
route/controller/module/journal targets and re-ran the mechanical checks.

**Scope:** the files reported by the WS-32 implementation — API
`Api\V1\Management\Subscription\{CouponController,EarlyPassController}` and
`routes/api/v1/management/ws32-coupons-earlyaccess.php`; the feature test
`tests/Feature/Api/ws32couponsearlyaccessTest.php`; SPA
`src/api/modules/ws32-coupons-earlyaccess.ts`, `src/stores/coupon.ts`,
`src/components/ws32/CouponRedemptionPanel.vue`,
`src/views/CouponRedemptionView.vue`,
`src/router/modules/ws32-coupons-earlyaccess.ts`,
`src/nav/modules/ws32-coupons-earlyaccess.ts`. Also read for dependency
verification: `App\Actions\Subscriptions\ActivateSubscriptionWith{Coupon,EarlyPass}`,
`CouponActivationResult`, `App\Models\{Coupon,EarlyPass,EarlyPassUsage}`,
`App\Mail\CouponExhaustedMail` (+ its Blade view), `App\Services\{StoreActivationNotifier,SubscriptionGate}`,
WS-07/WS-08 route modules and `PaymentController` (coupon-code contract), and
the management SPA shells (`router/index.ts`, `AppLayout.vue`, `@/nav/types`,
`PlansView.vue`, `subscription/SubscriptionPaymentView.vue`).

`php artisan test` and `npm run typecheck` were **not** run (the fleet shares
one test database and one toolchain — the orchestrator runs them serially).
Executed instead: `php -l` (all files), `php artisan route:list` (filtered +
`--json` app-wide duplicate scans), container resolution + reflection of all
three controller methods, `vendor/bin/pint --test`, `@vue/compiler-sfc`
parse/`compileScript`/`compileTemplate` on both SFCs, `ts.transpileModule` on
the four TS modules, a scoped `ts.createProgram` check of the four TS modules,
scripted resolution of every SPA and PHP import, and an app-wide
method+URI/name duplicate scan.

## Verification results

| Check | Result |
| --- | --- |
| Route → controller method exists, right signature | PASS — route module imports `CouponController` / `EarlyPassController`; container resolution succeeds and reflection shows `CouponController::validateCoupon(Request): JsonResponse`, `CouponController::removeCoupon(Request): JsonResponse` (route says `removeCoupon`, not legacy `remove`), `EarlyPassController::apply(Request): JsonResponse`, all public. Route paths/names (`plans.validate-coupon`, `plans.remove-coupon`, `subscription.check-early-pass`) all appear in `route:list` under `api/v1/management/` with the inherited auth/audience/team middleware plus `permission:settings subscription`. |
| House envelope + tenant scoping | PASS — every method returns `$this->ok(...)` / `$this->error(...)`; the only non-envelope paths are `$request->validate()` (standard 422) and the domain exceptions mapped to `$this->error(..., 422)`. Coupon lookup is scoped to `whereNull('business_id')->orWhere('business_id', $user->business_id)` (platform coupons or this business only — the test proves another tenant's coupon is "Invalid or expired"); `early_passes` is a platform table with no `business_id` column (confirmed against the migrations that add `business_id` "to all tables"), so the code lookup is unscoped by design while the mutation itself locks the user + business and filters stores by `business_id`. All three mutations (activate coupon, activate pass, usage write) live inside the actions' `DB::transaction` with `lockForUpdate`; no float arithmetic — naira→kobo is integer string parsing, percentage discounts use `intdiv`, fixed discounts are `min()`-capped. |
| SPA calls ↔ routes ↔ api module | PASS — the panel's only calls are `couponApi.validate/remove/checkEarlyPass`, each defined in `src/api/modules/ws32-coupons-earlyaccess.ts` and each matching a registered route 1:1 (`/management/plans/validate-coupon`, `/management/plans/remove-coupon`, `/management/subscription/check-early-pass`); response shapes line up with `CouponValidation` / `EarlyPassRedemption` (all keys present in both controller branches, including `discount_label` and the kobo fields). `CouponRedemptionView` additionally calls `subscriptionPlansApi.plans()` (`GET /management/subscription/plans`, WS-07 route + module, `PlansPayload` fields `plans`/`selected_plan_id`/`has_active_subscription` verified). The WS-08 checkout contract the workstream hands off to really does accept `coupon_code` on both `subscriptionBillingApi.payment()` (query param) and `processPayment()` (body), confirmed in `PaymentController::show/initialize`. |
| Route module wrapper / duplicate names | PASS — no prefix/name/auth wrapper; only the `permission:settings subscription` group, the exact idiom of sibling `ws07-subscription-plans.php` / `ws08-paystack-billing.php` (the gate cannot be inherited, and the staff-403 test depends on it). App-wide `route:list --json` scan over 1049 named routes: **0 duplicate names**; no other module file (management or admin) registers these URIs or names. Substring grep for the three route names hits only this module, the WS-09 gate's exemption prefixes, and the legacy web `routes/v1/management.php` (`management.*` namespace — a different name set). |
| Router/nav module shapes | PASS — router default-exports `RouteRecordRaw[]` with one child route `subscription/redeem` (no leading slash), `name: 'subscription.redeem'` (unique across all `src/router/modules/*.ts`), `meta.title: 'Redeem a code'`. Nav default-exports `NavGroup[]` matching `@/nav/types` (`{label, icon, items[]}`) with `permission: 'settings subscription'`, the same string the API routes and the seeder use; AppLayout's `../nav/modules/*.ts` glob merges it automatically. No path or label collision with WS-07/08/09 modules. |
| Imports resolve | PASS — scripted resolution of all 13 SPA `@/` imports lands on existing files; container-level `class_exists`/`interface_exists`/`trait_exists` on every `use` in the two controllers, route module and test file resolves cleanly. The one referenced cross-workstream surface — the `CouponExhaustedMail` view `emails.coupon.exhausted` — exists. No "vendor"-named identifiers anywhere in the workstream. |
| PHP syntax / Pint / SFC / TS | PASS — `php -l` clean on all four PHP files; `vendor/bin/pint --test` passed; both SFCs compile script+template via `@vue/compiler-sfc`; all four TS modules transpile clean and pass a scoped `ts.createProgram` check (only artifacts from the scoping itself: the `vite/client` type-root path and the `.vue` module resolution that `vue-tsc` handles — not file defects). |
| File ownership | PASS — git shows every WS-32 file as newly created (untracked); no shared file was touched (`routes/api/v1/management.php`, SPA `router/index.ts`, `AppLayout.vue`, `api/client.ts`, `api/endpoints.ts`, `PlansView.vue`, `SubscriptionPaymentView.vue` carry no WS-32 references/edits). |

## Fixes applied

None — nothing in the workstream-owned files required changing. (19 Pest
tests are written but deliberately unexecuted here; they are internally
consistent with the implementation — status codes, legacy messages, kobo
values, `forceFill` for the non-fillable `business_id`, and the mail fakes
were each traced against the controller/action code paths.)

## Verified-clean items of substance (deliberately not changed)

- **Legacy gate hole not reproduced.** `plans.remove-coupon` is registered and
  is exempt from the WS-09 subscription gate (`SubscriptionGate::EXEMPT_PREFIXES`
  covers `plans.`/`subscription.`), so a gated owner can clear the chip.
- **Silent-drop fix is real end-to-end.** The SPA always sends `plan_id`; the
  API answers a mis-scoped coupon with the legacy "This coupon only applies to
  {plan}." instead of dropping it, and WS-08's `initialize()` now errors (422)
  rather than nulling the coupon. Test coverage for both.
- **Money in kobo, integers only.** `toKobo()` parses naira decimals by string;
  percentages are basis points with `intdiv`; fixed discounts are capped with
  `min()`; `total_kobo` uses `max(0, …)`.
- **Exhaustion + admin alert.** Uses count increments under lock; at max the
  coupon is deactivated and `CouponExhaustedMail` is queued to
  `config('mail.admin_email')` with failure isolation, matching legacy.
- **Early pass parity.** Trimmed code lookup, active-default plan, exact legacy
  messages, per-user uniqueness, active-subscription refusal, 1-year
  subscription with metadata, usage row, pass auto-deactivation at max, store
  activation scoped to the redeeming business.

## Unfixable in this workstream's ownership (orchestrator wiring)

1. **Panel mount points.** `CouponRedemptionPanel.vue` is not rendered inside
   `src/views/PlansView.vue` or `src/views/subscription/SubscriptionPaymentView.vue`
   — both belong to WS-07/WS-08 and are off limits. The component ships with the
   exact mount note (place under the plan grid with
   `:plan-id="payload.selected_plan_id"`, and beside the pay button; feed
   `useCouponStore().code` into `subscriptionBillingApi.payment()/processPayment()`
   if WS-08's own coupon field is kept). Until then both affordances remain
   reachable through the standalone `/subscription/redeem` screen and nav entry,
   which are live.
2. **Two coupon fields on the payment page.** `SubscriptionPaymentView.vue`
   already has its own coupon input backed by WS-08's checkout coupon
   (`summary.coupon`) rather than `useCouponStore`; WS-32 cannot remove or
   reconcile it from its own files. The orchestrator should either drop one UI
   or have the WS-08 field write through the store, so a coupon applied on
   `/plans` and one applied on the payment page cannot disagree.
3. **Shell guard hazard once WS-07's fix lands.** WS-07's repair (correctly)
   asks the orchestrator to add `plans: 'plans'` to `ONBOARDING_ROUTES` in
   `src/router/index.ts` (shell workstream). When that happens, a user whose API
   `next` is `'plans'` — i.e. a gated owner, the main coupon-redeeming audience —
   will be redirected to `/plans` from any route other than `plans`, including
   `subscription.redeem`. Coupon and early-pass entry stay reachable for those
   users via the panel mounted on `/plans` (item 1), but if the standalone
   redeem screen should also stay reachable for gated owners, the shared guard
   map needs an explicit exception for `subscription.redeem`. Fixing that is an
   edit to a file this workstream does not own.
