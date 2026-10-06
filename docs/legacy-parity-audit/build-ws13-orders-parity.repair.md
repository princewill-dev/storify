# WS-13 — Orders List & Detail Parity — repair pass

**Scope:** the files reported by the WS-13 implementation, plus the two shared permission
maps and the WS-13 test file. This pass independently re-ran every check below against the
current tree; the four fixes listed were already applied in the earlier repair pass and are
still present and verified. `php -l`, Pint, Vue SFC compile and `php artisan route:list`
were run; `php artisan test` / `npm run typecheck` were **not** (the fleet runs them
serially — see the note at the bottom).

## Verification results

| Check | Result |
| --- | --- |
| Route module methods exist with matching signatures | PASS — `orders/board/list`→`index`, `orders/board/stats`→`stats`, `orders/{order}/detail`→`show`, `orders/{order}/edit`→`edit`, `PUT orders/{order}`→`update`; all five present in `route:list` as `api.management.orders.*`, and `class_exists` / `ReflectionClass::hasMethod` confirm each method autoloads |
| House envelope + tenant scoping | PASS — all five methods return `$this->ok(...)`; `accessibleQuery()` scopes `business_id` + `accessibleStoreIds()`; `authorizeOrder()` checks business + store access; the `store_id` filter id is authorized against `accessibleStores()`, foreign id → 403; `update()` mutates inside `DB::transaction` |
| SPA calls ↔ routes ↔ api modules | PASS — board list/stats, detail, edit data and update resolve to the routes above and to `src/api/modules/ws13-orders-parity.ts`; status/delete reuse the shared `ordersApi` base routes (`orders/{order}/status`, `orders/{order}` DELETE). `API_BASE_URL` already ends in `/api/v1`, so the module's `/management/...` paths land on the registered URIs |
| Route module wrapper / duplicate names | PASS — the module adds only `permission:` groups (inherits prefix/name/auth/team from `routes/api/v1/management.php`); app-wide `route:list --json` duplicate-name scan found none; the only other `orders.edit`/`orders.update` names live in the unloaded legacy `routes/v1/*` files with a different name prefix |
| Router/nav module shapes | PASS — router default-exports a child `RouteRecordRaw[]` with `meta.title` and no leading slash (`orders/:orderNumber/edit`); nav default-exports `NavGroup[]` (empty by design — the Sales → Orders entry already exists in `AppLayout`) |
| Imports resolve | PASS — every `@/...` import in the three views, the api module, the router module and the nav module resolves to an existing file/export; controller/trait/seeder all autoload |
| PHP syntax | PASS — `php -l` on all four PHP files (plus the two edited shared files) |
| Vue SFC compile | PASS — `@vue/compiler-sfc` parse + `compileScript` + `compileTemplate` on all three views |
| Pint | PASS — `vendor/bin/pint --test` on all touched PHP files |
| Cross-workstream integration | PASS — the timeline query (`subject_type = Order::class`, `subject_id`) matches what WS-12's `OrderFulfilmentController` writes, so fulfilment transitions appear in the detail activity panel |

## Fixes applied (verified present in this pass)

1. **`orders delete` was never seeded** (the WS-13 hand-off). The standalone seeder was
   correct but unreachable: it was not registered anywhere, so `db:seed` and
   `permissions:sync` still produced no `orders delete` permission and the existing
   `DELETE /management/orders/{order}` route stayed unreachable for every role —
   including the business owner. Fixed in the canonical maps instead:
   - `database/seeders/SpatiePermissionSeeder.php` — added `'delete'` to the `orders`
     action list (this also flows into the business Super Admin / Developer `'all'` roles
     and platform Super Admin, exactly what the standalone seeder did).
   - `app/Console/Commands/SyncPermissions.php` — same one-word addition, so
     `permissions:sync` heals existing databases too.
   - `database/seeders/Ws13OrdersDeletePermissionSeeder.php` — docblock rewritten: it is
     now the idempotent backfill for databases seeded before the map fix (and the fixture
     the WS-13 feature test exercises), not the only source of the permission.
   No `DatabaseSeeder` registration is needed: `db:seed` reaches `SpatiePermissionSeeder`,
   and `permissions:sync` covers existing databases.

2. **`OrderDetailView.vue` items footer overflowed the table.** The `<tfoot>` rows used
   `colspan="2"` for the label **plus** a value cell — three rendered columns for a table
   whose body rows have two, pushing the footer outside the table width. All five footer
   rows now use a single label cell + value cell (`colspan` removed).

3. **`OrdersView.vue` deep-linked store filter never selected.** The view advertises
   `/orders?store_id=3` deep links (store tabs, dashboard cards), but hydration stores the
   query value as a string while the `<option>` values were numbers — a deep link applied
   the filter but displayed "All stores". Option values are now `String(store.id)`, so
   hydration and user selection agree (the API validates `integer`, numeric strings pass).

4. **WS-13 test passed a dropped column.** `StoreBank::create(['store_id' => ...])` in
   `tests/Feature/Api/ws13ordersparityTest.php` — `store_id` was dropped from `store_banks`
   (migration `2026_07_12_142311_drop_store_id_from_store_banks.php`) and is not fillable,
   so it was silently discarded; every other test uses `business_id`. Changed to
   `business_id`, matching the convention and keeping the fixture honest (the assertion
   itself was unaffected).

## Deliberately not changed

- `meta: { permission: 'orders edit' }` on the SPA edit route is inert (the router guard
  does not evaluate `meta.permission`, same as other modules) — the API and the button
  visibility are the real gates. Left for convention parity.
- The board endpoints use `orders/board/list|stats` rather than the roadmap's
  `GET /management/orders/stats`: the shared route file binds `orders/{order}` before
  feature modules load, so a two-segment `orders/stats` would be captured by the order
  binding. The deviation is documented in the route file header and WS-27's store-tabs
  module already deep-links to `orders/board/list?store_id`.
- `update()` treats an absent `notes` as `null` (full-replace semantics for the three
  editable fields, matching how the legacy form always submits). Not a defect.
- Order money is stored in decimal naira columns (`decimal:2`) throughout this module —
  the kobo rule applies to the delivery-route `fee` (returned as integer kobo and divided
  by 100 in the SPA) and POS/accounting domains, both handled correctly.

## Verification limitation (hand-off, not a defect)

Per the fleet rule, `php artisan test` and `npm run typecheck` were not run here — the
orchestrator runs them serially after the fleet stops. Static review of the 9 Pest tests
against the models/migrations/enums found no blockers, and the SFCs compile cleanly; the
`orders delete` map fix above makes both the delete test and production seeding
self-sufficient. No issue in this workstream is blocked on a file owned by another agent.
