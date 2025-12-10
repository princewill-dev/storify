<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrderRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true; // Add proper authorization logic if needed
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => 'sometimes|in:pending,accepted,processing,dispatched,delivered,completed,cancelled,returned',
            'payment_status' => 'sometimes|in:unpaid,paid,refunded,failed',
            'notes' => 'nullable|string|max:1000',
            'shipping_fee' => 'sometimes|numeric|min:0',
            'tax' => 'sometimes|numeric|min:0',
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'status.in' => 'Invalid order status selected.',
            'payment_status.in' => 'Invalid payment status selected.',
            'shipping_fee.numeric' => 'Shipping fee must be a valid number.',
            'tax.numeric' => 'Tax must be a valid number.',
        ];
    }
}
