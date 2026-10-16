<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Models\Business;
use App\Services\Payments\Custody\PayoutRetryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AD-19 — the operator's retry, for a business the job has given up on.
 *
 * ## Why this exists, given "no admin route moves money"
 *
 * The rule this console was built on is that no admin route *pays anybody* — that
 * there is no lever which chooses an amount, a destination or a moment, because
 * every one of those is how a second way to move money appears. That rule is intact
 * and this is not an exception to it: the retry chooses none of the three. It
 * clears the platform's record of refusals and then dispatches the same
 * `SettlePayouts` job a collected sale dispatches, which sends what the wallet
 * already says is owed, to the account the business already named, with the amount
 * the wallet decides. Given a business that is owed nothing, is on hold, or names no
 * bank, it sends nothing.
 *
 * The rule as it was written assumed the platform retries forever — and it did,
 * on the old five-minute run, which is exactly why the ceiling in
 * `custody.retry_attempts` had to be added. With a ceiling there has to be a way
 * back over it, or a temporary provider outage becomes a permanent stop that only a
 * command line can lift. The amended rule is in ad12-money-config.php beside the
 * original, and {@see PayoutRetryService} carries the reasoning.
 *
 * ## Asynchronous, and the response says so
 *
 * This used to call the provider inline and answer with what it sent. It cannot any
 * more, because the attempt is a queued job now — the same one the collection path
 * dispatches, so that a retry gets the promotion step, the retries, the backoff and
 * the failure alert the original send had rather than a second, thinner path of its
 * own.
 *
 * So the answer is "queued", and the sentences below are written to be honest about
 * that rather than to imply a transfer happened: an operator whose provider is down
 * would otherwise read "sent" over a payout that had not been attempted yet, and the
 * difference matters most in precisely the outage this button exists for. What
 * happened is on the business's payout history a moment later, and pressing again is
 * cheap.
 *
 * Both actions are bounded by `custody.payout_batch`, and `retryAll` reports when it
 * stopped short of the whole set rather than silently doing part of it. Both are
 * gated by `admin.finance` and re-checked by {@see EnsuresPlatformAdmin}, and both
 * are audited by `AdminApiActivityLogger` on the route group.
 */
class PayoutRetryController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly PayoutRetryService $retries,
    ) {}

    /** Clear one business's refusals and queue an attempt. */
    public function retryBusiness(Request $request, Business $business): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $result = $this->retries->retryBusiness($business, $request->user());

        return $this->ok([
            'business_id' => (int) $business->id,
            'cleared' => $result['cleared'],
            'queued' => $result['queued'],
            'blocker' => $result['blocker'],
        ], $this->narrateOne($business, $result));
    }

    /** Retry every business whose payouts a ceiling has stopped. */
    public function retryAll(Request $request): JsonResponse
    {
        $this->authorizePlatformAdmin();

        $tally = $this->retries->retryAll($request->user());

        return $this->ok($tally, $this->narrateAll($tally));
    }

    /**
     * One sentence an operator can act on.
     *
     * Written here rather than in the console so the API and the button agree about
     * what happened — and because "queued" is a weaker claim than "sent", so it has
     * to be made in words. The old version narrated a `sent` count; there is no such
     * count any more, and inventing one from the tally would be the button telling an
     * operator that a payout had gone out when nothing had been attempted yet.
     *
     * @param  array{cleared: int, queued: bool, blocker: array{code: string, label: string}|null}  $result
     */
    private function narrateOne(Business $business, array $result): string
    {
        if ($result['blocker'] !== null) {
            return sprintf(
                'Nothing will be sent to %s: %s.',
                $business->name ?? 'this business',
                $result['blocker']['label'],
            );
        }

        return sprintf(
            'Queued an attempt for %s. The payout history will show what the provider said.',
            $business->name ?? 'this business',
        );
    }

    /**
     * The same sentence for a whole batch: what it queued, and what it left.
     *
     * @param  array<string, mixed>  $tally
     */
    private function narrateAll(array $tally): string
    {
        if ((int) $tally['retried'] === 0) {
            return 'No business is currently stopped by a payout ceiling.';
        }

        $sentence = sprintf(
            'Queued %d attempt(s). %d business(es) were left alone — a hold, a bank, or a ceiling they cannot clear.',
            $tally['queued'],
            $tally['blocked'],
        );

        if ($tally['truncated'] ?? false) {
            $sentence .= ' More businesses are waiting than one run handles — run it again.';
        }

        return $sentence;
    }
}
