# mgmt-customers — adversarial verification

**Verifier re-read, independently:** legacy routes (`routes/v1/management.php` lines 279–291, `routes/v1/admin_dashboard.php` lines 210–214, `staff.php`, `account.php`), `Management\CustomerController` and `Admin\CustomerController` in full, `StoreTabController@customers`, `Management\SearchController`, `Customer` model + migrations, `SpatiePermissionSeeder`, all six `resources/views/{management,admin}/customers/*.blade.php`, `management/stores/tabs/customers.blade.php`, both sidebars, `AppServiceProvider` view composer, customer mailables; new stack `routes/api/v1/{management,admin,auth}.php`, `Api\V1\Management\{Customer,Search,Store,Dashboard}Controller`, `Api\V1\Admin\{Dashboard,Search}Controller`, `Api\V1\Auth\CustomerAuthController`, management SPA `CustomersView.vue` / `endpoints.ts` / `SearchModal.vue` / `AppLayout.vue`, admin SPA router/views/endpoints/layout.

**Result:** every legacy route and every Blade file in scope is accounted for in the audit, and no audit status (`exists`/`partial`/`missing`) was found to be wrong. The findings below are six concrete corrections plus one omission from the audit's own "customer-facing messaging" section.

---

## Corrections

### C1 — 1.5/1.6: the legacy *business* UI never had suspend/activate controls (audit's `legacy_views` are wrong)
- The audit claims 1.5 "`resources/views/management/customers/index.blade.php` (no modal — **form post from the row**)" and 1.6 "**row action** in `index.blade.php`". Both are false. `grep -rn "customers.suspend\|customers.activate\|form.*suspend" resources/views/management/` returns nothing; `management/customers/{index,show,edit}.blade.php` contain only the status badge / status `<select>`. The two POST endpoints exist in `routes/v1/management.php:288–290`, but no Blade, button, form or modal anywhere in the business panel posts to them — they were reachable only by crafting a request.
- Consequence: the new SPA's `Suspend`/`Activate` buttons (`CustomersView.vue:144–146`) are the **first working UI** for these actions. The controller parity gaps (required reason, ActivityLog, guards — 1.5/1.6) are real, but there is no legacy UI behaviour to match, and the priority framing "the UI exists in legacy, port it" is wrong for the business side. Appendix C's row "ACTIVE → SUSPENDED | Trigger: business suspend" likewise implies a trigger that never existed.

### C2 — Appendix B: the `customers view` role list is wrong
- Actual grants in `database/seeders/SpatiePermissionSeeder.php`: Managing Director (line 104), Chief Financial Officer (128), Manager (151), Store Manager (172), Cashier (214), Customer Support (235), Auditor (268), Store Associate (286). Business owners hold the per-business **Super Admin** role (`permissions => 'all'`, assigned at 386–389).
- **Delivery Agent (line 243) does NOT have `customers view`** — its block is dashboard/deliveries/orders/products only.
- The audit lists "Business Owner, Store Manager, Customer Support, Store Associate, Cashier, **Delivery Agent**", omitting Manager/Managing Director/CFO/Auditor and wrongly including Delivery Agent (gap #5 repeats the same three role names). The scoping/privacy point in gap #5 still holds for **Store Associate and Cashier** — both are `isRestrictedStaff()` (no `transactions view`) and both are granted `customers view`, so the new business-wide API does expose every customer to them.

### C3 — 1.8 missing list: full-name matching was dropped too
- Legacy `Management\SearchController:115` also matched `orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?")`, so "John Smith" as one term worked. New `Api\V1\Management\SearchController:59–64` LIKEs first/last/email/phone separately — a single-term full-name query no longer matches. Add "full-name (CONCAT) matching" to 1.8's missing list alongside `account_id`, permission gating and per-result navigation.

### C4 — 1.3: "delivery addresses … rendered only in admin" is inaccurate
- Neither legacy panel renders the `deliveryAddresses` relation. The admin Address Information card (`resources/views/admin/customers/show.blade.php:131–139`) reads **customers-table columns**: `street_address`, `apartment`, `city`, `state`, `zip_code`, `country` (columns exist via `2025_11_05…create_customers_table` / `2026_08_05…add_address_fields…`). The relation loaded by both controllers is dead data. Build the address card from the columns; do not model it as a delivery-addresses list.

### C5 — 1.3 missing list omits per-user scoping of stats/recent orders
- Legacy `Management\CustomerController@show:103–117` limits recent orders (`where('user_id', $user->id)`) and computes all four stats over `$customer->orders()->where('user_id', $user->id)`. New `Api\V1\Management\CustomerController@show:44–57` aggregates/serves **all** business orders for the customer. Same theme as 1.1's "per-user order counts" — add it to 1.3.

### C6 — 1.2: the "stores lookup" is not missing (only the countries lookup is)
- `GET /api/v1/management/stores` → `Api\V1\Management\StoreController@index` (SPA `storesApi.index`, `endpoints.ts:61–64`) already returns accessible stores; the store-filter control only needs wiring into `CustomersView.vue`. Only the country lookup needs a new endpoint/query.

---

## Omitted feature (inside this audit's §2 "customer-facing messaging" scope)

### O1 — Customer emails on payment confirm / reject / refund
*(Cross-domain: already reported in `mgmt-finance.md` §1.3–1.5 — listed here only because audit §2 is the customer-messaging catalogue and it is absent from it. Do not double-count.)*
- **Legacy:** `Management\TransactionController@confirmPayment` queues `PaymentConfirmedMail` to the **customer** (+ store owner + platform admin); `@rejectPayment` queues `PaymentRejectedMail` with the reason; `@refundPayment` queues `RefundProcessedMail` with the reason (mail failures tolerated, one log line per recipient).
- **Legacy routes:** `POST /management/transactions/{transaction:reference}/confirm|reject|refund`; views `resources/views/management/transactions/show.blade.php` (modals); mailables still present in `app/Mail/`.
- **New stack:** endpoints exist and are wired in the SPA (`storify-management/src/views/TransactionsView.vue`), but `Api\V1\Management\TransactionController` sends **no mail** on confirm, reject or refund (`grep Mail` = empty). This is the third customer-facing email surface (after admin account mails and order-status mails) and the only one audit §2 omits.

---

## Verified as correct (no change)

- **All statuses.** Business index/show/update/suspend/activate are genuinely `partial` (new `Api\V1\Management\CustomerController` matches the audit's description line-for-line: `q` without account_id, business-only scoping, no `status` field on update, nullable discarded `reason`, no guards/transaction/log). Country/store filters + stats and the activity timeline are genuinely absent; no admin customer route/controller/view/nav/endpoint exists (`routes/api/v1/admin.php`, `Api\V1\Admin/`, admin SPA); admin `SearchController` excludes customers; the admin "Customers" tile is the only admin artefact.
- **Latent bug 10 confirmed:** `Api\V1\Management\StoreController@show:45` loads only `['products','orders']`, payload emits `customers_count` (`:69`), `Store` has no `customers` relation → always `null`.
- **4.2 `exists` correct:** storefront OTP reset is implemented (`Api\V1\Auth\CustomerAuthController@forgotPassword/resetPassword:136–171`, routes + SPA views `AccountForgotView.vue`/`AccountResetView.vue`); neither legacy business nor admin controller had a password reset.
- **No customer export, no create screen, no customer messaging UI in legacy** — the audit's gaps #2–#4 are accurate (`customers message` permission is phantom; only `AccountingReportController::csv` exists; invoice `save_customer` is the legacy auto-create path — `InvoiceController:117–126`).
- **Blades fully enumerated:** `management/customers/{index,show,edit}`, `admin/customers/{index,show,edit}`, `management/stores/tabs/customers.blade.php`, `emails/customer-account-*.blade.php`, `emails/customer/order-status-updated.blade.php` — each maps to an audit entry; no unaccounted view elements (stat cards, filter modals, account summaries, last-login badge, address/transactions/activity cards all match).
