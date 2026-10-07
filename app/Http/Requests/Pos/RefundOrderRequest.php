<?php

namespace App\Http\Requests\Pos;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The POS refund payload — the controller's inline rules, moved verbatim:
 * a reason is required, a string, of at most 500 characters.
 *
 * No authorisation here on purpose: the store the refund is aimed at is
 * enforced by the EnsurePosStoreAccess route middleware (403), which runs
 * before the controller body, so the guard keeps its order.
 */
final class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
