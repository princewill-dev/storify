<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRoute;
use App\Models\Store;
use App\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class VendorStoreDeliveryRouteController extends Controller
{
    public function store(Request $request, Vendor $routeVendor, Store $store): RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->id !== $routeVendor->id) {
            return redirect()->route('vendor.auth.login');
        }

        if ($store->vendor_id !== $vendor->id) {
            return back()->with('error', 'Unauthorized action.');
        }

        $validated = $request->validate([
            'country' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'area' => 'nullable|string|max:255',
            'fee' => 'required|numeric|min:0',
            'delivery_days' => 'required|integer|min:1',
            'active' => 'boolean',
        ]);

        $validated['store_id'] = $store->id;
        $validated['fee'] = $validated['fee'] * 100; // Convert to kobo
        $validated['active'] = $request->has('active');

        DeliveryRoute::create($validated);

        Log::info('vendor.delivery_route.created', [
            'vendor_id' => $vendor->id,
            'store_id' => $store->id,
        ]);

        return back()->with('success', 'Delivery route added successfully.');
    }

    public function update(Request $request, Vendor $routeVendor, Store $store, DeliveryRoute $deliveryRoute): RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->id !== $routeVendor->id) {
            return redirect()->route('vendor.auth.login');
        }

        if ($store->vendor_id !== $vendor->id || $deliveryRoute->store_id !== $store->id) {
            return back()->with('error', 'Unauthorized action.');
        }

        $validated = $request->validate([
            'country' => 'required|string|max:255',
            'state' => 'required|string|max:255',
            'area' => 'nullable|string|max:255',
            'fee' => 'required|numeric|min:0',
            'delivery_days' => 'required|integer|min:1',
            'active' => 'boolean',
        ]);

        $validated['fee'] = $validated['fee'] * 100; // Convert to kobo
        $validated['active'] = $request->has('active');

        $deliveryRoute->update($validated);

        Log::info('vendor.delivery_route.updated', [
            'vendor_id' => $vendor->id,
            'store_id' => $store->id,
            'route_id' => $deliveryRoute->id,
        ]);

        return back()->with('success', 'Delivery route updated successfully.');
    }

    public function destroy(Request $request, Vendor $routeVendor, Store $store, DeliveryRoute $deliveryRoute): RedirectResponse
    {
        /** @var Vendor|null $vendor */
        $vendor = $request->user('vendor');

        if (!$vendor || $vendor->id !== $routeVendor->id) {
            return redirect()->route('vendor.auth.login');
        }

        if ($store->vendor_id !== $vendor->id || $deliveryRoute->store_id !== $store->id) {
            return back()->with('error', 'Unauthorized action.');
        }

        $deliveryRoute->delete();

        Log::info('vendor.delivery_route.deleted', [
            'vendor_id' => $vendor->id,
            'store_id' => $store->id,
            'route_id' => $deliveryRoute->id,
        ]);

        return back()->with('success', 'Delivery route deleted successfully.');
    }
}
