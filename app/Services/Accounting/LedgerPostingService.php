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
use App\Support\Money\Naira;
use App\Support\Payments\PaymentGatewayRegistry;
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
     * A sale the platform processed on a business's behalf.
     *
     * Structurally different from {@see postPaymentReceived()} and deliberately
     * so. There, the whole gross becomes an asset we hold — a store balance and
     * a clearing account that clears to our bank. Here **nothing is ours**: what
     * lands in the business's account at the provider is the business's own
     * share, and we never hold a naira of it.
     *
     * So the business's books take the net as an asset and its own deductions as
     * expenses, and the entry balances against the receivable the order already
     * raised:
     *
     *     Dr 1030 Payment Gateway Clearing   net         (their balance at the provider)
     *     Dr 5500 Processing Fee             processing  (their deductions, combined)
     *     Cr 1100 Accounts Receivable      gross
     *
     * Under custody the shape is identical and only the asset moves — `1040 Funds
     * Held by Storify` in place of `1030` — because the business is still owed the
     * same net, still pays the same fee, and still had the same receivable when it
     * sold. The one thing that changed is who is holding the money while it
     * waits, and that is the one line that differs. The platform's own side of a
     * collected sale is a separate entry: {@see postCustodyCollection()}.
     *
     * ## What lands on 5500 depends on who paid the provider
     *
     * This used to be the provider's charge and our commission together, because
     * both came off the business's money and to the business they are the same
     * kind of thing — the cost of taking this payment. For a sale charged on the
     * business's **own** keys that is still exactly true, and it is still posted
     * that way.
     *
     * For a sale **we** charged, only our commission is on these books. The
     * provider's charge came out of our balance rather than the business's, so it
     * is our own cost of doing business and appears nowhere the business can see
     * it — one line, `5500 Processing Fee`, holding the commission alone. That
     * the two were ever presented as one number, without saying which provider
     * was being paid for, is what made a correct sum look like an overcharge.
     *
     * Our margin is still not itemised to the business, and does not need to be:
     * for a sale we charged there is nothing left on these books *but* our
     * commission, so there is no second cost to confuse it with.
     *
     * The provider's charge on a sale we charged is posted on the platform's own
     * books instead — {@see postCustodyProviderFeeSettled()} — and the share that
     * `platform_fee` settles to **us** is {@see postPlatformCommissionEarned()}.
     * The split is also recorded on the row, as `fee_kobo` and
     * `platform_fee_kobo`, which is what the backfill and the repair commands
     * read.
     *
     * `$providerFeeKobo` is not a parameter, on purpose. The row is where the fee
     * was just written, and a caller passing its own number could put the
     * provider's charge back onto a business's books — the one thing this entry
     * must never do again. Read from the row, the figure cannot disagree with
     * itself.
     */
    public function postManagedPaymentReceived(
        Transaction $transaction,
        int $commissionKobo,
        ?int $userId = null,
        bool $custody = false,
    ): ?JournalEntry {
        $grossKobo = (int) round((float) $transaction->amount * 100);

        if ($grossKobo <= 0) {
            return null;
        }

        $businessId = $transaction->business_id
            ?: ($transaction->order?->business_id ?? $transaction->invoice?->business_id);

        if (! $businessId) {
            return null;
        }

        // Taken off the business's share only when the business bore it. Zero for
        // a sale we charged, which is what makes the line below our commission
        // alone.
        $providerFeeKobo = $transaction->providerFeeBearer()->isBusiness()
            ? (int) ($transaction->fee_kobo ?? 0)
            : 0;

        // Clamped rather than trusted: a provider reporting a fee larger than
        // the sale would otherwise post a negative asset and a credit that does
        // not balance. The clamp can only trigger on bad data.
        $providerFeeKobo = max(0, min($providerFeeKobo, $grossKobo));
        $commissionKobo = max(0, min($commissionKobo, $grossKobo - $providerFeeKobo));
        $netKobo = $grossKobo - $providerFeeKobo - $commissionKobo;

        $storeId = $transaction->order?->store_id ?? $transaction->invoice?->store_id;
        $contactId = $transaction->order?->customer_id ?? $transaction->invoice?->customer_id;

        $lines = [[
            'account_key' => $this->managedAssetKey($custody),
            'debit' => $netKobo,
            'store_id' => $storeId,
            'description' => 'Payment '.$transaction->reference,
            'contact_type' => $contactId ? 'customer' : null,
            'contact_id' => $contactId,
        ]];

        // One line for whatever the business was actually charged, because that
        // is what it now sees on the transaction: a single processing fee. This
        // used to post two debits to the same account with different
        // descriptions, which made the ledger the only place the split was
        // visible — and put Storify's margin in front of the business on its own
        // books.
        //
        // Which is also why splitting it again would be wrong now: for a sale we
        // charged, the only thing left in this figure *is* our margin, so a
        // second line would itemise it. What replaces the old two-line posting is
        // not a finer breakdown but a smaller total.
        $processingFeeKobo = $providerFeeKobo + $commissionKobo;

        if ($processingFeeKobo > 0) {
            $lines[] = [
                'account_key' => 'gateway_fees',
                'debit' => $processingFeeKobo,
                'store_id' => $storeId,
                'description' => 'Processing fee '.$transaction->reference,
            ];
        }

        $lines[] = [
            'account_key' => 'accounts_receivable',
            'credit' => $grossKobo,
            'store_id' => $storeId,
            'description' => 'Payment '.$transaction->reference,
            'contact_type' => $contactId ? 'customer' : null,
            'contact_id' => $contactId,
        ];

        // The same idempotency key the own-key path uses, on purpose: for one
        // transaction these two postings are alternatives, not a pair, and the
        // key is what makes posting both impossible.
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
     * The commission on a managed sale, on the business's own books — for a sale
     * whose money never passed through Accounts Receivable.
     *
     * A storefront sale accrues to 1100 before it is paid, so its payment entry
     * ({@see postManagedPaymentReceived()}) takes the commission back off the
     * receivable on the way through. A POS sale has no receivable: the till takes
     * the money and {@see postSale()} debits the asset directly, so the
     * commission has to be taken off that asset explicitly, or the business's
     * books would claim it still holds money Bachs split off for us:
     *
     *     Dr 5500 Processing Fee             commission
     *     Cr 1030 Payment Gateway Clearing   commission
     *
     * (`1040 Funds Held by Storify` in place of `1030` under custody — the same
     * commission off the same asset, on whichever account is holding it.)
     *
     * The platform's side of the same commission is
     * {@see postPlatformCommissionEarned()}, on the platform's own books. Neither
     * is a reversal of the other; they are two parties' views of one deduction.
     *
     * **Bachs' own processing fee is not posted here, and does not belong here.**
     * Bachs deducts it from *our* balance when the charge settles — days after
     * the till closed the sale — and at the moment this row is written no number
     * for it exists anywhere. It is Storify's own cost of doing business on a
     * managed leg, so it is a platform expense and never reaches a business's
     * books at all: the business's deduction is our commission alone.
     *
     * The line is described as a processing fee rather than a commission because
     * that is the only name the business is given for this cost anywhere — the
     * account it lands on, the column on the transaction, the CSV — and because
     * it is what the business was in fact charged.
     */
    public function postManagedCommissionExpense(
        Transaction $transaction,
        int $commissionKobo,
        ?int $userId = null,
        bool $custody = false,
    ): ?JournalEntry {
        if ($commissionKobo <= 0) {
            return null;
        }

        $businessId = $transaction->business_id
            ?: ($transaction->order?->business_id ?? $transaction->invoice?->business_id);

        if (! $businessId) {
            return null;
        }

        $storeId = $transaction->order?->store_id ?? $transaction->invoice?->store_id;

        $lines = [
            [
                'account_key' => 'gateway_fees',
                'debit' => $commissionKobo,
                'store_id' => $storeId,
                'description' => 'Processing fee '.$transaction->reference,
            ],
            [
                'account_key' => $this->managedAssetKey($custody),
                'credit' => $commissionKobo,
                'store_id' => $storeId,
                'description' => 'Processing fee '.$transaction->reference,
            ],
        ];

        return $this->post($businessId, $lines, [
            // "Processing fee", like the account it debits and the column the
            // business reads. The idempotency key below keeps its older name
            // deliberately: it is a uniqueness claim on an entry that may already
            // exist, so changing it would let a re-run post a second time.
            'memo' => 'Processing fee '.$transaction->reference,
            'reference' => $transaction->reference,
            'source' => $transaction,
            'idempotency_key' => 'managed_commission:txn:'.$transaction->id,
            'user_id' => $userId,
            'date' => optional($transaction->paid_at ?? $transaction->created_at)->toDateString() ?? now()->toDateString(),
        ]);
    }

    /**
     * Storify's commission on a managed sale, on the platform's own books.
     *
     * `businessId = null` is the platform scope — the ledger has always
     * supported one, and this is what it is for. The commission is collected by
     * the provider out of the business's proceeds and settles into our account,
     * so it is cash at the provider:
     *
     *     Dr 1030 Payment Gateway Clearing   commission
     *     Cr 4020 Service Charge Income      commission
     *
     * Kept off the business's books because it is not the business's income: on
     * their side it is an expense, already posted to 5500 by
     * {@see postManagedPaymentReceived()}.
     */
    public function postPlatformCommissionEarned(
        Transaction $transaction,
        int $commissionKobo,
        ?int $userId = null,
    ): ?JournalEntry {
        if ($commissionKobo <= 0) {
            return null;
        }

        // An invoice payment has no order; its store lives on the invoice.
        $storeId = $transaction->order?->store_id ?? $transaction->invoice?->store_id;

        $lines = [
            [
                'account_key' => 'gateway_clearing',
                'debit' => $commissionKobo,
                'store_id' => $storeId,
                'description' => 'Commission '.$transaction->reference,
            ],
            [
                'account_key' => 'service_charge_income',
                'credit' => $commissionKobo,
                'store_id' => $storeId,
                'description' => 'Commission '.$transaction->reference,
            ],
        ];

        return $this->post(null, $lines, [
            'memo' => 'Platform commission '.$transaction->reference,
            'reference' => $transaction->reference,
            'source' => $transaction,
            'idempotency_key' => 'commission:txn:'.$transaction->id,
            'user_id' => $userId,
            'date' => optional($transaction->paid_at ?? $transaction->created_at)->toDateString() ?? now()->toDateString(),
        ]);
    }

    /**
     * A withdrawal from a business's balance at the provider into its bank.
     *
     * The second half of the managed model: 1030 holds what the business has at
     * the provider, and the payout is what empties it. Posted when the provider
     * reports the payout paid, not when it is requested — the debit at creation
     * is not the money having arrived, and a payout can still fail.
     *
     * ## With a processing fee
     *
     * The wallet is debited and the allocations sum to the gross, but the bank
     * receives the net — so the entry splits the difference rather than crediting
     * an asset the business never got:
     *
     *     Dr 1020 Bank                  gross − fee
     *     Dr 5500 Processing Fee        fee
     *     Cr 1030/1040 managed asset    gross
     *
     * One entry, one idempotency key: a reversal takes the fee expense back with
     * the bank debit, because a payout that was recalled was not a payout the
     * business should have paid a fee on.
     */
    public function postGatewayWithdrawal(
        int $businessId,
        ?int $storeId,
        int $amountKobo,
        string $reference,
        ?int $userId = null,
        bool $custody = false,
        int $feeKobo = 0,
    ): ?JournalEntry {
        if ($amountKobo <= 0) {
            return null;
        }

        // A fee can never exceed the amount it is taken from; clamping here keeps
        // a misconfigured policy from producing a negative bank debit rather than
        // a held payout, which the sweep already prevents.
        $feeKobo = max(0, min($feeKobo, $amountKobo));
        $netKobo = $amountKobo - $feeKobo;

        $lines = [];

        if ($netKobo > 0) {
            $lines[] = [
                'account_key' => 'bank',
                'debit' => $netKobo,
                'store_id' => $storeId,
                'description' => 'Payout '.$reference,
            ];
        }

        if ($feeKobo > 0) {
            // The business's own expense, under the one name its P&L gives the
            // cost of moving money: Processing Fee. The gross left its wallet,
            // the bank received the net, and this is the difference.
            $lines[] = [
                'account_key' => 'gateway_fees',
                'debit' => $feeKobo,
                'store_id' => $storeId,
                'description' => 'Payout fee '.$reference,
            ];
        }

        $lines[] = [
            'account_key' => $this->managedAssetKey($custody),
            'credit' => $amountKobo,
            'store_id' => $storeId,
            'description' => 'Payout '.$reference,
        ];

        return $this->post($businessId, $lines, [
            'memo' => 'Payout to bank '.$reference,
            'reference' => $reference,
            // Keyed on the provider's payout id, so a replayed `payout.paid`
            // cannot post the withdrawal twice.
            'idempotency_key' => 'payout:'.$reference,
            'user_id' => $userId,
        ]);
    }

    /**
     * Storify's side of a collected sale, on the platform's own books.
     *
     * The entry that did not exist before this change, because the arrangement it
     * describes did not exist: money that is in our provider balance, that we did
     * not earn, and that we owe to somebody.
     *
     * For a sale charged on the business's **own** keys — and for every sale
     * posted before this rule changed — there are three claims on the one charge,
     * and they leave at three different times:
     *
     *     Dr 1030 Payment Gateway Clearing      gross
     *       Cr 2300 Funds Held for Businesses     net
     *       Cr 4020 Service Charge Income         commission
     *       Cr 2210 Provider Fees Payable         provider fee
     *
     * The commission is ours the moment the sale happens and stays on 4020. The
     * provider's fee has already been deducted from our balance by the provider
     * itself — so it is a liability that is settled the instant it is recognised,
     * posted by {@see postCustodyProviderFeeSettled()} and never left sitting. The
     * business's share is the one that lingers, until the payout debits 2300
     * back out.
     *
     * For a sale **we** charged, 2210 is not credited at all, because there is
     * nobody to owe it to:
     *
     *     Dr 1030 Payment Gateway Clearing      gross
     *       Cr 2300 Funds Held for Businesses     net   (= gross − commission)
     *       Cr 4020 Service Charge Income         commission
     *
     * The provider is taking its charge out of a balance that is already ours, so
     * it is our own expense and not a liability to the business. It is recognised
     * on 5510 by the same {@see postCustodyProviderFeeSettled()}, which brings 1030
     * down by it exactly as the old 2210 posting did.
     *
     * ## The identity, on both rules
     *
     * `1030 = 2300 + 4020 − 5510`
     *
     * The clearing account is what the businesses are owed, plus what we earned,
     * less the provider's charges we have recognised as our own. A legacy sale
     * contributes its fee through 2300 (already net of it) and a sale under the
     * new rule through 5510, and both come out the same — which is why one
     * identity still describes the whole ledger. If it does not hold, the wallet
     * and the journal have drifted apart.
     *
     * Note what the fee's *timing* does to that sum: for a sale we charged, 1030
     * stands higher than the two credits under it by the fee until the recognition
     * entry lands (see the promote in {@see \App\Services\Payments\Custody\CustodyService::promoteSettled()}),
     * and that gap is work still to do rather than a drift to chase.
     *
     * **`$netKobo` is checked, not trusted.** It arrives from the wallet, which is
     * the thing that actually owes the business money, and the entry is built from
     * the row's own gross and fees. If those two disagree, the sale is being posted
     * with one number and credited with another — so this refuses rather than
     * absorbing the difference into a balancing line that would hide it forever.
     * The caller wraps this in {@see safe()}, so the refusal is logged and the
     * wallet credit stands.
     *
     * **And the row has to be in the currency the accounts are in.** Every figure
     * below comes off the row, and `Naira::koboFromRounded($transaction->amount)`
     * reads the store's own amount as naira — so on a converted sale the entry
     * would describe a collection that never happened, in a currency the platform's
     * custody accounts do not have. It would also go through: the check below
     * compares the wallet's number with the row's own arithmetic and the two agree
     * because both were computed the same wrong way. Refused outright, and the
     * caller logs it — the wallet refuses the same row for the same reason, so a
     * sale reaching here in the wrong currency means the wallet was credited by
     * something other than {@see CustodyService::recordCollectedSale()}, which is
     * the thing worth hearing about.
     *
     * @throws \RuntimeException when the wallet's figure and the row's figures disagree.
     */
    public function postCustodyCollection(
        Transaction $transaction,
        int $netKobo,
        ?int $userId = null,
    ): ?JournalEntry {
        if (! $transaction->isInCustodyCurrency()) {
            Log::warning('ledger.custody_collection_wrong_currency', [
                'transaction_id' => $transaction->id,
                'currency' => $transaction->currency,
                'wallet_currency' => config('custody.currency'),
            ]);

            return null;
        }

        // The same conversion `netKobo()` used to produce `$netKobo`. A different
        // one could differ by a kobo on the same row and turn the check below into
        // a false alarm on a sale that is perfectly fine.
        $grossKobo = Naira::koboFromRounded($transaction->amount);

        if ($grossKobo <= 0 || $netKobo <= 0) {
            return null;
        }

        // The provider's charge is a claim on *this* charge only when the business
        // paid it. Read from the row, so a sale we charged cannot have it put back
        // on a business's books here.
        $bearer = $transaction->providerFeeBearer();
        $providerFeeKobo = $bearer->isBusiness() ? max(0, (int) ($transaction->fee_kobo ?? 0)) : 0;
        $commissionKobo = $transaction->platformFeeKobo();

        // The exact expression `Transaction::netKobo()`, bearer included and the
        // raw nullable fee passed through rather than the clamped one — a
        // platform-bearing row's answer does not depend on the fee at all, and a
        // business-bearing row's is null until the provider reports it.
        $expectedNet = Transaction::netKoboFor(
            $grossKobo,
            $transaction->fee_kobo !== null ? (int) $transaction->fee_kobo : null,
            $commissionKobo,
            $bearer,
        );

        if ($expectedNet !== $netKobo) {
            throw new \RuntimeException(sprintf(
                'Custody collection does not reconcile for transaction %d: wallet credited %d kobo, row yields %s kobo.',
                $transaction->id,
                $netKobo,
                var_export($expectedNet, true),
            ));
        }

        $storeId = $transaction->order?->store_id ?? $transaction->invoice?->store_id;
        $description = 'Payment '.$transaction->reference;

        $lines = [[
            'account_key' => 'gateway_clearing',
            'debit' => $grossKobo,
            'store_id' => $storeId,
            'description' => $description,
        ], [
            'account_key' => 'business_funds_held',
            'credit' => $netKobo,
            'store_id' => $storeId,
            'description' => $description,
        ]];

        if ($commissionKobo > 0) {
            $lines[] = [
                'account_key' => 'service_charge_income',
                'credit' => $commissionKobo,
                'store_id' => $storeId,
                'description' => 'Commission '.$transaction->reference,
            ];
        }

        if ($providerFeeKobo > 0) {
            $lines[] = [
                'account_key' => 'provider_fees_payable',
                'credit' => $providerFeeKobo,
                'store_id' => $storeId,
                'description' => 'Provider fee '.$transaction->reference,
            ];
        }

        return $this->post(null, $lines, [
            'memo' => 'Collected payment '.$transaction->reference,
            'reference' => $transaction->reference,
            'source' => $transaction,
            'idempotency_key' => 'managed:collect:txn:'.$transaction->id,
            'user_id' => $userId,
            'date' => optional($transaction->paid_at ?? $transaction->created_at)->toDateString() ?? now()->toDateString(),
        ]);
    }

    /**
     * The provider has taken its fee out of Storify's balance.
     *
     * For a sale the business paid the provider on — and every sale posted before
     * the rule changed — this settles a liability:
     *
     *     Dr 2210 Provider Fees Payable    fee
     *     Cr 1030 Payment Gateway Clearing fee
     *
     * Posted when the provider reports its charge, which for a card payment is at
     * settlement days after the sale, and which is also the moment the fee stops
     * being an accrual. Until this runs the sale's fee sits on 2210 as a known
     * liability against a clearing balance that still appears to hold it — which
     * is the honest picture, not an error.
     *
     * For a sale **we** charged there was never a liability, because the provider
     * is taking the money out of a balance that is already ours:
     *
     *     Dr 5510 Provider Processing Fees  fee
     *     Cr 1030 Payment Gateway Clearing  fee
     *
     * Same size, same account on the credit side, and the other way round in
     * meaning: this recognises a cost we incurred rather than a debt we settled.
     * The gap it closes is identical — 1030 holds the full gross while our balance
     * at the provider is already short by this much.
     *
     * Which of the two it is comes off the row, like every other rule a sale is
     * under.
     *
     * Keyed on the transaction rather than on a provider reference: a sale has
     * exactly one provider fee, whatever the provider chooses to call it, and the
     * row is the one identifier both sides share. That key is also what makes it
     * safe to call from two directions — at collection, when the fee is already
     * known, and from the promote sweep for a fee learned afterwards.
     */
    public function postCustodyProviderFeeSettled(
        Transaction $transaction,
        int $providerFeeKobo,
        ?int $userId = null,
    ): ?JournalEntry {
        if ($providerFeeKobo <= 0) {
            return null;
        }

        $storeId = $transaction->order?->store_id ?? $transaction->invoice?->store_id;
        $description = 'Provider fee '.$transaction->reference;

        $lines = [
            [
                'account_key' => $transaction->providerFeeBearer()->isBusiness()
                    ? 'provider_fees_payable'
                    : 'provider_processing_fees',
                'debit' => $providerFeeKobo,
                'store_id' => $storeId,
                'description' => $description,
            ],
            [
                'account_key' => 'gateway_clearing',
                'credit' => $providerFeeKobo,
                'store_id' => $storeId,
                'description' => $description,
            ],
        ];

        return $this->post(null, $lines, [
            'memo' => 'Provider fee settled '.$transaction->reference,
            'reference' => $transaction->reference,
            'source' => $transaction,
            'idempotency_key' => 'managed:provider_fee:txn:'.$transaction->id,
            'user_id' => $userId,
            'date' => now()->toDateString(),
        ]);
    }

    /**
     * The platform's side of paying a custody business: the money leaves our hands.
     *
     *     Dr 2300 Funds Held for Businesses   amount
     *     Cr 1030 Payment Gateway Clearing    amount
     *
     * A second entry for a payout that the business's own books also record, via
     * {@see postGatewayWithdrawal()} with `$custody: true`. Both are needed and
     * neither duplicates the other: the business is recording that money left its
     * account at Storify, and we are recording that we are no longer holding it.
     * Before this change there was only one entry, because there was only one party
     * holding the money.
     *
     * A different idempotency key from the business-side entry on purpose. The keys
     * are scoped per business and this one's scope is `null`, where the uniqueness
     * index is not enforced by the database — so a shared key would be a claim
     * nothing checks.
     */
    public function postCustodyPayoutOnPlatformBooks(
        int $amountKobo,
        string $reference,
        ?int $storeId = null,
        ?int $userId = null,
        int $feeKobo = 0,
    ): ?JournalEntry {
        if ($amountKobo <= 0) {
            return null;
        }

        $feeKobo = max(0, min($feeKobo, $amountKobo));
        $netKobo = $amountKobo - $feeKobo;

        $lines = [
            [
                'account_key' => 'business_funds_held',
                'debit' => $amountKobo,
                'store_id' => $storeId,
                'description' => 'Payout '.$reference,
            ],
        ];

        if ($netKobo > 0) {
            $lines[] = [
                'account_key' => 'gateway_clearing',
                'credit' => $netKobo,
                'store_id' => $storeId,
                'description' => 'Payout '.$reference,
            ];
        }

        if ($feeKobo > 0) {
            // The fee stays with Storify: it is not money still owed to the
            // business, and it never left our provider balance. Recognising it as
            // service-charge income — the same account commission lands on — keeps
            // the documented identity `1030 = 2300 + 4020 − 5510` true, because
            // 2300 falls by gross while 1030 falls only by net.
            $lines[] = [
                'account_key' => 'service_charge_income',
                'credit' => $feeKobo,
                'store_id' => $storeId,
                'description' => 'Payout fee '.$reference,
            ];
        }

        return $this->post(null, $lines, [
            'memo' => 'Payout to business '.$reference,
            'reference' => $reference,
            'idempotency_key' => 'custody_payout:'.$reference,
            'user_id' => $userId,
        ]);
    }

    /**
     * A collected sale came back, so the platform's side of it is undone.
     *
     *     Dr 2300 Funds Held for Businesses   net
     *     Dr 4020 Service Charge Income       commission
     *     Dr 2210 Provider Fees Payable       fee        (own-keys sales)
     *     Cr 1030 Payment Gateway Clearing    gross
     *
     * — which is {@see postCustodyCollection()} backwards, and is written as a
     * reversal of it rather than as a second hand-written set of lines so the two
     * can never drift apart. A hand-written pair is how a fee line gets dropped
     * from one direction only, and the resulting difference sits on the platform's
     * clearing balance until somebody notices a business was paid too much.
     *
     * **And the fee's own entry is reversed alongside it.** For a sale we charged,
     * the provider's fee was posted as a separate entry
     * ({@see postCustodyProviderFeeSettled()}) rather than inside the collection,
     * so reversing the collection alone would leave 1030 short by the fee and 5510
     * carrying a cost for a charge that was given back. Both are reversed here,
     * together, so the two halves of one refund cannot come apart.
     *
     * That second reversal is a no-op on a sale whose fee entry was never posted,
     * and it assumes **the provider returns its fee to us when a charge is
     * refunded**. The books assumed the same before this change — the fee used to
     * be reversed as part of the collection entry — so this preserves the
     * assumption rather than introducing it, and it is deliberately left standing
     * on it: a business's refunds mostly go back out of its own bank rather than
     * through us, so a refund on this path is the exception rather than the rule,
     * and these books stay consistent with the ones already written.
     *
     * If the provider turns out to keep its fee on a platform-processed refund,
     * the cost should stand instead and this second `reverseByKey()` goes — the
     * two behaviours are one call apart, and nothing else in the method depends
     * on it.
     *
     * **Both a refund and a chargeback post this.** They are the same movement on
     * our books: money we took in goes back out, we stop holding the business's
     * share, and we give up the commission with it. What differs is who decided —
     * us, or the customer's bank — and that difference is recorded on the
     * transaction and in the activity trail rather than in the journal, because it
     * makes no difference to a single figure.
     *
     * The business's own books are reversed separately, by {@see postRefund()},
     * which finds `payment:txn:{id}` and voids it. Two entries, two books, neither
     * duplicating the other.
     *
     * Idempotent by key, so a redelivered `dispute.updated` and a refund on the
     * same sale cannot post the reversal twice — each of the two keys is claimed
     * once. `$newKey` is passed in so the two callers can be distinguished in the
     * journal, which is the only place that difference is ever legible.
     */
    public function postCustodyCollectionReturned(
        Transaction $transaction,
        string $reference,
        ?string $newKey = null,
        ?int $userId = null,
    ): ?JournalEntry {
        $reversal = $this->reverseByKey(
            null,
            'managed:collect:txn:'.$transaction->id,
            'Collection returned '.$reference,
            $newKey ?? ('custody_return:txn:'.$transaction->id),
            $userId,
        );

        if ($reversal) {
            $this->reverseByKey(
                null,
                'managed:provider_fee:txn:'.$transaction->id,
                'Provider fee returned '.$reference,
                'custody_return_fee:txn:'.$transaction->id,
                $userId,
            );
        }

        return $reversal;
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
     * Reverse whatever was posted under a given idempotency key, if anything was.
     *
     * For the callers that know the key but not the entry: a payout that came back
     * after landing knows its own reference and nothing else, and hunting the entry
     * down by hand at each such call site is how one of them eventually hunts in
     * the wrong scope. Null when there is nothing posted under that key, which is a
     * legitimate answer — the original posting may have failed and been logged,
     * and inventing a reversal for a non-existent entry would post money.
     */
    public function reverseByKey(
        ?int $businessId,
        string $idempotencyKey,
        string $memo,
        ?string $newKey = null,
        ?int $userId = null,
    ): ?JournalEntry {
        $entry = JournalEntry::query()
            ->where('business_id', $businessId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $entry) {
            return null;
        }

        return $this->reverseEntry($entry, $memo, $userId, $newKey);
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

    /**
     * Which asset account holds a platform-managed sale's money.
     *
     * The whole of the custody distinction, in one line, because it is one
     * question: does the money sit at the provider on the business's own account
     * (`1030`, split-at-source), or did Storify collect it and now owe it
     * (`1040`, custody)? Everything else about a managed sale — the fees, the
     * receivable, the descriptions the business reads — is identical either way,
     * which is why this is a key swap and not a second family of posting methods.
     *
     * `$custody` is passed in rather than read off the transaction: the caller
     * settles a payment and decides custody once, and both the wallet and the
     * journal must act on that one reading. Re-deriving it here would let the two
     * halves of a single settlement disagree if the flag were flipped in between,
     * and the disagreement would be money in the wallet that no account holds.
     */
    private function managedAssetKey(bool $custody): string
    {
        return $custody ? 'held_by_platform' : 'gateway_clearing';
    }

    /**
     * Which asset account a payment leg debited.
     *
     * Asked of the catalogue rather than matched against names. Paystack used
     * to be named literally here, so a Flutterwave, Korapay, Squad, Monnify or
     * Bitfra sale fell through to `cash` — a gateway's takings booked into the
     * drawer account, which is both wrong and invisible, since the drawer then
     * appears to hold money the till never had.
     *
     * Every provider with a hosted checkout clears through the one gateway
     * account; manual bank transfer settles into a bank; anything else is cash.
     * Removing a provider from the catalogue, or adding one, now needs no
     * change here.
     */
    private function paymentAssetKey(Transaction $transaction): string
    {
        // A sale Storify collected for is held by Storify, whatever rail the
        // money came over — a hosted checkout and a card at the till are the same
        // arrangement, and 1030 would say the business's own gateway account is
        // holding money that never went there. Checked first, so a custody sale
        // cannot be routed by its leg method into the wrong asset.
        //
        // The one condition, and the second one it used to carry was the bug. A
        // business under custody can still charge a till leg on its own keys, and
        // that money really does land in its own provider account — but
        // `isPlatformManaged()` is already false for that leg, because it was
        // charged on the business's own keys and the row says so. Adding
        // `custody_enabled` on top therefore excluded nothing real and instead
        // misrouted every collected sale that was still in flight when the business
        // was rolled back: money in Storify's balance booked against a gateway
        // account of the business's that holds none of it.
        if ($transaction->isPlatformManaged()) {
            return 'held_by_platform';
        }

        $legMethod = (string) ($transaction->metadata['leg_method'] ?? '');
        $code = $transaction->paymentMethod?->code;

        foreach (array_filter([$legMethod, $code]) as $method) {
            if ($method === 'transfer' || $method === 'bank_transfer') {
                return 'bank';
            }

            if (PaymentGatewayRegistry::has($method)
                && PaymentGatewayRegistry::checkoutModeFor($method) === 'redirect') {
                return 'gateway_clearing';
            }
        }

        // `card` and `gateway` are the POS client's older, provider-less names
        // for the same thing; tills in the field still send them.
        if (in_array($legMethod, ['card', 'gateway'], true) || in_array($code, ['card', 'gateway'], true)) {
            return 'gateway_clearing';
        }

        return 'cash';
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
