# mgmt-finance — Legacy → New Stack Feature Inventory

**Domain:** Payments, transactions and payment configuration (audience: business)
**Legacy source of truth:** `storify-api` @ `routes/v1/management.php` + `app/Http/Controllers/Management/{Transaction,PaymentSettings,StoreBank}Controller.php` + `resources/views/management/{transactions,payment-settings}/**` (plus the store settings payment tab and the accounting reconciliation module, both in scope per the audit brief)
**New stack:** `storify-api` (`routes/api/v1/management.php`, `app/Http/Controllers/Api/V1/Management/**`) + `storify-management` (`src/router/index.ts`, `src/views/**`, `src/api/endpoints.ts`)
**Status vocabulary:** `exists` = fully usable; `partial` = present but missing actions/fields/validations; `missing` = absent.

---

## Executive summary

The transaction **action loop** (list → open → confirm / reject / refund) was ported to the new stack and is broadly faithful for the money movement itself — store-balance credit/debit, order `amount_paid` reversal, ledger posting and digital delivery are all preserved. But three large things are gone:

1. **Payment configuration is 100% absent from the new stack.** There is not a single route, controller, endpoint or screen for bank accounts, Paystack keys, bank verification, or assigning payment methods to stores. In the new SPA a business literally cannot configure how it gets paid.
2. **All customer/business/admin email notifications** on confirm, reject and refund (`PaymentConfirmedMail`, `PaymentRejectedMail`, `RefundProcessedMail`) were dropped.
3. **All evidence and context in the transaction screens** — payment slip viewer, associated order summary, customer/staff/source, structured rejection/refund reason, store and date filters, store/customer columns — is thinner or missing. Reconciliation (CSV statement import + match) has no new counterpart at all.

| Area | Features | exists | partial | missing |
|---|---|---|---|---|
| Transaction list & detail | 3 | 0 | 3 | 0 |
| Transaction actions (confirm / reject / refund) | 3 | 0 | 3 | 0 |
| Evidence, badges & access control | 3 | 0 | 1 | 2 |
| Payment configuration (banks / Paystack / store assignment) | 5 | 0 | 0 | 5 |
| Reconciliation | 1 | 0 | 0 | 1 |
| **Total** | **15** | **0** | **7** | **8** |

**P0 count:** 4 (transaction list, confirm, reject, business bank accounts + Paystack config grouped as two P0 entries).

---

## 1. Transactions

### 1.1 Transaction list with search, status/store/date filters and pagination — `partial` / `partial`
- **What the user could do (legacy):** Paginated (15/page, query string preserved) list of every transaction across all accessible stores. Columns: **Reference** (link to detail), **Order / Invoice** (linked), **Store**, **Customer** (customer name for orders, `recipient_name` for invoices), **Amount**, **Status** badge, **Date**. Filters: free-text **reference** search (300 ms debounce), **status** dropdown (all 6 `TransactionStatus` values with labels), **store** dropdown (only accessible, non-deleted stores), **date-from** and **date-to**, plus a **Clear** link when any filter is active. Restricted staff see only transactions whose order/invoice belongs to their assigned stores. Empty state and "no transactions yet" copy.
- **Legacy route:** `GET /management/transactions` (`management.transactions.index`)
- **Legacy controller:** `Management\TransactionController@index`
- **Legacy views:** `resources/views/management/transactions/index.blade.php`
- **New API:** `GET /api/v1/management/transactions` → `Api\V1\Management\TransactionController@index` (`permission:transactions view`). Supports only `q` (reference like) and `status`; `per_page` default 20. Payload: `id, reference, amount, fee, net, currency, status, order, invoice, payment_method, paid_at, created_at`. Order/invoice/store/customer are eager-loaded but **not exposed** in the payload (only order/invoice numbers).
- **New SPA:** `src/views/TransactionsView.vue` (route `/transactions`), `transactionsApi.index`
- **Missing:** store filter and date-range filter in both API and UI (the API must accept `store_id`, `date_from`, `date_to`); store name and customer name columns/fields; status filter uses raw lowercase values in the SPA instead of labels; "Clear filters" affordance; per-store deep link. SPA adds fee/net/method columns legacy did not have (an improvement, keep).
- **Side effects:** none.
- **Effort:** S — **Priority:** P0

### 1.2 Transaction detail with order/invoice context, balance impact and audit metadata — `partial` / `partial`
- **What the user could do (legacy):** Full-page detail (not a modal) with: header status badge + contextual actions; key metrics (**Amount**, created date/time, `paid_at`); **Transaction Record** table (reference, status, **gateway_reference**, payment-method label for cash/bank_transfer/paystack, **linked bank account with `is_verified` badge**, currency); **Associated Order** block (order number link, status, item count, subtotal, service charge including its `meta` name, total, **source** POS/storefront, **Processed By** staff name/email, customer name/email/phone or walk-in `meta` fallback, store link + store code); **Store Balance Impact** (before / signed change / after, computed from kobo columns); **Payment Proof** thumbnail + uploaded-at, with a full-size modal that renders images or offers a PDF "Open Document" link; **Rejection / Refund Reason** panel with timestamps. Invoice-linked transactions show the invoice number instead of the order block. Access enforced per transaction (restricted staff scoped to assigned stores).
- **Legacy route:** `GET /management/transactions/{transaction:reference}` (`management.transactions.show`)
- **Legacy controller:** `Management\TransactionController@show`
- **Legacy views:** `resources/views/management/transactions/show.blade.php`
- **New API:** `GET /api/v1/management/transactions/{transaction}` → `@show`. Detailed payload adds `gateway_reference`, `store_balance_before/after`, `bank{bank_name,account_number,account_name}`, raw `metadata`. Missing: order status/items/subtotal/service charge/total/source/staff, customer block, invoice items, `payment_slip`, order store name, structured rejection/refund reason fields.
- **New SPA:** detail modal inside `src/views/TransactionsView.vue` — shows amount/fee/net/currency/order/invoice/method/paid_at, bank, gateway reference, balance pair (kobo-converted) and a raw `metadata` JSON dump. Missing the order/customer/context blocks, the slip, and human-readable reason presentation.
- **Missing:** all order/invoice context fields above; `payment_slip` in the API payload; structured display of rejection/refund reason + timestamps; a real detail route/page (legacy had a shareable URL); restricted-staff store authorization (see 3.3).
- **Side effects:** none (read-only).
- **Effort:** M — **Priority:** P1

### 1.3 Confirm pending payment — `partial` / `exists`
- **What the user could do (legacy):** Confirm a **pending** payment from the detail page via a modal that restates Amount, Reference, Bank Account and Order before submitting. Server-side, inside a DB transaction: set status `confirmed`; credit the store balance (kobo) with `lockForUpdate`, recording `store_balance_before/after` and `balance_updated_at`; add `transaction.amount` to the order's `amount_paid` and promote a fully-paid pending order to `accepted`; post to the ledger (`LedgerPostingService::postPaymentReceived`); deliver digital products once fully paid; then queue **PaymentConfirmedMail to the customer, the store owner and the platform admin**, with a log line per recipient. Only pending transactions can be confirmed; non-pending returns a friendly error.
- **Legacy route:** `POST /management/transactions/{transaction:reference}/confirm` (`management.transactions.confirm`)
- **Legacy controller:** `Management\TransactionController@confirmPayment`
- **Legacy views:** `resources/views/management/transactions/show.blade.php` (confirm modal + header button)
- **New API:** `POST /api/v1/management/transactions/{transaction}/confirm` (`permission:transactions confirm`) — idempotent status guard, atomic credit, order update, ledger, digital delivery all preserved.
- **New SPA:** `TransactionsView.vue` — header/list buttons gated on `auth.can('transactions confirm')`, but uses a bare browser `confirm()` dialog rather than the legacy summary modal.
- **Missing:** the three confirmation emails (mailable classes still exist in `app/Mail/`); order/bank context in the pre-confirm dialog; restricted-staff store scoping.
- **Side effects (legacy):** store balance credit; order amount_paid + status transition; ledger entry; digital delivery; 3 queued emails; structured logs.
- **Effort:** S — **Priority:** P0

### 1.4 Reject pending payment with reason — `partial` / `exists`
- **What the user could do (legacy):** Reject a **pending** payment via a modal that shows Amount and Reference and takes an **optional** reason (≤500 chars). Server sets status `cancelled`, stores `rejection_reason`, `rejected_at`, `rejected_by` in `metadata`, and queues **PaymentRejectedMail to customer, store owner and platform admin** with the reason. Invoice-linked payments short-circuit with "Invoice payment rejected." and send no emails.
- **Legacy route:** `POST /management/transactions/{transaction:reference}/reject` (`management.transactions.reject`)
- **Legacy controller:** `Management\TransactionController@rejectPayment`
- **Legacy views:** `resources/views/management/transactions/show.blade.php` (reject modal)
- **New API:** `POST /api/v1/management/transactions/{transaction}/reject` (`permission:transactions reject`) — same metadata write, but **reason is now required** (`required|string|max:500`, a behaviour change from legacy) and no emails are sent. No invoice-specific handling.
- **New SPA:** `TransactionsView.vue` reject modal — textarea marked required.
- **Missing:** optional-reason parity (or an explicit product decision that a reason is always needed); customer/store-owner/admin rejection emails.
- **Side effects (legacy):** status → `cancelled`; metadata audit pair; 3 queued emails.
- **Effort:** S — **Priority:** P0

### 1.5 Refund confirmed payment — `partial` / `exists`
- **What the user could do (legacy):** Refund a **confirmed** order payment from the detail page (button hidden when the order is delivered/completed). Modal restates amount, **shows current store balance**, and requires a reason. Server, in a DB transaction: debit the store balance (throws on insufficient funds, surfaced as "Insufficient store balance to process refund. Current balance: ₦…"), set status `refunded`, record `refund_reason`, `refunded_at`, `refunded_by`, `refund_balance_before/after` in metadata, reverse the order `amount_paid`, post the ledger refund (`postRefund`), and queue **RefundProcessedMail to the customer**. Refunds are explicitly **not supported for invoice payments**.
- **Legacy route:** `POST /management/transactions/{transaction:reference}/refund` (`management.transactions.refund`)
- **Legacy controller:** `Management\TransactionController@refundPayment`
- **Legacy views:** `resources/views/management/transactions/show.blade.php` (refund modal)
- **New API:** `POST /api/v1/management/transactions/{transaction}/refund` (`permission:transactions refund`) — same debit/metadata/order/ledger logic and same invoice + delivered/completed guards.
- **New SPA:** `TransactionsView.vue` refund modal — but the refund button is shown for **any** confirmed transaction, including delivered/completed orders, and only fails on submit (legacy hid it).
- **Missing:** refund email to the customer; current-balance display and friendly insufficient-balance message (new API returns the raw exception); correct button visibility.
- **Side effects (legacy):** store balance debit; order amount_paid reversal; ledger entry; 1 queued email.
- **Effort:** S — **Priority:** P1

---

## 2. Evidence and workflow affordances

### 2.1 Payment slip / proof-of-payment viewer — `missing` / `missing`
- **What the user could do (legacy):** A **pending** transaction with `payment_slip` shows a "View Slip" header button and a Payment Proof card (thumbnail + "Uploaded X ago"). The slip modal renders images inline (`Storage::disk('public')->url(...)`) or offers an "Open Document" link for PDFs. This is the customer-supplied evidence for the confirm/reject decision on bank transfers.
- **Legacy route/controller/views:** part of `GET /management/transactions/{transaction:reference}` (`Management\TransactionController@show`), `resources/views/management/transactions/show.blade.php`
- **New API:** **no** `payment_slip` field in either list or detailed payload.
- **New SPA:** no slip UI anywhere in `TransactionsView.vue`.
- **Missing:** expose `payment_slip` (signed/public URL) on the transaction detail payload and render image/PDF preview in the SPA. Confirm/reject on a manual transfer currently happens blind.
- **Effort:** S — **Priority:** P1

### 2.2 Pending-transactions sidebar badge — `missing` / `missing`
- **What the user could do (legacy):** The sidebar "Transactions" item shows an amber count of pending transactions, scoped to the whole business (or to assigned stores for restricted staff), cached 15 seconds and computed in the `AppServiceProvider` view composer.
- **Legacy route/controller/views:** `resources/views/management/components/sidebar.blade.php` + `app/Providers/AppServiceProvider.php` (`sidebarPendingTransactionsCount`)
- **New API:** none — the management dashboard returns only an orders `pending` count; no transaction count anywhere.
- **New SPA:** `AppLayout.vue` has no badge for Transactions (badges exist for nothing on this item).
- **Missing:** a pending-transaction count in an API payload the layout can consume, and the badge itself.
- **Effort:** S — **Priority:** P2

### 2.3 Restricted-staff transaction store scoping — `partial` / `partial`
- **What the user could do (legacy):** Staff with restricted store access only see transactions whose order/invoice belongs to one of their assigned stores in the list, and get **403** on detail/confirm/reject/refund for anything outside those stores (`userOwnsTransaction` checks assigned store IDs).
- **Legacy route/controller/views:** `Management\TransactionController` (`forBusiness`, `userOwnsTransaction`, used by index/show/confirm/reject/refund)
- **New API:** `Api\V1\Management\TransactionController` scopes index by `business_id` and `authorizeTransaction` checks **only** `business_id` — a restricted staff member can read and act on every transaction in the business.
- **New SPA:** relies entirely on the API; permission gating only (`transactions view/confirm/reject/refund`), no store scoping.
- **Missing:** assigned-store enforcement on index and on show/confirm/reject/refund for restricted staff (mirror `ResolvesManagementContext::accessibleStoreIds`).
- **Effort:** S — **Priority:** P1

---

## 3. Payment configuration (Payment Settings)

### 3.1 Business bank account management with Paystack verification — `missing` / `missing`
- **What the user could do (legacy):** On `/management/payment-settings`: see all business bank accounts with **Primary** and **Verified** chips and masked account number; **Add Bank Account** modal — bank picked from the live Paystack bank list (`getBanks`, cached 1 day), 10-digit account number auto-triggers **Paystack account-name resolution** (`verify-bank` JSON endpoint), account name is auto-filled read-only and the submit stays disabled until verification succeeds; optional "assign to store" (`store_id`) which also inserts the store payment-method pivot; duplicate guard on `account_number + bank_code`; adding the first bank **auto-registers `bank_transfer` as a business payment method**. Per row: **Set Primary** (single primary enforced by clearing others), **Edit** (modal; bank/account number readonly, only account name editable), **Remove** (with confirm). `is_verified` is stored true on create (post-verification).
- **Legacy routes:** `GET /management/payment-settings` (`management.payment-settings.index`); `POST /management/payment-settings/bank-accounts`; `PUT /management/payment-settings/bank-accounts/{bank}`; `DELETE /management/payment-settings/bank-accounts/{bank}`; `POST /management/payment-settings/verify-bank`
- **Legacy controller:** `Management\PaymentSettingsController@{index,storeBankAccount,updateBankAccount,destroyBankAccount,verifyBankAccount}`
- **Legacy views:** `resources/views/management/payment-settings/index.blade.php` (bank section + add/edit modals + `verifyBank()` JS)
- **New API:** **none** — `routes/api/v1/management.php` has no `payment-settings` or `bank-accounts` routes. The `StoreBank` model and `store_banks` table still exist, and the storefront checkout reads a gateway pivot (`pivot->api_keys`) that no business-facing endpoint writes (legacy wrote `config` JSON), so card payments cannot be configured at all in the new stack.
- **New SPA:** **none** — no route, view, or endpoint in `storify-management` (`src/router/index.ts` has no settings/payment route; `src/api/endpoints.ts` has no bank calls).
- **Missing:** every part of the above — list, create, edit, delete, set-primary, Paystack bank list proxy, account-number resolution, duplicate guard, auto-registering `bank_transfer`, optional store assignment.
- **Side effects (legacy):** `store_banks` rows; `business_payment_method` + `store_payment_method` pivot inserts; `payment-settings.bank_added` log.
- **Effort:** M — **Priority:** P0

### 3.2 Paystack gateway configuration (connect / edit / toggle / test / remove) — `missing` / `missing`
- **What the user could do (legacy):** On Payment Settings, connect a Paystack gateway (public + secret key), see connected gateways with Active/Inactive badge, masked public key and **assigned store count**; **Test** posts to Paystack `testConnection` and alerts success/failure; **Edit** updates both keys; **Enable/Disable** toggles `is_active`; **Remove** deletes the business gateway and all of its store assignments. Only Paystack is a supported gateway (the modal is hardcoded; an unused `$availableGateways` collection is passed to the view).
- **Legacy routes:** `POST /management/payment-settings/gateways`; `PUT /management/payment-settings/gateways/{gateway}`; `DELETE /management/payment-settings/gateways/{gateway}`; `PATCH /management/payment-settings/gateways/{gateway}/toggle`; `POST /management/payment-settings/gateways/{gateway}/test`
- **Legacy controller:** `Management\PaymentSettingsController@{storePaystackKeys,updatePaystackKeys,destroyPaystackKeys,togglePaystackKeys,testGateway}`
- **Legacy views:** `resources/views/management/payment-settings/index.blade.php` (gateway section + connect/edit modals + `testGateway()` JS)
- **New API:** **none.** `PaystackService` (including `testConnection`, `testApiKeys`, `usingGateway`) still exists in the new codebase but nothing exposes it to a business.
- **New SPA:** **none.**
- **Missing:** the whole feature. Also note the legacy edit modal round-trips the **secret key in plaintext to the browser**; a rebuild should make the secret write-only and show only a masked public key.
- **Side effects (legacy):** `business_payment_method` rows with `config {public_key, secret_key}`; cascading `store_payment_method` deletes on remove.
- **Effort:** M — **Priority:** P0

### 3.3 Payment method → store assignment (assigned / available stores) — `missing` / `missing`
- **What the user could do (legacy):** Open a payment method's info page (`/management/payment-method/{type}/{id}/info`, type = `gateway`|`bank`) to see the method header, a **Test Connection** button (gateways), the table of **assigned stores** (name, status, Manage link, Remove with confirmation) and an **Assign to Store** modal listing only stores that do **not** yet have the method active. Bank methods are identified by their business pivot `config`. (Legacy quirk: nothing in the index view links to this page — it is reachable only by URL — but the same assign/unassign operations are exposed from the store settings tab, feature 3.5.)
- **Legacy routes:** `GET /management/payment-method/{type}/{id}/info`; `POST /management/payment-method/{id}/assign/{type}`; `DELETE /management/payment-method/{id}/unassign/{type}/{store_id}`
- **Legacy controller:** `Management\PaymentSettingsController@{methodInfo,assignStore,unassignStore}`
- **Legacy views:** `resources/views/management/payment-settings/method-info.blade.php`
- **New API:** **none** (no payment-method routes of any kind).
- **New SPA:** **none.**
- **Missing:** the whole feature: method info, assigned/available store lists, assign and unassign endpoints and UI.
- **Side effects (legacy):** `store_payment_method` pivot insert/delete; this is what makes a method appear at checkout for a store.
- **Effort:** M — **Priority:** P1

### 3.4 Store-level bank accounts (StoreBankController CRUD + primary) — `missing` / `missing`
- **What the user could do (legacy):** From a store's settings, add bank accounts bound to the store: `bank_name`, `bank_code`, `account_number`, `account_name`, optional `is_primary` checkbox (the **first** bank is forced primary). Update details, **Set Primary** (clears others), delete — with a hard guard that the **primary account cannot be deleted** ("Set another account as primary first"). `is_verified` is set true on create. All mutations log request/validation/DB detail. The store settings page also renders the store's bank accounts card.
- **Legacy routes:** `POST /management/stores/{store}/banks`; `PUT /management/stores/{store}/banks/{bank}`; `PATCH /management/stores/{store}/banks/{bank}/primary`; `DELETE /management/stores/{store}/banks/{bank}`
- **Legacy controller:** `Management\StoreBankController@{store,update,destroy,setPrimary}`
- **Legacy views:** `resources/views/management/stores/settings.blade.php` (Bank Accounts card); related `stores/tabs/settings.blade.php` payment modal
- **New API:** **none** — the new management API has no `stores/{store}/banks` routes and the store payload exposes only `payment_mode`.
- **New SPA:** **none** — `StoresView.vue` has no bank section or tab.
- **Missing:** the entire CRUD + primary workflow, first-bank auto-primary, primary-delete guard. If rebuilt, also add the business/tenant check that legacy itself omitted on these routes.
- **Side effects (legacy):** `store_banks` rows; heavy logging.
- **Effort:** M — **Priority:** P1

### 3.5 Per-store "Manage Payment Methods" modal — `missing` / `missing`
- **What the user could do (legacy):** In a store's settings tab, open **Manage Payment Methods**: see **assigned gateways** (Paystack name + masked public key + Active dot + Remove), **assigned bank accounts** (name, account name, masked number, Verified chip + Remove), **available gateways** (Assign) and **available bank accounts** (Assign), or "All available payment methods are assigned" with a link to Payment Settings. Backed by `StoreTabController` data (`availableMethods`, `assignedMethodPivotIds`, `gatewayConfigs`, `bankAccounts`, `availableBankAccounts`).
- **Legacy routes:** `POST /management/stores/{store}/assign-bank`; `DELETE /management/stores/{store}/remove-bank/{bank}`; `POST /management/payment-method/{id}/assign/{type}`; `DELETE /management/payment-method/{id}/unassign/{type}/{store_id}`
- **Legacy controllers:** `Management\StoreSettingsController@{assignBank,removeBank}`, `Management\PaymentSettingsController@{assignStore,unassignStore}`, `Management\StoreTabController` (data)
- **Legacy views:** `resources/views/management/stores/tabs/settings.blade.php` (modal), `resources/views/management/stores/settings.blade.php`
- **New API:** **none.**
- **New SPA:** **none.**
- **Missing:** the whole modal and its four lists, plus the API endpoints that back them. This is the screen that actually controls which payment methods a store's customers can use.
- **Effort:** M — **Priority:** P1

### 3.6 Store payment mode (Auto card / Manual transfer) — `missing` / `missing`
- **What the user could do (legacy):** `POST /management/payment-settings/stores/{store}/toggle-mode` (owner only) sets a store's `payment_mode` to `auto` or `manual`, validating that `auto` requires an active Paystack assignment on the store and `manual` requires at least one business bank account. **Judgement: effectively obsolete** — no Blade view or JS in the legacy tree references this route, so it was controller-only/dead UI. If the new stack keeps per-store method assignment (3.5), this toggle is redundant.
- **Legacy route:** `POST /management/payment-settings/stores/{store}/toggle-mode` (`management.payment-settings.toggle-mode`)
- **Legacy controller:** `Management\PaymentSettingsController@togglePaymentMode`
- **Legacy views:** none found.
- **New API / SPA:** **none** (the `stores.payment_mode` column and field survive in the store payload).
- **Missing:** nothing to rebuild unless product wants the guardrails; keep as a data field only.
- **Effort:** S — **Priority:** P2

---

## 4. Reconciliation

### 4.1 Bank reconciliation — CSV statement import, match/unmatch/ignore, auto-match, completion — `missing` / `missing`
- **What the user could do (legacy):** Listed with the audit brief as "reconciliation of online payments" (the ledger account picker includes the **`gateway_clearing`** subtype for Paystack settlements alongside bank/cash).
  - **Index:** paginated list of statement imports (statement date, ledger account + store bank, line count, unmatched count in amber, status Imported/Reconciled, Open) and a table of the 10 most recent completed reconciliations.
  - **Import modal:** ledger account (active bank/cash/gateway_clearing accounts), optional store bank, statement date, opening and closing balance, CSV/TXT file ≤5 MB. CSV parsed with header detection (`date, description, reference, amount`, tolerant of ₦/commas); each row becomes an unmatched statement line.
  - **Show:** ledger closing balance computed from posted journal lines, unmatched count and matched total; the statement lines with per-line actions **Match** (choose a candidate journal line), **Unmatch**, **Ignore**; a candidates list of unmatched posted journal lines on that account; **Auto-match** (same amount, ±7-day window, nearest date first, no double-matching).
  - **Complete:** statement closing balance + start/end dates creates a `BankReconciliation` record with cleared balance and difference, and flips the import to reconciled.
- **Legacy routes:** `GET/POST /management/accounting/reconciliation`; `POST .../lines/{line}/match|unmatch|ignore`; `GET .../{import}`; `POST .../{import}/auto-match|complete`
- **Legacy controller:** `Management\Accounting\BankReconciliationController`
- **Legacy views:** `resources/views/management/accounting/reconciliation/index.blade.php`, `.../show.blade.php`
- **New API:** **none** — the new accounting API ends at dashboard/accounts/journal/expenses/suppliers/bills/reports; no reconciliation routes.
- **New SPA:** **none** — `storify-management/src/views/accounting/` has no reconciliation view and the router has no such route.
- **Missing:** the entire module (import, parsing, matching workflow, auto-match, completion). Note this is nominally the accounting domain, but there is no other reconciliation of gateway settlements anywhere in the new stack.
- **Side effects (legacy):** `bank_statement_imports`, `bank_statement_lines`, `bank_reconciliations` rows; import status transitions; no ledger postings.
- **Effort:** L — **Priority:** P1

---

## Gaps worth calling out

1. **The business cannot configure payments at all in the new stack.** There is no API route, controller, SPA route, view, or endpoint for bank accounts, Paystack keys, verification or store assignment — the entire `PaymentSettingsController`/`StoreBankController` surface is absent. Until this is rebuilt, every other payment feature (manual transfer confirmation, card checkout) has no configuration path. The storefront checkout reads Paystack keys from `pivot->api_keys` while legacy wrote `config {public_key, secret_key}` into `business_payment_method` — that mismatch means keys can never be populated today even manually. **This is the single biggest hole in the domain.**
2. **All payment emails are gone.** Confirm, reject and refund no longer notify the customer (nor the store owner/admin on confirm/reject). The mailable classes still exist in `app/Mail/`, so this is cheap to restore, but it is a customer-facing regression versus legacy.
3. **The transaction screens lost their context.** The detail payload drops order status/items/subtotal/service charge/total/source/staff and the customer block; the list drops store and customer columns and the store/date filters. The legacy page was what a finance operator used to decide on a payment; the new modal shows amounts and a raw JSON metadata blob.
4. **Payment slip viewing is gone.** Manual bank transfers are confirmed/rejected without the customer-submitted proof of payment — the evidence the legacy flow was designed around.
5. **Reject reason silently became mandatory.** Legacy allowed an empty reason (and stored `null`); the new API validates `required` and the SPA marks the textarea required. Either restore optionality or make the change deliberate.
6. **Restricted-staff scoping on transactions is not enforced by the new API** (business_id only), so a staff member confined to one store can read and act on the whole business's payments.
7. **Reconciliation has no successor**, including the `gateway_clearing` settlement matching that gives the domain its "reconciliation of online payments" coverage.
8. **`transactions export` permission exists in `SpatiePermissionSeeder` but no export ever existed in legacy or the new stack.** Either build it (CSV of the filtered list would be the obvious scope) or drop the permission; today it implies a capability that does not exist.
9. **Store payment-mode toggle was already dead UI in legacy** (route + controller, no view reference). Treat `stores.payment_mode` as a data field unless product asks for guardrails.
10. **Legacy security quirks not to copy:** the Paystack secret key is round-tripped to the browser in the edit modal, and `StoreBankController` never checks that the bank belongs to the store's business. The rebuild should make secrets write-only and add the tenant check.
