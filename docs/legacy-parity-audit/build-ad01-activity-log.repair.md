# AD-01 — Activity log & audit trail (WS1) — repair report

**Verdict:** repaired. One real defect found and fixed; everything else in the
reported implementation verifies against the checklist. Two cross-cutting
wiring items remain for the orchestrator because they need shared files this
workstream does not own (recorded below).

## Files inspected (reported implementation)

API
- `app/Http/Controllers/Api/V1/Admin/ActivityLogController.php`
- `app/Services/ActivityRecorder.php`
- `app/Http/Middleware/AdminApiActivityLogger.php`
- `routes/api/v1/admin/ad01-activity-log.php`
- `tests/Feature/Api/ad01activitylogTest.php`

SPA (storify-admin)
- `src/views/ActivityLogsView.vue`
- `src/api/modules/ad01-activity-log.ts`
- `src/router/modules/ad01-activity-log.ts`
- `src/nav/modules/ad01-activity-log.ts`

## Checks run

- `php -l` on all five PHP files — clean (re-run after edits — clean).
- `php artisan route:list --path=api/v1/admin/activity-logs -v` — the route
  appears exactly once: `GET|HEAD api/v1/admin/activity-logs`,
  name `api.admin.activity-logs.index`, controller `ActivityLogController@index`,
  middleware chain `api → auth:sanctum → throttle:api →
  EnsureTokenAudience:admin → SetPermissionsTeamId →
  permission:admin.activity-logs → AdminApiActivityLogger`.
- Grep for the route name across `routes/` — the only other hit is the legacy
  `routes/v1/admin_dashboard.php` (`admin.activity-logs.index`, different name,
  loaded via `routes/web.php`), so no duplicate name in the new stack. No other
  module file registers a route named or pathed `activity-logs`.
- Route module contains only one `Route::` line, no prefix/middleware wrapper,
  no imports beyond the controller and the middleware class — it inherits the
  admin group's prefix/name/middleware as designed.
- Every SPA import resolves (`@/api/client`, `@/api/modules/ad01-activity-log`,
  `@/composables/useDataTable`, `@/stores/ui`, `@/lib/format`, all six
  components) and the component props/slots used by the view exist
  (`AppModal`/`DetailDrawer` `v-model` + `#footer`, `EmptyState` `#action`,
  `SortHeader` `sort`/`direction`/`@sort`, `TableFooter`
  `meta`/`perPage`/`pageSizes`, `TableSkeleton` `rows`/`cols`).
- VS Code diagnostics on all four SPA files — no errors.
- SPA API module hits `GET /admin/activity-logs` with the exact filter keys the
  controller validates (`user_id`, `action`, `q`, `from`, `to`, `sort`,
  `direction`, `per_page`, `export`); list + `export=csv` both map to the single
  registered route. Base URL already carries `/api/v1` (`lib/runtimeConfig.ts`),
  matching peer modules.
- Router module default-exports a child route array with `meta.title`, path
  `activity-logs` (no leading slash), unique name. Nav module default-exports
  `{ label, nodes }` sections whose nodes carry `label/icon/to/permission`, the
  shape `AdminLayout.vue`'s glob expects — same shape as the peer ad02 module
  (admin SPA has no `src/nav/types.ts`).
- `php -r` autoload check: `ActivityRecorder`, `AdminApiActivityLogger`,
  `ActivityLogController`, `ActivityLog` all resolve; `User::ROLE_SUPERADMIN`/
  `ROLE_ADMIN`, `AdminController::PLATFORM_ROLES` and the
  `KycApplicationController` platform guard confirm the controller's
  `abort_unless(... in_array($user->role, [superadmin, admin]))` guard matches
  an existing house idiom. `permission:admin.activity-logs` is seeded in
  `SpatiePermissionSeeder`.
- `to=...` without `from` is safe: `after_or_equal:from` compares against a
  null timestamp, which PHP coerces (verified), so a to-only filter does not
  422.

## Fixed (1)

1. **CSV export leaked sensitive values on read.** `payload()` deliberately
   re-redacts `old_values`/`new_values`/`metadata` on read ("rows written before
   ActivityRecorder existed may carry raw secrets"), but `exportCsv()` wrote
   `$log->old_values` / `new_values` / `metadata` straight into the CSV. Any
   legacy-style row with an `api_keys`/`password` value would leak through the
   export even though the JSON view hid it. Fixed in
   `ActivityLogController::exportCsv()` by applying
   `ActivityRecorder::redact(...)` to all three columns, mirroring `payload()`.
   Regression assertions added to the existing CSV export test: a row with
   `api_keys.paystack_secret` and `metadata.password` must render
   `[redacted]` and must not contain the raw values.

## Unfixable here (needs a file this workstream does not own)

1. **Group-level middleware wiring.** The WS1 plan asks that
   `AdminApiActivityLogger` be applied to the whole admin group in
   `routes/api/v1/admin.php` (registered in `bootstrap/app.php`) so the legacy
   blind spot — dashboard and settings not audited — is closed. That file is on
   the must-not-edit list. Current mitigation is deliberate and documented in
   the middleware docblock: ad01 (and, by grep, ad02–ad18) apply the middleware
   at route level, and the per-request attribute de-dupes, so a later
   group-level application writes exactly one row per request. Note the plain
   pre-existing routes registered directly in `routes/api/v1/admin.php`
   (dashboard, search, businesses/stores/users/transactions/coupons) stay
   unaudited until the orchestrator (or their owning workstreams) apply it.
2. **Nav consolidation.** `AdminLayout.vue` hardcodes a "Settings" section
   (Coupons) and appends module sections, so the ad01 nav module's own
   "Settings" section renders as a second SETTINGS header until the
   orchestrator merges the node into the shared section and deletes the
   wrapper. Documented in the module's top comment, as the house rules require;
   consolidation needs `src/layouts/AdminLayout.vue`, which is must-not-edit.

## Not verified here (by instruction)

Tests were not executed (`php artisan test`) and no SPA typecheck was run —
the fleet shares one test database/toolchain and the orchestrator runs them
serially. The test file is thorough (payload, combining filters, dropdown
options, validation, permission boundary incl. the business-scoped leaked-
permission case, audience refusal, self-auditing, CSV export + the new
redaction assertions, recorder redaction) and lints clean.
