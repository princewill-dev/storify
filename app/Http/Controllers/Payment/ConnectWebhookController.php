<?php

namespace App\Http\Controllers\Payment;

use App\Enums\WebhookEventType;
use App\Http\Controllers\Controller;
use App\Models\ConnectWebhookEvent;
use App\Services\Payments\Connect\BachsEventCatalogue;
use App\Services\Payments\Connect\ConnectAccountEventService;
use App\Services\Payments\Contracts\PaymentGateway;
use App\Services\Payments\Custody\PayoutSweepService;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Data\WebhookEvent;
use App\Services\Payments\Data\WebhookRequest;
use App\Services\Payments\Gateways\PaystackGateway;
use App\Services\Payments\PaymentGatewayManager;
use App\Services\Payments\PaymentWebhookProcessor;
use App\Services\Subscription\PlatformBillingGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where the provider tells Storify about a charge Storify itself made.
 *
 * ## Why this is not a scope of the merchant endpoint
 *
 * `POST /webhooks/payments/{provider}/{scope}` is the URL a business pastes into
 * its own dashboard, and it resolves `{scope}` by searching connections for one
 * whose *stored secret* verifies the signature. These deliveries are addressed to
 * none of those: they are signed with the platform's own secret, they are only
 * sent to an endpoint whose `event_source` is `connect`, and the body names the
 * platform's account rather than any connection of a business's. Under the
 * merchant endpoint they would fail to verify against every candidate and be
 * answered 401 forever.
 *
 * It is also not a scope of that route's *path*, which is what the earlier draft
 * of this feature assumed: `{scope}` is a single `[A-Za-z0-9_]+` segment, so
 * `/webhooks/payments/bachs/connect` resolves to `provider=bachs, scope=connect`
 * and never reaches a controller of its own. Hence `/webhooks/connect/{provider}`.
 *
 * ## What it does with a delivery
 *
 * Four things, in this order: prove the signature is the platform's, claim the
 * event by its own id so a retry cannot do the work twice, have a look at the
 * account the payload names, and then hand it to whatever the provider says it
 * means — {@see BachsEventCatalogue} for Bachs' vocabulary, the provider's own
 * driver for a charge. Settlement is not reimplemented here — the browser verify
 * and this endpoint call the same processor, guarded on the transaction's own
 * status, so a sale cannot be settled twice by arriving on both.
 *
 * The account named, when the payload names one, is now the **platform's own**:
 * see the section below.
 *
 * **Two providers arrive here now, and only two.** `{provider}` is narrowed to
 * the providers the platform holds credentials for, which is the set it can
 * charge a business's customers through: those are the only ones these
 * deliveries can be about. The merchant endpoint
 * (`/webhooks/payments/{provider}/{scope}`) is a different thing and stays
 * exactly as it was — see {@see PaymentWebhookController} for why it must not
 * follow this.
 *
 * ## It used to work out *whose* account a delivery was about, and cannot any more
 *
 * That step is gone, and its absence is the shape of the change rather than a
 * simplification. Every delivery that arrives here is now about **Storify's own**
 * account at the provider: the charge named nothing of the business's, so a
 * chargeback is filed against our merchant account, a refund's outcome comes back
 * against our charge, and a payout is our balance sending money out. A business is
 * reached, when it is reached at all, by looking up the *charge* — the
 * transaction carries whose sale it was and how much they were owed — and the
 * services that do that take the platform's payload directly.
 *
 * `account.*` and `capability.*` are the deliveries that fall out of this. They
 * are about the account a business used to have, there is no longer one, and
 * nothing in the system depends on the answer — so they arrive, verify, are
 * answered 200, and are logged. See {@see BachsEventCatalogue}.
 *
 * ## The status codes are deliberate
 *
 * `400` for a signature that will never verify: the same body with the same
 * secret fails again, and a 4xx is how the provider is told not to spend its
 * retry budget. `200` for a payout we have no row for, for an event we have
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
        private readonly PayoutSweepService $payouts,
        private readonly ConnectAccountEventService $events,
    ) {}

    /**
     * Provider-keyed, and the key is the platform's credentials: the endpoint
     * exists for deliveries about charges the platform makes, and holding a key
     * for a provider is exactly what declares it can make them. A provider the
     * platform cannot charge through has nothing for a delivery here to be about,
     * so it is refused rather than quietly parsed by a driver that cannot settle
     * against it.
     *
     * Bachs and Paystack arrive here for the same reason and are then handled
     * slightly differently, because their payloads are different things: Bachs
     * documents a vocabulary of its own ({@see BachsEventCatalogue}), while
     * Paystack's deliveries are all about a charge and are already translated by
     * its driver.
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        if (! PlatformBillingGateway::canChargeThrough($provider)) {
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
     * they answer different questions. Bachs documents a vocabulary of its own,
     * so its deliveries go through {@see BachsEventCatalogue}. Paystack's are all
     * about a charge, and the provider's own vocabulary for those is already
     * translated by its driver in {@see PaystackGateway::parseWebhook()} —
     * `charge.success` to `PAYMENT_SUCCESS`, and so on — so there is no second
     * catalogue to keep in step with the first, and the dispatch below reads the
     * driver's answer instead of inventing a parallel one.
     *
     * Every branch is now about the **platform's** account: settlement is a
     * charge we made, a payout is our balance leaving, a dispute is filed against
     * our merchant account. Nothing here resolves a tenant, and nothing here
     * needs to — see the class docblock for why that step is gone.
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
            // A provider that has named its own vocabulary for money leaving —
            // Paystack calls them `transfer.*` — is routed by that name rather
            // than by the disposition table below, which is Bachs'. Everything
            // else on this path is a charge, and `dispatchCharge` says so.
            if ($event?->type === WebhookEventType::PAYOUT) {
                return $this->payout($data, $type);
            }

            return $this->dispatchCharge($driver, $credentials, $event, $type, $accountId);
        }

        $disposition = BachsEventCatalogue::dispositionFor($type);

        if ($accountId !== null) {
            // Recorded, not acted on. The account a payload names is now the
            // platform's own — a business's no longer exists — so the fact is
            // worth a log line when somebody is reading a delivery back, and it
            // is not worth a branch.
            Log::info('bachs.connect.account_named', ['account_id' => $accountId, 'type' => $type]);
        }

        return match ($disposition) {
            BachsEventCatalogue::SETTLE => $this->dispatchCharge($driver, $credentials, $event, $type, $accountId),
            BachsEventCatalogue::PAYOUT => $this->payout($data, $type),
            BachsEventCatalogue::DISPUTE => $this->dispute($data, $type),
            BachsEventCatalogue::REFUND => $this->refund($data, $type),
            // Understood, and nothing to do. A single answer for both because
            // the *reason* is the same for both: the account these are about was
            // deleted, so there is no state to keep. The catalogue still tells
            // them apart, and its own comments say what each one used to mean.
            BachsEventCatalogue::ACCOUNT, BachsEventCatalogue::NOTE => $this->noted($type),
            BachsEventCatalogue::UNUSED => ['ignored', Response::HTTP_OK],
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
     * An outcome for money leaving Storify's balance.
     *
     * Answered 200 whether or not a row was found, and deliberately: a payout
     * delivery we cannot place is one another environment sent, or one from
     * before the `payouts` table existed, and neither is fixed by a retry. The
     * log line inside the sweep is what makes it visible.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int}
     */
    private function payout(array $data, string $type): array
    {
        $recorded = $this->payouts->applyPayoutWebhook($data, $type);

        return [$recorded ? 'payout recorded' : 'payout not found', Response::HTTP_OK];
    }

    /**
     * A chargeback.
     *
     * Identified by the **charge**, not by an account, because the account the
     * delivery names is Storify's own and identifies nothing. See
     * {@see ConnectAccountEventService::recordPlatformDispute()}.
     *
     * Answered 200 when the charge cannot be found, and the service logs it as an
     * error rather than a warning when that happens: a dispute we cannot attach
     * to a sale is money leaving Storify's balance with nobody recorded as owing
     * it, which is worth more than a 500 the provider would retry into the same
     * dead end.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int}
     */
    private function dispute(array $data, string $type): array
    {
        $recorded = $this->events->recordPlatformDispute($data, $type);

        return [$recorded ? 'dispute recorded' : 'dispute not recorded', Response::HTTP_OK];
    }

    /**
     * A refund's outcome.
     *
     * Answered 200 whether or not the charge was identified: the refund itself
     * was posted by whoever made it, and this delivery only says whether it
     * arrived. A `refund.failed` that cannot be attached is logged as an error by
     * the service for the same reason a dispute's is — it is money that did not
     * reach a customer.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string, 1: int}
     */
    private function refund(array $data, string $type): array
    {
        $recorded = $this->events->recordPlatformRefund($data, $type);

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
