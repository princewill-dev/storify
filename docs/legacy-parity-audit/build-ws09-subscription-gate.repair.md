# WS-09 — Subscription Gate, Banners & Trial Lifecycle — repair pass

**Verdict:** clean. Every WS-09 route → controller method resolves, the envelope/tenant
rules hold, the SPA calls match registered routes and modules, the route module is
wrapper-free, there are no duplicate route names app-wide, and PHP/SFC/TS syntax plus Pint
are clean. The four repairs applied earlier are present in the tree and re-verified. Three
integrations still need the orchestrator because they live in shared files.

Not run, per instruction: `php artisan test`, `npm run typecheck`. Run instead: `php -l` on
all six PHP files, `php artisan route:list` (261 management routes; WS-09 route present;
`route:list --json` app-wide shows 1107 routes / 1048 names with zero duplicates), a
controller/route/alias probe via `artisan tinker`, `vendor/bin/pint --test` on the WS-09
files, and `@vue/compiler-sfc` parse/compile + `ts.transpileModule` for the SPA files.

## Verified in this pass

- `routes/api/v1/management/ws09-subscription-gate.php` → `GateController@status` exists
  with the right signature (`Request → JsonResponse`); `method_exists` probe returns true
  and `route('api.management.subscription.status')` resolves. The route registers as
  `GET /api/v1/management/subscription/status` and inherits the group stack
  (`auth:sanctum`, `token.audience:management`, `team.context`/`SetPermissionsTeamId`,
  `permission:dashboard view`). The module file contains no prefix/middleware group.
- The `management.subscription` middleware alias still maps to the legacy web
  `CheckSubscription` class (`app('router')->getMiddleware()`), which is why the module
  intentionally does not use that string.
- `GateController@status` returns `$this->ok([...])` (house envelope) and every read is
  scoped to `$user->business` / `$user->business_id`; no request ids are trusted.
- App-wide duplicate-route-name scan: zero duplicates; `subscription.status` is used only
  by this module (the sibling `subscription.*` routes from WS-07/08/32 do not collide).
- SPA: `subscriptionGateApi.status()` → the route above, exported from
  `src/api/modules/ws09-subscription-gate.ts`; `SubscriptionGateView.vue` and
  `SubscriptionBanner.vue` import only existing modules (`@/api/client`, `@/lib/money`,
  `StatusBadge.vue`, `SubscriptionBanner.vue`); every import specifier resolves to a file
  on disk. The router module default-exports `RouteRecordRaw[]` with `meta: { title }` and
  a no-leading-slash path (`subscription/gate`); the nav module default-exports
  `NavGroup[]` (empty by design — see Notes).
- Guard destinations exist: `/plans` (ws07), `/subscription` (ws07),
  `/subscription/payment|callback|payments` (ws08), `/kyc` (ws10), `/verify-otp`, `/setup`,
  `/profile` (shell) — and the guard's exempt matching covers the API's `subscription.`
  prefix (incl. WS-32's `subscription.redeem`). `DashboardView.vue` already mounts
  `<SubscriptionBanner />`.
- Test file: helper names (`ws09*`) collide with nothing else in `tests/`,
  `createBusinessOwner`/`freezeTime` exist, and every model scope/column/constant and both
  mailables' constructor signatures it touches match the shipped code. Not executed
  (explicitly forbidden — shared test DB).

## Repaired in place (present in the tree; re-verified)

1. **`routes/api/v1/management/ws09-subscription-gate.php` — no middleware-alias
   override.** `bootstrap/app.php:45` maps `management.subscription` to the legacy web
   `CheckSubscription` middleware, still used by `routes/v1/management.php:93`. A
   route-file alias override would silently retarget the legacy app (uncached) and a
   `route:cache`d deployment could resolve the API group back to the legacy redirect
   middleware. The module's wiring note asks for the middleware **class** reference, which
   is cache-safe.
2. **`app/Http/Middleware/EnsureManagementSubscription.php`** — registration docblock
   matches (class reference + alias-collision warning).
3. **`app/Jobs/ProcessTrialExpirations.php`** — `oncePerTrial()` releases its `Cache::add`
   claim on `Throwable` and rethrows, so a transient mail/queue failure retries on the next
   tick instead of dropping a reminder or the expiry stage forever; a successful send still
   fires exactly once.
4. **SPA gate — exempt matching mirrors the API namespaces.** `isSubscriptionGateExempt()`
   in `src/api/modules/ws09-subscription-gate.ts` matches the exact list plus the
   `subscription.` prefix, so WS-32's `subscription.redeem` is not bounced to `/plans`
   mid-redemption.

## Unfixable here (orchestrator — shared files)

1. **`routes/api/v1/management.php`** (owned by another agent): add the gate to the
   management group's middleware array as a **class reference**:
   `use App\Http\Middleware\EnsureManagementSubscription;` then
   `->middleware(['auth:sanctum', 'token.audience:management', 'team.context', EnsureManagementSubscription::class])`.
   Do **not** write `'management.subscription'` — that string is the legacy web alias
   (`CheckSubscription`); it would apply the wrong middleware to the API.
2. **`src/router/index.ts`** (shell workstream): add `plans: 'plans'` to `ONBOARDING_ROUTES`
   so the API's `next: "plans"` is consumed (WS-07 supplies the screen).
3. **`src/router/index.ts`**: call `installSubscriptionGate(router)` once, after the
   existing `beforeEach`
   (`import { installSubscriptionGate } from './modules/ws09-subscription-gate'`).
   Nothing else in the SPA references it yet, so until this is wired the SPA redirects
   nothing; and until (1) the API refuses nothing.

## Notes (no action needed)

- The roadmap said to fold the banner payload into `GET /management/dashboard`; WS-09 put
  it on `GET /management/subscription/status` because the dashboard controller belongs to
  another workstream. The four variants, copy and CTAs match
  `resources/views/management/dashboard.blade.php:39–74` apart from the expired-trial body
  (legacy claimed stores were paused during the 3-day grace window; this says when they
  pause) and the "ends in 0 days" title (now "ends today").
- The nav module intentionally exports an empty `NavGroup[]`: WS-07 already ships the
  permission-gated "Subscription" entry; a second group would duplicate the section.
- `SubscriptionGate::EXEMPT_ROUTES` keeps legacy names that no longer exist
  (`plans.check-early-pass`, `auth.*`, `kyc.index/…`); harmless documentation of the legacy
  list, covered anyway by the namespace prefixes.
- The plans payload (WS-07 `PlanController@plans`) already carries the trial settings
  read path (6.6), and `routes/console.php` already schedules `ProcessTrialExpirations`
  daily at 08:00.
