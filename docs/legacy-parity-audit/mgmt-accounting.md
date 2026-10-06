# mgmt-accounting — Legacy → New Stack Feature Inventory

**Domain:** Accounting module (audience: business)
**Legacy source of truth:** `storify-api` @ `routes/v1/management.php` (lines 308–398) + `app/Http/Controllers/Management/Accounting/**` (9 controllers) + `Management/StoreBankController.php` + `resources/views/management/accounting/**` (30 Blade files)
**New stack:** `storify-api` (`routes/api/v1/management.php`, `app/Http/Controllers/Api/V1/Management/AccountingController.php`, `app/Services/Accounting/LedgerReportService.php`) + `storify-management` (`src/router/index.ts`, `src/views/accounting/**`, `src/api/endpoints.ts`)
**Status vocabulary:** `exists` = fully usable; `partial` = present but missing actions/fields/validations; `missing` = absent.

---

## Executive summary

The new accounting stack is a **read-only shell**: the SPA has 7 routes (dashboard, accounts, journal, expenses, suppliers, bills, reports) backed by **18 GET endpoints** in `AccountingController`. **Zero** of the legacy module's ~20 mutating routes (create/edit accounts, journal create/post/reverse/delete, expense create/void/delete, supplier CRUD, bill create/pay/void, reconciliation match/complete, settings mappings/opening balances/period close) exist in the new API, and the SPA exposes no create, edit, void, post, reverse or close action anywhere. **Bank reconciliation and accounting settings are 100 % absent** (no routes, no controller methods, no screens) — the menus in `AppLayout.vue` do not even link to them. All CSV/PDF exports and print output are absent.

| Area | Features | exists | partial | missing |
|---|---|---|---|---|
| Dashboard | 1 | 0 | 1 | 0 |
| Chart of accounts | 3 | 0 | 1 | 2 |
| Journal entries | 4 | 0 | 2 | 2 |
| Expenses | 4 | 0 | 1 | 3 |
| Suppliers | 2 | 0 | 1 | 1 |
| Bills / payables | 5 | 0 | 1 | 4 |
| Bank reconciliation | 2 | 0 | 0 | 2 |
| Reports | 10 | 1 | 9 | 0 |
| Report exports / print | 1 | 0 | 0 | 1 |
| Accounting settings | 4 | 0 | 0 | 4 |
| Store bank accounts | 1 | 0 | 0 | 1 |
| **Total** | **37** | **1** | **16** | **20** |

**Headline numbers:** 20 of 37 features are entirely missing; 16 exist only as read views thinner than legacy; 1 (reports hub) is fully covered. The new API contains **no POST/PUT/PATCH/DELETE route under `/management/accounting/**`** — every legacy write capability must be built, not merely re-wired.

---

## 1. Accounting dashboard

### 1.1 Accounting dashboard (metrics, balances, recent entries, unposted warning) — `partial`
- **What the user could do (legacy):** Open `/management/accounting` and see four metric cards (Total Assets, Liabilities, Income YTD, Net Profit YTD); an amber warning banner when confirmed payments have no ledger entry yet (with the `php artisan ledger:reconcile --post` instruction and count); a "Recent Journal Entries" table (last 8: entry number link, date, memo, status badge); a "Cash & Bank Balances" panel listing every `cash`/`bank`/`gateway_clearing` ledger account with its balance; and a Quick Actions panel (Record an expense, Enter a supplier bill, Manual journal entry, Chart of accounts, Financial reports — permission-gated).
- **Legacy route:** `GET /management/accounting` (`management.accounting.index`)
- **Legacy controller:** `Management\Accounting\AccountingDashboardController@index`
- **Legacy views:** `resources/views/management/accounting/dashboard.blade.php`
- **New API:** `GET /api/v1/management/accounting/dashboard` → `Api\V1\Management\AccountingController@dashboard` — returns only `assets`, `liabilities`, `equity`, `income_ytd`, `expenses_ytd`, `net_profit_ytd`.
- **New SPA:** `src/views/accounting/AccountingDashboardView.vue` (route `/accounting`) — 6 stat cards (adds Equity and Expenses YTD) + 4 navigation tiles.
- **Missing:** cash & bank balances per account (needs a dashboard payload block or reuse of accounts+balances); recent journal entries list (8 most recent); unposted-confirmed-payments count and warning banner — the backfill signal that tells a business its books are out of date; quick-action shortcuts to create flows (which also do not exist yet); legacy's per-type totals are recomputed on the API side but equity is dropped from the legacy view while legacy's own dashboard omits the Equity/Expenses cards — the two dashboards disagree on cards.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

---

## 2. Chart of accounts

### 2.1 Chart of accounts list (grouped, with balances and status) — `partial`
- **What the user could do (legacy):** See all ledger accounts grouped into five sections (Assets / Liabilities / Equity / Income / Expenses) with a count per section; each row shows code, name (+ System badge), subtype, computed **balance** (sign-corrected per account type), Active/Inactive pill, and per-row Edit + Activate/Deactivate actions (permission-gated). Add Account button in the page header.
- **Legacy route:** `GET /management/accounting/accounts` (`management.accounting.accounts.index`)
- **Legacy controller:** `Management\Accounting\ChartOfAccountsController@index`
- **Legacy views:** `resources/views/management/accounting/accounts/index.blade.php`
- **New API:** `GET /api/v1/management/accounting/accounts` → `AccountingController@accounts` — returns flat accounts (id, code, name, type, subtype, is_system, is_active) with **no balances** and **no parent** relation.
- **New SPA:** `src/views/accounting/AccountsView.vue` (route `/accounting/accounts`) — flat table with a client-side type filter; columns Code, Name (+ System badge), Type, Subtype, Status.
- **Missing:** balances per account (controller must aggregate posted journal lines, as legacy does); type-grouped section headers with counts; subtype column is present but no balance column at all; Edit and Activate/Deactivate row actions; "Add Account" button; pagination isn't needed (legacy loads all) but the API returns all accounts unpaginated the same way — fine.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 2.2 Account create / edit form — `missing`
- **What the user could do (legacy):** Create or edit a ledger account with Code (unique per business), Name, Type (asset/liability/equity/income/expense), optional Parent Account (excluding self on edit), Description, Active checkbox. Validation: code unique per business, parent must belong to the business.
- **Legacy routes:** `GET /management/accounting/accounts/create`, `POST /management/accounting/accounts`, `GET /management/accounting/accounts/{account}/edit`, `PUT /management/accounting/accounts/{account}` (`accounting.accounts.create/store/edit/update`)
- **Legacy controller:** `Management\Accounting\ChartOfAccountsController@create/store/edit/update`
- **Legacy views:** `resources/views/management/accounting/accounts/form.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the whole CRUD: create endpoint, edit/update endpoint, and the form UI (modal or page). Without it a business cannot tailor its chart of accounts, add parent accounts or correct names.
- **Side effects:** none.
- **Effort:** M — **Priority:** P1

### 2.3 Account activate / deactivate toggle — `missing`
- **What the user could do (legacy):** Toggle an account's active state from the list. System accounts cannot be deactivated while active (`System accounts cannot be deactivated.`); deactivated accounts disappear from all account pickers (expense form, bill form, journal entry, reconciliation, settings mappings — all filter on `active()`).
- **Legacy route:** `POST /management/accounting/accounts/{account}/toggle` (`accounting.accounts.toggle`)
- **Legacy controller:** `Management\Accounting\ChartOfAccountsController@toggle`
- **Legacy views:** `resources/views/management/accounting/accounts/index.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** toggle endpoint + UI control + the system-account guard.
- **Side effects:** changes which accounts are selectable across the whole accounting UI.
- **Effort:** S — **Priority:** P2

---

## 3. Journal entries

### 3.1 Journal entries list (search, status/date filters) — `partial`
- **What the user could do (legacy):** Browse all journal entries (20/page) with free-text search across **entry number, reference and memo**, status filter (Posted / Void / Draft), from/to date filters, a Clear-filters link, and a "Manual Entry" button. Columns: entry number (+ reference below) linking to detail, date, memo, **Debit total, Credit total**, status pill.
- **Legacy route:** `GET /management/accounting/journal` (`management.accounting.journal.index`)
- **Legacy controller:** `Management\Accounting\JournalController@index`
- **Legacy views:** `resources/views/management/accounting/journal/index.blade.php`
- **New API:** `GET /api/v1/management/accounting/journal` → `AccountingController@journal` — filters `status`, `from`, `to`, `q` (**entry_number only**), paginated; rows carry id, entry_number, entry_date, memo, reference, status, lines_count, **total_debits only** (both sidebar totals, but no credit total or separate debit+credit columns).
- **New SPA:** `src/views/accounting/JournalView.vue` (route `/accounting/journal`) — search box, status select (includes a `void` option), from/to dates, table with Entry/date, Memo, Lines, Debits, Status; pagination.
- **Missing:** search does not cover `reference` or `memo`; credit column/total; reference shown under the entry number; Clear-filters affordance; "Manual Entry" button (create flow absent); status filter vocabulary matches legacy (draft/posted/void) even though nothing can set `void` in either stack.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 3.2 Journal entry detail — `partial`
- **What the user could do (legacy):** Open an entry and see the lines table (account code + name, description, debit, credit, totals footer), a Details panel (date, status, reference, **fiscal period**, **posted by**, **posted at**), a "Reversal of …" banner linking to the original entry, and contextual actions: Post Entry / Delete Draft (when draft) and Reverse Entry (when posted, with confirm).
- **Legacy route:** `GET /management/accounting/journal/{entry}` (`management.accounting.journal.show`)
- **Legacy controller:** `Management\Accounting\JournalController@show` (+ `postDraft`, `destroy`, `reverse` for the actions)
- **Legacy views:** `resources/views/management/accounting/journal/show.blade.php`
- **New API:** `GET /api/v1/management/accounting/journal/{entry}` → `AccountingController@journalShow` — returns entry (id, entry_number, entry_date, memo, status) + lines (account, account_code, description, debit, credit).
- **New SPA:** `JournalView.vue` detail modal — lines table with debit/credit and totals footer.
- **Missing:** `reference` (not even in the API payload), fiscal period name, posted-by user, posted-at timestamp, reversal-of link (and link to the reversing entry), store attribution per line (legacy loads `lines.store`), and all three workflow actions (post draft, delete draft, reverse posted) — which are missing at the API level too.
- **Side effects:** none (read view), but the missing actions are side-effecting.
- **Effort:** S — **Priority:** P1

### 3.3 Manual journal entry create (draft or posted) — `missing`
- **What the user could do (legacy):** Open the Manual Journal Entry form (header: date, optional reference, memo), add/remove dynamic lines (account select, description, debit **xor** credit per line), with a live totals footer that turns green when balanced and a status line ("Balanced — ready to post." / "Debits and credits must match. Difference: ₦…"). Post Entry is disabled until balanced and non-zero; **Save as Draft** is enabled whenever any amount is entered. Server-side validation: at least one line with an amount; for posting at least two lines; debits must equal credits; all accounts must belong to the business; accounts scoped to the business.
- **Legacy routes:** `GET /management/accounting/journal/create`, `POST /management/accounting/journal` (`accounting.journal.create/store`)
- **Legacy controller:** `Management\Accounting\JournalController@create/store`
- **Legacy views:** `resources/views/management/accounting/journal/create.blade.php`
- **New API:** none.
- **New SPA:** none (no create button on the journal list).
- **Missing:** entire manual-entry flow: endpoint, dynamic line editor, balance validation, draft saving. This is the only way a business can make adjusting entries.
- **Side effects:** posts a journal entry to the ledger (and consumes a fiscal period slot) — the basis of every financial report.
- **Effort:** M — **Priority:** P1

### 3.4 Journal workflow: post draft, delete draft, reverse posted — `missing`
- **What the user could do (legacy):** On a draft entry: **Post Entry** (re-validates balanced, resolves the open fiscal period, stamps posted_by/posted_at) and **Delete Draft** (deletes lines + entry). On a posted entry: **Reverse Entry** (creates a reversing entry with memo `Reversal of {entry_number}`). Guards: only drafts can be posted/deleted; only posted entries can be reversed; period must be open.
- **Legacy routes:** `POST /management/accounting/journal/{entry}/post`, `DELETE /management/accounting/journal/{entry}`, `POST /management/accounting/journal/{entry}/reverse` (`accounting.journal.post/destroy/reverse`)
- **Legacy controller:** `Management\Accounting\JournalController@postDraft/destroy/reverse`
- **Legacy views:** `resources/views/management/accounting/journal/show.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** all three actions and their guards; note `JournalEntry::STATUS_VOID` exists in the model but no legacy action ever sets it (dead status carried into the SPA filter).
- **Side effects:** creates/deletes ledger entries; reversal posts a contra entry affecting reports.
- **Effort:** S — **Priority:** P1

---

## 4. Expenses

### 4.1 Expense list with stats and filters — `partial`
- **What the user could do (legacy):** See three stat cards (This Month spend, This Year spend, Records count); filter by **category**, status (Paid/Void), from/to date, with Clear link; 20/page pagination; columns Date, Expense (description or reference, linking to detail), Category (falls back to ledger account), Supplier, Amount (total incl. VAT), Status pill. "Record Expense" button in header.
- **Legacy route:** `GET /management/accounting/expenses` (`management.accounting.expenses.index`)
- **Legacy controller:** `Management\Accounting\ExpenseController@index`
- **Legacy views:** `resources/views/management/accounting/expenses/index.blade.php`
- **New API:** `GET /api/v1/management/accounting/expenses` → `AccountingController@expenses` — supports **status only**; returns id, expense_date, category, supplier, amount_kobo, total_kobo, status, description.
- **New SPA:** `src/views/accounting/ExpensesView.vue` (route `/accounting/expenses`) — status filter only; columns Date, Description, Category, Supplier, Amount (total), Status; pagination.
- **Missing:** This Month / This Year / Records stat cards; category filter; from/to date filters; Clear link; row link to expense detail (detail screen doesn't exist); reference shown under the description; "Record Expense" button. The API's `status` vocabulary (draft/approved/paid/void) is exposed in the SPA select but only legacy `paid`/`void` rows can ever exist.
- **Side effects:** none.
- **Effort:** S — **Priority:** P0

### 4.2 Record expense form — `missing`
- **What the user could do (legacy):** Create an expense with date, optional category, optional supplier, required expense ledger account, amount, optional Input VAT, required payment method (cash / bank_transfer / card / cheque / other), optional "Paid From" ledger account (auto when blank), reference, optional receipt upload (jpg/jpeg/png/pdf ≤ 5 MB) and description. Posting immediately records the expense as `paid` and posts a balanced ledger entry; if posting fails, the expense is still saved with a warning.
- **Legacy routes:** `GET /management/accounting/expenses/create`, `POST /management/accounting/expenses` (`accounting.expenses.create/store`)
- **Legacy controller:** `Management\Accounting\ExpenseController@create/store`
- **Legacy views:** `resources/views/management/accounting/expenses/form.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** the entire flow (endpoint, form, receipt upload/storage, expense-account and payment-account pickers, automatic ledger posting). This is the daily-use heart of the module.
- **Side effects:** writes an expense row, stores the receipt file, posts a journal entry (expense debit + payment account credit with VAT split).
- **Effort:** M — **Priority:** P0

### 4.3 Expense detail — `missing`
- **What the user could do (legacy):** Open an expense and see a details grid (date, status, category, ledger account, supplier, reference, amount, input VAT, total, paid-via), the description, the linked **journal entry lines** (Dr/Cr per account with link to the entry), and the **receipt** rendered inline (image) or linked (PDF). Void button in the header when not void.
- **Legacy route:** `GET /management/accounting/expenses/{expense}` (`management.accounting.expenses.show`)
- **Legacy controller:** `Management\Accounting\ExpenseController@show`
- **Legacy views:** `resources/views/management/accounting/expenses/show.blade.php`
- **New API:** none (the list endpoint has no `/{expense}` sibling).
- **New SPA:** none — the expenses table rows are not clickable.
- **Missing:** the detail endpoint, the screen, receipt display, journal-entry traceability, and the header Void action entry point.
- **Side effects:** none.
- **Effort:** M — **Priority:** P1

### 4.4 Expense void / delete — `missing`
- **What the user could do (legacy):** **Void** a non-void expense — posts a reversing ledger entry (`Void expense #{id}`) and flips status to `void` (guarded against double-void, warning if posting fails). **Delete** an unposted expense only; posted expenses are refused with "Posted expenses cannot be deleted. Void it instead."
- **Legacy routes:** `POST /management/accounting/expenses/{expense}/void`, `DELETE /management/accounting/expenses/{expense}` (`accounting.expenses.void/destroy`)
- **Legacy controller:** `Management\Accounting\ExpenseController@void/destroy`
- **Legacy views:** `resources/views/management/accounting/expenses/show.blade.php` (void form)
- **New API:** none.
- **New SPA:** none.
- **Missing:** both actions and their guards.
- **Side effects:** void posts a reversal journal entry; delete removes the expense row.
- **Effort:** S — **Priority:** P1

---

## 5. Suppliers

### 5.1 Supplier list (search, outstanding, CRUD modal) — `partial`
- **What the user could do (legacy):** Search suppliers by name/email/phone; see per-supplier Bills count and **Outstanding** (sum of non-void bill totals minus payments, floored at 0); open a supplier detail; **Add Supplier** and **Edit Supplier** in a modal (name required, email, phone, address, notes); **Delete** a supplier (refused when the supplier has bills); 20/page pagination.
- **Legacy route:** `GET /management/accounting/suppliers` (`management.accounting.suppliers.index`); store/update/destroy at `POST/PUT/DELETE /management/accounting/suppliers[/{supplier}]`
- **Legacy controller:** `Management\Accounting\SupplierController@index/store/update/destroy`
- **Legacy views:** `resources/views/management/accounting/suppliers/index.blade.php`
- **New API:** `GET /api/v1/management/accounting/suppliers` → `AccountingController@suppliers` — returns id, name, email, phone, bills_count; **no search filter, no outstanding totals, no write endpoints**.
- **New SPA:** `src/views/accounting/SuppliersView.vue` (route `/accounting/suppliers`) — plain table (Supplier, Email, Phone, Bills); no search, no Add/Edit/Delete, no outstanding column, rows not clickable.
- **Missing:** search; Outstanding column (API already aggregates bills_count only); Add/Edit modal; Delete with the "suppliers with bills cannot be deleted" guard; row link to detail; "Add Supplier" header action. Without supplier CRUD, bills cannot be created for new suppliers (the bill create form itself is also missing).
- **Side effects:** none for list; CRUD writes suppliers.
- **Effort:** S — **Priority:** P1

### 5.2 Supplier detail (bills and payments history) — `missing`
- **What the user could do (legacy):** Open a supplier to see contact card (email, phone, address, notes), a **Bills** table (bill number link, date, total, balance, status) and a **Payments** table (date, reference, method, amount), plus a "New Bill" shortcut.
- **Legacy route:** `GET /management/accounting/suppliers/{supplier}` (`management.accounting.suppliers.show`)
- **Legacy controller:** `Management\Accounting\SupplierController@show`
- **Legacy views:** `resources/views/management/accounting/suppliers/show.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** endpoint, screen, bills/payments history, New Bill shortcut.
- **Side effects:** none.
- **Effort:** S — **Priority:** P2

---

## 6. Bills (payables)

### 6.1 Bill list with stats and filters — `partial`
- **What the user could do (legacy):** See three stat cards — **Outstanding** (sum of open+partial balances), **Paid (all time)**, **Overdue count**; search by bill number or supplier name; filter by supplier, status (open/partial/paid/void), from/to issue dates; Clear link; 20/page; columns Bill number (+ issue date) linking to detail, Supplier, Due (red when past and unpaid), Total, Balance (amber when unpaid), Status; "New Bill" button.
- **Legacy route:** `GET /management/accounting/bills` (`management.accounting.bills.index`)
- **Legacy controller:** `Management\Accounting\BillController@index`
- **Legacy views:** `resources/views/management/accounting/bills/index.blade.php`
- **New API:** `GET /api/v1/management/accounting/bills` → `AccountingController@bills` — **status filter only**; rows include bill_number, supplier, issue_date, due_date, total_kobo, paid_kobo, balance_kobo, status. No stats, no q/supplier/date filters.
- **New SPA:** `src/views/accounting/BillsView.vue` (route `/accounting/bills`) — status filter; columns Bill/issue date, Supplier, Due, Total, Paid, Balance, Status; pagination; rows not clickable.
- **Missing:** Outstanding / Paid / Overdue stat cards; search by bill/supplier; supplier filter; date-range filter; Clear; overdue-red due date highlighting (legacy computes it in Blade); row link to bill detail (detail missing); "New Bill" button.
- **Side effects:** none.
- **Effort:** S — **Priority:** P0

### 6.2 New supplier bill form (line items, VAT, inventory costing) — `missing`
- **What the user could do (legacy):** Enter a bill: supplier (active only), optional Bill Number (auto `BILL-XXXXXXXX`), Issue Date, optional Due Date, Notes; dynamic **line items** (description, optional expense account — "Default expense" fallback, quantity with 3-dp precision, unit cost, computed amount, add/remove rows); live Subtotal, Input VAT and Total footer. On save: bill stored as `open`, lines created, **weighted-average inventory cost updated** for product-linked lines (`InventoryCostingService::recordReceipt`), and a balanced Accounts-Payable journal entry posted (warning banner per failure without losing the bill).
- **Legacy routes:** `GET /management/accounting/bills/create`, `POST /management/accounting/bills` (`accounting.bills.create/store`)
- **Legacy controller:** `Management\Accounting\BillController@create/store`
- **Legacy views:** `resources/views/management/accounting/bills/form.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** everything — endpoint, line-item editor, totals, auto bill number, inventory costing hook, AP posting. Note legacy's backend accepts `items.*.product_id` (validated, drives costing) even though the Blade form does not expose a product picker — the new screen should expose it or consciously drop it.
- **Side effects:** writes Bill + BillItem rows, updates product weighted-average cost, posts AP journal entry.
- **Effort:** L — **Priority:** P1

### 6.3 Bill detail — `missing`
- **What the user could do (legacy):** Open a bill: items table (description, qty, unit cost, amount) with a totals footer (Subtotal, Input VAT, Total, Paid, Balance colour-coded); **Payments** table (date, method, reference, amount) when payments exist; Details card (supplier link, issue/due dates, notes); **Journal Entry** link; header **Record Payment** (hidden for void/paid) and **Void** actions; status pill.
- **Legacy route:** `GET /management/accounting/bills/{bill}` (`management.accounting.bills.show`)
- **Legacy controller:** `Management\Accounting\BillController@show` (+ `storePayment`, `void`)
- **Legacy views:** `resources/views/management/accounting/bills/show.blade.php`
- **New API:** none.
- **New SPA:** none — bill rows are not clickable.
- **Missing:** detail endpoint + screen, payment history, journal link, payment/void entry points.
- **Side effects:** none.
- **Effort:** M — **Priority:** P1

### 6.4 Record bill payment — `missing`
- **What the user could do (legacy):** From the bill screen, open the Record Payment modal prefilled with today's date and the remaining balance; enter date, amount (min 0.01, max remaining balance), method (bank_transfer / cash / cheque / card / other), optional "Paid From" ledger account, optional reference. On save: payment row created, `amount_paid_kobo` incremented, status becomes `paid` (fully) or `partial`, and a payment journal entry (AP debit + payment account credit) is posted; posting failure warns without losing the payment. Refused on void/paid bills.
- **Legacy route:** `POST /management/accounting/bills/{bill}/payments` (`accounting.bills.payments.store`)
- **Legacy controller:** `Management\Accounting\BillController@storePayment`
- **Legacy views:** `resources/views/management/accounting/bills/show.blade.php` (modal)
- **New API:** none.
- **New SPA:** none.
- **Missing:** endpoint, modal, status transition rules, ledger posting.
- **Side effects:** writes BillPayment, transitions bill status open→partial→paid, posts journal entry.
- **Effort:** M — **Priority:** P1

### 6.5 Void bill (with reversal) — `missing`
- **What the user could do (legacy):** Void a bill from its detail screen; the original AP journal entry is reversed (`Void bill {bill_number}`), status set to `void`. Guards: already-void refused; bills with any payment cannot be voided.
- **Legacy route:** `POST /management/accounting/bills/{bill}/void` (`accounting.bills.void`)
- **Legacy controller:** `Management\Accounting\BillController@void`
- **Legacy views:** `resources/views/management/accounting/bills/show.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** endpoint + action + guards + reversal.
- **Side effects:** posts a reversing journal entry; bill excluded from AP aging/stats.
- **Effort:** S — **Priority:** P2

---

## 7. Bank reconciliation

### 7.1 Reconciliation list + statement import — `missing`
- **What the user could do (legacy):** Open `/management/accounting/reconciliation`: see all imported statements (statement date, linked account + store bank, lines count, unmatched count, status Imported/Reconciled, Open action, paginated 15/page) and the last 10 **Completed Reconciliations** (period start–end, account, statement closing balance, ledger/cleared balance, difference coloured). Import Statement modal: bank ledger account (bank/cash/gateway-clearing subtypes), optional linked Store Bank, statement date, optional closing balance, and a CSV/TXT file (≤ 5 MB) parsed leniently on columns `date, description, reference, amount` (comma/₦-tolerant; positive = inflow, negative = outflow).
- **Legacy route:** `GET /management/accounting/reconciliation` (`accounting.reconciliation.index`), `POST /management/accounting/reconciliation` (`accounting.reconciliation.store`)
- **Legacy controller:** `Management\Accounting\BankReconciliationController@index/store`
- **Legacy views:** `resources/views/management/accounting/reconciliation/index.blade.php` (+ import modal)
- **New API:** none.
- **New SPA:** none — no route, no nav entry.
- **Missing:** everything: listing, import endpoint, CSV parser, file storage, unmatch counts, completed-reconciliation history.
- **Side effects:** stores the statement file and BankStatementImport + BankStatementLine rows.
- **Effort:** L — **Priority:** P2

### 7.2 Reconcile statement screen (manual match, auto-match, ignore, complete) — `missing`
- **What the user could do (legacy):** Open an import: four summary cards (Statement Closing, Ledger Balance as of statement date, Difference, Unmatched count); statement lines table (date, description, reference, signed amount, status Unmatched/Matched/Ignored) with per-line **Match** (modal picker of up to 100 unmatched posted ledger lines for that account with date/entry/Dr-Cr/description), **Ignore**, and **Unmatch**; a header **Auto-match** button (server matches ±7 days, exact debit/credit amount, one-to-one); and a **Complete** modal (statement closing balance required, period start/end defaulted from the statement lines) which snapshots a BankReconciliation (statement closing vs cleared ledger balance, difference, completed_by/at) and flips the import to `reconciled`.
- **Legacy routes:** `GET /management/accounting/reconciliation/{import}` (`show`), `POST .../{import}/auto-match`, `POST .../lines/{line}/match`, `POST .../lines/{line}/unmatch`, `POST .../lines/{line}/ignore`, `POST .../{import}/complete`
- **Legacy controller:** `Management\Accounting\BankReconciliationController@show/autoMatch/match/unmatch/ignore/complete`
- **Legacy views:** `resources/views/management/accounting/reconciliation/show.blade.php` (+ match & complete modals)
- **New API:** none.
- **New SPA:** none.
- **Missing:** the whole screen and all five line actions plus completion snapshot. Tenant guards (`business_id` checks on import/line) are part of the legacy behaviour to reproduce.
- **Side effects:** updates line statuses/matched_journal_line_id; writes BankReconciliation; import status → reconciled.
- **Effort:** L — **Priority:** P2

---

## 8. Reports

### 8.1 Reports hub — `exists`
- **What the user could do (legacy):** Open `/management/accounting/reports` to a card grid linking nine reports with titles and one-line descriptions.
- **Legacy route:** `GET /management/accounting/reports` (`accounting.reports.index`)
- **Legacy controller:** `Management\Accounting\AccountingReportController@index`
- **Legacy views:** `resources/views/management/accounting/reports/index.blade.php`
- **New API:** no index endpoint needed (SPA drives the reports directly).
- **New SPA:** `src/views/accounting/ReportsView.vue` (route `/accounting/reports`) — nine tabs (P&L, Balance Sheet, Trial Balance, General Ledger, AR Aging, AP Aging, VAT Summary, Expense Summary, Integrity Check) with run buttons.
- **Missing:** nothing material (layout differs: tabs vs cards).
- **Side effects:** none.
- **Effort:** — — **Priority:** —

### 8.2 Profit & Loss report — `partial`
- **What the user could do (legacy):** From/To date range (defaults to month-to-date); three stat cards (Total Income, Total Expenses, Net Profit colour-coded); Income table and Expenses table per account (code + name, amount) with totals; **Export PDF**, **Export CSV**, Back.
- **Legacy route:** `GET /management/accounting/reports/profit-and-loss` (+ `?export=pdf|csv`)
- **Legacy controller:** `Management\Accounting\AccountingReportController@profitAndLoss` (+ pdf view)
- **Legacy views:** `reports/profit-and-loss.blade.php`, `reports/pdf/profit-and-loss.blade.php`
- **New API:** `GET /api/v1/management/accounting/reports/profit-and-loss` → `AccountingController@profitAndLoss` (shared `LedgerReportService`), from/to.
- **New SPA:** `ReportsView.vue` P&L tab — income/expense tables, totals, net profit banner.
- **Missing:** **PDF export and CSV export entirely** (no server export route, no SPA download/print action); the three legacy stat cards (SPA shows totals inside tables + a net-profit row instead); no `as_of` ambiguity (legacy doesn't have one either).
- **Side effects:** none.
- **Effort:** S (export plumbing is cross-cutting, see 8.11) — **Priority:** P1

### 8.3 Balance Sheet report — `partial`
- **What the user could do (legacy):** As-of date; Assets, Liabilities and Equity sections per account with totals; a **Current Period Earnings** row inside Equity; a Liabilities + Equity vs Total Assets reconciliation panel with "✓ Balanced / ✗ Out of balance"; Export PDF, Export CSV.
- **Legacy route:** `GET /management/accounting/reports/balance-sheet` (+ exports)
- **Legacy controller:** `AccountingReportController@balanceSheet`
- **Legacy views:** `reports/balance-sheet.blade.php`, `reports/pdf/balance-sheet.blade.php`
- **New API:** `GET .../reports/balance-sheet` (`as_of`).
- **New SPA:** `ReportsView.vue` Balance Sheet tab — sections + totals + net profit caption.
- **Missing:** PDF/CSV exports; the explicit Assets vs Liabilities+Equity **balanced check**; the current-period-earnings is shown as a caption rather than an equity row.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 8.4 Trial Balance report — `partial`
- **What the user could do (legacy):** From/To range; one line per account (code, account, debit, credit) with totals row and a **"✓ Balanced / ✗ Out of balance"** footer; Export PDF, Export CSV.
- **Legacy route:** `GET /management/accounting/reports/trial-balance` (+ exports)
- **Legacy controller:** `AccountingReportController@trialBalance`
- **Legacy views:** `reports/trial-balance.blade.php`, `reports/pdf/trial-balance.blade.php`
- **New API:** `GET .../reports/trial-balance` (from/to) — returns lines (code/name/debit/credit) + totals.
- **New SPA:** `ReportsView.vue` Trial Balance tab — table + totals (no balanced indicator).
- **Missing:** PDF/CSV exports; the balanced/out-of-balance indicator (available in the Integrity tab but not here).
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 8.5 General Ledger report — `partial`
- **What the user could do (legacy):** Select any account, pick From/To; see an Opening Balance row, every posting (date, entry number, memo, debit, credit, **running balance**) and a Closing Balance footer; Export CSV.
- **Legacy route:** `GET /management/accounting/reports/general-ledger` (+ `?export=csv`; account via `?account={id}`)
- **Legacy controller:** `AccountingReportController@generalLedger`
- **Legacy views:** `reports/general-ledger.blade.php`
- **New API:** `GET .../reports/general-ledger/{account}` (from/to) — account, opening, rows (date, entry_number, memo, debit, credit, balance), closing.
- **New SPA:** `ReportsView.vue` General Ledger tab — account selector (all accounts), opening/closing header, rows with running balance.
- **Missing:** CSV export. (Path-vs-query account param is an implementation difference only.)
- **Side effects:** none.
- **Effort:** S — **Priority:** P2

### 8.6 AR Aging report — `partial`
- **What the user could do (legacy):** As-of date; five bucket cards (labels + bucket totals); a flat table of all outstanding customer invoices (Invoice, Customer, **Due date**, Total, **Paid**, Outstanding, Age in days red past 30) with Total Outstanding footer; Export CSV.
- **Legacy route:** `GET /management/accounting/reports/ar-aging` (+ `?export=csv`)
- **Legacy controller:** `AccountingReportController@arAging`
- **Legacy views:** `reports/ar-aging.blade.php`
- **New API:** `GET .../reports/ar-aging` (`as_of`) — buckets with rows (reference, contact, total, paid, outstanding, days_past_due, due_date).
- **New SPA:** `ReportsView.vue` AR Aging tab — per-bucket sections with label, bucket total and rows (Reference, Customer, Outstanding, Days Past Due).
- **Missing:** CSV export; bucket stat cards (buckets are rendered as section headers with totals instead); **Due date column**; Paid column; flat single-table view (legacy flattens all buckets into one table; SPA splits per bucket — arguably better, but the missing columns are the real gap).
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 8.7 AP Aging report — `partial`
- **What the user could do (legacy):** Same as AR Aging but for supplier bills (Bill, Supplier, Due, Total, Paid, Outstanding, Age) with bucket cards and Total Outstanding footer; Export CSV.
- **Legacy route:** `GET /management/accounting/reports/ap-aging` (+ `?export=csv`)
- **Legacy controller:** `AccountingReportController@apAging`
- **Legacy views:** `reports/ap-aging.blade.php`
- **New API:** `GET .../reports/ap-aging` (`as_of`).
- **New SPA:** `ReportsView.vue` AP Aging tab — same layout/gaps as AR.
- **Missing:** CSV export; bucket cards; Due date and Paid columns; flat view.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 8.8 VAT Summary report — `partial`
- **What the user could do (legacy):** From/To range; three cards (Output VAT on sales, Input VAT on purchases, Net VAT Payable/Refundable colour-coded); a statement-style table ("VAT collected on sales", "Less: VAT paid on purchases & expenses", Net VAT Payable/Refundable); Export CSV.
- **Legacy route:** `GET /management/accounting/reports/vat-summary` (+ `?export=csv`)
- **Legacy controller:** `AccountingReportController@vatSummary`
- **Legacy views:** `reports/vat-summary.blade.php`
- **New API:** `GET .../reports/vat-summary` (from/to).
- **New SPA:** `ReportsView.vue` VAT tab — three values (output, input, net payable).
- **Missing:** CSV export (the format a tax filing actually needs); statement-table breakdown rows.
- **Side effects:** none.
- **Effort:** S — **Priority:** P1

### 8.9 Expense Summary report — `partial`
- **What the user could do (legacy):** From/To range; two cards (Total Expenses, Records count); a table by category (Category, Count, Total, **Share %**) with totals row; Export CSV.
- **Legacy route:** `GET /management/accounting/reports/expense-summary` (+ `?export=csv`)
- **Legacy controller:** `AccountingReportController@expenseSummary`
- **Legacy views:** `reports/expense-summary.blade.php`
- **New API:** `GET .../reports/expense-summary` (from/to) — categories, count, grand_total.
- **New SPA:** `ReportsView.vue` Expense Summary tab — Category / Count / Total + grand total.
- **Missing:** CSV export; **Share %** column; stat cards.
- **Side effects:** none.
- **Effort:** S — **Priority:** P2

### 8.10 Ledger Integrity report — `partial`
- **What the user could do (legacy):** Three cards (Trial Balance balanced/out with Dr/Cr totals; Unposted Payments count with the `ledger:reconcile --post` hint; Accounts Receivable matched/gap with Ledger AR vs Documents AR); a **Store Wallet vs Ledger Cash** table (per store: wallet, ledger cash, difference, with totals footer); a "Why might these differ?" explanation panel; Export CSV.
- **Legacy route:** `GET /management/accounting/reports/integrity` (+ `?export=csv`)
- **Legacy controller:** `AccountingReportController@integrity`
- **Legacy views:** `reports/integrity.blade.php`
- **New API:** `GET .../reports/integrity` — includes trial_balanced, trial_debit/credit, wallet rows/totals, unposted_transactions, ledger_ar, documents_ar, ar_difference.
- **New SPA:** `ReportsView.vue` Integrity tab — balance banner, wallet/ledger/AR-difference tiles, unposted + AR detail line, store wallet table.
- **Missing:** CSV export; explanation panel (nice-to-have).
- **Side effects:** none (it runs diagnostic queries).
- **Effort:** S — **Priority:** P2

### 8.11 Report exports (CSV on all nine, PDF on three) and print — `missing`
- **What the user could do (legacy):** Every report page has an "Export CSV" button (`?export=csv` streams a CSV via `response()->streamDownload`) and Trial Balance / Profit & Loss / Balance Sheet additionally have "Export PDF" (`Barryvdh\DomPDF` on dedicated `reports/pdf/*` Blade templates). These are what a business hands to its accountant, bank or tax authority.
- **Legacy routes:** `?export=csv|pdf` on the nine report GET routes
- **Legacy controller:** `AccountingReportController` (`csv()`, `agingCsv()`, per-report export branches) + `reports/pdf/*.blade.php`
- **Legacy views:** `resources/views/management/accounting/reports/pdf/{trial-balance,profit-and-loss,balance-sheet}.blade.php`
- **New API:** none — report endpoints return JSON only.
- **New SPA:** no download/print action anywhere (`grep export|download|print` over `src/views/accounting/*.vue` is empty).
- **Missing:** all 9 CSV exports, all 3 PDF exports, any print styling. Legacy CSV headers/rows should be reproduced so files stay interchangeable with accountants' templates.
- **Side effects:** none.
- **Effort:** M — **Priority:** P1

---

## 9. Accounting settings

### 9.1 Auto-posting account mappings (20 keys) — `missing`
- **What the user could do (legacy):** View and edit the account each money event posts to: cash, bank, gateway_clearing, accounts_receivable, inventory, fixed_assets, accounts_payable, tax_payable, accrued_liabilities, owner_equity, retained_earnings, opening_balance_equity, sales_income, service_charge_income, shipping_income, other_income, sales_discounts, cogs, gateway_fees, default_expense — each a dropdown of active accounts (defaults shown from `LedgerMapping`), with Save Mappings.
- **Legacy route:** `GET /management/accounting/settings` (`accounting.settings.index`) + `PUT /management/accounting/settings/mappings` (`accounting.settings.mappings.update`)
- **Legacy controller:** `Management\Accounting\AccountingSettingsController@index/updateMappings`
- **Legacy views:** `resources/views/management/accounting/settings/index.blade.php`
- **New API:** none.
- **New SPA:** none — `/accounting/settings` is not routed and not in the sidebar.
- **Missing:** endpoint + screen. Validation must keep accounts scoped to the business (legacy `Rule::exists('ledger_accounts','id')->where('business_id', …)`).
- **Side effects:** changes where all future auto-posted entries land in the ledger.
- **Effort:** M — **Priority:** P2

### 9.2 Opening balances (one-time posting) — `missing`
- **What the user could do (legacy):** When no `opening:%` entry exists, enter As-of date and amounts for cash, bank, gateway clearing, accounts receivable, inventory, fixed assets (debits) and accounts payable, VAT payable, owner's equity (credits); posting balances the entry via Opening Balance Equity. Once posted, the form is replaced by a confirmation card linking to the created journal entry; posting again is refused ("Opening balances have already been posted.").
- **Legacy route:** `POST /management/accounting/settings/opening-balances` (`accounting.settings.opening-balances.store`); state shown on `GET /management/accounting/settings`
- **Legacy controller:** `AccountingSettingsController@storeOpeningBalances/index` (via `LedgerPostingService::postOpeningBalances`)
- **Legacy views:** `resources/views/management/accounting/settings/index.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** endpoint, screen, one-time guard and posted-state display.
- **Side effects:** posts a one-time opening journal entry that seeds every opening balance in the reports.
- **Effort:** M — **Priority:** P1

### 9.3 Fiscal periods list + close / reopen — `missing`
- **What the user could do (legacy):** See the latest 24 fiscal periods (name, date range, Open/Closed pill) and close an open period or reopen a closed one (confirm dialogs; closed periods reject new entries; tenant guard on the period's business).
- **Legacy routes:** `POST /management/accounting/settings/periods/{period}/close`, `POST .../reopen` (`accounting.settings.periods.close/reopen`); list on `GET /management/accounting/settings`
- **Legacy controller:** `AccountingSettingsController@closePeriod/reopenPeriod`
- **Legacy views:** `resources/views/management/accounting/settings/index.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** both actions + the period list with status and date ranges.
- **Side effects:** toggles `FiscalPeriod.status/closed_at/closed_by`; closed periods make posting fail (`resolveOpenPeriod`).
- **Effort:** M — **Priority:** P2

### 9.4 Fiscal years list + close year — `missing`
- **What the user could do (legacy):** See the latest 6 fiscal years (name, start–end, Open/Closed pill) and **Close Year** (confirm: "Net income will move to retained earnings and all periods will be locked"): `LedgerClosingService::closeYear` posts the net-result transfer and locks periods. Permission-gated separately (`accounting close`).
- **Legacy route:** `POST /management/accounting/settings/years/{year}/close` (`accounting.settings.years.close`); list on `GET /management/accounting/settings`
- **Legacy controller:** `AccountingSettingsController@closeYear`
- **Legacy views:** `resources/views/management/accounting/settings/index.blade.php`
- **New API:** none.
- **New SPA:** none.
- **Missing:** endpoint + screen + the `accounting close` permission gate.
- **Side effects:** posts the year-end closing entry to retained earnings and locks the year's periods.
- **Effort:** M — **Priority:** P2

---

## 10. Store bank accounts (StoreBankController)

### 10.1 Store bank accounts CRUD + primary flag — `missing`
- **What the user could do (legacy):** Manage a store's payout bank accounts — add (bank_name, bank_code, account_number, account_name; first account automatically primary and verified), edit, set primary (resets others), delete (refused for the primary account: "Cannot delete the primary bank account. Set another account as primary first."). The store settings screen lists the store's bank accounts (name, account number, account name), and reconciliation imports can link to a store bank.
- **Legacy routes:** `POST /management/stores/{store}/banks` (`stores.banks.store`), `PUT .../banks/{bank}`, `PATCH .../banks/{bank}/primary` (`stores.banks.primary`), `DELETE .../banks/{bank}` — all behind `permission:stores settings`
- **Legacy controller:** `Management\StoreBankController@store/update/setPrimary/destroy`
- **Legacy views:** `resources/views/management/stores/settings.blade.php` (Bank Accounts card; also referenced by `stores/payment-methods.blade.php` and `stores/success.blade.php`)
- **New API:** none — no `/stores/{store}/banks` routes in `routes/api/v1/management.php`.
- **New SPA:** none — `StoresView.vue` has no bank section.
- **Missing:** all four endpoints, the management UI (create/edit/delete/set-primary), the primary-account rules, and the store-bank link used by reconciliation imports.
- **Side effects:** writes StoreBank rows; the primary account is what POS/payout flows reference.
- **Effort:** M — **Priority:** P1

---

## Gaps worth calling out

1. **The new API is write-less for this whole domain.** `routes/api/v1/management.php` contains 18 GET routes for accounting and **not a single POST/PUT/PATCH/DELETE**. Twenty legacy mutating routes have no counterpart, so the SPA cannot be fixed by wiring — every create/edit/void/post/reverse/close endpoint has to be built. The permissions for all of them (`accounting accounts|journal|expenses|suppliers|bills|reconcile|settings|close`, `stores settings`) are already seeded in `SpatiePermissionSeeder`, so gating is ready.

2. **Bank reconciliation and accounting settings are 100 % absent** — not in the router, not in the sidebar (`AppLayout.vue` lists only 7 accounting entries), not in the API. A business that reconciled its bank feed in legacy has no path to do so now; the same applies to auto-posting mappings, opening balances, fiscal period close and year-end close. Opening balances are arguably P1: without them a business migrating mid-year starts its books at zero.

3. **Every export and print view is gone.** Nine CSV exports and three DomPDF reports (`reports/pdf/{trial-balance,profit-and-loss,balance-sheet}.blade.php`) were the accountant/tax-facing output. The new SPA has no download or print action at all; the API streams JSON only. VAT Summary CSV and the balance-sheet/trial-balance PDFs are the ones a business actually files.

4. **Day-to-day data entry is impossible.** Recording an expense, entering a bill and making a manual journal entry were the three daily-use flows; all three are missing (form, endpoint, posting side effects). Dashboard quick actions and the list-header buttons that led to them are also absent.

5. **Read views are visibly thinner.** Accounts lose balances and grouping; the journal loses reference/period/posted-by/reversal data and credit columns; expenses lose month/year stats and category/date filters and are not clickable; suppliers lose search, outstanding totals and all CRUD; bills lose stats, search, supplier/date filters and detail. Several of these are cheap (S) because the API already returns most fields — e.g. `bills` returns `paid_kobo` and `balance_kobo`, `journal` returns `reference` and `total_debits`, `suppliers` returns `bills_count`.

6. **Data-integrity signals were dropped from the dashboard.** The "N confirmed payments have no ledger entry yet" banner and its backfill hint are the only in-product warning that the books are incomplete; the new dashboard payload has no such field, and neither dashboard shows the per-account cash/bank balances that legacy listed.

7. **Minor observations:** (a) the SPA's Journal and Expenses status filters expose `void`/`draft`/`approved` values that no screen can ever set (legacy had the same dead `void` filter on the journal list; `JournalEntry::STATUS_VOID` is never written); (b) the legacy bill create backend accepts `items.*.product_id` and drives weighted-average inventory costing, but the legacy form never exposed a product picker — the new bill form should decide explicitly whether to expose it; (c) legacy ar/ap aging rows carry a `due_date` that the new API still returns but the SPA never renders; (d) the new API dashboard adds Equity and Expenses-YTD cards legacy didn't have — a net improvement worth keeping.
