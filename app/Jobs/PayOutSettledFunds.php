<?php

namespace App\Jobs;

use App\Services\Payments\Connect\PlatformPayoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Withdraw a business's settled money, away from the webhook's ten seconds.
 *
 * The provider allows ten seconds for a 2xx on a delivery and treats anything
 * else as a failure to retry. Withdrawing makes three provider calls of its own
 * — read the account, read its balance, create the payout — and doing that
 * inside the request is how a delivery that had already settled a sale gets
 * retried and arrives a second time. Queued, it is acknowledged immediately.
 *
 * **Best effort, and it does not throw.** The usual outcome is "nothing has
 * settled yet": a charge lands in the provider's `pending_balance` and only
 * becomes movable on the provider's own schedule. The hourly `connect:payouts`
 * sweep is the real mechanism — this only shortens the wait when the funds were
 * already available — so a failure here is logged and left to the sweep rather
 * than retried on the queue, where a permanently unreachable provider would
 * fill the table with copies of the same attempt.
 */
class PayOutSettledFunds implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $businessId,
        public readonly ?int $storeId = null,
    ) {}

    public function handle(PlatformPayoutService $payouts): void
    {
        // The admin's switch, and the same one the sweep reads. This job is
        // dispatched after a sale settles, which is precisely the moment a held
        // business's money must *not* leave — so the check has to be here and
        // not only in the sweep.
        if (! $payouts->autoPayoutEnabled($this->businessId)) {
            Log::info('bachs.connect.payout_held', [
                'business_id' => $this->businessId,
                'store_id' => $this->storeId,
            ]);

            return;
        }

        try {
            $result = $payouts->payOut($this->businessId, $this->storeId);

            Log::info('bachs.connect.payout_after_settlement', [
                'business_id' => $this->businessId,
                'store_id' => $this->storeId,
                'outcome' => $result->outcome,
                'message' => $result->message,
            ]);
        } catch (Throwable $e) {
            // The sale is settled and the customer has paid; a payout that could
            // not be attempted is the sweep's problem, not a reason to fail
            // anything and make the provider send the delivery again.
            Log::error('bachs.connect.payout_after_settlement_failed', [
                'business_id' => $this->businessId,
                'store_id' => $this->storeId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
