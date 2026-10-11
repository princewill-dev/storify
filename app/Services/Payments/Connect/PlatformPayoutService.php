<?php

namespace App\Services\Payments\Connect;

use App\Models\Business;
use App\Models\User;
use App\Services\Accounting\LedgerPostingService;
use App\Services\ActivityRecorder;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Gateways\BachsApi;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Money\Naira;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Moving a business's settled money out of its Bachs account and into its bank.
 *
 * Storify holds nothing here: the sale lands in the business's own connected
 * account, and this is the step that empties it. It runs automatically — on a
 * schedule, and after a charge settles — so nobody has to click anything, and
 * again by hand from the admin console when a business asks.
 *
 * **Automatic is the default, not a law.** `businesses.auto_payout` is an
 * admin's switch, and the automatic paths consult it — see
 * {@see autoPayoutEnabled()} and {@see setAutoPayout()}. With it off, a
 * business's settled money stays in its own account at the provider and moves
 * only when somebody asks for it. The switch never governs the manual payout:
 * holding a business's money and refusing to release it are different things.
 *
 * **What "instant" can and cannot mean.** A charge lands in `pending_balance`
 * and only becomes movable when the provider settles it; nothing anywhere can
 * withdraw unsettled funds, and an attempt returns `INSUFFICIENT_BALANCE`. So
 * the promise this makes is "withdrawn the moment it settles", which is a
 * settlement window of days — not seconds after the customer pays. The screen
 * has to say that in those words rather than implying otherwise.
 *
 * **Three things make this the most dangerous code in the payment stack.**
 * Money leaves, a payout **cannot be cancelled**, and the provider holds an
 * account's first-ever payout for a one-time review. So: the amount is what the
 * provider itself reports as available and nothing else; the request carries an
 * idempotency key that is derived rather than random, so a retry after a crash
 * is the same request; and every attempt is recorded on the connection row
 * whether it succeeded or not.
 */
final class PlatformPayoutService
{
    /** How many past payouts a connection row keeps for the console to show. */
    private const HISTORY_LIMIT = 20;

    /** The currency every managed destination is registered in. */
    private const CURRENCY = 'NGN';

    /**
     * The statuses that mean the money has actually left.
     *
     * `completed` is Bachs' terminal success; `paid` is accepted because the
     * payout *event* is named for it and a payload that reports the event's own
     * name in the status field would otherwise read as still in flight and never
     * be posted.
     */
    private const PAID_STATUSES = ['completed', 'paid'];

    public function __construct(
        private readonly PlatformBillingGateway $platform,
        private readonly BachsConnectAccountService $accounts,
        private readonly LedgerPostingService $ledger,
        private readonly ConnectConnections $connections,
    ) {}

    /**
     * Pay out every account that has settled money waiting.
     *
     * Called by the scheduled sweep. Returns counts rather than throwing: one
     * business's rejected bank account must not stop the other ninety-nine from
     * being paid, so each failure is logged and the loop continues.
     *
     * `held` is its own count rather than a kind of `skipped`. Both mean "no
     * money moved", but they are opposite answers to the question an operator
     * asks when a business says its transfer never arrived: "nothing had
     * settled" is the system working, "we are deliberately holding this" is a
     * decision somebody made. Folding them together would hide that.
     *
     * @return array{paid: int, held: int, skipped: int, failed: int}
     */
    public function sweep(): array
    {
        $counts = ['paid' => 0, 'held' => 0, 'skipped' => 0, 'failed' => 0];

        // Checked once, before anything is attempted. It was previously checked
        // inside the loop, where it only ever ran on the first iteration and
        // read as though it were per-connection.
        if (($problem = $this->platform->credentialProblem('bachs')) !== null) {
            Log::warning('bachs.payout.sweep_unavailable', ['reason' => $problem]);

            return $counts;
        }

        foreach ($this->connections->all() as $connection) {
            $target = $this->connections->scopeOf($connection);

            if (! $this->autoPayoutEnabled($target['business_id'])) {
                $counts['held']++;

                continue;
            }

            $result = $this->payOutForConnection($connection);

            $counts[$result->outcome]++;
        }

        return $counts;
    }

    /**
     * Whether this business's settled money is withdrawn on its own.
     *
     * The admin's switch, read here rather than passed in, so the automatic
     * paths and the console that flips it cannot disagree about what it means.
     * Read fresh every time and never memoised, for the same reason
     * {@see PlatformCommission::fromSettings()} is not: it is admin-mutable
     * state, and a service instance that outlived the change would keep
     * answering with the value it first saw.
     *
     * A missing row resolves to `true`. A connection whose business has
     * vanished is an anomaly, and the safe reading of an anomaly is the one
     * that lets the business's money reach its bank rather than the one that
     * traps it in a provider account with nothing on screen to explain why.
     *
     * Deliberately governs the **automatic** withdrawals only. {@see payOut()}
     * does not consult it, because an admin clicking "Pay out now" is a person
     * deciding, not a schedule.
     */
    public function autoPayoutEnabled(int $businessId): bool
    {
        $value = DB::table('businesses')->where('id', $businessId)->value('auto_payout');

        return $value === null ? true : (bool) $value;
    }

    /**
     * Turn the automatic withdrawal for one business on or off.
     *
     * Recorded as an activity rather than only as a column write: "who stopped
     * this business's payouts, and when" is a question that arrives weeks later,
     * from a business asking where its money is. The flag and its record stand
     * or fall together, exactly as the gateway grant does.
     */
    public function setAutoPayout(Business $business, bool $autoPayout, User $actor, ?string $reason = null): void
    {
        $before = (bool) ($business->auto_payout ?? true);

        if ($before === $autoPayout) {
            // Nothing changed, so nothing is written and nothing is recorded. A
            // console re-saving the value it already shows is not an event, and
            // an activity row for it is noise in a log read to find exactly the
            // moments something did change.
            return;
        }

        DB::transaction(function () use ($business, $autoPayout, $before, $actor, $reason): void {
            DB::table('businesses')->where('id', $business->id)->update([
                'auto_payout' => $autoPayout,
                'updated_at' => now(),
            ]);

            ActivityRecorder::record(
                action: $autoPayout ? 'business_payout_automatic_enabled' : 'business_payout_automatic_disabled',
                description: $autoPayout
                    ? "Business '{$business->name}' is paid its settled money automatically again"
                    : "Business '{$business->name}' will hold its settled money in its payment account",
                subject: $business,
                old: ['auto_payout' => $before],
                new: ['auto_payout' => $autoPayout],
                metadata: $reason === null ? [] : ['reason' => $reason],
                actor: $actor,
                businessId: (int) $business->id,
            );
        });

        Log::info('bachs.payout.auto_payout_changed', [
            'business_id' => $business->id,
            'auto_payout' => $autoPayout,
            'actor_user_id' => $actor->id,
        ]);
    }

    /**
     * Pay one business — or one store — out now.
     *
     * The manual path from the admin console, and the reason it exists rather
     * than only the sweep: a business that has asked, or a sweep that has not
     * run since the funds settled, should not require waiting for the next tick.
     */
    public function payOut(int $businessId, ?int $storeId = null): PayoutResult
    {
        $connection = $this->connections->for($businessId, $storeId);

        if ($connection === null) {
            return PayoutResult::skipped('This business has no Storify payment account.');
        }

        if (($problem = $this->platform->credentialProblem('bachs')) !== null) {
            return PayoutResult::failed('Storify cannot process payments at the moment: '.$problem);
        }

        return $this->payOutForConnection($connection);
    }

    /**
     * What has left this account, newest first, as far as we recorded it.
     *
     * Read off the connection row rather than from the provider, because this is
     * a history of *our* attempts: a payout the provider refused never became a
     * payout object at Bachs and would be missing from its ledger, and those
     * refusals are exactly the ones an admin needs to see.
     *
     * @return array<int, array<string, mixed>>
     */
    public function history(int $businessId, ?int $storeId = null): array
    {
        $connection = $this->connections->for($businessId, $storeId);

        if ($connection === null) {
            return [];
        }

        $history = $this->connections->config($connection)['payouts'] ?? null;

        return is_array($history) ? array_values(array_filter($history, 'is_array')) : [];
    }

    /**
     * The account this business is paid through, and what it can move — the
     * numbers the console needs before offering to pay out.
     *
     * `available` is the provider's own settled figure and null when there is no
     * account or the provider could not be asked, which are different things and
     * are told apart by `account_id` being null.
     *
     * @return array<string, mixed>|null
     */
    public function accountSummary(int $businessId, ?int $storeId = null): ?array
    {
        $connection = $this->connections->for($businessId, $storeId);

        if ($connection === null) {
            return null;
        }

        $accountId = (string) ($connection->gateway_account_id ?? '');

        if ($accountId === '') {
            return null;
        }

        $config = $this->connections->config($connection);
        $managed = is_array($config['managed'] ?? null) ? $config['managed'] : [];
        $destinationId = $this->destinationIdFrom($config);
        $balance = $destinationId === null ? null : $this->settledBalance($accountId);

        return [
            'account_id' => $accountId,
            'status' => (string) ($managed['status'] ?? 'unknown'),
            'payout_destination' => $this->destinationFrom($config),
            'available_kobo' => $balance['available'] ?? null,
            'currency' => $balance['currency'] ?? self::CURRENCY,
            'store_id' => $connection->source_table === 'store_payment_method'
                ? (int) $connection->scope_id
                : null,
        ];
    }

    /**
     * A `payout.paid` or `payout.failed` delivery, applied to the account it
     * names.
     *
     * `pending` is not a terminal state and neither is `processing`: the debit
     * happens when the payout is created, so a failure has to put the money back
     * where the console can see it rather than leaving a row the provider has
     * already refunded reading as though it succeeded.
     *
     * @param  array<string, mixed>  $data  the webhook's `data` block
     */
    public function applyPayoutWebhook(string $accountId, array $data, string $type = ''): bool
    {
        $payoutId = $this->payoutIdFrom($data);

        if ($payoutId === null) {
            return false;
        }

        $connection = $this->connections->forAccount($accountId);

        if ($connection === null) {
            // Not ours: logged and dropped, not an error worth a retry.
            Log::info('bachs.payout.unknown_account', ['account_id' => $accountId]);

            return false;
        }

        return $this->applyToConnection($connection, $payoutId, $data, $type);
    }

    /**
     * A payout delivery that named no account, matched by the payout id itself.
     *
     * `payout.failed` is documented without a top-level `account` — the one
     * connect delivery that is — so the account lookup that every other event
     * routes through finds nothing and the delivery would be dropped as "not a
     * connected account". Dropping a failed withdrawal is the worst possible
     * version of that: the money did **not** leave, our history still says it
     * did, and nothing anywhere notices.
     *
     * The match is on the provider's payout id, which we recorded when we
     * created it, so this is a lookup in our own data rather than a guess. It
     * reads every connection, which is only acceptable because it happens for
     * one event type and there are as many connections as there are businesses
     * being paid — and because the alternative is losing the event.
     *
     * Returns the same as {@see applyPayoutWebhook()}: true when a recorded
     * attempt was updated, false when the id is not one of ours.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyPayoutWebhookWithoutAccount(array $data, string $type = ''): bool
    {
        $payoutId = $this->payoutIdFrom($data);

        if ($payoutId === null) {
            return false;
        }

        foreach ($this->connections->all() as $connection) {
            $history = $this->connections->config($connection)['payouts'] ?? [];

            if (! is_array($history)) {
                continue;
            }

            foreach ($history as $entry) {
                if (is_array($entry) && ($entry['id'] ?? null) === $payoutId) {
                    return $this->applyToConnection($connection, $payoutId, $data, $type);
                }
            }
        }

        Log::info('bachs.payout.unmatched', ['payout_id' => $payoutId]);

        return false;
    }

    /**
     * The payout id in a delivery, whichever of the two names it arrived under.
     *
     * The REST API calls it `id` (`GET /v1/payouts/{withdrawal_id}` takes it
     * under that other name, and returns it as `id`), and the webhook pages
     * describe a `withdrawal_id`. Both are read rather than one being picked:
     * reading only `id` would silently stop recording every withdrawal outcome,
     * and reading only `withdrawal_id` would stop recording them the moment the
     * payloads agree with the API.
     *
     * @param  array<string, mixed>  $data
     */
    private function payoutIdFrom(array $data): ?string
    {
        foreach (['withdrawal_id', 'id'] as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }

            if (is_int($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Fold one delivery into a connection's recorded history.
     *
     * @param  array<string, mixed>  $data
     */
    private function applyToConnection(object $connection, string $payoutId, array $data, string $type = ''): bool
    {
        $status = $this->statusFor($data, $type);
        $config = $this->connections->config($connection);
        $recorded = $this->recordedStatus($config, $payoutId);

        // Out-of-order protection. The provider does not guarantee the delivery
        // order, and `payout.paid` followed by a late `processing` (or a
        // redelivered `payout.created`) would otherwise walk a withdrawn payout
        // back to "in flight" — and, because the ledger entry is posted on the
        // way *into* `paid`, leave the books and the history disagreeing with no
        // way to tell which is right.
        if ($recorded !== null && in_array($recorded, self::PAID_STATUSES, true)
            && ! in_array($status, self::PAID_STATUSES, true)) {
            Log::info('bachs.payout.out_of_order', [
                'payout_id' => $payoutId,
                'recorded' => $recorded,
                'reported' => $status,
            ]);

            return true;
        }

        if (! $this->updateHistory($config, $payoutId, [
            'status' => $status,
            'failure_reason' => $data['failure_reason'] ?? null,
            'settled_at' => in_array($status, self::PAID_STATUSES, true) || $status === 'failed'
                ? now()->toIso8601String()
                : null,
        ])) {
            return false;
        }

        $this->connections->writeConfig($connection, $config);

        // The withdrawal is posted here and not when the payout was requested,
        // because until the provider says it is paid the money has not arrived
        // anywhere — the debit at creation is provisional and a payout can still
        // fail. A failure is therefore nothing to unwind on the books, which is
        // exactly why nothing is posted for it: the entry that would have needed
        // reversing was never written.
        if (in_array($status, self::PAID_STATUSES, true)) {
            $this->ledger->safe(fn () => $this->ledger->postGatewayWithdrawal(
                (int) $connection->business_id,
                $connection->source_table === 'store_payment_method' ? (int) $connection->scope_id : null,
                (int) Naira::koboFromStrict($data['amount'] ?? 0),
                $payoutId,
            ));
        }

        Log::info('bachs.payout.settled', [
            'payout_id' => $payoutId,
            'status' => $status,
        ]);

        return true;
    }

    /**
     * The status we last recorded for one payout, or null when we have none.
     *
     * @param  array<string, mixed>  $config
     */
    private function recordedStatus(array $config, string $payoutId): ?string
    {
        foreach ((array) ($config['payouts'] ?? []) as $entry) {
            if (is_array($entry) && ($entry['id'] ?? null) === $payoutId) {
                return is_string($entry['status'] ?? null) ? $entry['status'] : null;
            }
        }

        return null;
    }

    /**
     * A delivery's status, from its own field or, failing that, its event name.
     *
     * `payout.failed` is documented as carrying no `status` — the event *is* the
     * status — so a payload read literally would record an empty string, and an
     * empty string is not what the screen matches on to show a refused payout.
     * The event name is the same answer the provider already gave, one step
     * earlier; deriving it is not a guess.
     *
     * @param  array<string, mixed>  $data
     */
    private function statusFor(array $data, string $type): string
    {
        $status = strtolower((string) ($data['status'] ?? ''));

        if ($status !== '') {
            return $status;
        }

        return match (strtolower($type)) {
            'payout.paid' => 'paid',
            'payout.failed' => 'failed',
            'payout.created' => 'pending',
            default => 'unknown',
        };
    }

    /**
     * Which tenant a connected account belongs to.
     *
     * The reverse of {@see ConnectConnections::forAccount()}, and needed by anything
     * that starts from the provider rather than from a business — a webhook body
     * names an account and nothing else. Null when the account is not one of
     * ours, which the caller answers rather than treating as an error: a
     * delivery about an account we do not know is a delivery we can do nothing
     * with, and a retry would not change that.
     *
     * @return array{business_id: int, store_id: int|null}|null
     */
    public function tenantForAccount(string $accountId): ?array
    {
        $connection = $this->connections->forAccount($accountId);

        if ($connection === null) {
            return null;
        }

        return [
            'business_id' => (int) $connection->business_id,
            'store_id' => $connection->source_table === 'store_payment_method'
                ? (int) $connection->scope_id
                : null,
        ];
    }

    /**
     * The money this account can actually move, in kobo — or null when the
     * provider could not be asked.
     *
     * `available_balance` only. `pending_balance` is charged money that has not
     * settled, and paying it is the one mistake that fails with
     * `INSUFFICIENT_BALANCE` at best.
     *
     * @return array{available: int, currency: string, pending: int}|null
     */
    public function settledBalance(string $accountId, ?GatewayCredentials $credentials = null): ?array
    {
        $credentials ??= $this->platform->credentialsFor('bachs');

        try {
            $response = BachsApi::client($credentials, $accountId)->get(BachsApi::baseUrl($credentials).'/balances');
        } catch (\Throwable $e) {
            Log::warning('bachs.payout.balance_unreadable', ['account_id' => $accountId, 'error' => $e->getMessage()]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('bachs.payout.balance_refused', [
                'account_id' => $accountId,
                'status' => $response->status(),
            ]);

            return null;
        }

        $body = $response->json() ?? [];

        // The response lists every currency the account holds, USD always among
        // them, so the entry has to be selected rather than assumed.
        foreach (($body['balances'] ?? []) as $entry) {
            if (! is_array($entry) || strtoupper((string) ($entry['currency'] ?? '')) !== self::CURRENCY) {
                continue;
            }

            // Decimal strings at currency precision — the provider's boundary
            // convention, and the opposite of every other driver's.
            return [
                'available' => (int) Naira::koboFromStrict($entry['available_balance'] ?? 0),
                'pending' => (int) Naira::koboFromStrict($entry['pending_balance'] ?? 0),
                'currency' => self::CURRENCY,
            ];
        }

        return ['available' => 0, 'pending' => 0, 'currency' => self::CURRENCY];
    }

    /**
     * The whole operation for one connection row: refresh, read, withdraw.
     *
     * @param  object  $connection  a row from `business_payment_method` or `store_payment_method`
     */
    private function payOutForConnection(object $connection): PayoutResult
    {
        $accountId = (string) ($connection->gateway_account_id ?? '');

        if ($accountId === '') {
            return PayoutResult::skipped('No payout account is registered.');
        }

        $config = $this->connections->config($connection);

        // Read the account first. This is the only call that reports a
        // capability having been withdrawn or a destination having been
        // rejected, and both must be known before money is committed — there is
        // no endpoint to take a payout back.
        $account = $this->accounts->refresh($accountId);

        if ($account !== null) {
            $config['managed'] = $account->withDestination($this->destinationFrom($config))->toConfig();
        }

        $destinationId = $account?->payoutDestinationId() ?? $this->destinationIdFrom($config);

        if (! is_string($destinationId) || $destinationId === '') {
            $this->connections->writeConfig($connection, $config);

            return PayoutResult::skipped('No bank account has been registered for payouts yet.');
        }

        $balance = $this->settledBalance($accountId);

        if ($balance === null) {
            $this->connections->writeConfig($connection, $config);

            return PayoutResult::skipped('Bachs could not be asked for this account\'s balance.');
        }

        if ($balance['available'] < 1) {
            // The ordinary answer while a charge is still settling. Not a
            // failure and not worth a log line on every tick.
            $this->connections->writeConfig($connection, $config);

            return PayoutResult::skipped('Nothing has settled yet.');
        }

        $amount = $balance['available'];
        $reference = $this->reference();
        $idempotencyKey = $this->idempotencyKey($accountId, $destinationId, $amount);

        $result = $this->createPayout($accountId, $destinationId, $amount, $reference, $idempotencyKey);

        // The in-memory `$config` rather than a fresh read of the row: it is the
        // one carrying the state the account refresh just reported, and
        // re-reading here would throw that away — leaving a capability the
        // provider had withdrawn, or a destination it had rejected, unrecorded
        // on the very run that acted on it.
        //
        // A refusal never got a provider status to report, which is what the
        // fallback is for: the console shows "failed" rather than an empty cell.
        $config = $this->pushHistory($config, [
            'id' => $result->payoutId,
            'reference' => $reference,
            'amount_kobo' => $amount,
            'currency' => self::CURRENCY,
            'destination' => $destinationId,
            'status' => $result->providerStatus ?? $result->outcome,
            'failure_reason' => $result->message,
            'created_at' => now()->toIso8601String(),
        ]);
        $this->connections->writeConfig($connection, $config);

        return $result;
    }

    /**
     * `POST /v1/payouts`, as the business's account.
     *
     * `amount` is what the *destination* receives and the provider's fee is
     * added on top, so the balance must cover more than the number sent — which
     * is why the amount comes from the provider's own `available_balance`
     * rather than from any figure of ours. A payout that overdraws is refused
     * with `INSUFFICIENT_BALANCE`, and the fee is the provider's business to
     * charge.
     */
    private function createPayout(
        string $accountId,
        string $destinationId,
        int $amountKobo,
        string $reference,
        string $idempotencyKey,
    ): PayoutResult {
        $credentials = $this->platform->credentialsFor('bachs');

        try {
            $response = BachsApi::client($credentials, $accountId)
                ->withHeaders(['Idempotency-Key' => $idempotencyKey])
                ->post(BachsApi::baseUrl($credentials).'/payouts', [
                    'destination' => $destinationId,
                    'amount' => Naira::decimalFromKobo($amountKobo),
                    'reference' => $reference,
                ]);
        } catch (\Throwable $e) {
            Log::error('bachs.payout.request_failed', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return PayoutResult::failed('Bachs could not be reached for this payout.', $amountKobo);
        }

        $body = $response->json() ?? [];

        if (! $response->successful()) {
            return $this->payoutRefusal($response, $body, $accountId, $amountKobo);
        }

        $status = strtolower((string) ($body['status'] ?? 'pending'));

        return PayoutResult::paid(
            payoutId: isset($body['id']) ? (string) $body['id'] : null,
            amountKobo: $amountKobo,
            reference: $reference,
            providerStatus: $status,
            raw: $body,
        );
    }

    /**
     * A refused payout, in words the admin can act on.
     *
     * Each of these is a different problem with a different remedy, and the
     * provider's own code is the only thing that tells them apart — which is why
     * they are not collapsed into "the payout failed".
     *
     * @param  array<string, mixed>  $body
     */
    private function payoutRefusal(Response $response, array $body, string $accountId, int $amountKobo): PayoutResult
    {
        $code = strtoupper((string) ($body['code'] ?? ''));
        $message = (string) ($body['message'] ?? '');

        // 409 means the *same* request is still in flight — the idempotency key
        // did its job. Nothing has been paid twice.
        if ($response->status() === 409 || $code === 'IDEMPOTENCY_IN_PROGRESS') {
            return PayoutResult::skipped('An identical payout is already being processed.');
        }

        Log::warning('bachs.payout.refused', [
            'account_id' => $accountId,
            'code' => $code,
            'status' => $response->status(),
        ]);

        return PayoutResult::failed(match ($code) {
            // A negative balance in *any* currency blocks every payout on the
            // account — a dispute can do it. Only future settlements heal it,
            // and the console has to say so rather than showing a retry button
            // that cannot work.
            'ORGANIZATION_IN_DEBT' => 'This business\'s Bachs account is in debt — a refund or chargeback pushed it negative. Payouts resume as future sales settle.',
            'INSUFFICIENT_BALANCE' => 'The account balance does not cover this payout and its fee yet.',
            'DESTINATION_PENDING_REVIEW' => 'The registered bank account is still under review by Bachs.',
            'DESTINATION_REJECTED' => 'Bachs rejected the registered bank account. The business needs to register another one.',
            'DESTINATION_NOT_FOUND' => 'The registered bank account no longer exists at Bachs.',
            'PAYOUT_CURRENCY_NOT_ENABLED' => 'Bachs has not enabled naira payouts for this account.',
            'QUOTE_REQUIRED', 'QUOTE_EXPIRED' => 'This payout needs a currency quote, which is not supported yet.',
            'FORBIDDEN' => 'Bachs says this account cannot be paid out to yet.',
            default => $message !== '' ? $message : 'Bachs refused this payout.',
        }, $amountKobo);
    }

    /**
     * What every connected account could be paid right now.
     *
     * Read-only, and reads the provider for each one — which is the point: the
     * number the console or a `--dry-run` shows is the same `available_balance`
     * the sweep would pay, not a figure of ours that could disagree with it.
     *
     * `available` is null in two different situations, and the `destination` flag
     * is what tells them apart: no bank account registered yet, which no amount
     * of settling will fix, versus a balance the provider could not be asked for
     * just now, which the next run probably can.
     *
     * @return array<int, array{account_id: string, business_id: int, store_id: int|null, destination: bool, available: int|null}>
     */
    public function connectedAccountBalances(): array
    {
        $rows = [];

        foreach ($this->connections->all() as $connection) {
            $accountId = (string) ($connection->gateway_account_id ?? '');

            if ($accountId === '') {
                continue;
            }

            $destinationId = $this->destinationIdFrom($this->connections->config($connection));
            $balance = $destinationId === null ? null : $this->settledBalance($accountId);

            $rows[] = [
                'account_id' => $accountId,
                'business_id' => (int) $connection->business_id,
                'store_id' => $connection->source_table === 'store_payment_method'
                    ? (int) $connection->scope_id
                    : null,
                'destination' => $destinationId !== null,
                'available' => $balance['available'] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * The idempotency key for one attempt.
     *
     * Derived, not random, and that is the point: a retry after a crash — the
     * request that was sent but whose response was never read — carries the same
     * key and the provider recognises it rather than paying twice. It folds in
     * the account, the destination, the amount and the day, so the only requests
     * it can collide with are ones that would have moved the same money to the
     * same place — and a successful payout empties the balance, which stops the
     * next attempt before it starts.
     */
    private function idempotencyKey(string $accountId, string $destinationId, int $amountKobo): string
    {
        return 'payout_'.substr(hash('sha256', implode('|', [
            $accountId,
            $destinationId,
            (string) $amountKobo,
            now()->toDateString(),
        ])), 0, 40);
    }

    private function reference(): string
    {
        return 'PO_'.time().'_'.strtoupper(substr(md5(uniqid('', true)), 0, 8));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function pushHistory(array $config, array $entry): array
    {
        $history = is_array($config['payouts'] ?? null) ? $config['payouts'] : [];
        array_unshift($history, $entry);

        $config['payouts'] = array_slice($history, 0, self::HISTORY_LIMIT);

        return $config;
    }

    /**
     * Fold a webhook's outcome into the recorded attempt. Returns false when the
     * payout id is not one we recorded, which is how a delivery about something
     * else is told apart from one about us.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $changes
     */
    private function updateHistory(array &$config, string $payoutId, array $changes): bool
    {
        $history = is_array($config['payouts'] ?? null) ? $config['payouts'] : [];

        foreach ($history as $index => $entry) {
            if (! is_array($entry) || ($entry['id'] ?? null) !== $payoutId) {
                continue;
            }

            $history[$index] = array_merge($entry, $changes);
            $config['payouts'] = $history;

            return true;
        }

        return false;
    }

    /**
     * The stored masked destination, in the shape {@see ManagedAccount} reads.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private function destinationFrom(array $config): ?array
    {
        $managed = $config['managed'] ?? null;

        if (! is_array($managed)) {
            return null;
        }

        $destination = $managed['payout_destination'] ?? null;

        return is_array($destination) ? $destination : null;
    }

    /**
     * The `pd_…` a payout is addressed to, from the row's stored state alone.
     *
     * One definition, used both by the payout itself and by the read-only
     * balance report, because the two answering differently would mean a console
     * saying "nothing to pay" about an account the sweep pays every hour.
     *
     * @param  array<string, mixed>  $config
     */
    private function destinationIdFrom(array $config): ?string
    {
        $destination = $this->destinationFrom($config);
        $id = $destination['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }
}
