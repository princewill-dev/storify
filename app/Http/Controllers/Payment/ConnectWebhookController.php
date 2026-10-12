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
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentWebhookProcessor;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Payments\PaymentGatewayRegistry;
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
 * business the delivery concerns, and then hand it to whatever the provider
 * says it means — {@see BachsEventCatalogue} for an account event, the
 * provider's own driver for a charge. Settlement is not reimplemented here —
 * the browser verify and this endpoint call the same processor, guarded on the
 * transaction's own status, so a sale cannot be settled twice by arriving on
 * both.
 *
 * **Two providers arrive here now, and only two.** `{provider}` is narrowed to
 * the registry's managed specs, which is the set of providers the platform can
 * charge a business's customers through: those are the only ones these
 * deliveries can be about. The merchant endpoint
 * (`/webhooks/payments/{provider}/{scope}`) is a different thing and stays
 * exactly as it was — see {@see PaymentWebhookController} for why it must not
 * follow this.
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
    public function __construct(
        private readonly PlatformBillingGateway $billing,
        private readonly PaymentGatewayManager $manager,
        private readonly PaymentWebhookProcessor $processor,
        private readonly PlatformPayoutService $payouts,
        private readonly ConnectAccountEventService $events,
    ) {}

    /**
     * Provider-keyed, and the key is the registry's: the endpoint exists for
     * deliveries about a business's account *the platform charges through*, and
     * a managed spec is exactly what declares that. A provider with no on-behalf
     * path has no such account for a delivery to be about, so it is refused
     * rather than quietly parsed by a driver that cannot settle against it.
     *
     * Bachs and Paystack arrive here for the same reason and are then handled
     * differently, because their deliveries are different things: Bachs' connect
     * events are about an *account* (its state, its payouts, its disputes) and
     * Paystack's are about a *charge*, with no account in the payload at all —
     * its subaccount event has nothing we act on, since a subaccount needs no
     * ongoing attention from us to be paid.
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        if (! PaymentGatewayRegistry::supportsManaged($provider)) {
            return response()->json(['status' => 'unknown provider'], Response::HTTP_NOT_FOUND);
        }

        // Refused before the body is looked at, and 503 rather than 400: an
        // unconfigured secret is our misconfiguration, not a bad delivery, and
        // the provider *should* retry this one — once someone has set the value,
        // the same delivery verifies.
        if (! $this->billing->connectWebhookConfiguredFor($provider)) {
            Log::error($provider.'.connect.webhook_not_configured', ['ip' => $request->ip()]);

            return response()->json(['status' => 'not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $driver = $this->manager->driver($provider);

        if ($driver === null) {
            return response()->json(['status' => 'unknown provider'], Response::HTTP_NOT_FOUND);
        }

        $credentials = $this->billing->connectWebhookCredentialsFor($provider);

        $event = $driver->parseWebhook(
            new WebhookRequest($request->getContent(), $this->headers($request)),
            $credentials,
        );

        if ($event === null) {
            Log::warning($provider.'.connect.unverified_signature', ['ip' => $request->ip()]);

            return response()->json(['status' => 'unverified'], Response::HTTP_BAD_REQUEST);
        }

        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        // Bachs names the event `type` and Paystack names it `event`. Read both
        // rather than branching on the provider: the field is a fact about the
        // payload, and the endpoints that follow are chosen from what it says.
        $type = strtolower((string) ($payload['type'] ?? $payload['event'] ?? ''));

        $accountId = $this->accountId($payload, $data);

        // Claimed before any work, and only when the provider named the event.
        // A redelivery of an event we have already handled is answered here,
        // without touching a balance, the ledger or the account.
        $claim = $this->claim($provider, $payload, $type, $accountId);

        if ($claim === false) {
            return response()->json(['status' => 'duplicate']);
        }

        try {
            [$status, $http] = $this->dispatch($provider, $event, $driver, $credentials, $type, $data, $accountId);
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
     * Paystack's platform deliveries never name one — its payload is `event`
     * plus `data`, and the only id in it is the transaction's — so nothing is
     * claimed there and settlement relies on the transaction's own status,
     * which is idempotent anyway. Said out loud because it is the reason a
     * Paystack redelivery is not answered as a duplicate.
     *
     * @param  array<string, mixed>  $payload
     */
    private function claim(string $provider, array $payload, string $type, ?string $accountId): ConnectWebhookEvent|bool
    {
        $eventId = $payload['id'] ?? null;

        if (! is_string($eventId) || $eventId === '') {
            // The level is the provider's, because the same absence means
            // opposite things. A Bachs connect event always names itself, so
            // one without an id is a payload shape we do not expect — and the
            // cost of it is a delivery that can be processed twice, which is
            // worth somebody seeing. Paystack's platform deliveries *never*
            // name one — its payload is `event` plus `data`, and the only id in
            // it is the transaction's — so warning there would be a warning on
            // every ordinary charge.
            $context = ['provider' => $provider, 'type' => $type];

            $provider === 'bachs'
                ? Log::warning($provider.'.connect.event_without_id', $context)
                : Log::info($provider.'.connect.event_without_id', $context);

            return true;
        }

        return ConnectWebhookEvent::claim($provider, $eventId, $type, $accountId) ?? false;
    }

    /**
     * Hand the delivery to whatever the provider says it means.
     *
     * Two shapes, chosen by the provider rather than by the payload, because
     * they answer different questions. Bachs' deliveries are about an account,
     * so they go through {@see BachsEventCatalogue} and the services that act on
     * account state. Paystack's are about a charge, and the provider's own
     * vocabulary for those is already translated by its driver in
     * {@see PaystackGateway::parseWebhook()} — `charge.success` to
     * `PAYMENT_SUCCESS`, and so on — so there is no second catalogue to keep in
     * step with the first, and the dispatch below reads the driver's answer
     * instead of inventing a parallel one.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int}
     */
    private function dispatch(
        string $provider,
        ?WebhookEvent $event,
        PaymentGateway $driver,
        GatewayCredentials $credentials,
        string $type,
        array $data,
        ?string $accountId,
    ): array {
        if ($provider !== 'bachs') {
            return $this->dispatchCharge($driver, $credentials, $event, $type, $accountId);
        }

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
     * A platform-processed charge, as Paystack reports it.
     *
     * The whole of it is settlement, and the settling is not done here:
     * {@see PaymentWebhookProcessor::settle()} re-verifies the payment through
     * the provider, credits whoever the transaction says the money belongs to,
     * posts the ledger and delivers anything digital. A managed sale's money is
     * the business's from the moment the split was made, which that processor
     * already knows from the transaction (`isPlatformManaged()`), so nothing
     * about the split is re-decided on this path.
     *
     * **No account is used or needed.** Paystack's payload names none, and there
     * is nothing to address: unlike Bachs' connect calls, settling a Paystack
     * charge is a read of the transaction, not an instruction to an account.
     *
     * A failed charge and a refund are answered 200 and nothing else. Both are
     * states the customer's own verification already reports, and acting on a
     * refund here would be a second implementation of something the merchant
     * webhook path owns — this endpoint exists to settle, and it says so.
     *
     * @return array{0: string, 1: int}
     */
    private function dispatchCharge(
        PaymentGateway $driver,
        GatewayCredentials $credentials,
        ?WebhookEvent $event,
        string $type,
        ?string $accountId,
    ): array {
        if ($event === null || ! $event->isSettlement()) {
            Log::info('connect.charge.not_a_settlement', [
                'provider' => $driver->code(),
                'type' => $type,
                'account_id' => $accountId,
            ]);

            return ['noted', Response::HTTP_OK];
        }

        // No scope: the delivery is about a charge the platform made, and the
        // transaction's own reference is what identifies it. A scope would be
        // the *connection* the URL named, which is a merchant concept this
        // endpoint deliberately does not have.
        $result = $this->processor->settle($event, $driver, $credentials);

        return [$result['status'], $result['http']];
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
