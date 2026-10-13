<?php

namespace App\Services\Payments\Connect;

use App\Models\Transaction;
use App\Services\ActivityRecorder;
use App\Services\Payments\Custody\CustodyService;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\Log;

/**
 * The provider deliveries that are not a payment and not a payout.
 *
 * Two kinds land here, and they have one thing in common: neither is about an
 * order, so neither can be settled, and both are still things somebody has to be
 * able to see afterwards.
 *
 * - **A chargeback** (`dispute.*`). Money has already moved or is about to — the
 *   provider debits a flat dispute fee the moment one is recorded — and there is
 *   a deadline to answer. What this does is record it faithfully, raise it in the
 *   activity log, and, **once it is lost**, take the business's share back out of
 *   its wallet: the dispute is filed against Storify's merchant account, so a
 *   lost one is Storify's money going out, and the business's share goes with it.
 *   Submitting evidence is a human decision about a real customer and is
 *   deliberately **not** automated.
 * - **A refund's outcome** (`refund.*`). Our refund call returns as soon as the
 *   provider accepts it, which is not the same as the customer being paid: the
 *   amount is only *reserved* at that point. `refund.failed` is the delivery that
 *   says the money did not arrive, and without it a failed refund looks exactly
 *   like a successful one.
 *
 * ## Both are the platform's, and the third kind is gone
 *
 * There used to be a third: the business's own account at the provider, whose
 * state arrived as `account.updated` and `capability.updated` and was stored on
 * the connection so a restricted capability could stop the storefront offering
 * that method. There is no such account — see the resolver — so there is no state
 * to keep and no connection to keep it on. Those deliveries now arrive, verify,
 * and do nothing, which is exactly right: they are about a thing that no longer
 * exists and nothing depends on.
 *
 * Every lookup below is therefore by the **charge**, and every fact it needs —
 * whose sale it was, whether Storify collected it, how much the business was
 * credited — comes off the transaction. A transaction is unique to one charge and
 * carries all of it, which is why nothing here needs to know about an account.
 */
final class ConnectAccountEventService
{
    /**
     * The dispute outcomes that mean the money is gone for good.
     *
     * The provider's vocabulary, lowercased, and only the terminal one. A
     * dispute passes through `needs_response` and `under_review` first, and in
     * neither of those states is the money certainly lost — a dispute that is
     * answered and won costs the business nothing. Taking the business's share
     * at `needs_response` and giving it back at `won` would be two movements for
     * one outcome, and the second would be a *credit* to a business that had
     * already been paid out in between.
     *
     * `won` is deliberately absent rather than listed as a no-op: it is the
     * status that says the money stayed with us, and there is nothing to do.
     */
    private const DISPUTE_LOST = ['lost'];

    public function __construct(
        private readonly CustodyService $custody,
    ) {}

    /**
     * Record a chargeback against a charge the platform itself made.
     *
     * ## Why this exists at all
     *
     * Under split-at-source a chargeback was always about a business's own
     * account, so it arrived with an account id and there was a connection to
     * file it against. Under collect-and-pay-out the charge is Storify's own: the
     * dispute is filed against **Storify's** merchant account, it debits
     * **Storify's** balance, and the delivery names either the platform's account
     * — which has no connection row, because it is not a business — or no
     * account at all. Both used to fall through as "not ours", which for a
     * chargeback is the one delivery whose loss leaves money moving with nothing
     * recorded against it anywhere.
     *
     * ## What identifies it
     *
     * The charge, not the account. A transaction is unique to one charge and
     * carries both its business and its money, so finding it answers every
     * question this needs: whose sale it was, whether Storify collected it, and
     * how much the business was credited.
     *
     * There is no connection to store the payload on and none is wanted — a
     * per-business dispute list was the *business's* own screen, and that screen
     * listed the business's own account's disputes, which no longer exist. It is
     * kept where it can be acted on: the activity trail, and the transaction
     * itself. Submitting evidence happens at the provider's dashboard, against
     * the charge, which is why the reference is what the trail carries.
     *
     * @param  array<string, mixed>  $data
     * @return bool whether a charge was identified
     */
    public function recordPlatformDispute(array $data, string $type): bool
    {
        $entry = $this->disputeEntry($data);

        if ($entry === null) {
            Log::warning('bachs.dispute.without_id', ['type' => $type, 'scope' => 'platform']);

            return false;
        }

        $transaction = $this->transactionForCharge($entry['charge_id'] ?? null);

        if ($transaction === null) {
            Log::warning('bachs.dispute.unknown_charge', [
                'dispute_id' => $entry['id'],
                'charge_id' => $entry['charge_id'] ?? null,
                'type' => $type,
            ]);

            return false;
        }

        $this->noteDispute($entry, $type, (string) $transaction->reference, $transaction->business_id, $transaction);

        return true;
    }

    /**
     * Record a refund's outcome against a charge the platform itself made.
     *
     * The mirror of {@see recordPlatformDispute()} and needed for the same
     * reason — a custody refund's outcome is delivered against the platform's
     * own account. Nothing is stored on a connection, and nothing moves: the
     * refund itself was posted by whoever made it, and this delivery only says
     * whether it arrived.
     *
     * @param  array<string, mixed>  $data
     * @return bool whether a charge was identified
     */
    public function recordPlatformRefund(array $data, string $type): bool
    {
        $refundId = $this->stringValue($data, 'refund_id', 'id');

        if ($refundId === null) {
            Log::warning('bachs.refund.without_id', ['type' => $type, 'scope' => 'platform']);

            return false;
        }

        $transaction = $this->transactionForCharge($this->stringValue($data, 'charge_id', 'checkout_id'));

        if ($transaction === null) {
            Log::warning('bachs.refund.unknown_charge', [
                'refund_id' => $refundId,
                'charge_id' => $this->stringValue($data, 'charge_id'),
                'type' => $type,
            ]);

            return false;
        }

        $status = strtolower($this->stringValue($data, 'status') ?? '');

        if ($status !== 'failed') {
            return true;
        }

        ActivityRecorder::record(
            action: 'payment_gateway.refund_failed',
            description: 'Bachs could not complete refund '.$refundId.' on '.$transaction->reference,
            new: [
                'refund_id' => $refundId,
                'reference' => $transaction->reference,
                'requested_amount' => $this->stringValue($data, 'requested_amount'),
                'reason' => $this->stringValue($data, 'failure_reason', 'reason'),
            ],
            metadata: ['provider' => 'bachs', 'event' => $type],
            businessId: $transaction->business_id,
        );

        Log::error('bachs.refund.failed', [
            'refund_id' => $refundId,
            'reference' => $transaction->reference,
        ]);

        return true;
    }

    /**
     * The dispute as it is stored and acted on, or null when it names no id.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    private function disputeEntry(array $data): ?array
    {
        $disputeId = $this->stringValue($data, 'dispute_id', 'id');

        if ($disputeId === null) {
            return null;
        }

        return array_filter([
            'id' => $disputeId,
            'charge_id' => $this->stringValue($data, 'charge_id', 'checkout_id'),
            'amount' => $this->stringValue($data, 'amount'),
            'amount_kobo' => ($amount = $this->stringValue($data, 'amount')) === null
                ? null
                : Naira::koboFromStrict($amount),
            'currency' => $this->stringValue($data, 'currency'),
            'status' => $this->stringValue($data, 'status'),
            'reason' => $this->stringValue($data, 'reason'),
            'is_response_editable' => $data['is_response_editable'] ?? null,
            'response_deadline_at' => $this->stringValue($data, 'response_deadline_at'),
            'opened_at' => $this->stringValue($data, 'created_at'),
            'updated_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null);
    }

    /**
     * The trail entry, and the money — the part that is the same either way.
     *
     * `$subject` is what the dispute is filed against, and under collect-and-pay-out
     * that is always a **charge** rather than an account: it is our merchant
     * account's charge, and the reference is what somebody answering the dispute
     * will actually be looking for.
     *
     * @param  array<string, mixed>  $entry
     */
    private function noteDispute(
        array $entry,
        string $type,
        string $subject,
        ?int $businessId,
        ?Transaction $transaction = null,
    ): void {
        $disputeId = (string) $entry['id'];
        $disputeStatus = (string) ($entry['status'] ?? 'unknown');

        $this->recoverLostDispute($entry, $transaction, $disputeId);

        ActivityRecorder::record(
            action: 'payment_gateway.dispute_'.$disputeStatus,
            description: 'Bachs dispute '.$disputeStatus.' on charge '.$subject,
            // Never `$data` wholesale: a dispute payload carries the customer's
            // card and billing detail, and the audit log is readable.
            new: [
                'dispute_id' => $disputeId,
                'charge_id' => $entry['charge_id'] ?? null,
                'amount' => $entry['amount'] ?? null,
                'currency' => $entry['currency'] ?? null,
                'reason' => $entry['reason'] ?? null,
                'response_deadline_at' => $entry['response_deadline_at'] ?? null,
            ],
            metadata: ['provider' => 'bachs', 'event' => $type, 'charge' => $subject],
            businessId: $businessId,
        );

        Log::warning('bachs.dispute.recorded', [
            'charge' => $subject,
            'dispute_id' => $disputeId,
            'status' => $disputeStatus,
            'type' => $type,
        ]);
    }

    /**
     * Take a lost chargeback back out of the business's wallet.
     *
     * A chargeback the customer's bank has decided against us is Storify paying
     * the money back, so the business's share goes with it — the same movement a
     * refund makes, and through the same method, because on both books it is one
     * fact. See {@see CustodyService::unwindCollectedSale()}.
     *
     * **Only on a lost dispute.** Everything earlier in a dispute's life is a
     * question, not an answer, and the provider may well have already moved the
     * money out of our balance while it is being asked — which is a cash-flow
     * fact for Storify and not yet a debt for the business. Moving early and
     * moving back would be two movements for one outcome, and the second is a
     * credit to a business that may have been paid out in between.
     *
     * The claim inside `unwindCollectedSale()` is what makes a redelivered
     * `dispute.updated` — the provider re-sends one whenever anything about it
     * changes — harmless. Nothing is recovered when the charge cannot be
     * identified, and that is reported loudly rather than passed over: money has
     * left Storify's balance and there is nobody recorded as owing it.
     *
     * @param  array<string, mixed>  $entry
     */
    private function recoverLostDispute(array $entry, ?Transaction $transaction, string $disputeId): void
    {
        if (! in_array(strtolower((string) ($entry['status'] ?? '')), self::DISPUTE_LOST, true)) {
            return;
        }

        $transaction ??= $this->transactionForCharge($entry['charge_id'] ?? null);

        if ($transaction === null) {
            Log::error('bachs.dispute.unrecoverable', [
                'dispute_id' => $disputeId,
                'charge_id' => $entry['charge_id'] ?? null,
                'amount_kobo' => $entry['amount_kobo'] ?? null,
            ]);

            return;
        }

        $this->custody->unwindCollectedSale($transaction, 'Dispute '.$disputeId);
    }

    /**
     * The transaction a provider charge id belongs to, if we have one.
     *
     * Three columns are asked because the id a provider quotes in a dispute is
     * not always the one we recorded: a charge has both a checkout and a charge
     * id, providers name either depending on the event, and Bachs' own deliveries
     * use the checkout id for settlement and the charge id here. `gateway_reference`
     * holds whichever the provider gave us at initialize; `gateway_response` holds
     * whatever its verify returned. Asking all three is cheaper than being wrong,
     * and the lookup is what decides whether Storify can recover the money.
     */
    private function transactionForCharge(?string $chargeId): ?Transaction
    {
        if ($chargeId === null || $chargeId === '') {
            return null;
        }

        return Transaction::query()
            ->where(fn ($query) => $query
                ->where('gateway_reference', $chargeId)
                ->orWhere('gateway_response->id', $chargeId)
                ->orWhere('gateway_response->checkout_id', $chargeId))
            ->latest('id')
            ->first();
    }

    /**
     * The first of several possible keys that holds a non-empty string.
     *
     * Alias names rather than one canonical key, because the provider's webhook
     * payloads and its REST objects do not always agree on a field's name and a
     * lookup that insisted on one of them would silently record nothing.
     *
     * @param  array<string, mixed>  $data
     */
    private function stringValue(array $data, string ...$keys): ?string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_int($value) || is_float($value)) {
                return (string) $value;
            }
        }

        return null;
    }
}
