<?php

namespace App\Http\Controllers\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StoreOrderController extends Controller
{
    public function track(Request $request, string $store_subdomain): View
    {
        $store = Store::where('slug', $store_subdomain)->firstOrFail();
        return view('storefront.pages.track-order', compact('store'));
    }

    public function findOrder(Request $request, string $store_subdomain): View
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
