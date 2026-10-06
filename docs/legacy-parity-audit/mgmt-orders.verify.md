# Verification pass — mgmt-orders (audience: business)

**Verifier method:** re-read every in-scope legacy route (`routes/v1/management.php` lines 177–277, 506), all three legacy controllers in full (`Management\OrderController`, `InvoiceController`, `DispatchesController`), all eight in-scope Blade views (`orders/{index,show,edit}`, `invoices/{index,create,show,pdf}`, `dispatches/index`), the supporting `Order`/`Invoice`/`OrderItem` models, `OrderStatus`/`InvoiceStatus` enums, `SpatiePermissionSeeder` + `SyncPermissions`, the new management API (`routes/api/v1/management.php`, `Api\V1\Management\{Order,Transaction,Staff}Controller`, `ResolvesManagementContext`), the POS invoice routes (`routes/api/v1/pos.php`, `Api\V1\Pos\InvoiceController`), and the SPA (`router/index.ts`, `endpoints.ts`, `OrdersView.vue`, `OrderDetailView.vue`, `TransactionsView.vue`, `AppLayout.vue`).

**Coverage verdict:** the audit is substantively complete. Every in-scope view file, controller method and management route in the domain maps to a feature entry; no whole feature area is missing from it, and every "missing" status checked (guarded transitions, order edit, invoices, dispatches, activity, delivery tracking, metric cards) is accurate in the new stack. What it did miss is a shared-shell affordance (below), plus several specific claims that are wrong or under-specified.

---

## Missed feature

### A. Sidebar backlog badges (pending orders, open dispatches) — `missing`

- **What the user could do (legacy):** While navigating anywhere in management, see a live **amber count of `pending` orders** on the sidebar Orders item and a **blue count of open dispatches** (`OrderDelivery.status NOT IN (delivered, failed, returned)`) on the Dispatches item. Counts are business-wide for owners (`whereIn('store_id', accessibleStoreIds)` / `where business_id`), scoped to assigned stores for restricted staff, cached 15 s per user (`Cache::remember("sidebar.counts.{user}")`), and hidden when zero.
- **Legacy route:** n/a (shell state; nav links to `GET /management/orders` and `GET /management/dispatches`).
- **Legacy controller:** `App\Providers\AppServiceProvider` View composer for `management.components.header` / `management.components.sidebar` (lines ~321–440).
- **Legacy views:** `resources/views/management/components/sidebar.blade.php` (Orders badge lines 146–155, Dispatches badge lines 158–168).
- **Key UI:** two nav count pills; also the same composer feeds pending-transaction, customers and staff badges (other domains).
- **Side effects:** none (read-only counts).
- **New API:** none — no stats/count endpoint anywhere in `routes/api/v1/management.php`.
- **New SPA:** none — `AppLayout.vue` already supports a `badge` field on nav items (only `Stores` uses it), the `Orders` item has no badge, and there is no Dispatches nav item at all.
- **Effort:** S — **Priority:** P2

*(Not counted as a miss but worth knowing: the store-detail "Sales"/"Invoices" tabs — `GET /management/stores/{store}/tab/orders|invoices`, `StoreTabController@orders|invoices`, `stores/tabs/{orders,invoices}.blade.php` — are a second per-store order/invoice surface the mgmt-orders §1.2 does not describe, but they are already covered by `mgmt-stores.md` §2.4 and §2.7. No duplicate work.)*

---

## Corrections

### 1. §1.4 / gap #10 — `product_code` **is** in the new order payload

The audit's missing-list and gap #10 say the new order `show` response omits "product images/codes". `Api\V1\Management\OrderController::payload()` (detailed branch) returns per item: `product_name`, **`product_code`**, `unit_price`, `quantity`, `subtotal`, `is_digital`. Product images are genuinely absent; product code is not — it is the SPA (`OrderDetailView.vue`, items table renders only name/digital/qty/price/subtotal) that fails to render it. Correct the claim so nobody re-adds an existing field.

### 2. §1.7 Bank-account panel — the bank data **and** UI already exist in the transactions module

Audit: "New API: transactions payload has no `storeBank` … New SPA: none (TransactionsView shows order/invoice links but no bank account)." Both claims are wrong as stated:

- `GET /api/v1/management/transactions/{transaction}` (`Api\V1\Management\TransactionController@payload`, detailed branch) returns `bank => { bank_name, account_number, account_name }` sourced from `Transaction::storeBank`.
- `TransactionsView.vue` (lines 176–179) renders that block in the transaction detail drawer ("Bank · bank_name · account_number / account_name").

What is genuinely missing is bank proof-of-payment **on the order screen**: the order `show` payload's embedded transactions have no bank, and `OrderDetailView.vue` has no Bank panel. Re-scope the gap accordingly (data + UI exist at transaction level; only the order-context panel is absent).

### 3. §2.7 Return order — stock restoration is **not** limited to non-digital lines

Audit: "stock is added back … for every non-digital line". `OrderController@returnOrder` (lines 397–416) loops `$order->items`, skips only when `! $item->product_id`, and calls `StockLedgerService::recordAddition()` for any line whose product has a `StockLocation` at the order's store. There is **no `is_digital` check** anywhere in the loop (confirmed `StockLedgerService::recordAddition` doesn't filter digital either). If the port adds a digital filter "for parity", it introduces behaviour legacy did not have.

### 4. §2.3 Dispatch — there is no ETA (or tracking) input in the modal; legacy ETA is always null

Audit: "creates the `OrderDelivery` record (status `assigned`, route, driver, notes, **ETA**, created_by)" and flags only `tracking_number` as the dead validation. In `orders/show.blade.php` the dispatch form posts exactly `delivery_agent_id`, `driver_name`, `driver_phone`, `delivery_notes` — there is **no `estimated_delivery_at` field and no `tracking_number` field** (both appear only as read-only rows in the Delivery Tracking panel). So both columns are always null through the UI. The controller's `estimated_delivery_at`/`tracking_number` validations are unreachable extras, not just the tracking one.

### 5. §4.5 Send invoice — missing side effect: ledger posting

`InvoiceController@send` (the route behind both "Send Invoice" and "Remind") calls `LedgerPostingService::postInvoice($invoice, $user->id)` after `sendInvoice()`. Note the asymmetry the port must preserve: `store()`/`update()` with `finalize` call `sendInvoice()` directly and do **not** post the ledger. The audit lists no ledger side effect for 4.5 (it lists them for 4.6/4.7), so this should be added to the feature entry.

### 6. §4.1 Invoice stats row — no "Paid count" card

Audit: "stats row (All / Draft / Sent / Overdue / **Paid counts** + paid revenue)". `invoices/index.blade.php` renders five cards: **All, Draft, Sent, Overdue, Revenue** — the last is `SUM(total) WHERE status = 'paid'`. There is no Paid count card; the description should be "All / Draft / Sent / Overdue counts + paid-invoice revenue".

---

## Smaller notes (no audit text change demanded)

- **Gap #5 route count:** legacy has **12** invoice routes (index, create, store, show, pdf, edit, update, send, mark-paid, void, record-payment, destroy), not 11. Cosmetic.
- **Gap #8 "no order print view":** true within the orders module, but legacy does ship a printable order receipt — `GET /management/pos/{store}/receipt/{order}` → `management.pos.receipt`, view `management/pos/receipt.blade.php` — it is POS-scoped and already covered by `mgmt-pos.md` §5.2. Don't rebuild; just be aware when planning order deep-links.
- **Overdue is never assigned by any legacy code path.** `InvoiceStatus::OVERDUE` exists, but grep across `app/`, `routes/`, `database/` finds no scheduler, observer or controller that ever sets it (`overdue` is only read by the index views and `LedgerReportService`). The Overdue tab/card and the red due-date styling are therefore always inert in practice — the port should not build an overdue-transition engine for parity.
- **Executive summary row "Order status transitions & actions | 9 | 0 | 1 | 8"** is internally inconsistent with the section it summarises (2.1–2.11 = 11 entries, incl. 2.8 `exists` and 2.9 `partial`). Cosmetic.
- **Re-confirmed as correct:** `orders delete` is absent from both `SpatiePermissionSeeder` and `SyncPermissions` (gap #1 stands); no `OrderDelivery` code anywhere in `routes/api/**` or `Api/V1/**`; the new refund endpoint's "Mark the order as returned first" dead-end (gap #3) is real; the free-form `PUT /orders/{order}/status` is genuinely the only ported transition (2.8); POS invoice API covers only index/show/store/send/record-payment as claimed.
- **Confirmed present in legacy but out of this scope's view list:** store Sales/Invoices tabs (`mgmt-stores.md` §2.4/§2.7), POS receipt (`mgmt-pos.md` §5.2). Nothing else in `routes/v1/management.php` touches orders/invoices/dispatches.
