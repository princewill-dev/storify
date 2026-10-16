<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\PayoutHold;
use App\Models\Store;
use App\Services\Payments\Custody\PayoutHoldService;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Lifts a payout hold, and says what that is about to pay.
 *
 * Separate from {@see HoldPayouts} rather than a `--release` flag, because the two
 * print different things: placing a hold has one thing to say ("money keeps being
 * collected"), and lifting one has to say what is about to leave. A flag would have
 * made one of those two messages conditional and the other half-written.
 *
 * ## What it tells you before you do it
 *
 * How much is sitting behind the hold. A hold is not a wallet operation, so
 * everything collected while it was in force is still there and goes out the moment
 * it lifts — which is the intended behaviour and also the reason this command prints
 * the figure first. Lifting a hold on a business whose balance has been growing for a
 * week is a decision worth making with the number in front of you.
 *
 * ## `--source` is how a suspension is lifted without touching anything else
 *
 * Suspending a business or a store places a hold it owns. Reactivating releases it
 * automatically, and this command with `--source=suspension` does the same thing by
 * hand. Without it, everything open is lifted — including a hold a different person
 * placed for a different reason, which is why the narrower form exists.
 */
class ReleasePayouts extends Command
{
    protected $signature = 'custody:release
                            {--system : Lift the platform-wide hold}
                            {--business= : Business id or business code}
                            {--store= : Store id}
                            {--source= : Lift only holds from this source (`manual` or `suspension`)}
                            {--dry-run : Report what is held and what lifting it would pay}';

    protected $description = 'Lift a payout hold and resume paying the money that accumulated behind it';

    public function handle(PayoutHoldService $holds, PayoutSweepService $payouts): int
    {
        $scope = $this->scope();

        if ($scope === null) {
            $this->error('Give exactly one of --system, --business or --store. Run `custody:hold` with no options to see what is held.');

            return self::FAILURE;
        }

        $source = $this->option('source');

        if ($source !== null && ! in_array($source, [PayoutHold::SOURCE_MANUAL, PayoutHold::SOURCE_SUSPENSION], true)) {
            $this->error('--source must be `manual` or `suspension`.');

            return self::FAILURE;
        }

        $subject = match ($scope) {
            'business' => $this->business(),
            'store' => $this->store(),
            default => null,
        };

        $open = $this->openFor($holds, $scope, $subject, $source);

        if ($open->isEmpty()) {
            $this->info(sprintf(
                'Nothing is held on %s%s. Nothing to lift.',
                $scope === 'system' ? 'the platform' : $scope.' '.($subject?->id),
                $source !== null ? ' from `'.$source.'`' : '',
            ));

            return self::SUCCESS;
        }

        $this->report($open, $subject, $payouts);

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run — nothing was changed.');

            return self::SUCCESS;
        }

        $actor = Auth::user();

        $released = match ($scope) {
            'system' => $holds->releaseSystem($actor),
            'business' => $holds->releaseBusiness($subject, $actor, $source),
            'store' => $holds->releaseStore($subject, $actor, $source),
        };

        Log::warning('custody.hold.released_from_console', [
            'scope' => $scope,
            'business_id' => $subject?->business_id ?? $subject?->id,
            'store_id' => $scope === 'store' ? $subject?->id : null,
            'source' => $source,
            'holds' => $released,
            'actor_user_id' => $actor?->id,
        ]);

        $this->info(sprintf('<info>Lifted</info> %d hold(s).', $released));
        $this->line('A payout attempt is queued — lifting the hold is what sends, and nothing else would have noticed.');

        return self::SUCCESS;
    }

    /**
     * What lifting this would pay.
     *
     * Read from the wallet rather than summed from anything, because the wallet is
     * the authority on what is owed: a hold never touched it, so the figure here is
     * exactly what the lift is about to send.
     *
     * @param  Collection<int, PayoutHold>  $open
     */
    private function report($open, ?Business $business, PayoutSweepService $payouts): void
    {
        $this->table(
            ['id', 'scope', 'over', 'source', 'since', 'reason'],
            $open->map(fn (PayoutHold $hold) => [
                $hold->id,
                $hold->scope,
                $hold->describe(),
                $hold->source,
                $hold->placed_at?->toDateTimeString(),
                $hold->reason,
            ])->all(),
        );

        // A system hold is reported per business rather than as one figure, because
        // it is the widest one and the number that matters is the platform's whole
        // outstanding liability — which is what stops it being lifted casually.
        if ($business === null) {
            $candidates = $payouts->candidates();

            $this->line(sprintf(
                '  %d business(es) hold a payable balance worth %s NGN in total.',
                $candidates->count(),
                number_format((int) $candidates->sum(fn ($b) => (int) $b->available_balance) / 100, 2),
            ));

            return;
        }

        $wallet = (int) $business->walletBalance();

        $this->line(sprintf(
            '  %s is owed %s NGN in its wallet, and every naira of it becomes payable the moment this lifts.',
            $business->name,
            number_format($wallet / 100, 2),
        ));

        if ($open->contains(fn (PayoutHold $hold) => $hold->scope === PayoutHold::SCOPE_STORE)) {
            // The store scope is the one where lifting changes the *shape* of the
            // payment rather than just starting it, and where a reader could
            // reasonably mistake the wallet figure for the amount sent. It is not:
            // another open store hold still withholds its own share.
            $business->loadMissing('stores');

            $held = $open->where('scope', PayoutHold::SCOPE_STORE)->pluck('store_id')->map(fn ($id) => (int) $id)->all();

            $this->warn(sprintf(
                '  Lifting a store hold does not pay the whole wallet — the business is paid for every sale that '
                .'is not from store(s) %s, and the rest is paid when those lift too.',
                implode(', ', $held),
            ));
        }
    }

    /**
     * The holds this invocation would lift, read with the same narrowings the
     * service will apply — so what is reported is what happens.
     *
     * @return Collection<int, PayoutHold>
     */
    private function openFor(PayoutHoldService $holds, string $scope, ?object $subject, ?string $source)
    {
        return $holds->openHolds()
            ->where('scope', $scope)
            ->when($scope === 'business', fn ($open) => $open->where('business_id', (int) $subject->id))
            ->when($scope === 'store', fn ($open) => $open->where('store_id', (int) $subject->id))
            ->when($source !== null, fn ($open) => $open->where('source', $source))
            ->values();
    }

    private function scope(): ?string
    {
        $chosen = array_values(array_filter([
            $this->option('system') ? 'system' : null,
            filled($this->option('business')) ? 'business' : null,
            filled($this->option('store')) ? 'store' : null,
        ]));

        return count($chosen) === 1 ? $chosen[0] : null;
    }

    private function business(): Business
    {
        $needle = (string) $this->option('business');

        $business = Business::query()
            ->when(is_numeric($needle), fn ($query) => $query->where('id', (int) $needle))
            ->when(! is_numeric($needle), fn ($query) => $query->where('business_code', $needle))
            ->first();

        return $business ?? throw new \RuntimeException("No business matches \"{$needle}\".");
    }

    private function store(): Store
    {
        $needle = (string) $this->option('store');

        $store = Store::query()->find((int) $needle);

        return $store ?? throw new \RuntimeException("No store matches \"{$needle}\".");
    }
}
