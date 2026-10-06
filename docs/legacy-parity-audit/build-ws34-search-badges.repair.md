# WS-34 — Global Search, Shell Badges & Avatar — repair report

**Verdict:** repaired. Three real backend defects found — two of them would 500 every
restricted-staff search and shell-counts request — plus three small SPA fixes (badge tooltip
grammar, palette unmount cleanup, a now-stale nav comment). All seven checklist items now
verify. Tests were written by the implementer and were **not executed** (fleet rule);
they were re-read against the controllers and schema.

## Verification performed

- `php -l` on all four PHP source files + the test file — clean.
- `php artisan route:list --path=api/v1/management` — 265 routes load cleanly; the five
  WS-34 routes are present, `/management/search` resolves to `GlobalSearchController`
  (not the parent `SearchController` or WS-19's), and all five carry the inherited
  `auth:sanctum` / `token.audience:management` / `team.context` middleware.
- Route-name collision sweep over `routes/` and the final route list: `api.management.shell.counts`
  and `api.management.profile.photo.{show,store,destroy}` are unique; `api.management.search`
  is a same-URI, same-name takeover of the parent and WS-19 registrations — last module file
  wins (glob order `ws19 < ws34`), so exactly one route serves the URI and one name resolves.
  This is the fleet's documented convention (see `ws18-transactions.php`, `ws19-customers.php`).
- `vendor/bin/pint --test` on all five files — passed.
- SPA: resolved every import in the 12 new files (all `@/` and relative imports exist);
  compiled the two edited SFCs with `@vue/compiler-sfc` — 0 template errors;
  deep links checked against the actual SPA routes (`products/:code`, `stores/:id`,
  `warehouses/:code`, `customers/:accountId`, `orders/:orderNumber`, `transactions/:reference`,
  `staff/:id`-as-account_code) — all match the URLs the controller builds.
- SQL-level proof of the ambiguity defect (read-only, dev DB):
  `select id from stores inner join staff_assignments ...` → SQLSTATE[23000] 1052
  "Column 'id' in field list is ambiguous". The repaired queries were then executed
  read-only against the real schema and succeed.

## Defects found and fixed

### 1. `pluck('id')` on the assignment pivot join — 500 for every restricted staffer
`GlobalSearchController::storeIds()` and `ShellCountsController::storeIds()`.

For restricted staff (`isStaff() && ! can('transactions view')` — e.g. Store Associate,
Store Manager), `accessibleStores()` returns the `assignedStores()` `MorphToMany`, which
joins `staff_assignments`. The pivot has its own `id`, so the bare `pluck('id')` compiles
`select id from stores inner join staff_assignments ...` and MySQL refuses with error 1052.
This fired on **every** call for a restricted staffer — even with zero assignments, because
the SQL is parsed before rows are returned: the whole palette and the whole shell-counts
endpoint (and therefore every nav badge) returned 500.

**Fixed:** `->pluck('stores.id')->map(fn ($id) => (int) $id)` in both controllers, matching
the qualified-column idiom already used by `DashboardController`/`PosController`/WS-18's
`staffStoreIds`.

### 2. `get(['id', ...])` on the same join — 500 for a restricted staffer with stores/warehouses view
`GlobalSearchController::stores()` and `warehouses()`.

`BelongsToMany::get($columns)` merges the caller's columns with the pivot aliases but does
not qualify them, so `get(['id', 'store_id', 'name', 'status'])` selected a bare `id` from the
joined query — same 1052. Store Manager (restricted, holds `stores view` and `warehouses view`)
would have had its Stores group 500 the whole search request.

**Fixed:** the id column is qualified (`stores.id` / `warehouses.id`); everything else stays
unqualified because those names exist only on the related table.

### 3. Currency-symbol fallback rendered a blank, and the test expected ₦
`GlobalSearchController::transactions()`.

`businesses.currency` is nullable with no default, and `createBusinessOwner` (like many real
businesses) leaves it unset — `currencySymbol(null)` returned a single space, so transaction
subtitles rendered `" 2,500.00 · 06 Oct 2026"`. WS-21's invoice helper, which this method
claims to mirror, first resolves `?->currency ?: 'NGN'`.

**Fixed:** `$this->currencySymbol($user->business?->currency ?: 'NGN')`.

### 4. Badge tooltip grammar
`src/components/ShellCountsBadge.vue` produced `"2 orders pendings"` /
`"3 staffs"`. Added a tooltip-noun map for the compound metric keys
(`orders_pending → pending order`, `staff → staff member`, …); explicit `label` prop still wins.

### 5. Palette could strand the page unscrollable
`src/components/GlobalSearchModal.vue` set `body.style.overflow = 'hidden'` while open but
only cleared it on close; an unmount while open (route change during teardown, logout) left
the page unable to scroll. The existing `onUnmounted` now also resets it.

### 6. Stale nav comment
`src/nav/modules/ws34-search-badges.ts` still claimed Support Messages was "NOT BUILT YET"
and carried a snippet to paste later. WS-33 has since shipped the screen, the route and the
`ws33-support.ts` entry, so the parity table now lists `Support ... ws33-support.ts` — the
final nav sweep the roadmap asks WS-34 to own still passes and the file no longer instructs
the orchestrator to double-add it.

## Checklist verification (all pass after repair)

1. **Routes → methods**: `search`/`shell/counts` hit `__invoke`; `profile/photo` hits existing
   `show/store/destroy`. Correct imports, correct signatures.
2. **Envelope + tenancy**: every method returns `$this->ok(...)`. Search is business-scoped and
   store-scoped (owner scope = own stores; restricted staff = assignments); shell counts are
   business-scoped with permission-zeroed counters; the photo resource only ever touches the
   authenticated user's row.
3. **SPA calls ↔ routes ↔ module exports**: `/management/search`, `/management/shell/counts`
   and the three `/management/profile/photo` verbs are all registered, all exported by
   `src/api/modules/ws34-search-badges.ts`, and all consumed by the new views/components.
4. **Route module hygiene**: `routes/api/v1/management/ws34-search-badges.php` contains only
   `Route::` lines (imports are controllers only), no prefix/middleware wrapper. No accidental
   duplicate names; the `search` name/URI takeover is deliberate and documented.
5. **Router/nav module shape**: `src/router/modules/ws34-search-badges.ts` default-exports a
   child-route array (`path: 'search'`, `meta.title`) with no leading slash and a unique route
   name; `src/nav/modules/ws34-search-badges.ts` default-exports `NavGroup[]` (empty by design —
   it owns badge plumbing, not destinations; the rationale and mount points are in its header).
6. **Imports**: all resolve (bare `vue`/`vue-router` packages aside); `KycStatusPill`,
   `stores/ui`, `api/client`, `lib/shell-counts` all exist with the members used.
7. **PHP syntax**: `php -l` clean on all four source files and the test.

Test file `tests/Feature/Api/ws34searchbadgesTest.php` was re-read against model fillables,
casts, the permission seeder and route middleware: fixtures are insertable, the restricted-staff
expectations match the corrected scoping, and the `₦` assertion now matches the currency fallback.
(Not run, per the fleet's shared-database rule.)

## Unfixable without touching files this workstream does not own

- **`photo_url` missing from `me`/login payload** — `App\Http\Controllers\Api\V1\Auth\Concerns\BuildsAuthResponses.php`
  (`userPayload`, line 32) is owned by the auth workstream. Add `'photo_url' => $user->photoUrl()`
  to the payload; the shell itself is unaffected because
  `ShellUserAvatar`/`ShellCountsController` read the URL from `GET /management/shell/counts`.
- **Shell wiring** — `src/layouts/AppLayout.vue` (shell workstream) still mounts `<SearchModal />`
  and the initials chip; `src/views/ProfileView.vue` (account workstream) has no photo card.
  The new components carry `MOUNT ME` headers naming the exact insertion points
  (`GlobalSearchModal`, `ShellUserAvatar`, `ShellCountsBadge`, `ShellKycPill`,
  `ShellSubscriptionPill`, `ProfilePhotoCard`); the orchestrator wires them.
- **Same ambiguity pattern in files owned elsewhere** (found while verifying scoping; each is
  a 1052 for restricted staff and should be qualified the same way):
  - `app/Http/Controllers/Api/V1/Management/Concerns/ResolvesManagementContext.php` —
    `accessibleStoreIds()` uses `accessibleStores()->pluck('id')`; reachable from WS-26's
    dispatches board via `DispatchController::accessibleQuery()`.
  - `app/Http/Controllers/Api/V1/Management/CustomerParityController.php` —
    `userStoreIds()` (line ~424) uses `accessibleStores()->pluck('id')` for restricted staff.
  - `app/Http/Controllers/Management/PosController.php` (line ~167) and
    `app/Http/Controllers/Management/DispatchesController.php` (line ~19) use
    `assignedStores()->pluck('id')` unqualified.

## Files changed

- `app/Http/Controllers/Api/V1/Management/GlobalSearchController.php`
- `app/Http/Controllers/Api/V1/Management/ShellCountsController.php`
- `src/components/ShellCountsBadge.vue` (management SPA)
- `src/components/GlobalSearchModal.vue` (management SPA)
- `src/nav/modules/ws34-search-badges.ts` (management SPA, comment only)

No shared/owned-by-others file was edited.
