<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Services\Payments\Custody\WalletSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AD-19 — who is owed what, who cannot take it, and what is held.
 *
 * The screen an operator opens during a money incident, and the reason this whole
 * subsystem is operable rather than merely correct. Before it there was no way to
 * ask, from the product, how much Storify owes across all businesses, whose money
 * is stuck and why, or what a hold is doing to a given business's figure.
 *
 * **Read-only, and there is no "run it now" beside it.** That refusal is recorded
 * in full at `ad12-money-config.php`, where the three withdrawn admin payout routes
 * are described: every payout now moves Storify's own balance, and a second way to
 * move money is the shape of bug this change exists to delete. What an operator
 * needs during an incident is to *see* the queue and to place a hold — the hold is
 * the next route file over.
 *
 * **What stands beside it is a retry, not a payout.** `POST admin/payouts/retry`
 * and its per-business twin in `PayoutRetryController` re-attempt sends the retry
 * ceiling has stopped, and they are not a "run it now": they choose no amount, no
 * destination and no moment, and dispatch the same `SettlePayouts` job a collected
 * sale dispatches. The amended rule is at `ad12-money-config.php`; what it buys is
 * that a ceiling added to stop a retry storm is not a trapdoor for the business
 * behind it.
 *
 * It is also not the only thing that pays, and the queue says so. The queued
 * `SettlePayouts` job a collected sale dispatches and the operator's retry both go
 * through `InstantPayoutService::settle()` →
 * `PayoutSweepService::sweepBusiness()`; the payload's `in_flight` block is the
 * transfers between them that nobody has confirmed yet. That block is here
 * because the queue alone cannot show them: `candidates()` lists businesses holding
 * money, and a business whose money is entirely reserved holds none, so a transfer
 * stuck unanswered by the provider would be absent from the one screen an operator
 * opens when a business says its payout never arrived.
 *
 * The `stuck_kobo` total is the other number this screen exists for: the sum of
 * every business's available balance that nothing will send. A healthy platform has
 * a small one and an explainable per-business `blocker` against each contributor;
 * an outage is that figure growing with the reasons attached.
 */
class PayoutQueueController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly WalletSummaryService $wallet,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        return $this->ok(['queue' => $this->wallet->platformQueue()]);
    }
}
