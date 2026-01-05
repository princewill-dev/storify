<?php

namespace App\Http\Controllers\Cart;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Store;
use App\Models\DeliveryRoute;
use App\Models\Vat;
use App\Models\Cart;

class CartController extends Controller
{
    // GET /{store}/cart
    public function cart(string $store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();

        // Delivery routes: build states and areas map from active routes
        $routes = DeliveryRoute::query()
            ->where('store_id', $store->id)
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

        // Check if there are any active payment methods globally
        // (Payment methods are platform-wide, not store-specific)
        $hasPaymentMethods = \App\Models\PaymentMethod::where('is_active', true)->exists();

        return view('storefront.pages.cart', [
            'store_slug' => $store_slug,
            'store' => $store,
            'states' => $states,
            'vatPercentage' => $vatPercentage,
            'areasByState' => $areasByState,
            'hasPaymentMethods' => $hasPaymentMethods,
        ]);
    }

    public function proceedToCheckout(Request $request, string $store_slug)
    {
        $store = Store::where('slug', $store_slug)->firstOrFail();
        
        $validated = $request->validate([
            'delivery_route_id' => [
                'nullable',
                'exists:delivery_routes,id,store_id,' . $store->id . ',active,1'
            ],
        ]);

        // Resolve cart
        $guestToken = $request->cookie('guest_token');
        $userId = auth()->guard('customer')->id();

        $query = Cart::query()
            ->where('store_id', $store->id)
            ->where('status', 'active');

        if ($userId) {
            $query->where('user_id', $userId);
        } elseif ($guestToken) {
            $query->where('guest_token', $guestToken);
        } else {
            return response()->json(['error' => 'Session expired'], 400);
        }

        $cart = $query->first();

        if (!$cart || $cart->items->isEmpty()) {
            return response()->json([
                'error' => 'Your cart is empty'
            ], 400);
        }

        // Validate route belongs to store
        if (!empty($validated['delivery_route_id'])) {
             $route = DeliveryRoute::where('id', $validated['delivery_route_id'])
                ->where('store_id', $store->id)
                ->first();
             if (!$route) {
                 return response()->json(['error' => 'Invalid delivery location selected'], 422);
             }
        }

        // Generate unique token
        $token = bin2hex(random_bytes(16)); // 32 chars
        
        $cart->update([
            'delivery_route_id' => $validated['delivery_route_id'] ?? null,
            'checkout_token' => $token
        ]);

        // IMPORTANT: The route parameter must use 'token' as defined in web.php (we will add this route next)
        // or we can just append it manually if route is not named yet.
        // Assuming we will name the route 'checkout.token' or similar, or modifying 'checkout.index'
        
        // For now constructing URL manually to ensure it matches the pattern requested
        // http://rozypolishpetals.localhost:8000/rozypolishpetals/checkout/<temp-order-id>
        
        // Generate redirect URL based on environment and context
        if ($request->routeIs('local.*')) {
            // Local development: use path prefix
            $redirectUrl = route('local.checkout.index', [
                'store_subdomain' => $store_slug,
                'token' => $token
            ]);
        } else {
            // Production subdomain: pass store_subdomain for domain pattern
            $redirectUrl = route('checkout.index', [
                'store_subdomain' => $store_slug,
                'token' => $token
            ]);
        }
        
        return response()->json([
            'redirect_url' => $redirectUrl
        ]);
    }
}
