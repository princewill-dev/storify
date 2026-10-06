<?php

namespace App\Http\Requests\Management\Transfer;

use App\Enums\TransferStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * WS-15 — the transfer list filters: status tab, code/requester search, the
 * location pair and the page size.
 *
 * Extracted from the controller unchanged, including the eight-value status
 * whitelist, so a hand-edited `?status=` fails the same 422 the SPA's
 * `hydrateFromQuery()` guard protects against.
 */
final class IndexStockTransferRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(array_map(fn (TransferStatus $status) => $status->value, TransferStatus::cases()))],
            'q' => ['nullable', 'string', 'max:100'],
            'location_type' => ['nullable', Rule::in(['warehouse', 'store'])],
            'location_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
