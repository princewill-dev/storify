# mgmt-finance — Adversarial Verification

**Verified:** 2026-10-06. Re-read independently (not trusting the audit's quotes): `routes/v1/management.php`, `Management/TransactionController`, `Management/PaymentSettingsController`, `Management/StoreBankController`, `Management/StoreSettingsController`, `Management/StoreTabController`, `Management/Accounting/BankReconciliationController`, all views under `resources/views/management/transactions/**` and `payment-settings/**` plus `stores/settings.blade.php`, `stores/tabs/settings.blade.php`, `stores/tabs/transactions.blade.php`, `stores/payment-methods.blade.php`, `accounting/reconciliation/**`; new stack: `routes/api/v1/management.php`, `Api/V1/Management/TransactionController`, `StoreController`, `DashboardController`, complete SPA (`storify-management` router/views/endpoints). Enums, models, migrations and `SpatiePermissionSeeder` were checked where a status claim depended on them.

## Missed features

**None found.** Every route in the finance scope is covered by a feature entry, and every view file in the literal scope (`transactions/index|show`, `payment-settings/index|method-info`, empty `payment-settings/partials`) is accounted for. Three near-misses, none of which warrant a new feature row:

- **Store transactions tab** (`GET /management/stores/{store}/tab/transactions` → `StoreTabController@transactions`, `stores/tabs/transactions.blade.php`) is a transaction list the finance audit does not mention, but it is already fully inventoried by the sibling audit `mgmt-stores.md` §2.5 (including its missing `store_id` filter in the new API). Do not double-count it here.
- `resources/views/management/stores/payment-methods.blade.php` (378 lines, "Set Up Payment Methods" onboarding page) is **unreachable dead UI**: no controller/route renders it and its forms post to `management.payment-methods.bank` / `management.payment-methods.paystack` / `management.store.get-banks` / `management.store.validate-bank`, none of which exist in any route file. Same category as 3.6 toggle-mode; needs no rebuild.
- The store settings "Bank Accounts" card is only a read-only list (see correction below), so there is no hidden CRUD UI the audit missed.

## Corrections

### C1 — 3.4 is a dead controller, not something "a user could do"
- **Feature:** 3.4 Store-level bank accounts (StoreBankController CRUD + primary)
- **Field:** what_user_can_do / legacy reachability
- **Was:** "From a store's settings, add bank accounts bound to the store … Update details, Set Primary, delete … The store settings page also renders the store's bank accounts card."
- **Should be:** Route/controller-only dead UI. No view or JS anywhere under `resources/` or `public/` references `management.stores.banks.store|update|primary|destroy` (verified by exhaustive grep). `stores/settings.blade.php` renders only a read-only list of `$store->banks`, and that relation is `hasMany(StoreBank::class, 'business_id', 'business_id')` — i.e. every store shows the same business-wide bank list. Legacy's live bank UI is: the business bank ledger on Payment Settings (3.1) and assign/remove of an existing bank to a store via the store settings modal (3.5). Treat 3.4 exactly like 3.6 (dead UI, status `missing`/`missing`, fold rebuild into 3.1/3.5). The audit's "All mutations log request/validation/DB detail" is also only true for `store()`; `update`, `destroy`, `setPrimary` do no logging.

### C2 — 1.2: legacy had no invoice block at all
- **Feature:** 1.2 Transaction detail with order/invoice context
- **Field:** what_user_can_do / "Missing" list
- **Was:** "Invoice-linked transactions show the invoice number instead of the order block" and missing "… invoice items …".
- **Should be:** The legacy detail view has no invoice section whatsoever — `@if($transaction->order)` is the only associated-record block; `invoice.store` / `invoice.items` are eager-loaded but never rendered. Invoice payments show only the Transaction Record (plus slip/balance/reason panels, if any). The new API omitting invoice line items is therefore not a legacy-parity gap; the SPA already displaying `invoice` number exceeds legacy parity.

### C3 — 3.5: the modal's gateway Assign was broken; method-info unassign strips the wrong pivot
- **Feature:** 3.5 Per-store "Manage Payment Methods" modal
- **Field:** what_user_can_do (Assign/Remove actions)
- **Was:** "available gateways (Assign) and available bank accounts (Assign) … This is the screen that actually controls which payment methods a store's customers can use."
- **Should be:** Gateway **Assign** in this modal is a legacy no-op: the form posts `{type}` = `payment_methods.code` (`paystack`), but `PaymentSettingsController@assignStore` branches only on `'gateway'|'bank'` and still flashes "Assigned to X." Bank assign (`stores.assign-bank`) and both Removes work. Symmetrically, the method-info page's Remove link sends `type` `'gateway'|'bank'`, and `unassignStore()` maps everything except the literal `'paystack'` to the `bank_transfer` pivot — so removing Paystack from method-info silently unassigns bank transfer instead. A rebuild needs one unambiguous assign/unassign API (type by method), and must not copy either mismatch. Also relevant: `destroyPaystackKeys` deletes `store_payment_method` rows by `payment_method_id` across **all** stores platform-wide (no business filter) — a cross-tenant cascade, alongside the audit's gap #10 security quirks.

## Minor notes (not worth their own rows)

- **Gap #1 is right but understated:** `store_payment_method` has no `api_keys` column at all (migration `2026_07_12_150443` defines only `store_id`, `payment_method_id`, `is_active`, timestamps), so `CheckoutController@useStorePaystackKeys` (`$gateway?->pivot?->api_keys`) and legacy `storefront/pages/invoice-pay.blade.php` always read null. Keys live in `business_payment_method.config` (legacy controller) and `settings.api_keys` (new admin settings). Keys are unpopulatable for a business today, as the audit says.
- **Seeder gap beyond `transactions export`:** `settings payment` and `accounting reconcile` permissions exist in `SpatiePermissionSeeder` but guard no management API route — consistent with 3.x and 4.1 being missing, worth removing or wiring alongside the rebuilds.
- **Section cross-reference typo:** 1.2's "see 3.3" for restricted-staff scoping should be 2.3.
- **Legacy sidebar badge nuance (2.2):** the pending count joins `orders`, so invoice-linked pending transactions are excluded; the audit's description is otherwise exact (Cache::remember 15 s, restricted-staff scoping, amber badge in `components/sidebar.blade.php`).
- **SPA list filter nuance (1.1):** the new search input has no debounce (Filter button submit) versus legacy's 300 ms live search; the six status values in `TransactionStatus` match the SPA's raw list, so only labels/debounce are lost.
