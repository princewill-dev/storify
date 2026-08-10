<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Http\Controllers\Controller;
use App\Models\ServiceCharge;
use App\Models\Store;
use Illuminate\Http\JsonResponse;

class ServiceChargeController extends Controller
{
    public function index(Store $store): JsonResponse
    {
        $charges = ServiceCharge::where('store_id', $store->id)
            ->active()
            ->get()
            ->map(fn($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'amount' => (float) $c->amount,
                'description' => $c->description,
            ]);

        return response()->json([
            'success' => true,
            'data' => ['charges' => $charges],
        ]);
    }
}
