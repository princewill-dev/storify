<?php

namespace App\Http\Requests\Admin;

use App\Enums\TransferStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * AD-14 — the platform transfer directory filters.
 *
 * Extracted from the controller unchanged: the eight-value status whitelist,
 * the code/location search term, the page size and the sort whitelist. The
 * sort column is passed straight into `orderBy`, so a request-supplied column
 * must never survive validation.
 */
final class ListStockTransfersRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The platform-admin guard stays in the controller on purpose: it must
        // not move into middleware or FormRequest::authorize().
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::in(array_map(fn (TransferStatus $status) => $status->value, TransferStatus::cases()))],
            'q' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            // Sort whitelisted, never taken straight from the request.
            'sort' => ['nullable', Rule::in(['created_at', 'transfer_code', 'status'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}
