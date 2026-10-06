# mgmt-pos — Legacy → New Stack Feature Inventory

**Domain:** POS oversight from the business dashboard (audience: business)
**Legacy source of truth:** `storify-api` @ `routes/v1/management.php` + `app/Http/Controllers/Management/{Pos,PosSale,PosSession}Controller.php` (+ `StoreSettingsController::enablePos`) + `resources/views/management/pos/**`, plus the POS blocks woven into `resources/views/management/stores/{show,settings}.blade.php`, `stores/tabs/settings.blade.php`, `components/sidebar.blade.php` and `dashboard.blade.php`.
**New stack:** `storify-api` (`routes/api/v1/management.php`, `app/Http/Controllers/Api/V1/Management/**`; separate POS-only API at `routes/api/v1/pos.php` + `App\Http\Controllers\Api\V1\Pos/**`) and `storify-management` (`src/router/index.ts`, `src/views/**`, `src/api/endpoints.ts`).
**Note:** the standalone Electron POS app (served by the audience-scoped POS API) already exists and is the intended home of the cashier terminal. This audit therefore judges the business dashboard against what the dashboard itself must show: session oversight, cash-drawer reconciliation, POS sales visibility and POS configuration.

**Status vocabulary:** `exists` = fully usable in the new stack; `partial` = endpoint or screen present but missing actions/fields/validations from legacy; `missing` = absent from the new stack.

---

## Executive summary

The **entire POS domain is absent from the new business SPA**. `storify-management` has no POS route, no POS view, no POS call in `endpoints.ts` and no POS entry in the sidebar. The management API (`routes/api/v1/management.php`) has no POS endpoints either — the only POS surface in the new stack is the **POS-audience** API under `/api/v1/pos/**`, which serves the Electron cashier app, is gated by `token.audience:pos` (a management token is rejected) and is **self-scoped to the signed-in cashier** (`staff_id = $user->id`). It therefore cannot back owner-level oversight of all cashiers' sessions without new management endpoints or shared service methods.

What does exist on the new side: the `pos_enabled` flag is returned read-only in `auth/me` and `GET /management/stores/{store}` (and is unused in the SPA), staff→store assignment (cashier assignment) and the full `pos` permission set (`open_session`, `process_sale`, `close_session`, `view_history`, `void_sale`) are seeded and editable via StaffView/RolesView.

| Area | Features | exists | partial | missing |
|---|---|---|---|---|
| POS session oversight | 4 | 0 | 0 | 4 |
| Session lifecycle & cash drawer | 3 | 0 | 0 | 3 |
| POS configuration & navigation | 3 | 0 | 0 | 3 |
| POS sales visibility | 1 | 0 | 1 (orders SPA) | 0 |
| In-dashboard terminal (legacy fallback) | 2 | 0 | 0 | 2 |
| People & permissions | 1 | 1 | 0 | 0 |

**Headline numbers:** 0 of 10 oversight/configuration features have any management API or SPA implementation; 0 of 2 legacy in-dashboard terminal features exist (intentionally superseded by the Electron app, but no deep-link/launcher exists in the SPA either); 1 of 1 cashier-assignment features exists.

---

## 1. POS session oversight

### 1.1 POS sessions list across stores with filters and KPIs — `missing`
- **What the user could do (legacy):** Open a paginated (20/page) list of every POS session in the business across all accessible stores. Three KPI cards: **Open Sessions** count, **Today's POS Sales** (sum of `orders.total` for POS orders created today), **POS Stores** (accessible stores with `pos_enabled`). Status tabs **All / Open / Closed** and a **Store** dropdown filter (both preserved in the querystring; pagination keeps filters). Each row: store name, status pill (open/closed), cashier name, relative opened time, orders count, and for open sessions a **Terminal** button that deep-links to the standalone POS app (`config('pos.link')`, i.e. `POS_LINK=https://pos.storify.ng`). Rows are permission-gated by `pos view_history` and restricted staff only see assigned stores.
- **Legacy route:** `GET /management/pos` (`management.pos.index`)
- **Legacy controller:** `Management\PosController@index`
- **Legacy views:** `resources/views/management/pos/index.blade.php`
- **New API:** none. (`GET /api/v1/pos/stores/{store}/session` returns only the caller's own open session for the POS app.)
- **New SPA:** none.
- **Missing:** the whole screen: list endpoint with `store_id` + `status` filters and pagination, the three KPI metrics, the tab/filter UI, the row data (`store`, `staff`, `orders_count`, `opened_at`), the external terminal deep-link, and the `pos view_history` / restricted-staff scoping rules.
- **Side effects:** none (read-only; "today's sales" is computed from orders with `pos_session_id`).
- **Effort:** M — **Priority:** P0

### 1.2 Global POS session detail (sales records, financials, staff activity) — `missing`
- **What the user could do (legacy):** Open a single session (closed or open) and see: four KPI cards — **Opening Float**, **Total Sales**, **Orders** count and **Difference** (or, while open, **Expected Close** = opening float + sales); a **Sales Records** table (order number, item count, total, payment status Paid/Pending from the first transaction, time); a **Session Info** panel (cashier, opened at, closed at, duration, actual close amount, notes); a **Staff Activity** panel listing that cashier's last 20 sessions across the business with links; and an **Open Terminal** button while the session is live. Access is scoped to accessible stores.
- **Legacy route:** `GET /management/pos/{session}` (`management.pos.show`, session bound by `session_code`)
- **Legacy controller:** `Management\PosController@show`
- **Legacy views:** `resources/views/management/pos/show.blade.php`
- **New API:** none in the management API. The POS API has no session-detail-of-another-cashier endpoint at all.
- **New SPA:** none.
- **Missing:** the entire detail screen, plus an API that returns a session with its orders/transactions, computed totals, `difference`, and the cashier's recent-session history — none of that exists management-side.
- **Side effects:** none.
- **Effort:** M — **Priority:** P1

### 1.3 Per-store POS session history (cash-register log) — `missing`
- **What the user could do (legacy):** Open a store's POS session history as a table with columns: **Session** code (link to detail), **Staff**, **Opened**, **Opening** float, **Expected** close, **Actual** close, **Difference** (green `+` over / red short) and **Status** badge; paginated 20/page; empty state telling the user to open a session from the terminal.
- **Legacy route:** `GET /management/pos/{store}/sessions` (`management.pos.sessions.index`)
- **Legacy controller:** `Management\PosSessionController@index`
- **Legacy views:** `resources/views/management/pos/sessions/index.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the whole table, its expected/actual/difference reconciliation columns, pagination and empty state; plus a store-scoped sessions endpoint. (Legacy ownership check: `store->user_id === user->id` only — the new stack should honour `accessibleStores()` like the rest of the management API.)
- **Side effects:** none.
- **Effort:** M (can share a component/endpoint with 1.1) — **Priority:** P1

### 1.4 Per-store POS session detail with reconciliation summary — `missing`
- **What the user could do (legacy):** Open one session for a specific store and see: a facts list (**staff, opened, closed or "still open", opening float, expected close, actual close, difference with over/short wording, notes**), a **Sales Summary** card (total orders, total sales) and the **Orders in this Session** table (order #, items count, total, payment method from `order.meta.payment_method`, time).
- **Legacy route:** `GET /management/pos/{store}/sessions/{session}` (`management.pos.sessions.show`)
- **Legacy controller:** `Management\PosSessionController@show`
- **Legacy views:** `resources/views/management/pos/sessions/show.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the whole screen. The reference semantics to reimplement: expected close = opening float + confirmed cash legs (`PosSession::close()` / `calculateCashSalesTotal()`), difference = actual − expected, shown as over/short.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

---

## 2. Session lifecycle & cash drawer

### 2.1 Open a POS session with an opening float — `missing`
- **What the user could do (legacy):** From the store's page (or store settings) open a cash-register session by entering an **opening cash float in kobo** (integer, ≥ 0). Validation: POS must be enabled for the store; a cashier cannot have two open sessions for the same store. Success/error flash messages; the store page then shows the live session (opened by, since, float). The route is permission-gated by `pos open_session`.
- **Legacy route:** `POST /management/stores/{store}/pos/open` (`management.pos.open`)
- **Legacy controller:** `Management\PosSessionController@open`
- **Legacy views:** `resources/views/management/stores/show.blade.php` ("POS Terminal" card), `resources/views/management/stores/settings.blade.php`, `resources/views/management/stores/tabs/settings.blade.php`
- **New API:** none in the management API. `POST /api/v1/pos/stores/{store}/session/open` exists but requires a `pos`-audience token and the `pos open_session` permission, and opens a session for the calling cashier only — the dashboard cannot use it.
- **New SPA:** none.
- **Missing:** a management endpoint wrapping the same behaviour (pos_enabled check, duplicate-open guard, kobo validation, session created with `session_code`) and the open-session form/state on a store screen.
- **Side effects:** creates a `pos_sessions` row in `open` state (auto `session_code` on create).
- **Effort:** S — **Priority:** P0

### 2.2 Close a POS session with cash-count reconciliation (cash drawer count) — `missing`
- **What the user could do (legacy):** From the store page, close the store's currently open session by entering the **cash counted (kobo)**; the controller validates `closing_balance_actual` (required integer ≥ 0) and optional `notes`, then `PosSession::close()` computes **expected = opening float + confirmed cash-sale legs**, **actual = counted**, **difference = actual − expected**, stamps `closed_at`, flips status to `closed` and stores the notes. The flash message reports the difference ("POS session closed. Difference: ₦x"). Notable legacy behaviour: close targets the **latest open session for the store** (so an owner can close a session a cashier left open) rather than the caller's own session; the staff terminal equivalent (`resources/views/staff/pos/index.blade.php`) shows Opening/Expected in a confirmation modal before submitting, with a notes textarea.
- **Legacy route:** `POST /management/stores/{store}/pos/close` (`management.pos.close`)
- **Legacy controller:** `Management\PosSessionController@close`
- **Legacy views:** `resources/views/management/stores/show.blade.php` (cash counted input + Close Session button); staff-side modal in `resources/views/staff/pos/index.blade.php`
- **New API:** `POST /api/v1/pos/stores/{store}/session/close` exists, but audience-scoped to `pos` and self-scoped (`staff_id = $user->id`) — it returns the full reconciliation payload (`closing_balance_expected`, `actual`, `difference`). Not callable by the management SPA, and no owner-closes-any-session variant.
- **New SPA:** none.
- **Missing:** management endpoint (incl. the "close the store's latest open session" behaviour and per-store permissions/scoping), the close form on a session/store screen, the pre-close Expected display, and surfacing expected/actual/difference after closing. This is the cash-drawer-count feature the business runs daily.
- **Side effects:** writes `closing_balance_expected`, `closing_balance_actual`, `difference`, `closed_at`, `status=closed`, `notes` on the session.
- **Effort:** M — **Priority:** P0

### 2.3 Store POS control card (live session state + actions) — `missing`
- **What the user could do (legacy):** On the store page, a "POS Terminal" card showed: POS Enabled/Disabled status; if enabled and a session is open — a pulsing "Session Open" indicator, **opened by**, **since**, **float**, and the inline close form; if enabled and no session — the open-session form; if disabled — an **Enable POS** button. Store settings repeated a simpler version (Enable / Open Session with float) plus a **POS Session History** link. The tabs settings variant showed Enabled badge + **Open Terminal** deep-link, or Enable POS.
- **Legacy route:** views on `GET /management/stores/{store}` (`management.stores.show`, backed by `StoreDashboardController@show` via `StoreAnalyticsService` `activePosSession`) and `GET /management/stores/{store}/settings`
- **Legacy controller:** `Management\StoreDashboardController@show` + `StoreAnalyticsService`, `Management\StoreSettingsController@settings`
- **Legacy views:** `resources/views/management/stores/show.blade.php`, `stores/settings.blade.php`, `stores/tabs/settings.blade.php`
- **New API:** `GET /api/v1/management/stores/{store}` returns `pos_enabled` only; no active-session data.
- **New SPA:** none — there is no store detail view in the SPA at all (`storesApi.show` exists in `endpoints.ts` but nothing calls it).
- **Missing:** store detail screen (which is itself a separate domain gap) and the POS card: `pos_enabled` state, active session summary, open/close/enable actions, terminal deep-link, session-history link.
- **Side effects:** none of its own (delegates to 2.1/2.2/3.1).
- **Effort:** S (card) — **Priority:** P1

---

## 3. POS configuration & navigation

### 3.1 Enable POS for a store — `missing`
- **What the user could do (legacy):** Flip a store's POS terminal on from the store page / store settings (`POST /management/stores/{store}/pos/enable`, sets `pos_enabled = true`). No disable action existed in the management UI. This is the gate every other POS screen checks (`$store->pos_enabled` → "POS is not enabled for this store.").
- **Legacy route:** `POST /management/stores/{store}/pos/enable` (`management.pos.enable`)
- **Legacy controller:** `Management\StoreSettingsController@enablePos` (outside the three named POS controllers but part of the POS workflow)
- **Legacy views:** `stores/show.blade.php`, `stores/settings.blade.php`, `stores/tabs/settings.blade.php`
- **New API:** none — `pos_enabled` is returned read-only by `StoreController@show` and `auth/me`; no enable/disable endpoint exists.
- **New SPA:** none — `StoresView.vue` never renders `pos_enabled`, and there is no store detail screen.
- **Missing:** management endpoint + permission gate, and any UI affordance showing/altering POS-enabled state.
- **Side effects:** updates `stores.pos_enabled`.
- **Effort:** S — **Priority:** P1

### 3.2 POS sidebar group with live session counts and terminal links — `missing`
- **What the user could do (legacy):** The dashboard sidebar had a collapsible **POS** group (visible with `pos view_history`) with an **open-session count badge**, a **"Create New POS"** entry linking to the POS sessions list, and one row per POS-enabled store showing its **active session count**, each deep-linking to the standalone terminal in a new tab. Data came from a view composer (`AppServiceProvider`): assigned stores with `pos_enabled`, with an active-session count per store.
- **Legacy route:** n/a (layout composer for all `management.*` pages)
- **Legacy controller/service:** `AppServiceProvider` view composer (`$sidebarPosStores`, `$sidebarPosOpenCount`)
- **Legacy views:** `resources/views/management/components/sidebar.blade.php`
- **New API:** none (no endpoint exposes per-store active session counts).
- **New SPA:** none — `AppLayout.vue` has no POS entry.
- **Missing:** the whole navigation surface: POS group, count badge, per-store active-session counts, terminal deep-links. Without this, the oversight screens (1.1–1.4) would have no entry point.
- **Side effects:** none.
- **Effort:** S — **Priority:** P0

### 3.3 Business dashboard "Open POS" KPI card — `missing`
- **What the user could do (legacy):** The main dashboard showed an **Open POS** metric card: number of open POS sessions across accessible stores, with subtitle "N stores enabled" (`pos_enabled` store count). Permission-gated by `pos view_history`.
- **Legacy route:** `GET /management/dashboard` (`management.dashboard`)
- **Legacy controller:** `Management\DashboardController@index` (`open_pos_sessions`, `active_pos_stores` stats)
- **Legacy views:** `resources/views/management/dashboard.blade.php`
- **New API:** `GET /api/v1/management/dashboard` exists but its `stats` block has no POS fields.
- **New SPA:** `DashboardView.vue` has no POS card.
- **Missing:** the two stats in the dashboard payload and the metric card in the SPA.
- **Side effects:** none.
- **Effort:** S — **Priority:** P2

---

## 4. POS sales visibility & reporting

### 4.1 POS sales in the Orders list (`source=pos` filter and POS badge) — `partial`
- **What the user could do (legacy):** The business Orders list let the owner filter by **Source = POS** (vs "Online Store"/checkout) and flagged POS rows with a purple **POS** badge next to the order number, so POS sales could be isolated and reconciled against sessions. (Session-scoped sales views are covered in 1.2/1.4.)
- **Legacy route:** `GET /management/orders` (`management.orders.index`)
- **Legacy controller:** `Management\OrderController@index` (`source` filter)
- **Legacy views:** `resources/views/management/orders/index.blade.php`
- **New API:** `GET /api/v1/management/orders` returns `source` per order but supports **no `source` filter parameter** (`store_id`, `status`, `from`, `to`, `q` only). The POS order payload also carries `pos_session_id` nowhere.
- **New SPA:** `OrdersView.vue` renders neither the source filter nor a POS badge (see the orders audit, §1.1, for the full list of order-list gaps).
- **Missing:** `source` filter on the management orders endpoint, and the filter control + POS badge in the SPA. POS orders are currently indistinguishable from online orders.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

---

## 5. In-dashboard terminal (legacy fallback — superseded by the Electron POS app)

### 5.1 In-dashboard POS terminal & checkout — `missing` (legacy fallback; note obsolescence judgement)
- **What the user could do (legacy):** A business owner could run sales straight from the dashboard: a web terminal per store with a grid of the 30 most recent active in-stock products, live client-side product search, cart with +/− quantity, clear cart, then a checkout modal for **cash (amount tendered + change calculation), Paystack (inline popup with a saved public key) and bank transfer (verified bank accounts shown)**, optional customer name/phone, notes; submitting created a completed `source=pos` order tied to the caller's open session and redirected to a receipt. `Management\PosSaleController` (staff routes) mirrors this with richer customer capture (email/address/city/state), receipt email (`PosReceiptMail`) and refund requests; `Management\PosController@checkout` is the dashboard variant.
- **Legacy route:** `GET /management/pos/{store}/terminal` (`management.pos.terminal`), `POST /management/pos/{store}/checkout` (`management.pos.checkout`)
- **Legacy controller:** `Management\PosController@terminal`, `@checkout`; `Management\PosSaleController@checkout` (staff routes `/staff/pos/{store}/checkout`)
- **Legacy views:** `resources/views/management/pos/terminal.blade.php`, `_terminal-body.blade.php`; staff equivalent `resources/views/staff/pos/index.blade.php`
- **New API:** the **POS app** API fully covers checkout (`POST /api/v1/pos/stores/{store}/checkout` → `Api\V1\Pos\CheckoutController`), product search, banks and service charges, with `pos` audience; the **management** API has nothing.
- **New SPA:** none.
- **Judgement:** the session list already deep-links live sessions to `https://pos.storify.ng`; the Electron POS app is the intended sale surface, so rebuilding the terminal inside `storify-management` is likely **obsolete**. The business-dashboard requirement that remains is the launcher/deep-link to the POS app (covered by 1.1/3.2), not a duplicate terminal.
- **Missing (if not treated as obsolete):** terminal UI, cart, checkout modal, payment handling, inventory decrement + stock-ledger writes, transaction creation, store credit balance update, customer creation, Paystack inline integration, receipt redirect. Side effects of a sale: order (`source=pos`, `pos_session_id`), order items, confirmed transaction, stock decrement + `StockLedgerService` removal entries, `store.creditBalance()` increment, optional customer `firstOrCreate`, optional receipt email.
- **Effort:** L (if ever rebuilt) — **Priority:** P2

### 5.2 POS receipt view / print — `missing` (legacy fallback; superseded by the Electron app)
- **What the user could do (legacy):** After checkout the browser landed on a printable receipt: store name, receipt number, date, item table (name/qty/price/subtotal), total, payment method, transaction reference and status (Paid/Pending), customer name/phone, an "Awaiting Bank Transfer" notice for transfer sales and a "New Sale" button. Staff receipts were also reachable at `/staff/pos/{store}/receipt/{order}`.
- **Legacy route:** `GET /management/pos/{store}/receipt/{order}` (`management.pos.receipt`); staff `GET /pos/{store}/receipt/{order}`
- **Legacy controller:** `Management\PosController@receipt`; `Management\PosSaleController@receipt`
- **Legacy views:** `resources/views/management/pos/receipt.blade.php`; `resources/views/staff/pos/receipt.blade.php`
- **New API:** `GET /api/v1/pos/stores/{store}/orders/{orderId}/receipt` returns the full receipt payload (items, payments, change, service charge) for the Electron app; no management endpoint.
- **New SPA:** none.
- **Missing:** nothing in the business dashboard if the Electron app prints receipts; otherwise a receipt render/print view. Transaction references remain visible in the dashboard's Transactions module.
- **Side effects:** none (read-only).
- **Effort:** S (read-only render) — **Priority:** P2

---

## 6. People & permissions

### 6.1 Cashier assignment and POS permissions — `exists`
- **What the user could do (legacy):** Create a staff user with a role (e.g. **Cashier**), assign them to one or more stores (`store_ids` on staff create/edit, plus store-settings "assign staff"), and control what they can do via role permissions — including the `pos` group (`open_session`, `process_sale`, `close_session`, `view_history`). A user with only the Cashier role was routed to the POS terminal on login.
- **Legacy route/controller/views:** `GET/POST /management/staff*` (`Management\StaffController`), `POST /management/stores/{store}/assign-staff` (`Management\StoreSettingsController@assignStaff`), roles CRUD (`Management\RoleController` + `resources/views/management/roles/**`)
- **New API:** `POST/PUT /api/v1/management/staff` accepts `role` + `store_ids` (`Api\V1\Management\StaffController@store/@update` sync `assignedStores`); `POST/PUT /api/v1/management/roles` syncs permissions (`Api\V1\Management\RoleController`); `SpatiePermissionSeeder` defines and grants the full `pos` permission set.
- **New SPA:** `StaffView.vue` (role select + store checkboxes + PIN), `RolesView.vue` (permission matrix grouped by module, POS group included).
- **Missing:** nothing material. Note there is no POS-specific "cashier" concept beyond the `Cashier` role and store assignment, and no per-store cashier restriction UI beyond the existing store checkboxes — same as legacy.
- **Side effects:** writes role/permission and store-assignment pivots.
- **Effort:** S — **Priority:** P1

---

## Gaps worth calling out

1. **The business dashboard has zero POS endpoints and zero POS screens.** `routes/api/v1/management.php` contains no `/pos` route; `storify-management`'s router contains no POS route. Every feature in §1–§3 and §5 must be built from scratch on the management audience.
2. **The existing POS API cannot be reused from the dashboard as-is.** `routes/api/v1/pos.php` is gated by `token.audience:pos` and `EnsurePosStoreAccess`, and `Pos\SessionController`/`Pos\OrderController` deliberately scope to `staff_id = $user->id` (the cashier's own session/orders). Owner oversight (all cashiers, all stores, reconcile someone else's drawer, close a session a cashier left open) needs new management endpoints or refactored shared services. Reusing the POS endpoints unchanged would show an owner only their own sessions.
3. **Cash-drawer reconciliation semantics must be preserved.** Legacy expected close = `opening_balance + PosSession::calculateCashSalesTotal()` (confirmed transactions with a cash leg only — `metadata.leg_method = cash` or no payment method), difference = actual − expected (over/short), expected/actual/difference all stored in **kobo integers** on the session. The new POS-app close endpoint already implements this via the model; a management close endpoint should call the same model methods rather than recompute.
4. **Owner-closes-any-session behaviour is easy to miss.** `PosSessionController@close` closes the *latest open session for the store*, not just the caller's; the new POS-audience endpoint closes only the caller's own. An owner-facing close must deliberately target the store's open session (with `pos close_session` + accessible-store scoping).
5. **POS sales are invisible as POS in the new Orders SPA.** The API returns `source` but has no `source` filter, and `OrdersView.vue` renders no source column or badge — a business cannot currently separate POS takings from online orders in the one list that exists.
6. **All POS stats vanished from the dashboard.** The new `GET /management/dashboard` payload omits `open_pos_sessions` / `active_pos_stores`, and the sidebar's POS group with per-store active counts is gone, so even a business with live sessions has no signal on the landing page.
7. **POS-enable is read-only in the new stack.** `pos_enabled` is exposed by `auth/me` and `stores.show` but nothing can set it; a fresh business literally cannot turn POS on from the new UI.
8. **Terminal/receipt duplication is the one place to not rebuild blindly.** The Electron app and POS API already cover sale processing, product search, receipts and refund requests (`pos refund` queues `refund_pending` transactions awaiting admin approval — legacy `PosSaleController@refund` did the same from staff routes). The dashboard's POS surface should be oversight + configuration + deep-links, not a second terminal; the legacy in-dashboard terminal is best judged obsolete.
9. **Permissions already line up.** `pos view_history`, `pos open_session`, `pos close_session`, `pos process_sale` and the new `pos void_sale` are seeded and surfaced in RolesView — the missing screens should be gated with these exactly as legacy was.
10. **Fidelity details for the rebuild:** session route key is `session_code` (`pos_...`), session close requires `notes` as an optional string ≤ 500, opening balance is an integer in kobo, and the legacy list filters are `store_id` + `status` (open/closed) with 20-per-page pagination.
