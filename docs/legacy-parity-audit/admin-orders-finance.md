# admin-orders-finance — Legacy → New Stack Feature Inventory

**Domain:** Platform order oversight, transactions, subscriptions, coupons, accounting, VAT, payment methods and bank accounts (audience: admin)
**Legacy source of truth:** `storify-api` — `routes/v1/admin_dashboard.php`, `app/Http/Controllers/Admin/{Order,Shop4meOrder,Transaction,Subscription,SubscriptionPlan,Coupon,Accounting,Vat,PaymentMethod,BankAccount}Controller.php`, `resources/views/admin/{order_management,transactions,subscriptions,subscription_fee,coupons,accounting,VAT,payment_methods,bank-accounts}/**`
**New stack:** `storify-api` — `routes/api/v1/admin.php`, `app/Http/Controllers/Api/V1/Admin/**`; `storify-admin` — `src/router/index.ts`, `src/views/**`, `src/api/endpoints.ts`
**Status vocabulary:** `exists` = fully usable in the new stack; `partial` = endpoint or screen present but missing actions/fields/validations from legacy; `missing` = absent from the new stack.

---

## Executive summary

The admin SPA currently ships **seven screens**: Dashboard, Businesses, Business Detail, Stores, Users, Transactions and Coupons. Of the ten controllers in this domain, only **Transactions (read-only)** and **Coupons** have been ported. Everything else — platform **order oversight** (all orders, order detail/edit, status + payment-status transitions, delete, Shop4Me orders), **subscriptions and plan CRUD**, the **entire platform accounting module** (books dashboard, chart of accounts, journal, reports, mappings, fiscal period/year close), **VAT**, **payment methods** and **platform bank accounts** — is absent from both the new API and the admin SPA. The permissions `admin.orders`, `admin.subscriptions`, `admin.accounting` and `admin.finance` are already seeded (`SpatiePermissionSeeder`) but no routes use them.

Two legacy surfaces do have parity already: the **transaction list/detail** (new API is at parity or better on filters, but the status override action is missing) and **coupon management** (full CRUD in API + SPA; only post-create code editing was dropped).

| Area | Features | exists | partial | missing |
|---|---|---|---|---|
| Platform orders oversight | 7 | 0 | 0 | 7 |
| Transactions | 3 | 2 | 1 | 0 |
| Subscriptions & plans | 2 | 0 | 0 | 2 |
| Coupons | 3 | 3 | 0 | 0 |
| Platform accounting | 6 | 0 | 0 | 6 |
| VAT | 2 | 0 | 0 | 2 |
| Payment methods | 1 | 0 | 0 | 1 |
| Bank accounts | 2 | 0 | 0 | 2 |
| **Total** | **26** | **5** | **1** | **20** |

**Headline numbers:** 20 of 26 features have no new API endpoint and no SPA screen at all. The biggest single lift is platform accounting (6 features, ~1 week+); the most urgent is admin order oversight (7 features, the thing an admin opens daily to answer "where is order X").

---

## 1. Platform order oversight

### 1.1 Admin orders list with metric cards, filters, sorting and pagination — `missing`
- **What the user could do (legacy):** Paginated (20/page) platform-wide list of every order across all businesses/stores. Metric cards: Total Orders, Pending, Processing, Total Revenue (sum of `total` for orders with a CONFIRMED transaction). Filter modal: free-text search (order number, customer email/first/last name), store dropdown (active stores), order status (pending/accepted/processing/dispatched/delivered/completed/cancelled/returned), payment status (unpaid/paid/refunded/failed — mapped to transaction statuses; "unpaid" = orders with no transactions), date-from/date-to. Column sorting via `sort_by`/`sort_order` query params (defaults created_at desc). "Filters Active" pill + Clear Filters. Table columns: Order # (link to detail), Customer (or "Walk-in"), Store, Items count, Type (Shop4Me vs Standard badge), Total, Status badge, Payment badge, Date, Actions (view / edit / delete-with-confirm-modal). Sidebar shows a live count badge of pending orders.
- **Legacy route:** `GET /office/orders` (`admin.orders.index`)
- **Legacy controller:** `Admin\OrderController@index`
- **Legacy views:** `resources/views/admin/order_management/index.blade.php`
- **New API:** none — no `/admin/orders` route exists; `Api\V1\Management\OrderController` is business-scoped and not callable by admin tokens.
- **New SPA:** none — no Orders route in `storify-admin/src/router/index.ts`; no nav entry.
- **Key UI:** 4 stat cards, filter modal (search/store/status/payment/date range), 10-column table, per-row action icons, delete confirm modal, pagination.
- **Side effects:** none on read.
- **Missing:** everything.
- **Effort:** M — **Priority:** P0

### 1.2 Admin order detail — `missing`
- **What the user could do (legacy):** Open any order and see: header with Shop4Me/Standard badge and created timestamp; **Items** table (product name, product ID, code, unit price, qty, subtotal) with totals footer (subtotal, shipping, tax, total); **Customer Information** (name, email, phone, full address — "Walk-in" fallback); **Delivery Information** (delivery area/state, delivery route name, estimated delivery days); **Payment Information** (every transaction: reference, payment method, amount, status badge) or "No payment transactions recorded"; **Order Notes**; **Activity Log** timeline (actor name, description, relative time, from `ActivityLog` rows whose subject is the Order); **Quick Info** (store name, store owner, order date, last updated). Actions: Back to Orders, Edit Order.
- **Legacy route:** `GET /office/orders/{order}` (`admin.orders.show`)
- **Legacy controller:** `Admin\OrderController@show`
- **Legacy views:** `resources/views/admin/order_management/show.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Key UI:** order items table + totals, customer card, delivery card, payments card, notes card, activity timeline, quick-info card.
- **Side effects:** none on read.
- **Missing:** everything.
- **Effort:** M — **Priority:** P0

### 1.3 Admin order edit form — `missing`
- **What the user could do (legacy):** Edit an order's financials and state: shipping fee, tax, status (all 8 statuses), payment status (no-change / unpaid / paid / refunded / failed), notes (free text, max 1000). Save writes an `ActivityLog` "updated" row with old/new values and redirects back to the order. The form also renders customer name/phone and delivery street/city/state inputs, but these are **not in `UpdateOrderRequest` rules and are silently discarded** — a legacy dead surface that should not be rebuilt. The `payment_status` select is also a no-op at the model level (see §7 Notes).
- **Legacy route:** `GET /office/orders/{order}/edit` (`admin.orders.edit`), `PUT /office/orders/{order}` (`admin.orders.update`)
- **Legacy controller:** `Admin\OrderController@edit`, `@update`; `App\Http\Requests\Admin\UpdateOrderRequest`
- **Legacy views:** `resources/views/admin/order_management/edit.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Key UI:** items read-only table, Pricing card (subtotal readonly, shipping, tax, total readonly), status/payment selects, notes textarea, Save.
- **Side effects:** `ActivityLog` update row; DB transaction wrapper; error/success flash.
- **Missing:** everything (the useful subset: shipping_fee, tax, notes, status).
- **Effort:** S — **Priority:** P1

### 1.4 Order status update with note and customer email — `missing`
- **What the user could do (legacy):** From the order detail sidebar pick any of the 8 order statuses, optionally add a note; on save the order status changes, the note is appended to `notes` with a timestamp header (`[Y-m-d H:i] Status changed to X: …`), an `ActivityLog` row records old→new status, and a **`CustomerOrderStatusUpdatedMail` email is sent to the customer** (failures logged, not fatal). Flash message: "Order status updated to X. Customer has been notified via email."
- **Legacy route:** `PATCH /office/orders/{order}/status` (`admin.orders.update-status`)
- **Legacy controller:** `Admin\OrderController@updateStatus`
- **Legacy views:** `resources/views/admin/order_management/show.blade.php` (Order Status card)
- **New API:** none.
- **New SPA:** none.
- **Key UI:** status select + optional notes textarea + "Update Status" button.
- **Side effects:** status change; notes append; `ActivityLog`; **customer email**; Laravel log entries.
- **Missing:** everything, including the customer notification email.
- **Effort:** S — **Priority:** P0

### 1.5 Order payment status update (transaction synchronisation) — `missing`
- **What the user could do (legacy):** Set an order's payment status to unpaid/paid/refunded/failed. The controller maps this onto the order's **transaction**: unpaid deletes the existing transaction; pending/paid/refunded/failed updates its status (or creates a new transaction for the order total using the `cash` payment method, else the first payment method, with a generated `MAN-xxxxxxxxxx` reference). Writes an `ActivityLog` "payment_status_updated" row. Validation is only `required|string` (no enum constraint).
- **Legacy route:** `PATCH /office/orders/{order}/payment-status` (`admin.orders.update-payment-status`)
- **Legacy controller:** `Admin\OrderController@updatePaymentStatus`
- **Legacy views:** `resources/views/admin/order_management/show.blade.php` (Payment Status card)
- **New API:** none.
- **New SPA:** none.
- **Key UI:** payment status select (Unpaid/Paid/Refunded/Failed) + "Update Payment" button.
- **Side effects:** transaction create/update/delete; `ActivityLog`; financial reporting downstream (revenue is computed from CONFIRMED transactions, so this changes admin/management revenue numbers).
- **Missing:** everything. Must be implemented atomically and tenant-aware.
- **Effort:** M — **Priority:** P1

### 1.6 Order delete — `missing`
- **What the user could do (legacy):** Delete any order from the list (confirm modal on index and shop4me list). Writes an `ActivityLog` "deleted" row capturing the old values before `$order->delete()` and redirects with a success flash naming the order number.
- **Legacy route:** `DELETE /office/orders/{order}` (`admin.orders.destroy`)
- **Legacy controller:** `Admin\OrderController@destroy`
- **Legacy views:** `resources/views/admin/order_management/index.blade.php`, `shop4me_orders.blade.php` (confirm modals)
- **New API:** none.
- **New SPA:** none.
- **Key UI:** trash action + confirmation modal.
- **Side effects:** order soft/hard delete; `ActivityLog`; order items cascade.
- **Missing:** everything.
- **Effort:** S — **Priority:** P2

### 1.7 Shop4Me orders list with stats and filters — `missing`
- **What the user could do (legacy):** Separate list of platform-fulfilled Shop4Me orders (`source = 'shop4me'`), with metric cards (Total, Pending, Processing, Total Revenue — controller also computes accepted/dispatched/delivered/completed/cancelled/paid but the view renders only four), filters (search order #/customer, store, status, payment status, date-from/date-to), a "Filters Active / Clear Filters" banner, and the same table layout as the main order list minus the Type column, with view/edit/delete actions and pagination (20/page). Every row still links into the shared admin order detail/edit.
- **Legacy route:** `GET /office/shop4me/orders` (`admin.shop4me.orders.index`)
- **Legacy controller:** `Admin\Shop4meOrderController@index`
- **Legacy views:** `resources/views/admin/order_management/shop4me_orders.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Key UI:** 4 stat cards, filter modal, 9-column table with Shop4Me actions.
- **Side effects:** none on read.
- **Missing:** everything.
- **Effort:** S — **Priority:** P1

---

## 2. Transactions (admin)

### 2.1 Platform transaction list with filters — `exists`
- **What the user could do (legacy):** Paginated (15/page) list of every transaction on the platform. Filters: reference (LIKE) and status (enum values: pending/paid/confirmed/refunded/refund_pending/cancelled). Columns: Reference (link to detail), Order (link), Customer (name + email from the order), Payment Method, Amount, Status badge, Date. Reload/reset buttons; empty state.
- **Legacy route:** `GET /office/transactions` (`admin.transactions.index`)
- **Legacy controller:** `Admin\TransactionController@index`
- **Legacy views:** `resources/views/admin/transactions/index.blade.php`
- **New API:** `GET /api/v1/admin/transactions` → `Api\V1\Admin\TransactionController@index` — filters `q` (reference), `status`, sortable by reference/amount/status/paid_at/created_at with direction, `per_page`. Returns reference, amount, currency, status, order, invoice, payment_method, paid_at, created_at.
- **New SPA:** `src/views/TransactionsView.vue` (route `/transactions`), `adminApi.transactions` in `src/api/endpoints.ts`.
- **Key UI:** search box, status select with all 7 statuses, sortable headers, pagination footer.
- **Side effects:** none on read.
- **Gaps vs legacy:** none material; the new list adds invoice + sort controls.
- **Effort:** — — **Priority:** —

### 2.2 Transaction detail with gateway payload — `exists`
- **What the user could do (legacy):** See the full payment event: summary (reference, status badge, amount, payment method, gateway reference, created, paid-at), the linked Order (link + store) and Customer (name + email), and the raw Gateway Response JSON.
- **Legacy route:** `GET /office/transactions/{transaction}` (`admin.transactions.show`)
- **Legacy controller:** `Admin\TransactionController@show`
- **Legacy views:** `resources/views/admin/transactions/show.blade.php`
- **New API:** `GET /api/v1/admin/transactions/{reference}` (bound by `reference`) → `Api\V1\Admin\TransactionController@show` — adds `gateway_reference`, `fee`, `customer`, `gateway_response`.
- **New SPA:** `src/views/TransactionsView.vue` detail drawer (`DetailDrawer`, collapsible gateway response, copy reference).
- **Key UI:** detail drawer with dl grid + collapsible raw JSON.
- **Side effects:** none on read.
- **Gaps vs legacy:** none material (drawer replaces page; all fields present).
- **Effort:** — — **Priority:** —

### 2.3 Transaction status override — `missing`
- **What the user could do (legacy):** From the transaction detail page open an "Update status" modal and set the transaction to any `TransactionStatus` value (pending/paid/confirmed/refunded/refund_pending/cancelled); validated against the enum, redirects back to the detail with a flash. Changing status affects how payments are reported on dashboards (revenue counts CONFIRMED totals).
- **Legacy route:** `PATCH /office/transactions/{transaction}/status` (`admin.transactions.update-status`)
- **Legacy controller:** `Admin\TransactionController@updateStatus`; `App\Http\Requests\Admin\UpdateTransactionStatusRequest`
- **Legacy views:** `resources/views/admin/transactions/show.blade.php` (status modal)
- **New API:** none — the admin API exposes index/show only, and the management API's confirm/reject/refund endpoints are business-scoped.
- **New SPA:** none — the transaction drawer has copy + raw payload but no action buttons.
- **Key UI:** modal with status select + save.
- **Side effects:** transaction status change (alters platform revenue/dashboard aggregates). Should log to the activity log.
- **Missing:** the mutation endpoint and the SPA action.
- **Effort:** S — **Priority:** P1

---

## 3. Subscriptions and plans (admin)

### 3.1 Subscription oversight list — `missing`
- **What the user could do (legacy):** Paginated (15/page) list of all business subscriptions. Filters: status (active/trial/expired/cancelled) and free-text `q` over business name or plan name. Columns: Business (linked to the admin business page, with business code), Plan, Status badge, Started (`starts_at`), Ends (`ends_at`). Reset link; empty state. Logs `admin_subscriptions_viewed`. No detail page, no cancel/extend/edit actions in legacy.
- **Legacy route:** `GET /office/subscriptions` (`admin.subscriptions.index`)
- **Legacy controller:** `Admin\SubscriptionController@index`
- **Legacy views:** `resources/views/admin/subscriptions/index.blade.php`
- **New API:** none (no admin subscription controller/route).
- **New SPA:** none.
- **Key UI:** status select, search input, 5-column table, pagination.
- **Side effects:** none on read.
- **Missing:** everything.
- **Effort:** S — **Priority:** P1

### 3.2 Subscription plan CRUD (Subscription Fee screen) — `missing`
- **What the user could do (legacy):** Manage the plans businesses subscribe to. List shows plan name + description excerpt, currency + amount, interval (+ count when >1), Default/Active badges, sort order, and Edit/Delete per row, with "Create Plan" button and pagination. Create/Edit modal fields: name, currency (3 chars), amount, description, interval (daily/weekly/monthly/yearly), interval_count, sort_order, is_active, is_default ("setting default un-defaults all others"), is_trial + trial_days, and features (one per line, parsed to a JSON array). Delete is guarded: plans with active subscriptions cannot be deleted (flash: "Deactivate it instead"); otherwise deleted with a logged audit line.
- **Legacy route:** `GET /office/subscription-plans` (`admin.subscription-plans.index`), `POST /office/subscription-plans` (`store`), `PUT /office/subscription-plans/{plan}` (`update`), `DELETE /office/subscription-plans/{plan}` (`destroy`)
- **Legacy controller:** `Admin\SubscriptionPlanController@index/store/update/destroy`
- **Legacy views:** `resources/views/admin/subscription_fee/index.blade.php` (create modal + per-row edit modals + delete confirm)
- **New API:** none. (Note: `Api\V1\Admin\CouponController@plans` returns a read-only `[id, name, interval]` plan list used by the coupon form — not plan management.)
- **New SPA:** none.
- **Key UI:** plans table with badges, Create Plan modal, Edit Plan modal, delete confirm, trial-days conditional field, features textarea.
- **Side effects:** plan rows affect business onboarding/checkout; default flag is exclusive; logs plan created/updated/deleted.
- **Missing:** everything. Legacy quirk to fix while porting: the modals render `is_trial`/`trial_days` but the legacy validator never accepted them (dead UI) — the new model/stack should wire them properly (see §7).
- **Effort:** M — **Priority:** P1

---

## 4. Coupons (admin)

### 4.1 Coupon list with usage tracking — `exists`
- **What the user could do (legacy):** Paginated (15/page) list, newest first. Columns: Coupon (code + internal name), Plan (or "All Plans"), Discount (`discount_label`), Usage (`uses_count / max_uses` or ∞, plus red "Exhausted" badge when `isExhausted()`), Expires (date or "Never"), Status (Active/Inactive pill), Actions (Edit, Activate/Deactivate, Delete with browser confirm). Empty state with a Create CTA.
- **Legacy route:** `GET /office/coupons` (`admin.coupons.index`)
- **Legacy controller:** `Admin\CouponController@index`
- **Legacy views:** `resources/views/admin/coupons/index.blade.php`
- **New API:** `GET /api/v1/admin/coupons` → `Api\V1\Admin\CouponController@index` — `q` (code), sortable by code/discount_value/uses_count/expires_at/is_active/created_at, `per_page`; payload adds `discount_label` and `subscription_plan_id`.
- **New SPA:** `src/views/CouponsView.vue` (route `/coupons`), `adminApi.coupons`.
- **Key UI:** search box, sortable headers, usage progress bar with % and colour thresholds, copy-code button, status pill.
- **Side effects:** none on read.
- **Gaps vs legacy:** none material — the SPA's usage bar supersedes the "Exhausted" badge.
- **Effort:** — — **Priority:** —

### 4.2 Coupon create/edit form — `exists`
- **What the user could do (legacy):** Dedicated create/edit page: internal name, coupon code (required, unique, uppercased server-side), applies-to plan (or all plans), discount type (percentage/fixed), discount value (min 0.01), max uses (blank = unlimited), expiry date (blank = never), active checkbox. Server validates unique code (ignoring self on update) and `exists:subscription_plans,id`.
- **Legacy route:** `GET /office/coupons/create`, `POST /office/coupons`, `GET /office/coupons/{coupon}/edit`, `PUT /office/coupons/{coupon}`
- **Legacy controller:** `Admin\CouponController@create/store/edit/update`, `validateData()`
- **Legacy views:** `resources/views/admin/coupons/form.blade.php`
- **New API:** `GET /api/v1/admin/coupon-plans`, `POST /api/v1/admin/coupons`, `PUT /api/v1/admin/coupons/{coupon}` → `Api\V1\Admin\CouponController`.
- **New SPA:** `CouponsView.vue` modal — adds a "Generate" random code button, discount preview chip, and +30/+90-day expiry shortcuts.
- **Key UI:** modal form with code + generate, name, plan select, segmented discount type, value, max uses, expiry with quick buttons, active toggle.
- **Side effects:** logs `coupon.created` / `coupon.updated` (new API relies on generic request logging); code uppercased on create only.
- **Gaps vs legacy:** on edit, the legacy controller allowed changing the **code** (unique-ignoring-self); the new API's `update()` validation omits `code` and the SPA disables the field, so codes are immutable after creation. Everything else is at parity or better.
- **Effort:** S (only if code editing is wanted) — **Priority:** P2

### 4.3 Coupon activate/deactivate and delete — `exists`
- **What the user could do (legacy):** One-click toggle of `is_active` with a flash naming the new state; delete with confirm (logs `coupon.deleted`).
- **Legacy route:** `POST /office/coupons/{coupon}/toggle` (`admin.coupons.toggle`), `DELETE /office/coupons/{coupon}`
- **Legacy controller:** `Admin\CouponController@toggleActive`, `@destroy`
- **Legacy views:** `resources/views/admin/coupons/index.blade.php`
- **New API:** `POST /api/v1/admin/coupons/{coupon}/toggle`, `DELETE /api/v1/admin/coupons/{coupon}` → `Api\V1\Admin\CouponController@toggle/destroy`.
- **New SPA:** `CouponsView.vue` — toggle and delete buttons + `ConfirmDialog`.
- **Side effects:** inactive coupons cannot be applied at checkout (management sub-coupon validation); delete is permanent.
- **Gaps vs legacy:** none.
- **Effort:** — — **Priority:** —

---

## 5. Platform accounting (Storify's own books)

### 5.1 Platform books dashboard — `missing`
- **What the user could do (legacy):** See the platform's own ledger at a glance: five totals — Assets, Liabilities, Revenue, Expenses, Net Profit (all posted, `business_id = null` entries, in kobo rendered as ₦); a Recent Journal Entries table (last 10: entry number link, date, memo, status badge); a Cash & Clearing panel listing cash/bank/gateway-clearing account balances; and quick links to Chart of Accounts, P&L, Balance Sheet, Settings. Calls `LedgerSetupService::ensureForBusiness(null)` to bootstrap the platform chart on first visit.
- **Legacy route:** `GET /office/accounting` (`admin.accounting.index`)
- **Legacy controller:** `Admin\AccountingController@index`
- **Legacy views:** `resources/views/admin/accounting/index.blade.php`
- **New API:** none. The management API's `/accounting/*` routes are business-scoped (`Api\V1\Management\AccountingController`) and are not reachable with an admin token; there is no platform (`business_id = null`) equivalent.
- **New SPA:** none.
- **Key UI:** 5 KPI cards, recent entries table, cash & clearing list, quick links.
- **Side effects:** idempotent ledger bootstrapping for the platform books.
- **Missing:** everything.
- **Effort:** M — **Priority:** P1

### 5.2 Platform chart of accounts — `missing`
- **What the user could do (legacy):** View the platform chart of accounts grouped by type (Assets, Liabilities, Equity, Income, Expenses) showing account count per group and, per account: code, name, subtype, active status. Read-only (accounts are seeded by the setup service).
- **Legacy route:** `GET /office/accounting/accounts` (`admin.accounting.accounts`)
- **Legacy controller:** `Admin\AccountingController@accounts`
- **Legacy views:** `resources/views/admin/accounting/accounts.blade.php`
- **New API:** none for platform; management equivalents are business-scoped.
- **New SPA:** none.
- **Key UI:** five grouped tables with badges.
- **Side effects:** ledger bootstrapping on first read.
- **Missing:** everything.
- **Effort:** S — **Priority:** P2

### 5.3 Platform journal list with search — `missing`
- **What the user could do (legacy):** Paginated (20/page) list of all platform journal entries, newest first, searchable by entry number, reference or memo. Columns: Entry (link), Date, Memo, Lines count, Status (posted/other). Empty state explains subscription payments post here automatically.
- **Legacy route:** `GET /office/accounting/journal` (`admin.accounting.journal`)
- **Legacy controller:** `Admin\AccountingController@journal`
- **Legacy views:** `resources/views/admin/accounting/journal.blade.php`
- **New API:** none for platform.
- **New SPA:** none.
- **Key UI:** search input, 5-column table, pagination.
- **Side effects:** none on read.
- **Missing:** everything.
- **Effort:** S — **Priority:** P1

### 5.4 Journal entry detail — `missing`
- **What the user could do (legacy):** Open an entry: Lines table (account code + name, description, debit, credit) with totals footer (total debits / total credits in ₦), and a Details card (date, status, reference, fiscal period, posted by). Non-platform entries 404.
- **Legacy route:** `GET /office/accounting/journal/{entry}` (`admin.accounting.journal.show`)
- **Legacy controller:** `Admin\AccountingController@journalShow`
- **Legacy views:** `resources/views/admin/accounting/journal-show.blade.php`
- **New API:** none for platform.
- **New SPA:** none.
- **Key UI:** lines table + totals, details dl.
- **Side effects:** none on read.
- **Missing:** everything.
- **Effort:** S — **Priority:** P2

### 5.5 Financial reports (Profit & Loss, Balance Sheet, Trial Balance) — `missing`
- **What the user could do (legacy):** Run three platform reports from one screen with a report selector. P&L: from/to date inputs (defaults start-of-year → today), Total Income / Total Expenses / Net Profit cards and income & expenses line tables. Balance Sheet: as-of date, Assets table with total, Liabilities table with total, Equity table with "Current Period Earnings" row and total. Trial Balance: from/to, per-account debit/credit columns with totals footer. Uses `LedgerReportService` scoped to `business_id = null`.
- **Legacy route:** `GET /office/accounting/reports` (`admin.accounting.reports`)
- **Legacy controller:** `Admin\AccountingController@reports`
- **Legacy views:** `resources/views/admin/accounting/reports.blade.php`
- **New API:** none for platform.
- **New SPA:** none.
- **Key UI:** report select (auto-submit), date filters, KPI cards, statement tables.
- **Side effects:** none on read.
- **Missing:** everything.
- **Effort:** M — **Priority:** P1

### 5.6 Accounting settings: mappings, fiscal periods and year close — `missing`
- **What the user could do (legacy):** Configure the platform books: (a) **Auto-posting mappings** — 20 select dropdowns (cash, bank, gateway clearing, AR, inventory, fixed assets, AP, VAT payable, accrued liabilities, owner equity, retained earnings, opening balance equity, subscription revenue, service charge income, shipping income, other income, sales discounts, COGS, gateway fees, misc expense) each mapped to an account; save via `PUT mappings`. (b) **Fiscal years** list with open/closed badge and "Close Year" (transfers net result to retained earnings, locks periods). (c) **Fiscal periods** list (24 most recent) with open/closed badge and Close/Reopen actions; closed periods reject new entries.
- **Legacy route:** `GET /office/accounting/settings` (`admin.accounting.settings`), `PUT /office/accounting/settings/mappings`, `POST /office/accounting/settings/periods/{period}/close`, `POST /office/accounting/settings/periods/{period}/reopen`, `POST /office/accounting/settings/years/{year}/close`
- **Legacy controller:** `Admin\AccountingController@settings/updateMappings/closePeriod/reopenPeriod/closeYear` (+ `LedgerClosingService`)
- **Legacy views:** `resources/views/admin/accounting/settings.blade.php`
- **New API:** none for platform.
- **New SPA:** none.
- **Key UI:** mappings form with account selects, fiscal years cards, fiscal periods table, confirm dialogs.
- **Side effects:** mapping changes redirect future auto-postings; closing a year posts a retained-earnings journal entry and locks periods; reopen un-locks.
- **Missing:** everything.
- **Effort:** M — **Priority:** P1

---

## 6. VAT (admin)

### 6.1 VAT rates management with single-active rule — `missing`
- **What the user could do (legacy):** List all VAT records (Id, Percentage, Active badge, Effective At, Created-ago) with pagination. Create modal (percentage 0–100, optional effective_at, active checkbox); **only one VAT can be active — any new record deactivates all others and becomes active**, and effective_at defaults to now. Edit modal (percentage, effective_at, active); marking a record active deactivates the rest. Delete with confirm. All actions write Laravel log entries (`vat_created/updated/deleted`).
- **Legacy route:** `GET /office/vats` (`admin.vats.index`), `POST /office/vats`, `GET /office/vats/{vat}/edit`, `PUT /office/vats/{vat}`, `DELETE /office/vats/{vat}`
- **Legacy controller:** `Admin\VatController@index/store/edit/update/destroy`; `App\Http\Requests\Admin\VatRequest`
- **Legacy views:** `resources/views/admin/VAT/index.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Key UI:** VAT table, create modal, edit modal, delete modal.
- **Side effects:** the active VAT record is what POS prices use (`Vat::active()` in `Api\V1\Pos\ProductController`), so this changes tax charged on new sales.
- **Missing:** everything.
- **Effort:** M — **Priority:** P1

### 6.2 Disable VAT by creating a 0% superseding record — `missing`
- **What the user could do (legacy):** Click the "ban" action on the active VAT to effectively disable VAT: the system creates a new **0% VAT record** marked active (VAT is never fully disabled — business rule), deactivating the previous rate, and flashes "0% VAT created". Logged as `vat_zero_created`.
- **Legacy route:** `POST /office/vats/{vat}/toggle` (`admin.vats.toggle`)
- **Legacy controller:** `Admin\VatController@toggle`
- **Legacy views:** `resources/views/admin/VAT/index.blade.php` (Disable VAT modal)
- **New API:** none.
- **New SPA:** none.
- **Key UI:** confirmation modal explaining the 0% record behaviour.
- **Side effects:** new VAT row; previous rate deactivated; all future POS/storefront tax becomes 0%.
- **Missing:** everything.
- **Effort:** S — **Priority:** P2

---

## 7. Payment methods (admin)

### 7.1 Payment method list with enable/disable toggle — `missing`
- **What the user could do (legacy):** List platform payment methods (#, Name, Code, Description, Active badge) and enable/disable each via a confirm modal that states the action ("Enable X?" / "Disable X?"). Only enabled methods appear at checkout (storefront) and in management payment settings. Success flash "X enabled/disabled successfully."
- **Legacy route:** `GET /office/payment-methods` (`admin.payment-methods.index`), `POST /office/payment-methods/{paymentMethod}/toggle`
- **Legacy controller:** `Admin\PaymentMethodController@index/toggle`
- **Legacy views:** `resources/views/admin/payment_methods/index.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Key UI:** 6-column table, toggle confirm modal.
- **Side effects:** immediately affects which payment methods customers can select at checkout (`PaymentMethod::active()` in storefront checkout).
- **Missing:** everything.
- **Effort:** S — **Priority:** P1

---

## 8. Bank accounts (admin)

### 8.1 Platform bank account list — `missing`
- **What the user could do (legacy):** Paginated (15/page, ordered by sort_order) list of the platform's receiving bank accounts: logo thumbnail (or "No logo"), Bank Name, Account Name (`N/A` fallback), Account Number (code chip), Sort Order, Status badge, and actions Activate/Deactivate + Edit + Delete (confirm modal). Empty state invites adding one.
- **Legacy route:** `GET /office/bank-accounts` (`admin.bank-accounts.index`), `DELETE /office/bank-accounts/{bankAccount}`, `POST /office/bank-accounts/{bankAccount}/toggle-active`
- **Legacy controller:** `Admin\BankAccountController@index/destroy/toggleActive`
- **Legacy views:** `resources/views/admin/bank-accounts/index.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Key UI:** 7-column table, status pill, action buttons, confirm modal.
- **Side effects:** active bank accounts are surfaced (e.g. manual-transfer instructions / management payment settings); deleting removes the stored logo from the public disk.
- **Missing:** everything.
- **Effort:** S — **Priority:** P1

### 8.2 Bank account create/edit form with logo upload — `missing`
- **What the user could do (legacy):** Create form and edit form (same fields): bank_name (required), account_number (required), account_name (optional, e.g. "Zimoziswift Limited"), sort_order (lower first), is_active select (Active/Inactive), and a bank logo upload (jpeg/png/jpg/gif, max 2 MB). Edit shows the current logo and replaces it, deleting the old file from storage. Validation errors rendered inline.
- **Legacy route:** `GET /office/bank-accounts/create`, `POST /office/bank-accounts`, `GET /office/bank-accounts/{bankAccount}/edit`, `PUT /office/bank-accounts/{bankAccount}`
- **Legacy controller:** `Admin\BankAccountController@create/store/edit/update` (public disk `bank-logos/`)
- **Legacy views:** `resources/views/admin/bank-accounts/create.blade.php`, `edit.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Key UI:** two-column form: bank name, account number, account name, sort order + status, logo file input with current-logo preview.
- **Side effects:** file upload/replacement/delete on the public disk; account rows are consumed by checkout instructions.
- **Missing:** everything.
- **Effort:** M — **Priority:** P1

---

## Gaps worth calling out

1. **Admin order oversight is 100% absent.** The admin SPA can list transactions and coupons but cannot list, open, edit, transition, pay-adjust or delete a single order, and has no Shop4Me queue. This is the most-used screen in the legacy admin panel (it even drives a pending-orders badge in the sidebar). It is the first thing to build: orders list (1.1) → order detail (1.2) → status update with customer email (1.4).

2. **The transaction screen is read-only in the new stack.** The legacy admin could override a transaction's status (which changes reported revenue because all revenue figures count CONFIRMED transactions). The new API has no mutation and the SPA drawer has no action. Small effort, but it is the only admin lever on money already taken.

3. **Platform accounting is a whole missing module (6 features).** The management API has a rich business-scoped accounting controller, so the services (`LedgerReportService`, `LedgerSetupService`, `LedgerClosingService`) and models already exist — the admin port mainly needs platform-scoped controllers, routes and six screens. Until then, nobody can see Storify's own books, subscription revenue postings, or close a fiscal period.

4. **Money-configuration screens (VAT, payment methods, bank accounts, subscription plans) are all missing.** These are small CRUD screens but each one controls live checkout behaviour: the active VAT rate is read by POS pricing, active payment methods gate storefront checkout, bank accounts feed payment instructions, and plans drive business onboarding/checkout. The permissions (`admin.finance`, `admin.subscriptions`) are already seeded and unused.

5. **Legacy data caveats to fix rather than port.** (a) `Order` no longer has a `payment_status` column/accessor, so the legacy admin order list's payment badge effectively reports "Failed" for everything and the Shop4Me list reports "Unpaid"; the working semantics live in the transaction-status mapping used by the filters/stats — the new screens must derive payment status from transactions. (b) The admin order edit form's customer/delivery fields and its `payment_status` select are silently discarded (not in `UpdateOrderRequest` / not fillable) — don't rebuild those inputs. (c) The admin order index passes unvalidated `sort_by`/`sort_order` straight into `orderBy` — whitelist sortable columns in the new API. (d) The subscription-plan modals render `is_trial`/`trial_days` but the legacy validator drops them — the new stack should validate and persist them (the model supports them). (e) The order detail view contains a dead "bulk order finalized" modal referencing a `bulk_finalized` session value nothing sets — ignore it.

6. **What is already done well.** Coupons are at full parity (list, create/edit modal, toggle, delete) with useful extras (code generator, usage bar, quick expiry); transactions have parity on read plus extra filters. Only the transaction status override and coupon-code editing differ from legacy, and both are small.

7. **Cross-cutting conventions for the rebuild.** Every legacy admin mutation writes an `ActivityLog` row and the order status flow sends a customer email; the new API's `ApiController` helpers, `team.context` middleware and Spatie permissions are already in place — new endpoints should reuse the `admin.orders` / `admin.transactions` / `admin.subscriptions` / `admin.accounting` / `admin.finance` permissions, keep monetary mutations atomic, and surface admin-facing enumerations with whitelisted filters/sorts.
