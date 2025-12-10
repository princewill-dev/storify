<?php

namespace App\Http\Controllers\Cart;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Store;
use App\Models\DeliveryRoute;
use App\Models\Vat;

class CartController extends Controller
{
    // GET /{store}/cart
    public function cart(string $store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();

        // Delivery routes: build states and areas map from active routes
        $routes = DeliveryRoute::query()
            ->where('active', true)
            ->orderBy('state')
            ->orderBy('area')
            ->get(['id','state','area','fee','delivery_days']);

        $states = $routes->pluck('state')->unique()->values()->all();
        $areasByState = $routes->groupBy('state')->map(function($items){
            return $items->map(function($r){
                return [
                    'id' => $r->id,
                    'area' => $r->area,
                    'fee' => (int) $r->fee,
                    'days' => $r->delivery_days,
                ];
            })->values()->all();
        })->toArray();

        // VAT percentage: from active VAT or latest
        $vatPercentage = optional(Vat::active()->orderByDesc('effective_at')->orderByDesc('id')->first())->percentage
            ?? optional(Vat::current())->percentage
            ?? 0;

        return view('home.pages.cart.cart', [
            'store_slug' => $store_slug,
            'store' => $store,
            'states' => $states,
            'vatPercentage' => $vatPercentage,
            'areasByState' => $areasByState,
        ]);
    }
}
