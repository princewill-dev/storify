<?php

namespace App\Services\Payments\Custody;

use App\Jobs\CheckPayoutStatus;
use App\Models\Business;
use App\Models\Payout;
use App\Models\PayoutHold;
use App\Models\Store;
use App\Models\StoreBank;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Payouts\BankDirectory;
use App\Services\Payments\Payouts\PayoutBankResolution;
use App\Services\Payments\Payouts\PayoutBankResolver;
use App\Services\Payments\Payouts\PayoutDestinationRef;
use App\Services\Payments\Payouts\PayoutLookupBreaker;
use App\Services\Payments\Payouts\PayoutLookupUnreachable;
use App\Services\Payments\Payouts\PayoutRail;
use App\Services\Payments\Payouts\PayoutRailRegistry;
use App\Services\Payments\Payouts\ProviderPayload;
use App\Services\Payments\Payouts\TransferOutcome;
use App\Services\Subscription\PlatformBillingGateway;
use App\Support\Payments\SystemProcessor;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Pays custody businesses out of the wallet, through the platform's own account.
 *
 * The rail that replaces "the provider settles each business directly". There is
 * no per-business account anywhere to address any more, so this asks the provider
 * to send an amount from **Storify's** balance to a bank code and an account
 * number, and the only things that have to be true are that the business is owed
 * the money, that it has somewhere to send it, and that the reserved amount comes
 * back if the transfer does not go.
 *
 * ## The reservation is the whole safety story
 *
 * Every attempt writes its `payouts` row and debits the wallet **before** the
 * provider is told anything. That ordering means the amount is out of
 * `available_balance` for as long as the outcome is unknown, so a second sweep
 * during a slow provider call cannot reserve the same money a second time. Three
 * outcomes then decide what happens to that reservation, and only one of them
 * releases it:
 *
 * | Provider said | Row | Reservation |
 * |---|---|---|
 * | took it | `processing` | kept |
 * | refused it | `failed` | released |
 * | nothing at all | `processing` | **kept** |
 *
 * The last row is the one that matters. An unreachable provider is not a refusal,
 * and a caller that released the reservation on a timeout would reserve, send,
 * time out, release, and send again — paying a business twice for one debt, with
 * nothing in the system recording that it happened. See {@see TransferOutcome}.
 *
 * ## A refusal is retried, and then it stops
 *
 * Releasing the reservation puts the money straight back in `available_balance`,
 * so a refusal needs no retry machinery to be tried again — the next attempt
 * simply finds the balance payable and reserves a fresh row. That is the whole
 * of the retry, and it is why there is no queue of retries anywhere: the wallet
 * *is* the queue.
 *
 * Left there it never stopped. A provider that was down, a balance that had not
 * settled, a refusal with a code nobody had seen — each was re-attempted on the
 * next five-minute run for as long as the condition lasted, with nothing counting
 * them and nobody told. Two ceilings now bound it, and they answer different
 * questions:
 *
 * | Ceiling | Counts | Answers |
 * |---|---|---|
 * | {@see self::destinationIsExhausted()} | refusals attributed to the account | "is this bank unusable" |
 * | {@see self::retriesExhausted()} | every refusal, since money last landed | "is sending to this business working at all" |
 *
 * Between the two, {@see self::backoffUntil()} spaces the attempts out on
 * `custody.retry_backoff_minutes` — shortest first, so a provider having a bad
 * minute is retried quickly and one having a bad afternoon is not hammered.
 *
 * What happens at either ceiling is the same, and it is deliberately *nothing*:
 * sends stop, the money stays in the wallet, `payoutBlocker()` says why on every
 * surface that asks, and a person decides. Sends resume by themselves when a send
 * lands — both counts are windows since the last `paid`, so success empties them —
 * or when an operator clears the record through {@see self::clearFailures()}.
 *
 * ## It pays the whole balance, and it cannot overdraw
 *
 * The amount is `available_balance`, which never includes a sale the provider has
 * not settled to us yet — that is what {@see CustodyService::promoteSettled()}
 * exists to keep true. `releaseForPayout()` refuses to overdraw, so a concurrent
 * refund that emptied the wallet while this was deciding turns into a skipped
 * business rather than a negative balance or an overpayment.
 *
 * ## And it knows which sales that balance was made of
 *
 * The amount sent is the sum of the sales {@see PayoutAllocationService} found,
 * capped by the balance — which in the ordinary case is the same number, and which
 * is the one case where the two disagree that makes the attribution worth having.
 * A refund taken after a payout leaves the wallet negative behind sales that still
 * look unallocated, and "we paid ₦50, which was part of that order" is an answer
 * the payout row alone cannot give.
 *
 * ## It is the only rail, and it is reached four ways
 *
 * `connect:payouts` used to run beside this, paying a business's connected
 * account out of the provider's own per-business balance. Both the account and
 * that command are gone, so this is now the whole of how money reaches a
 * business — which makes it worth naming the ways it is entered, because a row
 * that only one of them can finish is a row that strands:
 *
 * | Entered by | Finishes |
 * |---|---|
 * | {@see self::sweep()} — the manual console command | money that became payable since the last run |
 * | {@see self::sweepBusiness()} — {@see InstantPayoutService}, in the request that collects a sale | a sale that has just landed, so it does not wait for the next tick |
 * | {@see self::reconcile()} — the manual console command | a row whose send never returned, or whose webhook never arrived |
 * | {@see self::applyPayoutWebhook()} — the provider | a row whose outcome the provider reports directly |
 *
 * The four deliberately meet in the same private methods — `sweepBusiness()` is what
 * both the run and the instant path call, so the instant attempt is an extra *entry*
 * and not an extra rail. `settle()` claims its row by condition precisely because two
 * of them can be looking at it at the same moment, and the ledger keys are what make
 * the second arrival a no-op rather than a second posting.
 */
final class PayoutSweepService
{
    /**
     * The failures that mean *this bank account* is the problem.
     *
     * Both are ours, and both come from {@see self::destination()} rather than from
     * the provider's refusal of a transfer: they say the provider would not register
     * the destination at all, which is a statement about the account and cannot
     * become untrue by trying again. Deliberately not everything that can fail a
     * payout — a send the provider refused carries the provider's own code and
     * message, and `INSUFFICIENT_BALANCE` is the case that must keep being retried,
     * because it means our balance has not settled yet rather than that the
     * destination is unusable. A code nobody has seen before is not counted either,
     * which errs towards paying: an unrecognised refusal retries as it always did.
     *
     * @see config('custody.max_destination_failures')
     */
    private const DESTINATION_FAILURES = ['destination_unusable', 'destination_error'];

    /**
     * The blockers that mean sending has *stopped*, as opposed to *being held*.
     *
     * The distinction is who the problem belongs to, and it decides who gets told.
     * `destination_exhausted` and `retries_exhausted` are both the sweep giving up
     * after failures, which no business can see the cause of and no business can
     * fix alone — so they go to the operators. `hold`, `auto_payout_off`, `no_bank`
     * and `bank_incomplete` stop sending too, but they are a state the business (or
     * an operator acting on it) already put itself in, on purpose, and no attempt
     * has failed because of them; alerting on those would send an operator to a
     * page where there is nothing wrong.
     *
     * @see self::tellPlatformIfStalled()
     */
    public const STALLING_BLOCKERS = ['destination_exhausted', 'retries_exhausted'];

    /**
     * The blockers an operator's "try again" is allowed to act on.
     *
     * A superset of {@see self::STALLING_BLOCKERS}, and it has to be a separate
     * constant rather than the same one, because the two lists answer two different
     * questions. That one asks *"should a human be told about this business?"*, and
     * its answer is deliberately narrow. This asks *"is a send worth attempting
     * again?"*, and the answer has to include a blocker the other one has never
     * heard of: {@see PlatformPayoutNotifier::SETTLEMENT_FAILED}, which the queued
     * job raises when the provider was never reached and no ceiling is in force at
     * all.
     *
     * They were one list until it mattered. Filtering the batch retry on
     * `STALLING_BLOCKERS` meant the retry-all button skipped exactly the businesses
     * the job's alert had just named — an email saying "tell somebody" beside a
     * button that ignored them.
     *
     * Holds, a switched-off `auto_payout` and a missing bank stay out of both: those
     * are states a person put the business in, no attempt failed because of them, and
     * retrying would be a provider call that could not possibly send.
     */
    public const RETRYABLE_BLOCKERS = ['destination_exhausted', 'retries_exhausted', 'settlement_failed'];

    /**
     * Provider statuses that mean the transfer landed.
     *
     * Deliberately generous across providers rather than per-rail, because the
     * alternative is a rail-specific map that a new rail silently omits — and the
     * failure mode of an unrecognised success is a payout that stays `processing`
     * forever while the business has the money, which is the direction worth
     * erring in. The opposite mistake, treating an in-flight status as landed,
     * would post to the ledger early and is not possible here: anything not on
     * this list stays unresolved.
     */
    private const PAID_STATUSES = ['completed', 'complete', 'paid', 'success', 'successful', 'settled'];

    /**
     * Provider statuses that mean the money came back to us.
     *
     * Not a failure of the send — the transfer landed and was then recalled or
     * reversed. The business no longer has the money, so the wallet has to be
     * credited back and the books unwound, or Storify would be carrying a payout
     * it never made.
     */
    private const REVERSED_STATUSES = ['reversed', 'reversal', 'returned', 'recalled'];

    public function __construct(
        private readonly CustodyService $wallet,
        private readonly LedgerPostingService $ledger,
        private readonly PlatformBillingGateway $billing,
        private readonly PayoutAllocationService $allocations,
        private readonly PayoutHoldService $holds,
        private readonly CustodyNotifier $notifier,
        private readonly PlatformPayoutNotifier $platform,
        private readonly PayoutBankResolver $payoutBanks,
        private readonly BankDirectory $directory,
        private readonly PayoutLookupBreaker $breaker,
    ) {}

    /**
     * Who the money is paid through, and the credentials for it.
     *
     * The platform's *selected* processor, not a constant: it is the processor
     * every business's customers are charged through, so it is the one whose
     * balance the money is actually sitting in. Resolved per call rather than in
     * the constructor because an admin can switch it while a worker is alive, and
     * a memo would send the next payout through the provider that was selected
     * when the process started.
     *
     * Null when nothing is selected, or when what is selected cannot pay out. Both
     * are refusals rather than fallbacks — see {@see PayoutRailRegistry}.
     *
     * @return array{provider: string, rail: PayoutRail, credentials: GatewayCredentials}|null
     */
    /**
     * The currency the wallet and this rail are denominated in.
     *
     * Read from config rather than held as a constant here, so the one place that
     * decides it is also the one `PaymentGatewayResolver::custodyCanHold()` and
     * `CustodyService::recordCollectedSale()` read. A charge the wallet cannot
     * represent has to be refused by all three of them or by none: a constant here
     * beside a config key there is two answers to one question, and the one that
     * drifts is the one nobody is looking at.
     */
    private function currency(): string
    {
        return (string) config('custody.currency', 'NGN');
    }

    private function context(): ?array
    {
        $provider = SystemProcessor::code();

        if ($provider === null || ! PayoutRailRegistry::has($provider)) {
            return null;
        }

        return [
            'provider' => $provider,
            'rail' => PayoutRailRegistry::make($provider),
            'credentials' => $this->billing->credentialsFor($provider),
        ];
    }

    /**
     * Why payouts cannot run right now, or null when they can.
     *
     * Answered separately from {@see context()} so the command can refuse before
     * it does anything and say why, rather than iterating a candidate list that
     * cannot be paid.
     */
    public function unavailableReason(): ?string
    {
        $selected = SystemProcessor::stored();

        if ($selected === null) {
            return 'No system payment processor is selected, so no provider is holding collected funds.';
        }

        $provider = SystemProcessor::code();

        if ($provider === null) {
            return sprintf('The selected processor "%s" cannot be run by this build.', $selected);
        }

        if (! PayoutRailRegistry::has($provider)) {
            return sprintf(
                'The selected processor "%s" has no payout rail. Payouts can be sent through: %s.',
                $provider,
                implode(', ', PayoutRailRegistry::codes()),
            );
        }

        return $this->billing->credentialProblem($provider);
    }

    /**
     * Why this business would not be paid right now, or null when it would.
     *
     * The sweep's own precedence, expressed once. The order matters and is the
     * order {@see self::sweep()} applies: a hold first, because a decision a person
     * made about this business is the answer to the question whatever else is also
     * true of it; then the business's own preference; then the destination.
     *
     * It lives here rather than in the console so the run's report, the
     * `--dry-run` table and the business's own wallet page all answer "why is my
     * money not moving" with the same sentence. A second copy of this reasoning is
     * most wrong in exactly the case somebody is reading it: a business with a hold
     * *and* a dead bank, where only one of the two is the reason it is not being paid.
     *
     * `label` is the wording the console prints; `code` is what an API client
     * switches on, so a client never has to match on prose.
     *
     * @return array{code: string, label: string}|null
     */
    public function payoutBlocker(Business $business): ?array
    {
        $hold = $this->holds->holdOn($business);

        if ($hold !== null) {
            return [
                'code' => 'hold',
                'label' => sprintf('on hold: %s (%s)', $hold->reason, $hold->source),
            ];
        }

        if (! $business->auto_payout) {
            return ['code' => 'auto_payout_off', 'label' => 'held: automatic payouts off'];
        }

        $bank = $business->payoutBank;

        if (! $bank) {
            return ['code' => 'no_bank', 'label' => 'no payout bank'];
        }

        // Both halves, because the sweep requires both and a bank row with a code
        // and no account number would otherwise be reported as payable by every
        // surface that asked this and refused by the only one that sends.
        if (blank($bank->bank_code) || blank($bank->account_number)) {
            return ['code' => 'bank_incomplete', 'label' => 'payout bank has no bank code'];
        }

        if ($this->destinationIsExhausted($business)) {
            return ['code' => 'destination_exhausted', 'label' => 'provider keeps refusing this bank'];
        }

        // After the destination ceiling, because when both have tripped the
        // destination is the more useful thing to be told: it names something the
        // business can change, where this one is a platform problem it cannot.
        //
        // Checked here rather than only in the sweep, so that every surface reading
        // this figure already agrees: the wallet, the dry run, the console and the
        // sweep all ask this one method what is stopping a payment.
        if ($this->retriesExhausted($business)) {
            return ['code' => 'retries_exhausted', 'label' => 'we could not send your last payouts'];
        }

        return null;
    }

    /**
     * What the provider currently has for the platform, in kobo — or null.
     *
     * The measurement the whole promotion gate rests on, exposed on its own because
     * the console has to be able to say *why* nothing was promoted, and "the
     * provider could not be asked" is a different sentence from "the provider says
     * we hold nothing".
     *
     * Null covers every way of not knowing: no processor selected, a rail with no
     * balance call, a provider that refused or could not be reached. The caller
     * treats all of them the same way, which is right — none is a statement about
     * money.
     */
    public function providerAvailableKobo(): ?int
    {
        $context = $this->context();

        if ($context === null) {
            return null;
        }

        return $context['rail']->availableBalance($context['credentials']);
    }

    /**
     * How much pending money this run may mature, and the floor to fall back to.
     *
     * Returned as one array rather than two calls, because both answers are read
     * from the same provider response and a caller that asked twice could be given
     * a budget from one read and a floor from another.
     *
     * **The budget** is what the provider says it holds, less everything already
     * promised out of it:
     *
     *   provider spendable
     *   − every business's `available_balance`   (already promoted, so the next
     *                                              sweep will send it)
     *   − payouts reserved but unresolved        (already left the wallet, still
     *                                              sitting in the provider's
     *                                              balance until they land)
     *   − a fee headroom                          (Bachs adds its payout fee *on
     *                                              top* of the amount sent, so the
     *                                              platform must never be gated
     *                                              down to exactly the figure it
     *                                              means to send)
     *
     * The subtraction is signed on purpose: a refund after a payout leaves a
     * business's available bucket negative, and that deficit genuinely reduces what
     * the platform can afford to mature.
     *
     * **The floor** exists for the one case the budget cannot cover. With the
     * settlement window cut to nothing, an unreadable balance would otherwise leave
     * the window as no gate at all and promote everything instantly. So an
     * unmeasurable provider falls back to `custody.default_hours`: the gate fails
     * open, into the *conservative* path rather than into no gate. When the balance
     * is readable the floor is null, because the measurement is better than any
     * clock.
     *
     * **`components` is the same subtraction, itemised**, and is null whenever the
     * gate could not measure at all. It travels with the budget because the budget
     * alone cannot be interpreted: a zero has four quite different causes — a
     * provider that has not released the customer's charge, another business's
     * money stranded in `available_balance`, transfers already in flight, or the
     * fee headroom — and every one of them looks the same from the send, which
     * finds nothing payable and reports having sent nothing. The readers that have
     * to tell them apart are the job deciding whether to retry and the operator
     * reading the alert; both would otherwise be reconstructing these four sums by
     * hand, against live provider state, in the moment they least can.
     *
     * @return array{budget: int|null, floor: CarbonInterface|null, measured: bool, components: array{available: int, liability: int, in_flight: int, headroom: int}|null}
     */
    public function promotionGate(): array
    {
        if (! (bool) config('custody.balance_gate.enabled', true)) {
            return ['budget' => null, 'floor' => null, 'measured' => false, 'components' => null];
        }

        $context = $this->context();

        if ($context === null) {
            // No processor is selected, or the one selected cannot pay out, so
            // there is no payout for this gate to protect — the sweep would refuse
            // before it sent anything. Applying the floor here would only delay a
            // business's balance from maturing over a payout that could not happen
            // anyway, and make a misconfigured processor look like slow settlement.
            return ['budget' => null, 'floor' => null, 'measured' => false, 'components' => null];
        }

        $available = $context['rail']->availableBalance($context['credentials']);

        if ($available === null) {
            $floor = now()->subHours((int) config('custody.default_hours', 72));

            Log::warning('custody.promote.balance_unreadable', [
                'provider' => $context['provider'],
                'floor' => $floor->toIso8601String(),
            ]);

            return ['budget' => null, 'floor' => $floor, 'measured' => false, 'components' => null];
        }

        // Everything the platform owes, across both kinds of bucket.
        //
        // With per-store wallets on, a business's payable money sits on its
        // stores and the business column holds only what no store accounts for.
        // Counting the business column alone would understate the liability, let
        // the gate mature more than the provider is actually holding, and overdraw
        // the platform's own balance — the precise failure this gate exists to
        // prevent, arrived at by one missing SUM rather than by a wrong decision.
        //
        // Summed unconditionally rather than behind the flag: with it off every
        // store column is zero, so the figure is exactly what it was, and a
        // conditional here would be one more place the flag has to be right.
        $liability = (int) Business::query()->sum('available_balance')
            + (int) Store::query()->sum('available_balance');

        // Reserved and still in flight: the money has left the wallet but has not
        // yet left the provider's balance, so it is spoken for twice if this does
        // not subtract it. Platform-wide here, because the number being protected
        // is the platform's own balance.
        $inFlight = (int) $this->inFlightQuery()->sum('amount_kobo');

        $headroom = (int) config('custody.payout_fee_headroom_kobo', 0);

        return [
            'budget' => max(0, $available - $liability - $inFlight - $headroom),
            'floor' => null,
            'measured' => true,

            // The terms of the subtraction, carried out with the answer.
            //
            // A zero budget has several different meanings — the provider has not
            // released the customer's money yet, another business's stranded
            // balance is sitting on it, transfers already in flight account for it,
            // or the headroom absorbs it — and every one of them reads identically
            // from the outside: a sale that stayed in `pending_balance`. Without
            // these figures the only way to tell them apart is to reproduce the
            // arithmetic by hand against live provider state, which is exactly what
            // an operator cannot do at the moment they need to. They are returned
            // rather than logged here because the gate is read on every settlement
            // while only some callers want to say why they came away with nothing.
            'components' => [
                'available' => $available,
                'liability' => $liability,
                'in_flight' => $inFlight,
                'headroom' => $headroom,
            ],
        ];
    }

    /**
     * How much more the provider would have to be holding to cover everything owed.
     *
     * **The reconciliation number.** A positive answer means the wallets record money
     * owed to businesses that the provider's balance does not contain — the platform's
     * books and the provider's disagree, and no amount of waiting changes that. Every
     * business on the platform is gated off behind it, including businesses whose own
     * provider settled perfectly well, so it is not a payment problem and pressing
     * Retry will not touch it.
     *
     * It reads `liability` alone and **not** the four-term budget, and the omission of
     * `headroom` is the whole care in this method. The budget nets off the fee reserve
     * because the platform must not spend down to a figure that leaves nothing for the
     * fee — but the fee reserve is not a debt to anybody. Folding it in here would
     * report a provider holding exactly what it owes, and nothing more, as a platform
     * that is short: a much more alarming claim than the facts support, made on the
     * number an operator would then go and reconcile against.
     *
     * Returns zero when the gate could not measure at all. An unreadable provider is
     * an unknown rather than a shortfall, and every caller here checks `measured`
     * first for exactly that reason.
     *
     * @param  array<string, mixed>  $gate  {@see self::promotionGate()}'s answer
     */
    public function gateShortfallKobo(array $gate): int
    {
        $components = is_array($gate['components'] ?? null) ? $gate['components'] : null;

        if ($components === null) {
            return 0;
        }

        return max(
            0,
            (int) ($components['liability'] ?? 0) - (int) ($components['available'] ?? 0),
        );
    }

    /**
     * Whether the provider's balance could fund a payout at all right now.
     *
     * The question a caller asks before deciding that "nothing matured" means
     * *wait*. There are two ways a budget comes back empty and they need opposite
     * responses: the provider holds the money and it simply has not been released
     * into this sale yet, which clears itself; or the provider does not hold it,
     * which never clears itself.
     *
     * Deliberately not "is the shortfall zero", because a third state passes that
     * test and is not benign either: a provider holding **exactly** what it owes, and
     * not a naira more, cannot send anything — the fee is charged on top, so a
     * transfer drawn against a balance that is already fully committed is refused for
     * insufficient funds. It is not a reconciliation problem, but it is not a wait
     * either, and an operator should hear about it.
     *
     * So the test is the budget itself, plus one escape: **a platform that owes
     * nothing is not short of anything**, however little the provider is holding. That
     * is the everyday shape of a settlement lag on a quiet day — the charge is on its
     * way, no wallet claims it yet, and the right answer is to say nothing and look
     * again later.
     *
     * @param  array<string, mixed>  $gate  {@see self::promotionGate()}'s answer
     */
    public function gateCanFundPayout(array $gate): bool
    {
        $components = is_array($gate['components'] ?? null) ? $gate['components'] : null;

        if ($components === null) {
            // Unmeasurable, which is the caller's to interpret — see `measured`. Not
            // a statement about money either way.
            return false;
        }

        if ((int) ($components['liability'] ?? 0) <= 0) {
            return true;
        }

        return (int) ($gate['budget'] ?? 0) > 0;
    }

    /**
     * Why a settlement produced no transfer, named precisely enough to act on.
     *
     * Lives here, beside {@see self::promotionGate()} whose four figures it reads,
     * because two very different readers ask it the same question: the queued job,
     * which puts the answer in the operator's alert, and the instant payout, which
     * puts it on the sale's own log line. Both are looking at the same stall and
     * both would otherwise be re-deriving a cause from the same four numbers, one
     * of them getting it subtly wrong.
     *
     * **Not one reason but five, and they call for opposite things.** A provider
     * that has not released a customer's money yet resolves itself; a refusal at the
     * bank needs a person; a provider whose balance is short of what the platform
     * owes across every business will not move however many times anything comes
     * back, and is a reconciliation problem rather than a payment one. An operator
     * deciding whether to press Retry now or to wait is choosing between those, and
     * this is the only place that knows which they are looking at.
     *
     * The strings land inside `PayoutNotSentException`'s message and in the admin
     * alert, so each is a clause rather than a sentence: lowercase, no full stop.
     *
     * @param  array<string, mixed>  $summary  the wallet read model, as the caller read it
     * @param  array<string, mixed>  $gate  `promotionGate()`'s answer, empty when absent
     */
    public function stallReason(bool $refused, array $summary, array $gate): string
    {
        if ($refused) {
            return 'the provider refused the transfer';
        }

        // Payable and unsent: the walk found money it was willing to send and no
        // transfer came of it — a destination the bank refused, or a provider that
        // could not be reached. Different from everything below, which is about
        // money that never became payable in the first place.
        if ((int) ($summary['payable_kobo'] ?? 0) > 0) {
            return 'money was payable to this business and the send did not move it';
        }

        // Nothing payable, so the sale is still in `pending_balance`: it was due to
        // mature and did not. From here on the gate's own terms are the answer.
        if (($gate['measured'] ?? false) !== true) {
            return 'the provider\'s balance could not be read, so a fresh sale is inside the conservative settlement window';
        }

        $components = is_array($gate['components'] ?? null) ? $gate['components'] : [];
        $available = (int) ($components['available'] ?? 0);
        $liability = (int) ($components['liability'] ?? 0);
        $shortfall = $this->gateShortfallKobo($gate);

        // Nothing owed anywhere: no wallet claims any part of the provider's
        // balance, so a sale that did not mature is simply a charge that has not
        // been released yet. The ordinary wait, on a platform where nothing is
        // wrong, and the commonest way to arrive here.
        //
        // Asked **first**, and it has to be, for two reasons. On a quiet platform
        // this is where every zero-budget sale lands, and any check ordered above it
        // would report the fee reserve as a shortfall — alarming an operator about
        // the headroom on the most ordinary day the platform has. And it is asked on
        // `liability` alone rather than on both figures, because a provider holding
        // money that no wallet claims is the same answer: there is money, and none
        // of it is this sale's yet.
        if ($liability <= 0) {
            return 'the provider is not holding a released balance for these sales yet';
        }

        // The provider is holding less than the wallets say is owed. Named with all
        // three figures, because this is the one stall of the five that the reader
        // cannot act on from the sentence alone: it needs reconciling, and the size
        // of the gap is where that starts.
        if ($shortfall > 0) {
            return sprintf(
                'the provider holds %s but the platform owes %s across all businesses, %s short',
                $this->money($available),
                $this->money($liability),
                $this->money($shortfall),
            );
        }

        // Holding money, solvent, and the budget still came back empty — so what the
        // provider holds is already committed: to the wallets this very subtraction
        // just counted, to transfers in flight, or to the fee reserve. Nothing is
        // missing, but nothing is free either, and the money this sale is waiting for
        // will not appear until something else moves.
        return sprintf(
            'the provider\'s balance is already spoken for — %s held, %s owed, the rest reserved for transfers and fees',
            $this->money($available),
            $this->money($liability),
        );
    }

    /** Kobo as a bare naira figure, for prose the operator reads. */
    private function money(int $kobo): string
    {
        return 'NGN '.number_format($kobo / 100, 2);
    }

    /**
     * The last four digits of an account number, or null when there are none.
     *
     * Enough to let a business and an operator agree they are looking at the same
     * transfer, and not enough to be worth keeping out of a log file. The full
     * number is on the payout row already; this is the copy that is allowed to
     * travel.
     */
    private function lastFour(?string $accountNumber): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $accountNumber);

        if (! is_string($digits) || strlen($digits) < 4) {
            return null;
        }

        return substr($digits, -4);
    }

    /**
     * Transfers that have left the wallet and have been neither confirmed nor refused.
     *
     * `pending` is a row reserved but not yet answered for; `processing` is one the
     * provider accepted, or one it never answered at all. Both mean the same thing to
     * every reader: the amount is out of `available_balance` and is not yet known to
     * have arrived, so nobody may spend it again.
     *
     * A public predicate rather than the private sum this used to be, because there
     * are two readers with two scopes. {@see promotionGate()} counts every business
     * at once, to price what the platform can still afford to mature. The wallet
     * wants one business's transfers, to say that money is moving — and a private
     * sum inside the gate left the wallet screen unable to say that at all, showing
     * ₦0 payable with nothing about the ₦X already on its way.
     *
     * `failed` and `cancelled` are outside this on purpose: both credit the wallet
     * back, so the money is spendable again, and counting them would subtract it
     * from `payable` a second time.
     */
    public function inFlightQuery(): Builder
    {
        return Payout::query()
            ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_PROCESSING]);
    }

    /**
     * One business's in-flight transfers, oldest first.
     *
     * Oldest first because the caller's question is usually "how long has this been
     * moving" — the wallet reads the first row's `requested_at` to decide whether a
     * transfer has gone unconfirmed long enough to stop calling it normal — and a
     * newest-first order would answer with the one row guaranteed to look young.
     *
     * Ordered by `id` as well as by `requested_at`: two transfers requested by the
     * same instant would otherwise come back in whatever order the engine chose, and
     * the wallet's "since" would move between reads.
     *
     * @return Collection<int, Payout>
     */
    public function inFlightFor(Business $business): Collection
    {
        return $this->inFlightQuery()
            ->where('business_id', $business->id)
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Businesses holding money this platform owes, payable or not.
     *
     * "Owing" is both buckets, and the `pending_balance` half is not an
     * afterthought. A sale lands in `pending_balance` and matures to
     * `available_balance` when the promotion gate lets it — which, with the
     * provider readable but not yet holding the customer's money, it does not. So a
     * business can be owed money the platform is holding, and be owed it *for hours*,
     * while `available_balance` reads zero.
     *
     * Leaving those out of this list was a real fault rather than a tidiness
     * problem, and it showed up exactly where it hurt. This is a business whose money
     * is stuck, whose provider is fine, whose bank is fine, and who has no reason on
     * earth to know any of that — and it appeared absolutely nowhere an operator
     * looks: not on the queue, and not in `custody:payouts --dry-run`, which printed
     * "No custody business is holding a payable balance." while the platform was
     * holding that business's money. The only trace was a log line nobody had cause
     * to read, because nothing had failed.
     *
     * Not filtered on `auto_payout` or on having a bank, deliberately: the sweep
     * has to be able to *say* that a business is holding money it cannot receive,
     * and a query that silently excluded it would make "we are holding ₦40,000 for
     * a business with no bank account" invisible until someone complained.
     *
     * **Not filtered on `custody_enabled` either, and that one used to be.** The
     * flag decides whether a business is *charged*, so it is a fact about the
     * future; a balance is money already collected into Storify's own provider
     * balance, which is a fact about the past. `custody:disable` is available at any
     * moment and exists to be reached for in a hurry, so filtering here meant the one
     * command an operator runs during an incident also silently stopped every payout
     * that business was owed — and printed a warning telling them to pay it by hand,
     * with no tool left to pay it. Both balances are only ever raised by
     * {@see CustodyService::promoteSettled()} and
     * {@see InstantPayoutService::settle()}'s promote step, which only ever credit a
     * sale Storify collected, so a balance is proof of collection on its own.
     *
     * @return Collection<int, Business>
     */
    public function candidates(): Collection
    {
        // A business with money on one of its stores but nothing on itself is
        // just as payable as one holding the whole balance, and with per-store
        // wallets on that is the ordinary case. Asking only about the business
        // column made every such business invisible to the run: the stores held
        // the money and nothing ever looked at them.
        return Business::query()
            ->where(fn ($query) => $query
                ->where('available_balance', '>', 0)
                ->orWhere('pending_balance', '>', 0)
                ->orWhereHas('stores', fn ($stores) => $stores
                    ->where('available_balance', '>', 0)
                    ->orWhere('pending_balance', '>', 0)))
            ->orderBy('id')
            ->limit((int) config('custody.payout_batch', 100))
            ->get();
    }

    /**
     * Whether this business has been refused on the bank it still names, too many
     * times to keep asking.
     *
     * A destination the provider will not accept is not made acceptable by being
     * offered again, so retrying it every hour forever buys nothing: it spends
     * provider calls, fills the log with a failure that is not news, and — the
     * part that actually costs something — leaves a business that needs a person
     * looking at it indistinguishable from a healthy one that is simply between
     * payouts. The money stays in the wallet, so nothing is lost by waiting.
     *
     * Counted per destination rather than per business, and since the last payout
     * that landed: naming a different bank therefore clears it by itself, because
     * the rows being counted name the old one. It is deliberately not about
     * *attempts* — a run that never reached the provider is not evidence about the
     * account, which is why `destination_error` (the provider unreachable) is the
     * only failure beside `destination_unusable` that counts, and why a send
     * refused by the provider with a code of its own does not.
     */
    public function destinationIsExhausted(Business $business, ?StoreBank $bank = null): bool
    {
        $ceiling = $this->maxDestinationFailures();
        $bank ??= $business->payoutBank;

        if ($ceiling <= 0 || $bank === null) {
            return false;
        }

        return $this->destinationFailureQuery($business, $bank)->count() >= $ceiling;
    }

    /**
     * How many times the provider has refused the bank this business names now,
     * since money last reached it.
     *
     * Public so the console can say *how far* past the ceiling a business is,
     * rather than only that it is past it.
     */
    public function destinationFailures(Business $business, ?StoreBank $bank = null): int
    {
        $bank ??= $business->payoutBank;

        if ($bank === null) {
            return 0;
        }

        return $this->destinationFailureQuery($business, $bank)->count();
    }

    /**
     * Lift the ceiling for a business whose destination a person has vouched for.
     *
     * The ceiling exists to stop the sweep asking forever; it is not a decision
     * that the account is unusable, and only a person can tell the two apart. So
     * the refusals it counted are retired — marked `cancelled`, the status for a
     * row that moved no money and will not be attempted again as it stands — and
     * the next attempt sizes the destination up again from nothing.
     *
     * Cancelling the *rows* rather than storing a "cleared" flag is what keeps the
     * recovery honest: the count is a count of rows, so clearing it has to mean
     * the rows no longer say what they said. It also means the ceiling cannot be
     * lifted for a business by cancelling one row while two more keep it over —
     * the whole set goes or none of it does, because they are cleared by the same
     * predicate that counted them.
     *
     * The money was never touched: a `failed` payout has already had its
     * reservation released, so there is nothing to restore and nothing to re-send.
     * What resumes is the *next* attempt, which sees a destination with no failures
     * against it.
     *
     * @return int the number of rows retired
     */
    public function clearDestinationFailures(Business $business): int
    {
        $bank = $business->payoutBank;

        if ($bank === null) {
            return 0;
        }

        $cleared = 0;

        foreach ($this->destinationFailureQuery($business, $bank)->get() as $payout) {
            // Claimed by condition, like every other transition on this table: a
            // row that stopped being `failed` between the read and the write — a
            // late webhook settling it — is left exactly as it now is.
            $claimed = Payout::query()
                ->whereKey($payout->id)
                ->where('status', Payout::STATUS_FAILED)
                ->update(['status' => Payout::STATUS_CANCELLED]);

            if ($claimed === 0) {
                continue;
            }

            $cleared++;

            // Through the model rather than the update above, because `meta` is
            // cast and the query builder would write a hand-encoded string.
            $payout->refresh();
            $payout->forceFill([
                'meta' => array_merge($payout->meta ?? [], [
                    'cancelled_reason' => 'destination_retry_authorised',
                    'cancelled_at' => now()->toIso8601String(),
                ]),
            ])->save();

            Log::warning('custody.payout.destination_clear_authorised', [
                'payout_id' => $payout->id,
                'business_id' => $business->id,
                'bank_code' => (string) $payout->bank_code,
                'failure_code' => $payout->failure_code,
            ]);
        }

        return $cleared;
    }

    /**
     * How many sends in a row this business has had refused, for any reason.
     *
     * The sibling of {@see self::destinationFailures()}, and the difference between
     * them is the question each answers. That one asks "is this bank account the
     * problem", and counts only the refusals the provider attributed to the
     * account. This one asks "is sending to this business working at all", and
     * counts every refusal — an outage, an unreadable balance, a code nobody has
     * seen before.
     *
     * Every one of those used to be re-attempted on the next run with nothing
     * counting them, so a business behind a provider outage grew a new `failed` row
     * every five minutes for as long as the outage lasted. Nothing measured it and
     * nobody was told, which is the failure this exists to make countable.
     *
     * Scoped by *business* rather than by destination on purpose: a provider that is
     * down is not a fact about anybody's bank account, and charging it to the
     * account would stop the transfer to a destination that is perfectly good.
     *
     * `since the last payout that landed` is what makes the count self-clearing. A
     * send that succeeds empties the window, so a business that fails twice and then
     * succeeds is back to zero with nothing having to reset it.
     */
    public function consecutiveFailures(Business $business): int
    {
        return $this->failureQuery($business)->count();
    }

    /**
     * Whether this business has failed enough times in a row to stop being tried.
     *
     * Reaching this is not a verdict on the business or its bank: it means the
     * platform has asked three times and not been answered, and asking a fourth
     * automatically is how an outage turns into a retry storm. Sends resume when a
     * send lands, or when an operator says the destination and the provider are both
     * worth another go — see {@see self::clearFailures()}.
     */
    public function retriesExhausted(Business $business): bool
    {
        $ceiling = $this->retryAttempts();

        if ($ceiling <= 0) {
            return false;
        }

        return $this->consecutiveFailures($business) >= $ceiling;
    }

    /**
     * When the next attempt at this business is due, or null if one is due now.
     *
     * Returned as a moment rather than as a boolean because both callers want the
     * moment: the sweep asks whether it has arrived, and a console saying "next try
     * at 14:20" is a better sentence than "not yet". Null covers every case where
     * there is nothing to wait for — no failures recorded, a disabled backoff, or a
     * failure count past the end of a schedule that only means to pace the first few.
     *
     * Null is *not* "may be sent": a business that has exhausted its retries is
     * gated by {@see self::payoutBlocker()}, which outranks this. The two are read
     * together — the backoff paces the attempts that are still allowed, and the
     * ceiling decides whether there are any.
     */
    public function backoffUntil(Business $business): ?CarbonInterface
    {
        $failures = $this->consecutiveFailures($business);

        if ($failures <= 0) {
            return null;
        }

        $schedule = $this->retryBackoffMinutes();

        if ($schedule === []) {
            return null;
        }

        // Read through the model rather than as a raw `max()`, so `failed_at` comes
        // back as a date the way every other reader of that column gets it.
        $lastFailedAt = $this->failureQuery($business)->latest('failed_at')->value('failed_at');

        if (! $lastFailedAt instanceof CarbonInterface) {
            return null;
        }

        // The gap belongs to the attempt that is about to be made, so the first
        // refusal is followed by the shortest wait and each further one by a longer.
        // Past the end of the list the last gap repeats: a schedule for the first
        // few attempts, not a bound on them.
        $delay = $schedule[min($failures, count($schedule)) - 1];

        return $lastFailedAt->addMinutes($delay);
    }

    /** Whether this business's next attempt is still in the future. */
    public function awaitingBackoff(Business $business): bool
    {
        $until = $this->backoffUntil($business);

        return $until !== null && now()->lt($until);
    }

    /**
     * Retire every refusal on this business's record, whatever caused it.
     *
     * The operator's lever, and the reason it is wider than
     * {@see self::clearDestinationFailures()}: that one is a judgement about a bank
     * account and clears only the rows that blame one. This one is a judgement about
     * a *provider incident* — the destination was fine, the platform was not — and
     * so has to clear the refusals that named no account at all, which are exactly
     * the ones with no other way to be cleared.
     *
     * Both ceilings read the same rows through different filters, so clearing them
     * here resets both at once. That is the point of retiring rows rather than
     * storing a "cleared" flag, and it is the same reasoning as the destination
     * version: a count of rows can only be honestly reset by making the rows stop
     * saying what they said.
     *
     * No money moves. A `failed` payout has already had its reservation released, so
     * the balance is already whole; what resumes is the next attempt, which is a
     * separate decision the caller makes.
     *
     * @return int the number of rows retired
     */
    public function clearFailures(Business $business): int
    {
        $cleared = 0;

        foreach ($this->failureQuery($business)->get() as $payout) {
            // Claimed by condition, like every other transition on this table: a row
            // that stopped being `failed` between the read and the write — a late
            // webhook settling it — is left exactly as it now is.
            $claimed = Payout::query()
                ->whereKey($payout->id)
                ->where('status', Payout::STATUS_FAILED)
                ->update(['status' => Payout::STATUS_CANCELLED]);

            if ($claimed === 0) {
                continue;
            }

            $cleared++;

            // Through the model rather than the update above, because `meta` is cast
            // and the query builder would write a hand-encoded string.
            $payout->refresh();
            $payout->forceFill([
                'meta' => array_merge($payout->meta ?? [], [
                    'cancelled_reason' => 'payout_retry_authorised',
                    'cancelled_at' => now()->toIso8601String(),
                ]),
            ])->save();

            Log::warning('custody.payout.failures_cleared', [
                'payout_id' => $payout->id,
                'business_id' => $business->id,
                'failure_code' => $payout->failure_code,
            ]);
        }

        return $cleared;
    }

    /**
     * Close out every payout that has not finished, without sending anything.
     *
     * ## Why this exists
     *
     * A payout row is a claim on money: the reservation is taken off the wallet and
     * the sales behind it are committed, and nothing but a terminal status gives
     * either of them back. So a row left open is money the business is owed, sitting
     * outside the balance they can see, holding the promotion budget down and making
     * whichever business owns it look blocked — for as long as it is open.
     *
     * The rows this is for are the ones written while the payout path was broken,
     * plus the ones a sweep that no longer runs would have carried. They are not
     * going to be sent and never were; the only thing left to decide about them is
     * how they end.
     *
     * This is a one-time clear-up and must never become a payout path: it sends
     * nothing, asks no provider anything, and its whole job is to put the rows it
     * finds into a terminal state honestly.
     *
     * ## What each status means here
     *
     * - **`pending`** — reserved and never handed to a provider. The money is
     *   certainly still ours, so it is credited back and the sales are released:
     *   byte for byte the treatment {@see self::fail()} gives a refusal, without the
     *   notification, because a business is told a payout failed and this is not
     *   that.
     * - **`failed`** — already had its reservation released by {@see self::fail()},
     *   so no money moves and only the row lingers. Closed for exactly the reason
     *   {@see self::clearFailures()} closes one: so the retry ceiling, the backoff
     *   and the admin alert stop counting it as something that happened.
     * - **`processing`** — **left alone unless asked for.** It *was* handed to a
     *   provider, and the provider has not said what became of it. Crediting the
     *   money back is releasing a reservation on a transfer that may still land,
     *   which pays the business twice and is worse than leaving the row open. Its
     *   real routes out are the provider's webhook and {@see self::reconcile()}.
     *   `$includeProcessing` overrides this for an operator who has confirmed with
     *   the provider that the transfer is gone.
     *
     * ## The quiet window, and the race it is for
     *
     * {@see self::dispatch()} does not claim a row before it calls the provider: it
     * stamps `sent_at` and then makes the request. So a `pending` row can be
     * mid-send while this runs — and closing one of those credits its money back
     * *and* lets the transfer land, which pays the business twice.
     *
     * `$quiescentMinutes` excludes any row touched inside that many minutes, which
     * is the cheapest way to not step on a worker that is sending right now: a row
     * being dispatched has just had `sent_at` written. Zero disables the guard, for
     * a caller that knows nothing is running — the tests do.
     *
     * @param  Business|null  $business  limit to one business, or null for all
     * @param  bool  $includeProcessing  also close rows already handed to a provider
     * @param  int  $quiescentMinutes  skip rows touched more recently than this
     * @return array{closed: int, restored_kobo: int, by_status: array<string, int>}
     */
    public function closeOutstanding(
        ?Business $business = null,
        bool $includeProcessing = false,
        int $quiescentMinutes = 0,
    ): array {
        $closed = 0;
        $restoredKobo = 0;
        $byStatus = [];

        foreach ($this->closeableQuery($business, $includeProcessing, $quiescentMinutes)->get() as $payout) {
            $was = $this->closeOut($payout);

            if ($was === null) {
                continue;
            }

            $closed++;
            $byStatus[$was] = ($byStatus[$was] ?? 0) + 1;

            // What went back to wallets, which is the number an operator most wants
            // off this command: a `failed` row restores nothing because it already
            // did.
            if ($was !== Payout::STATUS_FAILED) {
                $restoredKobo += (int) $payout->amount_kobo;
            }
        }

        return [
            'closed' => $closed,
            'restored_kobo' => $restoredKobo,
            'by_status' => $byStatus,
        ];
    }

    /**
     * The rows {@see self::closeOutstanding()} would act on.
     *
     * Public and shared with the command so the list an operator approves and the
     * list that gets closed are the same rows by construction — a preview built
     * from its own query is how a destructive command ends up doing something the
     * person who said yes never saw.
     *
     * @return Builder<Payout>
     */
    public function closeableQuery(
        ?Business $business = null,
        bool $includeProcessing = false,
        int $quiescentMinutes = 0,
    ): Builder {
        $statuses = [Payout::STATUS_PENDING, Payout::STATUS_FAILED];

        if ($includeProcessing) {
            $statuses[] = Payout::STATUS_PROCESSING;
        }

        return Payout::query()
            ->whereIn('status', $statuses)
            ->when($business !== null, fn (Builder $query) => $query->where('business_id', $business->id))
            ->when(
                $quiescentMinutes > 0,
                fn (Builder $query) => $query->where('updated_at', '<=', now()->subMinutes($quiescentMinutes)),
            )
            ->orderBy('id');
    }

    /**
     * One payout, closed out and its money given back where it is certainly ours.
     *
     * @return string|null the status it was closed from, or null when it was not
     *                     ours to close
     */
    private function closeOut(Payout $payout): ?string
    {
        $was = (string) $payout->status;

        // Claimed by condition, and by the status we actually read rather than by
        // the set we asked for: a row that stopped being `pending` between the read
        // and this write — a late webhook settling it — is left exactly as it now
        // is. Same shape as every other transition on this table.
        $claimed = Payout::query()
            ->whereKey($payout->id)
            ->where('status', $was)
            ->update(['status' => Payout::STATUS_CANCELLED]);

        if ($claimed === 0) {
            return null;
        }

        // Only for a row that never reached the provider. A `failed` row has had
        // this done to it already, and doing it twice would invent money the
        // provider never sent.
        $restore = $was !== Payout::STATUS_FAILED;

        $business = $payout->business;

        if ($restore && $business instanceof Business) {
            $this->wallet->restoreRefusedPayout($business, (int) $payout->amount_kobo);
        }

        if ($restore) {
            // The sales are owed again, so the attribution stops claiming them —
            // after the wallet credit, like `fail()`, so the money is back before
            // the record says the claim is gone.
            $this->allocations->release($payout);
        }

        // Through the model rather than the update above, because `meta` is cast
        // and the query builder would write a hand-encoded string.
        $payout->refresh();
        $payout->forceFill([
            'meta' => array_merge($payout->meta ?? [], [
                'cancelled_reason' => 'outstanding_close_out',
                'cancelled_at' => now()->toIso8601String(),
                // Kept because the interesting question about a closed row is
                // always "closed from where", and the column it was closed from no
                // longer says.
                'cancelled_from' => $was,
            ]),
        ])->save();

        Log::warning('custody.payout.closed_out', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'business_id' => $payout->business_id,
            'from_status' => $was,
            'amount_kobo' => (int) $payout->amount_kobo,
            // Whether this call is the one that moved money. The difference between
            // a row that gave a business its balance back and one that only stopped
            // counting against it, which is the whole substance of the command.
            'restored' => $restore,
        ]);

        return $was;
    }

    /**
     * Every refusal on this business's record since money last reached it.
     *
     * One definition with four readers — the ceiling that stops the attempts, the
     * backoff that paces them, the alert that reports them, and the operator lever
     * that clears them — so a count and its reset can never be about different sets
     * of rows.
     *
     * Deliberately not scoped to a destination, and deliberately not filtered by
     * failure code. That is what makes it the complement of
     * {@see self::destinationFailureQuery()}: whatever that one leaves out is what
     * this one is for.
     *
     * @return Builder<Payout>
     */
    private function failureQuery(Business $business)
    {
        $lastPaidAt = Payout::query()
            ->where('business_id', $business->id)
            ->where('status', Payout::STATUS_PAID)
            ->max('created_at');

        return Payout::query()
            ->where('business_id', $business->id)
            ->where('status', Payout::STATUS_FAILED)
            ->when($lastPaidAt !== null, fn ($query) => $query->where('created_at', '>', $lastPaidAt));
    }

    private function retryAttempts(): int
    {
        return (int) config('custody.retry_attempts', 3);
    }

    /** @return array<int, int> the gaps in minutes, oldest first */
    private function retryBackoffMinutes(): array
    {
        return (array) config('custody.retry_backoff_minutes', []);
    }

    /**
     * The refusals that count against the destination this business names now.
     *
     * One definition, two users — the ceiling counts these rows and the recovery
     * retires them — so a ceiling can never be lifted by a set of rows other than
     * the one that raised it.
     *
     * @return Builder<Payout>
     */
    private function destinationFailureQuery(Business $business, object $bank)
    {
        // Scoped to *this* destination, not to the business.
        //
        // The count means "refusals of this account since money last reached this
        // account", and with one destination per business the two readings were
        // the same thing. With several they are not: a payout that landed in
        // store B's bank would reset store A's count, and a destination the
        // provider will never accept would be retried on every run for ever
        // without the ceiling ever tripping — which is precisely the situation
        // the ceiling exists to stop.
        $lastPaidAt = Payout::query()
            ->where('business_id', $business->id)
            ->where('status', Payout::STATUS_PAID)
            ->where('bank_code', (string) $bank->bank_code)
            ->where('account_number', (string) $bank->account_number)
            ->max('created_at');

        return Payout::query()
            ->where('business_id', $business->id)
            ->where('status', Payout::STATUS_FAILED)
            ->whereIn('failure_code', self::DESTINATION_FAILURES)
            // The snapshot on the row, not the business's current bank: these are
            // attempts that were made to *that* account, and comparing them to a
            // destination nobody has tried yet would be reading the wrong history.
            ->where('bank_code', (string) $bank->bank_code)
            ->where('account_number', (string) $bank->account_number)
            ->when($lastPaidAt !== null, fn ($query) => $query->where('created_at', '>', $lastPaidAt));
    }

    private function maxDestinationFailures(): int
    {
        return (int) config('custody.max_destination_failures', 3);
    }

    /**
     * Pay every business that can be paid.
     *
     * @return array{sent: int, held: int, on_hold: int, skipped: int, failed: int, exhausted: int, backoff: int}
     */
    public function sweep(): array
    {
        $counts = ['sent' => 0, 'held' => 0, 'on_hold' => 0, 'skipped' => 0, 'failed' => 0, 'exhausted' => 0, 'backoff' => 0];
        $context = $this->context();

        if ($context === null) {
            return $counts;
        }

        foreach ($this->candidates() as $business) {
            // A tally rather than one outcome: a business may now have several
            // destinations, and one of them can fail while another lands. An
            // empty tally is "nothing was owed and nothing was withheld" — a
            // business whose whole balance is behind store holds. Counted as
            // nothing rather than as `skipped`, because `skipped` reads as
            // "another run took the money".
            foreach ($this->sweepBusiness($business, $context, ignoreBackoff: true) as $outcome => $count) {
                $counts[$outcome] += $count;
            }
        }

        return $counts;
    }

    /**
     * Why this business must not be paid right now, or null if it may be.
     *
     * {@see self::payoutBlocker()} is the list — the same one the wallet page and
     * the run's `--dry-run` table show a business. This adds only what the *run*
     * needs and they do not: which of its tallies each reason is counted under,
     * and the hold itself so the log can name it. Re-deriving the reasons here
     * instead is how a business gets told one thing on its own screen and stopped
     * by another in the run.
     *
     * The translation is not cosmetic, because the two vocabularies answer
     * different questions. `payoutBlocker()` explains a business to a person;
     * the tally counts what a run *did*, and the distinctions that matter there
     * are the ones the run's report is read for:
     *
     *   hold                  -> on_hold   a person stopped it
     *   auto_payout_off       -> held      the business stopped it
     *   no_bank/bank_incomplete -> skipped nothing to send to
     *   destination_exhausted -> exhausted the ceiling, so nothing was attempted
     *   retries_exhausted     -> exhausted the other ceiling, for the same reason
     *
     * A **store** hold is deliberately absent from both. It withholds that store's
     * share and pays the business for the rest — see {@see self::payableFor()} —
     * so it narrows a payment rather than blocking one.
     *
     * @return array{code: string, count: string, label: string, hold: PayoutHold|null}|null
     */
    public function restrictionFor(Business $business): ?array
    {
        $blocker = $this->payoutBlocker($business);

        if ($blocker === null) {
            return null;
        }

        $count = match ($blocker['code']) {
            'hold' => 'on_hold',
            'auto_payout_off' => 'held',
            'destination_exhausted', 'retries_exhausted' => 'exhausted',
            default => 'skipped',
        };

        return [
            'code' => $blocker['code'],
            'count' => $count,
            'label' => $blocker['label'],
            // Re-read rather than threaded out of `payoutBlocker()`, which answers
            // with a sentence for a person and not with the row. One extra query
            // on the path where there is a hold — the rare one — buys the two
            // answers being unable to disagree about *which* hold it is.
            'hold' => $blocker['code'] === 'hold' ? $this->holds->holdOn($business) : null,
        ];
    }

    /**
     * Pay this business everything it is owed, one transfer per destination.
     *
     * The whole of {@see self::sweep()} for a single row, so that a sale can be
     * paid out the moment it is collected rather than at the next tick without
     * becoming a second way to send money. Everything that makes a sweep safe is
     * here unchanged: the restriction is read before the provider is called, the
     * amount is the allocation walk's, and the reservation is written and the
     * wallet debited in one transaction that refuses to overdraw.
     *
     * Called with no context by {@see InstantPayoutService}, which has already
     * read the provider's balance for the promotion gate and would rather not ask
     * twice — and that is the only caller that leaves `$ignoreBackoff` alone, for
     * the reason given at the gate itself.
     *
     * @param  array{provider: string, rail: PayoutRail, credentials: GatewayCredentials}|null  $context
     * @param  bool  $ignoreBackoff  attempt even if the last refusal is still inside its gap — for a person, not for a clock
     * @return array<string, int> the tallies to add to the run's report
     */
    public function sweepBusiness(Business $business, ?array $context = null, bool $ignoreBackoff = false): array
    {
        $context ??= $this->context();

        if ($context === null) {
            return [];
        }

        $restriction = $this->restrictionFor($business);

        if ($restriction !== null) {
            $this->reportRestriction($business, $restriction);

            return [$restriction['count'] => 1];
        }

        // Waiting out the gap after a refusal.
        //
        // Checked after the restriction, so a business that is blocked by a hold or
        // a ceiling is reported as blocked rather than as merely waiting. Deliberately
        // *not* a blocker of its own: a hold and a ceiling each name something a person
        // has to act on, where this resolves itself on the clock and the business can
        // do nothing about it — so the run reports it and the wallet stays quiet. A
        // one-off provider blip that succeeds on the retry therefore never reaches the
        // business's screen at all.
        //
        // It applies to the instant path too, which is the point: a sale collected
        // during the gap waits with everything else, rather than being the one way to
        // slip a send past the backoff.
        //
        // ## Why a caller can ask it to be ignored
        //
        // The gap is a throttle on *automatic* attempts, and what it throttles is the
        // failure ceiling rather than the provider: without it, three sales collected
        // in the thirty seconds a provider is having a bad minute become three
        // refusals, which is `retries_exhausted`, which blocks a business that would
        // have been paid by the retry after its backoff. So it stays on the automatic
        // path — {@see InstantPayoutService::settle()}, reached by the queued job,
        // which has the worker's own `backoff()` on top of it.
        //
        // It is wrong for a person, though. `custody:payouts` is run *because* somebody
        // wants to know whether it works now, and a run that answers `backoff` has
        // told them nothing they could not already see on the wallet. One deliberate
        // run is not a burst, so {@see self::sweep()} passes `$ignoreBackoff` — and the
        // operator's retry does not need to, because it retires the failures the gap
        // is measured from before it asks.
        if (! $ignoreBackoff && $this->awaitingBackoff($business)) {
            Log::info('custody.payout.backoff', [
                'business_id' => $business->id,
                'failures' => $this->consecutiveFailures($business),
                'next_attempt_at' => $this->backoffUntil($business)?->toIso8601String(),
            ]);

            return ['backoff' => 1];
        }

        $destinations = $this->payableDestinations($business);

        // No buckets at all: no stores with money, and nothing on the business.
        if ($destinations->isEmpty()) {
            return [];
        }

        $tally = [];
        $payableKobo = 0;
        $withheldKobo = 0;

        foreach ($destinations as $destination) {
            $amountKobo = (int) $destination['payable_kobo'];
            $payableKobo += $amountKobo;
            $withheldKobo += (int) $destination['withheld_kobo'];

            // A destination whose money is entirely behind a store hold has
            // nothing to send and is not an outcome in itself — the tally below
            // decides whether the business as a whole was withheld.
            if ($amountKobo <= 0) {
                continue;
            }

            // Checked per destination, not once for the business. One account the
            // provider keeps refusing should stop *that* transfer, not every
            // other store's — which is what a single business-level check would
            // do now that a business can have several.
            if ($this->destinationIsExhausted($business, $destination['bank'])) {
                Log::warning('custody.payout.destination_exhausted', [
                    'business_id' => $business->id,
                    'bank_code' => $destination['bank']->bank_code,
                    'account_number' => $destination['bank']->account_number,
                    'failures' => $this->destinationFailures($business, $destination['bank']),
                ]);

                $tally['exhausted'] = ($tally['exhausted'] ?? 0) + 1;

                continue;
            }

            $payout = $this->reserve(
                $business,
                $destination['bank'],
                $amountKobo,
                $context['provider'],
                $destination['plan'],
                $destination['store_ids'],
            );

            // Not a failure. The overwhelmingly likely reason is that a
            // concurrent run took the money first, which is the reservation
            // doing its job.
            $outcome = $payout === null ? 'skipped' : $this->dispatch($payout, $context);

            $tally[$outcome] = ($tally[$outcome] ?? 0) + 1;
        }

        // Nothing went anywhere, and money is still owed — it is all behind
        // store holds. Counted as `on_hold` rather than skipped, because
        // "skipped" reads as "another run took it", and this is a deliberate
        // withholding that will not resolve until somebody lifts it.
        if ($tally === [] && $payableKobo <= 0 && $withheldKobo > 0) {
            $tally['on_hold'] = 1;
        }

        return $tally;
    }

    /**
     * Say why a business was not paid, in the words the run's output uses.
     *
     * @param  array{code: string, count: string, label: string, hold: PayoutHold|null}  $restriction
     */
    private function reportRestriction(Business $business, array $restriction): void
    {
        $hold = $restriction['hold'];
        $bank = $business->payoutBank;

        if ($restriction['code'] === 'hold') {
            Log::warning('custody.payout.on_hold', [
                'business_id' => $business->id,
                'hold_id' => $hold?->id,
                'scope' => $hold?->scope,
                'source' => $hold?->source,
                'reason' => $hold?->reason,
                'available_kobo' => (int) $business->available_balance,
            ]);

            return;
        }

        if ($restriction['code'] === 'auto_payout_off') {
            Log::info('custody.payout.held', [
                'business_id' => $business->id,
                'available_kobo' => (int) $business->available_balance,
            ]);

            return;
        }

        if ($restriction['code'] === 'destination_exhausted') {
            Log::warning('custody.payout.destination_exhausted', [
                'business_id' => $business->id,
                'available_kobo' => (int) $business->available_balance,
                'bank_code' => (string) $bank?->bank_code,
                'ceiling' => $this->maxDestinationFailures(),
            ]);

            return;
        }

        if ($restriction['code'] === 'retries_exhausted') {
            Log::warning('custody.payout.retries_exhausted', [
                'business_id' => $business->id,
                'available_kobo' => (int) $business->available_balance,
                'failures' => $this->consecutiveFailures($business),
                'ceiling' => $this->retryAttempts(),
            ]);

            return;
        }

        Log::warning('custody.payout.no_destination', [
            'business_id' => $business->id,
            'available_kobo' => (int) $business->available_balance,
            'has_bank' => (bool) $bank,
            'incomplete' => $restriction['code'] === 'bank_incomplete',
        ]);
    }

    /**
     * What this business would be paid right now, and which sales it would be
     * drawn from — without sending anything.
     *
     * Read by {@see self::sweep()} and by the `--dry-run` report, so the report is
     * the sweep's own arithmetic rather than an estimate of it. That matters more
     * than it sounds: the dry run exists to answer "is this business about to be
     * paid, and if not, why", and a report that computed the figure a second way
     * would be most wrong in exactly the interesting cases — a store partly held,
     * a wallet with a deficit behind it.
     *
     * ## It is the walk's number, capped by the wallet, and the wallet floors it
     *
     * The amount is the sum of the sales the payout will be drawn from, not the
     * wallet read on its own. In the ordinary case they are the same number; they
     * differ when a refund taken after a payout left the wallet negative behind
     * sales that still look unallocated, and the walk is what says which sales the
     * money came from.
     *
     * A store-scope hold narrows the walk rather than subtracting a share, which is
     * the agreed behaviour — withhold that store's share, pay everything else
     * normally — and it is also the only version that gets the hard case right.
     * "Pay the balance minus the held store's share" needs a share to subtract, and
     * a share is only knowable from sales, so when the wallet is worth less than
     * the sum of its sale nets there is no share to subtract and anything computed
     * from one overpays. Letting the walk do it means the held sales absorb none of
     * that deficit, and the send is bounded by the sales allowed to pay for it.
     *
     * The floor is the wallet, for money no sale can account for. The walk covers
     * the balance in every state the wallet itself can produce — a balance is only
     * ever raised by maturing a sale, and a sale that comes back is excluded from
     * the walk *and* debited from the balance — so a shortfall beyond what is
     * deliberately withheld means attribution is thin rather than that the money is
     * not owed. Paying it is the safe direction: a business is never left unpaid
     * because our records of which sale paid for what are incomplete. With a store
     * held the two reasons are mixed, so they are separated rather than guessed at:
     * a second walk with the held store put back says whether the sales behind this
     * balance are all still there. Only money *nothing* accounts for is paid;
     * money a held store accounts for is withheld.
     *
     * @return array{
     *     plan: Collection<int, array{transaction_id: int, store_id: int|null, amount_kobo: int}>,
     *     payable_kobo: int,
     *     available_kobo: int,
     *     held_store_ids: array<int, int>,
     *     withheld_kobo: int,
     *     unattributed_kobo: int,
     * }
     */
    /**
     * What this business is owed, split by where it is going.
     *
     * Per destination, because a payout is one transfer and a business may now
     * have several: its own default account, and an account a store names for
     * itself. Each entry is one transfer — a destination, the sales funding it,
     * and the amount.
     *
     * ## Buckets are grouped by where they are paid, not by store
     *
     * Two stores sharing the business default are one destination and one
     * transfer, which is what the platform did before any of this existed and
     * what a business with stores that all pay into the same account still
     * wants. Grouping by store would send two transfers to one account and pay
     * the provider twice for the privilege.
     *
     * ## A bucket is capped by its own money
     *
     * Each store contributes `max(0, its available)`, and its plan is drawn only
     * from its own sales. That is the whole point of the split: without it a
     * store's payout could be funded by another store's takings — money leaving
     * an account that never earned it, and the store that did earn it left short.
     *
     * A **negative** bucket contributes nothing and keeps its negative, to be
     * recovered from its own later sales. It does not reduce a sibling's payout,
     * which is the owner's stated rule — this store's money is this store's —
     * and it is the one place this differs from the business-level arithmetic,
     * where a deficit behind the wallet did absorb a held store's share.
     *
     * ## With the flag off this is one destination and one bucket
     *
     * Every sale has a null bucket and every balance is on the business, so the
     * grouping yields exactly one entry, funded by exactly the sales the old
     * single-business walk found. The gap and held-store arithmetic below
     * reproduces itself term for term — which is what lets the flag be flipped
     * without a second code path.
     *
     * @return Collection<int, array{bank: StoreBank, plan: Collection, payable_kobo: int, available_kobo: int, store_ids: array<int, int>, withheld_kobo: int, unattributed_kobo: int}>
     */
    public function payableDestinations(Business $business): Collection
    {
        $heldStoreIds = $this->holds->heldStoreIds($business);
        $default = $business->payoutBank;

        // The blocker has already refused a business with no usable default, so
        // this is belt-and-braces rather than a state the sweep reaches.
        if (! $default) {
            return collect();
        }

        /** @var array<int, array{bank: StoreBank, buckets: array<int, array{bucket: int|null, available: int, held: bool}>}> $groups */
        $groups = [];

        $add = function (?int $bucket, int $available, bool $held, StoreBank $bank) use (&$groups): void {
            $groups[(int) $bank->id] ??= ['bank' => $bank, 'buckets' => []];
            $groups[(int) $bank->id]['buckets'][] = ['bucket' => $bucket, 'available' => $available, 'held' => $held];
        };

        // The business's own bucket: money no store accounts for.
        $add(null, max(0, (int) $business->available_balance), false, $default);

        $stores = Store::query()
            ->where('business_id', $business->id)
            ->with('payoutBank')
            ->get();

        foreach ($stores as $store) {
            $held = in_array((int) $store->id, $heldStoreIds, true);
            $available = max(0, (int) $store->available_balance);

            // Nothing to pay and nothing withheld: the store is not part of any
            // destination this run, and adding it would only create a group with
            // an empty plan.
            if ($available === 0 && ! $held) {
                continue;
            }

            // A store naming its own account is paid there; one that names none
            // falls back to the business default, which is the rule stated on the
            // payout screen.
            $add((int) $store->id, $held ? 0 : $available, $held, $store->payoutBank ?? $default);
        }

        return collect($groups)->map(function (array $group) use ($business, $heldStoreIds) {
            // The cap counts every bucket in this destination, held ones
            // included — because what the hold costs is measured against what
            // the destination would otherwise have sent.
            $capKobo = array_sum(array_column($group['buckets'], 'available'));

            $plan = collect();
            $attributedKobo = 0;
            $anyHeld = false;

            foreach ($group['buckets'] as $bucket) {
                if ($bucket['held']) {
                    $anyHeld = true;

                    continue;
                }

                if ($bucket['available'] <= 0) {
                    continue;
                }

                // Planned per bucket, so each store's cap is drawn only from its
                // own sales. The destination's plan is the union of its buckets'
                // plans, and its attributed amount is what they add up to.
                $bucketPlan = $this->allocations->plan($business, $bucket['available'], $heldStoreIds, $bucket['bucket']);
                $plan = $plan->merge($bucketPlan);
                $attributedKobo += $this->allocations->total($bucketPlan);
            }

            $withheldKobo = 0;
            $gapKobo = 0;
            $amountKobo = $attributedKobo;

            if ($attributedKobo < $capKobo && $anyHeld) {
                // What the destination would have paid with no hold in the way —
                // every bucket's sales walked, not just the paying ones — so the
                // difference is what the hold actually cost rather than what the
                // held store's balance happens to be.
                $fullKobo = $this->allocations->total(
                    $this->allocations->plan($business, $capKobo),
                );

                $withheldKobo = max(0, $fullKobo - $attributedKobo);
                $gapKobo = max(0, $capKobo - $fullKobo);
                $amountKobo = $attributedKobo + $gapKobo;

                Log::warning('custody.payout.store_held', [
                    'business_id' => $business->id,
                    'store_ids' => $heldStoreIds,
                    'available_kobo' => $capKobo,
                    'payable_kobo' => $attributedKobo,
                    'withheld_kobo' => $withheldKobo,
                    'unattributed_kobo' => $gapKobo,
                    'reasons' => $this->holds->storeHolds($business)->pluck('reason', 'store_id')->all(),
                ]);
            } elseif ($attributedKobo < $capKobo) {
                $gapKobo = $capKobo - $attributedKobo;
                $amountKobo = $capKobo;
            }

            // Never more than the destination holds. With one bucket this cannot
            // bite — the walk's total is bounded by the same cap — but a
            // destination pooling several buckets can have its unattributed gap
            // measured against a walk that spent the cap on another bucket's
            // older sales. Sending more than is there is the one outcome worth a
            // belt-and-braces clamp; the reservation would refuse it anyway, and
            // refusing here keeps the log honest about why.
            $amountKobo = min($amountKobo, $capKobo);

            if ($gapKobo > 0) {
                Log::warning('custody.payout.allocation_short', [
                    'business_id' => $business->id,
                    'available_kobo' => $capKobo,
                    'attributed_kobo' => $amountKobo - $gapKobo,
                    'gaped_kobo' => $gapKobo,
                ]);
            }

            return [
                'bank' => $group['bank'],
                'plan' => $plan,
                'payable_kobo' => $amountKobo,
                'available_kobo' => $capKobo,
                'store_ids' => collect($group['buckets'])->pluck('bucket')->filter()->values()->all(),
                'withheld_kobo' => $withheldKobo,
                'unattributed_kobo' => $gapKobo,
            ];
        })->values();
    }

    /**
     * The aggregate, for the screens and the dry run.
     *
     * "How much is payable for this business" is still one figure — the sum of
     * what each destination will send — and every caller of this wants exactly
     * that. Kept as a method rather than made into a sum at each call site so the
     * wallet page and the run cannot disagree about the total.
     */
    public function payableFor(Business $business): array
    {
        $destinations = $this->payableDestinations($business);

        return [
            'plan' => $destinations->flatMap(fn ($destination) => $destination['plan'])->values(),
            'payable_kobo' => (int) $destinations->sum('payable_kobo'),
            'available_kobo' => (int) $destinations->sum('available_kobo'),
            'held_store_ids' => $this->holds->heldStoreIds($business),
            'withheld_kobo' => (int) $destinations->sum('withheld_kobo'),
            'unattributed_kobo' => (int) $destinations->sum('unattributed_kobo'),
            'destinations' => $destinations,
        ];
    }

    /**
     * Write the row and take the money out of the wallet, or neither.
     *
     * One transaction, so there is no state in which a payout exists without its
     * reservation or a reservation without its payout. Rounding that off matters
     * more than it looks: a row without a reservation is a payout that would be
     * sent with the wallet still claiming to owe it, and a reservation without a
     * row is money removed from a business that nothing records a reason for.
     *
     * `requested_by` stays null — the sweep asked for this, and the column is how
     * an automated payout is told from one a person pressed.
     *
     * ## The plan is committed here, in the same transaction
     *
     * `$plan` is the set of sales the amount was worked out from, and its rows are
     * written beside the payout and the reservation. All three or none: a payout
     * with no allocations is money nobody can attribute, and allocations without a
     * payout are sales that look paid when nothing was sent.
     *
     * @param  Collection<int, array{transaction_id: int, store_id: int|null, amount_kobo: int}>  $plan
     */
    private function reserve(
        Business $business,
        StoreBank $bank,
        int $amountKobo,
        string $provider,
        Collection $plan,
        array $storeIds = [],
    ): ?Payout {

        try {
            return DB::transaction(function () use ($business, $bank, $amountKobo, $provider, $plan, $storeIds) {
                $payout = Payout::reserve(
                    (int) $business->id,
                    // The store this transfer was for, when the destination
                    // serves exactly one. Null when it pools several stores, or
                    // pays money no store accounts for — the per-store breakdown
                    // of any payout is on its allocations, which is
                    // authoritative either way.
                    count($storeIds) === 1 ? (int) $storeIds[0] : null,
                    $provider,
                    (string) $bank->account_number,
                    (string) $bank->bank_code,
                    $bank->account_name,
                    $amountKobo,
                    $this->currency(),
                    payoutBankId: (int) $bank->id,
                );

                // Refuses to overdraw, which is the point: if a refund or another
                // run has taken this money since `candidates()` read it, this
                // throws and the row above is rolled back with it.
                $this->wallet->releaseForPayout($business, $amountKobo, $plan);

                $this->allocations->commit($payout, $plan, $this->currency());

                return $payout;
            });
        } catch (\Throwable $e) {
            Log::info('custody.payout.reservation_refused', [
                'business_id' => $business->id,
                'amount_kobo' => $amountKobo,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get one reserved payout to the provider, and settle the row from the answer.
     *
     * Everything the provider does happens here, so the sweep and the reconciler
     * cannot diverge: a stuck `pending` row is chased by resending it through this
     * same method with the same reference, which is exactly the retry an
     * idempotency key exists to make safe.
     *
     * @param  array{provider: string, rail: PayoutRail, credentials: GatewayCredentials}  $context
     * @return string the count to increment: `sent` or `failed`
     */
    private function dispatch(Payout $payout, array $context): string
    {
        $destination = $this->destination($payout, $context);

        if ($destination === null) {
            return 'failed';
        }

        $payout->forceFill([
            'destination_id' => $destination->id,
            'attempts' => (int) $payout->attempts + 1,
            'sent_at' => now(),
        ])->save();

        // ---------------------------------------------------------------
        // The request, and then the provider's answer to it
        // ---------------------------------------------------------------
        //
        // Two lines per attempt, and they are the pair that answers the question
        // everything else in this file only implies: *did the provider receive a
        // payout request, and what did it say back?* Until now the log recorded
        // what the platform decided — reserved, accepted, failed — and nothing that
        // could be held against the provider's own dashboard. So the first thing
        // anybody asked when a transfer was missing there was the one thing no line
        // could answer, and the search moved to the provider's API logs.
        //
        // **`requesting` is logged before the call, not after it.** A request that
        // leaves and never comes back is the case where the pair matters most: with
        // only an after-the-fact line, a connection that hung and was killed leaves
        // no trace at all, and "we never sent it" and "we sent it and nobody heard"
        // are the two possibilities that need opposite responses. Logged first,
        // every attempt has a beginning; `custody.payout.acknowledged` is its end,
        // and an attempt with a beginning and no end is a request still out there.
        //
        // Neither line carries the account number or the account name. The last four
        // digits and the provider's own destination id are enough to recognise a
        // transfer in either system, and enough is what this gets — see
        // {@see ProviderPayload} for why the full body is not safe to put here.
        Log::info('custody.payout.requesting', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'business_id' => $payout->business_id,
            'provider' => $context['provider'],
            'amount_kobo' => (int) $payout->amount_kobo,
            'attempt' => (int) $payout->attempts,
            'destination_id' => (string) $destination->id,
            'account_last_four' => $this->lastFour($payout->account_number),
        ]);

        try {
            $outcome = $context['rail']->send(
                $context['credentials'],
                (string) $destination->id,
                (int) $payout->amount_kobo,
                (string) $payout->reference,
                $this->currency(),
            );
        } catch (\Throwable $e) {
            // Ours, not the provider's — but a request may still have gone out
            // before whatever this was, so it is unresolved rather than refused.
            // The reference makes a later retry the same request, and the
            // reconciler is what resolves it.
            // Paired with the `requesting` line above: this one appearing without a
            // `custody.payout.acknowledged` after it is what a request that left and
            // was never answered looks like, and it is the reason `requesting` is
            // written first. The message is ours, so it is not scrubbed — but it is
            // capped, because a client library's exception can be very long.
            Log::error('custody.payout.send_threw', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
                'business_id' => $payout->business_id,
                'provider' => $context['provider'],
                'error' => ProviderPayload::scrubText($e->getMessage()),
                'exception' => $e::class,
            ]);

            $outcome = TransferOutcome::indeterminate($e->getMessage());
        }

        // The provider's answer, as it gave it. `ack` is the response body with
        // every field that could identify a person or their bank stripped out —
        // and it is logged even when empty, because Bachs answering `{}` and Bachs
        // answering nothing at all are different facts about the same request.
        //
        // `outcome` is ours and `provider_status` is theirs, kept side by side
        // rather than mapped onto each other: ours is what we did about it, theirs
        // is the word the provider's own dashboard will be showing, and a support
        // call is two people reading those two words off two screens.
        Log::info('custody.payout.acknowledged', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'business_id' => $payout->business_id,
            'provider' => $context['provider'],
            'amount_kobo' => (int) $payout->amount_kobo,
            'attempt' => (int) $payout->attempts,
            'outcome' => $outcome->outcome,
            'provider_status' => $outcome->providerStatus,
            'transfer_id' => $outcome->transferId,
            'failure_code' => $outcome->failureCode,
            // A provider's refusal sentence frequently quotes the account holder's
            // name or their account number, so the shapes are scrubbed out of it
            // before it is written here.
            'message' => ProviderPayload::scrubText($outcome->message),
            'ack' => $outcome->raw === [] ? null : ProviderPayload::redact($outcome->raw),
        ]);

        return $this->apply($payout, $outcome);
    }

    /**
     * The destination to send to, taken from what the platform already knows.
     *
     * ## Where it comes from, and why it is not created here
     *
     * The business named this account once, and the destination was registered
     * against it *then* — see {@see PayoutBankResolver} — because registering here
     * is what made this the slowest and most wrong part of a payout:
     *
     * - **Wrong**, because the code on the row is a *Paystack* code, since the
     *   bank picker is Paystack's list, and Bachs numbers the same institutions
     *   differently. Handing it over untranslated is what made an Opay or
     *   Moniepoint account unpayable, with the failure filed as a provider fault.
     * - **Slow**, because `ensureDestination()` creates a destination rather than
     *   finding one — Bachs returned three different ids for three identical
     *   registrations — so every payout row added another one at the provider.
     *
     * So this reads. The row for the provider paying *right now* is the one that
     * answers, which is what makes a processor switch need nothing from the
     * business: `$context['provider']` is read per send from the platform's
     * selected processor.
     *
     * ## The fallback, and why it is not a refusal
     *
     * An account named before any of this existed has no row, and a business
     * holding money must not be stopped by our own migration backlog. So when
     * there is nothing recorded, the code is translated here and the destination
     * registered once — and then recorded, so the next payout takes the fast path.
     *
     * Read from the payout row rather than the business throughout, because a
     * payout already in flight has to keep going where it was reserved for even if
     * the business changes its bank in the meantime.
     *
     * Returns null and fails the payout when the provider will not accept the
     * destination. That releases the reservation, and it is safe to: no money has
     * moved, and a destination is not a transfer.
     *
     * @param  array{provider: string, rail: PayoutRail, credentials: GatewayCredentials}  $context
     */
    private function destination(Payout $payout, array $context): ?PayoutDestinationRef
    {
        if (filled($payout->destination_id)) {
            return PayoutDestinationRef::registered((string) $payout->destination_id, true);
        }

        $bank = $this->payoutBank($payout);

        // The registration made when the business named this account, for the
        // provider that is paying now. `is_usable` is passed through rather than
        // assumed: Bachs reports it, and a destination it considers unusable will
        // refuse the transfer later.
        if ($bank !== null) {
            $stored = $this->payoutBanks->stored($bank, $context['provider']);

            if (filled($stored?->destination_id)) {
                return PayoutDestinationRef::registered(
                    (string) $stored->destination_id,
                    $stored->is_usable ?? true,
                );
            }
        }

        // The number we hold for this account, in whichever provider's space it was
        // written in. The payout row carries its own snapshot, so this works for a
        // payout reserved before `payout_bank_id` existed as well as for one whose
        // bank account has since been deleted.
        $held = $bank !== null ? (string) $bank->bank_code : (string) $payout->bank_code;

        $code = $held;

        if ($bank !== null) {
            $translated = $this->directory->codeFor(
                $context['rail'],
                $context['credentials'],
                (string) $bank->bank_code,
                (string) $bank->bank_name,
            );

            // A translation is an improvement on the number we hold, never a
            // condition for sending.
            //
            // Null here covers two things — this provider does not list the bank,
            // and we could not read the provider's list at all — and neither is a
            // reason to stop a payout. Falling back to the number we hold is what
            // the platform did before any of this existed, and the provider's own
            // refusal is a better answer than ours would be: it arrives with the
            // provider's words, and it is a refusal we can quote.
            //
            // Failing the payout instead would strand a business's money on our own
            // uncertainty, and in the unreadable case it would turn one provider
            // having an afternoon into nobody on the platform being paid. The bank
            // that genuinely cannot be paid is stopped where it costs nothing —
            // {@see PayoutBankResolver::guardDefault()} refuses the account as it is
            // added and {@see PayoutBankResolver::guardStored()} refuses it as it is
            // chosen, both before anything is owed.
            if ($translated !== null) {
                $code = $translated;
            } else {
                Log::info('custody.payout.destination_untranslated', [
                    'payout_id' => $payout->id,
                    'reference' => $payout->reference,
                    'provider' => $context['provider'],
                    'bank_code' => $held,
                    'bank_name' => $bank->bank_name,
                ]);
            }
        }

        try {
            $destination = $context['rail']->ensureDestination(
                $context['credentials'],
                (string) $payout->account_number,
                $code,
                $payout->account_name,
                $this->currency(),
            );
        } catch (\Throwable $e) {
            $this->fail($payout, 'destination_error', $e->getMessage());

            return null;
        }

        if (! $destination->payable()) {
            $this->fail(
                $payout,
                'destination_unusable',
                $destination->message ?? 'The provider will not accept this payout destination.',
            );

            return null;
        }

        // Recorded so this is the last time it is registered. Failing to record it
        // is not worth failing the payout over — the money is on its way and the
        // cost of not knowing is one more registration next time.
        if ($bank !== null && filled($destination->id)) {
            (new PayoutBankResolution(
                provider: $context['provider'],
                bankCode: $code,
                destinationId: $destination->id,
                usable: $destination->usable,
            ))->store($bank);
        }

        return $destination;
    }

    /**
     * The `store_banks` row this payout was reserved against, if it still exists.
     *
     * Null for a payout reserved before `payout_bank_id` was written, and for one
     * whose account has since been deleted. Both are the fallback path rather than
     * an error: the payout row carries its own snapshot of the code and number, and
     * nothing here needs the business's current list.
     */
    private function payoutBank(Payout $payout): ?StoreBank
    {
        $id = $payout->payout_bank_id;

        return $id === null ? null : StoreBank::query()->find((int) $id);
    }

    /**
     * Record what the provider said, and move the money accordingly.
     *
     * @return string `sent` or `failed`
     */
    private function apply(Payout $payout, TransferOutcome $outcome): string
    {
        $business = $payout->business;
        $meta = array_merge($payout->meta ?? [], ['last_send' => $outcome->raw]);

        if ($outcome->isRefused()) {
            $this->fail($payout, $outcome->failureCode, $outcome->message, $meta);

            return 'failed';
        }

        // Accepted, or unresolved. Both keep the reservation: the difference
        // between them is whether we know the money is on its way or merely
        // suspect it might be, and neither is a reason to owe the business the
        // same amount again.
        $payout->forceFill([
            'status' => Payout::STATUS_PROCESSING,
            'provider_payout_id' => $outcome->transferId ?? $payout->provider_payout_id,
            // Kept on the row as well as in the log, because the row is what
            // somebody reviewing a stuck payout will actually be looking at, and
            // "we never found out" is the fact they need.
            'failure_reason' => $outcome->isIndeterminate()
                ? Str::limit((string) $outcome->message, 250, '')
                : null,
            'meta' => $meta,
        ])->save();

        // The clock starts here, and this is the only place it can: every path that
        // leaves a row `processing` runs through this method, so one line covers the
        // sweep's send, the reconciler's resend of a stuck reservation, and a
        // provider that accepted the request and then never answered it.
        //
        // **Indeterminate sends start it too.** The provider said nothing useful, or
        // the request threw before we read the answer — which is the case where a
        // question is worth the most, not the least. For Paystack the lookup resolves
        // by our own reference, so a five-second question can settle a transfer we
        // have no id for; for Bachs, which cannot look up without the id it never
        // gave us, the question costs nothing at all — `lookUp()` returns null before
        // making a request. Either way the ladder ends in a human being told, which
        // is right: a transfer that was sent and has no answer is unconfirmed money.
        CheckPayoutStatus::forPayout($payout);

        if ($outcome->isIndeterminate()) {
            Log::warning('custody.payout.indeterminate', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
                'message' => $outcome->message,
            ]);

            return 'sent';
        }

        Log::info('custody.payout.accepted', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'business_id' => $payout->business_id,
            'amount_kobo' => (int) $payout->amount_kobo,
            'provider_payout_id' => $outcome->transferId,
        ]);

        return 'sent';
    }

    /**
     * Mark a payout failed, give the money back, and say so.
     *
     * Used for refusals and for destinations the provider will not accept — both
     * are cases where the money definitely did not move. It must not be reached
     * from a timeout, which is what {@see TransferOutcome::isRefused()} guards.
     *
     * ## Claimed by condition, because a refusal can arrive after the money did
     *
     * The sweep only ever calls this about a row it is itself driving, but the
     * webhook path calls it about any row a delivery names — and deliveries
     * arrive out of order. A `failed` that lands *after* the same provider has
     * told us the transfer completed would otherwise flip a `paid` row back and
     * run {@see CustodyService::restoreRefusedPayout()} on money the business has
     * already been sent: paid twice, once by the transfer and once by the
     * credit, with both books agreeing it never happened.
     *
     * So the update is the claim, exactly as {@see self::settle()} and
     * {@see self::undo()} claim theirs, and a row that has already reached a
     * final status is left alone with a log line saying so.
     */
    private function fail(Payout $payout, ?string $code, ?string $reason, ?array $meta = null): void
    {
        $claimed = Payout::query()
            ->whereKey($payout->id)
            ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_PROCESSING])
            ->update([
                'status' => Payout::STATUS_FAILED,
                'failure_code' => $code ? Str::limit($code, 60, '') : null,
                'failure_reason' => $reason ? Str::limit($reason, 250, '') : null,
                'failed_at' => now(),
            ]);

        if ($claimed === 0) {
            Log::warning('custody.payout.failure_ignored', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
                'status' => $payout->status,
                'failure_code' => $code,
            ]);

            return;
        }

        // After the claim, so the cast applies and it cannot be written through
        // the query builder as a hand-encoded string.
        $payout->refresh();

        if ($meta !== null) {
            $payout->forceFill(['meta' => $meta])->save();
        }

        $business = $payout->business;

        if ($business instanceof Business) {
            $this->wallet->restoreRefusedPayout($business, (int) $payout->amount_kobo);
        }

        // The sales are owed again, so the attribution stops claiming them. After
        // the wallet credit, like it: the money is back before the record says the
        // claim is gone, never the other way round.
        $this->allocations->release($payout);

        Log::warning('custody.payout.failed', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'business_id' => $payout->business_id,
            'amount_kobo' => (int) $payout->amount_kobo,
            'failure_code' => $code,
            // The provider's own sentence, which is where a bank name, an account
            // holder or an account number most often comes back to us — so the log
            // copy is scrubbed. The row keeps the unscrubbed text, deliberately:
            // the row is the record of what actually happened, and an operator
            // acting on a refusal needs the provider's real words, not the version
            // cleaned up for a log file.
            'failure_reason' => ProviderPayload::scrubText($reason),
        ]);

        if (! $business instanceof Business) {
            return;
        }

        // Told the money came back, and the count is the failures standing against
        // the bank the business names now. The suffix on the notification key is
        // that count, so a refusal a business does nothing about is told once and a
        // new refusal after a change of bank is told again.
        $this->notifier->payoutFailed($payout, $reason, $this->destinationFailures($business));

        // And if this refusal is the one that crossed the ceiling, the business is
        // told something different and more urgent: the sweep has stopped asking,
        // and money is now accumulating that nothing will send. Checked *here*
        // rather than in {@see self::sweep()} on purpose — a check there would
        // re-fire on every pass and the crossing, which is the one moment worth a
        // message, would be indistinguishable from the silence that follows it.
        if ($this->destinationIsExhausted($business)) {
            $this->notifier->destinationBlocked($business, $payout, $this->destinationFailures($business));
        }

        $this->tellPlatformIfStalled($business, $payout);
    }

    /**
     * Tell the operators, when this refusal has stopped sending rather than delayed it.
     *
     * The business is told about a refusal by {@see CustodyNotifier}, and was told
     * about a crossing ceiling by the branch above. Neither of those is addressed to
     * anyone who can act on a *platform* problem: `INSUFFICIENT_BALANCE` from a
     * settling batch, a provider having an afternoon, a code nobody has seen — all
     * of them exhaust the retry ceiling, all of them stop a business being paid, and
     * until this existed the only route to a person was the business complaining to
     * support.
     *
     * ## Why it asks {@see self::payoutBlocker()} instead of counting
     *
     * The check is "is this business blocked now", read from the one method every
     * other surface reads, and not "did this attempt cross the number". A number
     * would be wrong in a case that is easy to reach: with the destination ceiling
     * set below the retry ceiling, the destination trips first, the sends stop, and
     * the retry count freezes below its own — so a crossing test would never fire
     * and the stall would be silent. Asking the blocker cannot miss either ceiling,
     * and cannot drift from what the wallet shows the business.
     *
     * ## Why this fires once and needs no dedupe key
     *
     * Because the blockers it accepts are exactly the ones that stop the failures
     * that call it. Nothing sends, so nothing fails, so this is not reached again
     * until an operator clears the ceiling — which is the moment they should be told
     * a second time. A hold, or a missing bank, is deliberately *not* among the
     * accepted codes: those are a business's own state, no attempt failed because of
     * them, and they are already reported where the business can see them.
     */
    private function tellPlatformIfStalled(Business $business, Payout $payout): void
    {
        $blocker = $this->payoutBlocker($business);

        if ($blocker === null || ! in_array($blocker['code'], self::STALLING_BLOCKERS, true)) {
            return;
        }

        $this->platform->payoutsStalled($business, $payout, $blocker, $this->consecutiveFailures($business));
    }

    /**
     * Ask the provider about every payout that is still unresolved.
     *
     * Two different questions, and the second is the one that matters:
     *
     * - **`processing`** — the provider accepted it and we have not heard it land.
     *   A dropped webhook would strand the row here forever, so it is read back.
     * - **`pending`** — the row was reserved and the send never completed. The
     *   money is out of the wallet and we do not know whether the request went
     *   out. It is **resent with the same reference**, not cancelled: the
     *   reference is the idempotency key, so a provider that already has it
     *   de-duplicates and one that does not sends it once. Cancelling instead
     *   would release a reservation for a transfer that may be in flight, which
     *   is the double payment this whole design exists to prevent.
     *
     * @return array{settled: int, reversed: int, failed: int, unresolved: int, resent: int}
     */
    public function reconcile(): array
    {
        $counts = ['settled' => 0, 'reversed' => 0, 'failed' => 0, 'unresolved' => 0, 'resent' => 0];
        $context = $this->context();

        if ($context === null) {
            return $counts;
        }

        $stuck = Payout::query()
            ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_PROCESSING])
            ->where('requested_at', '<=', now()->subMinutes((int) config('custody.reconcile_after_minutes', 30)))
            ->orderBy('requested_at')
            ->limit((int) config('custody.reconcile_batch', 100))
            ->get();

        foreach ($stuck as $payout) {
            if ($payout->status === Payout::STATUS_PENDING) {
                // `dispatch` answers `failed` only when the provider refused the
                // destination or the transfer, both of which have already given
                // the money back — so it is a failure here too, not an unresolved
                // row to come back to.
                $counts[$this->dispatch($payout, $context) === 'sent' ? 'resent' : 'failed']++;

                continue;
            }

            $counts[$this->resolve($payout, $context)]++;
        }

        return $counts;
    }

    /**
     * Ask the provider about one payout, and write the row from the answer.
     *
     * The single-payout form of {@see self::reconcile()}, and a public door onto
     * {@see self::resolve()} for the two callers that cannot use the batch:
     * {@see CheckPayoutStatus}, which asks about a transfer seconds after sending it
     * rather than half an hour later, and an operator's **Check now**, which asks
     * about the one row they are looking at.
     *
     * ## The guard, and what it is actually guarding
     *
     * A row that is not `processing` is answered `skipped` and the provider is never
     * asked. That is **not** what makes the write safe — `settle()`, `fail()` and
     * `undo()` each claim the row with a conditional update before touching
     * anything, precisely so that a racing writer loses cleanly, and `undo()`
     * claiming from `paid` is the designed recall path rather than a bug. What the
     * guard buys is that the *ladder* stops: no further provider calls, and no
     * "unconfirmed" alert about a payout that quietly reached a final state ten
     * seconds after it was sent.
     *
     * It lives here rather than in the callers because both of them must obey one
     * rule, and a rule with two copies is a rule that drifts.
     *
     * @return string `skipped`|`settled`|`failed`|`reversed`|`unresolved`
     */
    public function checkPayout(Payout $payout): string
    {
        if ($payout->status !== Payout::STATUS_PROCESSING) {
            return 'skipped';
        }

        $context = $this->context();

        if ($context === null) {
            return 'unresolved';
        }

        return $this->resolve($payout, $context);
    }

    /**
     * One `processing` payout, read back from the provider.
     *
     * @param  array{provider: string, rail: PayoutRail, credentials: GatewayCredentials}  $context
     * @return string the count to increment
     */
    private function resolve(Payout $payout, array $context): string
    {
        $provider = (string) $context['provider'];

        // The provider cannot be asked about this row at all — Bachs, and a send
        // that never returned an id. No request is made, so this is not evidence
        // either way about the provider's health and the breaker is left alone.
        if (! $context['rail']->canLookUpPayout($payout->provider_payout_id)) {
            return 'unresolved';
        }

        // The provider was unreachable within the last window, so every question
        // would cost the same thirty-second timeout to learn the same nothing.
        // Answering `unresolved` without asking keeps the worker free for money —
        // and, importantly, does not stop the ladder, which still ends in its one
        // alert. See {@see PayoutLookupBreaker}.
        if (! $this->breaker->allows($provider)) {
            Log::info('custody.payout.lookup_skipped', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
                'provider' => $provider,
                'reason' => 'provider_unreachable',
            ]);

            return 'unresolved';
        }

        try {
            $outcome = $context['rail']->lookUp(
                $context['credentials'],
                (string) $payout->reference,
                $payout->provider_payout_id,
            );
        } catch (PayoutLookupUnreachable $e) {
            // The *provider* is the problem, not this transfer. One failure opens
            // the breaker, so the other payouts due in this window are answered
            // without an HTTP call instead of each burning the full timeout.
            $this->breaker->recordUnreachable($provider);

            Log::warning('custody.payout.lookup_unreachable', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return 'unresolved';
        } catch (\Throwable $e) {
            // Something else went wrong inside the rail — unreadable body, a bug.
            // That is not a statement about reachability, so the breaker keeps its
            // state and the next check tries again.
            Log::warning('custody.payout.lookup_failed', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage(),
            ]);

            return 'unresolved';
        }

        // The provider answered, whatever it said about this transfer — including
        // "I have nothing for that reference". It is up, so the breaker closes.
        $this->breaker->recordAnswered($provider);

        // "Cannot answer" — which is not "not found". Bachs has no lookup by our
        // reference, so a Bachs send that never returned an id can only be
        // resolved by its webhook, and the row waits. Releasing the reservation
        // here would be releasing it on a transfer that may well have landed.
        if ($outcome === null) {
            return 'unresolved';
        }

        if ($outcome->isRefused()) {
            $this->fail($payout, $outcome->failureCode, $outcome->message);

            return 'failed';
        }

        $status = strtolower((string) $outcome->providerStatus);

        if (in_array($status, self::REVERSED_STATUSES, true)) {
            $this->undo($payout, $outcome->message);

            return 'reversed';
        }

        if (in_array($status, self::PAID_STATUSES, true)) {
            $this->settle($payout, $outcome);

            return 'settled';
        }

        // Accepted and still moving. Nothing to do but wait, and the row is
        // already where it needs to be for the next pass to find it.
        return 'unresolved';
    }

    /**
     * A provider delivery about a payout Storify sent.
     *
     * The third way into this service, and the one that closes the gap the other
     * two leave open: a send that the provider accepted and then carried out
     * **without ever answering our request** — a dropped response, a proxy 502, a
     * process that died between the call and the read — leaves a `processing` row
     * whose only other route out is {@see self::reconcile()} polling for it. This
     * is the provider volunteering the answer instead, and it is the only delivery
     * that can carry a *reversal*, which no amount of asking will tell us about
     * until we ask: a transfer recalled a week later arrives here and nowhere
     * else.
     *
     * ## Matched on the provider's id, and then on ours
     *
     * `withdrawal_id` is Bachs' name for it and `id` is the generic one; both are
     * asked because the field is not pinned by the documentation and the cost of
     * asking twice is a lookup. Ours — the reference we issued and passed as the
     * idempotency key — is the fallback, because a delivery about a payout the
     * provider never gave an id for still names the request.
     *
     * Returns whether a row was found. `false` is not an error and is not
     * answered as one: a delivery about a payout we have no record of is either
     * one another environment sent or one from before the `payouts` table
     * existed, and neither is fixed by a retry.
     *
     * @param  array<string, mixed>  $data
     */
    public function applyPayoutWebhook(array $data, string $type): bool
    {
        $payout = $this->payoutForWebhook($data, $type);

        if ($payout === null) {
            return false;
        }

        // The payload's own status where it has one, and the event's name where
        // it does not. Both vocabularies are the provider's and neither is
        // translated into a constant first: the lists below are the translation,
        // and an unrecognised status has to stay unrecognised rather than being
        // flattened into the nearest thing we happen to know.
        $status = strtolower((string) ($data['status'] ?? ''));

        if ($status === '') {
            $status = strtolower((string) (explode('.', $type)[1] ?? ''));
        }

        if (in_array($status, self::REVERSED_STATUSES, true)) {
            $this->undo($payout, $this->stringValue($data, 'failure_reason', 'reason') ?? 'Returned by the provider');

            return true;
        }

        if ($status === 'failed') {
            // A refusal the provider reports rather than answers. It is still a
            // refusal — the money did not move — so the reservation goes back and
            // the wallet is credited, exactly as a synchronous `failed` does.
            $this->fail(
                $payout,
                $this->stringValue($data, 'failure_code', 'code'),
                $this->stringValue($data, 'failure_reason', 'reason', 'message'),
                array_merge($payout->meta ?? [], ['webhook' => $data]),
            );

            return true;
        }

        if (in_array($status, self::PAID_STATUSES, true)) {
            $this->settle($payout, TransferOutcome::accepted(
                $this->stringValue($data, 'withdrawal_id', 'id') ?? $payout->provider_payout_id,
                $status,
                $data,
            ));

            return true;
        }

        // `processing`, `queued`, `pending` — accepted and still moving, which is
        // where the row already is. Recorded rather than dropped, because a
        // payout that only ever sends "still going" is one somebody will have to
        // look at, and the log is where they will start.
        $payout->forceFill([
            'meta' => array_merge($payout->meta ?? [], ['last_webhook' => $data]),
            'provider_payout_id' => $this->stringValue($data, 'withdrawal_id', 'id') ?? $payout->provider_payout_id,
        ])->save();

        Log::info('custody.payout.webhook_in_flight', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'status' => $status,
            'type' => $type,
        ]);

        return true;
    }

    /**
     * The payout row a delivery is about, or null when we have no record of it.
     *
     * Provider id first: it is what the provider itself would recognise, and the
     * only thing a delivery about a payout we sent under a reference it never
     * echoed back can carry. The reference second, and asked in that order
     * because the two can only disagree in a way this resolves — a row that has
     * a provider id but whose delivery names a reference was recorded under both
     * and is the same row.
     *
     * @param  array<string, mixed>  $data
     */
    private function payoutForWebhook(array $data, string $type): ?Payout
    {
        $providerId = $this->stringValue($data, 'withdrawal_id', 'id');
        $reference = $this->stringValue($data, 'reference', 'merchant_reference');

        $payout = $providerId !== null
            ? Payout::query()->where('provider_payout_id', $providerId)->latest('id')->first()
            : null;

        $payout ??= $reference !== null
            ? Payout::query()->where('reference', $reference)->latest('id')->first()
            : null;

        if ($payout === null) {
            Log::warning('custody.payout.webhook_unmatched', [
                'provider_payout_id' => $providerId,
                'reference' => $reference,
                'type' => $type,
            ]);
        }

        return $payout;
    }

    /**
     * The first of several possible keys that holds a non-empty string.
     *
     * Alias names rather than one canonical key: Bachs' webhook payloads and its
     * REST objects do not always agree on a field's name, and a lookup that
     * insisted on one of them would silently find nothing.
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

    /**
     * The provider says it landed.
     *
     * The reservation is already spent, so there is no wallet movement here — only
     * the row and, on both sets of books, the entry that says the money is gone.
     * Both entries carry the payout's own reference as their idempotency key, so a
     * webhook arriving after this pass cannot post them a second time.
     */
    private function settle(Payout $payout, TransferOutcome $outcome): void
    {
        // Claimed by condition: a webhook path may be settling the same row at the
        // same moment. The ledger keys are what stop a second posting; this is
        // what stops a second read-back being logged and stamped as if it were
        // news.
        $claimed = Payout::query()
            ->whereKey($payout->id)
            ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_PROCESSING])
            ->update([
                'status' => Payout::STATUS_PAID,
                'paid_at' => now(),
                'failure_code' => null,
                'failure_reason' => null,
                'provider_payout_id' => $outcome->transferId ?? $payout->provider_payout_id,
            ]);

        if ($claimed === 0) {
            return;
        }

        // After the claim, so the cast applies and it cannot be written through
        // the query builder as a hand-encoded string.
        $payout->refresh();
        $payout->forceFill([
            'meta' => array_merge($payout->meta ?? [], ['lookup' => $outcome->raw]),
        ])->save();

        // The attribution stops being a plan and becomes the record of which sales
        // this payout settled. No money moves with it — the wallet was debited at
        // reservation.
        $this->allocations->settle($payout);

        $storeId = $payout->store_id !== null ? (int) $payout->store_id : null;

        // The business's books: its money left our hands for its bank.
        $this->ledger->safe(fn () => $this->ledger->postGatewayWithdrawal(
            (int) $payout->business_id,
            $storeId,
            (int) $payout->amount_kobo,
            (string) $payout->reference,
            custody: true,
        ));

        // Our books: we are no longer holding it.
        $this->ledger->safe(fn () => $this->ledger->postCustodyPayoutOnPlatformBooks(
            (int) $payout->amount_kobo,
            (string) $payout->reference,
            $storeId,
        ));

        Log::info('custody.payout.settled', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'business_id' => $payout->business_id,
            'amount_kobo' => (int) $payout->amount_kobo,
        ]);

        // The business is told its money arrived. After the claim above, which is
        // what makes this exactly-once: a webhook replay and a `reconcile()`
        // read-back of the same row both reach `$claimed === 0` and return before
        // here, so the hook only ever runs on the call that actually moved the row
        // to `paid`.
        $this->notifier->payoutPaid($payout);
    }

    /**
     * The money came back to us after landing.
     *
     * A recall or a provider-side reversal, not a failure of the send: it left,
     * the business's bank did not keep it, and it is in Storify's balance again.
     * Two things have to follow it, and both are why this is not simply a status
     * change — the wallet has to be owed the money again, or the business is
     * short by an amount nothing records; and the entries posted when it landed
     * have to be reversed, or both sets of books would show it as paid out.
     *
     * The wallet credit is deliberately *not* conditional on the business still
     * being under custody. Turning custody off does not make the business not
     * owed money that came back, and refusing the credit would leave the debt
     * unrecorded — the same reasoning as a refund taken after a payout.
     *
     * ## Claimed from every non-final status, and the claim is load-bearing
     *
     * A recall is the one thing a provider tells us about *later* that undoes
     * something it already told us: the transfer completed, and then a week on
     * the bank sent it back. So the row this arrives about is usually `paid`, and
     * claiming only `processing` meant the commonest reversal of all was
     * swallowed — the update matched nothing, the method returned at the guard,
     * and the caller reported the reversal as handled while the wallet still
     * showed the money gone and both sets of books still showed it paid out.
     *
     * `pending` is claimed too, and deliberately: {@see self::reconcile()} reads
     * pending rows back from the provider, so a row whose send never completed
     * can still come back as reversed, and leaving it out would strand its
     * reservation while the sweep resent it forever.
     */
    private function undo(Payout $payout, ?string $reason): void
    {
        $claimed = Payout::query()
            ->whereKey($payout->id)
            ->whereIn('status', [
                Payout::STATUS_PENDING,
                Payout::STATUS_PROCESSING,
                Payout::STATUS_PAID,
            ])
            ->update([
                'status' => Payout::STATUS_REVERSED,
                'reversed_at' => now(),
                'failure_reason' => $reason ? Str::limit($reason, 250, '') : null,
            ]);

        if ($claimed === 0) {
            return;
        }

        $business = $payout->business;

        if ($business instanceof Business) {
            $this->wallet->restoreRefusedPayout($business, (int) $payout->amount_kobo);
        }

        // And the sales are owed again. Claimed from `settled` as well as from
        // `reserved`, because a recall is the one thing the provider tells us
        // *after* telling us the money arrived — the row this usually arrives about
        // is already `paid`, and its allocations are already `settled`.
        $this->allocations->reverse($payout);

        // Both books, one key each, matching what `settle()` posted. Null when
        // the payout was reversed before it was ever settled, which is legitimate
        // and means there is nothing to unwind.
        $this->ledger->safe(fn () => $this->ledger->reverseByKey(
            (int) $payout->business_id,
            'payout:'.$payout->reference,
            'Payout returned '.$payout->reference,
            'payout_returned:'.$payout->reference,
        ));

        $this->ledger->safe(fn () => $this->ledger->reverseByKey(
            null,
            'custody_payout:'.$payout->reference,
            'Payout returned '.$payout->reference,
            'custody_payout_returned:'.$payout->reference,
        ));

        Log::warning('custody.payout.reversed', [
            'payout_id' => $payout->id,
            'reference' => $payout->reference,
            'business_id' => $payout->business_id,
            'amount_kobo' => (int) $payout->amount_kobo,
            'reason' => $reason,
        ]);

        // After the claim, and after the wallet is owed the money again — so the
        // balance in the message is the balance the business can actually see. This
        // is the alert a business most needs and was least likely to receive: the
        // money left, it appeared in their bank, and then it was recalled a week
        // later with nothing anywhere saying so.
        $this->notifier->payoutReversed($payout, $reason);
    }
}
