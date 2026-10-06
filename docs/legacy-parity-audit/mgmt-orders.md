# mgmt-orders — Legacy → New Stack Feature Inventory

**Domain:** Order fulfilment (audience: business)
**Legacy source of truth:** `storify-api` @ `routes/v1/management.php` + `app/Http/Controllers/Management/{Order,Invoice,Dispatches}Controller.php` + `resources/views/management/{orders,invoices,dispatches}/**`
**New stack:** `storify-api` (`routes/api/v1/management.php`, `app/Http/Controllers/Api/V1/Management/**`) + `storify-management` (`src/router/index.ts`, `src/views/**`, `src/api/endpoints.ts`)
**Status vocabulary:** `exists` = fully usable; `partial` = present but missing actions/fields/validations; `missing` = absent.

---

## Executive summary

The new management SPA ships only a **read-mostly orders list + order detail** and a generic status dropdown. Everything that makes order fulfilment work day-to-day in legacy — the guarded, side-effecting transitions (accept / process / dispatch with driver assignment / deliver / complete), cancel-with-reason, return-with-stock-restoration, order editing, order status emails, the **entire invoices module**, and the **dispatches screen** — is absent from the new API and SPA.

| Area | Legacy features | exists | partial | missing |
|---|---|---|---|---|
| Order list & detail | 7 | 0 | 3 | 4 |
| Order status transitions & actions | 9 | 0 | 1 | 8 |
| Order editing / deletion | 2 | 0 | 1 | 1 |
| Notifications (order) | 1 | 0 | 0 | 1 |
| Invoices | 9 | 0 | 0 | 9 |
| Dispatches | 1 | 0 | 0 | 1 |
| Refunds (cross-domain) | 1 | 0 | 1 | 0 |

**Headline numbers:** 0 of 9 order status actions have any new API or SPA implementation; 0 of 9 invoice features exist; 0 of 1 dispatch features exist.

---

## 1. Orders list and detail

### 1.1 Order list with filters, search and pagination — `partial`
- **What the user could do (legacy):** Paginated (20/page) list of all business orders with metric cards (Total / Pending / Dispatched / Delivered), free-text search over order number, customer first/last name and email, plus filters for **status, store, source (Online Store `checkout` vs POS `pos`), date-from and date-to**, with a "Clear" link when any filter is active. Columns: order number (+POS badge), customer name and phone (or walk-in meta), store, item count, total with payment-method label and **"Split · N legs"** indicator, status badge with **remaining-balance** note, date. Legacy also computes 9 status counters in the controller (only 4 are rendered).
- **Legacy route:** `GET /management/orders` (`management.orders.index`); `GET /management/stores/{store}/orders` (`management.stores.orders`) — see 1.2
- **Legacy controller:** `Management\OrderController@index`
- **Legacy views:** `resources/views/management/orders/index.blade.php`
- **New API:** `GET /api/v1/management/orders` → `Api\V1\Management\OrderController@index` — supports `store_id`, `status`, `from`, `to`, `q`, `per_page`; returns order_number, customer, store, source, total, amount_paid, remaining, status, payment_status, created_at.
- **New SPA:** `src/views/OrdersView.vue` (route `/orders`), `ordersApi.index`
- **Missing:** `source` filter; store filter is supported by the API but has **no UI control**; metric cards/stats (needs an API stats block or a second call); item-count column; payment-method label; part-paid remaining indicator; split-payment legs indicator; POS badge; walk-in customer phone from `meta`; "Clear filters" affordance; human-readable status labels in the dropdown (raw lowercase values are shown). API list payload also omits items count, payment method and split-leg count.
- **Side effects:** none.
- **Effort:** S — **Priority:** P0

### 1.2 Store-scoped order list — `partial`
- **What the user could do (legacy):** Open `/management/stores/{store}/orders` to see the same order list pre-filtered to one store (route sets `store_id` then re-dispatches `index`).
- **Legacy route:** `GET /management/stores/{store}/orders` (`management.stores.orders`)
- **Legacy controller:** closure → `Management\OrderController@index`
- **Legacy views:** reuses `management/orders/index.blade.php`
- **New API:** `GET /api/v1/management/orders?store_id={id}` already filters correctly.
- **New SPA:** none — `StoresView.vue` shows an `orders_count` column only; no per-store order list or tab.
- **Missing:** a UI entry point (store detail orders tab or store filter pre-seeded from the store row). Legacy route note: it merges `store_id => $store->store_id` (the store **code** string) into the request and the index then compares it to the numeric `store_id` column — a latent legacy bug the new API does not have.
- **Effort:** S — **Priority:** P2

### 1.3 Order metric cards on the list — `missing`
- **What the user could do (legacy):** See Total / Pending / Dispatched / Delivered counts at the top of the order list (computed across the whole business, not the active filter).
- **Legacy route/controller/views:** as 1.1 (`OrderController@index` `$stats`, `index.blade.php` metric cards)
- **New API:** no stats field in the orders index response.
- **New SPA:** none.
- **Missing:** all four metric cards (and the other five legacy counters — accepted, processing, completed, cancelled, returned — which were computed but unused).
- **Effort:** S — **Priority:** P1

### 1.4 Order detail screen — `partial`
- **What the user could do (legacy):** Open an order and see: page header with status badge and contextual action buttons; **key metrics** (Total, Amount Paid, Remaining, item count, date/time); **Items** table with product image, name, qty × unit price and line subtotal; **Order Details** (source + POS badge, Processed-By staff name/email, payment method, subtotal, shipping, service charge w/ name, tax); **Customer** (name, email, phone, or walk-in `meta` name/phone); **Delivery** (address or area/state, delivery route and fee); **Bank Account** proof-of-payment panel (bank, account number, account name, verified badge, via `transaction.storeBank`); **Delivery Tracking** panel (delivery status, tracking number, driver, ETA, actual delivery, notes, return reason); **Transactions** table with links to transaction detail; **Notes**; **Activity** timeline (who did what, when, via `ActivityLog`).
- **Legacy route:** `GET /management/orders/{order}` (`management.orders.show`)
- **Legacy controller:** `Management\OrderController@show`
- **Legacy views:** `resources/views/management/orders/show.blade.php`
- **New API:** `GET /api/v1/management/orders/{order}` (bound by `order_number`) → `Api\V1\Management\OrderController@show`; returns items, subtotal/shipping/tax/service charge, notes, transactions (reference, amount, status, method, paid_at) and delivery address.
- **New SPA:** `src/views/OrderDetailView.vue` (route `/orders/:orderNumber`), `ordersApi.show`
- **Missing:** Product images and product codes on items; source + POS badge; Processed-By staff attribution; store-level detail; service-charge name; Bank Account panel; Delivery Tracking/delivery record panel; Activity timeline; Notes display (the API returns `notes` but the SPA never renders it); delivery route/area fallback when there is no `DeliveryAddress`; per-item `is_digital` is rendered but no digital download state. API payload omits `delivery`, `activity`, `staff`, `deliveryRoute`, `storeBank`, and the SPA has no "edit order" entry point.
- **Effort:** M — **Priority:** P0

### 1.5 Order activity timeline — `missing`
- **What the user could do (legacy):** See every fulfilment action against the order (accepted, processing, dispatched, delivered, completed, cancelled, returned) with actor name/initial and relative time, sourced from `ActivityLog` (subject `Order`).
- **Legacy views:** `orders/show.blade.php` "Activity" card; written by `OrderController::logActivity`
- **New API:** no activity/audit endpoint in the management API; mutations do not write `ActivityLog` rows.
- **New SPA:** none.
- **Missing:** activity data in the order payload + UI card; and activity-log writes on every transition.
- **Effort:** M — **Priority:** P2

### 1.6 Delivery tracking panel on the order — `missing`
- **What the user could do (legacy):** See, within the order, the linked `OrderDelivery` record: status, tracking number, driver name/phone, estimated delivery, actual delivery, delivery notes, and (after a return) the return reason banner. Populated by the legacy dispatch action.
- **Legacy views:** `orders/show.blade.php` "Delivery Tracking" card (uses `$order->delivery`)
- **New API:** order payload has no `delivery`.
- **New SPA:** none.
- **Missing:** payload relation + UI, and everything that creates/updates the record (see 2.3, 2.4, 2.6).
- **Effort:** M — **Priority:** P1

### 1.7 Bank-account / proof-of-payment panel — `missing`
- **What the user could do (legacy):** When the order's transaction is a manual bank transfer, see the receiving store bank account (bank name, account number, account name, verified badge) next to the payment so staff can match the transfer.
- **Legacy views:** `orders/show.blade.php` "Bank Account" card (`$tx->storeBank`)
- **New API:** transactions payload has no `storeBank`; order payload has no transaction bank.
- **New SPA:** none (TransactionsView shows order/invoice links but no bank account).
- **Missing:** storeBank data + UI.
- **Effort:** S — **Priority:** P2

---

## 2. Status transitions and fulfilment actions

Legacy exposes a guarded action per state, each with side effects. The new API exposes **one unguarded `PUT /orders/{order}/status`** and the SPA renders a raw dropdown. Note: legacy also has a free-form `PATCH /orders/{order}/status` (`updateStatus`) that sets any status with no guard, no side effects and no email — that endpoint is the only one the new stack ported. Judgement: the free-form status setter is fine as an admin escape hatch, but it must not replace the guarded actions below.

### 2.1 Accept order — `missing`
- **What the user could do (legacy):** From a `pending` order, open an Accept confirmation modal (order number, total, item count) and accept → `accepted`. **Blocked by a "Payment Pending" warning modal** when the order's transaction is still `pending`, which links to the transaction to confirm payment first. Writes an activity log and emails customer + store owner + platform admin.
- **Legacy route:** `POST /management/orders/{order}/accept` (`management.orders.accept`)
- **Legacy controller:** `Management\OrderController@acceptOrder` (guard `status === pending`; `notifyOrderUpdate`)
- **Legacy views:** `orders/show.blade.php` accept modal + payment-warning modal
- **New API:** none — only the generic status PUT.
- **New SPA:** the OrderDetail status `<select>` can set `accepted` with no guard.
- **Missing:** endpoint, guard, activity log, email trigger, confirmation modal, pending-payment warning modal (feature 2.10).
- **Effort:** S — **Priority:** P0

### 2.2 Move to processing — `missing`
- **What the user could do (legacy):** Confirmation modal moves `accepted` → `processing`; activity log + status email.
- **Legacy route:** `POST /management/orders/{order}/process`
- **Legacy controller:** `OrderController@processOrder`
- **Legacy views:** `orders/show.blade.php` process modal
- **New API/SPA:** none (raw status set only).
- **Missing:** endpoint, guard, side effects.
- **Effort:** S — **Priority:** P0

### 2.3 Dispatch order (driver/agent assignment + delivery record) — `missing`
- **What the user could do (legacy):** From `processing`, open a Dispatch modal that shows order summary and lets staff **assign a Delivery Agent from a dropdown of active users with the "Delivery Agent" role** (auto-filling driver name/phone), or type a driver name, driver phone, and delivery notes. Submitting transitions `processing` → `dispatched` in a DB transaction, creates the `OrderDelivery` record (status `assigned`, route, driver, notes, ETA, created_by), writes an activity log, and emails customer/store owner/admin.
- **Legacy route:** `POST /management/orders/{order}/dispatch` (`management.orders.dispatch`)
- **Legacy controller:** `OrderController@dispatchOrder`; agent list supplied by `OrderController@show` (`$deliveryAgents`)
- **Legacy views:** `orders/show.blade.php` dispatch modal
- **New API:** none; no delivery-agent list endpoint (StaffController has no role filter).
- **New SPA:** none.
- **Missing:** endpoint with `driver_name`, `driver_phone`, `tracking_number`, `delivery_notes`, `estimated_delivery_at` validation; `OrderDelivery` creation; delivery-agent picker; modal. Legacy quirk to fix while porting: `tracking_number` is validated but **never persisted** to `OrderDelivery`, and `delivery_agent_id` is posted but ignored (only used to prefill driver fields in Alpine).
- **Effort:** M — **Priority:** P0

### 2.4 Mark delivered — `missing`
- **What the user could do (legacy):** Confirmation modal moves `dispatched` → `delivered`, sets the delivery record to `delivered` with `actual_delivery_at = now()`, writes activity, emails.
- **Legacy route:** `POST /management/orders/{order}/deliver`
- **Legacy controller:** `OrderController@deliverOrder`
- **Legacy views:** `orders/show.blade.php` deliver modal
- **New API/SPA:** none.
- **Missing:** endpoint, delivery-record update, side effects.
- **Effort:** S — **Priority:** P0

### 2.5 Complete order — `missing`
- **What the user could do (legacy):** Confirmation modal moves `delivered` → `completed` (terminal), activity log + email.
- **Legacy route:** `POST /management/orders/{order}/complete`
- **Legacy controller:** `OrderController@completeOrder`
- **Legacy views:** `orders/show.blade.php` complete modal
- **New API/SPA:** none.
- **Missing:** endpoint, guard, side effects.
- **Effort:** S — **Priority:** P0

### 2.6 Cancel order with reason — `missing`
- **What the user could do (legacy):** From `pending` or `accepted` only, open a Cancel modal with a 500-char optional **reason**; the reason is appended to the order notes ("Cancellation reason: …"), an activity log is written and the customer/store/admin emails are sent. Earlier states are protected: cancel is refused for processing/dispatched/delivered/completed/returned.
- **Legacy route:** `POST /management/orders/{order}/cancel`
- **Legacy controller:** `OrderController@cancelOrder`
- **Legacy views:** `orders/show.blade.php` cancel modal
- **New API/SPA:** none — the SPA dropdown can set `cancelled` at any time, with no reason capture and no stock/payment handling.
- **Missing:** endpoint with reason, state guard, notes append, activity log, email. Stock is **not** restored on cancel in legacy either (only on return) — that behaviour should be reviewed while porting.
- **Effort:** S — **Priority:** P0

### 2.7 Return order with stock restoration — `missing`
- **What the user could do (legacy):** From `delivered` or `completed` only, open a Return modal (item count to restore, optional reason) and process a return. In one DB transaction: status → `returned`; **stock is added back to the store's `StockLocation` via `StockLedgerService::recordAddition`** for every non-digital line; the delivery record is set to `returned` with `return_reason`; an activity log is written; customer/store/admin emails are sent.
- **Legacy route:** `POST /management/orders/{order}/return` (`management.orders.return`)
- **Legacy controller:** `OrderController@returnOrder`
- **Legacy views:** `orders/show.blade.php` return modal
- **New API:** none. Worse, the new refund endpoint (`POST /transactions/{transaction}/refund`) refuses to refund delivered/completed orders with the message "Mark the order as returned first" — but there is no return action — so **refunds are a dead end** in the new stack.
- **New SPA:** none.
- **Missing:** endpoint incl. stock restore + delivery update + reason, activity log, emails, modal.
- **Effort:** M — **Priority:** P1

### 2.8 Free-form status update — `exists` (parity ok, weakly guarded)
- **What the user could do (legacy):** `PATCH /orders/{order}/status` sets any of the 8 statuses with a single `status` validation rule.
- **New API:** `PUT /api/v1/management/orders/{order}/status` (`orders status_update`), same validation.
- **New SPA:** raw `<select>` on OrderDetailView.
- **Gap/judgement:** the free-form setter exists, but as the *only* way to change status it bypasses business rules (e.g. `pending` → `completed`), skips stock restoration and never emails. It should be retained only as a secondary control; the guarded actions above must be the primary path.
- **Effort:** S (add guards or hide when the action endpoints land) — **Priority:** P1

### 2.9 Payment status update — `partial`
- **What the user could do (legacy):** `PATCH /orders/{order}/payment` with `payment_status ∈ {pending, paid, refunded, failed, unpaid}`. Maps to transaction status (paid→CONFIRMED, refunded→REFUNDED, failed→CANCELED); with **`unpaid`** it deletes the transaction; when no transaction exists it creates a manual one using the `cash` payment method (fallback: first payment method) with currency NGN and a `MAN-` reference.
- **Legacy route:** `PATCH /management/orders/{order}/payment` (`management.orders.update-payment-status`)
- **Legacy controller:** `OrderController@updatePaymentStatus`
- **Legacy views:** `orders/edit.blade.php` payment-status select (only place exposed; the show page never surfaces it)
- **New API:** `PUT /api/v1/management/orders/{order}/payment-status` accepts `{pending, paid, refunded, failed}` only — **no `unpaid`**; updates the latest transaction or creates one **without `payment_method_id` or `currency`**; `paid_at` set only for `paid`.
- **New SPA:** `ordersApi.updatePaymentStatus` exists but **no view calls it** — the OrderDetail screen has no payment-status control.
- **Missing:** `unpaid` handling (delete/void transaction), payment-method/currency on created transactions, aligning `paid_at`, and any SPA control.
- **Effort:** S — **Priority:** P1

### 2.10 Payment-pending warning before accept — `missing`
- **What the user could do (legacy):** When the order's first transaction is still `pending`, the Accept button opens a warning modal showing transaction reference, amount and pending status with a link to "Review Payment" instead of accepting blind.
- **Legacy views:** `orders/show.blade.php` payment-warning modal
- **New API/SPA:** none.
- **Missing:** modal + block-on-pending logic.
- **Effort:** S — **Priority:** P1

### 2.11 Order status notification emails — `missing`
- **What the user could do (legacy):** Every guarded transition queues `OrderStatusUpdatedMail` to **the customer**, **the store owner**, and **the platform admin** (`config('mail.admin_email')`), deduplicating identical addresses, and logs success/failure. Related order mails already exist in the codebase: `OrderReceivedMail`, `NewOrderAdminMail`, `BusinessOrderNotificationMail` (sent from payment controllers), `CustomerOrderStatusUpdatedMail` (admin panel), `OrderDispatchedMail`, `OrderDeliveredMail`.
- **Legacy controller:** `OrderController::notifyOrderUpdate`
- **New API:** none of the management order mutations send any mail.
- **New SPA:** n/a.
- **Missing:** mail dispatch wired into accept/process/dispatch/deliver/complete/cancel/return; failure logging.
- **Effort:** M — **Priority:** P1

---

## 3. Order editing

### 3.1 Edit order pricing and notes — `missing`
- **What the user could do (legacy):** Edit screen with read-only order items table (product, code, price, qty, subtotal), editable **shipping fee**, **tax** and **notes**, read-only subtotal/total, and a read-only customer/delivery summary. Saving recalculates `total = subtotal + shipping + tax + service_charge` and persists.
- **Legacy route:** `GET /management/orders/{order}/edit` + `PUT /management/orders/{order}` (`management.orders.edit` / `orders.update`, permission `orders edit`)
- **Legacy controller:** `OrderController@edit` / `@update`
- **Legacy views:** `resources/views/management/orders/edit.blade.php`
- **New API:** no route, no controller method.
- **New SPA:** no edit view; OrderDetailView has no Edit button.
- **Missing:** everything — endpoint, validation (`shipping_fee`, `tax`, `notes`), server-side total recompute, edit screen. Legacy bug worth fixing on port: the edit form also renders Order Status and Payment Status selects, but `update()` only validates shipping/tax/notes, so those two controls silently do nothing.
- **Effort:** M — **Priority:** P2

### 3.2 Delete order — `partial`
- **What the user could do (legacy):** `DELETE /orders/{order}` deletes the order (hard delete, permission `orders delete`, controller additionally required `order->user_id === user->id`) and redirects to the list.
- **Legacy route:** `DELETE /management/orders/{order}`
- **Legacy controller:** `OrderController@destroy`
- **New API:** `DELETE /api/v1/management/orders/{order}` exists with a tenant/store access check.
- **New SPA:** Delete button on OrderDetailView gated on `auth.can('orders delete')`.
- **Missing / bug:** **`orders delete` is not defined in `SpatiePermissionSeeder`** (`'orders' => ['view','create','edit','status_update','refund','cancel','assign_delivery']`), so no role can ever hold it — the API route middleware will fail/deny and the SPA button never renders. Also no confirm dialog beyond `confirm()`. See "Gaps worth calling out".
- **Effort:** S — **Priority:** P2

---

## 4. Invoices (whole module)

There are **no invoice routes in the management API** and **no invoice views in the management SPA**. The only new-stack invoice code is POS-scoped (`routes/api/v1/pos.php`, `Api\V1\Pos\InvoiceController`, `storify-pos` `InvoicesTab`) which covers index/show/store/send/record-payment **per store**, and lacks management-level list/edit/PDF/mark-paid/void/delete. All nine features below are `missing` in the management stack.

### 4.1 Invoice list with status tabs, stats and search — `missing`
- **What the user could do (legacy):** Paginated invoice list with: stats row (All / Draft / Sent / Overdue / Paid counts + paid revenue); status tabs for all six statuses (`draft, sent, partial, paid, overdue, void`); free-text search over invoice number, recipient name, recipient email and customer full name; table of invoice number + issue date, customer/recipient, total, status badge, due date (red when overdue) and a View action.
- **Legacy route:** `GET /management/invoices` (`management.invoices.index`, permission `invoices view`)
- **Legacy controller:** `Management\InvoiceController@index`
- **Legacy views:** `resources/views/management/invoices/index.blade.php`
- **New API:** none (`GET /api/v1/management/invoices` does not exist).
- **New SPA:** none.
- **Missing:** all of the above.
- **Effort:** M — **Priority:** P1

### 4.2 Invoice create / edit form — `missing`
- **What the user could do (legacy):** Create an invoice, or edit one while it is still a **draft** (edit/update reject non-drafts with 403). Form: "Bill To" section with saved-customer dropdown that pre-fills recipient name/email/phone (walk-in emails blanked), manual recipient name/email/phone/address, **"Save as customer for future invoices"** checkbox (creates a `Customer` when a new recipient email is given); dynamic line-items table (description, qty, unit price, computed amount, add/remove rows); **tax rate %**, **discount (fixed ₦ or %)**; live totals (subtotal/tax/discount/total); issue date and due date (must be ≥ issue date); store select; notes; payment terms; buttons "Save as Draft" and "Save & Send". Totals are recomputed server-side (`computeInvoiceTotals`), never trusted from the client; discount is clamped so total ≥ 0; new invoices are `draft` unless `finalize` is set.
- **Legacy routes:** `GET /management/invoices/create`, `POST /management/invoices`, `GET /management/invoices/{invoice}/edit`, `PUT /management/invoices/{invoice}` (permissions `invoices view` / `invoices edit`)
- **Legacy controller:** `InvoiceController@create/store/edit/update`, `validateInvoice`, `computeInvoiceTotals`
- **Legacy views:** `resources/views/management/invoices/create.blade.php` (shared by create & edit)
- **New API/SPA:** none (POS `POST /pos/stores/{store}/invoices` is store-scoped and has no discount/tax/totals parity).
- **Missing:** everything — including draft-only edit invariant and save-as-customer.
- **Effort:** L — **Priority:** P1

### 4.3 Invoice detail / document view — `missing`
- **What the user could do (legacy):** Invoice document preview (store logo/name/address header, invoice number, Bill To, issue/due dates, items table, subtotal/tax/discount/total, terms), plus sidebar invoice details (status, number, issued, due, store, paid_at, sent_at), internal notes, customer card, and payment history with total-paid-of-total.
- **Legacy route:** `GET /management/invoices/{invoice}` (`management.invoices.show`)
- **Legacy controller:** `InvoiceController@show`
- **Legacy views:** `resources/views/management/invoices/show.blade.php`
- **New API/SPA:** none.
- **Effort:** M — **Priority:** P1

### 4.4 Invoice PDF download / print — `missing`
- **What the user could do (legacy):** "PDF" button downloads a DomPDF-rendered invoice (`{invoice_number}.pdf`) with the store header/logo, status badge, bill-to, dates, item table, totals, terms and footer — the print artefact customers receive.
- **Legacy route:** `GET /management/invoices/{invoice}/pdf` (`management.invoices.pdf`)
- **Legacy controller:** `InvoiceController@pdf` (`Barryvdh\DomPDF\Facade\Pdf`)
- **Legacy views:** `resources/views/management/invoices/pdf.blade.php`
- **New API/SPA:** none.
- **Effort:** M — **Priority:** P1

### 4.5 Send invoice & reminder email with payment link — `missing`
- **What the user could do (legacy):** "Send Invoice" (draft → `sent` + `sent_at`) and "Remind" (re-send on sent/overdue/partial) actions email the recipient (falling back to `mail.from.address`; `@walkin.local` addresses redirected), generating/reusing a 32-char `payment_token` and including a public **payment URL** (`invoice.pay.show`) in `InvoiceMail`. Failures are logged, not thrown.
- **Legacy routes:** `POST /management/invoices/{invoice}/send` (permission `invoices edit`); public pay page `GET /pay/invoice/{token}` (`routes/v1/storefront.php`)
- **Legacy controller:** `InvoiceController@send`, `sendInvoice`
- **Legacy views:** n/a (mailable)
- **New API/SPA:** none in management (POS `POST .../send` exists but store-scoped; `InvoiceMail` is reused there).
- **Missing:** send/remind endpoints, token issue, mailable wiring, send timestamps, public payment link from the management UI.
- **Effort:** M — **Priority:** P1

### 4.6 Mark invoice fully paid — `missing`
- **What the user could do (legacy):** "Mark Fully Paid" creates a confirmed `PMT-` transaction for the remaining balance (metadata: manual/mark_paid/recorded_by), sets `amount_paid`, status `paid`, `paid_at`, **credits the store balance** with before/after audit fields, and posts to the accounting ledger (`postInvoice` + `postPaymentReceived`). Refuses paid/void or zero-balance invoices.
- **Legacy route:** `POST /management/invoices/{invoice}/mark-paid` (`management.invoices.mark-paid`)
- **Legacy controller:** `InvoiceController@markPaid`
- **New API/SPA:** none.
- **Effort:** M — **Priority:** P1

### 4.7 Record (partial) payment — `missing`
- **What the user could do (legacy):** Record a manual payment: amount (≤ remaining balance, with a formatted max error), method (`gateway` / `bank_transfer` / `check`), optional note, and **current-password confirmation** (Hash::check). Creates a confirmed transaction, increments `amount_paid`, sets status `PARTIAL` or `PAID` when the balance is settled, credits the store balance, logs, and posts to the ledger.
- **Legacy route:** `POST /management/invoices/{invoice}/record-payment`
- **Legacy controller:** `InvoiceController@recordPayment`
- **Legacy views:** `invoices/show.blade.php` record-payment modal
- **New API/SPA:** none.
- **Effort:** M — **Priority:** P1

### 4.8 Void invoice — `missing`
- **What the user could do (legacy):** Void an invoice from the show page (Alpine confirm), setting status `void` and `voided_at`.
- **Legacy route:** `POST /management/invoices/{invoice}/void`
- **Legacy controller:** `InvoiceController@voidInvoice`
- **New API/SPA:** none.
- **Effort:** S — **Priority:** P2

### 4.9 Delete draft invoice — `missing`
- **What the user could do (legacy):** Delete an invoice, only permitted while status is `draft` (403 otherwise).
- **Legacy route:** `DELETE /management/invoices/{invoice}` (permission `invoices delete`)
- **Legacy controller:** `InvoiceController@destroy`
- **New API/SPA:** none.
- **Effort:** S — **Priority:** P2

---

## 5. Dispatches

### 5.1 Dispatch list with filters, stats and tracking columns — `missing`
- **What the user could do (legacy):** A business-wide dispatch board over `OrderDelivery`: metric cards (Total / Pending [pending+assigned] / In Transit [picked_up+in_transit+out_for_delivery] / Delivered Today, plus a Failed/Returned counter computed but unused); free-text search over driver name, tracking number and order number; a **Filters modal** for status (8 delivery statuses), store and date-from/to, with an active-filter count and Clear all; table columns order number (linked to order), store, driver (+phone), tracking number (mono), colour-coded delivery status badge, ETA and created date. Read-only in legacy — the controller has only `index`.
- **Legacy route:** `GET /management/dispatches` (`management.dispatches.index`, permission `orders view`)
- **Legacy controller:** `Management\DispatchesController@index`
- **Legacy views:** `resources/views/management/dispatches/index.blade.php`
- **New API:** no dispatch endpoints at all; no `OrderDelivery` resource anywhere in `routes/api/`.
- **New SPA:** no dispatches route/view; sidebar has no entry.
- **Missing:** everything (endpoint with 5 filters + stats, SPA view, nav item). Legacy itself never let management staff advance delivery statuses (`picked_up`, `in_transit`, `out_for_delivery`, `failed`, `recipient_signature`, `current_location` were never settable from this screen) — do not over-build those; just port the read model first.
- **Effort:** M — **Priority:** P1

---

## 6. Refunds

### 6.1 Refund a transaction from order context — `partial`
- **What the user could do (legacy):** `POST /management/transactions/{ref}` refunds a confirmed payment: status → refunded, store balance debited, order `amount_paid` reduced, metadata records reason/actor/before-after.
- **Legacy route:** `POST /management/transactions/{transaction:reference}/refund` (`transactions.refund`)
- **Legacy controller:** `Management\TransactionController@refundPayment`
- **New API:** `POST /api/v1/management/transactions/{transaction}/refund` → `Api\V1\Management\TransactionController@refund` — implemented (confirmed-only, invoice payments rejected, balance debit, order `amount_paid` reduction), **but it blocks refunds of delivered/completed orders with "Mark the order as returned first"** and exposes no return path.
- **New SPA:** `TransactionsView.vue` has a refund action wired to `transactionsApi.refund`.
- **Missing:** the order-return prerequisite (2.7) so the refund flow is actually reachable for delivered/completed orders; refund of invoice payments is rejected in both stacks (legacy parity).
- **Effort:** M (mostly the return-order work) — **Priority:** P1

---

## 7. Gaps worth calling out

1. **`orders delete` permission is not seeded.** `SpatiePermissionSeeder` defines `orders => [view, create, edit, status_update, refund, cancel, assign_delivery]` — no `delete` — but `routes/api/v1/management.php` wraps `DELETE orders/{order}` in `permission:orders delete`, and the SPA gates the Delete button on the same ability. Result: the endpoint is unreachable / errors for every role, including Business Owner. Either add the permission or drop the middleware.
2. **The generic status endpoint bypasses every business rule.** `PUT /orders/{order}/status` accepts any of the 8 statuses from any current status; the SPA renders it as a raw dropdown. A user can jump `pending` → `completed`, or `delivered` → `pending`, with no stock movement, no delivery record, no activity log and no email. Legacy's similarly permissive endpoint existed but was never the primary UI — the guarded POST actions were. Ship the guarded actions (2.1–2.7) and restrict or remove this one.
3. **Refunds are a dead end.** The new refund endpoint tells the operator to "mark the order as returned first", but no return action exists in the new API or SPA, and stock restoration on return exists only in legacy (`StockLedgerService::recordAddition`). This is the one place where an existing new feature is unusable because a missing feature blocks it.
4. **No `OrderDelivery` can ever be created.** Dispatch record creation lives only in the legacy `dispatchOrder`. Until 2.3 lands, any dispatch screen built will show an empty table — so 2.3 should be built before or with 5.1.
5. **The entire invoices module is absent from management.** Legacy has 11 invoice routes, 4 views (incl. PDF), an email with a public payment link, password-confirmed manual payments, store-balance crediting and ledger posting. The new stack has store-scoped POS invoice APIs only; a business cannot issue, send, print or settle a single invoice from the management app.
6. **Zero order/invoice email is sent from the new management stack.** `OrderStatusUpdatedMail` and `InvoiceMail` already exist in the repo and are used elsewhere; only the wiring is missing.
7. **Legacy bugs to fix while porting, not reproduce:** (a) `tracking_number` is validated in `dispatchOrder` but never written to `OrderDelivery`; (b) `delivery_agent_id` is posted by the dispatch modal but never validated or stored; (c) the order edit form's Status and Payment Status selects post values `update()` silently discards; (d) `stores/{store}/orders` filters `store_id` with the store *code* string; (e) the `unpaid` payment status deletes the transaction outright.
8. **Legacy had no order export, no order print view, and no bulk actions on the orders list** — do not invent them; the only export/print artefact in this domain is the invoice PDF.
9. **Dispatches was a read-only list in legacy.** Delivery statuses beyond what order actions produce were never settable from management; the port should start as a read model.
10. **New API payloads are thinner than the screens need.** The order `show` response omits `delivery`, `activity`, `staff`, `deliveryRoute`, `storeBank`, product images/codes; the list response omits item count, payment method and split-payment leg count — all of which legacy rendered.

---

## Appendix — Legacy status transitions (source: `Management\OrderController`)

| Action | From → To | Extra input | Stock | Delivery record | Email | Activity |
|---|---|---|---|---|---|---|
| accept | pending → accepted | — (blocked if tx pending) | — | — | yes | yes |
| process | accepted → processing | — | — | — | yes | yes |
| dispatch | processing → dispatched | driver name/phone, notes, ETA, tracking (validated, not saved) | none (already reduced at checkout) | create `assigned` | yes | yes |
| deliver | dispatched → delivered | — | — | status `delivered`, `actual_delivery_at` | yes | yes |
| complete | delivered → completed | — | — | — | yes | yes |
| cancel | pending/accepted → cancelled | reason (appended to notes) | none | — | yes | yes |
| return | delivered/completed → returned | reason | restore per item via ledger | status `returned` + reason | yes | yes |
| updateStatus | any → any | status | — | — | — | — |
| updatePaymentStatus | n/a (payment) | pending/paid/refunded/failed/unpaid | — | — | — | — |

## Appendix — Emails triggered in this domain (legacy)

| Moment | Mailable | Recipients |
|---|---|---|
| Every guarded order transition | `OrderStatusUpdatedMail` | customer, store owner, platform admin (`mail.admin_email`) |
| Invoice send / remind | `InvoiceMail` (with public payment URL) | recipient_email → customer email → `mail.from.address` |
| Invoice paid online (public link) | `InvoicePaymentReceiptMail` | payer (`Storefront\InvoicePaymentController`) — storefront domain, needed for full parity |
| Order placed / paid (checkout) | `OrderReceivedMail`, `NewOrderAdminMail`, `BusinessOrderNotificationMail` | customer, admin, store owner — payment controllers, outside mgmt-orders scope but referenced |

---

*Method: legacy controllers/routes/Blade read in full; new API routes, `Api\V1\Management\OrderController`/`TransactionController`, management SPA router, `endpoints.ts`, `OrdersView.vue`, `OrderDetailView.vue` read in full; invoice/dispatch presence verified by grep across `routes/api/**` and `storify-management/src/**`.*
