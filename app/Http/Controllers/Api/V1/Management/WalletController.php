<?php

namespace App\Http\Controllers\Api\V1\Management;

use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Controllers\Api\V1\Management\Concerns\ResolvesManagementContext;
use App\Models\Business;
use App\Models\StoreBank;
use App\Services\Payments\Custody\WalletSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * WS-49 — "what is my money doing".
 *
 * Until this existed, a business under custody had no way to see its wallet at
 * all: `businesses.pending_balance` / `available_balance` were rendered only
 * inside the accounting-integrity JSON, an auditor's screen, while every
 * business-facing screen drew `stores.balance` — a column a collected sale never
 * credits. The figures here come from {@see WalletSummaryService}, which is a read
 * model over the send's own arithmetic, so the number a business reads as "what
 * goes to my bank" is the number the send would actually move.
 *
 * ## Read-only, deliberately
 *
 * There is no "pay me now" here and there will not be one. Money leaves the wallet
 * from one service, entered two ways — the queued `SettlePayouts` job a collected
 * sale dispatches, and the operator's retry — and both call
 * `InstantPayoutService::settle()` → `PayoutSweepService::sweepBusiness()`, so
 * neither is a second way to move money. A button here would be: it would move an
 * amount on a business's command, and a business that can move its own balance on
 * demand is a business that can be tricked into moving someone else's. The same
 * refusal is recorded on the admin side — see `ad12-money-config.php`.
 *
 * The permission is `transactions view`, the one the codebase already uses for
 * money. The wallet is business-level, and so are the dashboard figures behind
 * `dashboard view` and the transaction totals behind this one: a business-wide
 * money read is not new here, and inventing a second rule for it would be.
 */
class WalletController extends ApiController
{
    use ResolvesManagementContext;

    public function __construct(
        private readonly WalletSummaryService $wallet,
    ) {}

    /**
     * The wallet: what is settling, what is on its way, what is payable, what is held
     * and why.
     *
     * "On its way" is `in_flight`, and it is not decoration: a transfer debits the
     * wallet before the provider is called, so without it the screen reads ₦0 payable
     * at the precise moment money is moving.
     */
    public function show(Request $request): JsonResponse
    {
        $business = $this->business($request);

        return $this->ok([
            'wallet' => $this->wallet->forBusiness($business),
            // Where the money is going, on the same screen that says how much:
            // a business whose payout account is wrong sees "held: no payout
            // bank" from the blocker above, and needs the destination beside it
            // to act on. Masked — this is a display payload, not an edit form,
            // and the full number is already on the bank-accounts screen that
            // owns it.
            'destination' => $this->destination($business),
        ]);
    }

    private function business(Request $request): Business
    {
        $business = $this->user($request)->business;

        if ($business === null) {
            abort(403, 'Your account is not attached to a business.');
        }

        return $business;
    }

    /**
     * @return array<string, mixed>
     */
    private function destination(Business $business): array
    {
        $bank = $business->payoutBank;

        if (! $bank instanceof StoreBank) {
            return [
                'configured' => false,
                'bank_code' => null,
                'bank_name' => null,
                'masked_account_number' => null,
                'account_name' => null,
            ];
        }

        return [
            // "Configured" is about the destination being usable at all, which
            // is the same test the send makes: a row with no code or no number
            // is a bank on file that no provider can be told to pay into.
            'configured' => blank($bank->bank_code) === false && blank($bank->account_number) === false,
            'bank_code' => $bank->bank_code,
            'bank_name' => $bank->bank_name,
            // The model's own accessor, which every other management payload
            // that shows a bank account already reads. A second masking rule
            // here would be a second answer to "what does this business see".
            'masked_account_number' => $bank->masked_account_number,
            'account_name' => $bank->account_name,
        ];
    }
}
