<?php

namespace App\Http\Controllers\Payment;

use App\Http\Controllers\Controller;
use App\Jobs\PayOutSettledFunds;
use App\Models\ConnectWebhookEvent;
use App\Services\Payments\Connect\BachsEventCatalogue;
use App\Services\Payments\Connect\ConnectAccountEventService;
use App\Services\Payments\Connect\PlatformPayoutService;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\WebhookEvent;
use App\Services\Payments\Data\WebhookRequest;
use App\Services\Payments\Data\WebhookScope;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentWebhookProcessor;
use App\Services\Subscription\PlatformBillingGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where the provider tells Storify about a business's connected account.
 *
 * ## Why this is not a scope of the merchant endpoint
 *
 * `POST /webhooks/payments/{provider}/{scope}` is the URL a business pastes into
 * its own dashboard, and it resolves `{scope}` by searching connections for one
 * whose *stored secret* verifies the signature. Connected-account deliveries are
 * addressed to none of those: they are signed with the platform's connect secret,
 * they are only sent to an endpoint whose `event_source` is `connect`, and the
 * body names the account rather than any connection of ours. Under the merchant
 * endpoint they would fail to verify against every candidate and be answered
 * 401 forever.
 *
 * It is also not a scope of that route's *path*, which is what the earlier draft
 * of this feature assumed: `{scope}` is a single `[A-Za-z0-9_]+` segment, so
 * `/webhooks/payments/bachs/connect` resolves to `provider=bachs, scope=connect`
 * and never reaches a controller of its own. Hence `/webhooks/connect/{provider}`.
 *
 * ## What it does with a delivery
 *
 * Four things, in this order: prove the signature is the platform's, claim the
 * event by its own id so a retry cannot do the work twice, work out which
 * business the delivery concerns, and then hand it to whatever
 * {@see BachsEventCatalogue} says that event means. Settlement is not
 * reimplemented here — the browser verify and this endpoint call the same
 * processor, guarded on the transaction's own status, so a sale cannot be
 * settled twice by arriving on both.
 *
 * ## The status codes are deliberate
 *
 * `400` for a signature that will never verify: the same body with the same
 * secret fails again, and a 4xx is how the provider is told not to spend its
 * retry budget. `200` for an account we do not know, for an event we have
 * already handled, and for one that is understood but needs nothing done: none
 * is a failure, and an error status would make the provider retry a delivery
 * there is nothing wrong with. `500` only when a retry could actually help —
 * and when that happens the event's claim is released first, so the retry is
 * genuinely processed rather than dismissed as a duplicate.
 */
final class ConnectWebhookController extends Controller
{
    /**
     * The provider this endpoint speaks for.
     *
     * A constant rather than a registry lookup: the connect surface is Bachs'
     * alone until another provider offers one, and a delivery from any other
     * provider is refused rather than quietly parsed by a driver that has no
     * accounts API to settle against.
     */
    private const PROVIDER = 'bachs';

    public function __construct(
        private readonly PlatformBillingGateway $billing,
        private readonly PaymentGatewayManager $manager,
        private readonly PaymentWebhookProcessor $processor,
        private readonly PlatformPayoutService $payouts,
        private readonly ConnectAccountEventService $events,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        if ($provider !== self::PROVIDER) {
            return response()->json(['status' => 'unknown provider'], Response::HTTP_NOT_FOUND);
        }

        // Refused before the body is looked at, and 503 rather than 400: an
        // unconfigured secret is our misconfiguration, not a bad delivery, and
        // the provider *should* retry this one — once someone has set the value,
        // the same delivery verifies.
        if (! $this->billing->connectWebhookConfigured()) {
            Log::error('bachs.connect.webhook_not_configured', ['ip' => $request->ip()]);

            return response()->json(['status' => 'not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $driver = $this->manager->driver(self::PROVIDER);

        if ($driver === null) {
            return response()->json(['status' => 'unknown provider'], Response::HTTP_NOT_FOUND);
        }

        $credentials = $this->billing->connectWebhookCredentials();

        $event = $driver->parseWebhook(
            new WebhookRequest($request->getContent(), $this->headers($request)),
            $credentials,
        );

        if ($event === null) {
            Log::warning('bachs.connect.unverified_signature', ['ip' => $request->ip()]);

            return response()->json(['status' => 'unverified'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $type = strtolower((string) ($payload['type'] ?? ''));

        $accountId = $this->accountId($payload, $data);

        // Claimed before any work, and only when the provider named the event.
        // A redelivery of an event we have already handled is answered here,
        // without touching a balance, the ledger or the account.
        $claim = $this->claim($payload, $type, $accountId);

        if ($claim === false) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            [$status, $http] = $this->dispatch($event, $driver, $credentials, $type, $data, $accountId);
        } catch (\Throwable $e) {
            // The claim is given back so the provider's retry is processed
            // rather than dismissed as a duplicate of an event that never
            // actually succeeded. This is the whole reason the claim is written
            // before the work instead of after it.
            if ($claim instanceof ConnectWebhookEvent) {
                $claim->release();
            }

            throw $e;
        }

        if ($claim instanceof ConnectWebhookEvent) {
            $claim->settle($status, BachsEventCatalogue::reasonFor($type));
        }

        return response()->json(['status' => $status], $http);
    }

    /**
     * Claim the delivery by its event id.
     *
     * `true` when the provider named no id — an unnameable event cannot be
     * deduplicated, and refusing to process it would be worse than the risk of
     * doing one thing twice, which the handlers themselves mostly tolerate.
     * `false` when someone already has it. Otherwise the claim.
     *
     * @param  array<string, mixed>  $payload
     */
    private function claim(array $payload, string $type, ?string $accountId): ConnectWebhookEvent|bool
    {
        $eventId = $payload['id'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            Log::warning('bachs.connect.event_without_id', ['type' => $type]);

            return true;
        }

        return ConnectWebhookEvent::claim(self::PROVIDER, $eventId, $type, $accountId) ?? false;
    }

    /**
     * Hand the delivery to whatever the catalogue says it means.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int}
     */
    private function dispatch(
        ?WebhookEvent $event,
        PaymentGateway $driver,
        GatewayCredentials $credentials,
        string $type,
        array $data,
        ?string $accountId,
    ): array {
        $disposition = BachsEventCatalogue::dispositionFor($type);

        // Some events concern the platform's account or name one at all only by
        // a reference, and those are handled before the tenant lookup — a
        // `payout.failed` that names no account would otherwise be dropped here
        // as "not a connected account", which is exactly the delivery whose loss
        // leaves our records claiming money left when it did not.
        if ($accountId === null) {
            if ($disposition === BachsEventCatalogue::PAYOUT) {
                $recorded = $this->payouts->applyPayoutWebhookWithoutAccount($data, $type);

                return [$recorded ? 'payout recorded' : 'payout not found', Response::HTTP_OK];
            }

            // A delivery about the platform's own account rather than a
            // business's. It verified, so it is ours to receive — it is simply
            // not about anything this endpoint acts on.
            Log::info('bachs.connect.no_account', ['type' => $type]);

            return ['not a connected account', Response::HTTP_OK];
        }

        $tenant = $this->payouts->tenantForAccount($accountId);

        if ($tenant === null) {
            // Answering 200 on purpose. An account we have no connection for is
            // either one we have just created and not yet stored, or one that is
            // not ours at all; neither is fixed by a retry, and the log line is
            // what makes it visible.
            Log::warning('bachs.connect.unknown_account', ['account_id' => $accountId, 'type' => $type]);

            return ['unknown account', Response::HTTP_OK];
        }

        return match ($disposition) {
            BachsEventCatalogue::PAYOUT => $this->payout($accountId, $data, $type),
            BachsEventCatalogue::ACCOUNT => $this->accountState($accountId, $type),
            BachsEventCatalogue::DISPUTE => $this->dispute($accountId, $data, $type),
            BachsEventCatalogue::REFUND => $this->refund($accountId, $data, $type),
            BachsEventCatalogue::NOTE => $this->noted($type),
            BachsEventCatalogue::UNUSED => ['ignored', Response::HTTP_OK],
            BachsEventCatalogue::SETTLE => $this->settle($event, $driver, $credentials, $tenant, $type, $accountId),
            default => $this->unknown($type),
        };
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int}
     */
    private function payout(string $accountId, array $data, string $type): array
    {
        $recorded = $this->payouts->applyPayoutWebhook($accountId, $data, $type);

        return [$recorded ? 'payout recorded' : 'payout not found', Response::HTTP_OK];
    }

    /**
     * @return array{0: string, 1: int}
     */
    private function accountState(string $accountId, string $type): array
    {
        $refreshed = $this->events->refreshAccountState($accountId);

        Log::info('bachs.connect.account_state', [
            'account_id' => $accountId,
            'type' => $type,
            'refreshed' => $refreshed,
        ]);

        return ['account state refreshed', Response::HTTP_OK];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int}
     */
    private function dispute(string $accountId, array $data, string $type): array
    {
        $recorded = $this->events->recordDispute($accountId, $data, $type);

        return [$recorded ? 'dispute recorded' : 'dispute not recorded', Response::HTTP_OK];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int}
     */
    private function refund(string $accountId, array $data, string $type): array
    {
        $recorded = $this->events->recordRefund($accountId, $data, $type);

        return [$recorded ? 'refund recorded' : 'refund not recorded', Response::HTTP_OK];
    }

    /**
     * Understood, and nothing to do — deliberately.
     *
     * Each of these has its reason written down in the catalogue rather than
     * being a silent default, so "we do not act on `collection.failed`" stays a
     * decision somebody made instead of a gap nobody noticed.
     *
     * @return array{0: string, 1: int}
     */
    private function noted(string $type): array
    {
        Log::info('bachs.connect.noted', ['type' => $type]);

        return ['noted', Response::HTTP_OK];
    }

    /**
     * A slug the catalogue does not know.
     *
     * Logged as a warning rather than absorbed, because this is how a newly
     * published Bachs event announces itself: the first delivery of it is
     * visible in the log, and adding a disposition is then a decision rather
     * than a discovery.
     *
     * @return array{0: string, 1: int}
     */
    private function unknown(string $type): array
    {
        Log::warning('bachs.connect.unhandled_event', ['type' => $type]);

        return ['unhandled event', Response::HTTP_OK];
    }

    /**
     * Settle a customer's payment.
     *
     * The driver is the authority on *whether* a settlement event really is one
     * — a `checkout.completed` for a cart that cost nothing is not money — so a
     * catalogue entry of `settle` that the driver maps to `IGNORED` is answered
     * as noted rather than settled.
     *
     * @param  array{business_id: int, store_id: int|null}  $tenant
     * @return array{0: string, 1: int}
     */
    private function settle(
        ?WebhookEvent $event,
        PaymentGateway $driver,
        GatewayCredentials $credentials,
        array $tenant,
        string $type,
        string $accountId,
    ): array {
        if ($event === null || ! $event->isSettlement()) {
            Log::info('bachs.connect.not_a_settlement', ['type' => $type]);

            return ['noted', Response::HTTP_OK];
        }

        // The account goes onto the credentials, which is what addresses the
        // re-verification to the business's account rather than to ours — the
        // same header the charge was made under, without which the provider
        // would answer about the platform's own balance.
        $result = $this->processor->settle(
            $event,
            $driver,
            $credentials->withAccountId($accountId),
            new WebhookScope($tenant['business_id'], $tenant['store_id'], false),
        );

        if ($result['status'] === 'success') {
            $this->payoutAfterSettlement($tenant);
        }

        return [$result['status'], $result['http']];
    }

    /**
     * Try to withdraw a settled sale's money at once.
     *
     * **Queued after the response, not done inside it.** The provider allows ten
     * seconds for a 2xx and counts anything else as a failure worth retrying;
     * this path makes three provider calls of its own — a refresh, a balance
     * read and the payout — and a delivery that spent its budget on them would
     * be retried, arrive again as a settled sale, and waste the retry on work
     * that is already done. Queued, the delivery is acknowledged immediately and
     * the withdrawal happens a moment later.
     *
     * Best effort either way. A charge settles into `pending_balance` and only
     * becomes movable on the provider's own schedule, so the usual answer is
     * "nothing has settled yet" — the hourly sweep is what catches the funds
     * when they land, and this only shortens the wait.
     *
     * @param  array{business_id: int, store_id: int|null}  $tenant
     */
    private function payoutAfterSettlement(array $tenant): void
    {
        PayOutSettledFunds::dispatchAfterResponse($tenant['business_id'], $tenant['store_id']);
    }

    /**
     * The connected account a delivery names.
     *
     * Checked in three places because the field is not pinned by the
     * documentation: `account` at the top level is what the connect events are
     * described as carrying, and the other two are the shapes it can take — an
     * object rather than a bare id, and the id repeated inside `data`. Reading
     * all three costs nothing and means the endpoint does not go dark on a
     * payload shape nobody wrote down.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     */
    private function accountId(array $payload, array $data): ?string
    {
        foreach ([$payload['account'] ?? null, $data['account'] ?? null, $payload['account_id'] ?? null, $data['account_id'] ?? null] as $candidate) {
            $accountId = is_array($candidate) ? ($candidate['id'] ?? null) : $candidate;

            if (is_string($accountId) && $accountId !== '') {
                return $accountId;
            }
        }

        return null;
    }

    /**
     * Headers with lower-cased names, because providers disagree on casing.
     *
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = is_array($values) ? (string) ($values[0] ?? '') : (string) $values;
        }

        return $headers;
    }
}
