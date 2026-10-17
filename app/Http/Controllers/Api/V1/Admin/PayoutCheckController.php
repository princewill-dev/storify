<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\Admin\Concerns\EnsuresPlatformAdmin;
use App\Http\Controllers\Api\V1\ApiController;
use App\Jobs\CheckPayoutStatus;
use App\Models\Payout;
use App\Services\Payments\Custody\PayoutSweepService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * AD-19 — "Check now": ask the provider what happened to one transfer.
 *
 * ## Why this exists
 *
 * A transfer the provider accepts and then never follows up on is now chased
 * automatically — see {@see CheckPayoutStatus}, which asks at five seconds
 * and then on a widening ladder for about two hours, and mails the admins when the
 * ladder runs out. This is the operator's version of that question: the same ask,
 * on the row they are looking at, answered while they wait.
 *
 * ## Why it answers synchronously, when nothing else here does
 *
 * This is the one place in this console where a provider request happens on the
 * request thread, and it is a deliberate break rather than an oversight. The rule it
 * bends is about the *collection* path — a customer's checkout or a provider's webhook
 * must not pay for a provider round trip — and an operator pressing a button is neither.
 * The call is one `lookUp()`, bounded, idempotent, gated by `admin.finance`, re-checked
 * by {@see EnsuresPlatformAdmin}, and audited by `AdminApiActivityLogger`.
 *
 * The alternative is worse than the deviation. Dispatching the ladder from here would
 * work, and would give the operator no answer — and it would also start a **second
 * chain** on a payout that already has one, so a transfer nobody had answered about
 * would produce two "unconfirmed" emails instead of one. Asking directly is both more
 * useful and less noisy.
 *
 * ## The row is not read-only
 *
 * "No route here pays anybody" survives, because this cannot *initiate* a transfer: it
 * chooses no amount, no destination and no moment. But it is a write to the books,
 * and that is worth saying plainly — a provider answer of `completed` settles the
 * payout and posts to both ledgers, and an answer of `failed` or `reversed` credits the
 * business's wallet back. What it can never do is send the same money twice, or send
 * money the wallet does not say is owed.
 *
 * A row that is no longer `processing` is answered without asking the provider at all:
 * somebody — a webhook, the reconciler, this button a moment ago — has already settled
 * it, and re-asking could only put a stale answer against a finished row.
 */
class PayoutCheckController extends ApiController
{
    use EnsuresPlatformAdmin;

    public function __construct(
        private readonly PayoutSweepService $sweep,
    ) {}

    /** Ask the provider about one transfer and write the row from the answer. */
    public function check(Request $request, Payout $payout): JsonResponse
    {
        $this->authorizePlatformAdmin();

        // Checked before the call rather than after, so an operator pressing the button
        // on a finished row costs nothing and is told why. The service enforces the same
        // rule — this is the version that can explain itself without a provider request.
        if ($payout->status !== Payout::STATUS_PROCESSING) {
            return $this->ok(
                $this->row($payout, checked: false),
                sprintf(
                    'Nothing to check: this transfer is already %s. It was not sent to the provider again.',
                    $payout->status,
                ),
            );
        }

        $result = $this->sweep->checkPayout($payout);

        // Re-read rather than trusting the in-memory row: `settle()`, `fail()` and
        // `undo()` write with a conditional query, so the status the operator is told
        // about is the one in the table, not one this request assumed.
        return $this->ok(
            $this->row($payout->fresh(), checked: true, result: $result),
            $this->narrate($payout->fresh(), $result),
        );
    }

    /**
     * One sentence an operator can act on, composed here so the API and the button
     * agree about what happened — the console prints it verbatim.
     *
     * Each result gets its own words because they mean different things to the person
     * reading them: `settled` is resolved, `failed` and `reversed` are resolved *and*
     * the money is visibly back, and `unresolved` is the one case where nothing changed
     * and the honest thing to say is that we are still asking.
     */
    private function narrate(?Payout $payout, string $result): string
    {
        if (! $payout instanceof Payout) {
            return 'The transfer could not be read back after the check.';
        }

        $money = sprintf('%s %s', $payout->currency ?: config('custody.currency', 'NGN'), number_format(((int) $payout->amount_kobo) / 100, 2));

        return match ($result) {
            'settled' => sprintf('The provider now says %s landed — the payout is recorded as paid.', $money),
            'failed' => 'The provider says this transfer failed. The money is back in the business\'s wallet.',
            'reversed' => 'The provider says this transfer came back after landing. The wallet has been credited again.',
            'skipped' => 'This transfer is no longer in flight, so the provider was not asked again.',
            default => 'The provider still has not confirmed this transfer. We will keep checking it automatically.',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function row(?Payout $payout, bool $checked, ?string $result = null): array
    {
        if (! $payout instanceof Payout) {
            return ['checked' => $checked, 'result' => $result];
        }

        return [
            'payout_id' => (int) $payout->id,
            'reference' => (string) $payout->reference,
            'status' => (string) $payout->status,
            'amount_kobo' => (int) $payout->amount_kobo,
            'currency' => (string) ($payout->currency ?: config('custody.currency', 'NGN')),
            'provider' => $payout->provider,
            'checked' => $checked,
            'result' => $result,
        ];
    }
}
