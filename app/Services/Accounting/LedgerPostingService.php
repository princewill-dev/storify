<?php

namespace App\Services\Accounting;

use App\Enums\TransactionStatus;
use App\Models\Bill;
use App\Models\BillPayment;
use App\Models\Expense;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LedgerPostingService
{
    public function __construct(private readonly LedgerSetupService $setup) {}

    /**
     * Run a posting callback without allowing ledger failures to break the money flow.
     * Failures are logged and can be recovered via the ledger:reconcile command.
     */
    public function safe(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            Log::error('ledger.posting_failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Core posting primitive. Creates a balanced journal entry.
     *
     * @param  array<int, array{account_key?: string, account_id?: int, debit?: int, credit?: int, description?: ?string, store_id?: ?int, contact_type?: ?string, contact_id?: ?int, tax_kobo?: int}>  $lines
     * @param  array{memo?: ?string, reference?: ?string, source?: ?Model, idempotency_key?: ?string, user_id?: ?int, date?: ?string}  $options
     */
    public function post(?int $businessId, array $lines, array $options = []): ?JournalEntry
    {
        $prepared = $this->prepareLines($businessId, $lines);

        if (empty($prepared)) {
            return null;
        }

        $debitTotal = array_sum(array_column($prepared, 'debit_kobo'));
        $creditTotal = array_sum(array_column($prepared, 'credit_kobo'));

        if ($debitTotal !== $creditTotal) {
            throw new \RuntimeException(sprintf(
                'Unbalanced journal entry: debits %d != credits %d (kobo).',
                $debitTotal,
                $creditTotal
            ));
        }

        if ($debitTotal <= 0) {
            return null;
        }

        $idempotencyKey = $options['idempotency_key'] ?? null;

        if ($idempotencyKey) {
            $existing = JournalEntry::query()
                ->where('business_id', $businessId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $existing;
            }
        }

        $date = $options['date'] ?? now()->toDateString();
        $period = $this->setup->resolveOpenPeriod($businessId, $date);

        return DB::transaction(function () use ($businessId, $prepared, $options, $date, $period, $idempotencyKey) {
            $source = $options['source'] ?? null;

            $entry = JournalEntry::create([
                'business_id' => $businessId,
                'entry_date' => $date,
                'memo' => $options['memo'] ?? null,
                'reference' => $options['reference'] ?? null,
                'source_type' => $source ? $source::class : null,
                'source_id' => $source?->getKey(),
                'status' => JournalEntry::STATUS_POSTED,
                'fiscal_period_id' => $period->id,
                'idempotency_key' => $idempotencyKey,
                'posted_at' => now(),
                'posted_by' => $options['user_id'] ?? null,
            ]);

            foreach ($prepared as $line) {
                JournalLine::create([
                    'journal_entry_id' => $entry->id,
                    'ledger_account_id' => $line['ledger_account_id'],
                    'store_id' => $line['store_id'] ?? null,
                    'description' => $line['description'] ?? null,
                    'debit_kobo' => $line['debit_kobo'],
                    'credit_kobo' => $line['credit_kobo'],
                    'currency' => $line['currency'] ?? 'NGN',
                    'contact_type' => $line['contact_type'] ?? null,
                    'contact_id' => $line['contact_id'] ?? null,
                    'tax_kobo' => $line['tax_kobo'] ?? 0,
                ]);
            }

            return $entry;
        });
    }

    /**
     * @param  array<int, array{account_key?: string, account_id?: int, debit?: int, credit?: int, description?: ?string, store_id?: ?int, contact_type?: ?string, contact_id?: ?int, tax_kobo?: int}>  $lines
     * @return array<int, array<string, mixed>>
     */
    private function prepareLines(?int $businessId, array $lines): array
    {
        $prepared = [];

        foreach ($lines as $line) {
            $debit = (int) ($line['debit'] ?? 0);
            $credit = (int) ($line['credit'] ?? 0);

            if ($debit <= 0 && $credit <= 0) {
                continue;
            }

            if ($debit > 0 && $credit > 0) {
                throw new \RuntimeException('A journal line cannot have both a debit and a credit.');
            }

            $accountId = $line['account_id'] ?? null;

            if (! $accountId && ! empty($line['account_key'])) {
                $accountId = $this->setup->accountId($businessId, $line['account_key']);
            }

            if (! $accountId) {
                throw new \RuntimeException('A journal line requires an account_id or account_key.');
            }

            $prepared[] = [
                'ledger_account_id' => (int) $accountId,
                'store_id' => $line['store_id'] ?? null,
                'description' => $line['description'] ?? null,
                'debit_kobo' => $debit,
                'credit_kobo' => $credit,
                'currency' => $line['currency'] ?? 'NGN',
                'contact_type' => $line['contact_type'] ?? null,
                'contact_id' => $line['contact_id'] ?? null,
                'tax_kobo' => (int) ($line['tax_kobo'] ?? 0),
            ];
        }

        return $prepared;
    }

    /**
     * POS sale: cash/bank/gateway legs against revenue components, plus COGS.
     */
    public function postSale(Order $order, ?int $userId = null): ?JournalEntry
    {
        $businessId = $order->business_id;
        $totalKobo = (int) round((float) $order->total * 100);

        if ($totalKobo <= 0) {
            return null;
        }

        $taxKobo = (int) round((float) $order->tax * 100);
        $shippingKobo = (int) round((float) $order->shipping_fee * 100);
        $serviceKobo = (int) round((float) ($order->service_charge_amount ?? 0) * 100);
        $salesKobo = $totalKobo - $taxKobo - $shippingKobo - $serviceKobo;

        $lines = [];

        // Debit legs from recorded transactions; fall back to cash for the remainder.
        $legsKobo = 0;

        foreach ($order->transactions as $transaction) {
            $legKobo = (int) round((float) $transaction->amount * 100);
            $legsKobo += $legKobo;

            $lines[] = [
                'account_key' => $this->paymentAssetKey($transaction),
                'debit' => $legKobo,
                'store_id' => $order->store_id,
                'description' => 'POS sale '.$order->order_number,
                'contact_type' => $order->customer_id ? 'customer' : null,
                'contact_id' => $order->customer_id,
            ];
        }

        if ($legsKobo < $totalKobo) {
            $lines[] = [
                'account_key' => 'cash',
                'debit' => $totalKobo - $legsKobo,
                'store_id' => $order->store_id,
                'description' => 'POS sale '.$order->order_number,
            ];
        }

        if ($salesKobo > 0) {
            $lines[] = ['account_key' => 'sales_income', 'credit' => $salesKobo, 'store_id' => $order->store_id, 'description' => 'POS sales '.$order->order_number];
        }
        if ($serviceKobo > 0) {
            $lines[] = ['account_key' => 'service_charge_income', 'credit' => $serviceKobo, 'store_id' => $order->store_id, 'description' => 'Service charge '.$order->order_number];
        }
        if ($shippingKobo > 0) {
            $lines[] = ['account_key' => 'shipping_income', 'credit' => $shippingKobo, 'store_id' => $order->store_id, 'description' => 'Shipping '.$order->order_number];
        }
        if ($taxKobo > 0) {
            $lines[] = ['account_key' => 'tax_payable', 'credit' => $taxKobo, 'tax_kobo' => $taxKobo, 'description' => 'VAT '.$order->order_number];
        }

        $lines = array_merge($lines, $this->cogsLines($order));

        return $this->post($businessId, $lines, [
            'memo' => 'POS sale '.$order->order_number,
            'reference' => $order->order_number,
            'source' => $order,
            'idempotency_key' => 'sale:order:'.$order->id,
            'user_id' => $userId,
            'date' => optional($order->created_at)->toDateString() ?? now()->toDateString(),
        ]);
    }

    /**
     * Accrual revenue recognition for a storefront order (Dr AR, Cr revenue).
     */
    public function postOrderRevenue(Order $order, ?int $userId = null): ?JournalEntry
    {
        $businessId = $order->business_id;
        $totalKobo = (int) round((float) $order->total * 100);

        if ($totalKobo <= 0) {
            return null;
        }

        $taxKobo = (int) round((float) $order->tax * 100);
        $shippingKobo = (int) round((float) $order->shipping_fee * 100);
        $serviceKobo = (int) round((float) ($order->service_charge_amount ?? 0) * 100);
        $salesKobo = $totalKobo - $taxKobo - $shippingKobo - $serviceKobo;

        $lines = [[
            'account_key' => 'accounts_receivable',
            'debit' => $totalKobo,
            'store_id' => $order->store_id,
            'description' => 'Order '.$order->order_number,
            'contact_type' => $order->customer_id ? 'customer' : null,
            'contact_id' => $order->customer_id,
        ]];

        if ($salesKobo > 0) {
            $lines[] = ['account_key' => 'sales_income', 'credit' => $salesKobo, 'store_id' => $order->store_id, 'description' => 'Sales '.$order->order_number];
        }
        if ($serviceKobo > 0) {
            $lines[] = ['account_key' => 'service_charge_income', 'credit' => $serviceKobo, 'store_id' => $order->store_id, 'description' => 'Service charge '.$order->order_number];
        }
        if ($shippingKobo > 0) {
            $lines[] = ['account_key' => 'shipping_income', 'credit' => $shippingKobo, 'store_id' => $order->store_id, 'description' => 'Shipping '.$order->order_number];
        }
        if ($taxKobo > 0) {
            $lines[] = ['account_key' => 'tax_payable', 'credit' => $taxKobo, 'tax_kobo' => $taxKobo, 'description' => 'VAT '.$order->order_number];
        }

        $lines = array_merge($lines, $this->cogsLines($order));

        return $this->post($businessId, $lines, [
            'memo' => 'Order '.$order->order_number,
            'reference' => $order->order_number,
            'source' => $order,
            'idempotency_key' => 'order_revenue:'.$order->id,
            'user_id' => $userId,
            'date' => optional($order->created_at)->toDateString() ?? now()->toDateString(),
        ]);
    }

    /**
     * Payment received against an order or invoice (Dr cash/bank/clearing, Cr AR).
     */
    public function postPaymentReceived(Transaction $transaction, ?int $userId = null): ?JournalEntry
    {
        // Orderless/invoiceless transactions are platform subscription mirrors and post
        // to the platform books via postSubscriptionPayment() instead.
        if (! $transaction->order_id && ! $transaction->invoice_id) {
            return null;
        }

        $amountKobo = (int) round((float) $transaction->amount * 100);

        if ($amountKobo <= 0) {
            return null;
        }

        $businessId = $transaction->business_id;

        if (! $businessId && $transaction->order) {
            $businessId = $transaction->order->business_id;
        }
        if (! $businessId && $transaction->invoice) {
            $businessId = $transaction->invoice->business_id;
        }

        $storeId = $transaction->order?->store_id ?? $transaction->invoice?->store_id;
        $contactId = $transaction->order?->customer_id ?? $transaction->invoice?->customer_id;

        $lines = [[
            'account_key' => $this->paymentAssetKey($transaction),
            'debit' => $amountKobo,
            'store_id' => $storeId,
            'description' => 'Payment '.$transaction->reference,
            'contact_type' => $contactId ? 'customer' : null,
            'contact_id' => $contactId,
        ], [
            'account_key' => 'accounts_receivable',
            'credit' => $amountKobo,
            'store_id' => $storeId,
            'description' => 'Payment '.$transaction->reference,
            'contact_type' => $contactId ? 'customer' : null,
            'contact_id' => $contactId,
        ]];

        return $this->post($businessId, $lines, [
            'memo' => 'Payment received '.$transaction->reference,
            'reference' => $transaction->reference,
            'source' => $transaction,
            'idempotency_key' => 'payment:txn:'.$transaction->id,
            'user_id' => $userId,
            'date' => optional($transaction->paid_at ?? $transaction->created_at)->toDateString() ?? now()->toDateString(),
        ]);
    }

    /**
     * Invoice issued (Dr AR, Cr revenue + VAT).
     */
    public function postInvoice(Invoice $invoice, ?int $userId = null): ?JournalEntry
    {
        $totalKobo = (int) round((float) $invoice->total * 100);

        if ($totalKobo <= 0) {
            return null;
        }

        $taxKobo = (int) round((float) $invoice->tax_amount * 100);
        $revenueKobo = $totalKobo - $taxKobo;

        $lines = [[
            'account_key' => 'accounts_receivable',
            'debit' => $totalKobo,
            'store_id' => $invoice->store_id,
            'description' => 'Invoice '.$invoice->invoice_number,
            'contact_type' => $invoice->customer_id ? 'customer' : null,
            'contact_id' => $invoice->customer_id,
        ]];

        if ($revenueKobo > 0) {
            $lines[] = ['account_key' => 'sales_income', 'credit' => $revenueKobo, 'store_id' => $invoice->store_id, 'description' => 'Invoice '.$invoice->invoice_number];
        }
        if ($taxKobo > 0) {
            $lines[] = ['account_key' => 'tax_payable', 'credit' => $taxKobo, 'tax_kobo' => $taxKobo, 'description' => 'VAT '.$invoice->invoice_number];
        }

        return $this->post($invoice->business_id, $lines, [
            'memo' => 'Invoice '.$invoice->invoice_number,
            'reference' => $invoice->invoice_number,
            'source' => $invoice,
            'idempotency_key' => 'invoice:'.$invoice->id,
            'user_id' => $userId,
            'date' => optional($invoice->issue_date ?? $invoice->created_at)->toDateString() ?? now()->toDateString(),
        ]);
    }

    /**
     * Refund: reversing entry of the original payment.
     */
    public function postRefund(Transaction $transaction, ?int $userId = null): ?JournalEntry
    {
        $businessId = $transaction->business_id
            ?? $transaction->order?->business_id
            ?? $transaction->invoice?->business_id;

        $original = JournalEntry::query()
            ->where('business_id', $businessId)
            ->where('idempotency_key', 'payment:txn:'.$transaction->id)
            ->first();

        if (! $original) {
            $original = JournalEntry::query()
                ->where('business_id', $businessId)
                ->where('idempotency_key', 'sale:order:'.$transaction->order_id)
                ->first();
        }

        if (! $original) {
            return null;
        }

        return $this->reverseEntry($original, 'Refund '.$transaction->reference, $userId, 'refund:txn:'.$transaction->id);
    }

    public function postExpense(Expense $expense, ?int $userId = null): ?JournalEntry
    {
        $totalKobo = (int) ($expense->total_kobo ?: $expense->amount_kobo);

        if ($totalKobo <= 0) {
            return null;
        }

        $taxKobo = (int) ($expense->tax_kobo ?? 0);
        $netKobo = $totalKobo - $taxKobo;

        $paymentKey = match ($expense->payment_method) {
            'bank_transfer', 'bank', 'transfer' => 'bank',
            'card', 'gateway', 'paystack' => 'gateway_clearing',
            default => 'cash',
        };

        $creditAccountId = $expense->payment_account_id ?: null;
        $creditKey = $creditAccountId ? null : $paymentKey;

        $lines = [[
            'account_id' => $expense->ledger_account_id,
            'debit' => $netKobo,
            'description' => $expense->description ?? 'Expense',
            'contact_type' => $expense->supplier_id ? 'supplier' : null,
            'contact_id' => $expense->supplier_id,
        ]];

        if ($taxKobo > 0) {
            $lines[] = ['account_key' => 'tax_payable', 'debit' => $taxKobo, 'tax_kobo' => $taxKobo, 'description' => 'Input VAT'];
        }

        $lines[] = array_filter([
            'account_id' => $creditAccountId,
            'account_key' => $creditKey,
            'credit' => $totalKobo,
            'description' => 'Payment '.($expense->reference ?? ''),
        ], fn ($value) => $value !== null);

        return $this->post($expense->business_id, $lines, [
            'memo' => 'Expense '.($expense->reference ?? $expense->id),
            'reference' => $expense->reference,
            'source' => $expense,
            'idempotency_key' => 'expense:'.$expense->id,
            'user_id' => $userId,
            'date' => optional($expense->expense_date)->toDateString() ?? now()->toDateString(),
        ]);
    }

    public function postBill(Bill $bill, ?int $userId = null): ?JournalEntry
    {
        $totalKobo = (int) ($bill->total_kobo ?: 0);

        if ($totalKobo <= 0) {
            return null;
        }

        $taxKobo = (int) ($bill->tax_kobo ?? 0);
        $netKobo = $totalKobo - $taxKobo;

        $lines = [[
            'account_key' => 'accounts_payable',
            'credit' => $totalKobo,
            'description' => 'Bill '.$bill->bill_number,
            'contact_type' => 'supplier',
            'contact_id' => $bill->supplier_id,
        ]];

        $items = $bill->items()->get();

        if ($items->isEmpty()) {
            $lines[] = ['account_key' => 'default_expense', 'debit' => $netKobo, 'description' => 'Bill '.$bill->bill_number];
        } else {
            foreach ($items as $item) {
                $amount = (int) ($item->amount_kobo ?? 0);

                if ($amount <= 0) {
                    continue;
                }

                $lines[] = array_filter([
                    'account_id' => $item->expense_account_id,
                    'account_key' => $item->expense_account_id ? null : 'default_expense',
                    'debit' => $amount,
                    'description' => $item->description,
                ], fn ($value) => $value !== null);
            }
        }

        if ($taxKobo > 0) {
            $lines[] = ['account_key' => 'tax_payable', 'debit' => $taxKobo, 'tax_kobo' => $taxKobo, 'description' => 'Input VAT'];
        }

        return $this->post($bill->business_id, $lines, [
            'memo' => 'Bill '.$bill->bill_number,
            'reference' => $bill->bill_number,
            'source' => $bill,
            'idempotency_key' => 'bill:'.$bill->id,
            'user_id' => $userId,
            'date' => optional($bill->issue_date)->toDateString() ?? now()->toDateString(),
        ]);
    }

    public function postBillPayment(BillPayment $payment, ?int $userId = null): ?JournalEntry
    {
        $amountKobo = (int) ($payment->amount_kobo ?? 0);

        if ($amountKobo <= 0) {
            return null;
        }

        $assetKey = match ($payment->method) {
            'bank_transfer', 'bank', 'transfer', 'cheque' => 'bank',
            'card', 'gateway', 'paystack' => 'gateway_clearing',
            default => 'cash',
        };

        $lines = [
            [
                'account_key' => 'accounts_payable',
                'debit' => $amountKobo,
                'description' => 'Bill payment '.($payment->reference ?? ''),
                'contact_type' => 'supplier',
                'contact_id' => $payment->supplier_id,
            ],
            array_filter([
                'account_id' => $payment->payment_account_id ?: null,
                'account_key' => $payment->payment_account_id ? null : $assetKey,
                'credit' => $amountKobo,
                'description' => 'Bill payment '.($payment->reference ?? ''),
            ], fn ($value) => $value !== null),
        ];

        return $this->post($payment->business_id, $lines, [
            'memo' => 'Bill payment '.($payment->reference ?? $payment->id),
            'reference' => $payment->reference,
            'source' => $payment,
            'idempotency_key' => 'bill_payment:'.$payment->id,
            'user_id' => $userId,
            'date' => optional($payment->payment_date)->toDateString() ?? now()->toDateString(),
        ]);
    }

    /**
     * Platform subscription revenue (Dr clearing, Cr revenue).
     */
    public function postSubscriptionPayment(Payment $payment, ?int $userId = null): ?JournalEntry
    {
        $amountKobo = (int) round((float) $payment->amount * 100);

        if ($amountKobo <= 0) {
            return null;
        }

        $lines = [
            ['account_key' => 'gateway_clearing', 'debit' => $amountKobo, 'description' => 'Subscription payment '.$payment->reference],
            ['account_key' => 'sales_income', 'credit' => $amountKobo, 'description' => 'Subscription revenue '.$payment->reference],
        ];

        return $this->post(null, $lines, [
            'memo' => 'Subscription payment '.$payment->reference,
            'reference' => $payment->reference,
            'source' => $payment,
            'idempotency_key' => 'subscription_payment:'.$payment->id,
            'user_id' => $userId,
            'date' => optional($payment->paid_at ?? $payment->created_at)->toDateString() ?? now()->toDateString(),
        ]);
    }

    /**
     * Opening balances. Positive amounts are debits (assets/expenses),
     * negative amounts are credits (liabilities/equity). Plug goes to Opening Balance Equity.
     *
     * @param  array<string, int>  $balancesByKey  signed kobo per mapping key
     */
    public function postOpeningBalances(?int $businessId, array $balancesByKey, string $date, ?int $userId = null): ?JournalEntry
    {
        $lines = [];
        $net = 0;

        foreach ($balancesByKey as $key => $amountKobo) {
            $amountKobo = (int) $amountKobo;

            if ($amountKobo === 0) {
                continue;
            }

            $net += $amountKobo;

            $lines[] = $amountKobo > 0
                ? ['account_key' => $key, 'debit' => $amountKobo, 'description' => 'Opening balance']
                : ['account_key' => $key, 'credit' => abs($amountKobo), 'description' => 'Opening balance'];
        }

        if (empty($lines)) {
            return null;
        }

        if ($net > 0) {
            $lines[] = ['account_key' => 'opening_balance_equity', 'credit' => $net, 'description' => 'Opening balance equity'];
        } elseif ($net < 0) {
            $lines[] = ['account_key' => 'opening_balance_equity', 'debit' => abs($net), 'description' => 'Opening balance equity'];
        }

        return $this->post($businessId, $lines, [
            'memo' => 'Opening balances',
            'idempotency_key' => 'opening:'.($businessId ?? 'platform').':'.$date,
            'user_id' => $userId,
            'date' => $date,
        ]);
    }

    /**
     * Create a reversing entry for a posted entry.
     */
    public function reverseEntry(JournalEntry $entry, string $memo, ?int $userId = null, ?string $idempotencyKey = null): ?JournalEntry
    {
        $entry->loadMissing('lines');

        if ($entry->lines->isEmpty()) {
            return null;
        }

        $lines = $entry->lines->map(fn (JournalLine $line) => [
            'account_id' => $line->ledger_account_id,
            'store_id' => $line->store_id,
            'description' => $line->description,
            'debit' => (int) $line->credit_kobo,
            'credit' => (int) $line->debit_kobo,
            'contact_type' => $line->contact_type,
            'contact_id' => $line->contact_id,
        ])->all();

        $reversal = $this->post($entry->business_id, $lines, [
            'memo' => $memo,
            'reference' => $entry->reference,
            'source' => $entry->source,
            'idempotency_key' => $idempotencyKey ?? ('reversal:'.$entry->id),
            'user_id' => $userId,
            'date' => now()->toDateString(),
        ]);

        if ($reversal) {
            $entry->update(['status' => JournalEntry::STATUS_VOID, 'voided_at' => now(), 'voided_by' => $userId]);
            $reversal->update(['reversal_of_id' => $entry->id]);
        }

        return $reversal;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function cogsLines(Order $order): array
    {
        $costKobo = (int) $order->items()->sum('cost_kobo');

        if ($costKobo <= 0) {
            return [];
        }

        return [
            ['account_key' => 'cogs', 'debit' => $costKobo, 'store_id' => $order->store_id, 'description' => 'COGS '.$order->order_number],
            ['account_key' => 'inventory', 'credit' => $costKobo, 'store_id' => $order->store_id, 'description' => 'Inventory relief '.$order->order_number],
        ];
    }

    private function paymentAssetKey(Transaction $transaction): string
    {
        $legMethod = $transaction->metadata['leg_method'] ?? null;
        $code = $transaction->paymentMethod?->code;

        return match (true) {
            in_array($legMethod, ['transfer', 'bank_transfer'], true),
            $code === 'bank_transfer' => 'bank',
            in_array($legMethod, ['paystack', 'card', 'gateway'], true),
            $code === 'paystack' => 'gateway_clearing',
            default => 'cash',
        };
    }

    /**
     * Guard used by hooks: only post for transactions that actually moved money.
     */
    public function isPostableTransaction(Transaction $transaction): bool
    {
        $status = $transaction->status instanceof TransactionStatus
            ? $transaction->status
            : TransactionStatus::tryFrom((string) $transaction->status);

        return in_array($status, [TransactionStatus::CONFIRMED, TransactionStatus::PAID], true);
    }
}
