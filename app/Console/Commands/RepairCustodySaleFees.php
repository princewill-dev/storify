<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\Payments\Custody\CustodyService;
use App\Services\Payments\PaymentWebhookProcessor;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Money\Naira;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Prices the collected sales whose provider fee never arrived.
 *
 * A managed sale is credited to its business's wallet by
 * {@see CustodyService::recordCollectedSale()}, which refuses to work from a fee
 * it does not have: a partial subtraction is a wrong number, and a wrong number in
 * a wallet is a support ticket. So the sale waits — and it can wait for ever, in
 * two different ways that both end here.
 *
 * 1. The writer read the fee off the wrong field of the provider's payload, so it
 *    was never going to arrive. Nothing retries that.
 * 2. The provider genuinely had not worked the fee out yet when the sale settled,
 *    and the sale is a till or webhook leg that a later call would price — but
 *    {@see PaymentWebhookProcessor::settle()} short-circuits
 *    on the transaction's status, so the delivery that would have carried the fee
 *    is a no-op by the time it arrives.
 *
 * Either way the repair is the same: ask the provider again, and write what it
 * says. `custody:promote` then picks the sale up on its next pass through
 * {@see CustodyService::salesAwaitingFee()} and credits the wallet, so this
 * command moves no money itself — it only removes the reason the wallet could not
 * be credited.
 *
 * ## Which fee gets written
 *
 * The fee `fee_kobo` records is the fee **this sale bore**, not necessarily the fee
 * the provider charged. Those are the same thing now and were not always: Bachs
 * reports `amount − fees.amount` as `settlement_amount`, which is what actually
 * reached the platform's balance, and who funded the fee decides whether that is
 * the whole amount we billed for or less. On the sale that prompted this command,
 * a ₦24,725.00 order was collected as ₦25,095.88 with a ₦370.88 fee and settled at
 * ₦24,725.00 — the fee was added on top and the customer paid it, so the sale bore
 * none of it and subtracting it would have underpaid the business by ₦370.88.
 *
 * Both cases are therefore read from the provider's own numbers rather than
 * assumed from the configuration: a charge collected for **more** than we billed
 * means the fee was on top, and one collected for exactly what we billed means it
 * came out of the sale. `net_kobo` is then written through
 * {@see Transaction::netKoboFor()}, so the row keeps the same rule as every other
 * writer and `net = amount − fee_kobo − commission` stays true of it.
 *
 * ## What it will not do
 *
 * Price a sale the provider says is not paid, or one it has collected less for than
 * we billed; write a fee the provider has still not reported; invent a commission
 * that was never recorded; touch `amount`, `currency`, `status`, `paid_at` or
 * `gateway_response`; move money; or post a journal entry. It is a repair of two
 * derived columns, and the wallet credit stays where it already lives.
 *
 * It reports by default. `--apply` is the deliberate second run.
 */
class RepairCustodySaleFees extends Command
{
    protected $signature = 'custody:repair-sale-fees
        {--apply : Write the fee and the net instead of only reporting them}
        {--business= : Limit to a single business id}
        {--transaction= : Repair a single transaction id}
        {--limit=0 : Stop after this many transactions (0 for no limit)}';

    protected $description = 'Re-read the provider\'s charge for collected sales that settled with no fee';

    public function __construct(
        private readonly CustodyService $custody,
        private readonly PlatformBillingGateway $billing,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $businessId = $this->option('business') ? (int) $this->option('business') : null;
        $transactionId = $this->option('transaction') ? (int) $this->option('transaction') : null;
        $limit = (int) $this->option('limit');

        $sales = $this->custody->salesAwaitingFee()
            ->with('paymentMethod')
            ->when($businessId !== null, fn ($query) => $query->where('business_id', $businessId))
            ->when($transactionId !== null, fn ($query) => $query->whereKey($transactionId))
            ->orderBy('id')
            ->when($limit > 0, fn ($query) => $query->limit($limit))
            ->get();

        $tally = [];
        $lines = [];

        foreach ($sales as $transaction) {
            $priced = $this->price($transaction);
            $status = $priced['status'];
            $tally[$status] = ($tally[$status] ?? 0) + 1;

            if ($status === 'priceable' && $apply) {
                $written = Transaction::query()
                    ->whereKey($transaction->id)
                    // Claimed the same way the wallet's own credit claims it. A
                    // run of `custody:promote` in flight beside this one can
                    // settle the sale first, and overwriting a settled row would
                    // move a number the wallet has already been credited from.
                    ->whereNull('fee_kobo')
                    ->whereNull('settle_at')
                    ->update([
                        'fee_kobo' => $priced['fee'],
                        // The query builder again rather than `save()`, for the
                        // reason the sibling repair gives: nothing about the
                        // transaction changed, so `updated_at` should not move.
                        'net_kobo' => $priced['net'],
                    ]);

                if ($written === 0) {
                    $status = 'raced';
                    $tally['priceable']--;
                    $tally['raced'] = ($tally['raced'] ?? 0) + 1;
                } else {
                    Log::info('custody.sale_repriced', [
                        'transaction_id' => $transaction->id,
                        'business_id' => $transaction->business_id,
                        'fee_kobo' => $priced['fee'],
                        'net_kobo' => $priced['net'],
                        'provider_fee_kobo' => $priced['reported_fee'],
                    ]);
                }
            }

            $lines[] = [
                $transaction->id,
                (string) $transaction->business_id,
                $transaction->settlementProviderCode() ?: '—',
                $priced['fee'] ?? '—',
                $priced['net'] ?? '—',
                $priced['reported_fee'] ?? '—',
                $status,
            ];
        }

        if ($lines !== []) {
            $this->table(
                ['id', 'business', 'provider', 'fee', 'net', 'provider fee', $apply ? 'written' : 'would write'],
                $lines,
            );
        }

        $this->report($tally, $sales->count(), $apply);

        return self::SUCCESS;
    }

    /**
     * What the provider says about one sale, and the two derived columns that
     * follow from it.
     *
     * @return array{status: string, fee?: int, net?: int, reported_fee?: int}
     */
    private function price(Transaction $transaction): array
    {
        $code = $transaction->settlementProviderCode();

        if ($code === '') {
            return ['status' => 'no provider on the row'];
        }

        if ($this->billing->credentialProblem($code) !== null) {
            // Names the missing field, never a value, and says which provider —
            // this is the one status an operator has to fix rather than wait on.
            return ['status' => "platform cannot call {$code}"];
        }

        $driver = $this->billing->driverFor($code);

        if ($driver === null) {
            return ['status' => 'no driver'];
        }

        // The stored reference first: a provider that issued its own id at
        // initialize has no lookup by ours, and `gateway_reference` holds the
        // provider's *name* for the payment rather than the id it accepts.
        $reference = $transaction->metadata['provider_reference'] ?? $transaction->gateway_reference;

        if (! is_string($reference) || $reference === '') {
            return ['status' => 'no reference to ask about'];
        }

        // Platform credentials, not a business connection's: a managed sale was
        // charged on the platform's own account, and the business's keys — if it
        // ever had any — never saw this payment.
        $verification = $driver->verify($reference, $this->billing->credentialsFor($code));

        if (! $verification->isPaid()) {
            // The provider does not call this paid. That is a question about the
            // sale, not about its fee, and nothing here should paper over it.
            return ['status' => 'provider says not paid'];
        }

        $reportedFee = $verification->feesMinor;

        if ($reportedFee === null) {
            return ['status' => 'provider still has not reported a fee'];
        }

        $collected = $verification->amountMinor;
        $billed = Naira::koboFromRounded($transaction->amount);

        if ($collected === null || $collected < $billed) {
            return ['status' => 'collected less than billed'];
        }

        // A commission that was never recorded cannot be recovered by inferring it
        // from the current setting — the admin may have changed the rate since, and
        // the row is priced at the rate in force when the customer paid. Skipped
        // rather than guessed, exactly as the fee itself is.
        $recorded = $transaction->platform_fee_kobo ?? ($transaction->metadata['platform_fee_kobo'] ?? null);

        if (! is_numeric($recorded)) {
            return ['status' => 'no commission recorded'];
        }

        // `$collected > $billed` is the grossed-up case: Bachs added the fee on top
        // and the customer paid it, so the platform received the whole amount we
        // billed and this sale bore none of it. Equal amounts are the ordinary
        // case, and the fee came out of the sale.
        $fee = $collected > $billed ? 0 : $reportedFee;

        return [
            'status' => 'priceable',
            'fee' => $fee,
            'net' => Transaction::netKoboFor($billed, $fee, max(0, (int) $recorded)),
            'reported_fee' => $reportedFee,
        ];
    }

    /**
     * @param  array<string, int>  $tally
     */
    private function report(array $tally, int $examined, bool $apply): void
    {
        $priceable = $tally['priceable'] ?? 0;

        $this->info("Sales awaiting a fee: {$examined}");
        $this->info('Priceable '.($apply ? 'and written' : 'and to write').": {$priceable}");

        if ($priceable > 0) {
            $this->info(
                $apply
                    ? 'Their wallets are credited by the next `custody:promote` pass, which is where the money moves.'
                    : 'Run again with --apply to write their fee and net.'
            );
        }

        if (($tally['raced'] ?? 0) > 0) {
            $this->warn(
                'Settled by another run while this one was reading: '.$tally['raced']
                .' — their wallets were credited by whoever got there first, and nothing was overwritten.'
            );
        }

        foreach ($tally as $status => $count) {
            if (in_array($status, ['priceable', 'raced'], true)) {
                continue;
            }

            $this->warn("{$status}: {$count}");
        }

        if (($tally['provider still has not reported a fee'] ?? 0) > 0) {
            $this->line(
                '  The provider has not worked those fees out yet. Their net stays empty, which is the honest'
                .' answer — run this again once it has.'
            );
        }

        if (($tally['no commission recorded'] ?? 0) > 0) {
            $this->line(
                '  A managed sale with no commission recorded is not something this command can price —'
                .' inferring one from today\'s rate would price it at the wrong rate. Inspect these by hand.'
            );
        }
    }
}
