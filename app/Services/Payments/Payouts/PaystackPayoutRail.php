<?php

namespace App\Services\Payments\Payouts;

use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Gateways\PaystackApi;
use App\Services\Payments\Gateways\PaystackGateway;
use Illuminate\Support\Facades\Log;

/**
 * Paystack, transferring out of Storify's own balance.
 *
 * Two calls where Bachs needs two, but a different two: a **transfer recipient**
 * then a **transfer**. The recipient is Paystack's name for a payout destination,
 * and it is created from the same `bank_code` + `account_number` the Bachs rail
 * takes — which is the point. One stored bank account, two processors, and a
 * switch between them that changes nothing on the business's side.
 *
 * None of this code existed before. The old model charged *through* a Paystack
 * subaccount, so money reached the business by settlement and Storify never made
 * a transfer at all. The `subaccount` was the destination, and removing it is
 * what left the destination to be named here instead.
 *
 * Paystack answers `200` with `status: false` for business-level errors — a
 * refused transfer is a successful HTTP call — so the body's own flag is checked
 * alongside the status code, exactly as {@see PaystackGateway}
 * does.
 */
final class PaystackPayoutRail implements PayoutRail
{
    public function code(): string
    {
        return 'paystack';
    }

    /**
     * `GET /bank` — the codes Paystack will accept on a transfer recipient.
     *
     * **This list is where the platform's stored bank codes come from**, because
     * it is what the business picks a bank out of. Which means a code in
     * `store_banks` is a Paystack code by construction, and every other rail has
     * to translate it into its own space before it can be used; that translation is
     * {@see BankDirectory}'s job.
     *
     * `code` is what a transfer takes and `longcode` is the NIBSS number, which is
     * the reverse of Bachs' naming for the same two facts. `longcode` is used as
     * the cross-provider key for that reason — it is the one number both providers
     * agree on, where they agree at all.
     *
     * @return array<int, array{code: string, name: string, nibss: string|null}>
     */
    public function banks(GatewayCredentials $credentials): array
    {
        try {
            $response = PaystackApi::client($credentials)
                ->get(PaystackApi::baseUrl().'/bank', ['country' => 'nigeria']);
        } catch (\Throwable $e) {
            Log::warning('paystack.payout.banks_unreachable', ['error' => $e->getMessage()]);

            return [];
        }

        $body = $response->json() ?? [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            Log::warning('paystack.payout.banks_refused', [
                'status' => $response->status(),
                'message' => $body['message'] ?? null,
            ]);

            return [];
        }

        $rows = $body['data'] ?? [];

        if (! is_array($rows)) {
            Log::warning('paystack.payout.banks_unreadable', ['keys' => array_keys($body)]);

            return [];
        }

        $banks = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = $row['code'] ?? null;
            $name = $row['name'] ?? null;

            if (! is_string($code) && ! is_int($code)) {
                continue;
            }

            if (! is_string($name) || trim($name) === '') {
                continue;
            }

            $long = $row['longcode'] ?? null;

            $banks[] = [
                'code' => (string) $code,
                'name' => trim($name),
                'nibss' => is_string($long) && $long !== '' ? $long : null,
            ];
        }

        return $banks;
    }

    /**
     * `POST /transferrecipient`.
     *
     * Paystack deduplicates recipients by account number and bank, so a repeated
     * registration returns the recipient that already exists rather than
     * accumulating them. That is load-bearing for the same reason as on Bachs:
     * the send may call this on every attempt, and a destination is not something we
     * want one of per attempt.
     *
     * **Unverified, and not relied on.** Bachs' documentation makes the same claim
     * and it is measurably false there — three identical registrations returned
     * three ids — so the caller stores the destination id rather than counting on
     * this to hold.
     *
     * `name` is required by Paystack and is the recipient's display name in the
     * dashboard — the account name as the business gave it. It is sent when we
     * have it and omitted when we do not, because a payout with no name behind it
     * is one the provider should refuse at registration rather than bounce later.
     */
    public function ensureDestination(
        GatewayCredentials $credentials,
        string $accountNumber,
        string $bankCode,
        ?string $accountName,
        string $currency = 'NGN',
    ): PayoutDestinationRef {
        try {
            $response = PaystackApi::client($credentials)
                ->post(PaystackApi::baseUrl().'/transferrecipient', array_filter([
                    // NUBAN is the Nigerian account format; the only type this
                    // platform pays out to, so it is not parameterised.
                    'type' => 'nuban',
                    'name' => $accountName,
                    'account_number' => $accountNumber,
                    'bank_code' => $bankCode,
                    'currency' => $currency,
                ], fn ($value) => $value !== null && $value !== ''));
        } catch (\Throwable $e) {
            Log::error('paystack.payout.recipient_unreachable', ['error' => $e->getMessage()]);

            return PayoutDestinationRef::indeterminate('Paystack could not be reached to register this bank account.');
        }

        $body = $response->json() ?? [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            Log::warning('paystack.payout.recipient_refused', [
                'status' => $response->status(),
                // Paystack's messages are already addressed to a person
                // ("Account number is invalid"), so they are passed through
                // rather than replaced by one of ours.
                'message' => $body['message'] ?? null,
            ]);

            return PayoutDestinationRef::refused(
                (string) ($body['message'] ?? 'Paystack refused this bank account for payouts.'),
                $body,
            );
        }

        $code = $body['data']['recipient_code'] ?? null;

        if (! is_string($code) || $code === '') {
            Log::error('paystack.payout.recipient_without_code', ['keys' => array_keys($body['data'] ?? [])]);

            return PayoutDestinationRef::refused(
                'Paystack registered the bank account but did not return its recipient code. It has been logged.',
                $body,
            );
        }

        return PayoutDestinationRef::registered(
            $code,
            isset($body['data']['active']) ? (bool) $body['data']['active'] : null,
            $body['data'] ?? [],
        );
    }

    /**
     * `POST /transfer`, from `source: balance` — the platform's balance.
     *
     * `amount` is integer kobo, unlike Bachs, so the conversion happens only in
     * the ledger and never at this boundary.
     *
     * Idempotency here is the body's `reference`, not a header: Paystack has no
     * `Idempotency-Key`, and it enforces the reference by refusing a second
     * transfer that reuses one. A refusal on that basis is therefore **not** a
     * failure — a transfer with this reference already exists, and the only
     * reference that can reach this point is a ULID we minted and unique-indexed
     * on the payout row. Treating it as a failure would restore the wallet
     * reservation and send a second, genuine payout afterwards.
     *
     * Acceptance is not arrival: Paystack returns `pending` or `otp` here and the
     * money moves later, which is why the outcome carries the provider's own
     * status through rather than collapsing it.
     */
    public function send(
        GatewayCredentials $credentials,
        string $destinationId,
        int $amountKobo,
        string $reference,
        string $currency = 'NGN',
    ): TransferOutcome {
        try {
            $response = PaystackApi::client($credentials)
                ->post(PaystackApi::baseUrl().'/transfer', [
                    'source' => 'balance',
                    'amount' => $amountKobo,
                    'recipient' => $destinationId,
                    'reference' => $reference,
                    'currency' => $currency,
                    // What the transfer says it was for on the business's bank
                    // statement, so a business reading its own statement does not
                    // have to guess who paid it.
                    'reason' => 'Storify sales payout',
                ]);
        } catch (\Throwable $e) {
            // **Not a refusal.** A read timeout is the case that matters: Paystack
            // may have created the transfer and then failed to answer, and
            // releasing the reservation here is how the same debt gets paid twice.
            Log::warning('paystack.payout.unreachable', ['reference' => $reference, 'error' => $e->getMessage()]);

            return TransferOutcome::indeterminate('Paystack could not be reached, so this payout\'s fate is unknown.');
        }

        $body = $response->json() ?? [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            if (self::isDuplicateReference($body)) {
                Log::warning('paystack.payout.duplicate_reference', ['reference' => $reference]);

                return TransferOutcome::accepted(null, 'pending', $body);
            }

            // A 5xx is Paystack broken, not Paystack refusing — it says nothing
            // about whether the transfer was created. Only a 4xx does.
            if ($response->serverError()) {
                Log::warning('paystack.payout.server_error', [
                    'reference' => $reference,
                    'status' => $response->status(),
                ]);

                return TransferOutcome::indeterminate(
                    'Paystack returned a server error, so this payout\'s fate is unknown.',
                    $body,
                );
            }

            Log::warning('paystack.payout.refused', [
                'reference' => $reference,
                'status' => $response->status(),
                'code' => $data['code'] ?? null,
            ]);

            return TransferOutcome::refused(
                (string) ($body['message'] ?? 'Paystack refused this payout.'),
                isset($data['code']) ? (string) $data['code'] : null,
                $body,
            );
        }

        return TransferOutcome::accepted(
            transferId: isset($data['transfer_code']) ? (string) $data['transfer_code'] : null,
            providerStatus: isset($data['status']) ? (string) $data['status'] : null,
            raw: $body,
        );
    }

    /**
     * `GET /balance` — Storify's own balance, which is what a transfer draws on.
     *
     * Paystack reports the **available** figure only: there is no pending entry
     * beside it, because a successful charge is reflected here as soon as Paystack
     * accepts it. That is why this rail can be promoted against the moment a sale
     * is confirmed, while the settlement T+1 that matters to a *merchant's bank
     * account* never enters into it — {@see self::send()} draws on the balance, not
     * on a settlement.
     *
     * `status: false` is Paystack's way of refusing a business-level request with a
     * `200`, so the body's flag is checked alongside the status code, exactly as the
     * transfer below and {@see PaystackGateway} do. Any of those, or a response with
     * no naira entry at all, is null rather than zero: Paystack has not told us the
     * balance, and reading that as "nothing to pay with" would stop every payout.
     * A naira entry that genuinely reads zero returns `0`.
     *
     * @return int|null kobo, or null when the balance could not be read
     */
    public function availableBalance(GatewayCredentials $credentials): ?int
    {
        try {
            $response = PaystackApi::client($credentials)
                ->get(PaystackApi::baseUrl().'/balance');
        } catch (\Throwable $e) {
            Log::warning('paystack.payout.balance_unreadable', ['error' => $e->getMessage()]);

            return null;
        }

        $body = $response->json() ?? [];

        if (! $response->successful() || ! ($body['status'] ?? false)) {
            Log::warning('paystack.payout.balance_refused', [
                'status' => $response->status(),
                'message' => $body['message'] ?? null,
            ]);

            return null;
        }

        if (! is_array($body['data'] ?? null)) {
            return null;
        }

        foreach ($body['data'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (strtoupper((string) ($entry['currency'] ?? '')) !== 'NGN') {
                continue;
            }

            // Integer kobo, like the transfer amount — never naira.
            return (int) ($entry['balance'] ?? 0);
        }

        return null;
    }

    /**
     * `GET /transfer/verify/{reference}` — the lookup Bachs cannot offer.
     *
     * Paystack answers by our own reference, which is the whole reason a timed-out
     * send on this rail is recoverable without an operator. `data.status` is one of
     * `success`, `failed`, `pending`, `reversed`.
     *
     * **A 404 returns null, not a refusal.** A reference Paystack has never seen
     * most likely means the transfer was never created — but it can also mean the
     * lookup ran inside the window before a just-created transfer became readable,
     * and the two are indistinguishable from here. Reporting "not found" as a
     * refusal would release the reservation on the strength of a race, and the
     * cost of the two errors is not symmetric: a delayed payout needs an operator's
     * attention, a released reservation needs the business's money back. So this
     * says "I cannot tell you" and the caller waits.
     *
     * `reversed` comes back as `accepted` with that status, because a reversal is a
     * payout that happened and then came back — the caller maps it to its own
     * `reversed` state rather than to either "in flight" or "never sent".
     */
    public function lookUp(
        GatewayCredentials $credentials,
        string $reference,
        ?string $providerPayoutId = null,
    ): ?TransferOutcome {
        try {
            $response = PaystackApi::client($credentials)
                ->get(PaystackApi::baseUrl().'/transfer/verify/'.rawurlencode($reference));
        } catch (\Throwable $e) {
            Log::warning('paystack.payout.lookup_unreachable', [
                'reference' => $reference,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if ($response->status() === 404) {
            Log::info('paystack.payout.lookup_unfound', ['reference' => $reference]);

            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json() ?? [];
        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $status = strtolower((string) ($data['status'] ?? ''));

        if ($status === 'failed') {
            return TransferOutcome::refused(
                (string) ($data['reason'] ?? $body['message'] ?? 'Paystack reports this transfer failed.'),
                isset($data['failures']) ? 'transfer_failed' : null,
                $body,
            );
        }

        return TransferOutcome::accepted(
            transferId: isset($data['transfer_code']) ? (string) $data['transfer_code'] : null,
            providerStatus: $status ?: null,
            raw: $body,
        );
    }

    /**
     * Whether Paystack is saying this reference is already spent.
     *
     * Matched on the message, because Paystack documents this one by its wording
     * and not by a code: *"Please provide a unique reference. Reference already
     * exists on a transfer."* The phrases below are the parts of that sentence
     * that would survive a reword, plus the code-shaped guesses, and the check is
     * deliberately generous — reading a genuine refusal as a duplicate only
     * delays a payout until an operator looks, while reading a duplicate as a
     * refusal pays the same debt twice.
     *
     * @param  array<string, mixed>  $body
     */
    private static function isDuplicateReference(array $body): bool
    {
        $haystack = strtolower((string) ($body['message'] ?? '').' '.json_encode($body['meta'] ?? []));

        return str_contains($haystack, 'duplicate')
            || str_contains($haystack, 'already exists')
            || str_contains($haystack, 'already exist')
            || str_contains($haystack, 'already been used')
            || str_contains($haystack, 'unique reference');
    }
}
