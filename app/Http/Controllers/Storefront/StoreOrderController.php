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
    private function resolveStore(Request $request, ?string $store_subdomain = null): Store
    {
        if ($store_subdomain) {
            return Store::where('slug', $store_subdomain)->firstOrFail();
        }
        $host = $request->getHost();
        $mainDomain = config('app.main_domain', parse_url(config('app.url'), PHP_URL_HOST));
        $subdomain = str_replace('.' . $mainDomain, '', $host);
        return Store::where('slug', $subdomain)->firstOrFail();
    }

    public function track(Request $request, ?string $store_subdomain = null): View|RedirectResponse
    {
        $store = $this->resolveStore($request, $store_subdomain);
        $orderNumber = $request->query('orderNumber');
        
        if ($orderNumber) {
            $order = Order::where('store_id', $store->id)
                ->where('order_number', strtoupper(trim($orderNumber)))
                ->with(['items'])
                ->first();
            
            if (!$order) {
                return back()->with('error', 'Order not found. Please check the order number and try again.');
            }
            
            return view('storefront.pages.track-order', compact('store', 'order'));
        }
        
        return view('storefront.pages.track-order', compact('store'));
    }

    public function findOrder(Request $request, ?string $store_subdomain = null): View|RedirectResponse
    {
        $store = $this->resolveStore($request, $store_subdomain);
        
        $request->validate(['order_number' => 'required|string']);
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
