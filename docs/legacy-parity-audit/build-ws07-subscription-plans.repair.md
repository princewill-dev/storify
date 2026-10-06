# WS-07 — Subscription Plans & Trial Onboarding — repair pass

Verified against the actual files (not the completion report), re-run in full after a
recharge/continuation. Workstream files:

- API: `app/Http/Controllers/Api/V1/Management/Subscription/PlanController.php`,
  `routes/api/v1/management/ws07-subscription-plans.php`,
  `tests/Feature/Api/ws07subscriptionplansTest.php`
- SPA (management): `src/views/PlansView.vue`, `src/views/SubscriptionView.vue`,
  `src/components/subscription/PlanCard.vue`, `src/api/modules/ws07-subscription-plans.ts`,
  `src/router/modules/ws07-subscription-plans.ts`, `src/nav/modules/ws07-subscription-plans.ts`,
  `src/lib/money.ts`

## Verdict

All seven checklist items pass. No new defects were found in this pass; the one in-scope
repair from the earlier pass is present and re-verified. One acceptance criterion
(`next: "plans"` landing on `/plans`) remains blocked by a shared file WS-07 does not own —
recorded under Unfixable.

## Fixed (in place)

1. **Route module declares no prefix/name wrapper of its own.** It previously wrapped the
   four routes in a nested `prefix('subscription')->name('subscription.')` group. It now
   writes paths and names out in full inside a single `permission:settings subscription`
   group — the same shape as the sibling subscription-family modules
   (`ws08-paystack-billing.php`, `ws10-kyc.php`, `ws12-order-fulfilment.php`,
   `ws32-coupons-earlyaccess.php`). Prefix, name prefix and auth/audience/team middleware
   are inherited from the parent group exactly once.

   Registration verified with
   `php artisan route:list --path=api/v1/management/subscription --json`:

   | Method | URI | Name |
   |---|---|---|
   | GET | api/v1/management/subscription/plans | api.management.subscription.plans |
   | GET | api/v1/management/subscription | api.management.subscription.show |
   | POST | api/v1/management/subscription/select-plan | api.management.subscription.select-plan |
   | POST | api/v1/management/subscription/change-plan | api.management.subscription.change-plan |

   All four carry `auth:sanctum`, `token.audience:management`, team context
   (`SetPermissionsTeamId`) and `permission:settings subscription` — a permission the
   `SpatiePermissionSeeder` defines (`'settings' => ['view','edit','payment','subscription']`)
   and the owner's Super Admin role holds.

## Unfixable (file owned by another workstream)

1. **SPA guard does not honour `next: "plans"`.** `src/router/index.ts` (shell workstream,
   on the do-not-edit list) has an `ONBOARDING_ROUTES` map that is deliberately missing
   `plans: 'plans'` ("`plans` is deliberately absent until that workstream lands"), and it
   has no `installSubscriptionGate(router)` call either. The API returns `next: "plans"`
   for an owner without an active subscription or live trial
   (`Api/V1/Auth/Concerns/BuildsAuthResponses::nextStep`), so without the mapping a fresh
   owner lands on the dashboard instead of the WS-07 screen. Both
   `src/router/modules/ws07-subscription-plans.ts` and
   `src/router/modules/ws09-subscription-gate.ts` document the required one-line wiring
   (`plans: 'plans'`, plus WS-09's gate install) for the orchestrator. Editing
   `src/router/index.ts` here would breach file ownership, so it is left to the
   orchestrator.

## Verified clean (this pass, no change needed)

- **Routes → controller methods**: all four point at methods that exist with the right
  signature (`plans`, `show`, `select`, `change`, each `(Request): JsonResponse`) on
  `App\Http\Controllers\Api\V1\Management\Subscription\PlanController`; the container
  resolves the constructor-injected `SubscriptionTrialSettings`.
- **Envelope + tenancy**: every method returns `$this->ok(...)`/`$this->error(...)`; all
  reads go through `$request->user()` (`business?->activeSubscription()`,
  `Payment::forBusiness($user->business_id)` — scope exists on the `BelongsToBusiness`
  trait), never an id from the request. The plan catalogue is platform-wide by design.
- **Contract match**: SPA module endpoints (`/management/subscription/plans`,
  `/management/subscription`, `/management/subscription/select-plan`,
  `/management/subscription/change-plan`) match the registered URIs, and the payload types
  mirror the controller's keys exactly
  (`plans/monthly/yearly/other/yearly_savings_percent/trial_*`,
  `subscription/trial/selected_plan/billing_history`). `CouponRedemptionView.vue` (WS-32)
  consumes only `subscriptionPlansApi.plans()` + `PlansPayload`, both still exported.
  `PlansView`/`SubscriptionView`'s `router.hasRoute('subscription.payment')` guard matches
  WS-08's actual route name.
- **No duplicate route names**: full-app scan of `route:list --json` finds 0 duplicates
  across 1048 named routes. No sibling module re-registers the same URIs; WS-09's gate
  exempts the whole `subscription.` name family (`SubscriptionGate::EXEMPT_PREFIXES`).
- **Router/nav module shapes**: router module default-exports `RouteRecordRaw[]` children
  of `/` (`path: 'plans'`, `path: 'subscription'`, both with `meta.title`, no leading
  slash); the shell parent route already carries `meta: { requiresAuth: true }`. Nav module
  default-exports `NavGroup[]` for `AppLayout`'s `../nav/modules/*.ts` glob, gated on
  `settings subscription`, and no other nav module duplicates the path or label (WS-08's
  billing group explicitly defers the "Subscription" entry to WS-07).
- **Imports resolve**: every `@/...` import in the seven SPA files exists (`api/client`,
  `api/modules/ws07-subscription-plans`, `stores/auth` — `fetchMe`/`can` exported,
  `stores/ui` — `success`/`error`/`info` exported, `lib/money`, `components/AppModal.vue`
  with `modelValue` + `maxWidth`, `components/StatusBadge.vue`, `nav/types`). The three
  SFCs compile with `@vue/compiler-sfc` and the four TS modules parse clean with the
  repo's local `typescript`.
- **PHP syntax**: `php -l` clean on the controller, route module and test file.
- **Money**: the only arithmetic (`yearly_savings_percent`) is integer kobo with integer
  half-up rounding; `(float)` casts are display copies paired with exact `*_kobo` fields.
  The formula matches legacy `SubscriptionPlanController::yearlySavings` for positive
  savings.
- **Legacy parity**: select/change validation, messages, log lines (`subscription.plan_changed`
  with old/new plan ids), trial start-once (legacy restarted the trial on every selection —
  deliberate improvement), unverified refusal (legacy force-set `is_verified = true` —
  deliberate improvement, documented), billing history surviving expiry (legacy scoped it
  to the active subscription — deliberate improvement), select CTA staying visible for a
  user with `selected_plan_id` set (verify §1.4 `missing` — the improvement the roadmap
  asked for).
- **Tests**: `ws07subscriptionplansTest.php` (16 tests) matches the implemented behaviour —
  trial-starts-once, unverified refusal, inactive-plan refusal, cross-business isolation,
  expired-subscription billing history, permission and auth refusals. Helper names are
  `ws07*`, unique across the test suite; `createBusinessOwner` returns `[owner, business]`
  and owners hold Super Admin (all permissions), so the permission gate assertions are
  sound.
- **House rules**: no `vendor` naming anywhere in the WS-07 files; tenant-scoped mutations
  (`user->update` on select, `subscription->update` on change) run in `DB::transaction`.
  `src/lib/money.ts` is now a shared dependency of ~20 other views — its three exports
  (`money`, `formatDate`, `currencySymbol`) are unchanged and complete.

Commands run: `php -l` (all three PHP files),
`php artisan route:list --path=api/v1/management/subscription` and `--json` duplicate-name
scan, local `@vue/compiler-sfc` SFC compile, local `typescript` `transpileModule` syntax
check, plus grep-based resolution of every import and route name. Tests and
`npm run typecheck` were not run, per instructions.
