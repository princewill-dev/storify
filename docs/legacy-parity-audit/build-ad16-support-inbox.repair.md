# AD-16 — Support inbox (WS16) — repair report

**Verdict:** repaired. The reported implementation verifies against every
checklist item — routes resolve, the controller exists with the right methods
and envelope, the SPA calls map to registered routes, and all imports resolve.
One stale integration note was fixed; no code defects were found. Two
cross-cutting wiring items remain for the orchestrator because they need shared
files this workstream does not own (recorded below).

## Files inspected (reported implementation)

API
- `app/Http/Controllers/Api/V1/Admin/SupportMessageController.php`
- `routes/api/v1/admin/ad16-support-inbox.php`
- `app/Observers/SupportMessageObserver.php`
- `app/Providers/SupportInboxServiceProvider.php`
- `tests/Feature/Api/ad16supportinboxTest.php`

SPA (storify-admin)
- `src/views/SupportInboxView.vue`
- `src/api/modules/ad16-support-inbox.ts`
- `src/router/modules/ad16-support-inbox.ts`
- `src/nav/modules/ad16-support-inbox.ts`
- `src/components/SupportInboxBadge.vue`

## Checks run

- `php -l` on all five PHP files — clean.
- `php artisan route:list --path=api/v1/admin` — all seven routes appear once,
  named `api.admin.support-messages.{index,stats,show,reply,close,reopen,destroy}`;
  `-v` shows the full chain `api → auth:sanctum → throttle:api →
  EnsureTokenAudience:admin → SetPermissionsTeamId → permission:admin.support →
  AdminApiActivityLogger` with no double application and no prefix/name wrapper
  re-declared in the module (it inherits them, as designed; the permission group
  is the established ad02/ad03/ad06/… idiom since the parent file only wraps its
  own routes).
- `php artisan route:list --json` app-wide duplicate-name scan — **zero
  duplicate route names**. The only other `support-messages.*` names are the
  management module's (`api.management.…`) and the legacy `routes/v1/*` ones,
  all under different prefixes. No other admin module registers a
  `support-messages` path.
- Route ↔ controller: every route points at a method that exists with the right
  signature (`index/stats(Request)`, `show/reply/close/reopen/destroy(Request,
  SupportMessage)`), and the `{supportMessage}` implicit binding matches the
  argument name. `stats` is registered before the binding so it can never
  resolve as an id.
- Envelope + scoping: every action returns `$this->ok($data, $message, $status,
  $meta)` / `$this->error(...)` (validation through `$request->validate`, which
  throws the standard 422 JSON). Every action calls
  `authorizePlatformAdmin()` (`EnsuresPlatformAdmin`, the same trait 22 peer
  admin controllers use) — the platform-wide inbox is deliberate; the only
  request-supplied id (the `store_id` filter) is validated with
  `Rule::exists('stores','id')`, and `sort`/`direction`/`per_page` are
  whitelisted. All four mutations run in `DB::transaction` with the
  `ActivityRecorder` row inside it.
- Referenced pieces verified against source: `ApiController::ok/error/
  paginationMeta`, `EnsuresPlatformAdmin::authorizePlatformAdmin`,
  `ActivityRecorder::record` (named-arg signature matches),
  `SupportMessage` fillable/columns vs the migration (`status` enum
  pending|replied|closed, `reply`, `replied_by_type/id/at`; `store_id` is NOT
  nullable, so the payload's null-store branch is defensive only),
  `SupportMessageReplyMail` (ShouldQueue, `to` the customer),
  `AdminNewSupportMessageMail`, `AdminApiActivityLogger` (per-request de-dupe),
  `Store::business` + `business_code`, `User::ROLE_SUPERADMIN/ROLE_ADMIN`,
  the seeded `admin.support` permission, the Support Admin / Finance Admin role
  bundles, and the `Gate::before` superadmin bypass that lets the plain-role
  superadmin the tests build pass `permission:admin.support`.
- SPA call mapping: the view's six calls (`list`, `reply`, `close`, `reopen`,
  `destroy`, plus the badge's `stats`) each match a registered route **and** an
  export in `api/modules/ad16-support-inbox.ts`; `show` is exported and unused
  by the view (list payload carries the full thread). `API_BASE_URL` already
  ends in `/api/v1`.
- SPA imports/props: all seven components used by the view exist, and their
  props/slots match (`AppModal` v-model/title/maxWidth + `#footer`,
  `ConfirmDialog` v-model/title/message/confirmText + `@confirm`,
  `DetailDrawer` v-model/title/subtitle/width + `#footer`, `EmptyState` +
  `#action`, `StatCard` label/value/hint/icon/accent, `TableFooter`
  meta/perPage + `@page`/`@update:per-page`, `TableSkeleton` rows/cols);
  `useDataTable`'s fetcher contract matches `fetchMessages`;
  `stores/{auth,ui}` expose `can`/`success`/`error`/`info`; `lib/format`
  exports `formatDateTime`. The view's store link (`/stores/<store_id>`) hits
  ad06's `stores/:storeId` route, whose param is the public `st_…` id.
- Router module: default-exports a child-route array, path `support-messages`
  (no leading slash), `meta.title`, unique name. Nav module: default-exports
  `{ label, nodes }` sections whose leaf carries `label/icon/to/permission` —
  exactly the shape `AdminLayout.vue`'s glob and leaf renderer expect (admin
  SPA has no `src/nav/types.ts`; peers declare/infer the same shape).
- SPA duplicate scan: route name `support-messages` and path
  `/support-messages` appear only in the ad16 files.
- VS Code diagnostics on the five SPA files and the controller — no errors.
- Test file reviewed against the implementation: Pest helpers
  (`createBusinessOwner`, `Store::create` fillables, `User::ROLE_*`,
  `SpatiePermissionSeeder` role names) all line up, and the assertions match
  the controller's ordering (pending-first), counters, replier labels,
  guard messages, audit rows and observer fan-out. `php -l` clean. Tests were
  not executed (by instruction).

## Fixed (1)

1. **Stale nav mounting note (would have produced two "Content" headings).**
   `src/nav/modules/ad16-support-inbox.ts` claimed "no other module owns a
   'Content' section yet, so this ships as one". WS-18's nav module
   (`ad18-marketing-content.ts`) also returns a "Content" section, and
   `AdminLayout.vue` merges module sections verbatim — following the old note
   would render a second CONTENT header. The comment now tells the orchestrator
   to merge the Support Messages node into the single Content section
   (Testimonials · Company Services), matching the ad15/ad18 precedent. No code
   changed.

## Unfixable here (needs a shared file this workstream does not own)

1. **Mount `SupportInboxServiceProvider` in `bootstrap/providers.php`.** The
   observer that notifies the platform office of new storefront messages only
   registers when the provider is booted; with the current provider list
   (`AppServiceProvider` only) the deliverable is inert in production. The
   provider's docblock names the exact line to add. The test masks this gap on
   purpose (`ad16EnsureSupportObserverRegistered()` boots the provider itself
   when no listener exists), so this is invisible to the test run.
2. **Live pending badge in the sidebar (and nav merging).**
   `AdminLayout.vue` hardcodes its badge mechanism (`userCount`) and merges
   module sections; a live `counts.pending` badge therefore needs that shared
   layout file. `SupportInboxBadge.vue` and
   `GET /api/v1/admin/support-messages/stats` are provided, and the nav
   module's top comment says where to mount both — the house-rules escape hatch
   for a cross-cutting badge. The same merge step in item 1 above applies.

## Not verified here (by instruction)

`php artisan test` and `npm run typecheck` were not run — the fleet shares one
test database and one toolchain and the orchestrator runs them serially after
all repairs finish.
