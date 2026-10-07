<?php

namespace App\Http\Requests\Management\Pos;

use App\Models\PosSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-17 — per-store cash-register log filters. The store itself is route-bound
 * and access-checked by the controller, so it is not a filter here.
 */
class StorePosSessionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in([PosSession::STATUS_OPEN, PosSession::STATUS_CLOSED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
