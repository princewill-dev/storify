<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class StoreOrderController extends Controller
{
    public function track(Request $request, string $store_subdomain, ?string $orderNumber = null): View|RedirectResponse
    {
        $store = Store::where('slug', $store_subdomain)->firstOrFail();
        
        // If order number is provided in URL, fetch and display it
        if ($orderNumber) {
            $order = Order::where('store_id', $store->id)
                ->where('order_number', strtoupper(trim($orderNumber)))
                ->with(['items'])
                ->first();
            
            if (!$order) {
                return redirect()->route('home.store.order.track', ['store_subdomain' => $store_subdomain])
                    ->with('error', 'Order not found. Please check the order number and try again.');
            }
            
            return view('storefront.pages.track-order', compact('store', 'order'));
        }
        
        return view('storefront.pages.track-order', compact('store'));
    }

    public function findOrder(Request $request, string $store_subdomain): View|RedirectResponse
    {
        $store = Store::where('slug', $store_subdomain)->firstOrFail();
        
        $request->validate([
            'order_number' => 'required|string',
        ]);

        $orderNumber = trim($request->input('order_number'));

        $order = Order::where('store_id', $store->id)
            ->where('order_number', $orderNumber)
            ->with(['items'])
            ->first();

        if (!$order) {
            return back()->with('error', 'Order not found. Please check the order number and try again.')->withInput();
        }

        return view('storefront.pages.track-order', compact('store', 'order'));
    }
}
