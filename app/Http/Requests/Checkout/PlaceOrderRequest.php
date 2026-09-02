<?php

namespace App\Http\Requests\Checkout;

use App\Models\Store;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PlaceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $store = Store::query()
            ->where('slug', $this->route('store_subdomain'))
            ->where('status', Store::STATUS_ACTIVE)
            ->first();
        $guest = ! auth()->guard('customer')->check();

        return [
            'first_name' => [Rule::requiredIf($guest), 'nullable', 'string', 'max:255'],
            'last_name' => [Rule::requiredIf($guest), 'nullable', 'string', 'max:255'],
            'email' => [Rule::requiredIf($guest), 'nullable', 'email', 'max:255'],
            'phone' => [Rule::requiredIf($guest), 'nullable', 'string', 'max:20'],
            'street_address' => ['required', 'string'],
            'apartment' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'state' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'delivery_route_id' => [
                'nullable',
                Rule::exists('delivery_routes', 'id')->where(fn ($query) => $query
                    ->where('store_id', $store?->id ?? 0)
                    ->where('active', true)),
            ],
            'checkout_token' => ['nullable', 'string'],
        ];
    }
}
