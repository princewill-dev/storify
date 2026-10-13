<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Models\StoreBank;
use Illuminate\Http\JsonResponse;

/**
 * Where the till sends a customer who is paying by bank transfer.
 *
 * These are the **business's own** accounts — {@see Store::banks()}, scoped by
 * the store's `business_id` — and not the store's assigned banks. That is a
 * deliberate divergence from the storefront, which shows the accounts a *store*
 * receives into (the `store_bank` pivot, written by the management "assign bank
 * to store" screen) and only the verified ones.
 *
 * At a counter neither restriction applies. The customer is transferring to the
 * business, the money never enters Storify's custody, and there is nothing for
 * us to verify before it does — so every account the business holds is offered,
 * and the one the business's payouts go to is marked, because that is the
 * account the money is meant to end up in.
 */
class BankController extends Controller
{
    public function index(Store $store): JsonResponse
    {
        // Read once rather than per row: it is one column on the business, and
        // the marker below has to be the same answer on every line.
        $payoutBankId = $store->business?->payout_bank_id;

        $banks = $store->banks()
            ->orderByDesc('is_primary')
            ->orderBy('bank_name')
            ->get()
            ->map(fn (StoreBank $bank) => [
                'id' => $bank->id,
                'bank_name' => $bank->bank_name,
                'bank_code' => $bank->bank_code,
                'account_name' => $bank->account_name,
                'account_number' => $bank->account_number,
                // What the till should print. A counter screen is read over a
                // customer's shoulder, and the full number is not needed to pick
                // the right account.
                'masked_account_number' => $bank->masked_account_number,
                'is_primary' => $bank->is_primary,
                // Kept on the wire so the till can badge an account, but no
                // longer a filter: an unverified account still receives money,
                // and holding a sale up over it is not the till's business.
                'is_verified' => $bank->is_verified,
                // The account Storify pays this business's split payout into.
                'is_payout' => $payoutBankId !== null && (int) $payoutBankId === (int) $bank->id,
            ]);

        return response()->json([
            'success' => true,
            'data' => ['banks' => $banks],
        ]);
    }
}
