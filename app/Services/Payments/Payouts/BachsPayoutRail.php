<?php

namespace App\Services\Payments\Payouts;

use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Gateways\BachsApi;
use App\Support\Money\Naira;
use Illuminate\Support\Facades\Log;

/**
 * Bachs, with the payout destination held at the platform rather than per
 * business.
 *
 * This is the change that makes a processor switch invisible. The old path
 * registered a destination against a *connected account* — an account that had
 * to be created, furnished with the owner's identity documents, and approved
 * before it could receive a naira. Nothing was payable until that finished, and a
 * business approved at Bachs was unknown at Paystack. Registering the destination
 * at the platform removes the object that had to pre-exist, and with it the
 * switchover problem.
 *
 * The two calls differ from the old ones in exactly one way each: the destination
 * is not scoped to an account, and neither is the payout.
 */
final class BachsPayoutRail implements PayoutRail
{
    private const CURRENCY = 'NGN';

    public function code(): string
    {
        return 'bachs';
    }

    /**
     * `POST /v1/payouts/destinations`, on the platform's own organisation.
     *
     * **No `X-Account-Id`, and the empty string is what guarantees it.** The
     * header is what turns a request into one made *as* a business, and it is
     * attached automatically whenever the credentials carry an account — so a
     * payout rail that inherited a business's credentials out of a shared
     * container would register a destination on the wrong organisation and pay
     * out of the wrong balance, silently, with a 200. Passing `''` explicitly
     * defeats that fallback rather than relying on the platform credentials
     * happening not to have an id today.
     *
     * Idempotence is the provider's, not ours: registering the same bank twice
     * against the same organisation returns the destination that already exists,
     * which is why the sweep can call this on every run without accumulating
     * destinations.
     */
    public function ensureDestination(
        GatewayCredentials $credentials,
        string $accountNumber,
        string $bankCode,
        ?string $accountName,
        string $currency = self::CURRENCY,
    ): PayoutDestinationRef {
        $submitted = array_filter([
            'currency' => $currency,
            'type' => 'bank_account',
            'account_number' => $accountNumber,
            'account_name' => $accountName,
            'bank_code' => $bankCode,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $response = BachsApi::client($credentials)
                ->post(BachsApi::baseUrl($credentials).'/payouts/destinations', $submitted);
        } catch (\Throwable $e) {
            Log::error('bachs.payout.destination_unreachable', ['error' => $e->getMessage()]);

            return PayoutDestinationRef::refused('Bachs could not be reached to register this bank account.');
        }

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            Log::warning('bachs.payout.destination_refused', [
                'status' => $response->status(),
                'code' => $body['code'] ?? null,
            ]);

            return PayoutDestinationRef::refused(
                (string) ($body['message'] ?? 'Bachs refused this bank account for payouts.'),
                $body,
            );
        }

        // Top level first, then `data`. Bachs returns destination fields at the
        // top level of this response rather than wrapped, but the account
        // services read the id the same defensive way — a provider that moves a
        // field into an envelope should not silently stop paying a business.
        $id = $body['id'] ?? $body['data']['id'] ?? null;

        if (! is_string($id) || $id === '') {
            Log::error('bachs.payout.destination_without_id', ['keys' => array_keys($body)]);

            return PayoutDestinationRef::refused(
                'Bachs registered the bank account but did not return its id. It has been logged.',
                $body,
            );
        }

        return PayoutDestinationRef::registered(
            $id,
            isset($body['is_usable']) ? (bool) $body['is_usable'] : null,
            $body,
        );
    }

    /**
     * `POST /v1/payouts`, paid from the platform's balance.
     *
     * `amount` is what the destination receives, in naira as a decimal string —
     * Bachs does not take kobo — and the provider's own fee is added on top, so
     * the platform balance has to cover more than the number sent. That is the
     * reason the sweep may only draw on settled funds: a transfer the balance
     * cannot cover is refused outright.
     *
     * A `409` is *success*, not failure. It means this exact reference is still
     * in flight, which is the idempotency key doing its job — and reporting it as
     * a failure would make the caller restore the wallet reservation and send a
     * genuinely second payout afterwards. The transfer exists; we simply have not
     * been told its id yet.
     */
    public function send(
        GatewayCredentials $credentials,
        string $destinationId,
        int $amountKobo,
        string $reference,
        string $currency = self::CURRENCY,
    ): TransferOutcome {
        try {
            $response = BachsApi::client($credentials)
                ->withHeaders(['Idempotency-Key' => $reference])
                ->post(BachsApi::baseUrl($credentials).'/payouts', [
                    'destination' => $destinationId,
                    'amount' => Naira::decimalFromKobo($amountKobo),
                    'reference' => $reference,
                ]);
        } catch (\Throwable $e) {
            // **Not a refusal.** The request may have been received and acted on
            // before the connection dropped, and Bachs will have moved money. The
            // caller keeps the reservation and resolves this by lookup or webhook.
            Log::warning('bachs.payout.unreachable', ['reference' => $reference, 'error' => $e->getMessage()]);

            return TransferOutcome::indeterminate('Bachs could not be reached, so this payout\'s fate is unknown.');
        }

        $body = $response->json() ?? [];
        $code = strtoupper((string) ($body['code'] ?? ''));

        if (! $response->successful()) {
            // 409 means this exact reference is still in flight — the idempotency
            // key doing its job. Nothing has been paid twice; we simply have not
            // been told the id yet.
            if ($response->status() === 409 || $code === 'IDEMPOTENCY_IN_PROGRESS') {
                return TransferOutcome::accepted(null, 'processing', $body);
            }

            // A 5xx is the provider failing, not the provider refusing: it tells us
            // nothing about whether the payout was created. Only a 4xx is an
            // answer about *this* payout.
            if ($response->serverError()) {
                Log::warning('bachs.payout.server_error', [
                    'reference' => $reference,
                    'status' => $response->status(),
                ]);

                return TransferOutcome::indeterminate(
                    'Bachs returned a server error, so this payout\'s fate is unknown.',
                    $body,
                );
            }

            Log::warning('bachs.payout.refused', [
                'reference' => $reference,
                'status' => $response->status(),
                'code' => $code ?: null,
            ]);

            return TransferOutcome::refused(
                (string) ($body['message'] ?? 'Bachs refused this payout.'),
                $code ?: null,
                $body,
            );
        }

        return TransferOutcome::accepted(
            transferId: isset($body['id']) ? (string) $body['id'] : null,
            providerStatus: strtolower((string) ($body['status'] ?? 'pending')),
            raw: $body,
        );
    }

    /**
     * `GET /v1/balances` — the platform's own organisation, no account header.
     *
     * Same endpoint the connected-account era read, minus the `X-Account-Id`: this
     * is the balance of the organisation the charges land in, which is Storify.
     *
     * **`available_balance` and not `pending_balance` is the whole answer.** Bachs
     * reports both, and its documentation is explicit that a pending amount is
     * charged but not settled and *cannot be moved* — a transfer drawn against it
     * is refused with `INSUFFICIENT_BALANCE`. So the number that matters here is the
     * spendable one, and a charge that has arrived but not settled is deliberately
     * invisible to this call. The provider's own settlement schedule
     * (`pending_settlements_by_day`, on the same response) is what a run reporting
     * "nothing to promote" should be read against.
     *
     * A successful response naming no naira entry is **null, not zero**. The
     * distinction is not pedantry: zero here would gate the whole platform's
     * payouts off, while null only falls back to the conservative settlement
     * window. An envelope we do not recognise is a thing we failed to read, and it
     * must degrade to the slow path rather than to a freeze. A naira entry that
     * genuinely reads `"0.00"` is the zero case.
     *
     * @return int|null kobo, or null when the balance could not be read
     */
    public function availableBalance(GatewayCredentials $credentials): ?int
    {
        try {
            $response = BachsApi::client($credentials)
                ->get(BachsApi::baseUrl($credentials).'/balances');
        } catch (\Throwable $e) {
            Log::warning('bachs.payout.balance_unreadable', ['error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('bachs.payout.balance_refused', ['status' => $response->status()]);

            return null;
        }

        $body = $response->json() ?? [];

        // Top level first, then `data`, for the same reason the destination read
        // does: a provider that moves a field into an envelope should not silently
        // stop the platform being able to see its own money.
        $balances = $body['balances'] ?? $body['data']['balances'] ?? [];

        if (! is_array($balances)) {
            Log::warning('bachs.payout.balance_unreadable', ['keys' => array_keys($body)]);

            return null;
        }

        foreach ($balances as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (strtoupper((string) ($entry['currency'] ?? '')) !== self::CURRENCY) {
                continue;
            }

            // A decimal string at the currency's precision, never kobo — the same
            // conversion the payout send makes in the other direction.
            return Naira::koboFromStrict($entry['available_balance'] ?? 0);
        }

        Log::warning('bachs.payout.balance_without_naira', ['currencies' => array_column(array_filter($balances, 'is_array'), 'currency')]);

        return null;
    }

    /**
     * `GET /v1/payouts/{id}` — **and only by id**.
     *
     * Bachs offers no lookup by our own reference; the reference appears on the
     * payout object we get back and on its webhooks, but it is not a query key.
     * So a send that timed out before returning an id cannot be resolved here at
     * all, which is why this returns null and the caller waits for the webhook —
     * which does carry the reference (`data.reference`, null only when none was
     * supplied, and we always supply one).
     *
     * Statuses are `pending`, `processing`, `completed`, `failed`. The first three
     * all mean the provider still has it and the money may yet move, so they all
     * come back `accepted` and it is the caller that decides whether to keep
     * waiting. Only `failed` — with a `failure_reason` — is a refusal.
     */
    public function lookUp(
        GatewayCredentials $credentials,
        string $reference,
        ?string $providerPayoutId = null,
    ): ?TransferOutcome {
        if ($providerPayoutId === null || $providerPayoutId === '') {
            return null;
        }

        try {
            $response = BachsApi::client($credentials)
                ->get(BachsApi::baseUrl($credentials).'/payouts/'.$providerPayoutId);
        } catch (\Throwable $e) {
            Log::warning('bachs.payout.lookup_unreachable', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json() ?? [];
        $status = strtolower((string) ($body['status'] ?? ''));

        if ($status === 'failed') {
            return TransferOutcome::refused(
                (string) ($body['failure_reason'] ?? 'Bachs reports this payout failed.'),
                isset($body['failure_code']) ? (string) $body['failure_code'] : null,
                $body,
            );
        }

        return TransferOutcome::accepted($providerPayoutId, $status ?: 'pending', $body);
    }
}
