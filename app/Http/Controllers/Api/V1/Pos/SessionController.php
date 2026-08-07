<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\PosSession;
use App\Models\Store;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SessionController extends Controller
{
    public function status(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        $session = PosSession::where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->latest()
            ->first();

        return response()->json([
            'success' => true,
            'data' => [
                'session' => $session ? [
                    'session_code' => $session->session_code,
                    'opened_at' => $session->opened_at->toISOString(),
                    'opening_balance' => $session->opening_balance,
                    'sales_total' => $session->calculateSalesTotal(),
                ] : null,
            ],
        ]);
    }

    public function open(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        if (!$store->pos_enabled) {
            return response()->json(['success' => false, 'message' => 'POS is not enabled for this store.'], 400);
        }

        $existing = PosSession::where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->exists();

        if ($existing) {
            return response()->json(['success' => false, 'message' => 'You already have an open session for this store.'], 400);
        }

        $validated = $request->validate([
            'opening_balance' => 'required|integer|min:0',
        ]);

        $session = PosSession::create([
            'store_id' => $store->id,
            'business_id' => $store->business_id,
            'staff_id' => $user->id,
            'opened_at' => now(),
            'opening_balance' => $validated['opening_balance'],
            'status' => PosSession::STATUS_OPEN,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'session' => [
                    'session_code' => $session->session_code,
                    'opened_at' => $session->opened_at->toISOString(),
                    'opening_balance' => $session->opening_balance,
                ],
            ],
        ], 201);
    }

    public function close(Request $request, Store $store): JsonResponse
    {
        $user = $request->user();

        $session = PosSession::where('store_id', $store->id)
            ->where('staff_id', $user->id)
            ->where('status', PosSession::STATUS_OPEN)
            ->latest()
            ->first();

        if (!$session) {
            return response()->json(['success' => false, 'message' => 'No open session found.'], 400);
        }

        $validated = $request->validate([
            'closing_balance_actual' => 'required|integer|min:0',
            'notes' => 'nullable|string|max:500',
        ]);

        $session->close(
            $validated['closing_balance_actual'],
            $validated['notes'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => [
                'session' => [
                    'session_code' => $session->session_code,
                    'opening_balance' => $session->opening_balance,
                    'closing_balance_expected' => $session->closing_balance_expected,
                    'closing_balance_actual' => $session->closing_balance_actual,
                    'difference' => $session->difference,
                    'closed_at' => $session->closed_at->toISOString(),
                ],
            ],
        ]);
    }
}
