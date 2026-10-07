<?php

namespace App\Http\Requests\Management\Pos;

use App\Models\PosSession;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-17 — business-wide POS session list filters: one store, one status, and
 * the page size.
 */
class PosSessionIndexRequest extends FormRequest
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
            'store_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in([PosSession::STATUS_OPEN, PosSession::STATUS_CLOSED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
