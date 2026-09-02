<?php

namespace App\Http\Controllers\Checkout;

use App\Http\Controllers\Controller;
use App\Http\Requests\Checkout\SaveDeliveryAddressRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class DeliveryAddressController extends Controller
{
    public function store(SaveDeliveryAddressRequest $request): JsonResponse
    {
        $customer = auth()->guard('customer')->user();
        $data = $request->validated();

        $address = DB::transaction(function () use ($customer, $data) {
            if ($requestDefault = (bool) ($data['set_default'] ?? false)) {
                $customer->deliveryAddresses()->update(['is_default' => false]);
            }

            return $customer->deliveryAddresses()->create([
                'label' => $data['label'] ?? 'Home',
                'recipient_name' => $data['recipient_name'],
                'recipient_phone' => $data['recipient_phone'],
                'company_name' => $data['company_name'] ?? null,
                'street_address' => $data['street_address'],
                'apartment' => $data['apartment'] ?? null,
                'city' => $data['city'],
                'state' => $data['state'],
                'country' => $data['country'],
                'landmark' => $data['landmark'] ?? null,
                'zip_code' => $data['zip_code'] ?? null,
                'map_link' => $data['map_link'] ?? null,
                'is_default' => $requestDefault,
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Address saved successfully',
            'address' => $address,
        ]);
    }
}
