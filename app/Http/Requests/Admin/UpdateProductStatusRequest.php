<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

/**
 * WS-15 (admin console) — `PUT /api/v1/admin/products/{product}/status`.
 */
final class UpdateProductStatusRequest extends ProductApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ];
    }
}
