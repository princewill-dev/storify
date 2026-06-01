<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\DeliveryRoute;
use App\Models\Store;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class StoreDeliveryRouteController extends Controller
{
    public function store(Request $request, Store $store): RedirectResponse
    {
        /** @var User|null $vendor */
        $vendor = $request->user();

        

        if ($store->user_id !== $vendor->id) {
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
            'user_id' => $vendor->id,
            'store_id' => $store->id,
        ]);

        return back()->with('success', 'Delivery route added successfully.');
    }

    public function update(Request $request, Store $store, DeliveryRoute $deliveryRoute): RedirectResponse
    {
        /** @var User|null $vendor */
        $vendor = $request->user();

        

        if ($store->user_id !== $vendor->id || $deliveryRoute->store_id !== $store->id) {
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
            'user_id' => $vendor->id,
            'store_id' => $store->id,
            'route_id' => $deliveryRoute->id,
        ]);

        return back()->with('success', 'Delivery route updated successfully.');
    }

    public function destroy(Request $request, Store $store, DeliveryRoute $deliveryRoute): RedirectResponse
    {
        /** @var User|null $vendor */
        $vendor = $request->user();

        

        if ($store->user_id !== $vendor->id || $deliveryRoute->store_id !== $store->id) {
            return back()->with('error', 'Unauthorized action.');
        }

        $deliveryRoute->delete();

        Log::info('vendor.delivery_route.deleted', [
            'user_id' => $vendor->id,
            'store_id' => $store->id,
            'route_id' => $deliveryRoute->id,
        ]);

        return back()->with('success', 'Delivery route deleted successfully.');
    }
}
