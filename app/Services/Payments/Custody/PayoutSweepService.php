<?php

namespace App\Services\Payments\Custody;

use App\Models\Business;
use App\Models\Payout;
use App\Services\Accounting\LedgerPostingService;
use App\Services\Payments\Data\GatewayCredentials;
use App\Services\Payments\Payouts\PayoutDestinationRef;
use App\Services\Payments\Payouts\PayoutRail;
use App\Services\Payments\Payouts\PayoutRailRegistry;
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
 * ## It is the only rail, and it is reached three ways
 *
 * `connect:payouts` used to run beside this, paying a business's connected
 * account out of the provider's own per-business balance. Both the account and
 * that command are gone, so this is now the whole of how money reaches a
 * business — which makes it worth naming the ways it is entered, because a row
 * that only one of them can finish is a row that strands:
 *
 * | Entered by | Finishes |
 * |---|---|
 * | {@see self::sweep()} — the schedule | money that became payable since the last run |
 * | {@see self::reconcile()} — the schedule | a row whose send never returned, or whose webhook never arrived |
 * | {@see self::applyPayoutWebhook()} — the provider | a row whose outcome the provider reports directly |
 *
 * The three deliberately meet in the same private methods. `settle()` claims its
 * row by condition precisely because two of them can be looking at it at the same
 * moment, and the ledger keys are what make the second arrival a no-op rather
 * than a second posting.
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
     * @return array{budget: int|null, floor: CarbonInterface|null, measured: bool}
     */
    public function promotionGate(): array
    {
        if (! (bool) config('custody.balance_gate.enabled', true)) {
            return ['budget' => null, 'floor' => null, 'measured' => false];
        }

        $context = $this->context();

        if ($context === null) {
            // No processor is selected, or the one selected cannot pay out, so
            // there is no payout for this gate to protect — the sweep would refuse
            // before it sent anything. Applying the floor here would only delay a
            // business's balance from maturing over a payout that could not happen
            // anyway, and make a misconfigured processor look like slow settlement.
            return ['budget' => null, 'floor' => null, 'measured' => false];
        }

        $available = $context['rail']->availableBalance($context['credentials']);

        if ($available === null) {
            $floor = now()->subHours((int) config('custody.default_hours', 72));

            Log::warning('custody.promote.balance_unreadable', [
                'provider' => $context['provider'],
                'floor' => $floor->toIso8601String(),
            ]);

            return ['budget' => null, 'floor' => $floor, 'measured' => false];
        }

        $liability = (int) Business::query()->sum('available_balance');

        // Reserved and still in flight: the money has left the wallet but has not
        // yet left the provider's balance, so it is spoken for twice if this does
        // not subtract it.
        $inFlight = (int) Payout::query()
            ->whereIn('status', [Payout::STATUS_PENDING, Payout::STATUS_PROCESSING])
            ->sum('amount_kobo');

        $headroom = (int) config('custody.payout_fee_headroom_kobo', 0);

        return [
            'budget' => max(0, $available - $liability - $inFlight - $headroom),
            'floor' => null,
            'measured' => true,
        ];
    }

    /**
     * Businesses holding payable money, whatever their switch says.
     *
     * Not filtered on `auto_payout` or on having a bank, deliberately: the sweep
     * has to be able to *say* that a business is holding money it cannot receive,
     * and a query that silently excluded it would make "we are holding ₦40,000 for
     * a business with no bank account" invisible until someone complained.
     *
     * **Not filtered on `custody_enabled` either, and that one used to be.** The
     * flag decides whether a business is *charged*, so it is a fact about the
     * future; `available_balance` is money already collected into Storify's own
     * provider balance, which is a fact about the past. `custody:disable` is
     * available at any moment and exists to be reached for in a hurry, so filtering
     * here meant the one command an operator runs during an incident also silently
     * stopped every payout that business was owed — and printed a warning telling
     * them to pay it by hand, with no tool left to pay it. The balance is only ever
     * raised by {@see CustodyService::promoteSettled()}, which only ever matures a
     * sale Storify collected, so a balance is proof of collection on its own.
     *
     * @return Collection<int, Business>
     */
    public function candidates(): Collection
    {
        return Business::query()
            ->where('available_balance', '>', 0)
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
    public function destinationIsExhausted(Business $business): bool
    {
        $ceiling = $this->maxDestinationFailures();
        $bank = $business->payoutBank;

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
    public function destinationFailures(Business $business): int
    {
        $bank = $business->payoutBank;

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
     * the next run sizes the destination up again from nothing.
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
     * What resumes is the *next* sweep, which sees a destination with no failures
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
        $lastPaidAt = Payout::query()
            ->where('business_id', $business->id)
            ->where('status', Payout::STATUS_PAID)
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
     * @return array{sent: int, held: int, on_hold: int, skipped: int, failed: int, exhausted: int}
     */
    public function sweep(): array
    {
        $counts = ['sent' => 0, 'held' => 0, 'on_hold' => 0, 'skipped' => 0, 'failed' => 0, 'exhausted' => 0];
        $context = $this->context();

        if ($context === null) {
            return $counts;
        }

        foreach ($this->candidates() as $business) {
            // A hold is checked first, and it is the one check here that is a
            // *person's* decision rather than a fact about the business. An
            // operator looking into something has to be able to see that their
            // hold is what stopped the payment — "automatic payouts off" against
            // a business whose owner never touched the setting is a log line that
            // sends somebody looking for a bug.
            //
            // This is the only place a hold is read, which is what makes it safe:
            // it cannot stop a charge (the charge path never calls this), it
            // cannot clear or zero what a business is owed (no wallet mutator
            // reads it), and it cannot strand a row already with the provider
            // ({@see self::reconcile()} and {@see self::applyPayoutWebhook()}
            // never read it, so an in-flight payout still resolves while held).
            $hold = $this->holds->holdOn($business);

            if ($hold !== null) {
                $counts['on_hold']++;

                Log::warning('custody.payout.on_hold', [
                    'business_id' => $business->id,
                    'hold_id' => $hold->id,
                    'scope' => $hold->scope,
                    'source' => $hold->source,
                    'reason' => $hold->reason,
                    'available_kobo' => (int) $business->available_balance,
                ]);

                continue;
            }

            // Then `auto_payout` off, which stays exactly the meaning it has always
            // had: the money rests in the wallet until the business asks for it.
            // Beside a hold rather than replaced by it — one is a preference and
            // the other is a decision made about the business by somebody else.
            if (! $business->auto_payout) {
                $counts['held']++;

                Log::info('custody.payout.held', [
                    'business_id' => $business->id,
                    'available_kobo' => (int) $business->available_balance,
                ]);

                continue;
            }

            $bank = $business->payoutBank;

            if (! $bank || blank($bank->account_number) || blank($bank->bank_code)) {
                $counts['skipped']++;

                Log::warning('custody.payout.no_destination', [
                    'business_id' => $business->id,
                    'available_kobo' => (int) $business->available_balance,
                    'has_bank' => (bool) $bank,
                ]);

                continue;
            }

            // Counted apart from `held` and from `failed`, because it is neither:
            // nothing was attempted and nothing is owed the business by a person,
            // but the destination will not work until somebody changes it or lifts
            // the ceiling. Its own number in the run's output is what stops this
            // being a business that quietly never gets paid.
            if ($this->destinationIsExhausted($business)) {
                $counts['exhausted']++;

                Log::warning('custody.payout.destination_exhausted', [
                    'business_id' => $business->id,
                    'available_kobo' => (int) $business->available_balance,
                    'bank_code' => (string) $bank->bank_code,
                    'ceiling' => $this->maxDestinationFailures(),
                ]);

                continue;
            }

            $payable = $this->payableFor($business);

            $plan = $payable['plan'];
            $amountKobo = $payable['payable_kobo'];
            $capKobo = $payable['available_kobo'];
            $heldStoreIds = $payable['held_store_ids'];

            // Nothing to send, and there is only one way to get here with money
            // still owed: the whole of the balance belongs to held stores. Counted
            // as `on_hold` rather than skipped, because "skipped" reads as "another
            // run took it", and this is a deliberate withholding that will not
            // resolve until somebody lifts it.
            if ($amountKobo <= 0) {
                if ($heldStoreIds !== [] && $capKobo > 0) {
                    $counts['on_hold']++;
                }

                continue;
            }

            $payout = $this->reserve($business, $amountKobo, $context['provider'], $plan);

            // Not a failure. The overwhelmingly likely reason is that a concurrent
            // run took the money first, which is the reservation doing its job.
            if ($payout === null) {
                $counts['skipped']++;

                continue;
            }

            $counts[$this->dispatch($payout, $context)]++;
        }

        return $counts;
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
    public function payableFor(Business $business): array
    {
        $heldStoreIds = $this->holds->heldStoreIds($business);
        $capKobo = $business->payoutableBalance();

        $plan = $this->allocations->plan($business, $capKobo, $heldStoreIds);
        $amountKobo = $this->allocations->total($plan);

        $withheldKobo = 0;
        $gapKobo = 0;

        if ($amountKobo < $capKobo && $heldStoreIds !== []) {
            $fullKobo = $this->allocations->total(
                $this->allocations->plan($business, $capKobo),
            );

            $withheldKobo = max(0, $fullKobo - $amountKobo);
            $gapKobo = max(0, $capKobo - $fullKobo);

            Log::warning('custody.payout.store_held', [
                'business_id' => $business->id,
                'store_ids' => $heldStoreIds,
                'available_kobo' => $capKobo,
                'payable_kobo' => $amountKobo,
                'withheld_kobo' => $withheldKobo,
                'unattributed_kobo' => $gapKobo,
                'reasons' => $this->holds->storeHolds($business)->pluck('reason', 'store_id')->all(),
            ]);

            $amountKobo += $gapKobo;
        } elseif ($amountKobo < $capKobo) {
            $gapKobo = $capKobo - $amountKobo;
            $amountKobo = $capKobo;
        }

        if ($gapKobo > 0) {
            // Logged as the same shortfall it would be without a hold — a hold does
            // not get to hide money that no sale can account for.
            Log::warning('custody.payout.allocation_short', [
                'business_id' => $business->id,
                'available_kobo' => $capKobo,
                'attributed_kobo' => $amountKobo - $gapKobo,
                'gaped_kobo' => $gapKobo,
            ]);
        }

        return [
            'plan' => $plan,
            'payable_kobo' => $amountKobo,
            'available_kobo' => $capKobo,
            'held_store_ids' => $heldStoreIds,
            'withheld_kobo' => $withheldKobo,
            'unattributed_kobo' => $gapKobo,
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
    private function reserve(Business $business, int $amountKobo, string $provider, Collection $plan): ?Payout
    {
        $bank = $business->payoutBank;

        if (! $bank) {
            return null;
        }

        try {
            return DB::transaction(function () use ($business, $bank, $amountKobo, $provider, $plan) {
                $payout = Payout::reserve(
                    (int) $business->id,
                    null,
                    $provider,
                    (string) $bank->account_number,
                    (string) $bank->bank_code,
                    $bank->account_name,
                    $amountKobo,
                    $this->currency(),
                );

                // Refuses to overdraw, which is the point: if a refund or another
                // run has taken this money since `candidates()` read it, this
                // throws and the row above is rolled back with it.
                $this->wallet->releaseForPayout($business, $amountKobo);

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
            Log::error('custody.payout.send_threw', [
                'payout_id' => $payout->id,
                'reference' => $payout->reference,
                'error' => $e->getMessage(),
            ]);

            $outcome = TransferOutcome::indeterminate($e->getMessage());
        }

        return $this->apply($payout, $outcome);
    }

    /**
     * The destination to send to, registered on first use and reused after.
     *
     * Read from the row rather than the business, because a payout already in
     * flight has to keep going where it was reserved for even if the business
     * changes its bank in the meantime — the row is the snapshot, and this is one
     * of the two places that depends on it.
     *
     * Returns null and fails the payout when the provider will not accept the
     * destination. That releases the reservation, and it is safe to: no money has
     * moved, and a destination is not a transfer. The business keeps its balance
     * and the next sweep tries again once somebody fixes the account.
     *
     * @param  array{provider: string, rail: PayoutRail, credentials: GatewayCredentials}  $context
     */
    private function destination(Payout $payout, array $context): ?PayoutDestinationRef
    {
        if (filled($payout->destination_id)) {
            return PayoutDestinationRef::registered((string) $payout->destination_id, true);
        }

        try {
            $destination = $context['rail']->ensureDestination(
                $context['credentials'],
                (string) $payout->account_number,
                (string) $payout->bank_code,
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

        return $destination;
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
            'failure_reason' => $reason,
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
        // rather than in {@see self::sweep()} on purpose — the sweep runs every
        // five minutes, so a check there would re-fire on every pass and the
        // crossing, which is the one moment worth a message, would be
        // indistinguishable from the silence that follows it.
        if ($this->destinationIsExhausted($business)) {
            $this->notifier->destinationBlocked($business, $payout, $this->destinationFailures($business));
        }
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
     * One `processing` payout, read back from the provider.
     *
     * @param  array{provider: string, rail: PayoutRail, credentials: GatewayCredentials}  $context
     * @return string the count to increment
     */
    private function resolve(Payout $payout, array $context): string
    {
        try {
            $outcome = $context['rail']->lookUp(
                $context['credentials'],
                (string) $payout->reference,
                $payout->provider_payout_id,
            );
        } catch (\Throwable $e) {
            Log::warning('custody.payout.lookup_failed', [
                'payout_id' => $payout->id,
                'error' => $e->getMessage(),
            ]);

            return 'unresolved';
        }

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
