<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\DeliveryAddress;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AddressController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'delivery_route_id' => 'required|exists:delivery_routes,id',
            'label' => 'nullable|string|max:255',
            'recipient_name' => 'required|string|max:255',
            'recipient_phone' => 'required|string|max:50',
            'street_address' => 'required|string|max:500',
            'apartment' => 'nullable|string|max:255',
            'is_default' => 'boolean',
        ]);

        $customer = Auth::guard('customer')->user();

        $address = DeliveryAddress::create([
            'customer_id' => $customer->id,
            'delivery_route_id' => $request->delivery_route_id,
            'label' => $request->label ?? 'Address',
            'recipient_name' => $request->recipient_name,
            'recipient_phone' => $request->recipient_phone,
            'street_address' => $request->street_address,
            'apartment' => $request->apartment,
            'is_default' => $request->boolean('is_default'),
        ]);

        if ($address->is_default) {
            $address->setAsDefault();
        }

        // Load relationship for display
        $address->load('deliveryRoute');

        Log::info('address_created', ['customer_id' => $customer->id, 'address_id' => $address->id]);

        return response()->json([
            'success' => true,
            'message' => 'Address added successfully',
            'address' => $address
        ]);
    }
}
