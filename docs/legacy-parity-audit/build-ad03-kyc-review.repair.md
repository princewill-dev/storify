# AD-03 (WS3) — KYC review — repair pass

Verified against the actual files (not the completion report). Workstream files:

- API: `app/Http/Controllers/Api/V1/Admin/KycApplicationController.php`,
  `app/Services/KycApprovalService.php`, `app/Services/KycTransitionResult.php`,
  `app/Mail/KycRejected.php`, `resources/views/emails/kyc/rejected.blade.php`,
  `routes/api/v1/admin/ad03-kyc-review.php`,
  `tests/Feature/Api/ad03kycreviewTest.php`
- SPA (admin): `src/api/modules/ad03-kyc-review.ts`,
  `src/router/modules/ad03-kyc-review.ts`, `src/nav/modules/ad03-kyc-review.ts`,
  `src/views/KycApplicationsView.vue`, `src/views/KycApplicationReviewView.vue`

## Verdict

Passes all seven checklist items. Every route resolves to a real method with the right
signature, all four controller methods return the house envelope and the platform read is
gated beyond the route permission, every SPA call maps to a registered route and an exported
module symbol, the route module carries no prefix/name wrapper and its four names are unique
app-wide, the router/nav modules have the right shapes, every import resolves (SFCs compile,
TS modules transpile), and `php -l` is clean on all PHP files. Four small in-scope repairs
were made (one UI defect, three idiom/accuracy fixes); no route, envelope, tenancy or
contract defect was found. One wiring item is outside this workstream's file ownership and
is recorded under Unfixable.

## Fixed

1. **422 `review_notes` error always rendered under the reject form — even for approve**
   (`src/views/KycApplicationReviewView.vue`). Both forms submit the same field name; a
   failed approve (the only server error it can raise is an over-long note) painted the
   reject textarea red. Added `approveError`/`rejectError` computeds keyed off
   `confirmAction`, and `openConfirm()` now records the attempted action before its
   client-side reject-reason check, so the message lands under whichever form produced it
   (server 422 or client-side miss). SFC re-parsed/compiled with `@vue/compiler-sfc`:

       const approveError = computed(() => (confirmAction.value === 'approve' ? fieldErrors.value.review_notes : ''))
       const rejectError  = computed(() => (confirmAction.value === 'reject'  ? fieldErrors.value.review_notes : ''))

2. **Nav module missing the type annotation every peer admin nav module carries**
   (`src/nav/modules/ad03-kyc-review.ts`). Added the same `NavLeaf`/`NavNode` local types and
   `Array<{ label: string; nodes: NavNode[] }>` annotation used by `ad02`/`ad04`/`ad15`, so
   the default export's shape is checked against `AdminLayout`'s glob.

3. **Test file relied on the global `Mail` facade alias while every other test file imports
   it** (`tests/Feature/Api/ad03kycreviewTest.php`). Added
   `use Illuminate\Support\Facades\Mail;` (the alias does resolve — confirmed with
   `class_exists('Mail')` in the booted app — but the suite idiom is an explicit import).

4. **`ad03Application()` docblock listed `payload` among columns `$fillable` predates.**
   `payload` *is* in `KycApplication::$fillable`; corrected the list to
   `business_id, kyc_document_id, device_type, browser and ip_address` (the columns
   `forceFill` is genuinely needed for, matching `Management\KycController`'s comment).

## Unfixable (file owned by another workstream)

1. **The `kyc_pending` badge and the nesting under the layout's "Businesses" group are not
   wired.** The nav module ships the "KYC Submissions" leaf with a top-of-file mounting note,
   but both require edits to `src/layouts/AdminLayout.vue` (do-not-edit shared file): its
   hardcoded "Businesses" group must take the leaf, and its `onMounted` dashboard fetch
   (`adminApi.dashboard()`, already returning `stats.kyc_pending` beside `stats.users`) must
   pass the count as the leaf's `badge`. Until that merge lands the sidebar renders the
   module's own "Businesses" heading next to the layout's — deliberate, documented in the
   file header. The dashboard tile half is already done: `DashboardView.vue` (WS-7) links to
   `/kyc-applications?status=submitted` and the queue hydrates that filter from the URL.

## Verified clean (no change needed)

- **Routes → controller methods.** All four route lines point at
  `Api\V1\Admin\KycApplicationController@index/show/approve/reject`; reflection confirms the
  exact signatures (`index(Request)`, `show(Request, KycApplication)`,
  `approve(Request, KycApplication)`, `reject(Request, KycApplication)`), the
  `{application}` binding matches the parameter name, and those are the only four public
  methods. `php artisan route:list --path=api/v1/admin/kyc-applications` shows the four
  routes with the inherited `auth:sanctum` / `token.audience:admin` / `team.context` stack
  plus the module's own `permission:admin.businesses` and `AdminApiActivityLogger`.
- **Envelope, platform gate and tenancy.** Every method returns `$this->ok(...)` (no raw
  `response()->json`); validation failures flow through Laravel's 422 handler exactly like
  the reference `WarehouseController`. The queue is deliberately platform-wide (legacy's
  admin KYC domain), and each method calls `authorizePlatformAccess()`, which rejects any
  actor that is not `superadmin`/`admin` — necessary because the in-business "Super Admin"
  role bundles `admin.*` permissions and would otherwise pass the middleware gate (the test
  asserts that 403 for a business-scoped account). Mutations run in the service's
  `DB::transaction`; the `review_notes`/`reviewed_by`/`approved_at`/`rejected_at` writes go
  through `forceFill` and the owner cascade follows the application's `user_id`, not a
  request-supplied id.
- **SPA ↔ API contract.** A script extracted every `api.<verb>` call from the three SPA
  files and matched it against the full `route:list --json` URI set — all four
  (`GET/POST /admin/kyc-applications[/{id}/{approve|reject}]`) resolve, and each is exported
  by `src/api/modules/ad03-kyc-review.ts` (`list`/`get`/`approve`/`reject`, plus the types
  the views import). Response shapes match the envelope exactly (`{data, meta:{…,
  status_counts}}` for the queue, `{data:{application}}` for show,
  `{data:{application, notified}, message}` for the actions), and the views read those paths.
- **Route module shape and name uniqueness.** `ad03-kyc-review.php` contains only `Route::`
  lines inside one `permission:admin.businesses` + logger group — no `prefix()`/`name()`
  wrapper of its own; it matches `ad01`/`ad04`'s idiom. `api.admin.kyc-applications.*`
  appears nowhere else (`grep` over every route module and parent file), no other module
  registers those URIs, and the legacy `admin.business-kyc.*` names are distinct.
- **Router/nav module shapes.** The router module default-exports two `RouteRecordRaw`
  children of `/` (`path: 'kyc-applications'`, `'kyc-applications/:id'` — no leading slash)
  with `meta.title`, and both names are unique across all admin modules and
  `router/index.ts`. The nav module default-exports `{ label, nodes }` sections whose node
  carries `label`/`icon`/`to`/`permission` — exactly `AdminLayout`'s `NavNode` shape.
- **Imports resolve.** A resolution pass over every `@/` and relative import in the five SPA
  files found no missing target; both SFCs parse and `compileScript`/`compileTemplate` clean
  under `@vue/compiler-sfc`, and the three TS modules transpile clean via
  `tsc.transpileModule`.
- **PHP syntax.** `php -l` clean on the controller, service, result, mailable, route module,
  test and blade. The rejection blade was additionally *rendered*
  (`new KycRejected($user, $application)->render()`), proving the markdown view resolves and
  the `management.kyc.show` link (route exists in `routes/v1/management.php`) generates.
- **Legacy parity trace** (`Admin\BusinessKycApplicationController`). Queue: latest
  `submitted_at` first, status filter (roadmap's deliberate `submitted` default), status
  counts, pagination 20 — match. Approve: optional `review_notes ≤ 2000`, status +
  `approved_at` + `rejected_at` cleared + `reviewed_by` + owner `active`, non-fatal
  `KycApproved` queue — match, plus the status guard legacy lacked. Reject: required
  `review_notes`, status + `rejected_at` + `approved_at` cleared, owner `pending`, and the
  *new* `KycRejected` email (legacy only flashed "notified" and sent nothing) — match. The
  review payload is the deliberate superset (`selfie_url`, `kyc_document_type_id`,
  `kyc_document_id`, `payload`, plus history) the roadmap's improve-on-legacy items require.
- **Cross-workstream integration.** `BusinessLifecycleController@activate` and
  `UserModerationController@activate` call `KycApprovalService::autoApproveOpenApplication`
  with the shipped three-argument signature; `Management\KycController@store` force-fills
  `business_id`/`device_type`/`browser`/`ip_address`/`kyc_document_id`, so the columns this
  review payload surfaces are actually populated in production; the admin dashboard's
  `kyc_pending` counts `submitted` — the same set the queue defaults to.
- **Tests.** Authored, not executed (fleet rule). Every helper is uniquely `ad03`-prefixed
  (no collisions across the suite); `createBusinessOwner` exists in `tests/Pest.php` and the
  platform-admin/seeded-permission premises hold against `SpatiePermissionSeeder` +
  `Gate::before`; the mail-failure tests match `KycApprovalService`'s try/catch contract; the
  cross-tenant/audience/permission boundary tests match `EnsureTokenAudience`,
  `SetPermissionsTeamId` and `authorizePlatformAccess`.

## Notes (considered, deliberately not changed)

1. `KycApplicationController@history` rows carry no inline document URLs. The reviewer still
   reaches earlier uploads — every history row links to that application's own review page,
   which renders its `identification_document_url`/`selfie_url` (paths survive on the row
   unless WS-10 replaced the file and cleared it). WS-10's repair report flagged this as an
   optional payload extension; it is not a defect against either workstream's acceptance
   criteria, so no change was made.
2. `status=`, `per_page=` and `q=` sent empty are safe: the global `ConvertEmptyStringsToNull`
   middleware turns them into null, and `??` / `(int) (… ?? 20)` fall back to the defaults.
3. `TableSkeleton :cols="7"` renders seven shimmer columns for an eight-column table —
   cosmetic only.

Commands run: `php -l` (7 files), `php artisan route:list --path=api/v1/admin/kyc-applications`
(+ `--json` extraction cross-checks), `@vue/compiler-sfc` parse/`compileScript`/`compileTemplate`,
`tsc.transpileModule`, an on-disk import-resolution pass, a mailable render check. Not run, per
fleet rules: `php artisan test`, `npm run typecheck`.
