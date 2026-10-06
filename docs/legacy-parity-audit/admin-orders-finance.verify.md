# Verification pass — admin-orders-finance (audience: admin)

**Verifier method:** re-read every in-scope route in `routes/v1/admin_dashboard.php` (orders, shop4me, transactions, subscriptions/plans, coupons, accounting, vats, payment-methods, bank-accounts groups), all ten legacy controllers in full (`Admin\{Order,Shop4meOrder,Transaction,Subscription,SubscriptionPlan,Coupon,Accounting,Vat,PaymentMethod,BankAccount}Controller`), all 21 in-scope Blade views (`order_management/{index,show,edit,shop4me_orders}`, `transactions/{index,show}`, `subscriptions/index`, `subscription_fee/index`, `coupons/{index,form}`, `accounting/{index,accounts,journal,journal-show,reports,settings}`, `VAT/index`, `payment_methods/index`, `bank-accounts/{index,create,edit}`), the supporting `Order`/`Transaction`/`Coupon`/`Vat` models, `OrderStatus`/`PaymentStatus`/`TransactionStatus` enums, `UpdateOrderRequest`/`UpdateTransactionStatusRequest`/`VatRequest`, the new API (`routes/api/v1/admin.php`, `Api\V1\Admin\{Transaction,Coupon,Search}Controller`), and the admin SPA (`src/router/index.ts`, `src/api/endpoints.ts`, `src/views/{TransactionsView,CouponsView}.vue`, `src/layouts/AdminLayout.vue`).

**Coverage verdict:** view and feature coverage is essentially complete — every Blade file in scope maps to a feature entry, and every "missing" verdict for orders, subscriptions, platform accounting, VAT, payment methods and bank accounts is confirmed against `routes/api/v1/admin.php` and the admin router (the management order/accounting endpoints are genuinely gated by `token.audience:management`). The audit is wrong on several specific claims that affect rebuild guidance (below), and its route enumeration omits one dead legacy route.

---

## Corrections

### 1. Caveat #5(a) is wrong — `Order` **does** have a `payment_status` accessor, and the main list badge works

The audit claims "Order no longer has a `payment_status` column/accessor, so the legacy admin order list's payment badge effectively reports 'Failed' for everything". `App\Models\Order` defines `getPaymentStatusAttribute()` (`app/Models/Order.php:174-215`) which derives unpaid / pending / partial / paid / refunded from the order's transactions. `order_management/index.blade.php:103-118` reads it, detects the `PaymentStatus` enum via `instanceof`, and renders the correct derived label — the badge does **not** report "Failed for everything". Only the **Shop4Me list** is broken: it does `@switch ($order->payment_status)` against string cases (`shop4me_orders.blade.php:88-101`), and an enum never `==` a string, so every row falls through to `@default` → "Unpaid" (that half of the audit's claim is right). Also note the accessor can return `pending`/`partial`, which neither filter dropdown offers. The rebuild guidance ("derive payment status from transactions") stays valid; the stated reason does not.

### 2. §6.2 — VAT zero-rate toggle does **not** deactivate the previous rate

`VatController@toggle` (`app/Http/Controllers/Admin/VatController.php:74-85`) only creates the new 0% active record. There is no model event/observer (`app/Models/Vat.php` has none; no observers registered) that deactivates other rows. The single-active rule is enforced only in `store()` (deactivate all, then create with `active = true`) and `update()` (when `active = true`). So the "Disable VAT" action leaves the previous rate `active = true` — multiple active rows — while `Vat::current()` picks the newest by `effective_at`/`id`, so POS tax still effectively becomes 0%. The new stack must decide whether to preserve this quirk or enforce single-active on every path; the audit currently describes behaviour the legacy does not have.

### 3. §1.3 / caveat #5(b) — `payment_status` **is** in `UpdateOrderRequest`; the form offers values that fail validation

The audit says the edit form's payment status select is "no-change / unpaid / paid / refunded / failed" and (caveat 5b) "not in `UpdateOrderRequest`". Two inaccuracies:

- `UpdateOrderRequest::rules()` **does** validate `payment_status` (`sometimes|in:unpaid,paid,refunded,failed`) — it is not rejected by the request. It is a no-op only at the model level (`Order::$fillable` has no `payment_status`, and no column exists), so mass assignment silently discards it. The customer/delivery inputs (`customer_name`, `customer_phone`, `delivery_address`, `delivery_city`, `delivery_state`) are the ones neither validated nor persisted — that part is right.
- The select renders **all six** `PaymentStatus` cases plus "— No change —" (`edit.blade.php:124-130`), i.e. it includes Pending and Partially Paid, which the validator rejects → choosing either returns a 422. The audit's field list understates this and misses the validation conflict. The new stack should expose only the four accepted transitions.

### 4. §1.6 — the order delete is a **soft** delete; nothing cascades

`Order` uses `SoftDeletes` (`app/Models/Order.php:17`), so `$order->delete()` in `Admin\OrderController@destroy` is a soft delete. The audit's "order soft/hard delete; … order items cascade" is misleading: order items and transactions are **not** deleted or cascaded — the order row keeps `deleted_at` and children remain. The `ActivityLog` row is written before the soft delete.

### 5. §8.1 — route list omits `GET /office/bank-accounts/{bankAccount}` (dead in legacy)

`Route::resource('bank-accounts', BankAccountController::class)` (`routes/v1/admin_dashboard.php:190`) is not `->except(...)`, so it registers `show` as well, and `BankAccountController@show` returns `view('admin.bank-accounts.show', ...)`. No such Blade file exists (`resources/views/admin/bank-accounts/` contains only `index`, `create`, `edit`), so the URL raises a View-not-found error. It is a dead legacy surface — no rebuild warranted — but the audit's route enumeration is incomplete.

### 6. §2.2 — "all fields present" overstates the transaction detail parity

Legacy `transactions/show.blade.php` displays the linked order's **store name** (line 68) and the **created** timestamp (line 48). The new admin API `show` payload has `created_at` (but the SPA drawer never renders it) and no store at all (`order` is just the order number; the controller loads `invoice.store`, not `order.store`). The rest of the field list does hold. Qualify the "none material / all fields present" claim.

### 7. §2.1 — "status select with all 7 statuses" is wrong

`TransactionStatus` has **six** cases (pending, paid, confirmed, refunded, refund_pending, cancelled) and the legacy filter renders six. The SPA select (`TransactionsView.vue:79`) lists **seven** values, including `'failed'`, which is not a `TransactionStatus` value and can never match a row.

### 8. Summary table vs §2.3 — internal status inconsistency

The summary table counts Transactions as 2 `exists` + 1 `partial`, and the executive summary says "the status override action is missing", but the §2.3 feature heading itself says `missing` (and the "20 of 26" headline follows the heading). Per the stated vocabulary, `partial` is the better fit (the hosting transaction detail screen exists; only the mutation endpoint and SPA action are absent). Fix either the §2.3 heading or the table/exec summary so they agree.

---

## Route the audit omitted (dead — do not rebuild)

`GET /office/bank-accounts/{bankAccount}` (`admin.bank-accounts.show`) has no feature entry anywhere in the audit. Verified dead in legacy: `BankAccountController@show` renders `admin.bank-accounts.show`, which does not exist, so the route 500s. Recorded for route-coverage completeness only; it is not a user-facing capability and must not be ported as a feature.

---

## Smaller notes (no audit text change demanded)

- Verified: legacy admin sidebar does render a live pending-orders count badge (`admin/components/sidebar.blade.php:129-135`), as §1.1 states.
- Verified: `bulk_finalized` modal in `order_management/show.blade.php` is dead — no setter exists anywhere in `app/`, `routes/` or `resources/views/` (caveat 5e is correct).
- Verified: management API order mutations exist but are audience-gated (`token.audience:management` in `routes/api/v1/management.php`), so the "not callable by admin tokens" claim holds.
- Verified: all four "already at parity" coupons sub-features by reading `Api\V1\Admin\CouponController` and `CouponsView.vue` (including code immutability on update and the `'failed'`-free coupon flow); the transactions `exists` verdicts hold apart from corrections 6 and 7.
- Legacy transaction list had a Customer column (name + email); the new SPA list shows Order/Invoice/Method instead and surfaces the customer only in the detail drawer. Acceptable but worth knowing.
- `VatController@store` forces `active = true` regardless of the modal's Active checkbox; the audit's description ("any new record … becomes active") matches the forced behaviour.
