# WS-17 — POS Oversight & Sessions — repair pass

Verified against the actual files (not the completion report). Workstream files:

- API: `app/Http/Controllers/Api/V1/Management/Pos/SessionController.php`,
  `app/Http/Controllers/Api/V1/Management/Pos/OrderSourceController.php`,
  `routes/api/v1/management/ws17-pos-oversight.php`,
  `tests/Feature/Api/ws17posoversightTest.php`
- SPA (management): `src/api/modules/ws17-pos-oversight.ts`,
  `src/router/modules/ws17-pos-oversight.ts`, `src/nav/modules/ws17-pos-oversight.ts`,
  `src/views/PosSessionsView.vue`, `src/views/PosSessionDetailView.vue`,
  `src/components/pos/PosStoreControlCard.vue`, `src/components/pos/PosNavStores.vue`
- Admin SPA: no WS-17 surface (the admin roadmap has no POS workstream).

## Verdict

All seven checklist items pass after five in-scope repairs (three carried from the earlier
pass and re-verified in place, two added here). Every route resolves to an existing method with
the right signature, every method returns the house envelope under tenant scope, and every SPA
call matches a registered route and an exported module function. The only work the workstream
cannot land itself is the documented component-mount hand-off (orchestrator-owned shared files).

## Fixed

1. **(carried, re-verified) `PosSessionsView.vue` kept the previous store's rows when only the
   route param changed.** The per-store log (`/stores/:storeId/pos/sessions`) and the global
   list share one component, so Vue Router reuses the instance when the store id changes and
   `onMounted` never refires. The load is now
   `watch(storeCode, () => load(1), { immediate: true })`; `onMounted` is no longer imported.
2. **(carried, re-verified) `PosRecentSession.store` was typed as a full `PosStore` but the API
   sends the lite shape.** `SessionController::detail()` builds `staff_recent_sessions[].store`
   from `with('store:id,store_id,name')` — no `pos_enabled`. Ret-typed to
   `Pick<PosStore, 'id' | 'store_id' | 'name'> | null` with a comment. (List rows really do
   carry the full `PosStore` from `storePayload()` — unchanged.)
3. **(carried, re-verified) Pint was not clean, contrary to the completion report.**
   `SessionController.php` had `ordered_imports` / `fully_qualified_strict_types` /
   whitespace issues; all four WS-17 PHP files now pass `vendor/bin/pint --test`.
4. **(new) `PosStoreControlCard.vue`'s MOUNT ME comment pointed the orchestrator at a broken
   wiring and a duplicated card.** It said to place the card "beside the Web Storefront card"
   in `views/store-tabs/StoreDashboardTab.vue` passing `:store-id="store.store_id"` — but that
   tab has no `store` binding (the store hangs off its `dashboard: StoreDashboard` prop, so the
   correct expression is `dashboard.store.store_id`), and the tab already renders WS-03's
   read-only "POS Terminal" placeholder (`dashboard.pos.*`, lines ~223–262) which this card
   supersedes. Following the old hint produced an undefined prop and two POS cards. The comment
   now says to **replace** the `dashboard.pos.*` block, gives the correct expression for the tab
   and for `StoreDetailView.vue` (where `store.store_id` is valid), and warns that mounting
   beside the placeholder double-renders.
5. **(new) `PosNavStores.vue`'s mount note did not state the sidebar coalesce choice.** The
   component renders the whole legacy block — its own POS link with the live badge plus per-store
   rows — so once the orchestrator mounts it, `nav/modules/ws17-pos-oversight.ts`'s static "POS"
   group points at `/pos/sessions` a second time. The comment now documents the choice (wire the
   component *instead of* the static entry; keep the module only while the component is
   unmounted), matching the pattern WS-15 documents for the Inventory group. No code change —
   the module must keep exporting the destination until the component is mounted.

## Unfixable / not mine to land

1. **Mounting the two components.** `PosStoreControlCard.vue` belongs in WS-03's existing
   `src/views/store-tabs/StoreDashboardTab.vue` (replacing the read-only `dashboard.pos.*` block)
   and `PosNavStores.vue` inside `src/layouts/AppLayout.vue`'s sidebar `<nav>` after the groups
   loop. Both are existing shared views owned by WS-03 / the shell workstream; the house rules
   forbid editing them from here. The components carry corrected top-of-file MOUNT ME comments.
2. **Coalescing the static POS nav entry once the component is mounted.** That edit is in
   `AppLayout.vue` (shared) or must be coordinated with the orchestrator; removing the module's
   entry today would leave the interim sidebar without any POS destination.
3. **`VITE_POS_URL` is not in `storify-management/.env.example`.** Optional per the completion
   report: `posAppUrl()` derives `https://pos.<main-domain>` and matches WS-03, which imports the
   same helper. `.env.example` is a shared root file, left to the orchestrator.

## Verified clean (no change needed)

1. **Routes → controller methods and signatures.** All nine route actions exist:
   `SessionController@{overview,index,show,storeIndex,storeShow,open,close,enable}` and
   `OrderSourceController@index`. `php artisan route:list` JSON confirms every URI/action plus
   exactly one permission middleware per route (`pos view_history` reads, `pos open_session`,
   `pos close_session`, `stores settings` for enable, `orders view` for the takeover) stacked on
   the inherited `auth:sanctum`, `token.audience:management`, `team.context`. `{store}` binds via
   `Store::getRouteKeyName()` = `store_id`, `{session}` via `PosSession::getRouteKeyName()` =
   `session_code` — exactly what the SPA passes.
2. **Envelope + tenancy.** Every action returns `$this->ok(...)` / `$this->error(...)`
   (errors: POS-disabled 422, duplicate-open 422, no-open-session 422, deleted store 404,
   cross-tenant 403/404). Reads are scoped in `sessionQuery()` by `business_id` +
   `accessibleStoreIds()` + non-deleted store; `store_id` filters re-authorize (`authorizeStoreId`
   → 403); open/close/enable re-check `authorizeStore()` plus `Store::STATUS_DELETED`. All
   mutations (open/close/enable) sit in `DB::transaction`. Money is integer kobo and `close()`
   delegates reconciliation to `PosSession::close()` / `calculateCashSalesTotal()` — no second
   formula. The orders takeover scopes by `business_id` + accessible stores.
3. **SPA calls ↔ routes ↔ api modules.** All nine `posApi` URLs match registered URIs (GET/POST
   included); every symbol the four SFCs import (`posApi`, `posAppUrl`, `PosSessionRow`,
   `PosStore`, `PosStats`, `PosSessionDetail`) is exported by
   `src/api/modules/ws17-pos-oversight.ts`; shared imports (`formatKobo` from
   `ws08-paystack-billing`, `apiErrorMessage`, `useAuthStore`, `useUiStore`, `StatusBadge`,
   `StatCard`, `PaginationBar`, `AppModal`) resolve to real exports/files. Six files import the
   module; WS-03's `StoreDetailView.vue` and WS-20's `AcceptInviteView.vue` consume the
   load-bearing `posAppUrl` export.
4. **Route module shape / names.** `ws17-pos-oversight.php` has no prefix or auth wrapper — only
   `permission:` gates, which the house rules require. A full-app scan of 1107 registered routes
   (1049 named) found **zero** duplicate route names. The `GET orders` re-registration reuses
   `orders.index`; Laravel keys by method+URI, so the earlier `OrderController@index` is fully
   replaced — `route:list` resolves that URI to `OrderSourceController`, and
   `ManagementModulesApiTest`'s existing assertion (`data.0.order_number`) still holds. The
   payload is a strict superset of `OrderController@payload()` (same keys/order, plus `is_pos`
   and `pos_session_id`).
5. **Router / nav modules.** The router module default-exports `RouteRecordRaw[]` with relative
   child paths (`pos/sessions`, `pos/sessions/:sessionCode`, `stores/:storeId/pos/sessions`,
   `stores/:storeId/pos/sessions/:sessionCode`), each carrying `meta: { title, permission }`;
   the four names are unique app-wide and match the paths the views/card/nav link to. The nav
   module default-exports `NavGroup[]` (`label`/`icon`/`items[]`) per `@/nav/types`, consumed by
   AppLayout's `import.meta.glob('../nav/modules/*.ts')`.
6. **Imports resolve.** No missing file, no wrong relative path; the four SFCs pass
   `@vue/compiler-sfc` parse + `compileScript` + `compileTemplate` (re-run after the edits), and
   the three TS modules pass a `typescript` `transpileModule` syntax check.
7. **PHP syntax / style.** `php -l` clean on both controllers, the route module and the test
   file; `pint --test` clean on all four.

**Test-file review (22 tests, never executed — the fleet shares `storify_test`).** Walked every
test against the controller, models, migrations and `SpatiePermissionSeeder`: `ws17*` helper
names are unique across `tests/`; `createBusinessOwner` exists; `pos view_history`,
`pos open_session`, `pos close_session` and `stores settings` are seeded and the role
expectations match (Cashier lacks `stores settings` → 403 enable test; Store Associate has
`pos view_history` and no `transactions view` → `isRestrictedStaff()` routes it through
`assignedStores`). Reconciliation expectations (float + confirmed cash legs only, transfer leg
excluded, over/short signs) match `PosSession::close()` / `calculateCashSalesTotal()`.
`php artisan test` was not run, per the fleet instruction.

## Observations (not defects, no change made)

- **`payment_status` is always `null` on the orders list.** The column was dropped from `orders`
  (`2025_12_29_124452_drop_payment_status_from_orders_table`), so `$order->payment_status?->value`
  in both `OrderController@payload` and the superset `OrderSourceController@payload` resolves to
  null — a faithful mirror of the base contract, not a WS-17 regression.
- **`order.payment_method` in the session detail is usually null.** New-stack POS orders keep the
  method on `transactions.metadata.leg_method`, not `orders.meta.payment_method` (legacy's key);
  the controller's fallback chain (`meta` → `paymentMethod->name`) therefore returns null for cash
  legs. No SPA view renders that field (the payment-legs table shows `leg.payment_method ?? 'Cash'`),
  so this is a fidelity nuance, not a break.
- **Multiple open sessions per store** are deliberate, verified-legacy behaviour: the open guard
  is per cashier, every open drawer is surfaced, and close names the session (`session_code`)
  whenever more than one exists — returning `other_open_sessions` so the UI can say what remains.
- **`OrderSourceController` is registered inside the same permission group as the base route it
  replaces**, so no consumer lost a gate.

## Commands run

`php -l` on both controllers, the route module and the test file; `vendor/bin/pint --test` on the
same four; `php artisan route:list` (`--path=api/v1/management/pos`, `--path=api/v1/management/orders`,
full JSON) plus a 1107-route duplicate-name scan; greps of every module/parent route file for the
WS-17 route names; `@vue/compiler-sfc` parse + compile on the four SFCs; `typescript`
`transpileModule` on the three TS modules; import-resolution greps across both SPAs.
`php artisan test` and `npm run typecheck` were not run, per the fleet instruction.
