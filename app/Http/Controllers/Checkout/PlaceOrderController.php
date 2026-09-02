<?php

namespace App\Http\Controllers\Checkout;

use App\Actions\Checkout\PlaceStorefrontOrder;
use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\PlaceOrderRequest;
use App\Models\Store;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;

final class PlaceOrderController extends Controller
{
    public function __construct(private readonly PlaceStorefrontOrder $placeOrder) {}

    public function __invoke(PlaceOrderRequest $request, string $store_subdomain): RedirectResponse
    {
        $store = Store::query()
            ->where('slug', $store_subdomain)
            ->where('status', Store::STATUS_ACTIVE)
            ->firstOrFail();

        try {
            $order = $this->placeOrder->execute(
                $store,
                auth()->guard('customer')->user(),
                $request->validated(),
                $request->cookie('guest_token'),
                $request->ip(),
            );
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage())->withInput();
        } catch (\Throwable $e) {
            Log::error('checkout_failed', [
                'store_id' => $store->id,
                'error' => $e->getMessage(),
            ]);

            return back()->with('error', 'Checkout failed. Please try again.')->withInput();
        }

        Log::info('checkout_completed_waiting_payment', [
            'order_id' => $order->id,
            'business_id' => $order->business_id,
            'customer_id' => $order->customer_id,
        ]);

        $route = app()->environment('local') ? 'local.checkout.payment-methods' : 'checkout.payment-methods';

        return redirect()->route($route, [
            'store_subdomain' => $store_subdomain,
            'order' => $order,
        ]);
    }
}
