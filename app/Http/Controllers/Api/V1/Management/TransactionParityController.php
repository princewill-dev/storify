<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Enums\InvoiceStatus;
use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Mail\PaymentConfirmedMail;
use App\Mail\PaymentRejectedMail;
use App\Mail\RefundProcessedMail;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Store;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Digital\DigitalDeliveryService;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * WS-18 — Transactions parity & payment emails.
 *
 * Registered by routes/api/v1/management/ws18-transactions.php, which reuses
 * the base method+URI pairs so this controller replaces the thin
 * TransactionController registrations (Laravel keys routes by method+URI).
 *
 * On top of the money movement the base controller already got right, this
 * carries the legacy list filters (store + date range), the store/customer
 * columns, the full detail read model (order context, customer block, balance
 * impact, payment slip, structured rejection/refund reasons), the three
 * queued payment mails, and store scoping for assigned staff.
 */
class TransactionParityController extends ApiController
{
    use ResolvesManagementContext;

    public function index(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        $transactions = $this->applyFilters($this->scopedQuery($request), $filters)
            ->with($this->listRelations())
            ->latest()
            ->paginate($filters['per_page'] ?? 20)
            ->withQueryString();

        return $this->ok(
            [
                'transactions' => $transactions->getCollection()->map(fn (Transaction $transaction) => $this->payload($transaction))->all(),
                'stores' => $this->filterStores($request),
                'statuses' => $this->statusOptions(),
            ],
            null,
            200,
            $this->paginationMeta($transactions),
        );
    }

    /**
     * Pending count consumed by the sidebar badge (WS-34 mounts it).
     */
    public function pendingCount(Request $request): JsonResponse
    {
        $count = $this->scopedQuery($request)
            ->where('status', TransactionStatus::PENDING->value)
            ->count();

        // Legacy's badge joined orders, so an invoice-linked pending payment
        // never showed up. Every pending payment still needs a human decision,
        // so both count here.
        return $this->ok(['count' => $count]);
    }

    /**
     * Filtered CSV of the same rows the list renders — resolves the seeded
     * `transactions export` permission, which legacy granted but never routed.
     */
    public function export(Request $request): StreamedResponse
    {
        $filters = $this->validatedFilters($request);

        $query = $this->applyFilters($this->scopedQuery($request), $filters)
            ->with($this->listRelations())
            ->latest();

        $filename = 'transactions-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');

            // The explicit escape keeps PHP 8.4's fputcsv deprecation away and
            // follows RFC 4180 (enclosure-only quoting).
            fputcsv($handle, [
                'Reference', 'Order / Invoice', 'Store', 'Customer', 'Payment method',
                'Amount', 'Fee', 'Net', 'Currency', 'Status', 'Date',
            ], ',', '"', '');

            $query->chunk(500, function ($rows) use ($handle) {
                foreach ($rows as $transaction) {
                    fputcsv($handle, [
                        $transaction->reference,
                        $transaction->order?->order_number ?? $transaction->invoice?->invoice_number ?? '',
                        $this->store($transaction)?->name ?? '',
                        $this->customerName($transaction) ?? '',
                        $this->paymentMethodLabel($transaction) ?? '',
                        number_format((float) $transaction->amount, 2, '.', ''),
                        $transaction->fee_kobo !== null ? number_format($transaction->fee_kobo / 100, 2, '.', '') : '',
                        $transaction->net_kobo !== null ? number_format($transaction->net_kobo / 100, 2, '.', '') : '',
                        $transaction->currency ?? '',
                        $transaction->status_label,
                        $transaction->created_at?->toDateTimeString() ?? '',
                    ], ',', '"', '');
                }
            });

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function show(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        $transaction->load([
            'order.store',
            'order.customer',
            'order.items',
            'order.staff',
            'invoice.store',
            'paymentMethod',
            'storeBank',
        ]);

        return $this->ok(['transaction' => $this->payload($transaction, detailed: true)]);
    }

    public function confirm(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        if ($transaction->status !== TransactionStatus::PENDING) {
            return $this->error('Only pending transactions can be confirmed.', 409);
        }

        DB::transaction(function () use ($transaction) {
            $transaction->update(['status' => TransactionStatus::CONFIRMED->value]);

            $store = $this->store($transaction);

            if (! $store) {
                throw new \RuntimeException('Transaction has no associated store.');
            }

            $amountInKobo = $this->amountInKobo($transaction);
            $store->lockForUpdate();
            $balanceBefore = (int) $store->balance;
            $store->creditBalance($amountInKobo);

            $transaction->update([
                'balance_updated_at' => now(),
                'store_balance_before' => $balanceBefore,
                'store_balance_after' => (int) $store->fresh()->balance,
            ]);

            $order = $transaction->order;

            if ($order) {
                $order->amount_paid = (float) $order->amount_paid + (float) $transaction->amount;

                if ($order->isFullyPaid() && $order->status === OrderStatus::PENDING) {
                    $order->status = OrderStatus::ACCEPTED;
                }

                $order->save();
            }
        });

        $transaction = $transaction->fresh();

        $ledger = app(LedgerPostingService::class);
        $ledger->safe(fn () => $ledger->postPaymentReceived($transaction, $this->user($request)->id));

        if ($transaction->order && $transaction->order->isFullyPaid()) {
            $digital = app(DigitalDeliveryService::class);
            $digital->deliverSafely($transaction->order);
        }

        $this->queuePaymentMails($transaction, 'confirmed');

        Log::info('api.management.payment_confirmed', [
            'user_id' => $this->user($request)->id,
            'transaction_id' => $transaction->id,
            'amount_kobo' => $this->amountInKobo($transaction),
        ]);

        return $this->ok(
            ['transaction' => $this->payload($transaction->load($this->detailRelations()), detailed: true)],
            'Payment confirmed. Store balance has been credited and customer has been notified.',
        );
    }

    public function reject(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        if ($transaction->status !== TransactionStatus::PENDING) {
            return $this->error('Only pending transactions can be rejected.', 409);
        }

        // Legacy made the reason optional — the customer still receives the
        // rejection mail, just without an explanation. Keeping that contract
        // rather than inventing a mandatory field.
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $reason = $data['reason'] ?? null;

        $transaction->update([
            'status' => TransactionStatus::CANCELED->value,
            'metadata' => array_merge($transaction->metadata ?? [], [
                'rejection_reason' => $reason,
                'rejected_at' => now()->toDateTimeString(),
                'rejected_by' => $this->user($request)->id,
            ]),
        ]);

        $transaction = $transaction->fresh();

        // Legacy short-circuited invoice payments before the mail block (which
        // also crashed on the missing order). Invoice rejections notify nobody.
        if ($transaction->invoice) {
            return $this->ok(
                ['transaction' => $this->payload($transaction->load($this->detailRelations()), detailed: true)],
                'Invoice payment rejected.',
            );
        }

        $this->queuePaymentMails($transaction, 'rejected', $reason);

        Log::info('api.management.payment_rejected', [
            'user_id' => $this->user($request)->id,
            'transaction_id' => $transaction->id,
            'reason' => $reason,
        ]);

        return $this->ok(
            ['transaction' => $this->payload($transaction->load($this->detailRelations()), detailed: true)],
            'Payment rejected and customer has been notified.',
        );
    }

    public function refund(Request $request, Transaction $transaction): JsonResponse
    {
        $this->authorizeTransaction($request, $transaction);

        if ($transaction->status !== TransactionStatus::CONFIRMED) {
            return $this->error('Only confirmed transactions can be refunded.', 409);
        }

        if ($transaction->invoice) {
            return $this->error('Refunds are not supported for invoice payments.', 422);
        }

        $order = $transaction->order;

        if ($order && in_array($order->status, [OrderStatus::DELIVERED, OrderStatus::COMPLETED], true)) {
            return $this->error('Cannot refund delivered/completed orders. Mark the order as returned first.', 409);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $actorId = $this->user($request)->id;
        $store = $this->store($transaction);

        if (! $store) {
            return $this->error('Transaction has no associated store.', 422);
        }

        try {
            DB::transaction(function () use ($transaction, $order, $store, $data, $actorId) {
                $amountInKobo = $this->amountInKobo($transaction);

                $store->lockForUpdate();
                $balanceBefore = (int) $store->balance;
                $store->debitBalance($amountInKobo);

                $transaction->update([
                    'status' => TransactionStatus::REFUNDED->value,
                    'balance_updated_at' => now(),
                    'metadata' => array_merge($transaction->metadata ?? [], [
                        'refund_reason' => $data['reason'],
                        'refunded_at' => now()->toDateTimeString(),
                        'refunded_by' => $actorId,
                        'refund_balance_before' => $balanceBefore,
                        'refund_balance_after' => (int) $store->fresh()->balance,
                    ]),
                ]);

                if ($order) {
                    $order->amount_paid = max(0, (float) $order->amount_paid - (float) $transaction->amount);
                    $order->save();
                }
            });
        } catch (\Throwable $e) {
            // The legacy screen surfaced a friendly naira message here; the
            // raw exception leaked through the base API.
            if (str_contains($e->getMessage(), 'Insufficient balance')) {
                $balance = (int) ($store->fresh()->balance ?? 0);

                return $this->error(
                    'Insufficient store balance to process refund. Current balance: ₦'.number_format($balance / 100, 2),
                    422,
                );
            }

            Log::error('api.management.payment_refund_failed', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return $this->error('Failed to process refund: '.$e->getMessage(), 422);
        }

        $transaction = $transaction->fresh();

        $ledger = app(LedgerPostingService::class);
        $ledger->safe(fn () => $ledger->postRefund($transaction, $actorId));

        $this->queuePaymentMails($transaction, 'refunded', $data['reason']);

        Log::info('api.management.payment_refunded', [
            'user_id' => $actorId,
            'transaction_id' => $transaction->id,
            'amount_kobo' => $this->amountInKobo($transaction),
            'reason' => $data['reason'],
        ]);

        return $this->ok(
            ['transaction' => $this->payload($transaction->load($this->detailRelations()), detailed: true)],
            'Refund processed. Store balance has been debited and customer has been notified.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            // Legacy called the search box "reference"; the SPA already used
            // "q", so both names resolve to the same filter.
            'reference' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(TransactionStatus::values())],
            'store_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        $reference = $filters['reference'] ?? $filters['q'] ?? null;

        return $query
            ->when($reference, fn ($q, $term) => $q->where('reference', 'like', '%'.$term.'%'))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['store_id'] ?? null, fn ($q, $storeId) => $q->where(function ($scope) use ($storeId) {
                $scope->whereHas('order', fn ($order) => $order->where('store_id', $storeId))
                    ->orWhereHas('invoice', fn ($invoice) => $invoice->where('store_id', $storeId));
            }))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
    }

    private function scopedQuery(Request $request): Builder
    {
        $user = $this->user($request);

        $query = Transaction::query()->where('business_id', $user->business_id);

        if ($user->isStaff()) {
            $storeIds = $this->staffStoreIds($request);

            $query->where(function ($scope) use ($storeIds) {
                $scope->whereHas('order', fn ($order) => $order->whereIn('store_id', $storeIds))
                    ->orWhereHas('invoice', fn ($invoice) => $invoice->whereIn('store_id', $storeIds));
            });
        }

        return $query;
    }

    /**
     * Stores a staff member may see transactions for.
     *
     * The legacy `isRestrictedStaff()` flag keyed on the transactions-view
     * permission — the very permission that guards these routes — so the
     * store scoping it implied could never engage. An explicit store
     * assignment is the reachable (and correct) definition of "restricted",
     * so it wins over the permission heuristic; staff with no assignments
     * fall back to their accessible stores.
     *
     * @return Collection<int, int>
     */
    private function staffStoreIds(Request $request): Collection
    {
        $user = $this->user($request);

        if ($user->assignedStores()->exists()) {
            return $user->assignedStores()->pluck('stores.id')->map(fn ($id) => (int) $id);
        }

        return $this->accessibleStoreIds($request)->map(fn ($id) => (int) $id);
    }

    private function authorizeTransaction(Request $request, Transaction $transaction): void
    {
        $user = $this->user($request);

        if ((int) $transaction->business_id !== (int) $user->business_id) {
            abort(403, 'You do not have access to this transaction.');
        }

        if (! $user->isStaff()) {
            return;
        }

        $storeId = $transaction->order?->store_id ?? $transaction->invoice?->store_id;

        if ($storeId === null || ! $this->staffStoreIds($request)->contains((int) $storeId)) {
            abort(403, 'You do not have access to this transaction.');
        }
    }

    /**
     * Queues the legacy payment mails. Only order-linked transactions have a
     * mail to send: the legacy confirm/reject blocks read `$transaction->order`
     * unguarded, so every invoice payment died inside their own catch and
     * notified nobody — that is preserved deliberately, not by accident.
     */
    private function queuePaymentMails(Transaction $transaction, string $kind, ?string $reason = null): void
    {
        $order = $transaction->order;
        $store = $this->store($transaction);

        if (! $order || ! $store) {
            return;
        }

        $customer = $order->customer;
        $owner = $store->business?->owner ?? $store->user;

        try {
            if ($kind === 'refunded') {
                // Legacy refunded the customer only; RefundProcessedMail is
                // typed to a Customer for exactly that reason.
                if ($customer?->email) {
                    Mail::to($customer->email)->queue(
                        new RefundProcessedMail($transaction, $order, $customer, $store, (string) $reason)
                    );
                }

                Log::info('api.management.refund_mail_queued', [
                    'transaction_id' => $transaction->id,
                    'customer_email' => $customer->email ?? null,
                ]);

                return;
            }

            $recipients = [];

            if ($customer?->email) {
                $recipients[$customer->email] = $customer;
            }

            if ($owner?->email && ! isset($recipients[$owner->email])) {
                $recipients[$owner->email] = $owner;
            }

            foreach ($this->adminEmails() as $email) {
                $recipients[$email] = $recipients[$email] ?? null;
            }

            foreach ($recipients as $email => $recipient) {
                $mail = $kind === 'rejected'
                    ? new PaymentRejectedMail($transaction, $order, $recipient, $store, $reason)
                    : new PaymentConfirmedMail($transaction, $order, $recipient, $store);

                Mail::to($email)->queue($mail);
            }

            Log::info('api.management.payment_mail_queued', [
                'transaction_id' => $transaction->id,
                'kind' => $kind,
                'recipients' => array_keys($recipients),
            ]);
        } catch (\Throwable $e) {
            // A mail failure must never roll back the money movement.
            Log::error('api.management.payment_mail_failed', [
                'transaction_id' => $transaction->id,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Platform admins, mirroring the KYC notification recipients.
     *
     * @return array<int, string>
     */
    private function adminEmails(): array
    {
        $emails = User::query()
            ->where('role', User::ROLE_SUPERADMIN)
            ->pluck('email')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($emails === [] && config('mail.admin_email')) {
            $emails = [config('mail.admin_email')];
        }

        return $emails;
    }

    /**
     * @return array<int, string>
     */
    private function listRelations(): array
    {
        return [
            'order:id,order_number,store_id,customer_id,meta',
            'order.store:id,name,store_id',
            'order.customer:id,first_name,last_name,email,phone',
            'invoice:id,invoice_number,store_id,recipient_name',
            'invoice.store:id,name,store_id',
            'paymentMethod',
        ];
    }

    /**
     * @return array<int, string>
     */
    private function detailRelations(): array
    {
        return [
            'order.store',
            'order.customer',
            'order.items',
            'order.staff',
            'invoice.store',
            'paymentMethod',
            'storeBank',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Transaction $transaction, bool $detailed = false): array
    {
        $store = $this->store($transaction);

        $data = [
            'id' => $transaction->id,
            'reference' => $transaction->reference,
            'amount' => (float) $transaction->amount,
            'fee' => $transaction->fee_kobo !== null ? $transaction->fee_kobo / 100 : null,
            'net' => $transaction->net_kobo !== null ? $transaction->net_kobo / 100 : null,
            'currency' => $transaction->currency,
            'status' => $transaction->status instanceof TransactionStatus ? $transaction->status->value : $transaction->status,
            'status_label' => $transaction->status_label,
            'order' => $transaction->order?->order_number,
            'invoice' => $transaction->invoice?->invoice_number,
            'store' => $store?->name,
            'store_id' => $store?->id,
            'customer' => $this->customerName($transaction),
            'payment_method' => $this->paymentMethodLabel($transaction),
            'payment_method_code' => $transaction->paymentMethod?->code,
            'has_payment_slip' => (bool) $transaction->payment_slip,
            'paid_at' => $transaction->paid_at?->toISOString(),
            'created_at' => $transaction->created_at?->toISOString(),
        ];

        if (! $detailed) {
            return $data;
        }

        $meta = $transaction->metadata ?? [];

        return [
            ...$data,
            'gateway_reference' => $transaction->gateway_reference,
            'store_balance_before' => $transaction->store_balance_before !== null ? (int) $transaction->store_balance_before : null,
            'store_balance_after' => $transaction->store_balance_after !== null ? (int) $transaction->store_balance_after : null,
            'store_balance_current' => $store ? (int) $store->balance : null,
            'balance_updated_at' => $transaction->balance_updated_at?->toISOString(),
            'bank' => $transaction->storeBank ? [
                'bank_name' => $transaction->storeBank->bank_name,
                'account_number' => $transaction->storeBank->account_number,
                'account_name' => $transaction->storeBank->account_name,
                'is_verified' => (bool) $transaction->storeBank->is_verified,
            ] : null,
            'payment_slip' => $this->slip($transaction),
            // Structured replacement for the legacy raw metadata panel, which
            // the SPA used to dump as JSON.
            'rejection' => array_key_exists('rejection_reason', $meta) ? [
                'reason' => $meta['rejection_reason'],
                'at' => $meta['rejected_at'] ?? null,
                'by' => $meta['rejected_by'] ?? null,
                'by_name' => $this->actorName($meta['rejected_by'] ?? null),
            ] : null,
            'refund' => array_key_exists('refund_reason', $meta) ? [
                'reason' => $meta['refund_reason'],
                'at' => $meta['refunded_at'] ?? null,
                'by' => $meta['refunded_by'] ?? null,
                'by_name' => $this->actorName($meta['refunded_by'] ?? null),
                'balance_before' => $meta['refund_balance_before'] ?? null,
                'balance_after' => $meta['refund_balance_after'] ?? null,
            ] : null,
            'order_context' => $transaction->order ? $this->orderContext($transaction->order) : null,
            // Legacy rendered no invoice block at all; a compact one answers
            // "which invoice is this money against" without a second screen.
            'invoice_context' => $transaction->invoice ? $this->invoiceContext($transaction->invoice) : null,
            'customer_context' => $this->customerContext($transaction->order),
            'metadata' => $meta,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function slip(Transaction $transaction): ?array
    {
        if (! $transaction->payment_slip) {
            return null;
        }

        $extension = strtolower(pathinfo($transaction->payment_slip, PATHINFO_EXTENSION));

        return [
            'path' => $transaction->payment_slip,
            'url' => Storage::disk('public')->url($transaction->payment_slip),
            'is_image' => in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true),
            'uploaded_at' => $transaction->paid_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function orderContext(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status instanceof OrderStatus ? $order->status->value : $order->status,
            'status_label' => $order->status_label,
            'items_count' => $order->items->count(),
            'subtotal' => (float) $order->subtotal,
            'shipping_fee' => (float) $order->shipping_fee,
            'tax' => (float) $order->tax,
            'service_charge' => $this->serviceCharge($order),
            'total' => (float) $order->total,
            'amount_paid' => (float) $order->amount_paid,
            'remaining' => $order->remainingBalance(),
            'source' => $order->source,
            'is_pos' => $order->isPos(),
            'staff' => $order->staff ? [
                'id' => $order->staff->id,
                'name' => $order->staff->name,
                'email' => $order->staff->email,
            ] : null,
            'store' => $order->store ? [
                'id' => $order->store->id,
                'name' => $order->store->name,
                'store_id' => $order->store->store_id,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function invoiceContext(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
            'status' => $invoice->status instanceof InvoiceStatus ? $invoice->status->value : $invoice->status,
            'status_label' => $invoice->status instanceof InvoiceStatus ? $invoice->status->label() : ucfirst((string) $invoice->status),
            'total' => (float) $invoice->total,
            'amount_paid' => (float) $invoice->amount_paid,
            'recipient_name' => $invoice->recipient_name,
            'recipient_email' => $invoice->recipient_email,
            'store' => $invoice->store ? [
                'id' => $invoice->store->id,
                'name' => $invoice->store->name,
                'store_id' => $invoice->store->store_id,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function customerContext(?Order $order): ?array
    {
        if (! $order) {
            return null;
        }

        $customer = $order->customer;
        $meta = $order->meta ?? [];
        $email = (string) $customer?->email;
        $isWalkIn = ! $customer
            || str_contains($email, 'walkin@pos.local')
            || str_contains($email, '@walkin.local');

        if (! $isWalkIn) {
            return [
                'name' => $customer->full_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'is_walk_in' => false,
            ];
        }

        // Walk-ins carry a placeholder account (`walkin@pos.local`,
        // `pos-…@walkin.local`). Legacy never rendered that address — it fell
        // back to the name/phone captured on the order.
        if (($meta['customer_name'] ?? null) !== null) {
            return [
                'name' => $meta['customer_name'],
                'email' => null,
                'phone' => $meta['customer_phone'] ?? null,
                'is_walk_in' => true,
            ];
        }

        if ($customer) {
            return [
                'name' => $customer->full_name,
                'email' => null,
                'phone' => $customer->phone,
                'is_walk_in' => true,
            ];
        }

        return null;
    }

    /**
     * Legacy fell back to the stored columns and then to whatever the total
     * left over after subtotal/shipping/tax.
     *
     * @return array{name: string|null, amount: float}
     */
    private function serviceCharge(Order $order): array
    {
        $meta = $order->meta ?? [];

        $amount = $order->service_charge_amount
            ?? ($meta['service_charge_amount'] ?? null)
            ?? (($order->total - $order->subtotal - $order->shipping_fee - $order->tax) > 0
                ? $order->total - $order->subtotal - $order->shipping_fee - $order->tax
                : 0);

        return [
            'name' => $meta['service_charge_name'] ?? null,
            'amount' => (float) $amount,
        ];
    }

    private function customerName(Transaction $transaction): ?string
    {
        $context = $this->customerContext($transaction->order);

        return $context['name'] ?? $transaction->invoice?->recipient_name;
    }

    private function paymentMethodLabel(Transaction $transaction): ?string
    {
        $method = $transaction->paymentMethod;

        if (! $method) {
            return null;
        }

        return match ($method->code) {
            'cash' => 'Cash',
            'bank_transfer' => 'Bank Transfer',
            'paystack' => 'Paystack (Card)',
            default => $method->name,
        };
    }

    private function store(Transaction $transaction): ?Store
    {
        return $transaction->order?->store ?? $transaction->invoice?->store;
    }

    private function amountInKobo(Transaction $transaction): int
    {
        return (int) round((float) $transaction->amount * 100);
    }

    private function actorName($actorId): ?string
    {
        if (! $actorId) {
            return null;
        }

        return User::query()->whereKey($actorId)->value('name');
    }

    /**
     * Store filter options, scoped exactly like the rows: a store-assigned
     * staff member must not be offered a store their list can never return.
     *
     * @return array<int, array{id: int, name: string, store_id: string|null}>
     */
    private function filterStores(Request $request): array
    {
        $user = $this->user($request);

        $query = $user->isStaff()
            ? Store::query()->whereIn('id', $this->staffStoreIds($request))
            : $user->accessibleStores();

        return $query
            ->where('status', '!=', Store::STATUS_DELETED)
            ->orderBy('name')
            ->get()
            ->map(fn (Store $store) => [
                'id' => $store->id,
                'name' => $store->name,
                'store_id' => $store->store_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{value: string, label: string}>
     */
    private function statusOptions(): array
    {
        return array_map(
            fn (TransactionStatus $status) => ['value' => $status->value, 'label' => $status->label()],
            TransactionStatus::cases(),
        );
    }
}
