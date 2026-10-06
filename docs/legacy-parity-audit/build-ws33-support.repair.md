# WS-33 — Support Messaging — repair pass

**Status:** verified, no defects found, no repairs required. This pass was
re-run independently after the session recharge; every check below was
re-executed against the current tree (the first pass ran at 09:03, this
re-verification later the same day).

**Scope:** the files reported by the WS-33 implementation — API
`Api\V1\Management\SupportMessageController` and
`routes/api/v1/management/ws33-support.php`; the feature test
`tests/Feature/Api/ws33supportTest.php`; SPA
`src/api/modules/ws33-support.ts`, `src/router/modules/ws33-support.ts`,
`src/nav/modules/ws33-support.ts`, `src/views/SupportMessagesView.vue` and
`src/components/support/{SupportStatusPill,SupportPendingBadge}.vue`.

`php artisan test` and `npm run typecheck` were **not** run (the fleet shares
one test database and one toolchain — the orchestrator runs them serially).

## Verification results

| Check | Result |
| --- | --- |
| Route → controller method signature | PASS — `php -l` clean on all three PHP files; reflection loads `SupportMessageController` (extends `ApiController`, trait `ResolvesManagementContext` present) and shows `index(Request)`, `stats(Request)`, `show(Request, SupportMessage)`, `reply(Request, SupportMessage)` all public with `JsonResponse` returns. `route:list` shows all four routes bound to this controller with the inherited `management` prefix and `api.management.` name. |
| Route registration order | PASS — dumping the router in registration order gives `stats` before `{supportMessage}`, so `/support-messages/stats` can never bind as a model id. |
| House envelope + tenant scoping | PASS — `index/stats/show/reply` all return `$this->ok(...)` (`index` passes `$this->paginationMeta()` as `$meta`); the only non-envelope paths are `$this->error('Invalid store selection.', 422)` for an out-of-scope `store_id` filter, `$this->error(...422)` on a closed conversation, and `abort(403)` in `authorizeMessage()` — the exact idiom of the reference `WarehouseController` (line 213). Every query is bounded by `accessibleStoreIds()` (owner `stores()`, staff `business_id`, restricted staff `assignedStores()`, all minus `Store::STATUS_DELETED`); `{supportMessage}` is checked against those ids on both read and write; the reply mutation is inside `DB::transaction`; mail + audit fire after commit. |
| SPA calls ↔ routes ↔ api module | PASS — `supportMessagesApi.list/stats/show/reply` are the only API calls the view (and the badge) make; each maps 1:1 onto a registered route and is exported from `src/api/modules/ws33-support.ts`. Response shapes line up with the controller payloads. |
| Route module wrapper / duplicate names | PASS — no prefix/name/auth wrapper; only the per-route `permission:` groups (same idiom as `ws31-categories.php`). No other API module file registers a `support-messages` URI. App-wide `route:list --json` duplicate-name scan (1048 named routes at re-check): **0 duplicates**; no other router module or AppLayout group claims `support-messages` or the "Support" label (WS-34's nav module is deliberately empty and points the entry here). |
| Router/nav module shapes | PASS — router default-exports `RouteRecordRaw[]` (relative path `support-messages`, `meta.title`, permission); nav default-exports `NavGroup[]` matching `@/nav/types` (`{label, icon, items[]}`), auto-merged by AppLayout's `../nav/modules/*.ts` glob. |
| Imports resolve | PASS — scripted resolution of all 11 local imports across the six SPA files resolves to existing files; PHP class/trait/mail/logger references all autoload. No `vendor`-named identifiers in any WS-33 file. |
| SFC / TS syntax | PASS — all three Vue SFCs parse and compile script + template via `@vue/compiler-sfc`; all three TS modules transpile clean via `ts.transpileModule`. Pint `--test` passed on the three PHP files. |
| File ownership | PASS — shared files (`routes/api/v1/management.php`, SPA `router/index.ts`, `AppLayout.vue`, `api/client.ts`, `api/endpoints.ts`) were last modified 06:42–06:43, before the WS-33 files (07:40+); this workstream only created its own files. |

## Fixes applied

None — no defect was found in either pass. No file needed changing.

## Unfixable

None — nothing required editing a file outside this workstream's ownership.

## Notes / handoffs (not defects)

- `SupportPendingBadge.vue` (the "unread/pending indicator" on the nav entry)
  is intentionally unmounted: `src/layouts/AppLayout.vue` is owned by the
  shell workstream, so the badge ships with a top-of-file MOUNT IN comment
  naming the exact line to place it on (inside the Support → Support Messages
  nav button, after `<span class="truncate">{{ item.label }}</span>`). Its
  data source (`GET /management/support-messages/stats`, `support
  view_tickets`-gated) is live; WS-34's shell-counts endpoint is expected to
  take it over later.
- The nav "Support" group needs no wiring — AppLayout's nav glob picks the
  module up automatically.
- Error-path detail preserved deliberately: a message belonging to a deleted
  store is invisible and un-repliable (403), matching the WS-06 "deleted
  records must not leak back into reads" policy; a reply on a `closed`
  conversation is refused with 422 (admin-final), a documented departure from
  the legacy overwrite behaviour — see the controller header.
