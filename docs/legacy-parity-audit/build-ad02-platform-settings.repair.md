# AD-02 — Platform settings & branding (WS2) — repair report

**Verdict:** repaired. The implementation matches the roadmap on every
functional point (full payload, per-field validation, exclusive default
currency, old-file cleanup, cache busting, changed-keys audit, deep-linkable
tabs, per-field errors); three defects were found and fixed in place, all
inside the workstream's own files. One cross-cutting wiring item remains for
the orchestrator because it needs `AdminLayout.vue` (recorded below).

## Files inspected (reported implementation)

API
- `app/Http/Controllers/Api/V1/Admin/SettingsController.php`
- `routes/api/v1/admin/ad02-platform-settings.php`
- `tests/Feature/Api/ad02platformsettingsTest.php`

SPA (storify-admin)
- `src/views/SettingsView.vue`
- `src/components/SettingsFileField.vue`
- `src/api/modules/ad02-platform-settings.ts`
- `src/router/modules/ad02-platform-settings.ts`
- `src/nav/modules/ad02-platform-settings.ts`

## Checks run

- `php -l` on the three PHP files — clean (re-run after edits — clean).
- `php artisan route:list --path=api/v1/admin/settings -v` — exactly two rows:
  `GET|HEAD api/v1/admin/settings` → `api.admin.settings.show` →
  `SettingsController@show`, and `PUT api/v1/admin/settings` →
  `api.admin.settings.update` → `SettingsController@update`; middleware chain
  `api → auth:sanctum → throttle:api → EnsureTokenAudience:admin →
  SetPermissionsTeamId → permission:admin.settings → AdminApiActivityLogger`.
  Both methods exist with the right signatures (`show(): JsonResponse`,
  `update(Request): JsonResponse`).
- Route-name grep across `routes/`: the only other `settings.*` names are
  `api.management.settings.index` (ws37, management name group) and the legacy
  `admin.settings.edit/update` in `routes/v1/admin_dashboard.php` (web prefix);
  `api.admin.settings.*` is registered exactly twice — no duplicate names. The
  route module carries only `Route::` lines (no prefix/name wrapper) and no
  imports beyond the controller and the audit middleware.
- Envelope: `show()` returns `$this->ok([...])`, `update()` returns
  `$this->ok([...], 'Settings updated.')`; validation failures render Laravel's
  standard `{message, errors}` 422 — the same shape `$this->error()` produces,
  which is what the SPA's `settingsFieldErrors()` reads. Platform settings is a
  platform-wide singleton, so tenant scoping is deliberately N/A on the row
  itself; the only cross-tenant input, `main_store_id`, is constrained to an
  existing non-deleted store.
- Controller vs roadmap: logo PNG/JPG/WEBP ≤2 MB, favicon ICO/PNG ≤1 MB,
  certificate PDF/JPG/PNG/WEBP ≤5 MB, OG image ≤2 MB; `store_creation_limit`
  min 1; `trial_days` 1–90; `og_type` enum is exactly legacy's three
  (website/article/product) and greeting frequency exactly legacy's six
  (never/always/once_per_session/per_day/per_week/per_month — verified against
  `advanced/settings.blade.php`); uploads delete the replaced file; the default
  currency flip (`where is_default → false`, then set the chosen id) runs inside
  the same transaction; `SettingsUpdated` is queued to the first superadmin and
  failures never fail the save; the audit row carries changed key names only.
- Cache bust verified against every reader: `company_settings`,
  `admin_main_store`, `home_main_store`, `search_suggested_products`
  (`AppServiceProvider`) and `home_api_company` (`Api\V1\Home\HomeController`)
  are all forgotten — `home_main_store`/`search_suggested_products` only when
  `main_store_id` actually changed, which is exactly when they go stale.
- Legacy traps closed as the audit's corrections require: `api_keys` is
  unreachable through this API and untouched by a save (never written), and the
  favicon is validated with `file` + `mimes` (not the legacy `image` rule), so a
  real `.ico` is accepted.
- SPA imports all resolve (`@/api/client`, `@/api/modules/ad02-platform-settings`,
  `@/stores/ui`, `@/components/ConfirmDialog.vue`,
  `@/components/SettingsFileField.vue`); `ConfirmDialog`
  (`v-model/title/message/confirmText/@confirm`) and `SettingsFileField`
  (`label/hint/accept/previewUrl/isPdf/error` + `change` emit) match their
  definitions; `ui.success/info/error` exist on the store. The five SPA files
  parse and compile with the installed `@vue/compiler-sfc` /
  `typescript` transpiler, and VS Code diagnostics report zero for all five.
- SPA request shape matches the route: `GET /admin/settings`, and
  `POST /admin/settings` with `_method=PUT` in the FormData (multipart
  method-spoofing — the reason the comment in the route module gives), typed
  against the `{data:{settings,changed_keys}, message}` envelope. Router module
  default-exports a child array with `meta.title` and path `settings/:tab?` (no
  leading slash); the static `settings/*` siblings (ad04, ad11, ad12, ad17)
  outrank it in Vue Router, which ad12's module explicitly documents. Nav
  module default-exports the `{label, nodes}` shape `AdminLayout.vue` globs.
- Tests: helper functions are `ad02`-prefixed (`ad02Token/Headers/SuperAdmin/
  PlatformAdmin/Baseline/Settings/FullPayload/Store`), no collision with the
  other Pest files; 18 tests cover payload/options, persistence, cache busting,
  currency exclusivity, enum and bound validation, homepage-store deletion
  handling, uploads (store/replace/refuse/ICO), api_keys immutability, method
  spoofing, mail targeting, no-op saves, audit redaction and the permission
  boundary. Not executed (instruction).

## Fixed (3)

1. **Missing platform-role guard (privilege escalation).** The route gate alone
   is not sufficient: every business's in-business "Super Admin" role is seeded
   `permissions => 'all'` (which includes `admin.settings`), and
   `SetPermissionsTeamId` resolves permissions against the caller's business —
   so a business-scoped account holding a leaked admin-audience token would pass
   `permission:admin.settings` and could read or rewrite platform branding, the
   homepage store, the store-creation limit and the trial rules. 22 sibling
   admin controllers (WS1's activity viewer, WS5/11/12/13 …) already close this
   with `Concerns\EnsuresPlatformAdmin`; `SettingsController` did not. Fixed by
   `use EnsuresPlatformAdmin;` and `$this->authorizePlatformAdmin()` at the top
   of `show()` and `update()`, plus a class-docblock note. Legitimate console
   users are unaffected — `AdminAuthController` only signs in
   `superadmin`/`admin`. Regression test added: a business owner whose
   in-business role carries `admin.settings` gets 403 on both methods and leaves
   the settings table untouched.
2. **Settings visits were not audited at route level.** WS1's acceptance
   criterion is that the dashboard and settings screens are logged (the legacy
   blind spot), and 15 of the 18 admin route modules attach
   `AdminApiActivityLogger` directly so the trail exists even before the
   orchestrator's group-level wiring lands. `ad02` did not. Added it to the
   route group (`['permission:admin.settings', AdminApiActivityLogger::class]`)
   with a comment explaining the de-dupe contract with the planned group-level
   application. The controller's own `settings_updated` audit row is unchanged.
3. **Stale comment.** The `UPLOADS` docblock claimed "Validation limits live in
   rules()" — there is no `rules()` method; limits are in `update()`'s rule
   list. Comment corrected.

## Unfixable here (needs a file this workstream does not own)

1. **Nav consolidation.** `AdminLayout.vue` hardcodes a "Settings" section
   (Coupons) and appends module sections verbatim, so the ad02 nav module's own
   "Settings" section renders as a second SETTINGS header until the orchestrator
   merges the node into the shared section and deletes the wrapper. Recorded in
   the module's top-of-file mounting note, as the house rules require;
   consolidation needs `src/layouts/AdminLayout.vue`, which is on the
   must-not-edit list. Same situation as ad01/ad03/ad12/… — not ad02-specific.
2. **Group-level audit middleware.** `AdminApiActivityLogger` is still not
   applied to the whole admin group in `routes/api/v1/admin.php` (or
   `bootstrap/app.php`), which is a WS1 orchestrator wiring item on a
   must-not-edit file. Mitigation: ad02 now carries it at route level and the
   per-request attribute de-dupes, so the later group-level application writes
   exactly one row. The plain routes registered directly in that shared file
   (dashboard, search, businesses/stores/users/transactions/coupons) stay
   unaudited until then.

## Deliberately not built (allowed by the roadmap)

- The optional split upload endpoints (`POST /admin/settings/logo|favicon|
  certificate|og-image`) — the roadmap marks them optional and the single
  multipart PUT covers all four files; the SPA uses the single save.
- Clearing the default currency is not possible through the screen (an empty
  `default_currency_id` is a no-op). This matches legacy exactly
  (`AdminSettingsController` used `$request->filled('default_currency_id')`
  too), so it is parity, not a gap.

## Not verified here (by instruction)

`php artisan test` and `npm run typecheck` were not run — the fleet shares one
test database and one toolchain and the orchestrator runs them serially. The
test file lints clean and is thorough; the SPA files were syntax/compile checked
as above.
