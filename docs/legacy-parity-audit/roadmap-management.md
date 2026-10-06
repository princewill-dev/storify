# Roadmap — storify-management (business dashboard)

**Sources:** every `mgmt-*.md` audit and its `.verify.md` verification pass in `.legacy-gap-audit/`. Verification corrections are treated as authoritative over original audit text where the two conflict (e.g. `product_code` already exists in the order payload; bank proof exists at transaction level; `JournalEntry::STATUS_VOID` is live; the auth field is `next`, not `next_step`).

**Scope:** `storify-management` (business audience) + the `storify-api` management slice (`routes/api/v1/management.php`, `app/Http/Controllers/Api/V1/Management/**`). Admin-audience (`storify-admin`, `routes/api/v1/admin.php`) features found inside `mgmt-*` audits are deferred to the admin roadmap — see **Deferred**.

**Rule applied:** every feature whose verified `api_status` or `spa_status` is `missing` or `partial` is carried into exactly one workstream or listed under **Deferred** with a reason. Features already `exists` and needing no work are recorded as no-op notes in the owning workstream (orders 2.8 guard tightening is the one "exists but must change" exception, noted in WS-12).

**Effort scale (one engineer):** S ≤ 1 week · M ≈ 1–3 weeks · L ≈ 3+ weeks.

**Endpoint conventions:** paths below are relative to the existing management prefix — `routes/api/v1/management.php`, e.g. `POST /management/orders/{order}/accept` → `/api/v1/management/orders/{order}/accept`; route names follow the existing `orders.index` style; middleware follows the existing `permission:<ability> <action>` pattern. Controllers live under `app/Http/Controllers/Api/V1/Management/**`.

---

## Build order at a glance

| # | Workstream | Phase | Features | Effort | Depends on |
|---|---|---|---|---|---|
| WS-01 | Business Onboarding & Auth Shell | Foundations | 6 | M | — |
| WS-02 | Store Onboarding & Slug | Foundations | 6 | M | — |
| WS-03 | Store Detail Shell & Dashboard Tab | Foundations | 2 | M | WS-02 |
| WS-04 | Store Settings, Branding & Lifecycle | Foundations | 9 | L | WS-02, WS-03 |
| WS-05 | Storefront Enablement & Branding | Foundations | 4 | M | WS-02, WS-04 |
| WS-06 | Warehouse Management & Product↔Warehouse | Foundations | 8 | L | — |
| WS-07 | Subscription Plans & Trial Onboarding | Foundations | 6 | M | WS-01 |
| WS-08 | Paystack Billing & Activation | Foundations | 5 | L | WS-07 |
| WS-09 | Subscription Gate, Banners & Trial Lifecycle | Foundations | 4 | M | WS-07, WS-08 |
| WS-10 | KYC Verification & Activation Gate | Foundations | 7 | L | WS-01 |
| WS-11 | Payment Configuration (Banks, Paystack, Store Assignment) | Foundations | 6 | L | WS-01, WS-02 |
| WS-12 | Order Fulfilment Actions, Activity & Emails | Foundations | 11 | L | WS-01 |
| WS-13 | Orders List & Detail Parity | P0 | 9 | M | WS-12 |
| WS-14 | Products Form, Variants & Media | P0 | 4 | L | WS-06 |
| WS-15 | Inventory Transfers Workflow | P0 | 8 | L | WS-06 |
| WS-16 | Accounting: Expenses | P0 | 4 | M | — |
| WS-17 | POS Oversight & Sessions | P0 | 11 | M | WS-03 |
| WS-18 | Transactions Parity & Payment Emails | P0 | 8 | M | WS-11 (emails only) |
| WS-19 | Customers Module Parity | P0/P1 | 11 | M | WS-03 |
| WS-20 | Staff & Roles Parity | P0/P1 | 16 | L | WS-06 |
| WS-21 | Invoices Module | P1 | 9 | L | — |
| WS-22 | Bills & Suppliers | P1 | 7 | L | — |
| WS-23 | Chart of Accounts & Journal Workflows | P1 | 8 | M | — |
| WS-24 | Accounting Report Exports & Polish | P1 | 10 | M | WS-23 (GL account picker) |
| WS-25 | Products List, Bulk Actions & Detail | P1 | 5 | M | WS-14 |
| WS-26 | Dispatches Board | P1 | 1 | S | WS-12 |
| WS-27 | Store Tabs (Products/Sales/Transactions/Customers/Staff/Invoices) | P1 | 6 | M | WS-03, WS-21 |
| WS-28 | Dashboard Widgets & Store Switcher | P0/P1 | 9 | M | WS-15, WS-17, WS-06 |
| WS-29 | Stock Visibility & Low-Stock Model | P1 | 3 | M | WS-06, WS-15 |
| WS-30 | Services Catalogue | P1 | 4 | M | — |
| WS-31 | Categories Polish & Audit Logging | P1 | 3 | S | — |
| WS-32 | Coupons & Early-Access Redemption | P1 | 4 | M | WS-07, WS-08 |
| WS-33 | Support Messaging | P1 | 2 | S | — |
| WS-34 | Global Search, Shell Badges & Avatar | P1/P2 | 6 | M | most modules |
| WS-35 | Store Web Metrics & Storefront Analytics | P2 | 2 | M | WS-03, WS-05 |
| WS-36 | Sections & Product↔Section | P2 | 6 | M | WS-06 |
| WS-37 | Accounting Settings, Closing & Reconciliation | P1/P2 | 6 | L | WS-23 |

---

## Phase 0 — Foundations (unblocks other workstreams)

### WS-01 — Business Onboarding & Auth Shell — M
**Delivers:** account-misc 2.1 (business setup form, P0 blocker), 2.2 (onboarding step routing), 3.4 + staff-roles C22 (forced password change), 8.1 (impersonation banner), subscription-kyc 6.2 (consume the auth `next` signal; the plans destination itself is WS-07).

**API**
- `POST /management/setup` — validate name/location/description/phone, create `Business` (status active), attach to user, seed business Spatie roles (`SpatiePermissionSeeder::createRolesForBusiness`), bootstrap ledger (`LedgerSetupService::ensureForBusiness`). Decision item per verify: persist the phone (legacy dropped it) and decide whether `slug` is used.
- `POST /management/auth/change-password` — extend: when `force_password_change = true`, accept new password + confirmation with **no current-password field** (legacy forced-flow parity); otherwise require current password as today.
- `GET /management/auth/me` — add `impersonator` block (when impersonated) so the shell can render the banner. Keep `next` (`change_password | verify_email | setup | plans | dashboard`) and `subscription` as-is.

**Controllers:** new `Api\V1\Management\SetupController`; extend `Api\V1\Auth\ManagementAuthController` (+ `BuildsAuthResponses`).

**SPA**
- New views `SetupView.vue` (`/setup`), `ForcedChangePasswordView.vue` (`/change-password`).
- Router guard: redirect on `auth.next` (`setup` → `/setup`, `change_password` → `/change-password`, `plans` → `/plans` once WS-07 lands); `LoginView`/`VerifyOtpView` must stop hard-pushing `/`.
- `AppLayout.vue`: amber impersonation banner + "Return to Admin" wired to existing `authApi.stopImpersonation` (currently uncalled).

**Acceptance criteria**
- A freshly verified user with no business is forced through `/setup`; afterwards the dashboard returns real numbers (no `business_id = null` zeros), and the business has roles + a chart of accounts.
- A `force_password_change` user cannot reach any dashboard route until the new password is set; flag clears; audit log written.
- Impersonated session shows the banner; Return to Admin restores the admin session.

**Improve on legacy:** honor `next` server-side per request, not just client-side (deep links cannot bypass onboarding); resolve the killed-legacy fallback where `/management/change-password` was a standalone Bootstrap page.

---

### WS-02 — Store Onboarding & Slug — M
**Delivers:** stores 1.1 (list parity: card grid, filters, quick actions), 1.2 (create store, P0 — `CreateStore` action + `CreateStoreRequest` already exist server-side), 1.3 (live slug check), 1.4 (creation success page), storefront-styling 2 (online-storefront model choice + branding fields on create), 3 (slug availability endpoint, shared by create/enable/wizard).

**API**
- `GET /management/stores` — extend: `status`, `from`, `to` filters; add `description`, `location`, `categories_count` to payload; fix `customers_count` (currently always null).
- `GET /management/stores/create-options` (or fold into `GET /management/stores/meta`) — currencies list, business bank accounts, staff list for the create form.
- `POST /management/stores` — wrap existing `App\Actions\Stores\CreateStore`; logo upload, store-model flags (`has_website`), bank/staff pivots; store starts `pending`.
- `POST /management/stores/check-slug` — `{available, slug, url}`; throttled like the legacy route (outside heavy middleware).

**Controllers:** extend `Api\V1\Management\StoreController`; reuse `CreateStore`, `CreateStoreRequest`, `ReservedStoreSlug`.

**SPA:** rebuild `StoresView.vue` (filters + per-card actions: View, Orders, Storefront link when `has_website`, settings deep link) and add `StoreCreateView.vue` (`/stores/create`, also reused as `/stores/:id/storefront` wizard base) + `StoreCreatedView.vue` (`/stores/:id/finalize`).

**Acceptance criteria**
- A business owner creates a physical and/or online store with logo, description, socials; reserved slugs rejected; on collision a `-1`-suffixed suggestion renders; the finalize page shows the live URL with copy-to-clipboard.
- The list reproduces the legacy filters and quick actions; the fields the storefront renders (`logo_url`, `description`, socials) round-trip.

**Improve on legacy:** the legacy create form never showed `ownership_type_id`/`business_type_id`; keep them out. The legacy index rendered filters server-side only — surface them in the UI deliberately.

---

### WS-03 — Store Detail Shell & Dashboard Tab — M
**Delivers:** stores 2.1 (tabbed store detail shell, hash deep links, permission-gated tabs), 2.2 (per-store dashboard tab — verify says its API is `partial`: `GET /management/dashboard` already accepts `store_id` and returns store-scoped stats/recent_orders/revenue_series). Also unblocks account-misc 6.1 (same screen).

**API**
- `GET /management/stores/{store}/dashboard` — extend the store-scoped dashboard payload: revenue %-change vs last month, completed split, active-product + stock totals, store-scoped unique customers, low-stock **list** with the reconciled threshold (WS-29), POS/web cards data (behind WS-17/WS-05).

**Controllers:** extend `Api\V1\Management\StoreController` or add `Api\V1\Management\StoreDashboardController`.

**SPA:** `StoreDetailView.vue` (`/stores/:id`) with tab shell (`dashboard`, `products`, `sales`, `transactions`, `customers`, `invoices`, `staff`, `web`, `settings`); header actions Visit Storefront / Open POS Portal / Stock Adjustment (deep links land in WS-05/WS-17/WS-15). Tabs are separate workstreams but the shell ships here.

**Acceptance criteria**
- Rows in the stores list open the detail page; deep link `#settings` works; tabs lazy-load; restricted staff see only assigned stores and permitted tabs.
- Dashboard tab shows the four metric cards (revenue + split, sales, products + stock, customers), 6-month chart, recent sales, storefront + POS + low-stock cards (data cards land as dependencies complete; shell + cards render empty states meanwhile).

---

### WS-04 — Store Settings, Branding & Lifecycle — L
**Delivers:** stores 3.1 (details/branding edit incl. logo lifecycle + reserved-slug rules), 3.2 (social links), 3.3 (service charges CRUD + toggle — POS checkout consumes them), 3.4 (store staff assignment via store context; API/store_ids path exists), 3.8 (settings screen shell with danger zone), 4.1 (suspend with reason + `StoreSuspended` mail), 4.2 (reactivate with KYC gate + `StoreReactivated` mail), 4.3 (delete with incomplete-orders/transactions guards + `store.deleted` log), 5.1 (delivery route CRUD, ₦→kobo handling + nationwide default). Self-critique: 5.1 could split out if necessary.

**API**
- `PUT /management/stores/{store}` — name/contacts/address/description/logo/slug (slug rules per verify: slug is wizard/enable-only in legacy — decide whether settings may change it; recommend freeze once live with a warning), socials, service-charge special fields (keep legacy field contract or — preferred — dedicated endpoints below).
- `POST /management/stores/{store}/service-charges`, `PUT /management/stores/{store}/service-charges/{charge}`, `DELETE .../{charge}`, `PATCH .../{charge}/toggle` — preferred over legacy's overloaded `PUT stores/{store}` branches.
- `POST /management/stores/{store}/staff`, `DELETE /management/stores/{store}/staff/{user}` (or reuse staff `store_ids` sync; see WS-20).
- `PATCH /management/stores/{store}/suspend` (`reason` ≤ 2000), `PATCH .../activate` (KYC-approved guard), `DELETE /management/stores/{store}` (guards).
- `POST/PUT/DELETE /management/stores/{store}/delivery-routes[/{route}]` (`country`, `state`, `area`, fee in kobo, days ≥ 1, active).

**Controllers:** new `StoreSettingsController`, `StoreLifecycleController`, `StoreDeliveryRouteController` (Api/V1/Management); reuse `StoreSuspended`/`StoreReactivated` mailables.

**SPA:** `StoreSettingsView.vue` (tab inside `StoreDetailView` and standalone `/stores/:id/settings`); cards per legacy: Storefront CTA, Details, Socials, Service Charges, Staff, Bank Accounts (read-only link to WS-11), POS card (WS-17), Delivery Routes, Info sidebar, Danger Zone.

**Acceptance criteria**
- Logo replace deletes the old file only after success; reserved slugs refused; both lifecycle emails fire; suspend hides the storefront; delete refuses while orders aren't `completed` or transactions aren't `confirmed`.
- Service charges created here appear in the POS service-charge read endpoint.

**Improve on legacy:** KYC gate checks the **acting user** in legacy while emails go to the acting user, not the owner (verify corrections 3–4) — decide deliberately: gate on the owner's KYC and email the owner. Add the tenant check legacy omitted on bank/store routes.

---

### WS-05 — Storefront Enablement & Branding — M
**Delivers:** storefront-styling 4 (Enable Web Storefront modal incl. nationwide delivery upsert, P0 gateway to the online channel), 5 (storefront wizard), 6 (web storefront status card + Visit link — data already in store payload), 8 (branding edit — actually delivered in WS-04; here only the storefront-facing confirmation/visit surface). Also stores 3.7 (duplicate of 4/5 — track as one).

**API**
- `POST /management/stores/{store}/enable-website` — set `has_website`, slug, name; upsert nationwide `DeliveryRoute` (state "All States", Nigeria, fee ×100 kobo, default 3 days). Decide the guard legacy lacked (verify: `enableWebsite` had no `has_website` refusal on direct POST).
- `POST /management/stores/{store}/storefront` — wizard submit; `template` validated-but-discarded in legacy — either drop the chooser or introduce a persisted theme (product decision).

**SPA:** modal on the store dashboard; wizard `StorefrontCreateView.vue` (`/stores/:id/storefront/create`); status card in `StoreDetailView` with Live badge + Visit Store.

**Acceptance criteria:** enabling a store puts `{slug}.{domain}` live and creates the delivery route that checkout charges; re-enabling an already-online store behaves deliberately (guarded or idempotent).

---

### WS-06 — Warehouse Management & Product↔Warehouse — L
**Delivers:** inventory 1.1 (list), 1.2 (create), 1.3 (edit — collapse the legacy three-edit-surface mess into one form + modal), 1.4 (deactivate/soft-delete; decide whether soft-deleted rows disappear from lists — a deliberate change vs legacy), 1.5 (detail shell: 4 stat cards, tabs, warehouse info), 1.7 (add product to warehouse + product↔warehouse link — cross-ref products 13), 1.8 (sections tab overview — section CRUD is WS-36), 1.9 (assign staff to warehouses; cross-ref staff-roles A13).

**API**
- `GET /management/warehouses` (paginate — note legacy paginated but rendered no pager; build real pagination), `POST /management/warehouses`, `PUT /management/warehouses/{warehouse}`, `DELETE /management/warehouses/{warehouse}` (soft `status = deleted`), `GET /management/warehouses/{warehouse}` (stats: total stock, sections, low stock via reconciled threshold, assigned staff), `POST/DELETE /management/warehouses/{warehouse}/staff[/{user}]` or reuse `staff_ids` sync.
- Product link: `POST/PUT /management/products` gain **validated** `warehouse_id` (exists + accessible; required for non-digital — legacy enforced "Please assign the product to a warehouse") and return `warehouse_id`/`warehouse` name in payloads; section→warehouse auto-fill already exists in the model hook.

**Controllers:** new `Api\V1\Management\WarehouseController`; extend `Api\V1\Management\ProductController` (validation + payload) and `StaffController` (warehouse picker source).

**SPA:** `WarehousesView.vue` (`/warehouses`, card grid + quick edit/delete), `WarehouseDetailView.vue` (`/warehouses/:id`, tabs Products/Sections/Activity/Settings), warehouse-context product create entry (`/warehouses/:id/products/new` preselects warehouse/section). Nav: new "Inventory → Warehouses" group.

**Acceptance criteria**
- A new business creates a warehouse, assigns staff, and assigns products to it; staff see only assigned warehouses. Physical products cannot be saved without a warehouse.
- Products created in the new UI are no longer warehouse-less — the downstream receiving/transfer flows have a warehouse to work with.

**Improve on legacy:** fix the multi-select render bug (legacy kept only the last staff id); decide the warehouse products grid source of truth (verify: grid is `Product`-driven, count is `StockLocation`-driven and they can disagree); build real pagination; exclude deleted warehouses consistently (legacy left undelete paths).

---

### WS-07 — Subscription Plans & Trial Onboarding — M
**Delivers:** subscription-kyc 1.1 (onboarding plans page), 1.2 (current plan card), 1.3 (trial card), 1.4 (plans grid + switch buttons), 1.5 (change plan), 1.6 (select plan / start trial), and closes 6.2's SPA half by providing the `/plans` destination. (1.7 checkout deep link deferred — dead route.)

**API**
- `GET /management/subscription/plans` — active non-trial plans (name, description, amount, currency, interval, interval_count, features, is_default), monthly/yearly grouping + savings %, `trial_enabled`/`trial_days` from settings, empty state.
- `GET /management/subscription` — current subscription (plan, status, starts/expires, next amount), trial state + days remaining, billing history (WS-08 owns the payment rows; render section empty until then).
- `POST /management/subscription/select-plan` — guard active subscription; set `selected_plan_id`; start trial or redirect to payment.
- `POST /management/subscription/change-plan` — non-trial active plan, different from current; swap immediately, no proration; log `subscription.plan_changed`; message "new billing amount applies on next renewal".

**Controllers:** new `Api\V1\Management\Subscription\PlanController`.

**SPA:** `PlansView.vue` (`/plans`, onboarding layout), `SubscriptionView.vue` (`/subscription` — current plan + trial + grid + billing history). Nav: "Subscription" entry (owner-only, per verify's gate note).

**Acceptance criteria**
- Fresh verified+set-up user with `next: plans` lands on `/plans`; selecting a plan starts the platform trial (if enabled) and lands on the dashboard, else routes to checkout.
- Subscribed users see current plan + switch modal; monthly/yearly tabs and "Save N%" computed same as legacy.

**Improve on legacy:** show the select CTA even when `selected_plan_id` is set but unpaid (verify: legacy trialing users saw buttonless cards); zero-amount coupons at checkout are WS-32.

---

### WS-08 — Paystack Billing & Activation — L
**Delivers:** subscription-kyc 2.1 (payment page), 2.2 (idempotent Paystack initialization), 2.3 (callback + double verification + activation — the money path), 2.4 (billing history, verified `partial`), 2.5 (renewal gate experience + messaging).

**API**
- `GET /management/subscription/payment` — guard redirects (active sub / no selected plan / plan inactive); plan summary + amount.
- `POST /management/subscription/process-payment` — idempotency-key contract, coupon-aware amount (`max(0, amount − discount)`), atomic pending `Subscription`+`Payment`+`Transaction`, Paystack initialize, redirect URL; ≤ 0 total routes to instant activation (WS-32 action).
- `GET /management/subscription/callback` — double-verify with Paystack (status, exact kobo amount, currency), transactional activation: payment success, transaction confirmed, subscription active (`+1 month`/`+1 year` by interval), clear trial, user active/verified, activate pending/suspended stores, coupon usage increment/exhaustion, `StoreActivated` + `AdminStoreCreated` emails, `LedgerPostingService::postSubscriptionPayment`, session cleanup, dashboard feedback.
- `GET /management/subscription/payments` — last 20 for the active subscription (date, plan name from metadata, truncated reference, amount, status pill). Note verify: subscription payments appear in the generic transactions list already — gate its Confirm/Reject/Refund actions by order/invoice presence.

**Controllers:** `Api\V1\Management\Subscription\PaymentController`; port `PaystackService` usage; reuse mailables + `LedgerPostingService`.

**SPA:** `SubscriptionPaymentView.vue` (`/subscription/payment`) — plain server POST to gateway (do not port the unused public-key variable); callback route shows success/failure and refreshes `me`. Billing-history table inside `SubscriptionView.vue`.

**Acceptance criteria**
- Re-submitting the same idempotency key re-redirects to the stored authorization URL — no second charge.
- Gateway amount/currency mismatch marks the payment failed and sends nothing to the ledger; success activates subscription + stores, increments coupon usage, emails, posts ledger, and the user lands on the dashboard with the success flash.
- Expired subscription without trial routes the owner to `/plans` ("Please select a plan to continue") once WS-09's gate is live.

**Improve on legacy:** surface the coupon-discounted total on the checkout page (legacy hid it though applied); make renewal an explicit affordance (`payment_type = renewal`) rather than only expire→repurchase.

---

### WS-09 — Subscription Gate, Banners & Trial Lifecycle — M
**Delivers:** subscription-kyc 6.1 (server-side subscription gate + plans redirect contract), 6.3 (dashboard banners — 4 variants), 6.5 (trial reminder/expiry job + store pausing), 6.6 (trial settings read path; fold into plans payload). Also account-misc 1.10 (same banners).

**API/server**
- Middleware `management.subscription` on the management route group: exempt list per legacy but fix the verify-identified holes (`plans.remove-coupon` was gated → session coupon could never be removed; `subscription.select-plan/payment/process-payment` were onboarding-gated).
- `GET /management/dashboard` — add banner payload: subscription state, days left, expired flag, selected plan name.
- `App\Jobs\ProcessTrialExpirations` port + scheduler entry: reminders ≤ 3 days, day-0 expiry, +3-day `TrialExpiredMail` + set active stores back to `pending`; use the dormant `trial_reminder_day5/6/7_sent_at` markers for idempotency (verify: legacy re-sent per tick).

**SPA:** dashboard banners in `DashboardView.vue` with CTAs (Upgrade/Pay/Choose Plan); nav "Subscription" entry self-hides for non-owners per legacy gate.

**Acceptance criteria:** an owner without active subscription/trial is redirected to `/plans` from every non-exempt route (API and SPA); trial reminders/expiry fire once each; stores pause at expiry+3.

---

### WS-10 — KYC Verification & Activation Gate — L
**Delivers:** subscription-kyc 5.1 (status page 4 states), 5.2 (identity fields + 18-year rule), 5.3 (document type, ID number, uploads — note both uploads are optional in legacy; decide deliberate parity), 5.4 (submission state machine/guards), 5.5 (`BusinessKycSubmitted` + `AdminKycSubmitted` emails), 5.6 business-visible outcome + `KycApproved` mail (admin review screen is a cross-audience dependency — see Deferred), 5.7 (store-activation KYC gate + dashboard banners). Also account-misc 1.11 and 7.1 (same feature, different audit).

**API**
- `GET /management/kyc` — latest application status (submitted/approved/rejected + timestamps + `review_notes`), active document types.
- `POST /management/kyc` — multipart: legal name, phone, DOB (`before:-18 years`), address fields, document type, ID number, documents; store under `kyc/documents` + `kyc/selfies`; capture device/browser/IP; new row per submission (history retained); user `status = pending`; both emails queued with failure isolation.

**Controllers:** new `Api\V1\Management\KycController`, reuse `SubmitKycRequest` + mailables; extend `StoreLifecycleController@activate` (WS-04) to require approved KYC.

**SPA:** `KycView.vue` (`/kyc`) — status card + submission/resubmission modal; dashboard KYC banners; sidebar KYC entry with status pill (Pending/Verified/Rejected/**Required** — verify: the "Required" pill exists when absent). Owner-only.

**Acceptance criteria:** all four status states render with correct copy; resubmission only allowed after rejection; submission blocks while submitted/approved; store activation refuses without approved KYC; storefront/dashboard banners appear only for owners with pending stores.

**Cross-audience dependency:** storify-admin has no KYC review screen — without admin approve/reject, submissions can never leave "under review". Coordinate with the admin roadmap (admin-businesses.md) for a same-release dependency.

**Improve on legacy:** fix orphaned files on resubmission; add a rejection email (legacy flashed "owner notified" but sent nothing).

---

### WS-11 — Payment Configuration (Banks, Paystack, Store Assignment) — L
**Delivers:** finance 3.1 (business bank accounts + Paystack verify, auto-register `bank_transfer`), 3.2 (Paystack gateway config: connect/edit/toggle/test/remove), 3.3 (method → store assign/unassign + method-info), 3.4 (store bank accounts CRUD + primary — legacy UI dead, fold the rebuild into 3.1/3.3), 3.5 (per-store Manage Payment Methods modal; cross-ref stores 3.5), and accounting 10.1 (StoreBankController — same routes, dedupe).

**API**
- `GET /management/payment-settings` — banks (masked) + gateways + store assignment counts.
- `POST /management/payment-settings/bank-accounts`, `PUT .../{bank}`, `DELETE .../{bank}`, `PATCH .../{bank}/primary`; `POST /management/payment-settings/verify-bank` (Paystack account-name resolution); `POST /management/payment-settings/banks` (Paystack bank list proxy, cached).
- `POST /management/payment-settings/gateways`, `PUT .../{gateway}`, `DELETE .../{gateway}`, `PATCH .../{gateway}/toggle`, `POST .../{gateway}/test`.
- `POST /management/payment-methods/{type}/{id}/assign`, `DELETE .../unassign/{store_id}` — **one unambiguous type resolution** (verify: legacy `assignStore` was a no-op for the modal's `paystack` code and `unassignStore` stripped the wrong pivot); add the business/tenant checks legacy missed.
- Store-side: assign/remove bank from a store; `GET /management/stores/{store}/payment-methods` for the modal's four lists.

**Controllers:** new `Api\V1\Management\PaymentSettingsController`; reuse `PaystackService` (`testConnection`).

**SPA:** `PaymentSettingsView.vue` (`/settings/payment-settings`) with bank + gateway sections, add/edit modals, verify flow; Manage Payment Methods modal from the store settings screen; nav entry "Payment Settings".

**Acceptance criteria**
- Adding a first bank auto-registers `bank_transfer`; duplicate `account_number + bank_code` refused; account-name resolution gates submit; set-primary clears others; primary cannot be deleted.
- Gateway test reports success/failure; remove cascades store assignments; assignment controls which methods appear at checkout for a store.
- Paystack **secret key is write-only** (never round-tripped to the browser) — a deliberate fix of the legacy leak. Fix the `business_payment_method.config` vs storefront `pivot.api_keys` mismatch so keys can actually reach checkout.

---

### WS-12 — Order Fulfilment Actions, Activity & Emails — L
**Delivers:** orders 2.1 accept (blocked by pending-payment warning 2.10), 2.2 process, 2.3 dispatch (+`OrderDelivery` creation, driver assignment), 2.4 deliver, 2.5 complete, 2.6 cancel-with-reason, 2.7 return-with-stock-restoration, 2.9 payment-status update (`partial` — add `unpaid`, payment-method/currency, `paid_at` alignment), 2.10 payment-pending warning, 2.11 `OrderStatusUpdatedMail` (customer + store owner + admin, de-duplicated), 6.1 refund reachability (return prerequisite). Also tightens 2.8 (existing free-form status PUT) — restrict it to the guarded transitions or keep it only as an escape hatch with logs.

**API** (guarded, side-effecting, each in a DB transaction):
- `POST /management/orders/{order}/accept` (refuse when first transaction pending unless override), `/process`, `/dispatch` (`driver_name`, `driver_phone`, `tracking_number`, `delivery_notes`, `estimated_delivery_at`, `delivery_agent_id`), `/deliver` (delivery record → delivered + `actual_delivery_at`), `/complete`, `/cancel` (`reason` ≤ 500 appended to notes), `/return` (`reason`, stock restore via `StockLedgerService::recordAddition`, delivery → returned + `return_reason`).
- `PUT /management/orders/{order}/payment-status` — extend to `unpaid` (void/delete transaction deliberately — legacy deleted; recommend void), create manual transaction with `cash` method + NGN + `MAN-` reference.
- `GET /management/orders/{order}/delivery-agents` or `GET /management/staff?role=delivery` — agent picker source.
- Activity: write `ActivityLog` rows on every transition (feeds WS-13's timeline).

**Controllers:** extend `Api\V1\Management\OrderController`; reuse `StockLedgerService`, `OrderStatusUpdatedMail`.

**SPA:** Order detail action bar + confirmation modals (accept/process/dispatch/deliver/complete/cancel/return), payment-pending warning modal, payment-status control. Guarded actions become the primary path; status dropdown demoted.

**Acceptance criteria**
- Each action enforces its source-state guard, writes activity, sends `OrderStatusUpdatedMail` to customer + owner + admin (deduped), and commits atomically; invalid states flash the legacy refusal messages.
- Dispatch creates the `OrderDelivery` (status `assigned`) and **persists** `tracking_number` + `delivery_agent_id` (legacy validated tracking but dropped it, and ignored the agent id — fix, do not port).
- Return restores stock for every line with a stock location at the order's store — do not add a digital-only filter (verify: legacy had none); refunds of delivered/completed orders become reachable.

**Improve on legacy:** no `OrderDelivery` ETA field in the modal unless added deliberately (legacy ETA was always null); cancel does not restore stock in legacy either — review deliberately.

---

## Phase 1 — Daily-use modules (ordered P0 → P1)

### WS-13 — Orders List & Detail Parity — M
**Delivers:** orders 1.1 (list filters/search/metrics), 1.2 (store-scoped list entry — store tab is WS-27; here add the store filter control), 1.3 (metric cards), 1.4 (detail screen depth), 1.5 (activity timeline — data now written by WS-12), 1.6 (delivery tracking panel), 1.7 (bank/proof-of-payment panel on the order), 3.1 (edit order pricing/notes), 3.2 (delete order — **seed `orders delete` permission**, currently unseeded so the existing DELETE endpoint and button are unreachable). Verify notes: `product_code` already in the payload (SPA must render it); bank data exists at transaction level (only the order-context panel is missing).

**API**
- `GET /management/orders` — add `source` filter, items count, payment-method label, split-payment leg count; add `GET /management/orders/stats` (Total/Pending/Dispatched/Delivered + the 5 extra legacy counters) or a `stats` block.
- `GET /management/orders/{order}` — add `delivery`, `activity`, `staff` (Processed-By), `deliveryRoute`, `storeBank` on embedded transactions, product images.
- `PUT /management/orders/{order}` + `GET .../edit` data — `shipping_fee`, `tax`, `notes` validation + server-side total recompute.
- `DELETE /management/orders/{order}` — already exists; seed the permission (and/or `SyncPermissions`).

**Controllers:** extend `OrderController`.

**SPA:** `OrdersView.vue` (metric cards, source/store/date filters, Clear, POS badge, split/remaining indicators, human-readable status labels); `OrderDetailView.vue` (delivery tracking panel, bank panel, activity timeline, notes, Processed-By, item images/codes); `OrderEditView.vue` or modal (`/orders/:orderNumber/edit`).

**Acceptance criteria:** list reproduces legacy columns and all filters; detail renders every legacy card; editing recalculates total = subtotal + shipping + tax + service charge server-side; delete works for a role holding the permission.

**Improve on legacy:** fix the store-code/numeric filter bug (legacy `stores/{store}/orders` compared a code string); drop the silent no-op Status/Payment selects from the edit form; seed the missing permission rather than dropping the middleware.

---

### WS-14 — Products Form, Variants & Media — L
**Delivers:** products 5 (full create/edit form: warehouse/section, cost price, discount %, VAT, bulk pricing, attributes size/weight/color/tags, currency, featured, COD, rich description), 6 (variants editor + API fixes: size/weight/currency/featured per variant, `has_variants ⇒ variants` validation, quantity > 0, update-in-place instead of delete/recreate, disabling variants deletes rows), 7 (image management SPA: gallery, delete, set primary — plus fix the API `is_primary` bug where every upload is stored non-primary), 8 (digital files UX: stored/missing indicator, listing). Cross-refs: products 13 (warehouse-context create — entry point in WS-06), storefront-styling 10/verify (featured toggle feeds the storefront — render side in WS-35/storefront repo). Side effects to restore: `ActivityLog` (`business_create_product`/`business_update_product`), `InventoryCostingService::syncFromCostPrice()`, and initial stock (`stock_quantity`) so sold/stock math populates.

**API**
- Extend `POST/PUT /management/products`: add `size`, `size_unit_id`, `weight`, `weight_unit_id`, `color`, `currency_id` rules; require `warehouse_id` for non-digital (WS-06); fix `syncImages()` primary bug; fix `syncVariants()` orphan rows; add store-access validation on `store_id` for update (verify: update path has no accessible-store check).
- `GET /management/products/{product}` — widen detail payload: store/category/section/warehouse names, tags, dimensions/units, `stock_quantity`, sold quantity, stock percentage/level, `cod_available`, `has_variants`, views, variant dimensions/currency/featured, price ranges.

**Controllers:** extend `ProductController`; call `InventoryCostingService` + `ActivityLog`.

**SPA:** rebuild the create/edit surfaces as full views (`/products/create`, `/products/:id/edit`) rather than the thin modal: all fields above, variant row editor with add/remove, existing-image gallery with primary/delete, digital file list with stored/missing state, Trix-class rich text or a deliberate plain-text replacement.

**Acceptance criteria:** a product can be entered with the real selling data legacy accepted (cost price feeds accounting costing; attributes persist; variants round-trip with stable ids; first upload becomes primary; disabling variants deletes them). Data blocker: existing `store_id = NULL` warehouse-only products are invisible/403 in the new stack — WS-14 must decide the migration (default store or an accessible "warehouse-only" mode).

---

### WS-15 — Inventory Transfers Workflow — L
**Delivers:** inventory 2.1 (list + status tabs), 2.2 (create: source/destination, product grid, search/select-all, steppers, draft/submit, `?from_warehouse=` pre-selection, L), 2.3 (detail: items, timeline, details, action bar, L), 2.4 (approve with per-line quantity adjustment + acknowledgement loop + reject with reason), 2.5 (dispatch with stock-sufficency validation → ledger removals), 2.6 (receive → destination stock location create/update, ledger additions), 2.7 (submit/cancel), 2.8 (warehouse Send/Request deep links — screens obsolete, capability only). Depends on WS-06.

**API**
- `GET /management/transfers` (+`status`), `POST /management/transfers` (draft/pending, validation ≥ 1 item, qty ≥ 1, distinct locations), `GET /management/transfers/{transfer}`.
- `PATCH /management/transfers/{transfer}/submit|cancel|approve|reject|acknowledge|dispatch|receive` — reuse `StockLedgerService::recordRemoval/recordAddition`; keep per-line `approved_quantity` semantics and the awaiting-acknowledgment state; `transfers approve` gates quantity editing; write the legacy `transfer.*` logs.
- `GET /management/stock-locations?source_type=&source_id=` + `GET /management/transfers/source-products?location=...` for the create grid.

**Controllers:** new `Api\V1\Management\StockTransferController`.

**SPA:** `TransfersView.vue` (`/inventory/transfers`), `TransferCreateView.vue` (`/inventory/transfers/create`), `TransferDetailView.vue` (`/inventory/transfers/:code`) with action bar + confirm modals; warehouse "Send stock"/"Request stock" buttons → pre-seeded create.

**Acceptance criteria:** full state machine `draft → pending → (approved | awaiting_acknowledgment → approved) → dispatched → received`, plus `rejected`/`cancelled`, all state-guarded; dispatch fails atomically with the per-line shortage message surfaced to the user (an improvement — legacy logged it and flashed a generic error); receive creates destination stock locations; ledger balance before/after rows written.

---

### WS-16 — Accounting: Expenses — M
**Delivers:** accounting 4.1 (list stats/filters: This Month, This Year, Records; category + date filters; Clear; row links), 4.2 (Record Expense form: account, amount, VAT, payment method, paid-from account, receipt upload, description — posts `paid` + balanced ledger entry), 4.3 (detail: details grid, journal-entry lines, receipt rendering, Void entry), 4.4 (void posts reversal; delete unposted only). Daily-use heart of accounting.

**API**
- `GET /management/accounting/expenses` — add `category_id`, `from`, `to`, stats block.
- `POST /management/accounting/expenses`, `GET .../expenses/{expense}`, `POST .../expenses/{expense}/void`, `DELETE .../expenses/{expense}` (guards: posted cannot be deleted, void not double-voidable, posting failure warns without losing the row).
- `GET /management/accounting/accounts?active=1&type=` — picker source (already exists; ensure it filters active).

**Controllers:** new `Api\V1\Management\Accounting\ExpenseController`; reuse `LedgerPostingService`.

**SPA:** extend `ExpensesView.vue` (stats, filters, Record Expense button), add `ExpenseFormView.vue` (`/accounting/expenses/create`) and `ExpenseDetailView.vue` (`/accounting/expenses/:id`).

**Acceptance criteria:** recording an expense stores the receipt and posts expense debit + payment credit with the VAT split; void reverses; posted expenses refuse delete with the legacy message.

---

### WS-17 — POS Oversight & Sessions — M
**Delivers:** pos 1.1 (sessions list, KPIs, tabs/store filter, terminal deep-link), 1.2 (global session detail: sales records, financials, staff activity), 1.3 (per-store session history with expected/actual/difference), 1.4 (per-store session detail), 2.1 (open session with opening float), 2.2 (close with cash-count reconciliation — owner can close the store's latest open session), 2.3 (store POS control card), 3.1 (enable POS — cross-ref stores 3.6), 3.2 (POS sidebar group + counts), 3.3 (Open POS dashboard KPI — card rendered in WS-28), 4.1 (POS source filter + badge in Orders — SPA side in WS-13, API here). POS 6.1 (cashier assignment) already exists — no-op.

**API**
- `GET /management/pos/sessions` (`store_id`, `status`, pagination; KPIs: open count, today's POS sales, POS stores), `GET /management/pos/sessions/{session}` (session + orders + transactions + difference + cashier's recent sessions), `GET /management/stores/{store}/pos/sessions` (+`/{session}`).
- `POST /management/stores/{store}/pos/open` (float kobo, pos_enabled check, duplicate-open guard), `POST .../pos/close` (`closing_balance_actual` + notes; target the store's latest open session deliberately; return expected/actual/difference), `POST .../pos/enable`.
- Extend `GET /management/orders` with the `source` filter (dedupe with WS-13) and include `pos_session_id` where needed.

**Controllers:** new `Api\V1\Management\Pos\SessionController`; reuse `PosSession::close()`/`calculateCashSalesTotal()` — do not recompute reconciliation.

**SPA:** `PosSessionsView.vue` (`/pos/sessions` + `/stores/:id/pos/sessions`), `PosSessionDetailView.vue`, store POS control card inside `StoreDetailView`. Nav: "POS" group with open-session count + per-store rows deep-linking to the terminal.

**Acceptance criteria:** expected = opening float + confirmed cash legs, difference = actual − expected shown over/short; owner sees all cashiers' sessions (the POS-audience API cannot do this — it self-scopes); `VITE_POS_URL` setting replaces the dead `config('pos.link')` (verify: all legacy deep links rendered `href=""`); multiple open sessions per store are surfaced.

---

### WS-18 — Transactions Parity & Payment Emails — M
**Delivers:** finance 1.1 (list: store/date filters, store+customer columns, Clear), 1.2 (detail: order/invoice context, customer block, balance impact, structured reason display, shareable detail route), 1.3 (confirm — add `PaymentConfirmedMail` to customer + owner + admin, summary modal), 1.4 (reject — `PaymentRejectedMail`, decide optional-vs-required reason, invoice short-circuit), 1.5 (refund — `RefundProcessedMail`, current-balance display + friendly insufficient-balance message, hide button for delivered/completed orders), 2.1 (payment slip viewer — expose `payment_slip` URL + image/PDF preview), 2.2 (pending-transactions sidebar badge — rendered in WS-34), 2.3 (restricted-staff store scoping on index/show/actions).

**API**
- `GET /management/transactions` — add `store_id`, `date_from`, `date_to`; expose store/customer names.
- `GET /management/transactions/{transaction}` — add order context (status/items/totals/source/staff), customer block, `payment_slip` URL, structured rejection/refund reasons.
- Wire mailables into confirm/reject/refund; enforce `accessibleStoreIds` for restricted staff in `authorizeTransaction` and index.

**Controllers:** extend `Api\V1\Management\TransactionController`.

**SPA:** `TransactionsView.vue` — filters, columns, richer detail drawer (or route), slip viewer modal, balance-impact panel, action-button visibility rules.

**Acceptance criteria:** confirming/rejecting/refunding queues the three legacy mails respectively with the legacy accept/reject semantics; the slip renders for pending bank transfers; a store-restricted staff member cannot see or act on other stores' transactions.

---

### WS-19 — Customers Module Parity — M
**Delivers:** customers 1.1 (list: account_id search, restricted-staff scoping — currently every `customers view` holder sees the whole business, per-user order counts), 1.2 (country + store filters + 4 stat cards), 1.3 (detail: real route `/customers/:accountId`, pending count, delivery addresses from customer columns, transactions, activity, spend definition decision), 1.4 (edit: status field Active/Suspended/Deleted + email-verification sync, account summary, prefill fix), 1.5 (suspend with **required stored reason** — currently validated then discarded; add ActivityLog), 1.6 (activate with audit + guard), 1.7 (activity timeline), 1.8 (search group: `account_id` + full-name CONCAT matching, per-result deep link), 1.9 (store-scoped customers tab — UI is WS-27; API scoping here; fix `StoreController@show` `customers_count` null), 4.1 (dashboard active-customer metric — WS-28), 4.3 (nav badge — WS-34). Also decides business vs admin suspension emails (legacy sent them only on admin action).

**API**
- `GET /management/customers` — `account_id` q, `country`, `store_id`, stats block, restricted scoping.
- `GET /management/customers/{customer}` — add pending count, addresses (columns, not the dead relation), transactions, activity; label the spend definition ("Total spent (completed)").
- `PUT /management/customers/{customer}` — add `status` + verification side effects; `POST .../suspend` (`reason` required, persisted to ActivityLog), `POST .../activate` (guard + log).

**Controllers:** extend `Api\V1\Management\CustomerController`; add `GET /management/customers/countries`.

**SPA:** `CustomersView.vue` (stats, filters, reason modal), `CustomerDetailView.vue` (`/customers/:accountId`).

**Acceptance criteria:** suspend requires and stores a reason visible on the timeline; activate restores verification; account_id and full-name search work; the Suspended reason answers "why" from the audit trail.

**Improve on legacy:** write the real actor id (legacy stashed actors in `metadata.user_id`); don't blind-copy the business-vs-admin email asymmetry.

---

### WS-20 — Staff & Roles Parity — L
**Delivers:** staff-roles A1 (directory: store filter, owner row + badge, View, Resend Invite), A2 (invite: optional password + confirmation, documents drag&drop with tags, warehouse field, `exists:roles` validation instead of a 500), A3 (resend invitation), A4 (accept-invite SPA consuming the existing API), A5 (profile screen + `accepted_at`), A6 (edit: photo upload/remove, clear POS PIN, warehouse checkboxes), A7 (**role reassignment — P0; API and SPA both refuse `roles` today**), A8 (documents CRUD), A10 (remove = soft-deactivate `status = deleted`, not hard delete — current hard delete cascades POS sessions and nulls order audit), A12 (store-side roster assign/remove — UI hooks into WS-04), A14 (staff dashboard widget/nav count — rendered in WS-28/WS-34), A15 (staff search group — WS-34), B16–B19 (role list chips, create, edit incl. rename-uniqueness validation, delete guards for all three protected roles). No-op: A9 suspend/activate, A11 store assignment, B20 catalog, C21/C23 password flows exist.

**API**
- `GET /management/staff` (+`store_id` filter), `POST /management/staff` (documents, password, warehouse_ids), `GET /management/staff/{staff}` (add `accepted_at`), `PUT` (roles[], photo, remove_photo, clear pin semantics), `POST .../resend-invite`, `DELETE` → soft delete.
- `GET/PUT/DELETE /management/roles` — add uniqueness-on-update, `exists:permissions`, protected-role list (`Super Admin`, `Developer`, `Store Associate`).
- `GET/POST /management/invitations/{token}` — already exists; SPA consumes.

**Controllers:** extend `StaffController`, `RoleController`.

**SPA:** `StaffView.vue` (filters, owner badge, resend), staff profile route `/staff/:id`, edit modal (roles multi-select, photo, documents), `AcceptInviteView.vue` (`/invitations/:token` with POS redirect for cashiers), `RolesView.vue` (chips, Select All/Clear All).

**Acceptance criteria:** promoting a cashier to manager works via the UI; removing staff keeps order/POS history; invites can be resent; the invite email lands on the SPA, not legacy Blade; protected roles cannot be deleted.

---

### WS-21 — Invoices Module — L
**Delivers:** orders 4.1 (list: stats row All/Draft/Sent/Overdue/Revenue, status tabs, search), 4.2 (create/edit: bill-to with save-as-customer, line items, tax/discount, server recompute, draft-only edit), 4.3 (detail/document view + payment history), 4.4 (PDF via DomPDF — the print artefact), 4.5 (send + remind with payment token + public payment link + **ledger posting on send** per verify), 4.6 (mark fully paid → confirmed `PMT-` transaction, store balance credit, ledger), 4.7 (record partial payment with password confirmation), 4.8 (void), 4.9 (delete draft). Reuses `InvoiceMail`; the public pay page is a storefront-side dependency (`GET /pay/invoice/{token}`).

**API**
- `GET /management/invoices` (+stats/search/tabs), `POST /management/invoices`, `GET/PUT /management/invoices/{invoice}`, `GET .../pdf`, `POST .../send` (+remind), `POST .../mark-paid`, `POST .../record-payment`, `POST .../void`, `DELETE .../{invoice}` (draft-only).
- `GET /pay/invoice/{token}` — storefront route + `InvoicePaymentReceiptMail` for full parity.

**Controllers:** new `Api\V1\Management\InvoiceController`; reuse `InvoiceMail`, `LedgerPostingService`, `computeInvoiceTotals` semantics.

**SPA:** `InvoicesView.vue` (`/invoices`), `InvoiceFormView.vue` (`/invoices/create`, `/invoices/:id/edit`), `InvoiceDetailView.vue` (`/invoices/:id`) with send/remind/void/record-payment modals.

**Acceptance criteria:** totals always server-computed with discount clamp ≥ 0; non-drafts refuse edit (403 parity); send/remind issue a payment token and email with the public link; mark-paid/record-payment credit the store balance and post ledger entries; PDF matches the legacy artefact; the Overdue state is **not** auto-engineered (verify: nothing ever set it — keep the filter but don't build a transition machine).

**Improve on legacy:** legacy's `unpaid` transaction deletion and the record-payment password gate are judgement calls — keep password confirmation as a deliberate security feature; consider optional.

---

### WS-22 — Bills & Suppliers — L
**Delivers:** accounting 5.1 (supplier list: search, Outstanding column, CRUD modals, delete guard when bills exist), 5.2 (supplier detail: contact card, bills, payments, New Bill shortcut), 6.1 (bill list: Outstanding/Paid/Overdue stats, search, supplier + status filters, row links, New Bill), 6.2 (bill form: line items, VAT, optional expense account, auto number, weighted-average inventory costing when a product is given — decide whether to expose the product picker legacy kept backend-only, AP journal posting), 6.3 (bill detail: items, payments, journal link, actions), 6.4 (record payment: ≤ remaining, status transitions, journal posting), 6.5 (void with reversal; refuse when payments exist). Verify fix: no from/to date filter on the bill list UI — q/supplier/status only.

**API**
- `GET /management/accounting/suppliers` (+`q`, outstanding), `POST/PUT/DELETE /management/accounting/suppliers[/{supplier}]`.
- `GET /management/accounting/bills` (+stats, q, supplier, status), `POST .../bills`, `GET .../bills/{bill}`, `POST .../bills/{bill}/payments`, `POST .../bills/{bill}/void`.

**Controllers:** new `Api\V1\Management\Accounting\{SupplierController,BillController}`; reuse `LedgerPostingService` + `InventoryCostingService::recordReceipt`.

**SPA:** `SuppliersView.vue`, `SupplierDetailView.vue`, extend `BillsView.vue` (stats/filters/New Bill), `BillFormView.vue`, `BillDetailView.vue`.

**Acceptance criteria:** AP entries post balanced on create/payment/void; bill statuses open→partial→paid; void refuses with payments; suppliers with bills refuse delete; weighted-average cost updates for product-linked lines.

---

### WS-23 — Chart of Accounts & Journal Workflows — M
**Delivers:** accounting 1.1 (dashboard: cash/bank balances panel, recent entries, unposted-confirmed-payments warning + backfill hint, quick actions), 2.1 (accounts list: balances, grouping, Edit + toggle actions), 2.2 (account create/edit), 2.3 (activate/deactivate with system-account guard), 3.1 (journal list: memo/reference search, credit totals, reference display, Clear, Manual Entry button), 3.2 (journal detail: reference, fiscal period, posted-by/at, reversal banner — verify: no store attribution, no forward link needed), 3.3 (manual journal entry with balanced validation + draft), 3.4 (post draft / delete draft / reverse posted — **reversal must void the original entry** per verify, not just post a contra).

**API**
- `GET /management/accounting/dashboard` — extend payload (balances, recent 8, unposted count).
- `GET /management/accounting/accounts` — add computed balances + `parent`; `POST/PUT /management/accounting/accounts[/{account}]`; `POST .../accounts/{account}/toggle`.
- `GET /management/accounting/journal` (+reference/memo q, credit totals), `GET .../journal/{entry}` (reference, period, posted_by/at); `POST .../journal`, `POST .../journal/{entry}/post`, `DELETE .../journal/{entry}`, `POST .../journal/{entry}/reverse` (uses `LedgerPostingService::reverseEntry` which voids the original).

**Controllers:** new `Api\V1\Management\Accounting\{ChartOfAccountsController,JournalController}`; extend `AccountingController`.

**SPA:** extend `AccountingDashboardView.vue`, `AccountsView.vue` (balances/groups/actions + form modal), `JournalView.vue` (create view `/accounting/journal/create`, detail modal/page with post/delete/reverse).

**Acceptance criteria:** balances are sign-corrected per account type; posting requires ≥ 2 lines, balanced, business-owned accounts; reverse voids the original and creates the contra so reports don't double-count; system accounts cannot be deactivated.

---

### WS-24 — Accounting Report Exports & Polish — M
**Delivers:** accounting 8.2 P&L, 8.3 Balance Sheet, 8.4 Trial Balance, 8.5 General Ledger, 8.6 AR Aging, 8.7 AP Aging, 8.8 VAT Summary, 8.9 Expense Summary, 8.10 Integrity, 8.11 exports (9 CSV + 3 PDF). 8.1 hub is `exists` — no-op. Deliverables per report: CSV export endpoints (legacy headers/rows so files stay interchangeable with accountants' templates), PDF for Trial Balance/P&L/Balance Sheet, plus the missing UI pieces: balanced/out-of-balance indicators, AR/AP Due-date + Paid columns, bucket cards, Share % column, statement breakdown rows, integrity explanation panel.

**API**
- `?export=csv` on all nine report GETs (`response()->streamDownload` parity), `?export=pdf` on the three; keep JSON endpoints untouched.

**Controllers:** extend `AccountingController` + `LedgerReportService`; DomPDF templates under a new `resources/views/pdf/accounting/**`.

**SPA:** export buttons on every report tab; render the missing columns/indicators.

**Acceptance criteria:** a VAT Summary CSV opens in the accountant's template unchanged; P&L/TB/BS PDFs match legacy layout; Trial Balance shows the balanced footer.

---

### WS-25 — Products List, Bulk Actions & Detail — M
**Delivers:** products 1 (list: filters incl. category/warehouse, variant price range + discount display, Store/Section/Source columns, low-stock highlight, clickable name, per-page, currency-aware formatting), 2 (bulk edit price/stock/status), 3 (bulk activate/deactivate), 4 (bulk delete with image/file cleanup), 9 (tabbed product detail: overview/variants/images + widened payload from WS-14). No-op: products 10 (status toggle) and 11 (single delete) already exist — wire any UI polish only.

**API**
- `GET /management/products` — add `from`/`to`, category-name search decision, min–max/discount display fields, `has_variants`, currency.
- `POST /management/products/bulk-update`, `POST /management/products/bulk-status`, `POST /management/products/bulk-delete`.

**Controllers:** extend `ProductController`.

**SPA:** `ProductsView.vue` (row checkboxes + floating bulk bar + modals), `ProductDetailView.vue` (`/products/:id`, tabs). Fix the hardcoded ₦ → data-driven currency symbol.

**Acceptance criteria:** bulk edit applies per selected row tenant-scoped with "N updated" feedback; detail shows stock math (sold, level bar), variant range, images with primary ring, metadata; the product name links to detail.

---

### WS-26 — Dispatches Board — S
**Delivers:** orders 5.1 (dispatch list: 4 metric cards, search over driver/tracking/order, filters modal with status/store/date, active-filter count, table with status badges, ETA, created). Read-only first (legacy never advanced statuses from this screen — do not over-build). Depends on WS-12 for `OrderDelivery` rows.

**API:** `GET /management/dispatches` (+ filters + stats).

**Controllers:** new `Api\V1\Management\DispatchController`.

**SPA:** `DispatchesView.vue` (`/dispatches`) + nav item with the blue open-count badge (WS-34).

**Acceptance criteria:** board shows every order's delivery with the 5 filters and 4 metrics; rows link to the order; empty until WS-12 ships (which is why WS-12 precedes it).

---

### WS-27 — Store Tabs — M
**Delivers:** stores 2.3 (Products tab), 2.4 (Sales tab), 2.5 (Transactions tab — needs a new `store_id` filter on the transactions API), 2.6 (Customers tab — needs store scoping per WS-19), 2.7 (Invoices tab — depends on WS-21), 2.8 (Staff tab). Hosted in the WS-03 shell.

**API:** filters only — `transactions?store_id`, `customers?store_id` (else reuse existing `products`, `orders` filters); no new store-tab endpoints needed.

**SPA:** tab components inside `StoreDetailView.vue`, reusing the module tables with a fixed store scope.

**Acceptance criteria:** each tab reproduces the legacy columns/filters ("Add" deep links into create flows with the store preselected) and stays inside the tab for pagination.

---

### WS-28 — Dashboard Widgets & Store Switcher — M
**Delivers:** account-misc 1.1 (11 KPI cards + permission gating + revenue % change), 1.2 (headline + delta; no zero-fill parity needed per verify), 1.3 (recent orders: item count, relative time, View all), 1.4 (recent transactions table), 1.5 (transfer requests table — depends on WS-15), 1.6 (low-stock panel — depends on WS-29), 1.7 (staff panel), 1.8 (warehouses panel — depends on WS-06), 1.9 (store switcher: Pinia persistence + pass `store_id`), plus the POS card (WS-17 3.3) and subscription/KYC banners (WS-09/WS-10 render hooks already there).

**API:** extend `GET /management/dashboard` payload (stock value, total stock, active customers/stores, warehouses, transfers, POS, staff, web visits, % changes; recent transactions; transfer list; low-stock rows; warehouse rows).

**Controllers:** extend `Api\V1\Management\DashboardController`.

**SPA:** `DashboardView.vue` full rebuild + `AppLayout.vue` store selector.

**Acceptance criteria:** each card is permission-gated and scoped to the selected store or "All Stores"; panels deep-link to their modules; month-over-month % matches legacy definitions.

---

### WS-29 — Stock Visibility & Low-Stock Model — M
**Delivers:** inventory 3.1 (dashboard stock metrics: stock value, transfer requests, warehouses — data side; rendered in WS-28), 3.2 (reconciled low-stock model: adopt `StockLocation.min_quantity` **and build the missing min-level editor** — verify: legacy never wrote `min_quantity`, so the per-location indicator was dead; reconcile the 1–5 vs 1–10 threshold divergence in one place), 3.3 (stock movement history: warehouse Activity tab + paged/filterable movement read API — data already written by POS/storefront/returns). Also account-misc 1.6 (same panel).

**API:** `GET /management/stock-movements?warehouse_id=&product_id=&type=&from=&to=` (paginated, balance columns, performed-by); add `min_quantity` read/write on stock locations; dashboard low-stock list.

**Controllers:** new `Api\V1\Management\StockMovementController`; extend dashboard.

**SPA:** warehouse Activity tab; product/store stock badges; low-stock drill-down.

**Acceptance criteria:** one documented low-stock definition used by dashboard, warehouse card and product list; movement history shows signed qty + balance before/after; per-location low stock works because a min level can actually be set.

---

### WS-30 — Services Catalogue — M
**Delivers:** catalog 6 (list: thumbnails, filters, pagination, row actions), 7 (create: name, description, price, currency, images, store; ActivityLog `service_created`), 8 (edit: image gallery primary/delete, store, status), 9 (delete + image cleanup). Urgency note: the storefront already publicly serves services from this data (`CatalogController@services`) while the business cannot manage them.
**Prerequisite:** currencies endpoint (does not exist in the management API — add `GET /management/meta/currencies` or a business default).

**API:** `GET/POST /management/services`, `GET/PUT/DELETE /management/services/{service}` (decide numeric id vs `service_code` route key up front — legacy bound by code).

**Controllers:** new `Api\V1\Management\ServiceController`.

**SPA:** `ServicesView.vue` (`/services`) + create/edit form with image gallery; nav "Catalog → Services".

**Acceptance criteria:** a business creates a service with image + price and it appears on the storefront; deleting removes image files.

**Improve on legacy:** use the currency relation for symbols (legacy hardcoded ₦); validate store accessibility on update (legacy didn't); build the "no stores" empty state properly (legacy's branch 500'd).

---

### WS-31 — Categories Polish & Audit Logging — S
**Delivers:** catalog 1 (store filter, q search, pagination UI — the SPA currently hard-fetches 100 rows with no pager; slug column), 3 (status control on edit + slug regeneration on rename — verify: legacy regenerated, new API leaves a stale storefront slug), 16 (ActivityLog hooks on category writes; also on future service/section writes via WS-30/WS-36).

**API:** extend `CategoryController` (store_id + q already exist server-side — wire the SPA), add slug regeneration decision, ActivityLog.

**SPA:** `CategoriesView.vue` — filters, pager, slug column, status select on edit. Note the verified correction: filter by **internal store id** (`auth.stores[].id`), not the legacy public code.

**Acceptance criteria:** categories beyond 100 reachable; rename updates the storefront slug or a documented decision not to; deletes still 409 with products (intentional stricter-than-legacy behaviour — keep and surface a hint).

### WS-32 — Coupons & Early-Access Redemption — M
**Delivers:** subscription-kyc 3.1 (coupon entry modal + chip + removal), 3.2 (validation semantics: expiry, per-plan applicability, discount description, session/state handoff to checkout), 3.3 (fully-covering coupon instant activation + exhausted-coupon admin alert — note verify: plans-page auto-activation only fires for plan-scoped coupons; the checkout path activates for any full-cover coupon), 4.1 (early-access pass redemption endpoint + a deliberate code-entry affordance — legacy endpoint existed with no UI).

**API:** `POST /management/plans/validate-coupon`, `POST /management/plans/remove-coupon`, `POST /management/subscription/check-early-pass`; port `ActivateSubscriptionWithCoupon` / `ActivateSubscriptionWithEarlyPass`; `CouponExhaustedMail`.

**Controllers:** new `Api\V1\Management\Subscription\{CouponController,EarlyPassController}`.

**SPA:** coupon modal on `/plans` and the payment page; early-pass entry beside it.

**Acceptance criteria:** invalid/expired/other-plan codes produce the legacy messages; a full-cover coupon activates with no payment and redirects; usage increments and deactivates at max; a coupon applied to plan A is visibly rejected (not silently dropped) when checking out plan B.

---

### WS-33 — Support Messaging — S
**Delivers:** account-misc 4.1 (support inbox: business-scoped list, store/phone/status), 4.2 (reply: required ≤ 2000, status → replied, actor/timestamp, `SupportMessageReplyMail` to customer, audit log — legacy had no management reply UI, so this exceeds legacy).

**API:** `GET /management/support-messages`, `POST /management/support-messages/{message}/reply`.

**Controllers:** new `Api\V1\Management\SupportMessageController`.

**SPA:** `SupportMessagesView.vue` (`/support-messages`) + nav entry with unread/pending indicator.

**Acceptance criteria:** replies email the customer and flip status; ownership enforced against the business's stores.

---

### WS-34 — Global Search, Shell Badges & Avatar — M
**Delivers:** account-misc 5.1 (search: add Stores, Warehouses, Transactions, Staff groups with permission gates, 2-char minimum, per-entity deep links, optional cache — legacy had no Ctrl+K; keep the SPA's net-new keybinding), 3.2 (avatar upload/remove + payload `photo_url` + header rendering), verify-A (sidebar counters: Stores, Warehouses, Staff, Customers, Orders-pending, Dispatches-open, Transactions-pending, POS counts + KYC pill Pending/Verified/Rejected/Required), finance 2.2 (pending-transactions badge, dedupe), staff A14 (staff count), subscription-kyc 6.4 (Subscription + KYC nav entries with status). Badge source: one `GET /management/shell/counts` endpoint (15 s cache per user) instead of scattering counts.

**Cross-cutting (this WS owns):** every nav entry for new modules (Invoices, Dispatches, Payment Settings, Warehouses, Transfers, POS, Services, Subscription, KYC, Support) appears here or in its module WS — final parity check against the legacy sidebar groups.

**Acceptance criteria:** Ctrl/⌘K returns all six legacy groups with working deep links; badges hide at zero and respect restricted-staff scoping; KYC pill shows "Required" when no application exists.

---

## Phase 2 — P2 (schedule after the P0/P1 spine)

### WS-35 — Store Web Metrics & Storefront Analytics — M
**Delivers:** stores 2.9 (Web Store tab + standalone web-metrics page: 4 tiles — Store Views, Product Views, Web Orders, Web Revenue; 6-month web-orders chart; Top Products by Views; Recent Activity feed) and storefront-styling 7 (same feature, deduped). Depends on WS-03 (screen) + WS-05 (enablement).

**API:** `GET /management/stores/{store}/web-metrics` (+`?from=&to=`) over `stores.views`, `products.views`, `orders.source = 'checkout'`, confirmed transactions — data exists; query work only.

**Controllers:** extend `StoreDashboardController`.

**SPA:** web-metrics tab + standalone `/stores/:id/web-metrics`.

**Acceptance criteria:** metrics match `StoreAnalyticsService@web` definitions; page refuses (or hides) for stores without `has_website` — decided deliberately rather than a redirect+flash.

**Storefront-render note (verify):** `featured_products` is fetched by the storefront but never rendered; picking the featured mechanism over slides requires a storefront-repo change (HomeView/CatalogSection consuming `featured_products`) — coordinate; otherwise the featured toggle added in WS-14 changes nothing customer-visible.

---

### WS-36 — Sections & Product↔Section — M
**Delivers:** catalog 10 (section list per warehouse), 11 (create), 12 (detail with stats + paginated products — fix the prefill bug: legacy passed `section_code` where a numeric id was expected, and `ProductController@create` never read the query param at all), 13 (edit), 14 (delete with product guard, soft delete), 15 (product↔section assignment + auto-warehouse inheritance + validation that the section belongs to the business). Depends on WS-06.

**API:** `GET/POST /management/warehouses/{warehouse}/sections`, `GET/PUT/DELETE /management/warehouses/{warehouse}/sections/{section}` (decide numeric id vs `section_code` key up front); `GET /management/sections?warehouse_id=` picker source; product rules gain validated `section_id`.

**Controllers:** new `Api\V1\Management\SectionController`; extend `ProductController`.

**SPA:** sections inside the warehouse detail (tab) + section detail/forms; section picker in the product form (WS-14).

**Acceptance criteria:** section stats (products/active/stock value/out-of-stock) correct; delete refuses with products; product assigned a section with no warehouse inherits the section's warehouse.

---

### WS-37 — Accounting Settings, Closing & Reconciliation — L
**Delivers:** accounting 7.1 (reconciliation list + CSV/TXT statement import), 7.2 (reconcile screen: match/unmatch/ignore, auto-match ±7 days exact amount, complete snapshot), 9.1 (20 auto-posting account mappings), 9.2 (opening balances one-time posting), 9.3 (fiscal periods list + close/reopen), 9.4 (fiscal years + close year). Also finance 4.1 (bank reconciliation — same module, dedupe). P1 portions: 9.2 (without opening balances a mid-year migrant's books start at zero — consider pulling WS-37.9.2 earlier).

**API:** `GET/POST /management/accounting/reconciliation`, `GET .../{import}`, `POST .../{import}/auto-match|complete`, `POST .../lines/{line}/match|unmatch|ignore`; `PUT /management/accounting/settings/mappings`, `POST .../settings/opening-balances`, `POST .../settings/periods/{period}/close|reopen`, `POST .../settings/years/{year}/close` (permission `accounting close`). Tenant guards (`business_id`) on every sub-resource per legacy.

**Controllers:** new `Api\V1\Management\Accounting\{ReconciliationController,AccountingSettingsController}`; reuse `LedgerPostingService::postOpeningBalances`, `LedgerClosingService::closeYear`.

**SPA:** `/accounting/reconciliation` (list, import modal, reconcile screen), `/accounting/settings` (mappings, opening balances, periods, years). Nav entries + `accounting settings`/`accounting close` gates.

**Acceptance criteria:** import parses comma/₦-tolerant CSVs; auto-match is one-to-one within ±7 days and exact amount; complete snapshots closing vs cleared balance; opening balances post once and refuse a second time; closed periods reject new entries.

---

## Cross-cutting work (must be scheduled explicitly)

**Permission strings to seed / verify before the workstreams that need them land**
- `orders delete` — **not seeded today**; seed it (or drop the middleware) in WS-13 so the existing endpoint becomes reachable.
- `invoices view|edit|delete` — confirm seeded; add if missing (WS-21).
- `accounting *`, `stores settings`, `warehouses view|create|edit|delete`, `transfers view|create|approve|dispatch|receive`, `pos open_session|process_sale|close_session|view_history|void_sale`, `customers view|edit|suspend` — already seeded; wire middleware per WS.
- Phantom permissions to resolve deliberately: `customers message` (never implemented in legacy — drop or build as net-new), `transactions export` (no export ever existed — build a filtered CSV in WS-18 or drop), `settings payment` + `accounting reconcile` (guard no route today — they get routes in WS-11/WS-37).

**Navigation entries to add** (final parity target): Warehouses, Inventory → Stock Adjustment (transfers), Dispatches, POS (group with open-session counts), Invoices, Payment Settings, Subscription, KYC Verification (status pill), Support Messages, Services, Sections (under a warehouse rather than top-level). Existing entries to add badges/counts to: Orders, Dispatches, Transactions, Customers, Stores, Staff, Warehouses, POS. Nav is owner/staff-permission-gated exactly as legacy; Subscription under the Finance block gate (`transactions view || invoices view || settings payment`), KYC owner-only.

**Shared components to build once and reuse**
- Table shell with server pagination + filter bar + Clear (orders, products, invoices, transactions, bills, expenses, journal, suppliers, customers, staff, sessions, disputes).
- Bulk-selection bar (products WS-25, warehouse products WS-06, dispatches if ever writable).
- Confirm/detail modal + drawer system (reuse the existing AppModal; add a reason-capture modal variant for cancel/suspend/reject/return/void).
- KPI card + metric grid with per-card permission gating (dashboard WS-28, store dashboard WS-03, POS KPIs WS-17, accounting dashboard WS-23).
- Money/currency formatter (kill hardcoded ₦; support per-store/business currency) — needed by WS-25, WS-30, WS-28, all accounting screens.
- Date-range filter + Nigeria state/city selects (stores, warehouses, delivery routes, KYC).
- File-upload control with preview + size/mime validation (logo, product images, KYC docs, receipts, staff docs, statement import).
- Activity timeline component (order WS-13, customer WS-19, store WS-35, staff ws-20).
- Export plumbing: CSV stream helper (accounting reports WS-24, transactions if approved) + DomPDF pipeline (invoices WS-21, accounting PDFs WS-24) — one server-side render service, not per-module implementations.
- Notification/email dispatch helper with per-recipient failure logging (used by WS-08/10/12/18/21/etc.).

**Emails and notifications that must fire** (all mailables already exist in the repo unless noted)
- Order guarded transitions → `OrderStatusUpdatedMail` (customer + store owner + admin, deduped) — WS-12.
- Invoice send/remind → `InvoiceMail` with public payment link; public pay → `InvoicePaymentReceiptMail` — WS-21.
- Transaction confirm/reject/refund → `PaymentConfirmedMail`, `PaymentRejectedMail`, `RefundProcessedMail` (customer + owner + admin on confirm/reject; customer on refund) — WS-18.
- Subscription activation → `StoreActivated` + `AdminStoreCreated`; coupon exhaustion → `CouponExhaustedMail`; KYC → `BusinessKycSubmitted` + `AdminKycSubmitted` + `KycApproved` (consider adding `KycRejected` — legacy sent none but flashed "owner notified"); trial → `TrialExpiryReminderMail` + `TrialExpiredMail` — WS-08/09/10/32.
- Store lifecycle → `StoreSuspended` / `StoreReactivated` (email the **owner**, not the acting user — verify fix) — WS-04.
- Staff → `StaffInvitationMail` (re-point link at the SPA accept route) — WS-20.
- Support → `SupportMessageReplyMail` — WS-33.
- Admin-initiated customer suspension/activation mails → deferred with the admin module, but decide the business-side email question in WS-19.

**Legacy behaviour to fix, not clone** (consolidated; each is also flagged in its workstream)
1. `tracking_number` / `delivery_agent_id` dead validation and ignored fields in dispatch — persist them (WS-12).
2. Free-form `PUT orders/{order}/status` bypasses every guard — demote to escape hatch with logging (WS-12); `unpaid` payment status silently deleting transactions — prefer voiding (WS-12).
3. `orders delete` permission unseeded (WS-13); `stores/{store}/orders` store-code vs id filter bug (WS-13).
4. Product image first-upload-never-primary bug in the new API; variant update churns ids and cannot turn off; store-access check missing on product update; store_id-NULL products invisible (WS-14).
5. Low-stock thresholds disagree (1–5 new vs 1–10 legacy vs `min_quantity` dead) — unify and build the min-level editor (WS-29).
6. Payment config: Paystack secret round-tripped to browser; store-bank routes don't check the store belongs to the business; assign/unassign pivot type mismatches; `destroyPaystackKeys` cascades across tenants; `business_payment_method.config` vs checkout `pivot.api_keys` mismatch (WS-11).
7. Staff remove is a hard delete (cascades POS sessions, nulls order audit) — soft-deactivate (WS-20); role rename 500s on duplicates; permission-name validation missing.
8. Subscription gate exempt-list holes (`plans.remove-coupon` gated; pay routes onboarding-gated) and trial job re-sends per tick (WS-09).
9. KYC resubmission orphans files; no rejection email despite the flash (WS-10).
10. Coupon silently drops at checkout when not applicable to the chosen plan (WS-32).
11. POS terminal links pointed at a now-undefined `config('pos.link')` → introduce `VITE_POS_URL` (WS-17).
12. Legacy dead UI not to port: storefront wizard template chooser (validated, discarded); category create page missing its status field (new modal already fixed it); various orphaned views/routes (delivery-routes.blade, payment-methods.blade, admin stores/edit) — verify before rebuilding.

---

## Deferred (explicit, with reasons)

| # | Feature(s) | Audit ref | Reason |
|---|---|---|---|
| D1 | Admin customer module: admin list/detail/edit/suspend-activate + admin suspension emails | customers 3.1–3.4, 2.1 | Admin audience (`storify-admin`); out of scope for the management roadmap. Covered by `admin-businesses.md` / `admin-users-admins.md`; schedule in the admin roadmap. Business-side customer features are all carried (WS-19). |
| D2 | Platform admin management: invite/resend/role-change/remove office admins; admin invite-accept SPA; admin user directory gaps (reset-password, restore, delete, plan/business columns, impersonation hand-off) | staff-roles D25–D27, C24 | Admin audience; belongs to the admin roadmap (`admin-users-admins.md`). Account-recovery gap noted there. |
| D3 | Admin KYC approve/reject review screens | subscription-kyc 5.6 (admin half) | Admin audience. **Blocking cross-dependency**: WS-10's business KYC flow is unusable end-to-end until the admin review screen ships — schedule as a same-release dependency in the admin roadmap, not later. |
| D4 | Admin Page Styling CRUD; Storefront Slides; admin store branding writes | storefront-styling §B 9–11 | Admin audience (`admin-content-support.md` / `admin-stores-catalog.md`) **and** legacy-inert (Page Styling never rendered; slides never rendered). If featured-products replaces slides, only a storefront-render + featured toggle (WS-14) is needed; add `KycRejected`-style deliberate decisions to the admin roadmap. |
| D5 | Locations module (CRUD, detail) | inventory 4.1, stores 6.1 | Dead legacy code: no routes registered, `location_id` dropped from warehouses, views unreachable, model unused. Reintroduce only on an explicit product decision for multi-branch grouping (would be net-new, not a port). |
| D6 | Standalone warehouse Send/Receive pages + `products-json` endpoint | inventory 2.8 | Obsolete by deliberate consolidation: legacy routes are redirect stubs into transfer-create. The **capability** (send/request with pre-seeded source/destination) is carried in WS-15; only the standalone screens are dropped. |
| D7 | Bulk "move all products" warehouse action (`move-products`) | inventory 2.9 | Orphaned legacy route with no Blade UI; duplicates transfer-create with a dangerous "move everything" shortcut. Revisit only if a bulk-relocate shortcut is requested. |
| D8 | In-dashboard POS terminal + receipt print view | pos 5.1, 5.2 | Superseded by the Electron POS app + POS-audience API; legacy terminal was unreachable (no links) and its deep-link target config no longer exists. Dashboard keeps the launcher/deep-link (WS-17); revisit only if the POS app is retired. |
| D9 | Category hierarchy UI (`parent_id` selectors/tree/validation) | catalog 5 | Schema-only in both stacks; the new storefront does not render child categories, so exposing it creates unusable data. Defer with the storefront consumer; the API currently accepts/returns `parent_id` without validation — stop silently accepting it until then (small guard item in WS-31). |
| D10 | Subscription cancellation / scheduled cancel | subscription-kyc 2.6 | Never existed for businesses in legacy (verify: not even admin-side). Parity = no affordance; any cancel flow is net-new product scope. |
| D11 | Per-store payment-mode toggle (`auto`/`manual`) | finance 3.6 | Dead legacy UI (no view referenced the route). Keep `stores.payment_mode` as a data field; revisit only if guardrails are wanted (WS-11 could absorb it cheaply). |
| D12 | Checkout deep-link stub `GET /management/plans/checkout/{plan}` | subscription-kyc 1.7 | Dead-end legacy redirect kept for URL compatibility; the real flows are select-plan and change-plan (WS-07). |
| D13 | Staff landing dashboard (`GET /staff` hub with module tiles / cashier redirect) | staff-roles verify-A | Staff-app/POS audience — superseded by the POS app + management SPA; not part of the business dashboard parity. Revisit in the staff/POS app roadmap if the hub is wanted. |
| D14 | Order status free-form writes as a standalone product surface | orders 2.8 (exists) | Not dropped: the endpoint is kept but must be demoted/logged (WS-12). Recorded here so it is not mistaken for a missing primary control. |

**No-op confirmation (audited, already `exists`, zero work):** category create/delete, accounting reports hub, product status toggle + single delete, staff suspend/activate + store assignment + catalog + self-service password flows, POS cashier assignment, profile details + change password + logout/logout-all (logout-all button is optional net-new), customer self-service password reset. Do not rebuild these.

---

## Suggested sequencing summary (how to slice the release train)

1. **Unblock onboarding + money:** WS-01, WS-07, WS-08, WS-09, WS-10 (KYC), WS-11, WS-02. These make the product usable by a brand-new business end to end.
2. **Unblock the daily trade loop:** WS-12, WS-13, WS-06, WS-14, WS-15, WS-16, WS-17 as parallel teams.
3. **Module depth:** WS-18 through WS-29 in dependency order above.
4. **Depth and long tail:** WS-30 through WS-37 plus WS-34's shell parity sweep as the final gate before calling the management UI "not scanty".

Dependencies that must not be violated: WS-12 → WS-13/WS-26 (delivery rows); WS-06 → WS-14/WS-15/WS-29/WS-36; WS-02/WS-03 → store tabs/POS card/web metrics; WS-07/WS-08 → WS-09/WS-32; WS-01 → WS-07/WS-11; admin KYC review (D3) before WS-10 is production-honest.


