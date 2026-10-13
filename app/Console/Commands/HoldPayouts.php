<?php

namespace App\Console\Commands;

use App\Models\Business;
use App\Models\Store;
use App\Services\Payments\Custody\PayoutHoldService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Stops payouts while somebody looks into something, at the width they need.
 *
 * The gap this fills was not a missing feature but a missing way in: the only
 * switch that stopped a payout was `businesses.auto_payout`, and nothing in the
 * application could set it. An operator who needed to hold a business's money had
 * to edit the database, and had nowhere to write down why or to remember that they
 * had. This is the way in, and the reason is required rather than optional for
 * that second reason — a hold nobody can explain is a hold nobody lifts.
 *
 * ## Three widths, because the questions are different
 *
 * `--system` is the incident switch: something is wrong with the rail, or with the
 * provider, and every payout on the platform should stop at once. `--business`
 * holds one business's money across all of its stores. `--store` withholds that
 * one store's share and **keeps paying the business for everything else**, which
 * is the agreed behaviour and the reason the narrowest width exists at all.
 *
 * ## What it does not do
 *
 * It does not stop the business being charged, and it does not touch a balance.
 * Money keeps landing in the wallet and stays visible there; the sweep simply
 * skips it. So a hold is reversible by doing nothing but lifting it — the next run
 * pays everything that accumulated — and a payout already with the provider still
 * resolves, because reconciliation does not read holds.
 *
 * ## With no options it lists, and changes nothing
 *
 * The question "is anything held right now" is asked more often than "hold this",
 * and the answer has to be reachable without knowing an id. Running the command
 * bare answers it.
 */
class HoldPayouts extends Command
{
    protected $signature = 'custody:hold
                            {--system : Hold every payout on the platform}
                            {--business= : Business id or business code}
                            {--store= : Store id, whose share is withheld while the rest is paid}
                            {--reason= : Why. Required when placing a hold}';

    protected $description = 'Stop payouts on the platform, a business, or a single store';

    public function handle(PayoutHoldService $holds): int
    {
        $scope = $this->scope();

        if ($scope === null) {
            return $this->list($holds);
        }

        $reason = trim((string) ($this->option('reason') ?? ''));

        if ($reason === '') {
            // Refused rather than defaulted. The reason is the only thing that
            // tells the next person this was deliberate, and a placeholder would
            // say "somebody held this" without saying who or why.
            $this->error('A reason is required. Pass --reason="..." so the hold can be explained later.');

            return self::FAILURE;
        }

        // `Auth::user()` rather than an actor argument: a console run by a person
        // through a login leaves their id, and a cron or a deploy script leaves
        // null — which is the honest record of a hold nobody signed.
        $actor = Auth::user();

        $hold = match ($scope) {
            'system' => $holds->holdSystem($reason, $actor),
            'business' => $holds->holdBusiness($this->business(), $reason, $actor),
            'store' => $holds->holdStore($this->store(), $reason, $actor),
        };

        Log::warning('custody.hold.placed_from_console', [
            'hold_id' => $hold->id,
            'scope' => $hold->scope,
            'business_id' => $hold->business_id,
            'store_id' => $hold->store_id,
            'reason' => $reason,
            'actor_user_id' => $actor?->id,
        ]);

        $this->info(sprintf(
            '<info>Held</info> %s%s.',
            $hold->describe(),
            $hold->wasRecentlyCreated ? '' : ' (already held — nothing changed)',
        ));

        $this->line('  '.$reason);
        $this->newLine();
        $this->line('Money keeps being collected and the wallet keeps growing; only the payout is stopped.');
        $this->line('Lift it with: custody:release '.$this->releaseHint($hold->scope));

        return self::SUCCESS;
    }

    /**
     * Which width was asked for, or null when none was and the command should list.
     *
     * Exactly one. A `--system --business=7` that silently held the platform is the
     * kind of surprise this subsystem cannot afford, so an ambiguous invocation is
     * refused rather than resolved.
     */
    private function scope(): ?string
    {
        $chosen = array_values(array_filter([
            $this->option('system') ? 'system' : null,
            filled($this->option('business')) ? 'business' : null,
            filled($this->option('store')) ? 'store' : null,
        ]));

        if (count($chosen) > 1) {
            $this->error('Give exactly one of --system, --business or --store. Got: '.implode(', ', $chosen).'.');

            return null;
        }

        return $chosen[0] ?? null;
    }

    private function list(PayoutHoldService $holds): int
    {
        $open = $holds->openHolds();

        if ($open->isEmpty()) {
            $this->info('Nothing is held. Every custody business is being paid as normal.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'scope', 'over', 'since', 'by', 'source', 'reason'],
            $open->map(fn ($hold) => [
                $hold->id,
                $hold->scope,
                $hold->describe(),
                $hold->placed_at?->toDateTimeString(),
                $hold->placed_by ?? '—',
                $hold->source,
                $hold->reason,
            ])->all(),
        );

        $this->warn(sprintf('%d payout hold(s) are in force. Lifting one pays out whatever accumulated.', $open->count()));

        return self::SUCCESS;
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

    private function releaseHint(string $scope): string
    {
        return match ($scope) {
            'system' => '--system',
            'business' => '--business='.$this->option('business'),
            default => '--store='.$this->option('store'),
        };
    }
}
