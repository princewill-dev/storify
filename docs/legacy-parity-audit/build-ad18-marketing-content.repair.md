# AD-18 / WS-18 — Marketing content: testimonials & company services — repair pass

Verified against the actual files (not the completion report). Workstream files:

- API: `app/Http/Controllers/Api/V1/Admin/TestimonialController.php`,
  `app/Http/Controllers/Api/V1/Admin/CompanyServiceController.php`,
  `routes/api/v1/admin/ad18-marketing-content.php`
- Admin SPA: `src/views/TestimonialsView.vue`, `src/views/CompanyServicesView.vue`,
  `src/api/modules/ad18-marketing-content.ts`, `src/router/modules/ad18-marketing-content.ts`,
  `src/nav/modules/ad18-marketing-content.ts`
- Test: `tests/Feature/Api/ad18marketingcontentTest.php`

## Verdict

All seven checklist items pass after three in-scope repairs. Every one of the twelve routes resolves
to an existing controller method with the right signature, every method returns the house envelope
under the platform-admin guard, and every SPA call matches a registered route and an exported
module function. `php artisan route:list` shows exactly the twelve `api.admin.testimonials.*` /
`api.admin.company-services.*` routes with the inherited admin middleware, and a whole-app route
name scan reports no duplicates. One cross-workstream rendering nit (two module nav sections both
labelled "Content") cannot be fixed from this workstream's files — recorded under Unfixable.

## Fixed

1. **`CompanyServiceController` page-link closure rule 500'd on array input instead of 422.**
   The custom rule called `preg_match()` / `str_contains()` / `preg_match('/\s/')` directly on
   `$value`; `nullable|string|max:255` does not stop later rules from running (no `bail`), so a
   request with `page_link[]=x` reached the closure with an array and threw a `TypeError`, turning
   what should be the `string` rule's 422 into a 500. Guarded the closure with
   `! is_string($value)` (value ''/null still returns early), so malformed input now produces the
   normal validation response.

2. **`TestimonialController::update()` deleted the superseded photo before the transaction
   committed.** The old file was removed inside the `DB::transaction` closure, *before*
   `ActivityRecorder::record()`; an audit-write failure would roll the row back to the old path
   while the old file had already been deleted (and the catch then deleted the new file too — the
   row would point at nothing). The replacement path is now read before the transaction and the
   old file is deleted only after the transaction returns; the catch still removes the new file on
   rollback. The workstream's own test (`old file exists == false` after a successful replace)
   still holds — deletion now runs just after commit, before the response.

3. **Same ordering bug in `CompanyServiceController::update()` for the background image** —
   identical fix (`$previousImage` hoisted out of the closure, deleted only after commit, before
   `forgetNavCache()`).

## Verified, no change needed

1. **Route → method signatures.** All twelve routes point at existing methods:
   `TestimonialController::{index, store, update(Request, Testimonial), destroy(Request,
   Testimonial), toggle(Request, Testimonial), backfillPhotos}` and
   `CompanyServiceController::{index, store, update(Request, CompanyService), destroy(Request,
   CompanyService), toggle(Request, CompanyService), reorder}`. Parameter names match the
   `{testimonial}` / `{companyService}` bindings, so implicit route-model binding resolves.
   `backfill-photos` and `reorder` are static segments registered ahead of the parameter routes
   (static segments outscore parameters regardless).
2. **Envelope + scoping.** Every method returns `$this->ok(...)` / `$this->error(...)` from
   `ApiController`, with `paginationMeta` on both index endpoints; writes run in `DB::transaction`
   with the audit row written inside it. These tables are platform-wide marketing content (no
   `business_id`/`store_id` column), so tenant scoping does not apply — the equivalent guard is
   `EnsuresPlatformAdmin` on every method plus the `permission:admin.content` route gate, which is
   necessary because in-business "Super Admin" roles bundle the `admin.*` permission names. Same
   shape as the ad12/ad16/ad17 siblings.
3. **SPA ↔ API contract.** `TestimonialsView.vue` → `testimonialApi.list/create/update/toggle/
   remove/backfillPhotos` and `CompanyServicesView.vue` → `companyServiceApi.list/create/update/
   toggle/remove/reorder` are all exported by `src/api/modules/ad18-marketing-content.ts` and map
   exactly onto the registered routes. Multipart updates use `POST` + `_method=PUT`; method
   spoofing is enabled by `Illuminate\Foundation\Http\Kernel::handle()`'s
   `enableHttpMethodParameterOverride()` (verified in vendor). Envelope shapes line up with
   `useDataTable`'s `{ data: { data, meta } }` fetcher contract and every prop of `AppModal`,
   `ConfirmDialog`, `EmptyState`, `SortHeader`, `StatusBadge`, `TableFooter`, `TableSkeleton` used
   by the views matches the component signatures. `useDataTable` sends `q`/`status` as empty
   strings when unset; the global `ConvertEmptyStringsToNull` middleware (present in
   `getGlobalMiddleware()`, not removed by `bootstrap/app.php`) turns them into nulls so the
   `nullable` + `Rule::in` validation accepts them.
4. **Route module shape.** `routes/api/v1/admin/ad18-marketing-content.php` has no prefix and no
   auth/audience/team wrapper — only the `permission:admin.content` + `AdminApiActivityLogger`
   group, the same idiom as ad04/ad09/ad11/ad12/ad15/ad16/ad17 (the shared `admin.php` applies no
   per-module permission gate, so the group is required, not redundant). A whole-app scan
   (`route:list --json` + name count) reports **no duplicate route names**, and
   `php artisan route:list --path=api/v1/admin` lists the twelve routes with
   `auth:sanctum`, `token.audience:admin`, `SetPermissionsTeamId`, `permission:admin.content` and
   `AdminApiActivityLogger`.
5. **SPA registration modules.** The router module default-exports a `RouteRecordRaw[]` with two
   children of `/` (`content/testimonials`, `content/company-services` — no leading slash, each
   with `meta.title`); no other module claims those paths or names. The nav module default-exports
   `[{ label, nodes }]` with `label`/`icon`/`to`/`permission` nodes — the exact shape
   `AdminLayout.vue` globs and filters. Caveat below.
6. **Imports resolve.** PHP: controllers use `ApiController`, `EnsuresPlatformAdmin`,
   `ActivityRecorder`, the models, `Cache`/`DB`/`Log`/`Storage`/`Rule`/`ValidationException`, all
   present; the route file imports resolve and the unqualified `Route` facade alias works (proved
   by `route:list`). SPA: `@/api/client` (`api`, `apiErrorMessage`, `HOME_URL` — exported via
   `@/lib/runtimeConfig`), `@/composables/useDataTable`, `@/stores/auth` (`can`), `@/stores/ui`
   (`success`/`error`) and all seven components exist with matching props; the three TS modules
   parse cleanly under the local `typescript` transpiler and both SFCs compile under the local
   `@vue/compiler-sfc`.
7. **Syntax/format.** `php -l` clean on all four PHP files (after the edits); `vendor/bin/pint
   --test` passes on all four. The nav cache claim was verified against its consumer —
   `app/Providers/AppServiceProvider.php:232` reads `Cache::remember('nav_company_services', …)`,
   and both controllers forget that exact key on every mutation.

Test file static review: helpers mirror the working ad16/ad17 conventions (bearer tokens with
`['admin']`/`['management']` abilities for `token.audience`, `SpatiePermissionSeeder` roles,
`createBusinessOwner()` from `tests/Pest.php`); the assertions match the controllers' actual
payloads (`public_cap`, `counts.*`, `is_legacy_photo`, `page_link` normalisation before uniqueness,
reorder 422s, home API cap of 6) and the migrations it relies on (`page_link` unique index,
`background_image_path`, `photo` longText). Per the fleet rule, `php artisan test` and
`npm run typecheck` were not run.

## Unfixable (outside WS-18 file ownership)

**The sidebar renders two "Content" headings.** `src/nav/modules/ad16-support-inbox.ts` and
`src/nav/modules/ad18-marketing-content.ts` each default-export a section labelled `Content`, and
`storify-admin/src/layouts/AdminLayout.vue` (a shared file this workstream must not edit)
`flatMap`s module sections verbatim with no cross-module merge, so both headings render (also a
duplicate `:key`). Merging the two modules' nodes into one section requires editing either
`AdminLayout.vue` or the ad16 module — both outside this workstream's ownership. Both nav modules
now carry top-of-file mounting notes flagging this for the orchestrator; the ad18 note asks for the
nodes to be merged into the single Content section rather than appended.
