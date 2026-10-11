<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Admin\AutoPayoutRequest;
use App\Models\Business;
use App\Services\Payments\Connect\ConnectAccountEventService;
use App\Services\Payments\Connect\ConnectConnections;
use App\Services\Payments\Connect\PlatformPayoutService;
use App\Support\Money\Naira;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * WS-39 — an admin paying a business's settled money out by hand.
 *
 * The sweep already does this without anyone clicking, and that is the normal
 * path. This exists for the two cases where it is not: a business that has
 * asked because their money has not arrived, and a sweep that has not run since
 * the funds settled. Both end the same way — the money is read from the
 * provider and withdrawn to the bank the business registered.
 *
 * **Read the account before every payout.** The provider's `available_balance`
 * is the only figure that can be sent (an amount above it is refused), a payout
 * cannot be cancelled, and the amount is what the *destination* receives with
 * the provider's fee added on top. So this returns the account's position
 * alongside the history, and the console shows both before the button is
 * offered.
 *
 * Gated on `admin.finance` like the rest of AD-12, and on the platform-admin
 * guard as well: every business's in-business "Super Admin" role carries the
 * `admin.*` permission names, so the permission alone would let a business move
 * another business's money.
 *
 * ## Automatic, and the switch that stops it
 *
 * Paying by hand is the exception. Normally the sweep withdraws settled money on
 * its own, for every business, and this panel is where an admin can stop that
 * for one business — see `updateAutomatic()`. The switch governs the automatic
 * withdrawal only; the button below it keeps working either way, which is how a
 * business that has asked to accumulate its balance still gets paid when it asks.
 */
class ConnectPayoutController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly PlatformPayoutService $payouts,
        private readonly ConnectConnections $connections,
        private readonly ConnectAccountEventService $events,
    ) {}

    /**
     * Everything the console needs to decide whether to pay, and by how much.
     */
    public function index(Request $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $storeId = $this->storeId($request);
        $businessId = (int) $business->id;

        return $this->ok([
            'business' => [
                'id' => $businessId,
                'business_code' => $business->business_code,
                'name' => $business->name,
            ],
            'account' => $this->payouts->accountSummary($businessId, $storeId),
            'history' => $this->historyPayload($businessId, $storeId),
            // Whether the sweep will move this business's money on its own.
            // Top-level rather than per-scope because it is a business-wide
            // decision: the switch holds every account the business holds, not
            // just the store whose tab happens to be open.
            'auto_payout' => $this->payouts->autoPayoutEnabled($businessId),
            // Every account this business holds, so the console can offer a
            // choice of scope. `account` above answers for the scope actually
            // selected; this is the list that makes the others reachable at all
            // — a store with its own subaccount is otherwise invisible here,
            // and its money is the store's.
            'scopes' => $this->scopePayload($businessId),
            // Not money we move, but money the business has already lost or
            // refunded. Shown beside the payout history because that is what
            // explains an `available_balance` that is smaller than the sales
            // suggest, and a finance admin asked "why is this short" should not
            // have to go to the provider's dashboard to find out.
            'disputes' => $this->events->disputes($businessId, $storeId),
            'refunds' => $this->events->refunds($businessId, $storeId),
        ]);
    }

    /**
     * Pay out now, and answer with what happened.
     *
     * A refused or empty payout is still a `200`: "nothing has settled yet" is
     * the ordinary state of a healthy account and not an error, and answering
     * with a 4xx would have the console render a failure for it. The `outcome`
     * is what tells the two apart.
     */
    public function store(Request $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $storeId = $this->storeId($request);
        $businessId = (int) $business->id;

        $result = $this->payouts->payOut($businessId, $storeId);

        return $this->ok([
            'outcome' => $result->outcome,
            'message' => $result->message,
            'amount' => $result->amountKobo === null ? null : Naira::decimalFromKobo($result->amountKobo),
            'amount_kobo' => $result->amountKobo,
            'payout_id' => $result->payoutId,
            'provider_status' => $result->providerStatus,
            'account' => $this->payouts->accountSummary($businessId, $storeId),
            'history' => $this->historyPayload($businessId, $storeId),
        ], $this->outcomeMessage($result->outcome, $result->message));
    }

    /**
     * Turn automatic withdrawal on or off for one business.
     *
     * On is what every business already has: the hourly sweep empties its
     * connected account into the bank it registered, with nobody clicking
     * anything. Off means the sweep and the post-settlement job both leave it
     * alone, and the money accumulates in the business's own provider account
     * until an admin pays it out by hand from this same panel.
     *
     * The answer carries the new state *and* a refreshed view of the accounts,
     * because the console renders both from one response and a second round
     * trip could show a stale switch beside fresh balances.
     */
    public function updateAutomatic(AutoPayoutRequest $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $data = $request->validated();
        $autoPayout = (bool) $data['auto_payout'];
        $businessId = (int) $business->id;

        $this->payouts->setAutoPayout($business, $autoPayout, $request->user(), $data['reason'] ?? null);

        return $this->ok([
            'business' => [
                'id' => $businessId,
                'business_code' => $business->business_code,
                'name' => $business->name,
            ],
            'auto_payout' => $autoPayout,
            'scopes' => $this->scopePayload($businessId),
        ], $autoPayout
            ? 'Settled money will be withdrawn to this business\'s bank automatically.'
            : 'Settled money will stay in this business\'s payment account until you pay it out.');
    }

    /**
     * The recorded attempts, in the shape the console renders.
     *
     * @return array<int, array<string, mixed>>
     */
    private function historyPayload(int $businessId, ?int $storeId): array
    {
        return array_map(fn (array $entry): array => [
            'id' => $entry['id'] ?? null,
            'reference' => $entry['reference'] ?? null,
            'amount' => isset($entry['amount_kobo']) ? Naira::decimalFromKobo((int) $entry['amount_kobo']) : null,
            'amount_kobo' => $entry['amount_kobo'] ?? null,
            'status' => $entry['status'] ?? null,
            'failure_reason' => $entry['failure_reason'] ?? null,
            'destination' => $entry['destination'] ?? null,
            'created_at' => $entry['created_at'] ?? null,
        ], $this->payouts->history($businessId, $storeId));
    }

    /**
     * Every payout account this business holds, in the shape the console lists.
     *
     * A store with its own subaccount is a separate payout and therefore has to
     * be separately selectable — otherwise the only account an admin can pay is
     * the business's, and a store that banks on its own is left with money it
     * cannot withdraw. The API has always been able to pay a store scope; this
     * is what makes the console aware there is one.
     *
     * Each entry carries its own summary rather than just an id, because the
     * choice an admin is making is *which account has money in it*, and a list
     * of ids would have them pick one to find out.
     *
     * @return array<int, array<string, mixed>>
     */
    private function scopePayload(int $businessId): array
    {
        $connections = $this->connections->forBusiness($businessId);

        if ($connections === []) {
            return [];
        }

        $names = DB::table('stores')
            ->whereIn('id', array_filter(array_map(
                fn (object $connection): ?int => $connection->source_table === 'store_payment_method'
                    ? (int) $connection->scope_id
                    : null,
                $connections,
            )))
            ->pluck('name', 'id');

        return array_map(function (object $connection) use ($businessId, $names): array {
            $storeId = $connection->source_table === 'store_payment_method'
                ? (int) $connection->scope_id
                : null;

            $config = $this->connections->config($connection);
            $managed = is_array($config['managed'] ?? null) ? $config['managed'] : [];

            // Where this account's money can actually go, read from the stored
            // config rather than from the provider — so the console can say
            // "nowhere to send it" even when the provider cannot be reached,
            // which is exactly when an admin goes looking.
            $destination = $this->destinationSummary($managed);

            return [
                'store_id' => $storeId,
                // Null store_id is the business's own account, which is the
                // default for every store without one of its own.
                'label' => $storeId === null
                    ? 'Whole business'
                    : 'Store: '.($names[$storeId] ?? "#{$storeId}"),
                'account_id' => (string) $connection->gateway_account_id,
                'status' => (string) ($managed['status'] ?? 'unknown'),
                'is_paused' => ! (bool) $connection->is_active,
                // "Money can reach a bank from here" — the same question
                // `payOutForConnection()` answers before it skips, and about a
                // registered destination as well as a usable one: an account
                // the provider has rejected is no more reachable than one that
                // was never entered, and the console's warning is the same for
                // both. Which of the two it is comes from `destination.is_usable`.
                'has_destination' => (bool) ($destination['is_usable'] ?? false),
                // Masked, and only ever masked: the account number the owner
                // typed was never written on our side (see
                // `ManagedAccount::maskDestination()`), so there is nothing
                // fuller to send even if the console asked for it.
                'destination' => $destination,
                // Null rather than a zero when the provider could not be asked.
                // The console shows a dash for "unknown" and 0.00 for "nothing
                // has settled", and only one of those is worth chasing.
                'available_kobo' => $this->payouts->accountSummary($businessId, $storeId)['available_kobo'] ?? null,
            ];
        }, $connections);
    }

    /**
     * The stored payout destination, masked, in the exact shape
     * {@see PlatformPayoutService::accountSummary()} already returns for the
     * selected scope.
     *
     * The same shape deliberately: the console shows the destination of the
     * account it picked *and* the destination of each account it might pick, and
     * two shapes for one fact would mean two renderers and two chances to
     * disagree.
     *
     * `is_usable` is carried because "no bank registered" and "the provider
     * rejected the bank that was registered" are the same amount of stuck money
     * but two different phone calls, and only the admin can start the right one.
     *
     * @param  array<string, mixed>  $managed
     * @return array<string, mixed>|null
     */
    private function destinationSummary(array $managed): ?array
    {
        $destination = $managed['payout_destination'] ?? null;

        if (! is_array($destination)) {
            return null;
        }

        return [
            'id' => $destination['id'] ?? null,
            'bank_name' => $destination['bank_name'] ?? null,
            'account_name' => $destination['account_name'] ?? null,
            'last4' => $destination['last4'] ?? null,
            'currency' => $destination['currency'] ?? null,
            'is_usable' => (bool) ($destination['is_usable'] ?? false),
            'status' => $destination['status'] ?? null,
        ];
    }

    /**
     * The `store_id` scope, when one is named.
     *
     * A store with its own account is a separate payout, so the same parameter
     * every other business-scoped endpoint takes means the same thing here.
     *
     * Read through `input()`, which checks the payload and the query string,
     * rather than `query()` alone: the read is a `GET` and passes it as a query
     * parameter, but the payout is a `POST` and sends it in the body. Reading
     * only the query string meant the console's chosen scope was silently
     * ignored and the business's own account paid instead — a store that banks
     * on its own would never see its money move, and nothing would say why.
     */
    private function storeId(Request $request): ?int
    {
        $storeId = $request->input('store_id');

        return is_numeric($storeId) ? (int) $storeId : null;
    }

    /**
     * A sentence for the flash message. The service's own wording is used where
     * it has one, because it names the actual problem — a rejected bank account,
     * a debt, an identical payout already in flight.
     */
    private function outcomeMessage(string $outcome, ?string $message): string
    {
        if ($message !== null && $message !== '') {
            return $message;
        }

        return match ($outcome) {
            'paid' => 'Payout requested.',
            'skipped' => 'Nothing to pay out.',
            default => 'The payout could not be made.',
        };
    }
}
