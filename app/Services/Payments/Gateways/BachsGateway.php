<?php

namespace App\Services\Payments\Gateways;

use App\Enums\WebhookEventType;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\ConnectionResult;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\InitializationResult;
use App\Services\Payments\Data\PaymentIntent;
use App\Services\Payments\Data\RefundRequest;
use App\Services\Payments\Data\RefundResult;
use App\Services\Payments\Data\VerificationResult;
use App\Services\Payments\Data\WebhookEvent;
use App\Services\Payments\Data\WebhookRequest;
use App\Support\Money\Naira;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Log;

/**
 * Bachs — cards, bank transfer, mobile money and stablecoins across African markets.
 *
 * Four things make it unlike the others here, and all four are Bachs' contract
 * rather than choices made in this class:
 *
 * 1. **Money travels as a decimal string at the currency's precision, never in
 *    minor units.** `"23000.00"`, not `2300000`. So the conversions here are the
 *    opposite of every other driver's: out through `Naira::decimalFromKobo()`,
 *    back through `Naira::koboFromStrict()`.
 * 2. **It identifies a payment by its own `checkout_id`.** `POST /v1/checkout-sessions`
 *    takes a `reference` of ours, but the only documented retrieve is by
 *    `checkout_id` — there is no lookup by our reference. So `verify()` is
 *    addressed by the checkout id, exactly as Bitfra's is by its `payment_id`, and
 *    the webhook therefore quotes the checkout id rather than our reference.
 * 3. **A charge is created from a price, not a product.** `pricing: {currency,
 *    amount}` needs no catalogue entry, which is what lets an arbitrary store
 *    amount be charged. (`product_cart` is the alternative, and the docs insist on
 *    exactly one of the two.)
 * 4. **The signature covers a timestamp.** `X-Bachs-Signature-V2` is
 *    `t={unix},v1={hmac-sha256 of "<t>.<raw body>"}`, and during a secret rotation
 *    the header carries one `v1=` per live secret — so every one of them is
 *    tested, not just the first.
 *
 * 5. **A connected account is named by a header, and its absence is not an
 *    error.** Sending `X-Account-Id: acct_…` alongside the platform's key makes
 *    the request a *direct charge*: the connected account is the merchant of
 *    record, the sale lands in its balance, the processing fee comes out of its
 *    proceeds, and a refund debits it rather than us. `platform_fee` on the same
 *    request is our cut, "taken from the account's proceeds".
 *
 *    Bachs does **not** reject a missing or misspelled `X-Account-Id` — the
 *    request simply succeeds as the platform's own charge — so the header is
 *    attached in one place, `client()`, for every call that has an account to
 *    name, and `initialize()` refuses to post when the intent declares a direct
 *    charge the credentials cannot honour. Destination charges are the
 *    alternative (`transfer_data.destination`) and are not used: they land the
 *    money in *our* balance, make us merchant of record, and leave us bearing
 *    the whole refund. Bachs rejects the two together —
 *    `400 CONTRADICTORY_CHARGE_TYPE`.
 *
 * `success_url` must be publicly reachable: Bachs refuses localhost and private
 * addresses, in sandbox as well as production. A Bachs checkout cannot be created
 * from a development machine without a tunnel.
 */
final class BachsGateway implements PaymentGateway
{
    /** Reject a webhook whose timestamp is older than this, to blunt replays. */
    private const SIGNATURE_TOLERANCE_SECONDS = 300;

    /**
     * Charge statuses that mean the money is there. `accepted` is an under- or
     * overpayment the account chose to accept as final, and `overpaid` is a
     * customer having sent more than was asked for — both are settled money.
     */
    private const SETTLED_CHARGE_STATUSES = ['succeeded', 'accepted', 'overpaid'];

    /**
     * Statuses that mean the charge will never be paid: `incomplete` ended
     * without a payment, `failed` ended after one and has no further attempt.
     */
    private const FAILED_CHARGE_STATUSES = ['incomplete', 'failed'];

    /**
     * Statuses of a charge that *was* paid and has since been given back. They
     * are deliberately not settled: money that came and went must not complete an
     * order, and the store must not be credited for it.
     */
    private const REFUNDED_CHARGE_STATUSES = ['refunded', 'partially_refunded', 'auto_refunded'];

    /** Bachs accepts at most twenty metadata pairs. */
    private const METADATA_PAIR_LIMIT = 20;

    public function code(): string
    {
        return 'bachs';
    }

    public function currency(): string
    {
        return 'NGN';
    }

    public function initialize(PaymentIntent $intent, GatewayCredentials $credentials): InitializationResult
    {
        // Before the request, not after the response. A direct charge that goes
        // out without its account is not a failed payment — it is a *successful*
        // payment to the wrong party, and nothing in the response says so.
        if (($problem = $this->directChargeProblem($intent, $credentials)) !== null) {
            Log::error('bachs.direct_charge.refused', [
                'reference' => $intent->reference,
                'reason' => $problem,
                'declared_account' => $intent->directChargeAccountId,
                'credential_account' => $credentials->accountId(),
            ]);

            return InitializationResult::failed($problem);
        }

        $response = $this->client($credentials)->post($this->baseUrl($credentials).'/checkout-sessions', array_filter([
            // `pricing` rather than `product_cart`: the amount is whatever the
            // customer is paying on this order, and it exists in no catalogue.
            'pricing' => [
                'currency' => $intent->currency,
                // Decimal string at currency precision. Sending kobo here would
                // charge a hundred times the amount.
                'amount' => Naira::decimalFromKobo($intent->amountMinor),
            ],
            // Our cut, on a direct charge only, and "taken from the account's
            // proceeds" — it never passes through our balance and we never pay
            // Bachs' processing fee. Omitted entirely when there is no cut to
            // take: Bachs refuses `platform_fee >= gross` with
            // `400 INVALID_PLATFORM_FEE`, and a `0.00` line is not the same
            // request as no line at all.
            'platform_fee' => $this->platformFee($intent),
            // Ours to choose and unique per organisation for good — expired
            // checkouts included. `generateReference()` issues one per attempt.
            'reference' => $intent->reference,
            'customer' => array_filter([
                'email' => $intent->email,
                'name' => $intent->customerName,
            ]),
            // Where the customer's browser returns. Bachs refuses a URL it cannot
            // reach from the internet, so this is the storefront's public origin.
            'success_url' => $intent->callbackUrl,
            'metadata' => $this->metadataFor($intent->metadata),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []));

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            // A rejected reference is the one refusal worth naming, because it is
            // permanent and a retry cannot fix it.
            return InitializationResult::failed(
                $body['message'] ?? 'Bachs could not start this payment.',
                $body,
            );
        }

        if (! isset($body['checkout_url'], $body['checkout_id'])) {
            return InitializationResult::failed('Bachs did not return a checkout page for this payment.', $body);
        }

        return InitializationResult::redirect(
            redirectUrl: (string) $body['checkout_url'],
            // The checkout id is the name this payment is known by from here on:
            // it is what `verify()` asks about and what the webhook quotes.
            providerReference: (string) $body['checkout_id'],
            providerId: (string) $body['checkout_id'],
            raw: $body,
        );
    }

    /**
     * `GET /v1/checkout-sessions/{checkout_id}`.
     *
     * The session carries a `charge` object once the customer has paid and `null`
     * while the session is open, and that charge is the only place the collected
     * amount is reported — the session's own `amount` is the price that was asked
     * for. So the charge is what this reads, falling back to the session's status
     * when there is none.
     *
     * The full shape of that charge is not published, so this reads the fields it
     * needs and logs `bachs.verify.unexpected_shape` rather than inventing
     * behaviour for anything else. A paid charge reporting no usable amount is
     * treated as pending, not as a paid zero: settling on an unknown sum would
     * credit the store for money nobody has confirmed.
     */
    public function verify(string $reference, GatewayCredentials $credentials): VerificationResult
    {
        $response = $this->client($credentials)->get(
            $this->baseUrl($credentials).'/checkout-sessions/'.rawurlencode($reference)
        );

        $body = $response->json() ?? [];

        if ($response->status() === 404) {
            // The ordinary answer for an id Bachs does not know — a checkout
            // created a moment ago, or a caller holding our own reference, which
            // it has no lookup for. Neither is a refusal, so this reads as
            // pending: a failed result is terminal at the till, and an id we
            // cannot ask about yet must not abort a sale that is going to be paid.
            return VerificationResult::pending('Bachs has not seen this checkout yet.', $body);
        }

        if (! $response->successful()) {
            return VerificationResult::failed($body['message'] ?? 'Bachs could not verify this payment.', $body);
        }

        $charge = is_array($body['charge'] ?? null) ? $body['charge'] : [];

        if ($charge === []) {
            return match (strtolower((string) ($body['status'] ?? ''))) {
                'expired', 'cancelled' => VerificationResult::failed('This checkout is no longer open.', $body),
                // Paid but with no charge to read is a payload this driver does
                // not understand; saying so beats guessing at an amount.
                'completed' => $this->unexpectedShape($body, 'completed session without a charge'),
                default => VerificationResult::pending('This payment has not completed yet.', $body),
            };
        }

        $status = strtolower((string) ($charge['status'] ?? ''));

        if (in_array($status, self::REFUNDED_CHARGE_STATUSES, true)) {
            return VerificationResult::failed('This payment has since been refunded.', $charge);
        }

        if (in_array($status, self::FAILED_CHARGE_STATUSES, true)) {
            return VerificationResult::failed('This payment was not successful.', $charge);
        }

        if (! in_array($status, self::SETTLED_CHARGE_STATUSES, true)) {
            return VerificationResult::pending('This payment has not completed yet.', $charge);
        }

        // `amount`, not `settlement_amount`: settlement is reported in the
        // account's own settlement currency, which is a different question from
        // what this customer was charged.
        $amountMinor = $this->minorFrom($charge['amount'] ?? null);

        if ($amountMinor === null || $amountMinor < 1) {
            return $this->unexpectedShape($charge, 'settled charge without a usable amount');
        }

        return VerificationResult::paid(
            amountMinor: $amountMinor,
            currency: isset($charge['currency']) ? (string) $charge['currency'] : null,
            // The **checkout** id — not the charge id — and deliberately so.
            // This is what the caller writes to `transactions.gateway_reference`,
            // which is what a webhook is matched against, and Bachs quotes the
            // checkout id in its deliveries. Reporting the charge id here would
            // overwrite that name on settlement and make every subsequent
            // delivery of the same event unmatchable — a second `checkout.completed`
            // for one sale would read as "transaction not found".
            //
            // The charge id a refund is addressed by is the *other* name this
            // payment has, the way Paystack's `access_code` is; it is in
            // `raw['id']`, which is the charge itself.
            providerId: (string) ($body['checkout_id'] ?? $charge['id'] ?? $reference),
            feesMinor: $this->feesMinor($charge),
            paidAt: isset($charge['updated_at'])
                ? (string) $charge['updated_at']
                : (isset($charge['created_at']) ? (string) $charge['created_at'] : null),
            raw: $charge,
        );
    }

    /**
     * `POST /v1/refunds`.
     *
     * Addressed by the **charge id**, not by our reference and not by the
     * checkout id either. Take it from `gateway_response['id']` — the charge
     * `verify()` recorded when it settled the payment — because
     * `gateway_reference` deliberately holds the checkout id instead: that is
     * the name webhooks arrive under, and overwriting it with the charge id
     * would stop them matching.
     *
     * A refund settles asynchronously and is reported by the `refund.*` webhooks,
     * so a successful response here means the refund was accepted for processing,
     * not that the customer has the money. Bachs allows **one refund per charge**:
     * refunding part of a payment forecloses the rest of it, which the response
     * status makes visible but nothing here can undo.
     *
     * On a connected account — the header comes from `client()` — the refund
     * debits **that account's** balance, not ours, and a customer's chargeback can
     * push it negative. That is the whole point of the direct-charge model: we
     * are not the party who has to fund it.
     */
    public function refund(RefundRequest $refund, GatewayCredentials $credentials): RefundResult
    {
        $response = $this->client($credentials)->post($this->baseUrl($credentials).'/refunds', array_filter([
            'charge_id' => $refund->providerId ?? $refund->reference,
            // Unique per organisation, like a charge reference, so one is minted
            // here rather than carried in.
            'reference' => $this->refundReference(),
            // Always sent. Bachs refunds the whole charge when the field is
            // omitted, so passing the amount is the same request for a full
            // refund and the only way to ask for a partial one.
            'amount' => Naira::decimalFromKobo($refund->amountMinor),
            'reason' => $refund->reason,
        ], fn ($value) => $value !== null && $value !== ''));

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            return RefundResult::failed($body['message'] ?? 'Bachs refused this refund.', $body);
        }

        return RefundResult::ok(
            refundId: isset($body['refund_id']) ? (string) $body['refund_id'] : null,
            status: isset($body['status']) ? (string) $body['status'] : null,
            raw: $body,
        );
    }

    public function parseWebhook(WebhookRequest $request, GatewayCredentials $credentials): ?WebhookEvent
    {
        $secret = (string) $credentials->get('webhook_secret');

        if ($secret === '' || ! $this->signatureIsValid($request, $secret)) {
            return null;
        }

        $payload = $request->json();
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        // `checkout.completed` nests the settled charge; `collection.succeeded`
        // reports the same fields flat alongside a `charge_id`.
        $charge = is_array($data['charge'] ?? null) ? $data['charge'] : $data;

        return new WebhookEvent(
            type: match ((string) ($payload['type'] ?? '')) {
                'collection.succeeded' => WebhookEventType::PAYMENT_SUCCESS,
                // Fulfil on whichever of the two arrives first — but only a
                // checkout that actually charged something. `no_payment_required`
                // is a free cart or a trial, which is not money.
                'checkout.completed' => ($data['payment_status'] ?? null) === 'paid'
                    ? WebhookEventType::PAYMENT_SUCCESS
                    : WebhookEventType::IGNORED,
                'collection.failed', 'checkout.expired' => WebhookEventType::PAYMENT_FAILED,
                'refund.paid' => WebhookEventType::REFUND,
                // Everything else is ignored, `collection.underpaid` included: an
                // underpayment can still be accepted as final, and deciding
                // whether too little arrived is the settlement floor's job, not
                // this mapping's.
                default => WebhookEventType::IGNORED,
            },
            // The checkout id rather than our own reference: the settlement
            // processor re-verifies through `verify()`, which only understands
            // the checkout id, and the transaction's `gateway_reference` holds it.
            reference: isset($data['checkout_id']) ? (string) $data['checkout_id'] : null,
            providerId: isset($data['charge_id'])
                ? (string) $data['charge_id']
                : (isset($charge['id']) ? (string) $charge['id'] : null),
            amountMinor: $this->minorFrom($charge['amount'] ?? null),
            currency: isset($charge['currency']) ? (string) $charge['currency'] : null,
            feesMinor: $this->minorFrom($charge['processing_fee'] ?? null),
            raw: $data,
        );
    }

    public function testConnection(GatewayCredentials $credentials): ConnectionResult
    {
        try {
            $response = $this->client($credentials)->get($this->baseUrl($credentials).'/accounts/me');

            if ($response->status() === 401) {
                return ConnectionResult::failed('Bachs rejected this API key.');
            }

            if ($response->status() === 403) {
                return ConnectionResult::failed('Bachs says this account cannot take payments.');
            }

            return $response->successful()
                ? ConnectionResult::ok('Connected to Bachs.')
                : ConnectionResult::failed('Bachs answered with '.$response->status().'.');
        } catch (\Throwable $e) {
            Log::warning('bachs.test_failed', ['error' => $e->getMessage()]);

            return ConnectionResult::failed('Could not reach Bachs.');
        }
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function supportsPartialPayment(): bool
    {
        return true;
    }

    /**
     * Why this charge must not be posted, or null when it may be.
     *
     * Only ever refuses a charge the caller *asked* to be direct. An ordinary
     * charge — the platform's own subscription billing, or a business's own keys
     * — declares no account and is unaffected, which is what keeps the platform's
     * billing key from being mistaken for a managed connection here.
     *
     * The two ids are compared rather than trusted: a mismatch means the
     * resolver answered for one account and something else is about to be
     * charged, and the only safe response to that is to stop.
     */
    private function directChargeProblem(PaymentIntent $intent, GatewayCredentials $credentials): ?string
    {
        $declared = $intent->directChargeAccountId;

        if ($declared === null || $declared === '') {
            return null;
        }

        if (! str_starts_with($declared, 'acct_')) {
            return 'This payment could not be started: the connected account it needs is not a valid account id.';
        }

        if ($credentials->accountId() !== $declared) {
            return 'This payment could not be started: the payment account does not match the account being charged.';
        }

        if ((string) $credentials->get('api_key') === '') {
            return 'This payment could not be started: no gateway credentials are configured for it.';
        }

        return null;
    }

    /**
     * The platform fee as Bachs takes it — a decimal string, like every other
     * amount over this boundary — or null when no cut is configured.
     *
     * Zero is reported as null rather than `"0.00"`: a platform with no
     * commission is not asking for a zero-fee deduction, and sending one would
     * put a record on every settlement that says nothing.
     */
    private function platformFee(PaymentIntent $intent): ?string
    {
        $fee = $intent->platformFeeMinor;

        return $fee === null || $fee < 1 ? null : Naira::decimalFromKobo($fee);
    }

    /**
     * Whether the request carries a signature this connection's secret produces.
     *
     * `X-Bachs-Signature-V2` is preferred and the legacy pair is still accepted:
     * Bachs sends both, and a dashboard that has only ever handed a merchant the
     * old header should not be a reason their webhooks stop settling.
     */
    private function signatureIsValid(WebhookRequest $request, string $secret): bool
    {
        $v2 = (string) $request->header('x-bachs-signature-v2', '');

        if ($v2 !== '') {
            return $this->v2Matches($v2, $request, $secret);
        }

        $signature = (string) $request->header('x-bachs-signature', '');
        $timestamp = (string) $request->header('x-bachs-timestamp', '');

        if ($signature === '' || $timestamp === '') {
            return false;
        }

        return $this->timestampIsFresh((int) $timestamp)
            && hash_equals($this->signature((int) $timestamp, $request->payload(), $secret), $signature);
    }

    /**
     * `t={unix timestamp},v1={signature}[,v1={signature}]`.
     */
    private function v2Matches(string $header, WebhookRequest $request, string $secret): bool
    {
        if (preg_match('/t=(\d+)/', $header, $matches) !== 1) {
            return false;
        }

        $timestamp = (int) $matches[1];

        if (! $this->timestampIsFresh($timestamp)) {
            return false;
        }

        $expected = $this->signature($timestamp, $request->payload(), $secret);

        // Every `v1=` is tested, not just the first. During a rotation Bachs signs
        // with the new secret and the previous one and sends both, so comparing
        // against the first alone fails for every delivery signed with the other.
        preg_match_all('/v1=([a-f0-9]+)/i', $header, $signatures);

        foreach ($signatures[1] ?? [] as $candidate) {
            if (hash_equals($expected, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The signed string is the timestamp, a literal dot, then the raw body —
     * so the body must be hashed as it arrived, never re-encoded.
     */
    private function signature(int $timestamp, string $payload, string $secret): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
    }

    /**
     * A signed request is only trustworthy while it is fresh; without this a
     * captured webhook could be replayed indefinitely.
     */
    private function timestampIsFresh(int $timestamp): bool
    {
        if (abs(time() - $timestamp) <= self::SIGNATURE_TOLERANCE_SECONDS) {
            return true;
        }

        Log::warning('bachs.webhook.stale_signature', ['timestamp' => $timestamp]);

        return false;
    }

    /**
     * Bachs takes at most twenty metadata pairs, and the intent carries nested
     * values (the store's bank accounts) that are not pairs at all. Trimmed rather
     * than rejected: a provider's limit is not a reason to fail a payment a
     * customer is standing in front of.
     *
     * @param  array<string, mixed>  $metadata
     * @return array<string, scalar|null>
     */
    private function metadataFor(array $metadata): array
    {
        $pairs = array_filter($metadata, fn ($value) => is_scalar($value) || $value === null);

        return array_slice($pairs, 0, self::METADATA_PAIR_LIMIT);
    }

    /**
     * A decimal string at the currency's precision, as minor units — or null when
     * the field is missing or malformed. `Naira::koboFromStrict()` fails closed on
     * anything that is not a plain decimal, which is what an amount compared
     * against a payment requires.
     */
    private function minorFrom(mixed $amount): ?int
    {
        if (! is_string($amount) && ! is_int($amount) && ! is_float($amount)) {
            return null;
        }

        if (trim((string) $amount) === '') {
            return null;
        }

        return Naira::koboFromStrict($amount);
    }

    /**
     * Fees, when the payload reports them in the same currency as the charge.
     * A fee in another currency is not a number that can be subtracted from this
     * one, so it is left out rather than converted at a rate nothing here knows.
     *
     * @param  array<string, mixed>  $charge
     */
    private function feesMinor(array $charge): ?int
    {
        if (! isset($charge['processing_fee'], $charge['currency'])) {
            return null;
        }

        $feeCurrency = (string) ($charge['processing_fee_currency'] ?? $charge['currency']);

        return strtoupper($feeCurrency) === strtoupper((string) $charge['currency'])
            ? $this->minorFrom($charge['processing_fee'])
            : null;
    }

    /**
     * A payload this driver does not understand, reported as pending and logged
     * rather than guessed at. Pending is not a failure: it leaves the payment
     * open for a webhook that does match the documented shape.
     *
     * @param  array<string, mixed>  $raw
     */
    private function unexpectedShape(array $raw, string $detail): VerificationResult
    {
        Log::warning('bachs.verify.unexpected_shape', [
            'detail' => $detail,
            'keys' => array_keys($raw),
        ]);

        return VerificationResult::pending(
            'Bachs reported a payment this integration could not read. It has been logged.',
            $raw,
        );
    }

    /** Test keys are not interchangeable with live ones, and the hosts differ. */
    private function baseUrl(GatewayCredentials $credentials): string
    {
        return BachsApi::baseUrl($credentials);
    }

    private function refundReference(): string
    {
        return 'RF_'.time().'_'.strtoupper(substr(md5(uniqid('', true)), 0, 8));
    }

    /**
     * Every call to Bachs, with the connected account attached when there is one.
     *
     * The header is set inside {@see BachsApi} rather than at each call site
     * because it decides whose money a request moves, and Bachs does not reject
     * its absence. One place that cannot be forgotten beats six that can — this
     * covers the charge, the read-back, the refund and the account lookup alike.
     */
    private function client(GatewayCredentials $credentials): PendingRequest
    {
        return BachsApi::client($credentials);
    }
}
