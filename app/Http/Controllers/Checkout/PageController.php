<?php

namespace App\Http\Controllers\Checkout;

use App\Http\Controllers\Controller;
use App\Models\Cart;
use App\Models\DeliveryRoute;
use App\Models\Order;
use App\Models\PaymentMethod;
use App\Models\Store;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PageController extends Controller
{
    public function checkout(Request $request, string $store_subdomain, string $token): View|RedirectResponse
    {
        $store = Store::query()
            ->where('slug', $store_subdomain)
            ->where('status', Store::STATUS_ACTIVE)
            ->firstOrFail();
        $cart = Cart::query()
            ->where('checkout_token', $token)
            ->where('store_id', $store->id)
            ->where('status', 'active')
            ->with(['items.product.images', 'deliveryRoute'])
            ->first();

        if (! $cart || $cart->items->isEmpty()) {
            return redirect()->route('home.store.cart', ['store_subdomain' => $store_subdomain])
                ->with('error', 'Invalid, empty, or expired checkout session.');
        }

        $summaryItems = $cart->items->map(function ($item): array {
            $product = $item->product;
            $unitAmountKobo = (int) ($item->unit_amount ?? 0);

            if ($unitAmountKobo <= 0 && $item->qty > 0 && $item->line_subtotal) {
                $unitAmountKobo = (int) round($item->line_subtotal / $item->qty);
            }
            if ($unitAmountKobo <= 0 && $product?->amount) {
                $unitAmountKobo = (int) round($product->amount * 100);
            }

            return [
                'id' => $item->id,
                'name' => $item->name ?? $product?->name ?? 'Item',
                'qty' => $item->qty,
                'unit_amount' => $unitAmountKobo,
                'unit_price' => $unitAmountKobo / 100,
                'total' => (int) ($item->line_subtotal ?? ($unitAmountKobo * $item->qty)) / 100,
                'image_path' => $product?->primaryImage()?->path,
                'has_product' => (bool) $product,
                'unit_hint' => data_get($item->meta, 'unit_hint'),
            ];
        });

        $preselectedRoute = $cart->deliveryRoute;
        if (! $preselectedRoute && $request->filled('delivery_route_id')) {
            $preselectedRoute = DeliveryRoute::query()
                ->where('store_id', $store->id)
                ->where('active', true)
                ->find($request->integer('delivery_route_id'));
        }

        $customer = auth()->guard('customer')->user();

        return view('storefront.pages.checkout', [
            'store' => $store,
            'cart' => $cart,
            'cartSummaryItems' => $summaryItems,
            'paymentMethods' => PaymentMethod::active()->get(),
            'vatPercentage' => 0,
            'customer' => $customer,
            'preselectedRoute' => $preselectedRoute,
            'shippingFee' => $preselectedRoute?->active ? $preselectedRoute->fee : 0,
            'savedAddresses' => $customer?->deliveryAddresses()->with('deliveryRoute')->latest()->get() ?? collect(),
            'defaultAddress' => $customer?->defaultDeliveryAddress,
        ]);
    }

    public function payment(string $store_subdomain, Order $order): View
    {
        $store = $this->storeForOrder($store_subdomain, $order);
        $order->load(['customer', 'items.product', 'transactions.paymentMethod', 'deliveryRoute']);

        return view('home.pages.checkout.payment', compact('store', 'order'));
    }

    private function storeForOrder(string $slug, Order $order): Store
    {
        return Store::query()
            ->whereKey($order->store_id)
            ->where('slug', $slug)
            ->where('status', Store::STATUS_ACTIVE)
            ->firstOrFail();
    }
}
