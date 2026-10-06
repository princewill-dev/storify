# AD-04 — Business lifecycle & directory (WS4) — repair report

**Verdict:** repaired. Four contained defects fixed (one of them would have failed
the shipped Pest file). Everything else in the reported implementation verifies
against the checklist. Three items remain for the orchestrator because they need
shared files this workstream does not own — recorded below with exact reasons,
plus the one verified alternative that would need no shared-file edit.

## Files inspected (reported implementation)

API
- `app/Http/Controllers/Api/V1/Admin/BusinessLifecycleController.php`
- `app/Http/Controllers/Api/V1/Admin/BusinessTypeController.php`
- `app/Http/Controllers/Api/V1/Admin/OwnershipTypeController.php`
- `routes/api/v1/admin/ad04-business-lifecycle.php`
- `tests/Feature/Api/ad04businesslifecycleTest.php`

SPA (storify-admin)
- `src/api/modules/ad04-business-lifecycle.ts`
- `src/router/modules/ad04-business-lifecycle.ts`
- `src/nav/modules/ad04-business-lifecycle.ts`
- `src/views/ad04/BusinessDirectoryView.vue`
- `src/views/ad04/BusinessConsoleView.vue`
- `src/views/ad04/BusinessTypesView.vue`
- `src/views/ad04/OwnershipTypesView.vue`
- `src/components/ad04/TypeListPanel.vue`

Also read for verification: `routes/api/v1/admin.php` (parent), every sibling
admin route module, `ApiController`, `User`, `Business`, `Store`, `Warehouse`,
`Order`, `Transaction`, `KycApplication`, `Subscription`, `Setting`,
`ActivityRecorder`, `KycApprovalService`, `AdminApiActivityLogger`, the three
mail classes and their Blade views, the admin SPA's `client.ts`, `useDataTable`,
`stores/auth`, `stores/ui`, `lib/format`, `lib/runtimeConfig`, AdminLayout's nav
merge and router-index merge, and every component the WS4 views use.

## Checks run

- `php -l` on all five PHP files — clean, re-run after edits — clean. The four
  files Pint reformatted in the build pass
  (`UserModerationController.php`, `StoreModerationController.php`,
  `Shop4meOrderController.php`, `Concerns/SerializesAdminOrders.php`) also parse
  clean; nothing semantic changed there.
- `php artisan route:list --path=api/v1/admin/businesses -v` — exactly one entry
  per URI, all 16 WS4 routes present and pointing at the WS4 controllers:
  `BusinessLifecycleController@index/store/show/update/destroy/suspend/activate/verifyOwner`,
  `BusinessTypeController@index/store/update/destroy`,
  `OwnershipTypeController@index/store/update/destroy`. The four re-registered
  `businesses` URIs really do replace the parent's older `BusinessController`
  entries (route collection keys on method+URI), so the later controller wins and
  the table keeps one entry each.
- Whole-app duplicate-name check: `php artisan route:list --json` → 1107 routes,
  **0 duplicate names**. Grep of every other module file and the parent route
  files for the names used: `business-types.*` / `ownership-types.*` appear
  nowhere else; `businesses.index/show/suspend/activate` exist in the parent
  `routes/api/v1/admin.php` only as the predecessors this module deliberately
  supersedes.
- Route module shape: no prefix/name/middleware wrapper — only a permission +
  `AdminApiActivityLogger` group, the same pattern as the sibling ad03/ad08
  modules; it inherits `admin` prefix, `api.admin.` name and the auth/audience/
  team middleware.
- Every controller method exists with a matching implicit-binding signature
  (`{business}` → `Business` binds by `business_code`; `{businessType}` /
  `{ownershipType}` bind by id) and returns the house envelope
  (`$this->ok` / `$this->error`); all three controllers enforce the platform
  guard (`$user->isAdmin()`), which matters because in-business "Super Admin"
  roles carry the `admin.*` permission names. Tenant lifecycle mutations
  (`store`/`update`/`destroy`/`suspend`/`activate`) are transactional; money
  handling is untouched (no monetary math in this workstream).
- Supporting classes verified to exist and match call sites: the three mailable
  constructors and their Blade views (`emails.admin.business-created`,
  `emails.business.suspended`, `emails.business.reactivated`),
  `KycApprovalService::autoApproveOpenApplication(User, ?User, ?string)`,
  `ActivityRecorder::record(...)` named arguments, `User::ROLE_SUPERADMIN` /
  `ROLE_BUSINESS_OWNER` / `isAdmin()`, `Business` route key + relations
  (`owner`, `activeSubscription.subscriptionPlan`, `stores.ownershipType/businessType`,
  `warehouses`, `users`, `kycApplications`), `Store::STATUS_DELETED` /
  `Warehouse::STATUS_DELETED` / `WarehouseStatus` cast, and the columns the
  payloads touch (`users.account_code/is_verified/last_login_at/force_password_change`,
  `businesses.business_type_id/ownership_type_id`,
  `kyc_applications.business_id` — nullable, written via `forceFill` by the
  management KYC flow — and `stock_locations.quantity`).
- `to=` without `from=` cannot 500: Laravel's `compareDates` falls back to the
  other field's value and a null timestamp compares as 0 (read in framework
  source). Empty filter strings (`status=`, `from=`) arrive as `null` because the
  framework-default `ConvertEmptyStringsToNull` middleware is active and not
  removed in `bootstrap/app.php`, so the `'' !== null` branch can never turn an
  empty status into `where status = ''`.
- SPA: every import resolves; every component prop/emit/slot the WS4 views use
  exists with the expected name (`AppModal` `modelValue`/`maxWidth`,
  `ConfirmDialog` `modelValue`/`title`/`message`/`confirmText`/`@confirm`,
  `EmptyState` `#action`, `SortHeader` props+`@sort`, `TableFooter`
  `meta`/`perPage`/`@page`/`@update:per-page`, `TableSkeleton` `rows`/`cols`,
  `StatCard` `label`/`value`/`hint`/`accent`/`icon`, `StatusBadge` `status`).
  `useDataTable` refs are consistently accessed with `.value` in templates
  (nested refs do not auto-unwrap); filters use the same keys the API validates;
  `ad04Api` paths all map to registered routes; the api client base URL already
  carries `/api/v1`; `TypeListPanel`'s declared fetcher/mutation prop types match
  `ad04Api`'s exports and `useDataTable`'s generic signature.
- Router module default-exports `RouteRecordRaw[]` child-of-`/` paths (no leading
  slash, `meta.title` on each); nav module default-exports `{ label, nodes }`
  sections whose nodes carry `label/icon/to/permission` — the shape
  `AdminLayout.vue`'s glob expects.
- VS Code diagnostics (Volar/vue-tsc for the SPA, PHP language server for the
  API) on every WS4 file: no errors.
- Vue-router mount behaviour verified empirically in Node against the pinned
  `vue-router@5.3.1`: same path + different name → the first (shared) record
  wins; same name → the later record replaces the earlier one.

## Fixed (4)

1. **Test KYC rows were not business-scoped — the console test would fail.**
   Both tests built applications with `KycApplication::create([...])`, but
   `business_id` is a column the model's `$fillable` predates, so `fill()`
   silently dropped it. The console payload resolves KYC through
   `$business->kycApplications()` (keyed on `business_id`), so
   "the business console answers who works here..." would have found no
   application and failed its `data.business.kyc.*` assertions. Added an
   `ad04KycApplication()` helper that `forceFill`s `business_id` from the
   business — the idiom ad03/ad08 use — and switched both tests to it.

2. **Directory offered Edit on deleted rows.** `BusinessDirectoryView.vue` showed
   the Edit action for rows with status `deleted` (reachable through the new
   status filter / include-deleted), but the API always refuses that with 422
   ("A deleted business cannot be edited."). The button is now hidden for
   deleted rows and `openEdit()` guards against the status as well.

3. **`verifyOwner` was the one tenant mutation not wrapped in a transaction.**
   Every other lifecycle write in the controller pairs the mutation and its
   audit row inside `DB::transaction` ("a rejected audit row must take the edit
   down with it"); `verifyOwner` saved the owner and then wrote the audit row
   without one. Both writes now stand or fall together.

4. **Console back buttons left the WS4 flow.** They pushed `/businesses`, which
   (while the shared router entries below still shadow the module) resolves to
   the older `BusinessesView`. They now push `/businesses/lifecycle` — the
   module's non-colliding alias — so directory ↔ console navigation is coherent
   today and after the orchestrator removes the old entries.

## Unfixable here (needs files this workstream does not own)

1. **Canonical SPA paths are shadowed by `src/router/index.ts`.** The shared
   file still registers `businesses` → `BusinessesView.vue` and
   `businesses/:businessCode` → `BusinessDetailView.vue` ahead of the globbed
   module routes; vue-router keeps the first record for an identical path when
   the names differ (verified against the pinned version), so the WS4 directory
   and console are only reachable through the module's aliases
   (`/businesses/lifecycle`, `/businesses/:businessCode/console`) — which is
   where the WS4 views link. Fix belongs in the must-not-edit router index:
   either remove the two old entries (the documented mount hint), or, needing no
   shared-file edit, rename the module's two canonical routes to
   `businesses` / `businesses.show` — verified: a later record with the same
   name replaces the earlier one, so the module would take the canonical paths
   automatically. I kept unique module names and the hand-off intact rather than
   pre-empting the orchestrator's cleanup.
2. **Sidebar "Settings" will render twice.** `AdminLayout.vue` hardcodes a
   Settings section (Coupons) and appends module sections; the WS4 nav module's
   Settings section (Business Types / Ownership Types) should be merged into it.
   The mounting note is in `src/nav/modules/ad04-business-lifecycle.ts`;
   consolidation needs the must-not-edit layout.
3. **Old `BusinessesView.vue` / `BusinessDetailView.vue` retirement.** Once (1)
   lands these two views are orphaned; deleting/repointing them is orchestrator
   cleanup (they are existing files this workstream must not edit).

## Known gaps (recorded, not defects — not repaired here)

- **AB-3's "no-Business-record" fallback is still missing.** The console binds on
  `business_code`, so an owner account with no `Business` row remains
  unreachable; a fix needs a new user-keyed API route plus a fallback layout, a
  feature addition beyond this repair pass (WS8's user console covers the
  account view meanwhile).
- The build pass ran Pint over all of
  `app/Http/Controllers/Api/V1/Admin/`, which formatting-touched four files owned
  by other workstreams. All four still parse and lint; a concurrent agent
  writing a stale copy could undo the formatting only.

## Not verified here (by instruction)

`php artisan test` and `npm run typecheck` were not run — the fleet shares one
test database and toolchain, and the orchestrator runs them serially. The Pest
file was written against the real fixtures and every model/migration/column it
touches was read during this pass; the two KYC-creation defects above were the
only ones that would have failed it. VS Code diagnostics were used as the
static type check for the SPA.
