<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

class BankController extends Controller
{
    public function index(Store $store): JsonResponse
    {
        $banks = $store->assignedBanks()
            ->where('is_verified', true)
            ->get()
            ->map(fn($bank) => [
                'id' => $bank->id,
                'bank_name' => $bank->bank_name,
                'account_name' => $bank->account_name,
                'account_number' => $bank->masked_account_number,
                'is_primary' => $bank->is_primary,
                'is_verified' => $bank->is_verified,
            ]);

        return response()->json([
            'success' => true,
            'data' => ['banks' => $banks],
        ]);
    }
}
