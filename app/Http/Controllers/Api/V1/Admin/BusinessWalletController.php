<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Business;
use App\Models\Payout;
use App\Services\Payments\Custody\WalletSummaryService;
use App\Support\Money\Naira;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AD-19 — one business's money, from the platform's side.
 *
 * The business console is where an operator answers "what does this business
 * think we owe it, and where is that money", and until this endpoint there was no
 * way to ask: `BusinessResource` carries `can_connect_own_gateway` and nothing
 * about money, the platform queue lists only businesses the job would consider,
 * and the wallet itself was readable only through the management API — which
 * answers as the business, with its tenancy rules, and cannot be called on
 * somebody else's behalf.
 *
 * **The same read model, not a second one.** The figures are
 * {@see WalletSummaryService::forBusiness()} — the identical block the business
 * sees on its own wallet page, including `blocker` and `hold` — because an
 * operator and a business disagreeing about a balance during a support call is
 * worse than either of them being wrong alone. Recent payouts ride along so the
 * console answers "and did it arrive" without a second screen.
 *
 * **Read-only.** Nothing here pays, and nothing here lifts a hold: the holds
 * routes next door place and release. Money leaves the wallet from one service,
 * entered two ways — the queued `SettlePayouts` job a collected sale dispatches,
 * and the operator's retry — both through `InstantPayoutService::settle()` →
 * `PayoutSweepService::sweepBusiness()`, and this console only reads what they did:
 * `in_flight` included, which is how an operator sees a transfer a business says
 * never arrived. A console action that pushed one would be the second way to move
 * money this whole subsystem exists to delete.
 *
 * The console draws a **Retry** beside this read, and it posts to
 * `PayoutRetryController`, not here — the same separation as the wallet figure,
 * which this controller only reports. That route is deliberately not a payout: it
 * chooses no amount, destination or moment, and dispatches the same `SettlePayouts`
 * job a collected sale dispatches, which sends what the wallet above already says
 * is owed. The amended rule is at `ad12-money-config.php`.
 *
 * Open holds are *not* repeated here. They carry their own ids, sources and
 * placement records, and `GET admin/payout-holds` already returns exactly that
 * for the whole platform — a store hold included, since the row records the
 * business it belongs to. Sending a second, thinner copy of them from here would
 * give the operator two lists of holds that could disagree.
 */
class BusinessWalletController extends ApiController
{
    use EnsuresPlatformAdmin;

    /**
     * How many transfers the console shows. Enough to see the rhythm of payout
     * and catch a stuck one; the full history is the business's own payouts list,
     * which paginates.
     */
    private const RECENT_PAYOUTS = 10;

    public function __construct(
        private readonly WalletSummaryService $wallet,
    ) {}

    public function show(Request $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // The walk reads the business's payout bank to decide the blocker, and the
        // wallet block's hold is scoped to it — one eager load rather than a query
        // per figure.
        $business->loadMissing('payoutBank');

        $payouts = Payout::query()
            ->where('business_id', $business->id)
            // One aggregate, not a query per row — the same count the business's
            // own list shows, so an operator and the owner read one number.
            ->withCount('allocations')
            ->orderByDesc('id')
            ->limit(self::RECENT_PAYOUTS)
            ->get();

        return $this->ok([
            'wallet' => $this->wallet->forBusiness($business),
            'payouts' => $payouts->map(fn (Payout $payout) => $this->payoutRow($payout))->values()->all(),
        ]);
    }

    /**
     * A transfer, as the console needs it: where it went, how much, and how it
     * ended.
     *
     * Deliberately smaller than the management payout row — no destination digits
     * (the business's own payouts screen is the place that answers "which account
     * did it go to", and an operator has no reason to read somebody's account
     * number to answer a support question), and no allocation lines, which are a
     * per-payout second query. The allocation *count* is here because it is one
     * aggregate for the whole page and it is what tells a grouped payout from a
     * single sale. What is here is what the console draws.
     *
     * @return array<string, mixed>
     */
    private function payoutRow(Payout $payout): array
    {
        return [
            'id' => (int) $payout->id,
            'reference' => $payout->reference,
            'status' => $payout->status,
            'currency' => $payout->currency,
            'amount' => Naira::floatFromKobo((int) $payout->amount_kobo),
            'amount_kobo' => (int) $payout->amount_kobo,
            'fee' => Naira::floatFromKobo($payout->feeKobo()),
            'fee_kobo' => $payout->feeKobo(),
            'net' => Naira::floatFromKobo($payout->netKobo()),
            'net_kobo' => $payout->netKobo(),
            'sales_count' => (int) ($payout->allocations_count ?? 0),
            'store_id' => $payout->store_id !== null ? (int) $payout->store_id : null,
            'failure_reason' => $payout->failure_reason,
            'requested_at' => $payout->requested_at?->toISOString(),
            'sent_at' => $payout->sent_at?->toISOString(),
            'paid_at' => $payout->paid_at?->toISOString(),
            'failed_at' => $payout->failed_at?->toISOString(),
            'reversed_at' => $payout->reversed_at?->toISOString(),
            'created_at' => $payout->created_at?->toISOString(),
        ];
    }
}
