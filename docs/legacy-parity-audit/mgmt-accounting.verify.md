# mgmt-accounting — adversarial verification of `mgmt-accounting.md`

**Method:** re-enumerated all 30 legacy Blade files under `resources/views/management/accounting/**` and mapped each to an audit entry; re-read all 9 legacy Accounting controllers + `StoreBankController` + the accounting route blocks in `routes/v1/management.php` (lines 308–398, 143–146); re-read the new API (`routes/api/v1/management.php` 143–162, `Api\V1\Management\AccountingController`, `LedgerReportService`, `LedgerPostingService`) and the 7 SPA views, `router/index.ts`, `api/endpoints.ts`, `AppLayout.vue`.

**Verdict:** coverage is complete — every legacy view, route and controller action in scope is accounted for, and **no feature entry is missing**. The feature-level statuses (exists/partial/missing) are all correct on re-check: the new API really has zero mutating `/management/accounting/**` routes, all 9 reports+report views exist in the SPA, and reconciliation/settings/StoreBank are absent from router, nav and API. The findings below are factual corrections inside covered entries (one is material).

---

## Material correction

**1. `JournalEntry::STATUS_VOID` is NOT dead — reversal/void/refund write it.** The audit says twice that nothing can set `void` (3.4: "note `JournalEntry::STATUS_VOID` exists in the model but no legacy action ever sets it"; gap 7a: "`JournalEntry::STATUS_VOID` is never written"). That is wrong. `LedgerPostingService::reverseEntry()` ends with:

```php
// app/Services/Accounting/LedgerPostingService.php:653
$entry->update(['status' => JournalEntry::STATUS_VOID, 'voided_at' => now(), 'voided_by' => $userId]);
$reversal->update(['reversal_of_id' => $entry->id]);
```

Callers: journal reverse (`JournalController.php:223`), expense void (`ExpenseController.php:129`), bill void (`BillController.php:223`) and payment refunds (`LedgerPostingService.php:404–407`). Consequences for the build:

- The journal list `void` filter and the journal detail **Void** status are live; a business that reversed entries in legacy will have void rows. The new SPA filter `void` is therefore correct parity, not dead vocabulary.
- Reversal is a two-part transition: *create contra entry* **and** *mark the original void*. Because every report aggregates `journal_entries.status = posted` (`LedgerReportService` lines 195, 216, 225, 360, 415, 434, 469), the voided original drops out of the books and only the contra entry counts. The audit's model (reversal only "posts a contra entry") would double-count if implemented literally; expense void / bill void carry the same side effect on their linked journal entries.

## Numeric corrections

**2. New API is 16 GET endpoints, not 18.** `routes/api/v1/management.php`: 7 core (`dashboard`, `accounts`, `journal`, `journal/{entry}`, `expenses`, `suppliers`, `bills`) + 9 reports = 16. No other accounting routes exist (grep over both new route files).

**3. Mutating-route count is understated.** Actual legacy accounting mutating routes: 3 accounts (store/update/toggle) + 4 journal (store/post/reverse/destroy) + 3 expenses (store/void/destroy) + 3 suppliers + 3 bills (store/payments/void) + 5 settings (mappings, opening balances, period close, period reopen, year close) + 6 reconciliation (store, auto-match, match, unmatch, ignore, complete) = **27**, plus 4 `stores.banks.*` = 31. The audit's "~20"/"Twenty legacy mutating routes" undercounts by 7–11. The conclusion (zero exist in the new stack) is unaffected.

## Corrections within covered entries

**4. Bills list (6.1) — legacy UI had no from/to date filter.** The audit lists "from/to issue dates" under what the legacy user could do. `BillController@index` accepts `from`/`to`, but `bills/index.blade.php` renders only `q`, `supplier`, `status`, Filter and Clear — no date inputs. Required parity is q/supplier/status only.

**5. Journal detail (3.2) — two "missing" items are not legacy capabilities.** `JournalController@show` eager-loads `lines.store` but `journal/show.blade.php` never renders it ("store attribution per line" was never visible), and legacy has no link from an original entry to its reversing entry — only the reversal entry shows a `reversalOf` banner ("Reversal of …"). Drop both from the required-missing list; keep `reference`, fiscal period, posted-by/at, and the reversal-of banner.

**6. Store bank accounts (10.1) — capability is route-level only.** Verified via route-name and `/banks` greps over `resources/views/`: no Blade view posts to `stores.banks.store/update/primary/destroy`; `management/stores/settings.blade.php` and `tabs/settings.blade.php` only *list* banks. The shipped add-bank UI (`stores/payment-methods.blade.php`) posts to `management.payment-methods.*`, whose routes no longer exist in this repo (orphaned view), and the live payment-settings flow (`PaymentSettingsController@storeBankAccount/updateBankAccount/destroyBankAccount`, also StoreBank rows) belongs to the payments domain. The new stack's absence is still real (`missing`), but the legacy add/edit/set-primary/delete UX never shipped through StoreBankController.

**7. Minor wording:** (a) dashboard 1.1 — only the "Financial reports" quick action is permission-gated in `dashboard.blade.php`; the other four links are not `@can`-wrapped. (b) Accounts create/edit (2.2) — `ChartOfAccountsController@validateAccount` accepts and stores a nullable `subtype` (max 40; the account list renders it) although the Blade form exposes no subtype input, so a rebuilt form should keep the field optional rather than drop the column. (c) `AccountingSettingsController@index` renders the Fiscal Years card inside `@can('accounting close')` but the GET page itself is behind `accounting view`.

## Boundary items (no action claimed)

- `stores/{store}/assign-bank` / `remove-bank` (`StoreSettingsController`, routes 133–134) and `/payment-settings/bank-accounts*` (`PaymentSettingsController`) also read/write `store_banks`; both sit outside the controllers named in this domain's scope — flag for the stores/finance audits rather than padding this one.
- Reconciliation import (`BankReconciliationController@store`) accepts `opening_balance`, but the import modal has no such input, so it is not a legacy user capability.
- Verified unchanged/correct: all 9 report CSV exports + 3 PDF templates (`reports/pdf/{trial-balance,profit-and-loss,balance-sheet}.blade.php`), the 20 mapping keys, opening-balance one-time guard, period list (24)/year list (6), reconciliation candidate limit (100) and ±7-day auto-match, aging payload fields (`due_date`/`total`/`paid` unrevealed in the SPA), POS-style 3-dp bill quantity, and the supplier "cannot delete with bills" guard.
