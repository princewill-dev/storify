<?php

namespace App\Http\Requests\Management\Transfer;

use Illuminate\Foundation\Http\FormRequest;

/**
 * WS-15 — the mandatory rejection reason.
 *
 * Like ApproveStockTransferRequest, this is resolved from the container by the
 * controller rather than type-hinted: the AD-14 admin console delegates
 * `reject` with a plain Request. Validating at the same point in the sequence
 * (after the 403 tenant guard and the 409 state guard) preserves the
 * pre-refactor ordering, so a terminal transfer stays a 409 and a reason-less
 * pending one stays a 422.
 */
final class RejectStockTransferRequest extends FormRequest
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
            'rejection_reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
